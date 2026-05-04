<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB, $PAGE, $OUTPUT;

$tafilter = optional_param('ta', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kelas/index.php', [
    'ta' => $tafilter,
]));
$PAGE->set_context($context);
$PAGE->set_title('Manajemen Kelas');
$PAGE->set_heading('Manajemen Kelas');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_kelas_admin_urls(string $active): array {
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

function local_akademikmonitor_tahun_label($tahun): string {
    if (!$tahun) {
        return '-';
    }
    if (property_exists($tahun, 'tahun_ajaran') && trim((string)$tahun->tahun_ajaran) !== '') {
        return (string)$tahun->tahun_ajaran;
    }
    if (property_exists($tahun, 'nama') && trim((string)$tahun->nama) !== '') {
        return (string)$tahun->nama;
    }
    return '-';
}

function local_akademikmonitor_kelas_next_label(string $tingkat): array {
    $tingkat = strtoupper(trim($tingkat));

    if ($tingkat === 'X') {
        return ['label' => 'Naik ke XI', 'is_lulus' => false];
    }

    if ($tingkat === 'XI') {
        return ['label' => 'Naik ke XII', 'is_lulus' => false];
    }

    return ['label' => 'Luluskan', 'is_lulus' => true];
}

$tahunajaran = $DB->get_records('tahun_ajaran', null, 'id DESC');
$tahunoptions = [];
foreach ($tahunajaran as $ta) {
    $tahunoptions[] = [
        'id' => (int)$ta->id,
        'nama' => format_string(local_akademikmonitor_tahun_label($ta)),
        'selected' => ((int)$tafilter === (int)$ta->id),
    ];
}

$tahunaktifrecords = $DB->get_records('tahun_ajaran', null, 'id DESC', '*', 0, 1);
$tahunaktif = $tahunaktifrecords ? reset($tahunaktifrecords) : null;
$tahunaktifid = $tahunaktif ? (int)$tahunaktif->id : 0;

$params = [];
$sql = "SELECT k.id,
               k.nama,
               k.tingkat,
               k.id_jurusan,
               k.id_tahun_ajaran,
               k.id_user,
               j.nama_jurusan,
               j.kode_jurusan,
               ta.tahun_ajaran,
               u.firstname,
               u.lastname
          FROM {kelas} k
          JOIN {jurusan} j ON j.id = k.id_jurusan
     LEFT JOIN {tahun_ajaran} ta ON ta.id = k.id_tahun_ajaran
     LEFT JOIN {user} u ON u.id = k.id_user";

if ($tafilter > 0) {
    $sql .= " WHERE k.id_tahun_ajaran = :tafilter";
    $params['tafilter'] = $tafilter;
}

$sql .= " ORDER BY k.id_tahun_ajaran DESC, k.id DESC";

$kelasrecords = $DB->get_records_sql($sql, $params);

$items = [];
$no = 1;
foreach ($kelasrecords as $k) {
    $pesertacount = $DB->count_records('peserta_kelas', ['id_kelas' => $k->id]);
    $next = local_akademikmonitor_kelas_next_label((string)$k->tingkat);
    $isaktif = $tahunaktifid > 0 && (int)$k->id_tahun_ajaran === $tahunaktifid;
    $islulusstatus = strtoupper((string)$k->tingkat) === 'XII' && !$isaktif;

    $items[] = [
        'no' => $no++,
        'id' => (int)$k->id,
        'nama' => format_string($k->nama),
        'jurusan' => format_string($k->nama_jurusan),
        'kode_jurusan' => isset($k->kode_jurusan) ? format_string((string)$k->kode_jurusan) : '',
        'tingkat' => format_string($k->tingkat),
        'tahun_ajaran' => isset($k->tahun_ajaran) ? format_string($k->tahun_ajaran) : '-',
        'wali_nama' => trim((string)($k->firstname ?? '') . ' ' . (string)($k->lastname ?? '')) ?: '-',
        'jumlah_peserta' => $pesertacount,
        'is_aktif' => $isaktif,
        'is_lulus' => $next['is_lulus'],
        'is_lulus_status' => $islulusstatus,
        'peserta_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/peserta.php', ['kelasid' => $k->id]))->out(false),
        'coursemoodle_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/coursemoodle.php', ['id' => $k->id]))->out(false),
        'edit_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/form.php', ['id' => $k->id]))->out(false),
        'delete_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/delete.php', ['id' => $k->id, 'sesskey' => sesskey()]))->out(false),
        'naik_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/naikkan.php', ['id' => $k->id, 'sesskey' => sesskey()]))->out(false),
        'naik_label' => $next['label'],
        'histori_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/histori.php', ['id' => $k->id]))->out(false),
    ];
}

$templatecontext = array_merge(local_akademikmonitor_kelas_admin_urls('kelas'), [
    'items' => $items,
    'has_items' => !empty($items),
    'add_url' => (new moodle_url('/local/akademikmonitor/pages/kelas/form.php'))->out(false),
    'tahun_options' => $tahunoptions,
    'has_tahun_options' => !empty($tahunoptions),
    'filter_action' => (new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
    'tafilter' => $tafilter,
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kelas', $templatecontext);
echo $OUTPUT->footer();
