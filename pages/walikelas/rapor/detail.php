<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\rapor_service;

require_once($CFG->libdir . '/accesslib.php');

global $PAGE, $OUTPUT, $USER, $DB ;

$userid = required_param('userid', PARAM_INT);
$kelasidparam = optional_param('kelasid', 0, PARAM_INT);
$semester = period_filter_service::get_selected_semester();
$tahunajaranid = period_filter_service::get_selected_tahunajaranid();

$detail = rapor_service::get_detail_page_data($userid, $semester, $tahunajaranid, $kelasidparam);
$course = $detail['course'];
$group = $detail['group'];
$kelasid = (int)$detail['kelasid'];
$template = $detail['template'];

$systemcontext = context_system::instance();
$coursecontext = context_course::instance((int)$course->id);

// Halaman detail rapor adalah halaman local plugin, jadi context halaman dibuat system.
// Jangan memakai context_course sebagai PAGE context, karena wali kelas belum tentu bisa membuka
// semua course mapel hasil generate. Kalau PAGE context course dipakai, Moodle akan melempar
// requireloginerror: Course or activity not accessible.
$PAGE->set_url('/local/akademikmonitor/pages/walikelas/rapor/detail.php', [
    'userid' => $userid,
    'kelasid' => $kelasid,
    'semester' => $semester,
    'tahunajaranid' => $tahunajaranid,
]);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Detail Raport');
$PAGE->set_heading('Detail Raport');

// Admin/manager boleh lewat system context. Wali kelas boleh lewat course context kelasnya.
// Selain itu, pastikan siswa memang anggota group kelas yang sedang dibuka.
$studentingroup = $DB->record_exists('groups_members', [
    'groupid' => $kelasid,
    'userid' => $userid,
]);

$canview = has_capability('local/akademikmonitor:viewrapor', $systemcontext)
    || has_capability('local/akademikmonitor:viewrapor', $coursecontext);

if (!$studentingroup || !$canview) {
    throw new required_capability_exception($coursecontext, 'local/akademikmonitor:viewrapor', 'nopermissions', '');
}

$periodfilterdata = period_filter_service::get_filter_ui_data(
    '/local/akademikmonitor/pages/walikelas/rapor/detail.php',
    [
        'userid' => $userid,
        'kelasid' => $kelasid,
    ]
);

$template['periodfilter'] = $periodfilterdata['periodfilter'] ?? $periodfilterdata;

$PAGE->requires->css('/local/akademikmonitor/css/walikelasstyles.css');
$PAGE->requires->css('/local/akademikmonitor/css/styles.css');
$PAGE->requires->js_call_amd('local_akademikmonitor/tabrapor', 'init');
$PAGE->requires->js_call_amd('local_akademikmonitor/catatan', 'init', [$userid, $kelasid, $semester]);
$PAGE->requires->js_call_amd('local_akademikmonitor/kenaikan_kelas', 'init', [$userid, $kelasid]);
$PAGE->requires->js_call_amd('local_akademikmonitor/ketidakhadiran', 'init', [$userid, $kelasid, $semester]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/walikelas/detail_raport', $template);
echo $OUTPUT->footer();
