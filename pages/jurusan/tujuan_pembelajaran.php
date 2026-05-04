<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB, $PAGE, $OUTPUT;

$kmid = required_param('kmid', PARAM_INT);
$cpid = required_param('cpid', PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid]));
$PAGE->set_context($context);
$PAGE->set_title('Tujuan Pembelajaran');
$PAGE->set_heading('Tujuan Pembelajaran');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_backend_admin_urls(string $active): array {
    return [
        'is_dashboard' => $active === 'dashboard',
        'is_tahun_ajaran' => $active === 'tahun_ajaran',
        'is_kurikulum' => $active === 'kurikulum',
        'is_manajemen_jurusan' => $active === 'jurusan',
        'is_manajemen_kelas' => $active === 'kelas',
        'is_mata_pelajaran' => $active === 'mata_pelajaran',
        'is_matpel' => $active === 'mata_pelajaran',
        'is_kktp' => $active === 'kktp',
        'is_notif' => $active === 'notif',
        'is_ekskul' => $active === 'ekskul',
        'is_mitra' => $active === 'mitra',
        'dashboard_url' => (new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url' => (new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url' => (new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url' => (new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url' => (new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url' => (new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url' => (new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url' => (new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
    ];
}

function local_akademikmonitor_backend_tahun_label(?stdClass $tahun): string {
    if (!$tahun) { return '-'; }
    if (property_exists($tahun, 'tahun_ajaran')) { return (string)$tahun->tahun_ajaran; }
    if (property_exists($tahun, 'nama')) { return (string)$tahun->nama; }
    return '-';
}
function local_akademikmonitor_backend_kurikulum_namefield(): string {
    global $DB;
    $columns = $DB->get_columns('kurikulum');
    return isset($columns['nama']) ? 'nama' : 'nama_kurikulum';
}
function local_akademikmonitor_backend_active_tahun(): ?stdClass {
    global $DB;
    $activeid = (int)get_config('local_akademikmonitor', 'active_tahunajaranid');
    if ($activeid > 0) {
        $record = $DB->get_record('tahun_ajaran', ['id' => $activeid], '*', IGNORE_MISSING);
        if ($record) { return $record; }
    }
    $columns = $DB->get_columns('tahun_ajaran');
    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('tahun_ajaran', [$field => 1], '*', IGNORE_MULTIPLE);
            if ($record) { return $record; }
        }
    }
    $records = $DB->get_records('tahun_ajaran', null, 'id DESC', '*', 0, 1);
    return $records ? reset($records) : null;
}
function local_akademikmonitor_backend_active_kurikulum(): ?stdClass {
    global $DB;
    $activeid = (int)get_config('local_akademikmonitor', 'active_kurikulumid');
    if ($activeid > 0) {
        $record = $DB->get_record('kurikulum', ['id' => $activeid], '*', IGNORE_MISSING);
        if ($record) { return $record; }
    }
    $columns = $DB->get_columns('kurikulum');
    foreach (['is_active', 'aktif'] as $field) {
        if (isset($columns[$field])) {
            $record = $DB->get_record('kurikulum', [$field => 1], '*', IGNORE_MULTIPLE);
            if ($record) { return $record; }
        }
    }
    $records = $DB->get_records('kurikulum', null, 'id DESC', '*', 0, 1);
    return $records ? reset($records) : null;
}
function local_akademikmonitor_backend_ensure_kurikulum_jurusan(int $jurusanid, ?int $kurikulumid = null, ?int $tahunajaranid = null): ?stdClass {
    global $DB;
    if ($jurusanid <= 0) { return null; }
    if (empty($kurikulumid)) { $kurikulum = local_akademikmonitor_backend_active_kurikulum(); $kurikulumid = $kurikulum ? (int)$kurikulum->id : 0; }
    if (empty($tahunajaranid)) { $tahun = local_akademikmonitor_backend_active_tahun(); $tahunajaranid = $tahun ? (int)$tahun->id : 0; }
    if ($kurikulumid <= 0 || $tahunajaranid <= 0) { return null; }
    $existing = $DB->get_record('kurikulum_jurusan', ['id_jurusan'=>$jurusanid, 'id_kurikulum'=>$kurikulumid, 'id_tahun_ajaran'=>$tahunajaranid], '*', IGNORE_MULTIPLE);
    if ($existing) { return $existing; }
    $record = new stdClass();
    $record->id_jurusan = $jurusanid;
    $record->id_kurikulum = $kurikulumid;
    $record->id_tahun_ajaran = $tahunajaranid;
    $record->id = $DB->insert_record('kurikulum_jurusan', $record);
    return $record;
}
function local_akademikmonitor_backend_get_kurikulum_jurusan(int $jurusanid, ?int $tahunajaranid = null): ?stdClass {
    global $DB;
    if ($jurusanid <= 0) { return null; }
    $activekurikulum = local_akademikmonitor_backend_active_kurikulum();
    $activetahun = local_akademikmonitor_backend_active_tahun();
    $kurikulumid = $activekurikulum ? (int)$activekurikulum->id : 0;
    $tahunid = $tahunajaranid ?: ($activetahun ? (int)$activetahun->id : 0);
    if ($kurikulumid > 0 && $tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', ['id_jurusan'=>$jurusanid, 'id_kurikulum'=>$kurikulumid, 'id_tahun_ajaran'=>$tahunid], '*', IGNORE_MULTIPLE);
        if ($record) { return $record; }
        return local_akademikmonitor_backend_ensure_kurikulum_jurusan($jurusanid, $kurikulumid, $tahunid);
    }
    if ($tahunid > 0) {
        $record = $DB->get_record('kurikulum_jurusan', ['id_jurusan'=>$jurusanid, 'id_tahun_ajaran'=>$tahunid], '*', IGNORE_MULTIPLE);
        if ($record) { return $record; }
    }
    $records = $DB->get_records('kurikulum_jurusan', ['id_jurusan'=>$jurusanid], 'id DESC', '*', 0, 1);
    return $records ? reset($records) : null;
}
function local_akademikmonitor_backend_normalize_tingkat(string $tingkat): string {
    $tingkat = strtoupper(trim($tingkat));
    return in_array($tingkat, ['X', 'XI', 'XII'], true) ? $tingkat : 'X';
}
function local_akademikmonitor_backend_jam(string $jam): string {
    $jam = trim($jam);
    if ($jam === '') { return ''; }
    if (preg_match('/^\d{1,2}$/', $jam)) { return $jam; }
    return substr($jam, 0, 8);
}

$km = $DB->get_record('kurikulum_mapel', ['id' => $kmid], '*', MUST_EXIST);
$cp = $DB->get_record('capaian_pembelajaran', ['id' => $cpid, 'id_kurikulum_mapel' => $kmid], '*', MUST_EXIST);
$hasKonten = isset($DB->get_columns('tujuan_pembelajaran')['konten']);

if ($deleteid > 0) {
    require_sesskey();
    $tp = $DB->get_record('tujuan_pembelajaran', ['id' => $deleteid, 'id_capaian_pembelajaran' => $cpid], '*', MUST_EXIST);
    \local_akademikmonitor\service\tp_gradebook_service::delete_grade_items_for_tp((int)$tp->id);
    $DB->delete_records('assignment_tp', ['id_tp' => $tp->id]);
    $DB->delete_records('quiz_tp', ['id_tp' => $tp->id]);
    $DB->delete_records('tujuan_pembelajaran', ['id' => $tp->id]);
    redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid]), 'Tujuan pembelajaran berhasil dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $kontens = optional_param_array('konten', [], PARAM_TEXT);
    $kompetensis = optional_param_array('kompetensi', [], PARAM_TEXT);
    $dpls = optional_param_array('dpl', [], PARAM_TEXT);
    $atps = optional_param_array('atp', [], PARAM_TEXT);
    $deskripsis = optional_param_array('deskripsi', [], PARAM_RAW_TRIMMED);

    $inserted = 0;
    $max = max(count($kontens), count($kompetensis), count($dpls), count($atps), count($deskripsis));
    for ($i = 0; $i < $max; $i++) {
        $deskripsi = trim((string)($deskripsis[$i] ?? ''));
        $kompetensi = trim((string)($kompetensis[$i] ?? ''));
        $konten = trim((string)($kontens[$i] ?? ''));
        $dpl = trim((string)($dpls[$i] ?? ''));
        $atp = trim((string)($atps[$i] ?? ''));

        if ($deskripsi === '' && $kompetensi === '' && $konten === '' && $dpl === '' && $atp === '') {
            continue;
        }
        if ($deskripsi === '') {
            continue;
        }

        $data = new stdClass();
        if ($hasKonten) {
            $data->konten = substr($konten, 0, 100);
        }
        $data->kompetensi = $kompetensi;
        $data->dpl = $dpl;
        $data->atp = $atp;
        $data->deskripsi = $deskripsi;
        $data->id_capaian_pembelajaran = $cpid;
        $tpid = $DB->insert_record('tujuan_pembelajaran', $data);
        \local_akademikmonitor\service\tp_gradebook_service::ensure_grade_items_for_tp((int)$tpid);
        $inserted++;
    }

    redirect(new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid]), $inserted . ' tujuan pembelajaran berhasil disimpan.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$records = $DB->get_records('tujuan_pembelajaran', ['id_capaian_pembelajaran' => $cpid], 'id ASC');
$groups = [];
$index = [];
$no = 1;
foreach ($records as $record) {
    $konten = $hasKonten && isset($record->konten) && trim((string)$record->konten) !== '' ? (string)$record->konten : 'Tanpa Konten';
    if (!isset($index[$konten])) {
        $index[$konten] = count($groups);
        $groups[] = ['konten' => format_string($konten), 'rows' => []];
    }
    $groups[$index[$konten]]['rows'][] = [
        'no' => $no++,
        'kompetensi' => format_string((string)($record->kompetensi ?? '')),
        'dpl' => format_string((string)($record->dpl ?? '')),
        'atp' => format_string((string)($record->atp ?? '')),
        'deskripsi' => format_text((string)$record->deskripsi, FORMAT_PLAIN),
        'edit_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/tp_form.php', ['id' => $record->id, 'kmid' => $kmid, 'cpid' => $cpid]))->out(false),
        'delete_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid, 'deleteid' => $record->id, 'sesskey' => sesskey()]))->out(false),
    ];
}

$templatecontext = array_merge(local_akademikmonitor_backend_admin_urls('jurusan'), [
    'cp' => format_text($cp->deskripsi, FORMAT_PLAIN),
    'jp' => s((string)($km->jam_pelajaran ?? '-')),
    'kmid' => $kmid,
    'cpid' => $cpid,
    'groups' => $groups,
    'has_groups' => !empty($groups),
    'action_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/tujuan_pembelajaran.php', ['kmid' => $kmid, 'cpid' => $cpid]))->out(false),
    'back_url' => (new moodle_url('/local/akademikmonitor/pages/jurusan/capaian_pembelajaran.php', ['kmid' => $kmid]))->out(false),
    'sesskey' => sesskey(),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/tp', $templatecontext);
echo $OUTPUT->footer();
