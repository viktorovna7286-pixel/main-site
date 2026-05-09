/**
 * Обновляет catalog-data.json: поле segment по второму листу Excel «По сегментам 7btu».
 * Запуск: node apply-segments-from-xlsx.mjs [путь-к-xlsx]
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import XLSX from 'xlsx';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const SEGMENT_MAP = {
  БЮДЖЕТ: 'budget',
  КОМФОРТ: 'comfort',
  ПРЕМИУМ: 'premium',
  БЮДЖЕТЫ: 'budget',
};

function normStr(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFKD')
    .replace(/\p{Mn}/gu, '')
    .replace(/\s+/g, ' ')
    .trim();
}

function normBrand(s) {
  return normStr(s).replace(/[^a-z0-9]/g, '');
}

function brandsCompatible(a, b) {
  const A = normBrand(a);
  const B = normBrand(b);
  if (!A || !B) return false;
  if (A === B) return true;
  if ((A === 'roda' || A === 'rovex') && (B === 'roda' || B === 'rovex')) return true;
  if ((A === 'hisense' || A === 'ballu') && (B === 'hisense' || B === 'ballu')) return true;
  if ((A === 'midea' || A === 'ballu') && (B === 'midea' || B === 'ballu')) return true;
  return false;
}

function normFactory(s) {
  return normStr(s).replace(/[^a-z0-9]/g, '');
}

function modelSansBtuDigits(sku) {
  const u = String(sku || '')
    .toUpperCase()
    .trim();
  return u.replace(/^([A-Z][A-Z0-9]{1,12})-\d{1,3}(?=[A-Z0-9\-/])/g, (_, p) => `${p}-`);
}

function skuKeysFromExcelCell(cell) {
  const txt = String(cell || '');
  const out = new Set();
  for (const m of txt.matchAll(/\(([A-Za-z0-9\-\/\\.]+)\)/g)) {
    const raw = m[1].replace(/\./g, '/').toUpperCase();
    for (const chunk of raw.split('/')) {
      const c = chunk.trim();
      if (c.length < 4) continue;
      out.add(c);
      out.add(modelSansBtuDigits(c));
    }
  }
  return [...out];
}

function skuKeysFromCatalogModel(mod) {
  const out = new Set();
  for (const part of String(mod || '').toUpperCase().split('/')) {
    const p = part.trim();
    if (!p) continue;
    out.add(p);
    out.add(modelSansBtuDigits(p));
  }
  return [...out];
}

function excelSeriesStems(full) {
  const before = String(full || '').split('(')[0].trim();
  return new Set([
    normStr(before),
    normStr(before.replace(/^серия\s+/i, '')),
    normStr(before.replace(/^серия\s+/i, '').replace(/^a\s+/i, 'a')),
  ].filter(Boolean));
}

function catalogSeriesStem(ser) {
  const plain = normStr(String(ser || '').replace(/\([^)]*\)/g, ' ')).replace(/\s+/g, ' ');
  return plain;
}

function seriesOverlap(stems, catalogStem) {
  if (!catalogStem) return 0;
  let best = 0;
  for (const stem of stems) {
    if (!stem || stem.length < 2) continue;
    if (catalogStem.includes(stem) || stem.includes(catalogStem)) {
      best = Math.max(best, Math.min(stem.length, catalogStem.length));
    }
    const cw = stem.split(' ').filter((w) => w.length >= 4);
    for (const w of cw) {
      if (catalogStem.includes(w)) best = Math.max(best, w.length);
    }
  }
  return best;
}

function skuOverlap(excelSKUs, catSKUs) {
  let best = 0;
  for (const e of excelSKUs) {
    if (e.length < 4) continue;
    for (const c of catSKUs) {
      if (!c || c.length < 4) continue;
      if (e === c) return Math.max(best, e.length + 50);
      if (e.includes(c) || c.includes(e)) best = Math.max(best, Math.min(e.length, c.length));
    }
  }
  return best;
}

function parseXlsx(filepath) {
  const wb = XLSX.readFile(filepath);
  const name = wb.SheetNames[1];
  const ws = wb.Sheets[name];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false });
  const [, ...data] = rows;
  const out = [];
  for (const row of data) {
    const segRu = String(row[0] || '').trim().toUpperCase().replace(/\s+/g, '');
    if (!segRu || !SEGMENT_MAP[segRu]) continue;
    out.push({
      segment: SEGMENT_MAP[segRu],
      brand: row[1],
      seriesCell: row[2],
      factory: row[3],
    });
  }
  return { sheetName: name, rules: out };
}

function pickSegment(product, rules) {
  const catFactory = normFactory(product.factory);
  const catBrand = product.brand;
  const catStem = catalogSeriesStem(product.series);
  const catSkus = skuKeysFromCatalogModel(product.model);

  let bestSeg = null;
  let bestScore = 0;

  for (const r of rules) {
    const excelSkus = skuKeysFromExcelCell(r.seriesCell);
    const ko = skuOverlap(excelSkus, catSkus);
    const xf = normFactory(r.factory);
    const factoryOk = !xf || !catFactory || xf === catFactory;
    if (!factoryOk && ko < 52) continue;

    let score = (factoryOk ? 20 : 0) + ko;
    if (brandsCompatible(r.brand, catBrand)) score += 15;

    const excelStems = excelSeriesStems(r.seriesCell);
    const so = seriesOverlap(excelStems, catStem);
    if (so) score += Math.min(35, so + 12);

    if (score > bestScore) {
      bestScore = score;
      bestSeg = r.segment;
    }
  }

  const threshold = 45;
  if (bestScore >= threshold) return { segment: bestSeg, score: bestScore };
  return null;
}

/** То, чего нет отдельной строкой в «По сегментам 7btu», но логика линейки очевидна */
function patchSegment(product, inferred) {
  const m = String(product.model || '');
  if (/BSEI-/i.test(m))
    return {
      segment: 'comfort',
      score: inferred?.score ?? 0,
      note: 'Platinum DC Inverter → комфорт (отдельной строки в таблице нет)',
    };
  return inferred;
}

const defaultXlsx =
  process.platform === 'win32'
    ? path.join(process.env.USERPROFILE || '', 'Downloads', 'Список кондиционеров (1).xlsx')
    : '';

const argPath = process.argv[2] || defaultXlsx;
const jsonPath = path.join(__dirname, '..', 'catalog-data.json');

if (!argPath || !fs.existsSync(argPath)) {
  console.error('Укажите путь к .xlsx: node apply-segments-from-xlsx.mjs "C:\\...\\Список кондиционеров (1).xlsx"');
  process.exit(1);
}

const { sheetName, rules } = parseXlsx(argPath);
console.error(`Лист: ${sheetName}; правил: ${rules.length}`);

const catalog = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));

const summary = [];

for (const p of catalog) {
  const before = p.segment;
  const hit = patchSegment(p, pickSegment(p, rules));
  if (hit) {
    p.segment = hit.segment;
    summary.push({
      id: p.id,
      model: p.model,
      series: p.series.slice(0, 60),
      before,
      after: hit.segment,
      score: hit.score,
      ...(hit.note ? { note: hit.note } : {}),
    });
  } else {
    summary.push({
      id: p.id,
      model: p.model,
      series: p.series.slice(0, 60),
      before,
      after: null,
      note: 'no excel match ≥ threshold — segment unchanged',
    });
  }
}

fs.writeFileSync(jsonPath, JSON.stringify(catalog, null, 2) + '\n', 'utf8');
console.table(summary);
