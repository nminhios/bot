<?php
require_once 'config.php';

$action = $_GET['action'] ?? '';
log_debug("api.php action=$action");

// BALANCE
if ($action === 'balance') {
    $userId = (string)($_GET['user_id'] ?? '0');
    $users = json_read(USERS_FILE);
    json_out(['ok' => true, 'balance' => $users[$userId]['balance'] ?? 0]);
}

// ORDER
if ($action === 'orders') {
    $userId = (string)($_GET['user_id'] ?? '0');
    $orders = json_read(ORDERS_FILE);
    $userOrders = array_values(array_filter($orders, fn($o) => (string)$o['userId'] === $userId));
    json_out(['ok' => true, 'orders' => array_slice(array_reverse($userOrders), 0, 50)]);
}

//  DEPOSITS
if ($action === 'deposits') {
    $userId = (string)($_GET['user_id'] ?? '0');
    $deposits = json_read(DEPOSITS_FILE);
    $userDeposits = array_values(array_filter($deposits, fn($d) => (string)$d['userId'] === $userId));
    json_out(['ok' => true, 'deposits' => array_slice(array_reverse($userDeposits), 0, 50)]);
}

//  MUA 
if ($action === 'buy_balance') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_out(['ok' => false, 'error' => 'Invalid input'], 400);

    $userId = (string)($input['userId'] ?? '0');
    $price = (int)($input['price'] ?? 0);

    if ($price <= 0) json_out(['ok' => false, 'error' => 'Giá không hợp lệ'], 400);

    $users = json_read(USERS_FILE);
    if (($users[$userId]['balance'] ?? 0) < $price) {
        json_out(['ok' => false, 'error' => 'Số dư không đủ'], 400);
    }

    $users[$userId]['balance'] -= $price;
    json_write(USERS_FILE, $users);

    $key = 'K' . strtoupper(bin2hex(random_bytes(8)));
    $orders = json_read(ORDERS_FILE);
    $orders[] = [
        'orderId' => 'ORD' . time(),
        'userId' => $userId,
        'gameName' => $input['gameName'] ?? '',
        'panelName' => $input['panelName'] ?? '',
        'panelIcon' => $input['panelIcon'] ?? '📦',
        'price' => $price,
        'key' => $key,
        'method' => 'balance',
        'date' => date('c'),
        'status' => 'active'
    ];
    json_write(ORDERS_FILE, $orders);

    json_out(['ok' => true, 'key' => $key, 'newBalance' => $users[$userId]['balance']]);
}

if ($action === 'notify') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_out(['ok' => false, 'error' => 'Invalid JSON'], 400);

    $userId = (string)($input['userId'] ?? '0');
    $message = $input['message'] ?? '';

    if (!$userId || !$message) json_out(['ok' => false, 'error' => 'Missing data'], 400);

    $fullMsg = $message . "\n\n━━━━━━━━━━━━━━━━━━\n" .
               "💡 <b>Lệnh duyệt:</b>\n" .
               "<code>/add {$userId} " . ($input['amount'] ?? 0) . "</code>";

    $result = tg_api('sendMessage', [
        'chat_id' => ADMIN_ID,
        'text' => $fullMsg,
        'parse_mode' => 'HTML'
    ]);

    if (!$result || empty($result['ok'])) {
        json_out(['ok' => false, 'error' => $result['description'] ?? 'Send failed'], 500);
    }

    json_out(['ok' => true]);
}

if ($action === 'setup_webhook') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $dir = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
    $webhookUrl = "$scheme://$host$dir/webhook.php";

    tg_api('deleteWebhook', ['drop_pending_updates' => true]);
    $result = tg_api('setWebhook', [
        'url' => $webhookUrl,
        'allowed_updates' => ['message', 'callback_query']
    ]);

    json_out(['ok' => $result['ok'] ?? false, 'webhook_url' => $webhookUrl, 'result' => $result]);
}

if ($action === 'webhook_info') {
    json_out(tg_api('getWebhookInfo'));
}

if ($action === 'test_admin') {
    json_out(tg_api('sendMessage', [
        'chat_id' => ADMIN_ID,
        'text' => "TEST OK\n" . date('H:i:s'),
        'parse_mode' => 'HTML'
    ]));
}

if ($action === 'debug_log') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!file_exists(LOG_FILE)) { echo "No log"; exit; }
    $log = file_get_contents(LOG_FILE);
    echo implode("\n", array_slice(explode("\n", $log), -200));
    exit;
}

if ($action === 'clear_log') {
    @unlink(LOG_FILE);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 404);