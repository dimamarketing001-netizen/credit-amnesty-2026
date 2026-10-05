<?php
/**
 * Кредитная Амнистия — обработчик заявок
 * ООО «Мой юрист» | Екатеринбург
 * Интеграция: lead-injector.sms19.ru
 */

// ============ НАСТРОЙКИ ============

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Server configuration missing']);
    exit;
}
$config = require $configFile;

// LEAD-INJECTOR (основной приёмник)
$LEAD_INJECTOR_URL   = 'https://lead-injector.sms19.ru/api/lead';
$LEAD_INJECTOR_TOKEN = (string)($config['lead_injector_token'] ?? '');

// TELEGRAM (опционально — дублирование)
$TELEGRAM_ENABLED = (bool)($config['telegram_enabled'] ?? false);
$TELEGRAM_TOKEN   = (string)($config['telegram_token'] ?? '');
$TELEGRAM_CHAT_ID = (string)($config['telegram_chat_id'] ?? '');

// EMAIL (опционально — дублирование)
$EMAIL_ENABLED = (bool)($config['email_enabled'] ?? false);
$EMAIL_TO      = (string)($config['email_to'] ?? 'info@moy-yurist.ru');
$EMAIL_FROM    = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Логи
$LOG_ENABLED = true;
$LOG_FILE       = __DIR__ . '/leads.log';         // все заявки
$LOG_INJECTOR   = __DIR__ . '/injector.log';      // ответы lead-injector
$LOG_ERROR      = __DIR__ . '/errors.log';        // ошибки

// ============ КОД ============

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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Валидация
$name  = trim($data['name']  ?? '');
$phone = trim($data['phone'] ?? '');

if (mb_strlen($name) < 2 || mb_strlen($phone) < 10) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Validation failed']);
    exit;
}

// Rate limit по IP (1 заявка в 20 сек)
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = explode(',', $ip)[0];
$ip = trim($ip);
$rateFile = sys_get_temp_dir() . '/ca_rate_' . md5($ip);
if (file_exists($rateFile) && (time() - filemtime($rateFile)) < 20) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests']);
    exit;
}
@touch($rateFile);

// Защита от спама
if (preg_match('/(https?:\/\/|<script|javascript:)/i', $name . $phone)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Spam detected']);
    exit;
}

// Собираем данные
$answers    = $data['answers'] ?? [];
$score      = (int)($data['score'] ?? 0);
$source     = $data['source'] ?? '';
$referrer   = $data['referrer'] ?? '';
$utm        = $data['utm'] ?? [];
$region     = trim($answers['regionName'] ?? '');
$regionKey  = trim($answers['region'] ?? '');
$city       = trim($answers['city'] ?? '');

// Аналитика клиента
$yandexClientId = trim($data['yandex_client_id'] ?? '');
$allCookies     = $data['cookies'] ?? [];
$userAgent      = trim($data['user_agent'] ?? '');
$screenSize     = trim($data['screen'] ?? '');
$userLang       = trim($data['language'] ?? '');
$phoneId        = trim($data['PhoneId'] ?? '');
$phoneCall      = trim($data['phoneCall'] ?? '');

if (!is_array($allCookies)) $allCookies = [];

// Нормализуем телефон (только цифры)
$phoneClean = preg_replace('/\D/', '', $phone);
if (strlen($phoneClean) === 11 && $phoneClean[0] === '8') {
    $phoneClean = '7' . substr($phoneClean, 1);
}
$phoneFormatted = '+' . $phoneClean;

// Оценка "качества" лида
$leadQuality = 'good';
if ($score < 80) $leadQuality = 'medium';
if ($score < 60 || ($answers['debt'] ?? '') === 'less-300') $leadQuality = 'weak';

// Расшифровка ответов
$ANSWERS_MAP = [
    'debt'      => ['title' => 'Сумма долга', 'values' => [
        'less-300'  => 'До 300 000₽',
        '300-500'   => '300 000 – 500 000₽',
        '500-1500'  => '500 000 – 1 500 000₽',
        '1500-plus' => 'Более 1 500 000₽'
    ]],
    'court'     => ['title' => 'Кредиторы подавали в суд', 'values' => [
        'yes' => 'Да', 'no' => 'Нет', 'unknown' => 'Не знает'
    ]],
    'execution' => ['title' => 'Исполнительное производство', 'values' => [
        'no' => 'Не было', 'active' => 'Идёт взыскание', 'past' => 'Прекратилось', 'unknown' => 'Не знает'
    ]],
    'income'    => ['title' => 'Официальный доход', 'values' => [
        'employed' => 'Официальная работа', 'informal' => 'Неофициально', 'pension' => 'Пенсия/пособие', 'no' => 'Нет'
    ]],
    'mortgage'  => ['title' => 'Ипотека', 'values' => [
        'yes' => 'Есть', 'no' => 'Нет'
    ]],
    'property'  => ['title' => 'Имущество кроме единственного жилья', 'values' => [
        'no' => 'Нет', 'car' => 'Автомобиль', 'estate' => 'Недвижимость', 'unknown' => 'Не знает'
    ]],
    'alimony'   => ['title' => 'Алименты/штрафы', 'values' => [
        'no' => 'Нет', 'alimony' => 'Алименты', 'fines' => 'Штрафы/налоги', 'mixed' => 'Всего понемногу'
    ]],
];

// Комментарий для lead-injector — сжатая информация
$commentParts = [];
$commentParts[] = "Заявка с квиза «Кредитная Амнистия»";
$commentParts[] = "Оценка лида: {$leadQuality} ({$score} баллов)";
if ($region || $city) {
    $loc = trim(implode(', ', array_filter([$city, $region])), ', ');
    $commentParts[] = "Регион: {$loc}";
}

$commentParts[] = "";
$commentParts[] = "═══ ОТВЕТЫ ═══";
foreach ($ANSWERS_MAP as $key => $meta) {
    if (!isset($answers[$key])) continue;
    $val = $answers[$key];
    $label = $meta['values'][$val] ?? $val;
    $commentParts[] = "• {$meta['title']}: {$label}";
}

// UTM метки
if (!empty($utm)) {
    $commentParts[] = "";
    $commentParts[] = "═══ UTM ═══";
    foreach ($utm as $k => $v) {
        $commentParts[] = "{$k}: {$v}";
    }
}

// Яндекс.Метрика
$commentParts[] = "";
$commentParts[] = "═══ АНАЛИТИКА ═══";
if ($yandexClientId) {
    $commentParts[] = "Yandex ClientID: {$yandexClientId}";
}

// _ym_uid (уникальный идентификатор Метрики)
if (!empty($allCookies['_ym_uid'])) {
    $commentParts[] = "_ym_uid: " . $allCookies['_ym_uid'];
}

// Источник и рефер
$commentParts[] = "Источник: " . ($source ?: 'прямой');
if ($referrer) {
    $commentParts[] = "Referrer: " . $referrer;
}

// User info
if ($userAgent) {
    $commentParts[] = "User-Agent: " . mb_substr($userAgent, 0, 200);
}
if ($screenSize) {
    $commentParts[] = "Экран: {$screenSize}";
}
if ($userLang) {
    $commentParts[] = "Язык: {$userLang}";
}
$commentParts[] = "IP: {$ip}";

// Все куки (в конце — они длинные)
if (!empty($allCookies)) {
    $commentParts[] = "";
    $commentParts[] = "═══ COOKIES ═══";
    foreach ($allCookies as $ckKey => $ckVal) {
        $commentParts[] = "{$ckKey}={$ckVal}";
    }
}

$comment = implode("\n", $commentParts);

// ============ ОТПРАВКА В LEAD-INJECTOR ============
$injectorPayload = [
    'name'             => $name,
    'phone'            => $phoneFormatted,
    'city'             => $city,
    'region'           => $region,
    '0__'          => $comment,
    'source'           => $_SERVER['HTTP_HOST'] ?? 'кредитная-амнистия-2026.рф',
    'quality'          => $leadQuality,
    'score'            => $score,
    // Аналитика
    'yandex_client_id' => $yandexClientId,
    'ym_uid'           => $allCookies['_ym_uid'] ?? '',
    'referrer'         => $source,
    'landing'          => $source,
    'ip'               => $ip,
    // Битрикс24 CRM
    'UF_CRM_1662639727' => '10422',
    'PhoneId' => $phoneId,
    'PhoneCall' => $phoneCall,
];

// UTM параметры
if (!empty($utm)) {
    foreach ($utm as $k => $v) {
        $injectorPayload[$k] = $v;
    }
}

$injectorResult = sendToLeadInjector($LEAD_INJECTOR_URL, $LEAD_INJECTOR_TOKEN, $injectorPayload);

// ============ ЛОГИРОВАНИЕ ============
if ($LOG_ENABLED) {
    $timestamp = date('Y-m-d H:i:s');
    // Общий лог всех заявок
    $logLine = "[{$timestamp}] " . json_encode([
        'name' => $name,
        'phone' => $phoneFormatted,
        'city' => $city,
        'region' => $region,
        'score' => $score,
        'quality' => $leadQuality,
        'answers' => $answers,
        'ip' => $ip,
        'utm' => $utm,
        'yandex_client_id' => $yandexClientId,
        'cookies' => $allCookies,
        'user_agent' => $userAgent,
        'injector_ok' => $injectorResult['ok'],
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

    // Лог отправки в injector (payload + response)
    $injectorLog = "[{$timestamp}] "
        . "REQUEST: " . json_encode($injectorPayload, JSON_UNESCAPED_UNICODE) . " | "
        . "RESPONSE (HTTP {$injectorResult['http_code']}): " . $injectorResult['response']
        . ($injectorResult['error'] ? " | ERROR: " . $injectorResult['error'] : "")
        . PHP_EOL;
    @file_put_contents($LOG_INJECTOR, $injectorLog, FILE_APPEND | LOCK_EX);

    // Отдельный лог ошибок
    if (!$injectorResult['ok']) {
        $errLine = "[{$timestamp}] Injector FAIL for {$phoneFormatted}: "
            . "HTTP {$injectorResult['http_code']} | "
            . $injectorResult['response']
            . ($injectorResult['error'] ? " | " . $injectorResult['error'] : "")
            . PHP_EOL;
        @file_put_contents($LOG_ERROR, $errLine, FILE_APPEND | LOCK_EX);
    }
}

// ============ ДОП. ДУБЛИРОВАНИЕ (Telegram/Email) ============
$results = ['injector' => $injectorResult['ok']];

if ($TELEGRAM_ENABLED && $TELEGRAM_TOKEN !== 'ВСТАВЬ_ТОКЕН_БОТА') {
    $tgMsg = "🔔 <b>НОВАЯ ЗАЯВКА</b>\n\n";
    $tgMsg .= "👤 <b>Имя:</b> " . htmlspecialchars($name) . "\n";
    $tgMsg .= "📱 <b>Телефон:</b> " . htmlspecialchars($phoneFormatted) . "\n";
    if ($city || $region) {
        $tgMsg .= "📍 <b>Регион:</b> " . htmlspecialchars(trim(implode(', ', array_filter([$city, $region])), ', ')) . "\n";
    }
    $tgMsg .= "\n<pre>" . htmlspecialchars($comment) . "</pre>";
    $results['telegram'] = sendTelegram($TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID, $tgMsg);
}

if ($EMAIL_ENABLED && !empty($EMAIL_TO)) {
    $emailBody = "Новая заявка с сайта\n\n"
        . "Имя: {$name}\n"
        . "Телефон: {$phoneFormatted}\n"
        . "Город: {$city}\n"
        . "Регион: {$region}\n\n"
        . $comment;
    $results['email'] = sendEmail($EMAIL_TO, $EMAIL_FROM, 'Новая заявка на списание долгов', $emailBody);
}

// ============ ОТВЕТ КЛИЕНТУ ============
echo json_encode([
    'ok' => true,
    'message' => 'Заявка принята',
    'results' => $results
], JSON_UNESCAPED_UNICODE);


// ============ ФУНКЦИИ ============

function sendToLeadInjector($url, $token, $payload) {
    $fullUrl = $url . '?token=' . urlencode($token);

    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $ok = ($httpCode >= 200 && $httpCode < 300);

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'response' => $response ?: '',
        'error' => $error ?: '',
    ];
}

function sendTelegram($token, $chatId, $text) {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

function sendEmail($to, $from, $subject, $body) {
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}
