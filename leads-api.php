<?php
declare(strict_types=1);
require __DIR__ . '/leads-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
function lead_reply(array $payload, int $status = 200): never {
    http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit;
}
try {
    $settings = leads_settings();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') lead_reply(['ready' => leads_ready($settings)]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') lead_reply(['error' => 'Method not allowed'], 405);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) lead_reply(['error' => 'Origin not allowed'], 403);
    if (!leads_ready($settings)) lead_reply(['error' => 'Приём заявок ещё не настроен. Позвоните нам: +7 999 636-86-98.'], 503);
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($raw) > 8192) lead_reply(['error' => 'Слишком много данных.'], 413);
    $input = json_decode($raw, true);
    if (!is_array($input)) lead_reply(['error' => 'Некорректная форма.'], 400);
    if (!empty($input['company'])) lead_reply(['error' => 'Не удалось отправить форму.'], 400);
    $requestId = $input['request_id'] ?? '';
    if (!is_string($requestId) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $requestId)) lead_reply(['error' => 'Обновите страницу и повторите отправку.'], 400);
    $lead = leads_validate($input);
    // One lock covers duplicate requests and Telegram delivery for this record.
    $path = leads_dir() . '/lead-' . hash('sha256', $requestId) . '.json';
    $fp = fopen($path . '.lock', 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Lock unavailable');
    try {
        if (is_file($path)) {
            $existing = leads_read($path);
            lead_reply(['ok' => true, 'id' => $existing['id']]);
        }
        if (!leads_limit('submit', 6, 3600)) lead_reply(['error' => 'Слишком много заявок. Позвоните нам: +7 999 636-86-98.'], 429);
        $lead += ['id' => strtoupper(bin2hex(random_bytes(5))), 'created_at' => gmdate('c'), 'status' => 'new', 'telegram_sent' => false];
        leads_write($path, $lead);
        try {
            leads_notify($lead, $settings);
            $lead['telegram_sent'] = true;
            leads_write($path, $lead);
        } catch (Throwable $e) { error_log('Lead saved; Telegram notification pending: ' . $lead['id']); }
    } finally { flock($fp, LOCK_UN); fclose($fp); }
    lead_reply(['ok' => true, 'id' => $lead['id']]);
} catch (InvalidArgumentException $e) {
    lead_reply(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Lead request failed');
    lead_reply(['error' => 'Не удалось сохранить заявку. Попробуйте ещё раз или позвоните: +7 999 636-86-98.'], 500);
}
