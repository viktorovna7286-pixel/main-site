<?php
declare(strict_types=1);

// All customer data and Telegram credentials live outside the document root.
function leads_dir(): string {
    $dir = getenv('LEADS_PRIVATE_DIR') ?: dirname(__DIR__) . '/.vershina8848-leads';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Storage unavailable');
    $real = realpath($dir);
    $public = str_replace('\\', '/', (string)realpath(__DIR__));
    $normal = str_replace('\\', '/', (string)$real);
    if (!$real || $normal === $public || str_starts_with($normal, $public . '/')) throw new RuntimeException('Storage must be private');
    return $real;
}
function leads_read(string $path): array {
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Invalid stored data');
    return $data;
}
function leads_write(string $path, array $data): void {
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($temp, $json, LOCK_EX) === false) throw new RuntimeException('Write failed');
    chmod($temp, 0600);
    if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Write failed'); }
}
function leads_settings(): array { return leads_read(leads_dir() . '/telegram.json'); }
function leads_ready(array $settings): bool {
    return !empty($settings['enabled']) && !empty($settings['token']) && !empty($settings['chat_id']);
}
function leads_telegram(string $token, string $method, array $payload = []): array {
    if (!preg_match('/^\d{5,16}:[A-Za-z0-9_-]{20,100}$/D', $token)) throw new RuntimeException('Некорректный токен бота');
    if (!in_array($method, ['getMe', 'getUpdates', 'sendMessage'], true)) throw new RuntimeException('Invalid method');
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch); curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($payload), 'timeout' => 10, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $context);
    }
    $result = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($result) || empty($result['ok'])) throw new RuntimeException('Telegram не ответил или отклонил запрос. Проверьте токен и нажмите «Старт» у бота.');
    return $result;
}
function leads_message(array $lead): string {
    $lines = ['Новая заявка · 8848 ВЕРШИНА', '№ ' . $lead['id'], 'Имя: ' . $lead['name'], 'Телефон: ' . $lead['phone']];
    foreach (['service' => 'Услуга', 'product' => 'Кондиционер', 'address' => 'Адрес', 'area' => 'Площадь, м²'] as $key => $label) {
        if (!empty($lead[$key])) $lines[] = $label . ': ' . $lead[$key];
    }
    $lines[] = 'Заявки: https://vershina8848.ru/leads-admin.php';
    return implode("\n", $lines);
}
function leads_notify(array $lead, array $settings, ?callable $transport = null): void {
    $send = $transport ?? 'leads_telegram';
    $send($settings['token'], 'sendMessage', ['chat_id' => $settings['chat_id'], 'text' => leads_message($lead)]);
}
function leads_limit(string $scope, int $max, int $seconds): bool {
    $key = hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $fp = fopen(leads_dir() . '/rate-' . $key . '.json', 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Rate limiter unavailable');
    try {
        $old = json_decode(stream_get_contents($fp), true);
        $times = array_values(array_filter(is_array($old) ? $old : [], static fn($t) => is_int($t) && $t > time() - $seconds));
        if (count($times) >= $max) return false;
        $times[] = time(); rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($times));
        return true;
    } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function leads_validate(array $input): array {
    $out = [];
    foreach (['name' => 100, 'phone' => 40, 'address' => 300, 'service' => 120, 'area' => 12] as $key => $limit) {
        if (isset($input[$key]) && !is_scalar($input[$key])) throw new InvalidArgumentException('Проверьте поля формы.');
        $value = trim((string)($input[$key] ?? ''));
        if (strlen($value) > $limit * 4 || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) throw new InvalidArgumentException('Проверьте поля формы.');
        $out[$key] = $value;
    }
    $digits = preg_replace('/\D/', '', $out['phone']);
    if ($out['name'] === '' || !preg_match('/^\+?[0-9 () .-]+$/D', $out['phone']) || strlen($digits) < 10 || strlen($digits) > 15) throw new InvalidArgumentException('Укажите имя и корректный телефон.');
    if ($out['area'] !== '' && (!is_numeric($out['area']) || (float)$out['area'] <= 0 || (float)$out['area'] > 100000)) throw new InvalidArgumentException('Проверьте площадь.');
    $out['product'] = '';
    foreach (['product_id', 'btu'] as $field) if (isset($input[$field]) && !is_scalar($input[$field])) throw new InvalidArgumentException('Проверьте выбранную модель.');
    if (!empty($input['product_id'])) {
        $products = leads_read(__DIR__ . '/catalog/catalog-data.json');
        foreach ($products as $product) {
            if ((string)$product['id'] !== (string)$input['product_id']) continue;
            $out['product'] = $product['brand'] . ' ' . $product['series'];
            foreach ($product['btuData'] ?? [] as $row) {
                if ((string)$row['btu'] === (string)($input['btu'] ?? '')) $out['product'] .= ' · ' . $row['btu'] . ' BTU · ' . number_format((float)$row['price'], 0, '.', ' ') . ' ₽';
            }
        }
    }
    return $out;
}
