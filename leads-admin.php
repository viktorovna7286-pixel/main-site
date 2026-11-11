<?php
declare(strict_types=1);
require __DIR__ . '/leads-lib.php';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
function h($text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$_SESSION['csrf_leads'] ??= bin2hex(random_bytes(24));
$error = ''; $message = ''; $chats = [];
$statuses = ['new' => 'Новая', 'contacted' => 'Связались', 'survey' => 'Замер', 'installation' => 'Монтаж', 'done' => 'Завершена'];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_leads'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Обновите страницу.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            if (!leads_limit('login', 8, 900)) throw new RuntimeException('Слишком много попыток входа. Попробуйте через 15 минут.');
            $config = __DIR__ . '/catalog/admin-config.php';
            if (is_file($config)) require_once $config;
            if (!defined('CATALOG_ADMIN_PASSWORD') || CATALOG_ADMIN_PASSWORD === '' || CATALOG_ADMIN_PASSWORD === 'СМЕНИТЕ_НА_СВОЙ_СЛОЖНЫЙ_ПАРОЛЬ' || !hash_equals(CATALOG_ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) throw new RuntimeException('Неверный пароль или пароль админки ещё не настроен.');
            session_regenerate_id(true); $_SESSION['catalog_admin'] = true;
        } elseif (empty($_SESSION['catalog_admin'])) {
            throw new RuntimeException('Войдите в админку.');
        } elseif ($action === 'logout') {
            $_SESSION = []; session_regenerate_id(true); header('Location: leads-admin.php'); exit;
        } elseif ($action === 'token') {
            $token = trim((string)($_POST['token'] ?? ''));
            $bot = leads_telegram($token, 'getMe')['result'];
            leads_write(leads_dir() . '/telegram.json', ['token' => $token, 'username' => $bot['username'], 'enabled' => false]);
            unset($_SESSION['lead_chats']); $message = 'Бот сохранён. Нажмите «Старт» у бота в Telegram, затем «Найти чаты».';
        } elseif ($action === 'chats') {
            $settings = leads_settings();
            $updates = leads_telegram($settings['token'] ?? '', 'getUpdates', ['timeout' => 0, 'limit' => 100])['result'];
            foreach ($updates as $update) {
                $chat = $update['message']['chat'] ?? null;
                if (is_array($chat) && isset($chat['id'])) $chats[(string)$chat['id']] = trim(($chat['title'] ?? '') . ' ' . ($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
            }
            $_SESSION['lead_chats'] = $chats;
            if (!$chats) $message = 'Чатов пока нет. Отправьте боту /start и повторите поиск. Для этой настройки используйте отдельного бота без webhook.';
        } elseif ($action === 'connect') {
            $id = (string)($_POST['chat_id'] ?? '');
            if (!isset($_SESSION['lead_chats'][$id])) throw new RuntimeException('Сначала найдите и выберите свой чат.');
            $settings = leads_settings();
            leads_telegram($settings['token'], 'sendMessage', ['chat_id' => $id, 'text' => '8848 ВЕРШИНА: уведомления подключены. Новые заявки с сайта будут приходить сюда.']);
            $settings['chat_id'] = $id; $settings['chat_name'] = $_SESSION['lead_chats'][$id]; $settings['enabled'] = true;
            leads_write(leads_dir() . '/telegram.json', $settings); $message = 'Telegram подключён. Тестовое сообщение отправлено.';
        } elseif (in_array($action, ['status', 'retry'], true)) {
            $id = (string)($_POST['id'] ?? '');
            if (!preg_match('/^[A-F0-9]{10}$/D', $id)) throw new RuntimeException('Неверный номер заявки.');
            foreach (glob(leads_dir() . '/lead-*.json') ?: [] as $file) {
                $record = leads_read($file); if (($record['id'] ?? '') !== $id) continue;
                $lock = fopen($file . '.lock', 'c+'); if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Повторите действие.');
                try {
                    $record = leads_read($file);
                    if ($action === 'status') {
                        $status = (string)($_POST['status'] ?? '');
                        if (!isset($statuses[$status])) throw new RuntimeException('Неверный статус.');
                        $record['status'] = $status;
                    } elseif (empty($record['telegram_sent'])) {
                        leads_notify($record, leads_settings()); $record['telegram_sent'] = true;
                    }
                    leads_write($file, $record); $message = 'Заявка обновлена.';
                } finally { flock($lock, LOCK_UN); fclose($lock); }
                break;
            }
        }
    }
    $authorized = !empty($_SESSION['catalog_admin']);
    $settings = $authorized ? leads_settings() : [];
    $records = [];
    if ($authorized) foreach (glob(leads_dir() . '/lead-*.json') ?: [] as $file) $records[] = leads_read($file);
    usort($records, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $chats = $_SESSION['lead_chats'] ?? [];
} catch (Throwable $e) { $error = $e->getMessage(); }
$authorized = !empty($_SESSION['catalog_admin']);
$csrf = '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf_leads']) . '">';
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Заявки · 8848 ВЕРШИНА</title>
<link rel="stylesheet" href="/assets/fonts/fonts.css?v=7406384041"><style>
*{box-sizing:border-box}body{margin:0;background:#111;color:#eee;font:15px Manrope,sans-serif}main{max-width:1100px;margin:auto;padding:32px 20px}h1,h2{font-family:Unbounded,sans-serif}h1{font-size:26px}h2{font-size:18px}a{color:#e8c96b}section,article{background:#1a1a1a;border:1px solid #34302a;border-radius:18px;padding:24px;margin:20px 0}label{display:block;margin:12px 0}input,select,button{font:inherit;padding:12px;border-radius:9px;border:1px solid #555;background:#222;color:#fff}input{width:100%;max-width:600px}button{cursor:pointer;background:#c9a84c;color:#111;font-weight:700}p{line-height:1.65}small,.muted{color:#aaa}.notice{padding:14px;border:1px solid #c9a84c;border-radius:9px}.error{color:#ffa8a8}header{display:flex;justify-content:space-between;gap:20px;align-items:center;flex-wrap:wrap}.details{display:grid;grid-template-columns:1fr 1fr;gap:10px}.details p{margin:4px 0;overflow-wrap:anywhere}.inline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.badge{color:#e8c96b} @media(max-width:600px){.details{grid-template-columns:1fr}section,article{padding:16px}}
</style></head><body><main><header><h1>Заявки с сайта</h1><a href="/catalog/admin.php">Каталог</a></header>
<?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif ?>
<?php if ($message): ?><p class="notice" role="status"><?= h($message) ?></p><?php endif ?>
<?php if (!$authorized): ?>
<section><h2>Вход</h2><p>Используйте пароль админки каталога.</p><form method="post"><?= $csrf ?><input type="hidden" name="action" value="login"><label>Пароль <input type="password" name="password" autocomplete="current-password" required></label><button>Войти</button></form></section>
<?php else: ?>
<section><h2>Уведомления в Telegram</h2>
<?php if (leads_ready($settings ?? [])): ?><p class="badge">Подключено: <?= h($settings['chat_name'] ?? $settings['chat_id']) ?></p><?php endif ?>
<p>Создайте отдельного бота в <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> командой /newbot. Токен вводится только здесь и не отображается после сохранения.</p>
<form method="post"><?= $csrf ?><input type="hidden" name="action" value="token"><label>Токен бота <input type="password" name="token" autocomplete="new-password" required></label><button><?= !empty($settings['username']) ? 'Заменить бота' : 'Сохранить бота' ?></button></form>
<?php if (!empty($settings['username'])): ?>
<p>Откройте <a href="https://t.me/<?= h($settings['username']) ?>" target="_blank" rel="noopener">@<?= h($settings['username']) ?></a> и нажмите «Старт».</p>
<form method="post"><?= $csrf ?><button name="action" value="chats">Найти чаты</button></form>
<?php foreach ($chats as $id => $name): ?><form method="post" style="margin-top:12px"><?= $csrf ?><input type="hidden" name="chat_id" value="<?= h($id) ?>"><button name="action" value="connect">Подключить: <?= h($name ?: $id) ?></button></form><?php endforeach ?>
<?php endif ?></section>
<h2>Обращения · <?= count($records ?? []) ?></h2>
<?php if (empty($records)): ?><p class="muted">Заявок пока нет. После подключения Telegram отправьте тестовую заявку через сайт.</p><?php endif ?>
<?php foreach ($records ?? [] as $record): ?><article>
<header><strong>№ <?= h($record['id']) ?> · <?= h($record['name']) ?></strong><small><?= h((new DateTimeImmutable($record['created_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i')) ?> МСК</small></header>
<div class="details"><p>Телефон: <a href="tel:<?= h(preg_replace('/[^+0-9]/', '', $record['phone'])) ?>"><?= h($record['phone']) ?></a></p><p>Услуга: <?= h($record['service']) ?></p><p>Кондиционер: <?= h($record['product'] ?: 'Не выбран') ?></p><p>Адрес: <?= h($record['address'] ?: 'Не указан') ?></p><p>Площадь: <?= h($record['area'] ?: '—') ?> м²</p></div>
<form method="post" class="inline"><?= $csrf ?><input type="hidden" name="id" value="<?= h($record['id']) ?>"><select name="status" aria-label="Статус заявки"><?php foreach ($statuses as $key => $label): ?><option value="<?= h($key) ?>" <?= $record['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach ?></select><button name="action" value="status">Сохранить статус</button>
<?php if (empty($record['telegram_sent'])): ?><button name="action" value="retry">Повторить уведомление</button><small>Сохранена на сайте, уведомление пока не доставлено.</small><?php else: ?><small>Уведомление отправлено</small><?php endif ?></form></article><?php endforeach ?>
<form method="post"><?= $csrf ?><button name="action" value="logout">Выйти</button></form>
<?php endif ?></main></body></html>
