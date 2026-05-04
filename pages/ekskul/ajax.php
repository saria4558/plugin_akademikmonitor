<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

use local_akademikmonitor\service\ekskul_service;

$action = required_param('action', PARAM_ALPHAEXT);

try {
    switch ($action) {
        case 'create':
            $nama = required_param('nama', PARAM_TEXT);
            $pembinaid = required_param('pembinaid', PARAM_INT);

            if ($pembinaid <= 0) {
                throw new Exception('Pembina wajib dipilih');
            }

            $id = ekskul_service::create($nama, $pembinaid);

            echo json_encode([
                'ok' => true,
                'message' => 'Ekstrakurikuler ditambahkan',
                'data' => [
                    'id' => $id,
                    'nama' => $nama,
                    'pembina' => fullname(core_user::get_user($pembinaid)),
                    'id_pembina' => $pembinaid,
                    'is_active' => '1',
                ]
            ]);
            break;

        case 'update':
            $id = required_param('id', PARAM_INT);
            $nama = required_param('nama', PARAM_TEXT);
            $pembinaid = required_param('pembinaid', PARAM_INT);

            if ($pembinaid <= 0) {
                throw new Exception('Pembina wajib dipilih');
            }

            ekskul_service::update($id, $nama, $pembinaid);

            echo json_encode([
                'ok' => true,
                'message' => 'Ekstrakurikuler diupdate',
                'data' => [
                    'id' => $id,
                    'nama' => $nama,
                    'pembina' => fullname(core_user::get_user($pembinaid)),
                    'id_pembina' => $pembinaid,
                ]
            ]);
            break;

        case 'toggle':
            $id = required_param('id', PARAM_INT);
            ekskul_service::toggle($id);

            echo json_encode([
                'ok' => true,
                'message' => 'Status berubah'
            ]);
            break;

        default:
            echo json_encode([
                'ok' => false,
                'message' => 'Action tidak dikenal'
            ]);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage()
    ]);
}
exit;