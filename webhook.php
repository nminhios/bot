<?php
require_once 'config.php';

// LOG 
log_debug("########## WEBHOOK ##########");

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$input = file_get_contents('php://input');
log_debug("RAW: " . substr($input, 0, 1500));

$update = json_decode($input, true);
if (!$update) {
    log_debug("Invalid JSON");
    exit;
}


if (isset($update['message'])) {
    $msg = $update['message'];
    $chatId = (string)($msg['chat']['id'] ?? '0');
    $text = trim($msg['text'] ?? '');
    $from = $msg['from'] ?? [];

    log_debug("MSG: chatId=$chatId text=[$text]");

    // Lưu user
    $users = json_read(USERS_FILE);
    if (!isset($users[$chatId])) $users[$chatId] = ['balance' => 0];
    $users[$chatId]['name'] = $from['first_name'] ?? 'Khách';
    $users[$chatId]['username'] = $from['username'] ?? '';
    json_write(USERS_FILE, $users);

    if (strpos($text, '/add') === 0) {
        log_debug("/add detected");
        $parts = preg_split('/\s+/', trim($text));

        if (count($parts) < 3) {
            tg_api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Cú pháp: <code>/add USER_ID SỐ_TIỀN</code>\nVD: <code>/add 8003281912 50000</code>",
                'parse_mode' => 'HTML'
            ]);
            exit;
        }

        $targetUserId = (string)$parts[1];
        $amount = (int)$parts[2];

        if ($chatId !== (string)ADMIN_ID) {
            tg_api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Bạn không phải admin!",
                'parse_mode' => 'HTML'
            ]);
            exit;
        }

        if (!ctype_digit($targetUserId) || $amount <= 0) {
            tg_api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ User ID hoặc số tiền không hợp lệ!",
                'parse_mode' => 'HTML'
            ]);
            exit;
        }

        // Cộng tiền
        $users = json_read(USERS_FILE);
        if (!isset($users[$targetUserId])) $users[$targetUserId] = ['balance' => 0];
        $oldBalance = (int)($users[$targetUserId]['balance'] ?? 0);
        $users[$targetUserId]['balance'] = $oldBalance + $amount;
        json_write(USERS_FILE, $users);
        $newBalance = $users[$targetUserId]['balance'];

        log_debug("/add OK: $targetUserId $oldBalance+$amount=$newBalance");

        // Lưu lịch sử nạp
        $deposits = json_read(DEPOSITS_FILE);
        $deposits[] = [
            'depositId' => 'D' . substr(md5(uniqid()), 0, 10),
            'orderId' => 'ADMIN' . time(),
            'userId' => $targetUserId,
            'userName' => $users[$targetUserId]['name'] ?? 'Khách',
            'username' => $users[$targetUserId]['username'] ?? '',
            'amount' => $amount,
            'oldBalance' => $oldBalance,
            'newBalance' => $newBalance,
            'status' => 'success',
            'method' => 'admin_add',
            'approvedAt' => date('c'),
            'approvedBy' => $chatId
        ];
        json_write(DEPOSITS_FILE, $deposits);

        // Báo admin
        tg_api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ <b>ĐÃ CỘNG TIỀN</b>\n\n" .
                      "👤 ID: <code>{$targetUserId}</code>\n" .
                      "💰 +" . number_format($amount, 0, ',', '.') . "đ\n" .
                      "💳 Số dư: <b>" . number_format($newBalance, 0, ',', '.') . "đ</b>",
            'parse_mode' => 'HTML'
        ]);

        // Báo user
        $userName = $users[$targetUserId]['name'] ?? 'bạn';
        tg_api('sendMessage', [
            'chat_id' => $targetUserId,
            'text' => "✅ <b>NẠP TIỀN THÀNH CÔNG!</b>\n\n" .
                      "Xin chào <b>{$userName}</b>,\n\n" .
                      "💰 +" . number_format($amount, 0, ',', '.') . "đ\n" .
                      "💳 Số dư: <b>" . number_format($newBalance, 0, ',', '.') . "đ</b>\n\n" .
                      "🎮 Mở Mini App mua key ngay!",
            'parse_mode' => 'HTML'
        ]);

        exit;
    }

    // /id
    if ($text === '/id') {
        tg_api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "🆔 <code>{$chatId}</code>",
            'parse_mode' => 'HTML'
        ]);
        exit;
    }

    // /help
    if ($text === '/help') {
        tg_api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "📖 <b>HƯỚNG DẪN</b>\n\n" .
                      "• <code>/add USER_ID SỐ_TIỀN</code>\n" .
                      "• <code>/id</code> — Lấy ID\n" .
                      "• <code>/start</code> — Mở app",
            'parse_mode' => 'HTML'
        ]);
        exit;
    }

    // /start
    if ($text === '/start' || $text === '') {
        $name = $from['first_name'] ?? 'bạn';
        $balance = number_format($users[$chatId]['balance'] ?? 0, 0, ',', '.');

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $dir = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
        $appUrl = "$scheme://$host$dir/index.html";

        $text2 = "👋 Xin chào <b>{$name}</b>!\n\n" .
                 "🌿 Chào mừng đến <b>FaskTrueStore</b>\n\n" .
                 "💰 Số dư: <b>{$balance}đ</b>";

        if ($chatId === (string)ADMIN_ID) {
            $text2 .= "\n\n👑 <b>ADMIN</b> — Dùng <code>/help</code>";
        }

        $sendData = [
            'chat_id' => $chatId,
            'text' => $text2,
            'parse_mode' => 'HTML'
        ];

        if (strpos($appUrl, 'https://') === 0) {
            $sendData['reply_markup'] = [
                'inline_keyboard' => [[
                    ['text' => '🌿 Mở FaskTrueStore', 'web_app' => ['url' => $appUrl]]
                ]]
            ];
        }

        tg_api('sendMessage', $sendData);
        exit;
    }

    exit;
}

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    tg_api('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => 'Dùng lệnh /add để duyệt',
        'show_alert' => true
    ]);
    exit;
}

log_debug("WEBHOOK: no handler");