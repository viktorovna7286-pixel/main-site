/**
 * Собирает catalog-data.json из текста прайса (извлечённого из PDF Люксхолод).
 * Оставляет только строки со статусом «в наличии», цены берёт как РРЦ.
 *
 * Запуск из папки tools: node build-catalog-from-pdf-text.mjs
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const EXTRACT_PATH = path.join(__dirname, 'luxholod-zima2026.extracted.txt');
const OUT_PATH = path.join(__dirname, '..', 'catalog-data.json');
const OLD_PATH = OUT_PATH;

const DEFAULT_IMG =
  'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=800';

/** Haier Coral / Quantum / Flexis / Jade: код ASnn → условная мощность в «BTU-блоках» сайта */
const AS_DISPLAY_BTU = {
  20: 7,
  25: 9,
  35: 12,
  50: 18,
  70: 24,
  100: 33,
};

/** Mitsubishi Heavy номенклатура SRKnn */
function srkNominal(n) {
  if (n <= 22) return 7;
  if (n <= 28) return 9;
  if (n <= 38) return 12;
  if (n <= 53) return 18;
  if (n <= 68) return 24;
  return 30;
}

function looksLikeDimCell(s) {
  const t = String(s).replace(/\*/g, '×').replace(/x/gi, '×');
  return /\d\s*[×]\s*\d/.test(t) || /\d+[х]\d+[х]/i.test(String(s));
}

/** РРЦ: явные ₽/р., суммы вида «23 990», скобочные каталогные */
function parseMoneyCell(s) {
  if (!s || /отсутствует/i.test(s)) return null;
  if (looksLikeDimCell(s)) return null;

  const str = String(s);
  const hasCurrency = /[₽]|р\.?/i.test(str);
  const spacedMoney = /\b\d[\d\s]{3,10}\s*(?:₽|[р]|$)/;
  const parenMoney = /\(\s*([\d\s]+)\s*\)/;

  let m = str.match(/\(\s*([\d\s]+)\s*\)/);
  if (m) {
    const v = parseInt(m[1].replace(/\s/g, ''), 10);
    if (v > 990 && v < 5_000_000) return v;
  }

  if (hasCurrency || spacedMoney.test(str)) {
    let cleaned = str.replace(/\s/g, '').replace(/₽/g, '').replace(/р\.?/gi, '');
    cleaned = cleaned.replace(/[^\d]/g, '');
    if (!cleaned) return null;
    const v = parseInt(cleaned, 10);
    return v >= 990 && v < 5_000_000 ? v : null;
  }

  const stripped = str.replace(/\s/g, '').replace(/\D/g, '');
  if (!stripped) return null;
  const compact = parseInt(stripped, 10);
  if (/^\s*\(?\d[\d\s]{5,}\)?\s*$/.test(str) && compact >= 990 && compact <= 990_999) return compact;

  return null;
}

/** Обычно: … диаметр | габарит | РРЦ | дилер | статус — РРЦ третья с конца **/
function guessRrc(parts) {
  if (parts.length >= 5) {
    const direct = parseMoneyCell(parts[parts.length - 3]);
    if (direct) return direct;
  }
  for (let i = parts.length - 2; i >= 2; i--) {
    const v = parseMoneyCell(parts[i]);
    if (v) return v;
  }
  return null;
}

function inferBtu(nameLine) {
  const line = nameLine.replace(/\u00ad/g, '').trim();
  const head = line.split(/\s+/)[0];
  let t = head.replace(/^JAX\s+/i, '');

  let m = t.match(/^ECO(\d{2})/i);
  if (m) return +m[1];

  m = line.match(/\bRS-(\d{2})(?!\d)/i);
  if (m) return +m[1];

  m = head.match(/^LAC-(\d{2})(?=[A-Z]?)/i);
  if (m) return +m[1];

  m = head.match(/^BSE[IP]-(\d{2})/i);
  if (m) return +m[1];

  m = line.match(/\b(?:BSDI|BSD)-(\d{2})/i);
  if (m) return +m[1];

  m = t.match(/^B-(\d{2})/i);
  if (m) return +m[1];

  m = line.match(/\bACiU-(\d{2})|(?:^|\s)ACN-(\d{2})\b/i);
  if (m) {
    const v = +(m[1] || m[2]);
    if (v <= 37) return v;
  }

  m = line.match(/\bACE-(\d{2})\b|\bACM-(\d{2})\b|\bACY-(\d{2})\b|\bACI-(\d{2})\b/i);
  if (m) {
    const v = +(m[1] || m[2] || m[3] || m[4]);
    if (v) return Math.min(v, 40);
  }

  m = line.match(/\b(?:AC)(\d{2})BK\./i);
  if (m) return srkNominal(+m[1]); // LG 09 / 12

  m = line.match(/HSU-(\d{2})/i);
  if (m) return +m[1];

  m = line.match(/MSAG3-(\d{2})/i);
  if (m) return +m[1];

  m = line.match(/^ZAC-PG(\d{2})/i);
  if (m) return +m[1];

  m = head.match(/^B(\d{2})TS\.|\/B(\d{2})TS\./i);
  if (m) return +(m[1] || m[2]);

  m = line.match(/^C(\d{2})BK\./i);
  if (m) return Math.min(63, Math.max(7, +m[1])); /* C09BK → 9 */

  const asHead = line.match(/^AS(\d{2,3})/i);
  if (asHead && AS_DISPLAY_BTU[+asHead[1]]) return AS_DISPLAY_BTU[+asHead[1]];

  /* Flexis/Jade строки могут начинаться с пробела после переноса — ищем ASnn */
  m = line.match(/\bAS(\d{2})[HS]/i);
  if (m) return AS_DISPLAY_BTU[+m[1]] ?? srkNominal(+m[1]);

  const sr = line.match(/SRK(\d{2})/i);
  if (sr) return srkNominal(+sr[1]);

  m = head.match(/^VSL-(\d{2})/i);
  if (m) return +m[1];

  return null;
}

/** Расшифровка бренда/завода по заголовку секции */
function metaFromSection(sectionRaw) {
  const s = sectionRaw.trim();
  const low = s.toLowerCase();

  let brand = null;
  let factory = null;

  const fact = /\((AUX|TCL|Midea|MBO|Haier|GREE|Hisense|Chigo|Тайланд|MIDEA|Midea \+4)\)/i.exec(s);
  const inParensSimple = /\(([^)]+)\)/.exec(s);
  let facFrom = fact ? fact[1] : inParensSimple ? inParensSimple[1] : '';

  facFrom = facFrom.replace(/^завод\s+/i, '').trim();
  const facNorm = ({
    Aux: 'AUX',
    AUX: 'AUX',
    TCL: 'TCL',
    midea: 'Midea',
    MIDEA: 'Midea',
    Midea: 'Midea',
    MBO: 'MBO',
    Haier: 'Haier',
    GREE: 'Gree',
    Hisense: 'Hisense',
    Chigo: 'Chigo',
    'Тайланд': 'Тайланд',
  }[facFrom] ?? facFrom);

  if (/skyline|résidence|nocturne|diamond|palermo|fortuna|резиденс|residence/i.test(low))
    brand = brand || 'Loriot';
  if (/\(haier\)/i.test(s) && /серия\s+a/i.test(low)) {
    brand = brand || 'Ecoletta';
    factory = 'Haier';
  }
  if (/ecoletta|^серия a/i.test(low) || /leta/i.test(low)) brand = brand || 'Ecoletta';

  if (/lagoon/i.test(low) || /bsd/i.test(low)) {
    factory = factory || 'Midea';
    brand = brand || 'Ballu';
  }
  if (/platinum/i.test(low) && /hisense/i.test(low)) {
    factory = 'Hisense';
    brand = brand || 'Ballu';
  }
  if (/mira s|grace|city cst/i.test(low)) factory = factory || 'Midea';
  if (/mira s|^серия mira/i.test(low)) brand = brand || 'Mira';
  if (/grace|^серия grace/i.test(low)) brand = brand || 'Rovex';
  if (/city cst/i.test(low)) brand = brand || 'Rovex';
  if (/rich inverter/i.test(low)) brand = brand || 'Rovex';
  if (/star s|^серия star/i.test(low)) brand = brand || 'Midea';
  if (/primary/i.test(low) && !/midea primary/i.test(low)) {
    factory = 'Midea';
    brand = brand || 'Midea';
  }

  if (/york|^серия york/i.test(low)) {
    brand = 'Axioma';
    factory = 'Midea';
  }

  if (/tasmania|coral|quantum|tundra|^as\d|\bhsu-/i.test(low)) {
    brand = brand || 'JAX';
    factory = 'Haier';
  }
  if (/brisbane|^aci?u-/i.test(low)) {
    factory = factory || 'Haier';
    brand = brand || 'JAX';
  }

  if (/flexis|jade\s+inverter|stell|dual\s+invert|прокул|арткул|mirror/i.test(low))
    factory = factory || 'Haier';

  if (/\bmbo\b|diamond\s+white/i.test(low)) factory = factory || 'MBO';

  if (/progress.*tcl/i.test(low)) {
    brand = brand || 'Roda';
    factory = factory || 'TCL';
  }

  if (/melbourne|murray|^acy-/i.test(low)) {
    brand = 'JAX';
    factory = 'Gree';
  }
  if (/hayman/i.test(low)) {
    brand = 'JAX';
    factory = 'Midea';
  }

  if (/\bsrk\d|MHI|\(таиланд\)|\(тайланд\)|standard\s+plus|premium\s+invert/i.test(low)) {
    brand = 'MHI';
    factory = factory || 'MHI (Тайланд)';
  }

  brand = brand || 'Другое';
  factory =
    factory ||
    (facNorm && facNorm !== 'Wi-Fi'
      ? facNorm.includes('Haier')
        ? 'Haier'
        : facNorm
      : factory);
  factory = factory || '—';

  if (/\(Chigo\)/i.test(s)) {
    factory = 'AUX';
    brand = brand === 'Другое' ? 'Бирюса' : brand;
  }

  /* Vesel только Palermo-блок восклицание */
  if (/palermo|^vsl-/i.test(s)) brand = brand || 'Vesel';

  return { brand, factory, seriesHuman: s };
}

function normalizeSeries(sec) {
  return sec.trim().replace(/\s+/g, ' ');
}

function isInverterSeries(sec) {
  const l = String(sec).toLowerCase();
  if (/platinum\s+dc\b/.test(l)) return true;
  if ((/on-?off|on\s\/\s?off/).test(l) && !/(invert|инверт|dc\s*i)/i.test(sec)) return false;
  return /(invert|инверт|dc\s*i)/i.test(sec);
}

function segmentFor(series, maxPrice) {
  const s = series.toLowerCase();
  if (/premium|jade|flexis|mirror|srk|MHI|stellar quantum|dual invert|stell/i.test(s) || maxPrice > 115000)
    return 'premium';
  if (/invert/i.test(series) || maxPrice > 35000) return 'comfort';
  return 'budget';
}

function preprocessLines(raw) {
  const rawLines = raw.split(/\r?\n/);
  const out = [];
  for (let i = 0; i < rawLines.length; i++) {
    let line = rawLines[i];
    if (/^-- \d of \d --$/i.test(line)) continue;

    /* Склеить двухстрочный MIDEA PRIMARY */
    if (/^MSAG3-\d{2}[A-Za-z0-9-]+$/.test(line.trim()) && /\t|\s\d{4,}\s*$/.test(rawLines[i + 1] || '')) {
      line = `${line.trimEnd()} ${(rawLines[i + 1] || '').trim()}`;
      i++;
    }

    /* Склеить название модели с переносом (Flexis/Jade часть) — если следующая строка начинается с таба после цветов */
    if (!line.includes('\t') && i + 1 < rawLines.length) {
      const next = rawLines[i + 1];
      if (next.includes('\t') && line.length > 3 && /\b(AS\d{2}|белый|золото|черн)/i.test(line)) {
        line = `${line.trim()} ${next.trim()}`.replace(/\s+/g, ' ');
        i++;
      }
    }

    /* MSAG строка где модель уже на одной линии с ценами (без таб статуса) */
    const msagOne = /^MSAG3-\d{2}[A-Za-z0-9-]+\s+I\/MSAG3[^\t]+\t[\d.]+\t[\d.]+(?:\t|$)/i;
    const msagBroken = /^I\/MSAG3-.+\t[\d.]+\t[\d.]+/;
    if ((msagOne.test(line) || msagBroken.test(line.trim())) && !/в наличии/i.test(line)) {
      /* Блок последней страницы без текста наличия — пропускаем */
      out.push(line);
      continue;
    }

    out.push(line);
  }
  return out;
}

function slugKey(brand, series) {
  return `${brand}|${normalizeSeries(series)}`;
}

/** Подтягиваем изображение из предыдущего каталога по серии или бренду */
function collectImageHints(oldProducts) {
  const map = {};
  if (!Array.isArray(oldProducts)) return map;
  for (const p of oldProducts) {
    const keys = [`${p.brand}|${p.series}`, p.series, `${p.factory}|${p.series}`];
    keys.forEach((k) => {
      if (!k) return;
      if (!map[k] && p.image) map[k] = p.image;
    });
  }
  return map;
}

function main() {
  const rawText = fs.readFileSync(EXTRACT_PATH, 'utf8');
  const hintMap = fs.existsSync(OLD_PATH)
    ? collectImageHints(JSON.parse(fs.readFileSync(OLD_PATH, 'utf8')))
    : {};

  const lines = preprocessLines(rawText);
  let currentSection = 'Общее';
  const buckets = {};

  function addRow(sec, payload) {
    const key = normalizeSeries(sec);
    if (!buckets[key]) buckets[key] = [];
    buckets[key].push(payload);
  }

  for (const line of lines) {
    const t = line.trim();
    if (!t) continue;
    /* Заголовок секции без колонки трубы */
    if (!t.includes('\t')) {
      currentSection =
        /^наименование/i.test(t) || /^пробел/i.test(t) || /^су?ббо?т/ui.test(t)
          ? currentSection
          : /^максим|^среда|^суббо?т|^суббута|^наименование/i.test(t)
            ? currentSection
            : t;
      continue;
    }

    let parts = t.split(/\t/).map((p) => p.trim());
    if (parts.filter(Boolean).length < 4) continue;

    const rawNameLine = parts[0];
    if (/Wi-Fi|^модуль/i.test(parts[1] || '') || /^LCA-WFA/i.test(rawNameLine.trim())) continue;

    const status = (parts[parts.length - 1] || '').toLowerCase();
    if (!/в\s+наличии/i.test(status)) continue;

    const rrc = guessRrc(parts);
    if (rrc === null) continue;

    const btu = inferBtu(rawNameLine);
    if (btu === null) {
      console.warn('BTU?', rawNameLine);
      continue;
    }

    const modelNorm = rawNameLine.split(/\s+/)[0];
    addRow(currentSection, { modelNorm, btu, price: rrc, rawLine: rawNameLine });
  }

  const sortedSectionKeys = Object.keys(buckets).sort((a, b) =>
    normalizeSeries(a).localeCompare(normalizeSeries(b), 'ru')
  );

  const catalog = [];
  let id = 1;

  for (const sec of sortedSectionKeys) {
    const rows = buckets[sec];
    const { brand, factory, seriesHuman } = metaFromSection(sec);

    rows.sort((a, b) => a.btu - b.btu);
    const uniqBtu = new Map();
    for (const r of rows) uniqBtu.set(r.btu, r);

    const btuData = [...uniqBtu.entries()]
      .sort((x, y) => x[0] - y[0])
      .map(([btu, r]) => ({ btu, price: r.price }));

    if (!btuData.length) continue;

    const firstModel = uniqBtu.get(btuData[0].btu).modelNorm;
    const seriesLabel = normalizeSeries(seriesHuman.replace(/^\s*сери[я]?\s+/i, ''));
    const type = isInverterSeries(sec) ? 'inverter' : 'onoff';
    const maxP = Math.max(...btuData.map((x) => x.price));

    const img =
      hintMap[`${brand}|${seriesLabel}`] ||
      hintMap[`Mira|${seriesLabel}`] ||
      hintMap[seriesLabel] ||
      `${DEFAULT_IMG}`;

    catalog.push({
      id: id++,
      brand,
      factory,
      series: seriesLabel.slice(0, 120),
      model: firstModel.slice(0, 120),
      segment: segmentFor(sec, maxP),
      type,
      btuData,
      description: `${brand}. ${factory}. ${seriesLabel}. РРЦ и наличие по прайсу Люксхолод «зима 2026»`,
      image: img,
    });
  }

  catalog.sort((a, b) => {
    const s = segmentOrder[a.segment] - segmentOrder[b.segment];
    return s !== 0 ? s : a.series.localeCompare(b.series, 'ru');
  });

  catalog.forEach((p, i) => {
    p.id = i + 1;
  });

  fs.writeFileSync(OUT_PATH, JSON.stringify(catalog, null, 2), 'utf8');
  console.log(`OK: ${catalog.length} карточек → ${OUT_PATH}`);
}

const segmentOrder = { budget: 1, comfort: 2, premium: 3 };

main();
