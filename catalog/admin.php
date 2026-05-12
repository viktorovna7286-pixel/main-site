<?php
declare(strict_types=1);

session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$configPath = __DIR__ . '/admin-config.php';
$jsonCatalogPath = __DIR__ . '/catalog-data.json';
/** Раньше использовался отдельный черновик — подхватываем один раз, если основного файла ещё нет. */
$jsonLegacyDraftPath = __DIR__ . '/catalog-data-draft.json';

function catalog_admin_migrate_legacy_draft(string $catalogPath, string $legacyDraftPath): void
{
    if (is_readable($catalogPath)) {
        return;
    }
    if (!is_readable($legacyDraftPath)) {
        return;
    }
    @copy($legacyDraftPath, $catalogPath);
}

function catalog_admin_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function catalog_admin_esc_textarea(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Возвращает пустую строку при успехе, иначе — текст ошибки.
 */
function catalog_admin_validate_products(array $data): string
{
    if ($data === []) {
        return 'Массив не должен быть пустым';
    }
    foreach ($data as $i => $row) {
        if (!is_array($row)) {
            return 'Элемент #' . ($i + 1) . ' не объект';
        }
        foreach (['id', 'brand', 'factory', 'series', 'segment', 'type', 'description'] as $f) {
            if (!array_key_exists($f, $row)) {
                return 'Товар #' . ($i + 1) . ': нет поля «' . $f . '»';
            }
        }
        $images = [];
        if (isset($row['images']) && is_array($row['images'])) {
            foreach ($row['images'] as $u) {
                if (is_string($u) && trim($u) !== '') {
                    $images[] = trim($u);
                }
            }
        } elseif (isset($row['image']) && is_string($row['image']) && trim($row['image']) !== '') {
            $images[] = trim($row['image']);
        }
        if (count($images) < 1 || count($images) > 5) {
            return 'Товар #' . ($i + 1) . ': фотографии — от 1 до 5 штук (ссылка или загрузка)';
        }
        if (!isset($row['btuData']) || !is_array($row['btuData']) || $row['btuData'] === []) {
            return 'Товар #' . ($i + 1) . ': btuData должен быть непустым массивом';
        }
        foreach ($row['btuData'] as $j => $bd) {
            if (!isset($bd['btu'], $bd['price']) || !is_numeric($bd['price'])) {
                return 'Товар #' . ($i + 1) . ', btu #' . ($j + 1) . ': нужны числовые btu и price';
            }
        }
    }
    return '';
}

function catalog_admin_encode_catalog(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
}

/** @return array{ok:bool, url?:string, error?:string} */
function catalog_admin_save_product_image_upload(): array
{
    $uploadDir = __DIR__ . '/uploads/products';
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        return ['ok' => false, 'error' => 'Файл не получен'];
    }
    $f = $_FILES['image'];
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Ошибка загрузки файла'];
    }
    if (($f['size'] ?? 0) > 6 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Максимум 6 МБ'];
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Некорректная загрузка'];
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'error' => 'Нужно изображение (JPG, PNG, WebP или GIF)'];
    }
    $itype = isset($info[2]) ? (int) $info[2] : 0;
    switch ($itype) {
        case IMAGETYPE_JPEG:
            $ext = 'jpg';
            break;
        case IMAGETYPE_PNG:
            $ext = 'png';
            break;
        case IMAGETYPE_WEBP:
            $ext = 'webp';
            break;
        case IMAGETYPE_GIF:
            $ext = 'gif';
            break;
        default:
            return ['ok' => false, 'error' => 'Формат не поддерживается — используйте JPG, PNG, WebP или GIF'];
    }
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        return ['ok' => false, 'error' => 'Не удалось создать папку uploads/products'];
    }
    $base = 'p-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $base;
    if (!@move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'error' => 'Не удалось сохранить файл на сервере'];
    }
    return ['ok' => true, 'url' => 'uploads/products/' . $base];
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_POST['upload_product_image'])
    && !empty($_SESSION['catalog_admin'])
) {
    header('Content-Type: application/json; charset=utf-8');
    $csrfPost = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_catalog'] ?? ''), $csrfPost)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Сессия устарела'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = catalog_admin_save_product_image_upload();
    if (!$r['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'Ошибка'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'url' => $r['url']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && isset($_GET['export'])
    && ($_GET['export'] === 'json')
    && !empty($_SESSION['catalog_admin'])
) {
    if (!is_readable($jsonCatalogPath)) {
        http_response_code(404);
        echo 'catalog-data.json не найден';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="catalog-data.json"');
    readfile($jsonCatalogPath);
    exit;
}

$loginErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!file_exists($configPath)) {
        $loginErr = 'Создайте на сервере catalog/admin-config.php по образцу admin-config.example.php';
    } else {
        require $configPath;
        $pass = (string)($_POST['password'] ?? '');
        if (!defined('CATALOG_ADMIN_PASSWORD') || CATALOG_ADMIN_PASSWORD === '' || CATALOG_ADMIN_PASSWORD === 'СМЕНИТЕ_НА_СВОЙ_СЛОЖНЫЙ_ПАРОЛЬ') {
            $loginErr = 'В admin-config.php задайте CATALOG_ADMIN_PASSWORD';
        } elseif (hash_equals(CATALOG_ADMIN_PASSWORD, $pass)) {
            $_SESSION['catalog_admin'] = true;
            $_SESSION['csrf_catalog'] = bin2hex(random_bytes(24));
            header('Location: admin.php');
            exit;
        } else {
            $loginErr = 'Неверный пароль';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = [];
    header('Location: admin.php');
    exit;
}

$saveMsg = '';
$saveErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['catalog_admin'])) {
    $csrf = (string)($_POST['csrf'] ?? '');
    $csrfFail = !hash_equals((string)($_SESSION['csrf_catalog'] ?? ''), $csrf);

    if (isset($_POST['save_catalog'])) {
        if ($csrfFail) {
            $saveErr = 'Сессия устарела, обновите страницу';
        } else {
            $raw = (string)($_POST['catalog_json'] ?? '');
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $saveErr = 'Некорректный JSON: ' . json_last_error_msg();
            } elseif (!is_array($data)) {
                $saveErr = 'Ожидается JSON-массив товаров';
            } else {
                $saveErr = catalog_admin_validate_products($data);
                if ($saveErr === '') {
                    try {
                        $out = catalog_admin_encode_catalog($data);
                        catalog_admin_migrate_legacy_draft($jsonCatalogPath, $jsonLegacyDraftPath);
                        if (is_readable($jsonCatalogPath)) {
                            @copy($jsonCatalogPath, $jsonCatalogPath . '.prev');
                        }
                        if (@file_put_contents($jsonCatalogPath, $out, LOCK_EX) === false) {
                            $saveErr = 'Не удалось записать catalog-data.json — проверьте права на каталог.';
                        } else {
                            $saveMsg = 'Каталог сохранён (catalog-data.json). Резервная копия предыдущей версии: catalog-data.json.prev';
                        }
                    } catch (\Throwable $e) {
                        $saveErr = 'Ошибка кодирования JSON';
                    }
                }
            }
        }
    }
}

if (empty($_SESSION['catalog_admin'])) {
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход · Каталог 8848</title>
    <style>
        body { font-family: system-ui,sans-serif; background:#111; color:#eee; margin:0; display:flex; min-height:100vh; align-items:center; justify-content:center; }
        form { background:#1a1a1a; padding:2rem; border-radius:1rem; width:90%; max-width:360px; border:1px solid #333;}
        label { display:block; margin-bottom:.5rem; font-size:.9rem;}
        input { width:100%; padding:.75rem; border-radius:.5rem; border:1px solid #444; background:#222; color:#fff; margin-bottom:1rem; box-sizing:border-box;}
        button { width:100%; padding:.85rem; border-radius:999px; border:2px solid #d4bc6a; background:#C9A84C; color:#111; font-weight:bold; cursor:pointer;
            box-shadow:0 0 18px rgba(201,168,76,.35), inset 0 1px 0 rgba(255,255,255,.25);
            transition:box-shadow .3s ease, transform .15s ease, background .25s;}
        button:hover { box-shadow:0 0 26px rgba(201,168,76,.52), inset 0 1px 0 rgba(255,255,255,.3);}
        button:active { transform:scale(.98);}
        .err { color:#f87171; font-size:.85rem; margin:.5rem 0;}
        .hint { font-size:.75rem; color:#888; margin-top:1rem; line-height:1.5;}
    </style>
</head>
<body>
    <form method="post">
        <h2 style="margin-top:0">Админ · каталог</h2>
        <?php if (!empty($loginErr)) { ?>
            <p class="err"><?= catalog_admin_escape($loginErr); ?></p>
        <?php } ?>
        <label>Пароль</label>
        <input type="password" name="password" required autocomplete="current-password"/>
        <button type="submit" name="login" value="1">Войти</button>
        <p class="hint">На сервере должен быть PHP и файл catalog/admin-config.php<br><a href="index.html" style="color:#C9A84C">К каталогу</a></p>
    </form>
</body>
</html>
    <?php
    exit;
}

if (empty($_SESSION['csrf_catalog'])) {
    $_SESSION['csrf_catalog'] = bin2hex(random_bytes(24));
}

catalog_admin_migrate_legacy_draft($jsonCatalogPath, $jsonLegacyDraftPath);

$catalogContent = '';
if (!is_readable($jsonCatalogPath)) {
    $saveErr = $saveErr === '' ? 'Не найден catalog-data.json — положите файл в папку catalog/ или зайдите после копирования с сервера.' : $saveErr;
} else {
    $catalogContent = (string) file_get_contents($jsonCatalogPath);
}

$catalogParsed = [];
$catalogErrJson = '';
if ($catalogContent !== '') {
    $tmp = json_decode($catalogContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($tmp)) {
        $catalogErrJson = 'Каталог не читается как JSON: откройте режим «Код» и исправьте.';
    } else {
        $catalogParsed = $tmp;
    }
}

$catalogEmbedJson = '[]';
try {
    $catalogEmbedJson = json_encode(
        $catalogParsed,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_THROW_ON_ERROR
    );
} catch (\Throwable $e) {
    $catalogErrJson = 'Не удалось подготовить данные для формы';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактор каталога · 8848</title>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:ital,wght@0,500;0,700;1,600&family=Montserrat:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-bg: #1a1a1a;
            --color-accent: #C9A84C;
            --color-accent-light: #e8c96b;
        }
        body { margin:0; font-family:'Raleway',system-ui,sans-serif; background:#111; color:#e5e5e5;}
        header { padding:1rem 1.25rem; background:#161616; border-bottom:1px solid #333; display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between;}
        header h1 { margin:0; font-size:1rem; font-weight:600;}
        .actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;}
        .msg { font-size:.9rem;}
        .ok { color:#86efac;}
        .err { color:#f87171;}
        button,a.btn {
            padding:.5rem 1rem; border-radius:999px; border:2px solid #555;
            background:rgba(37,37,37,.92); color:#fff; cursor:pointer;
            text-decoration:none; font-size:.82rem;
            box-shadow:0 0 10px rgba(255,255,255,.06), inset 0 1px 0 rgba(255,255,255,.05);
            transition:box-shadow .3s ease, border-color .2s;}
        button:hover,a.btn:hover {
            border-color:#777;
            box-shadow:0 0 16px rgba(255,255,255,.08), inset 0 1px 0 rgba(255,255,255,.07);}
        button.primary {
            border-color:#C9A84C;
            background:#C9A84C;
            color:#111;
            font-weight:600;
            box-shadow:0 0 18px rgba(201,168,76,.38), inset 0 1px 0 rgba(255,255,255,.28);}
        button.primary:hover { box-shadow:0 0 28px rgba(201,168,76,.52), inset 0 1px 0 rgba(255,255,255,.35);}
        button.danger-outline { border-color:#b45309; color:#fdba74;}
        .tabs { display:flex; gap:.35rem; flex-wrap:wrap; margin:1rem 0 .75rem;}
        .tabs button { border-radius:.5rem; font-size:.85rem;}
        .tabs button[aria-selected="true"] { border-color:#C9A84C; color:#f5e6b8;}
        .panel { display:none;}
        .panel.active { display:block;}
        .layout-editor { display:grid; grid-template-columns:minmax(200px,240px) minmax(300px,1fr) minmax(260px,300px); gap:1rem; align-items:start;}
        @media (max-width:1040px) { .layout-editor { grid-template-columns:1fr; } }
        .col-preview { position:sticky; top:1rem; }
        @media (max-width:1040px) { .col-preview { position:static; max-width:340px; margin:0 auto;} }
        .preview-label { font-size:.75rem; color:#a8a8a8; margin:0 0 .5rem; text-transform:uppercase; letter-spacing:.12em;}
        .premium-card { background:linear-gradient(145deg,#232323 0%,#1a1a1a 100%); border:1px solid rgba(255,255,255,.06); }
        .catalog-card-hit {
            transition:border-color .45s ease, box-shadow .45s ease;
            box-shadow:0 20px 25px -5px rgba(0,0,0,.38),0 8px 10px -6px rgba(0,0,0,.22);
            border:1px solid rgba(255,255,255,.05); border-radius:2.15rem; overflow:hidden;
            min-height:22rem; display:flex; flex-direction:column; cursor:default;
        }
        .catalog-chip {
            display:inline-block; padding:.28rem .55rem; border-radius:.375rem; font-size:7px;
            font-family:'Raleway',sans-serif; font-weight:700; text-transform:uppercase;
            letter-spacing:.12em; line-height:1.2; backdrop-filter:blur(10px);
        }
        .catalog-chip--seg-budget { border:1px solid rgba(120,190,200,.6); color:#b8eaf0; background:rgba(18,40,48,.82);}
        .catalog-chip--seg-comfort { border:1px solid rgba(120,200,155,.58); color:#bff0d4; background:rgba(22,48,36,.82);}
        .catalog-chip--seg-premium { border:1px solid rgba(200,165,230,.55); color:#ebd4ff; background:rgba(48,32,58,.82);}
        .catalog-chip--seg-fallback { border:1px solid rgba(201,168,76,.45); color:rgba(232,201,107,.95); background:rgba(35,28,12,.78);}
        .catalog-chip--type-onoff { border:1px solid rgba(205,180,135,.55); color:#ebe0cc; background:rgba(48,38,24,.82);}
        .catalog-chip--type-inverter { border:1px solid rgba(115,185,255,.55); color:#c8e4ff; background:rgba(24,36,58,.82);}
        .catalog-chip--type-fallback { border:1px solid rgba(180,175,170,.45); color:rgba(220,216,210,.95); background:rgba(40,40,42,.78);}
        .pv-img-wrap { height:12rem; flex-shrink:0; position:relative; overflow:hidden; background:#0a0a0a;}
        .pv-img-wrap img { width:100%; height:100%; object-fit:cover; filter:grayscale(.35); opacity:.85;}
        .pv-body { padding:1.35rem 1.5rem 1.25rem; flex:1; display:flex; flex-direction:column; min-height:0;}
        .font-title { font-family:'Raleway',sans-serif; font-weight:700; }
        .font-numbers { font-family:'Montserrat',sans-serif; font-weight:500; }
        .font-numbers-bold { font-family:'Montserrat',sans-serif; font-weight:700; }
        .upload-row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;}
        .upload-status { font-size:.78rem; color:#94a3b8; }
        .upload-status.err { color:#f87171;}
        .upload-status.ok { color:#86efac;}
        .photo-slot-row { display:grid; grid-template-columns:64px 1fr auto; gap:.5rem; align-items:end; margin-bottom:.55rem;}
        @media (max-width:520px) { .photo-slot-row { grid-template-columns:1fr; } .photo-slot-row .ph-thumb-cell { display:none;} }
        .photo-thumb { width:64px; height:64px; object-fit:cover; border-radius:.45rem; background:#222; border:1px solid #333;}
        .form-section-title { font-size:.85rem; font-weight:600; color:#e8e8e8; margin:1rem 0 .5rem; padding-bottom:.25rem; border-bottom:1px solid #333;}
        .list { background:#141414; border:1px solid #333; border-radius:.75rem; max-height:65vh; overflow:auto;}
        .list button.item {
            display:block; width:100%; text-align:left; padding:.65rem .85rem; border:none; border-bottom:1px solid #2a2a2a;
            background:transparent; color:#e5e5e5; font-size:.85rem; border-radius:0; box-shadow:none;}
        .list button.item:hover { background:#1f1f1f;}
        .list button.item.active { background:#2a2418; color:#f5e6b8;}
        .card { background:#141414; border:1px solid #333; border-radius:.75rem; padding:1rem 1.1rem;}
        label.f { display:block; font-size:.78rem; color:#a3a3a3; margin:.35rem 0 .2rem;}
        input.in, select.in, textarea.in {
            width:100%; box-sizing:border-box; padding:.55rem .65rem; border-radius:.45rem; border:1px solid #444;
            background:#0d0d0d; color:#eee; font-size:.9rem;}
        textarea.in { min-height:4.5rem; resize:vertical; font-family:inherit;}
        .row2 { display:grid; grid-template-columns:1fr 1fr; gap:.65rem;}
        @media (max-width:600px) { .row2 { grid-template-columns:1fr; } }
        .btu-row { display:grid; grid-template-columns:1fr 1fr auto; gap:.5rem; align-items:end; margin-bottom:.5rem;}
        .mono { font-family:ui-monospace,Consolas,monospace;}
        textarea.code { width:100%; min-height:55vh; box-sizing:border-box; padding:1rem; background:#0d0d0d; color:#eaeaea; border:1px solid #333; resize:vertical; font-size:13px;}
        .hint { font-size:.82rem; color:#888; line-height:1.45; margin:.5rem 0 0;}
        .toolbar { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.75rem;}
    </style>
</head>
<body>
<header>
    <h1>Каталог · ВЕРШИНА 8848</h1>
    <div class="actions">
        <a class="btn" href="index.html" target="_blank" rel="noopener">Каталог</a>
        <a class="btn" href="catalog-data.json?v=<?= (string)time(); ?>" target="_blank" rel="noopener">JSON</a>
        <a class="btn" href="admin.php?export=json">Скачать</a>
        <form method="post" style="margin:0"><button type="submit" name="logout" value="1">Выйти</button></form>
    </div>
</header>
<div style="padding:1rem; max-width:1100px; margin:0 auto;">
<?php if ($saveMsg !== '') { ?><p class="msg ok"><?= catalog_admin_escape($saveMsg); ?></p><?php } ?>
<?php if ($saveErr !== '') { ?><p class="msg err"><?= catalog_admin_escape($saveErr); ?></p><?php } ?>
<?php if ($catalogErrJson !== '') { ?><p class="msg err"><?= catalog_admin_escape($catalogErrJson); ?></p><?php } ?>

<p class="hint" style="margin-top:0">
    Всё хранится в <span class="mono">catalog-data.json</span>. «Сохранить каталог» — сразу на сайте; копия прошлой версии: <span class="mono">catalog-data.json.prev</span>. На позицию — 1–5 фото.
</p>

<div class="tabs" role="tablist">
    <button type="button" class="tab" role="tab" aria-selected="true" data-panel="simple">Форма</button>
    <button type="button" class="tab" role="tab" aria-selected="false" data-panel="code">JSON</button>
</div>

<div id="panel-simple" class="panel active">
    <input type="hidden" id="admin-csrf" value="<?= catalog_admin_escape((string)$_SESSION['csrf_catalog']); ?>"/>
    <?php if ($catalogErrJson === '') { ?>
    <div class="layout-editor">
        <div class="col-list">
            <div class="toolbar">
                <button type="button" class="primary" id="btn-add">+ Товар</button>
            </div>
            <div class="list" id="product-list" role="listbox" aria-label="Список товаров"></div>
        </div>
        <div class="card" id="editor-wrap">
            <p id="editor-placeholder" style="margin:0; color:#888; font-size:.9rem;">Выберите товар слева или добавьте новый.</p>
            <div id="editor" style="display:none;">
                <p class="form-section-title" style="margin-top:0;">Данные модели</p>
                <div class="row2">
                    <div><label class="f" for="fld-id">Номер в каталоге (id)</label><input class="in" id="fld-id" type="number" min="1" step="1"/></div>
                    <div><label class="f" for="fld-model">Артикул / модель</label><input class="in mono" id="fld-model" placeholder="можно пусто"/></div>
                </div>
                <div class="row2">
                    <div><label class="f" for="fld-brand">Бренд</label><input class="in" id="fld-brand" placeholder="например, Ballu"/></div>
                    <div><label class="f" for="fld-factory">Завод-производитель</label><input class="in" id="fld-factory" placeholder="например, Hisense"/></div>
                </div>
                <label class="f" for="fld-series">Серия (название линейки)</label><input class="in" id="fld-series"/>
                <div class="row2">
                    <div>
                        <label class="f" for="fld-segment">Сегмент цены</label>
                        <select class="in" id="fld-segment">
                            <option value="budget">Бюджет</option>
                            <option value="comfort">Комфорт</option>
                            <option value="premium">Премиум</option>
                        </select>
                    </div>
                    <div>
                        <label class="f" for="fld-type">Тип компрессора</label>
                        <select class="in" id="fld-type">
                            <option value="onoff">On/Off</option>
                            <option value="inverter">Инвертор</option>
                        </select>
                    </div>
                </div>
                <label class="f" for="fld-desc">Описание для сайта</label>
                <textarea class="in" id="fld-desc" rows="4" placeholder="Краткий текст как в каталоге"></textarea>

                <p class="form-section-title">Фотографии</p>
                <p class="hint" style="margin:0 0 .6rem;">1–5 шт., до 6 МБ, файл или URL. Первое — в сетке каталога.</p>
                <div class="upload-row" style="margin-bottom:.4rem;">
                    <input type="file" id="fld-image-file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none"/>
                    <span class="upload-status" id="upload-status" aria-live="polite"></span>
                </div>
                <div id="photo-slots"></div>

                <p class="form-section-title">Мощности (BTU) и цена</p>
                <p class="hint" style="margin:0 0 .5rem;">Строка на каждую мощность из прайса.</p>
                <div id="btu-rows"></div>
                <button type="button" id="btn-btu-add" class="btn" style="margin-top:.35rem;">+ Строка BTU</button>

                <div class="toolbar" style="margin-top:1rem;">
                    <button type="button" class="danger-outline" id="btn-delete">Удалить этот товар</button>
                </div>
            </div>
        </div>
        <div class="col-preview">
            <p class="preview-label">Предпросмотр карточки</p>
            <div id="live-card" class="catalog-card-hit premium-card" aria-hidden="true">
                <div class="pv-img-wrap">
                    <img id="pv-img" src="" alt=""/>
                    <div style="position:absolute;inset:0;background:linear-gradient(to top,#0a0a0a,transparent);pointer-events:none;"></div>
                    <div style="position:absolute;top:1rem;left:1rem;display:flex;flex-direction:column;gap:.25rem;">
                        <span id="pv-chip-seg" class="catalog-chip catalog-chip--seg-budget">Бюджет</span>
                        <span id="pv-chip-type" class="catalog-chip catalog-chip--type-onoff">On/Off</span>
                    </div>
                </div>
                <div class="pv-body">
                    <div style="flex:1;">
                        <p class="font-title" style="font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#ca8a04;margin:0 0 .25rem;" id="pv-brand-line">—</p>
                        <h3 class="font-title" style="font-size:1rem;margin:0;color:rgba(255,255,255,.9);font-style:italic;text-transform:uppercase;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;" id="pv-series">—</h3>
                        <p class="font-numbers" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin:.4rem 0 0;color:rgba(209,213,219,.95);" id="pv-model">—</p>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:flex-end;border-top:1px solid rgba(255,255,255,.06);padding-top:1rem;margin-top:auto;">
                        <div>
                            <span style="font-size:8px;color:#737373;display:block;text-transform:uppercase;letter-spacing:.08em;" class="font-title">Цена</span>
                            <p class="font-numbers-bold" style="font-size:1.25rem;margin:0;color:#eab308;line-height:1;" id="pv-price">от 0 р.</p>
                        </div>
                        <div style="background:rgba(202,138,4,.15);padding:.5rem;border-radius:.75rem;color:#eab308;line-height:0;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                    </div>
                </div>
            </div>
            <p class="hint">Предпросмотр карточки как на сайте.</p>
        </div>
    </div>
    <form method="post" id="form-save-visual">
        <input type="hidden" name="csrf" value="<?= catalog_admin_escape((string)$_SESSION['csrf_catalog']); ?>"/>
        <input type="hidden" name="catalog_json" id="catalog-json-visual"/>
        <button class="primary" type="submit" name="save_catalog" value="1">Сохранить каталог</button>
    </form>
    <?php } else { ?>
    <p>Откройте вкладку «JSON» и исправьте данные.</p>
    <?php } ?>
</div>

<div id="panel-code" class="panel">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= catalog_admin_escape((string)$_SESSION['csrf_catalog']); ?>"/>
        <textarea class="code mono" name="catalog_json" spellcheck="false"><?= catalog_admin_esc_textarea(str_ireplace('</textarea', '<\\/textarea', $catalogContent)); ?></textarea>
        <p class="hint">id, brand, factory, series, segment, type, btuData, description, <strong>images</strong> (1–5 URL), model по желанию.</p>
        <div class="toolbar" style="margin-top:.75rem;">
            <button class="primary" type="submit" name="save_catalog" value="1">Сохранить каталог</button>
        </div>
    </form>
</div>

</div>

<script type="application/json" id="initial-catalog-json"><?= $catalogEmbedJson; ?></script>
<script>
(function () {
    const tabs = document.querySelectorAll('.tab');
    const panels = { simple: document.getElementById('panel-simple'), code: document.getElementById('panel-code') };
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.setAttribute('aria-selected', 'false'); });
            t.setAttribute('aria-selected', 'true');
            const id = t.getAttribute('data-panel');
            Object.keys(panels).forEach(function (k) {
                panels[k].classList.toggle('active', k === id);
            });
        });
    });

    const slug = document.getElementById('initial-catalog-json');
    if (!slug) return;

    var CATALOG_LABELS = {
        segment: { budget: 'Бюджет', comfort: 'Комфорт', premium: 'Премиум' },
        type: { inverter: 'Инвертор', onoff: 'On/Off' }
    };
    function chipSegClass(seg) {
        var k = String(seg || '').toLowerCase();
        if (k === 'budget' || k === 'comfort' || k === 'premium') return 'catalog-chip catalog-chip--seg-' + k;
        return 'catalog-chip catalog-chip--seg-fallback';
    }
    function chipTypeClass(ty) {
        var k = String(ty || '').toLowerCase();
        if (k === 'inverter') return 'catalog-chip catalog-chip--type-inverter';
        if (k === 'onoff') return 'catalog-chip catalog-chip--type-onoff';
        return 'catalog-chip catalog-chip--type-fallback';
    }

    let catalog = [];
    try {
        catalog = JSON.parse(slug.textContent || '[]');
        if (!Array.isArray(catalog)) catalog = [];
    } catch (e) {
        catalog = [];
    }

    const listEl = document.getElementById('product-list');
    const editor = document.getElementById('editor');
    const placeholder = document.getElementById('editor-placeholder');
    if (!listEl || !editor) return;
    const liveCard = document.getElementById('live-card');
    const pvImg = document.getElementById('pv-img');
    const pvChipSeg = document.getElementById('pv-chip-seg');
    const pvChipType = document.getElementById('pv-chip-type');
    const pvBrandLine = document.getElementById('pv-brand-line');
    const pvSeries = document.getElementById('pv-series');
    const pvModel = document.getElementById('pv-model');
    const pvPrice = document.getElementById('pv-price');
    const csrfInput = document.getElementById('admin-csrf');
    let selected = -1;

    var PH_IMG = 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=800';

    function nextId() {
        var ids = catalog.map(function (p) { return Number(p.id) || 0; });
        return (ids.length ? Math.max.apply(null, ids) : 0) + 1;
    }

    function productImagesListFromObj(p) {
        var out = [];
        if (Array.isArray(p.images) && p.images.length) {
            p.images.forEach(function (s) {
                var t = String(s || '').trim();
                if (t) out.push(t);
            });
        } else if (p.image && String(p.image).trim()) {
            out.push(String(p.image).trim());
        }
        return out.slice(0, 5);
    }

    function urlsForFiveInputs(p) {
        var list = productImagesListFromObj(p);
        var out = list.slice(0, 5);
        while (out.length < 5) out.push('');
        return out;
    }

    function normalizeProduct(p) {
        var imgs = productImagesListFromObj(p);
        if (imgs.length === 0) imgs = [PH_IMG];
        var o = {
            id: Number(p.id) || nextId(),
            brand: String(p.brand || ''),
            factory: String(p.factory || ''),
            series: String(p.series || ''),
            segment: (['budget', 'comfort', 'premium'].indexOf(p.segment) >= 0) ? p.segment : 'budget',
            type: (p.type === 'inverter') ? 'inverter' : 'onoff',
            btuData: Array.isArray(p.btuData) ? p.btuData.map(function (b) {
                return { btu: Number(b.btu) || 0, price: Number(b.price) || 0 };
            }) : [{ btu: 7, price: 0 }],
            description: String(p.description || ''),
            images: imgs,
            image: imgs[0] || ''
        };
        if (p.model !== undefined && p.model !== null && String(p.model) !== '') {
            o.model = String(p.model);
        }
        return o;
    }

    catalog = catalog.map(normalizeProduct);

    var fldId = document.getElementById('fld-id');
    var fldModel = document.getElementById('fld-model');
    var fldBrand = document.getElementById('fld-brand');
    var fldFactory = document.getElementById('fld-factory');
    var fldSeries = document.getElementById('fld-series');
    var fldSegment = document.getElementById('fld-segment');
    var fldType = document.getElementById('fld-type');
    var fldDesc = document.getElementById('fld-desc');
    var btuWrap = document.getElementById('btu-rows');
    var fileIn = document.getElementById('fld-image-file');
    var uploadSlot = 0;

    function readBtuRows() {
        var rows = btuWrap.querySelectorAll('.btu-row');
        var out = [];
        rows.forEach(function (row) {
            var b = row.querySelector('.btu-btu');
            var pr = row.querySelector('.btu-price');
            out.push({ btu: Number(b && b.value) || 0, price: Number(pr && pr.value) || 0 });
        });
        return out.length ? out : [{ btu: 7, price: 0 }];
    }

    function renderBtuRows(data) {
        btuWrap.innerHTML = '';
        data.forEach(function (bd) {
            var row = document.createElement('div');
            row.className = 'btu-row';
            row.innerHTML =
                '<div><label class="f">BTU</label><input type="number" class="in btu-btu" min="0" step="1" value="' + String(bd.btu) + '"/></div>' +
                '<div><label class="f">Цена (₽)</label><input type="number" class="in btu-price" min="0" step="1" value="' + String(bd.price) + '"/></div>' +
                '<button type="button" class="btn rm-btu" title="Убрать строку">✕</button>';
            btuWrap.appendChild(row);
        });
        btuWrap.querySelectorAll('.rm-btu').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var r = btn.closest('.btu-row');
                if (r && btuWrap.querySelectorAll('.btu-row').length > 1) r.remove();
            });
        });
    }

    function readImagesFromSlots() {
        var out = [];
        var i;
        for (i = 0; i < 5; i++) {
            var el = document.getElementById('photo-url-' + i);
            if (el && el.value.trim()) out.push(el.value.trim());
        }
        return out;
    }

    function updatePhotoThumbs() {
        var i;
        for (i = 0; i < 5; i++) {
            var inp = document.getElementById('photo-url-' + i);
            var th = document.getElementById('photo-thumb-' + i);
            if (!inp || !th) continue;
            var v = inp.value.trim();
            if (v) {
                th.src = v;
                th.style.opacity = '1';
            } else {
                th.removeAttribute('src');
                th.style.opacity = '0.25';
            }
        }
    }

    function renderPhotoSlots(fiveVals) {
        var wrap = document.getElementById('photo-slots');
        if (!wrap) return;
        wrap.innerHTML = '';
        var i;
        for (i = 0; i < 5; i++) {
            var row = document.createElement('div');
            row.className = 'photo-slot-row';
            var thumbCell = document.createElement('div');
            thumbCell.className = 'ph-thumb-cell';
            var thumb = document.createElement('img');
            thumb.className = 'photo-thumb';
            thumb.id = 'photo-thumb-' + i;
            thumb.alt = '';
            thumbCell.appendChild(thumb);
            var mid = document.createElement('div');
            var lab = document.createElement('label');
            lab.className = 'f';
            lab.setAttribute('for', 'photo-url-' + i);
            lab.textContent = (i === 0) ? 'Фото 1 — главное (обязательно)' : ('Фото ' + (i + 1) + ' — по желанию');
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'in mono photo-url';
            inp.id = 'photo-url-' + i;
            inp.placeholder = 'Загрузите файл (кнопка) или вставьте URL';
            inp.value = fiveVals[i] || '';
            mid.appendChild(lab);
            mid.appendChild(inp);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn primary btn-pick-photo';
            btn.setAttribute('data-slot', String(i));
            btn.textContent = 'Файл';
            row.appendChild(thumbCell);
            row.appendChild(mid);
            row.appendChild(btn);
            wrap.appendChild(row);
        }
        updatePhotoThumbs();
    }

    function pushFieldsToCatalog() {
        if (selected < 0 || !catalog[selected]) return;
        var p = catalog[selected];
        p.id = Number(fldId.value) || p.id;
        p.brand = fldBrand.value.trim();
        p.factory = fldFactory.value.trim();
        p.series = fldSeries.value.trim();
        var m = fldModel.value.trim();
        if (m) p.model = m; else delete p.model;
        p.segment = fldSegment.value;
        p.type = fldType.value;
        p.description = fldDesc.value;
        var imgs = readImagesFromSlots();
        p.images = imgs;
        p.image = imgs[0] || '';
        p.btuData = readBtuRows();
    }

    function refreshCardPreview() {
        if (!liveCard || !pvImg || !pvChipSeg || !pvChipType) return;
        if (selected < 0 || !catalog[selected]) {
            liveCard.style.opacity = '0.38';
            pvImg.removeAttribute('src');
            pvImg.alt = '';
            return;
        }
        liveCard.style.opacity = '1';
        pushFieldsToCatalog();
        var p = catalog[selected];
        var segKey = String(p.segment || '').toLowerCase();
        var typeKey = String(p.type || '').toLowerCase();
        pvChipSeg.className = chipSegClass(p.segment);
        pvChipSeg.textContent = CATALOG_LABELS.segment[segKey] || p.segment || '—';
        pvChipType.className = chipTypeClass(p.type);
        pvChipType.textContent = CATALOG_LABELS.type[typeKey] || p.type || '—';
        var bp = String(p.brand || '').trim() || '—';
        var fp = String(p.factory || '').trim() || '—';
        pvBrandLine.textContent = bp + ' — завод ' + fp;
        pvSeries.textContent = String(p.series || '').trim() || '—';
        pvModel.textContent = (p.model && String(p.model).trim()) ? String(p.model).trim() : '—';
        var prices = (p.btuData || []).map(function (x) { return Number(x.price); }).filter(function (n) { return !isNaN(n); });
        var minP = prices.length ? Math.min.apply(null, prices) : 0;
        pvPrice.textContent = 'от ' + minP.toLocaleString('ru-RU') + ' р.';
        var imgs = productImagesListFromObj(p);
        var u = imgs[0] || String(p.image || '').trim();
        if (!u) {
            pvImg.removeAttribute('src');
            pvImg.alt = '';
        } else {
            pvImg.src = u;
            pvImg.alt = String(p.series || 'Фото товара');
        }
    }

    function showEditor(ix) {
        selected = ix;
        if (ix < 0 || !catalog[ix]) {
            editor.style.display = 'none';
            placeholder.style.display = 'block';
            refreshCardPreview();
            return;
        }
        placeholder.style.display = 'none';
        editor.style.display = 'block';
        var p = catalog[ix];
        fldId.value = String(p.id);
        fldModel.value = p.model != null ? String(p.model) : '';
        fldBrand.value = p.brand;
        fldFactory.value = p.factory;
        fldSeries.value = p.series;
        fldSegment.value = p.segment;
        fldType.value = p.type;
        fldDesc.value = p.description;
        renderPhotoSlots(urlsForFiveInputs(p));
        renderBtuRows(p.btuData && p.btuData.length ? p.btuData : [{ btu: 7, price: 0 }]);
        document.querySelectorAll('#product-list .item').forEach(function (b, i) {
            b.classList.toggle('active', i === ix);
        });
        refreshCardPreview();
    }

    function renderList() {
        listEl.innerHTML = '';
        catalog.forEach(function (p, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'item';
            b.textContent = (p.brand || '—') + ' · ' + (p.series || 'без серии');
            b.addEventListener('click', function () {
                if (selected >= 0) pushFieldsToCatalog();
                showEditor(i);
            });
            listEl.appendChild(b);
        });
    }

    ['input', 'change'].forEach(function (ev) {
        [fldId, fldModel, fldBrand, fldFactory, fldSeries, fldSegment, fldType, fldDesc].forEach(function (el) {
            el.addEventListener(ev, function () {
                pushFieldsToCatalog();
                if (selected >= 0 && listEl.children[selected]) {
                    listEl.children[selected].textContent =
                        (fldBrand.value.trim() || '—') + ' · ' + (fldSeries.value.trim() || 'без серии');
                }
                refreshCardPreview();
            });
        });
    });

    editor.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.classList) return;
        if (t.classList.contains('photo-url')) {
            updatePhotoThumbs();
            pushFieldsToCatalog();
            refreshCardPreview();
            return;
        }
        if (t.classList.contains('btu-btu') || t.classList.contains('btu-price')) {
            pushFieldsToCatalog();
            refreshCardPreview();
        }
    });

    editor.addEventListener('click', function (e) {
        var b = e.target && e.target.closest && e.target.closest('.btn-pick-photo');
        if (!b || !fileIn) return;
        uploadSlot = parseInt(b.getAttribute('data-slot'), 10);
        if (isNaN(uploadSlot)) uploadSlot = 0;
        fileIn.click();
    });

    document.getElementById('btn-btu-add').addEventListener('click', function () {
        pushFieldsToCatalog();
        var rows = readBtuRows();
        rows.push({ btu: rows.length ? rows[rows.length - 1].btu : 7, price: 0 });
        renderBtuRows(rows);
        if (selected >= 0) catalog[selected].btuData = readBtuRows();
        refreshCardPreview();
    });

    document.getElementById('btn-add').addEventListener('click', function () {
        if (selected >= 0) pushFieldsToCatalog();
        catalog.push(normalizeProduct({
            id: nextId(),
            brand: '', factory: '', series: '', segment: 'budget', type: 'onoff',
            btuData: [{ btu: 7, price: 0 }],
            description: '',
            images: [PH_IMG],
            image: PH_IMG
        }));
        renderList();
        showEditor(catalog.length - 1);
    });

    document.getElementById('btn-delete').addEventListener('click', function () {
        if (selected < 0 || !confirm('Удалить этот товар из каталога?')) return;
        catalog.splice(selected, 1);
        renderList();
        showEditor(catalog.length ? 0 : -1);
    });

    if (fileIn && csrfInput) {
        fileIn.addEventListener('change', function () {
            var f = fileIn.files && fileIn.files[0];
            var status = document.getElementById('upload-status');
            if (!f) return;
            var slotInp = document.getElementById('photo-url-' + uploadSlot);
            status.textContent = 'Загрузка…';
            status.className = 'upload-status';
            var fd = new FormData();
            fd.append('csrf', csrfInput.value);
            fd.append('upload_product_image', '1');
            fd.append('image', f);
            fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) {
                    return res.json().catch(function () { return null; }).then(function (j) {
                        return { ok: res.ok, j: j };
                    });
                })
                .then(function (pack) {
                    var j = pack.j;
                    if (j && j.ok && j.url && slotInp) {
                        slotInp.value = j.url;
                        updatePhotoThumbs();
                        pushFieldsToCatalog();
                        refreshCardPreview();
                        status.className = 'upload-status ok';
                        status.textContent = 'Фото ' + (uploadSlot + 1) + ' сохранено. Сохраните каталог.';
                    } else {
                        status.className = 'upload-status err';
                        status.textContent = (j && j.error) ? j.error : 'Не удалось загрузить';
                    }
                    fileIn.value = '';
                })
                .catch(function () {
                    var st = document.getElementById('upload-status');
                    st.className = 'upload-status err';
                    st.textContent = 'Ошибка сети или сервера';
                    fileIn.value = '';
                });
        });
    }

    var formVis = document.getElementById('form-save-visual');
    if (formVis) {
        formVis.addEventListener('submit', function (e) {
            pushFieldsToCatalog();
            var k;
            for (k = 0; k < catalog.length; k++) {
                var imgs = productImagesListFromObj(catalog[k]);
                if (imgs.length < 1 || imgs.length > 5) {
                    e.preventDefault();
                    alert('Позиция «' + (catalog[k].series || catalog[k].brand || ('#' + (k + 1))) + '»: нужно от 1 до 5 фотографий.');
                    showEditor(k);
                    return false;
                }
            }
            document.getElementById('catalog-json-visual').value = JSON.stringify(catalog);
        });
    }

    renderList();
    if (catalog.length) showEditor(0);
    else { showEditor(-1); refreshCardPreview(); }

    window.addEventListener('beforeunload', function () {
        pushFieldsToCatalog();
    });
})();
</script>
</body>
</html>
