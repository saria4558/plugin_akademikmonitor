<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB;

$id = required_param('id', PARAM_INT);
$DB->get_record('jurusan', ['id' => $id], '*', MUST_EXIST);

if ($DB->record_exists('kelas', ['id_jurusan' => $id])) {
    redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'), 'Jurusan tidak bisa dihapus karena masih dipakai oleh kelas.', null, \core\output\notification::NOTIFY_ERROR);
}

$kjs = $DB->get_records('kurikulum_jurusan', ['id_jurusan' => $id], '', 'id');
foreach ($kjs as $kj) {
    $kms = $DB->get_records('kurikulum_mapel', ['id_kurikulum_jurusan' => $kj->id], '', 'id');
    foreach ($kms as $km) {
        $cps = $DB->get_records('capaian_pembelajaran', ['id_kurikulum_mapel' => $km->id], '', 'id');
        foreach ($cps as $cp) {
            $tps = $DB->get_records('tujuan_pembelajaran', ['id_capaian_pembelajaran' => $cp->id], '', 'id');
            foreach ($tps as $tp) {
                $DB->delete_records('grade_items_tp', ['id_tp' => $tp->id]);
                $DB->delete_records('assignment_tp', ['id_tp' => $tp->id]);
                $DB->delete_records('quiz_tp', ['id_tp' => $tp->id]);
            }
            $DB->delete_records('tujuan_pembelajaran', ['id_capaian_pembelajaran' => $cp->id]);
        }
        $DB->delete_records('capaian_pembelajaran', ['id_kurikulum_mapel' => $km->id]);
        $DB->delete_records('course_mapel', ['id_kurikulum_mapel' => $km->id]);
    }
    $DB->delete_records('kurikulum_mapel', ['id_kurikulum_jurusan' => $kj->id]);
}
$DB->delete_records('kurikulum_jurusan', ['id_jurusan' => $id]);
$DB->delete_records('jurusan', ['id' => $id]);

redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'), 'Jurusan berhasil dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
