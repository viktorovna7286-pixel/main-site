<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$reviewsDir = __DIR__ . '/data/reviews';
$reviewsFile = $reviewsDir . '/reviews.json';
$uploadsDir = __DIR__ . '/uploads/reviews';

function reviews_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function reviews_ensure_dirs(string $reviewsDir, string $uploadsDir): bool
{
    if (!is_dir($reviewsDir) && !@mkdir($reviewsDir, 0755, true)) {
        return false;
    }
    if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true)) {
        return false;
    }
    return true;
}

function reviews_read(string $reviewsFile): array
{
    if (!is_file($reviewsFile)) {
        return [];
    }
    $raw = @file_get_contents($reviewsFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function reviews_write(string $reviewsFile, array $reviews): bool
{
    $json = json_encode($reviews, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($reviewsFile, $json . "\n", LOCK_EX) !== false;
}

function reviews_save_photo(array $file, string $uploadsDir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 7 * 1024 * 1024) {
        return null;
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        return null;
    }
    $imageType = (int)($info[2] ?? 0);
    $ext = match ($imageType) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
        default => '',
    };
    if ($ext === '') {
        return null;
    }
    $name = 'review-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($tmp, $target)) {
        return null;
    }
    return 'uploads/reviews/' . $name;
}

if (!reviews_ensure_dirs($reviewsDir, $uploadsDir)) {
    reviews_json_response(['ok' => false, 'error' => 'Ошибка доступа к папкам отзывов'], 500);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $reviews = reviews_read($reviewsFile);
    $published = array_values(array_filter($reviews, static function ($item) {
        return is_array($item) && !empty($item['approved']);
    }));
    usort($published, static function ($a, $b) {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });
    reviews_json_response(['ok' => true, 'reviews' => array_slice($published, 0, 30)]);
}

if ($method !== 'POST') {
    reviews_json_response(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
}

$name = trim((string)($_POST['name'] ?? ''));
$comment = trim((string)($_POST['comment'] ?? ''));
$rating = (int)($_POST['rating'] ?? 0);

if ($name === '' || mb_strlen($name) < 2) {
    reviews_json_response(['ok' => false, 'error' => 'Укажите имя (минимум 2 символа)'], 400);
}
if ($rating < 1 || $rating > 5) {
    reviews_json_response(['ok' => false, 'error' => 'Поставьте оценку от 1 до 5'], 400);
}
if (mb_strlen($comment) > 2500) {
    reviews_json_response(['ok' => false, 'error' => 'Комментарий слишком длинный'], 400);
}

$photos = [];
if (isset($_FILES['photos']) && is_array($_FILES['photos'])) {
    $names = $_FILES['photos']['name'] ?? [];
    $tmpNames = $_FILES['photos']['tmp_name'] ?? [];
    $errors = $_FILES['photos']['error'] ?? [];
    $sizes = $_FILES['photos']['size'] ?? [];
    $types = $_FILES['photos']['type'] ?? [];
    $count = min(count($names), 5);
    for ($i = 0; $i < $count; $i++) {
        $file = [
            'name' => $names[$i] ?? '',
            'tmp_name' => $tmpNames[$i] ?? '',
            'error' => $errors[$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $sizes[$i] ?? 0,
            'type' => $types[$i] ?? '',
        ];
        $saved = reviews_save_photo($file, $uploadsDir);
        if ($saved !== null) {
            $photos[] = $saved;
        }
    }
}

$review = [
    'id' => 'r_' . bin2hex(random_bytes(6)),
    'name' => $name,
    'rating' => $rating,
    'comment' => $comment,
    'photos' => $photos,
    'createdAt' => gmdate('c'),
    'approved' => true,
];

$reviews = reviews_read($reviewsFile);
$reviews[] = $review;
if (!reviews_write($reviewsFile, $reviews)) {
    reviews_json_response(['ok' => false, 'error' => 'Не удалось сохранить отзыв'], 500);
}

reviews_json_response(['ok' => true, 'review' => $review], 201);
