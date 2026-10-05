<?php
/**
 * Прокси для lead-injector tracker
 * Нужен чтобы отправить реальный IP клиента (а не сервера)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Получаем IP клиента (не сервера!)
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$ip = trim(explode(',', $ip)[0]);

// Читаем данные с клиента
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$cookies    = $data['cookies']    ?? [];
$clientId   = $data['client_id']  ?? null;
$referer    = $data['referer']    ?? ($_SERVER['HTTP_REFERER'] ?? '');
$needPhone  = $data['need_phone'] ?? 1;

if (!is_array($cookies)) $cookies = [];

// Формируем payload для tracker
$payload = [
    'ip'         => $ip,
    'cookie'     => $cookies,
    'referer'    => $referer,
    'client_id'  => $clientId,
    'need_phone' => (int)$needPhone,
];

// Отправляем
$ch = curl_init('https://lead-injector.sms19.ru/api/tracker');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Логируем
$LOG_FILE = __DIR__ . '/tracker.log';
$logLine = "[" . date('Y-m-d H:i:s') . "] "
    . "IP={$ip} | "
    . "REQUEST: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . " | "
    . "HTTP {$httpCode} | "
    . "RESPONSE: " . ($response ?: '(empty)')
    . ($curlError ? " | CURL_ERROR: {$curlError}" : "")
    . PHP_EOL;
@file_put_contents($LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

// Проксируем ответ клиенту
if ($httpCode >= 200 && $httpCode < 300 && $response) {
    // Пытаемся распарсить и вернуть JSON как есть
    $decoded = json_decode($response, true);
    if ($decoded !== null) {
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
    } else {
        echo $response;
    }
} else {
    // При ошибке — возвращаем пустой объект, чтобы не блокировать процесс
    http_response_code(200);
    echo json_encode(['error' => true, 'http_code' => $httpCode]);
}
