<?php
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_akademikmonitor\service\notif_service;

$raw = file_get_contents('php://input');

if (!$raw) {
    http_response_code(200);
    exit;
}

$data = json_decode($raw, true);

if (!is_array($data) || empty($data['message'])) {
    http_response_code(200);
    exit;
}

$message = $data['message'];
$chatid = isset($message['chat']['id']) ? (string)$message['chat']['id'] : '';
$text = trim((string)($message['text'] ?? ''));
$username = trim((string)($message['from']['username'] ?? ''));

if ($chatid === '' || $text === '') {
    http_response_code(200);
    exit;
}

if (preg_match('/^\/start\s+(\d+)$/', $text, $matches)) {
    $userid = (int)$matches[1];

    notif_service::save_user_link($userid, $chatid, $username);

    $reply = "✅ Akun Telegram kamu berhasil dihubungkan ke Moodle.\n\nSekarang kamu bisa menerima notifikasi dari sistem akademik.";
    notif_service::send_telegram($chatid, $reply);

    http_response_code(200);
    exit;
}

if ($text === '/start') {
    $reply = "Halo 👋\n\nSilakan kembali ke halaman Moodle lalu klik tombol <b>Hubungkan Telegram</b> dari sana, supaya akun kamu bisa ditautkan dengan benar.";
    notif_service::send_telegram($chatid, $reply);

    http_response_code(200);
    exit;
}

http_response_code(200);
exit;