<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\ekskul_service;

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHAEXT);

try {
    switch ($action) {
        case 'save':
            $userid   = required_param('userid', PARAM_INT);
            $kelasid  = required_param('kelasid', PARAM_INT);
            $ekskulid = required_param('ekskulid', PARAM_INT);
            $predikat = required_param('predikat', PARAM_TEXT);
            $semester = optional_param('semester', period_filter_service::get_selected_semester(), PARAM_INT);

            ekskul_service::save($userid, $kelasid, $ekskulid, $semester, $predikat);

            echo json_encode([
                'ok' => true,
                'message' => 'Data ekstrakurikuler berhasil disimpan',
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