<?php
//Config
define('BOT_TOKEN', '[bot-token]');
define('ADMIN_ID', 8690800964);

define('BANK_ID', 'mbbank-6099882404');
define('BANK_NAME', 'Vu Hai Lam');
define('BANK_ACCOUNT', '6099882404');

define('DEBUG_LOG', true);
define('LOG_FILE', __DIR__ . '/data/debug.log');

define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('PENDING_FILE', DATA_DIR . '/pending.json');
define('ORDERS_FILE', DATA_DIR . '/orders.json');
define('DEPOSITS_FILE', DATA_DIR . '/deposits.json');

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}

function log_debug($msg) {
    if (!DEBUG_LOG) return;
    @file_put_contents(LOG_FILE, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND | LOCK_EX);
}

function json_read($file) {
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if (!$content) return [];
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function json_write($file, $data) {
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}

function tg_api($method, $data = []) {
    if (!BOT_TOKEN || BOT_TOKEN === 'ĐIỀN_BOT_TOKEN_VÀO_ĐÂY') {
        log_debug("tg_api($method): BOT_TOKEN chưa cấu hình");
        return ['ok' => false, 'description' => 'Bot token chưa cấu hình'];
    }

    $cleanData = [];
    foreach ($data as $key => $value) {
        if ($value === null) continue;
        if ($key === 'reply_markup') {
            if (!is_array($value) || empty($value)) continue;
            if (isset($value['inline_keyboard']) && empty($value['inline_keyboard'])) continue;
        }
        if ($key === 'text' && trim((string)$value) === '') continue;
        $cleanData[$key] = $value;
    }

    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    $payload = json_encode($cleanData, JSON_UNESCAPED_UNICODE);
    log_debug("tg_api($method) REQ: " . substr($payload, 0, 500));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        log_debug("tg_api($method) CURL: $err");
        return ['ok' => false, 'description' => "CURL: $err"];
    }

    log_debug("tg_api($method) RES: " . substr($res, 0, 500));

    $decoded = json_decode($res, true);
    if (!$decoded) {
        return ['ok' => false, 'description' => 'Invalid JSON response'];
    }
    return $decoded;
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}