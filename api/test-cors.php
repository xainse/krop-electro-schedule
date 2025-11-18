<?php
// Тестовий файл для перевірки CORS заголовків
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'CORS headers are working!',
    'timestamp' => time()
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>

