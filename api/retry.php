<?php
/**
 * Скрипт автоматического перезапуска неудавшихся заявок в Lead-Injector
 * Запускается по Cron каждые 10 минут.
 */

// Защита от запуска через браузер (разрешено только из консоли/cron)
if (php_sapi_name() !== 'cli' && !isset($_GET['force'])) {
    http_response_code(403);
    die('Only CLI access allowed');
}

// Настройки путей
$BASE_DIR   = __DIR__;
$LOG_FILE   = $BASE_DIR . '/leads.log';
$DB_FILE    = $BASE_DIR . '/retried_success.json'; // здесь храним ID успешно отправленных повторно
$RETRY_LOG  = $BASE_DIR . '/retry.log';            // лог работы этого скрипта

// Настройки интеграции
$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "config.local.php not found\n");
    exit(1);
}
$config = require $configFile;
$LEAD_INJECTOR_URL   = 'https://lead-injector.sms19.ru/api/lead';
$LEAD_INJECTOR_TOKEN = (string)($config['lead_injector_token'] ?? '');
$DOMAIN_FALLBACK     = 'xn----2026-2nfb7aj8adau0bme9b3ami3zia.xn--p1ai';

if (!file_exists($LOG_FILE)) {
    logMessage("Файл логов leads.log не найден по пути: {$LOG_FILE}");
    exit;
}

// Загружаем базу уже отправленных через retry заявок
$retriedDatabase = [];
if (file_exists($DB_FILE)) {
    $retriedDatabase = json_decode(file_get_contents($DB_FILE), true) ?: [];
}

// Читаем leads.log построчно (эффективно для больших файлов)
$handle = fopen($LOG_FILE, 'r');
if (!$handle) {
    logMessage("Не удалось открыть файл leads.log");
    exit;
}

logMessage("=== Старт проверки зависших лидов ===");

$processedCount = 0;
$successCount = 0;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;

    // Парсим строку лога: [YYYY-MM-DD HH:MM:SS] {JSON}
    if (!preg_match('/^\[(.*?)\]\s+(.*)$/', $line, $matches)) {
        continue;
    }

    $timestamp = $matches[1];
    $jsonData  = json_decode($matches[2], true);

    if (!$jsonData || !is_array($jsonData)) {
        continue;
    }

    // Если интеграция прошла успешно изначально, пропускаем
    if (isset($jsonData['injector_ok']) && $jsonData['injector_ok'] === true) {
        continue;
    }

    // Создаем уникальный ID заявки на основе времени и телефона
    $phone = $jsonData['phone'] ?? '';
    $leadId = md5($timestamp . $phone);

    // Если мы эту заявку уже успешно доотправили ранее — пропускаем
    if (isset($retriedDatabase[$leadId])) {
        continue;
    }

    // Нашли упавшую заявку! Пытаемся отправить заново
    $processedCount++;
    logMessage("Найдена упавшая заявка: {$phone} от {$timestamp}. Отправляем...");

    $payload = rebuildPayload($jsonData, $LEAD_INJECTOR_TOKEN, $DOMAIN_FALLBACK);
    $result = sendToLeadInjector($LEAD_INJECTOR_URL, $LEAD_INJECTOR_TOKEN, $payload);

    if ($result['ok']) {
        $successCount++;
        $retriedDatabase[$leadId] = [
            'phone' => $phone,
            'time_logged' => $timestamp,
            'time_sent' => date('Y-m-d H:i:s')
        ];
        logMessage("Успешно отправлено! Ответ API: " . trim($result['response']));
    } else {
        logMessage("Ошибка отправки! Код: {$result['http_code']}, Ошибка: {$result['error']}, Ответ: " . trim($result['response']));
    }
}

fclose($handle);

// Сохраняем обновленную базу успешно доотправленных
file_put_contents($DB_FILE, json_encode($retriedDatabase, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

logMessage("=== Работа завершена. Найдено ошибок: {$processedCount}. Успешно доотправлено: {$successCount} ===");


// ================== ФУНКЦИИ ==================

function logMessage($message) {
    global $RETRY_LOG;
    $logLine = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    echo $logLine; // Вывод в консоль
    @file_put_contents($RETRY_LOG, $logLine, FILE_APPEND | LOCK_EX);
}

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

    return [
        'ok' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'response' => $response ?: '',
        'error' => $error ?: '',
    ];
}

// Восстановление Payload из данных лога (копия логики submit.php)
function rebuildPayload($data, $token, $domain) {
    $name  = $data['name'] ?? '';
    $phone = $data['phone'] ?? '';
    $city  = $data['city'] ?? '';
    $region = $data['region'] ?? '';
    $score  = (int)($data['score'] ?? 0);
    $answers = $data['answers'] ?? [];
    $utm = $data['utm'] ?? [];
    $yandexClientId = $data['yandex_client_id'] ?? '';
    $allCookies = $data['cookies'] ?? [];
    $userAgent = $data['user_agent'] ?? '';
    $ip = $data['ip'] ?? '127.0.0.1';

    $leadQuality = $data['quality'] ?? 'good';

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

    $commentParts = [];
    $commentParts[] = "Заявка с квиза «Кредитная Амнистия» (ПОВТОРНАЯ ОТПРАВКА)";
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

    if (!empty($utm)) {
        $commentParts[] = "";
        $commentParts[] = "═══ UTM ═══";
        foreach ($utm as $k => $v) {
            $commentParts[] = "{$k}: {$v}";
        }
    }

    $commentParts[] = "";
    $commentParts[] = "═══ АНАЛИТИКА ═══";
    if ($yandexClientId) {
        $commentParts[] = "Yandex ClientID: {$yandexClientId}";
    }
    if (!empty($allCookies['_ym_uid'])) {
        $commentParts[] = "_ym_uid: " . $allCookies['_ym_uid'];
    }
    $commentParts[] = "IP: {$ip}";
    if ($userAgent) {
        $commentParts[] = "User-Agent: " . mb_substr($userAgent, 0, 200);
    }

    $comment = implode("\n", $commentParts);

    $payload = [
        'name'             => $name,
        'phone'            => $phone,
        'city'             => $city,
        'region'           => $region,
        '0__'              => $comment,
        'source'           => $domain,
        'quality'          => $leadQuality,
        'score'            => $score,
        'yandex_client_id' => $yandexClientId,
        'ym_uid'           => $allCookies['_ym_uid'] ?? '',
        'ip'               => $ip,
        'UF_CRM_1662639727'=> '10422',
    ];

    if (!empty($utm)) {
        foreach ($utm as $k => $v) {
            $payload[$k] = $v;
        }
    }

    return $payload;
}
