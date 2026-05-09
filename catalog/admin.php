<?php
declare(strict_types=1);

session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$configPath = __DIR__ . '/admin-config.php';

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && isset($_GET['export'])
    && ($_GET['export'] === 'json')
    && !empty($_SESSION['catalog_admin'])
) {
    $path = __DIR__ . '/catalog-data.json';
    if (!is_readable($path)) {
        http_response_code(404);
        echo 'catalog-data.json не найден';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="catalog-data.json"');
    readfile($path);
    exit;
}

$jsonPath = __DIR__ . '/catalog-data.json';

function catalog_admin_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function catalog_admin_esc_textarea(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_json']) && !empty($_SESSION['catalog_admin'])) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_catalog'] ?? ''), $csrf)) {
        $saveErr = 'Сессия устарела, обновите страницу';
    } else {
        $raw = (string)($_POST['json'] ?? '');
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $saveErr = 'Некорректный JSON: ' . json_last_error_msg();
        } elseif (!is_array($data)) {
            $saveErr = 'Ожидается JSON-массив товаров';
        } elseif ($data === []) {
            $saveErr = 'Массив не должен быть пустым';
        } else {
            foreach ($data as $i => $row) {
                if (!is_array($row)) {
                    $saveErr = 'Элемент #' . ($i + 1) . ' не объект';
                    break;
                }
                foreach (['id', 'brand', 'factory', 'series', 'segment', 'type', 'description', 'image'] as $f) {
                    if (!isset($row[$f])) {
                        $saveErr = 'Товар #' . ($i + 1) . ': нет поля «' . $f . '»';
                        break 2;
                    }
                }
                if (!isset($row['btuData']) || !is_array($row['btuData']) || $row['btuData'] === []) {
                    $saveErr = 'Товар #' . ($i + 1) . ': btuData должен быть непустым массивом';
                    break;
                }
                foreach ($row['btuData'] as $j => $bd) {
                    if (!isset($bd['btu'], $bd['price']) || !is_numeric($bd['price'])) {
                        $saveErr = 'Товар #' . ($i + 1) . ', btu #' . ($j + 1) . ': нужны числовые btu и price';
                        break 2;
                    }
                }
            }
            if ($saveErr === '') {
                $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                if (@file_put_contents($jsonPath, $out . "\n", LOCK_EX) === false) {
                    $saveErr = 'Не удалось записать catalog-data.json — проверьте права на каталог.';
                } else {
                    $saveMsg = 'Каталог сохранён.';
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

$content = '';
if (!is_readable($jsonPath)) {
    $saveErr = 'Не найден catalog-data.json';
} else {
    $content = (string) file_get_contents($jsonPath);
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактор каталога · 8848</title>
    <style>
        body { margin:0; font-family:ui-monospace,Consolas,monospace; background:#111; color:#e5e5e5;}
        header { padding:1rem 1.25rem; background:#161616; border-bottom:1px solid #333; display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between;}
        header h1 { margin:0; font-family:system-ui,sans-serif; font-size:1rem; font-weight:600;}
        .actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;}
        textarea { width:100%; min-height:70vh; box-sizing:border-box; padding:1rem; background:#0d0d0d; color:#eaeaea; border:1px solid #333; resize:vertical; font-size:13px;}
        .msg { font-family:system-ui,sans-serif; font-size:.9rem;}
        .ok { color:#86efac;}
        .err { color:#f87171;}
        button,a.btn {
            padding:.5rem 1rem; border-radius:999px; border:2px solid #555;
            background:rgba(37,37,37,.92); color:#fff; cursor:pointer;
            text-decoration:none; font-family:system-ui,sans-serif; font-size:.82rem;
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
    </style>
</head>
<body>
<header>
    <h1>Редактор catalog-data.json · ВЕРШИНА 8848</h1>
    <div class="actions">
        <a class="btn" href="catalog-data.json?v=<?= (string)time(); ?>" target="_blank">Посмотреть JSON</a>
        <a class="btn" href="admin.php?export=json">Скачать JSON</a>
        <a class="btn" href="index.html">Каталог сайта</a>
        <form method="post" style="margin:0"><button type="submit" name="logout" value="1">Выйти</button></form>
    </div>
</header>
<div style="padding:1rem; max-width:1200px; margin:0 auto;">
<?php if ($saveMsg !== '') { ?><p class="msg ok"><?= catalog_admin_escape($saveMsg); ?></p><?php } ?>
<?php if ($saveErr !== '') { ?><p class="msg err"><?= catalog_admin_escape($saveErr); ?></p><?php } ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= catalog_admin_escape((string)$_SESSION['csrf_catalog']); ?>"/>
<textarea name="json" spellcheck="false"><?= catalog_admin_esc_textarea(str_ireplace('</textarea', '<\\/textarea', $content)); ?></textarea>
<p style="font-family:system-ui,sans-serif; font-size:.85rem; color:#888;">
Массив объектов: id, brand, factory, series, model (опц.), segment (budget|comfort|premium), type (onoff|inverter), btuData: [{btu, price}], description, image (URL).
</p>
<button class="primary" type="submit" name="save_json" value="1">Сохранить на сервер</button>
</form>
</div>
</body>
</html>
