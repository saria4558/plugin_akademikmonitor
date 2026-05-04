<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

use local_akademikmonitor\service\notif_service;

$action = required_param('action', PARAM_ALPHANUMEXT);

try {
    switch ($action) {
        case 'check_token':
            $token = required_param('token', PARAM_RAW_TRIMMED);
            $res = notif_service::check_telegram_token($token);

            echo json_encode([
                'ok' => (bool)$res['ok'],
                'username' => (string)$res['username'],
                'message' => (string)$res['message'],
            ]);
            break;

        case 'save_token':
            $token = required_param('token', PARAM_RAW_TRIMMED);
            $enabled = optional_param('enabled', 1, PARAM_INT);

            $check = notif_service::check_telegram_token($token);
            if (!$check['ok']) {
                echo json_encode(['ok' => false, 'message' => $check['message']]);
                break;
            }

            $verifiedat = date('Y-m-d H:i:s');
            notif_service::save_setting($token, $check['username'], (int)$enabled, $verifiedat);

            echo json_encode([
                'ok' => true,
                'message' => 'Token tersimpan & terverifikasi',
                'username' => $check['username'],
            ]);
            break;

        case 'update_rule':
            $id = required_param('id', PARAM_INT);
            $offset = required_param('offset', PARAM_RAW_TRIMMED);
            $time = required_param('time', PARAM_RAW_TRIMMED);
            $event = required_param('event', PARAM_RAW_TRIMMED);
            $recipients = required_param('recipients', PARAM_RAW_TRIMMED);

            notif_service::update_rule($id, $offset, $time, $event, $recipients);

            echo json_encode(['ok' => true, 'message' => 'Rule berhasil diupdate']);
            break;

        case 'toggle_rule':
            $id = required_param('id', PARAM_INT);
            $new = notif_service::toggle_rule($id);

            echo json_encode([
                'ok' => true,
                'message' => 'Status rule berubah',
                'is_enabled' => $new,
            ]);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'Action tidak dikenal']);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
exit;