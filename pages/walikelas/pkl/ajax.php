<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\pkl_service;

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHAEXT);

try {
    switch ($action) {
        case 'save_pkl':
            $pklid = optional_param('pklid', 0, PARAM_INT);
            $userid = required_param('userid', PARAM_INT);
            $kelasid = required_param('kelasid', PARAM_INT);
            $mitraid = required_param('mitraid', PARAM_INT);
            $semester = optional_param('semester', period_filter_service::get_selected_semester(), PARAM_INT);
            $waktu_mulai = required_param('waktu_mulai', PARAM_TEXT);
            $waktu_selesai = required_param('waktu_selesai', PARAM_TEXT);
            $nilai = required_param('nilai', PARAM_TEXT);

            pkl_service::save(
                $userid,
                $kelasid,
                $mitraid,
                $semester,
                $waktu_mulai,
                $waktu_selesai,
                $nilai,
                $pklid
            );

            echo json_encode([
                'ok' => true,
                'message' => 'Data PKL berhasil disimpan',
            ]);
            break;

        default:
            echo json_encode([
                'ok' => false,
                'message' => 'Action tidak dikenal',
            ]);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}
exit;