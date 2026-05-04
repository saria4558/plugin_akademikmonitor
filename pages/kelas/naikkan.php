<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$id = required_param('id', PARAM_INT);

try {
    $result = \local_akademikmonitor\class_manager::naikkan_kelas($id);

    if (($result['status'] ?? '') === 'lulus') {
        $message = 'Kelas XII berhasil diproses sebagai lulus.';
    } else {
        $message = 'Naik kelas berhasil. Kelas baru dibuat/digunakan untuk tingkat ' .
            s($result['nexttingkat'] ?? '-') . '. Siswa disalin: ' . (int)($result['copied'] ?? 0) .
            '. Silakan pilih wali kelas baru dan generate course untuk kelas baru tersebut.';
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/index.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
} catch (Throwable $e) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/kelas/index.php'),
        $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
