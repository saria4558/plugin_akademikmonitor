<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB, $PAGE, $OUTPUT, $CFG;

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_title('Detail Kartu Ujian');
$PAGE->set_heading('Detail Kartu Ujian');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));
$PAGE->requires->js_call_amd('local_akademikmonitor/sidebar', 'init');

function local_akademikmonitor_kudetail_admin_urls(): array {
    return [
        'is_dashboard'=>false,'is_tahun_ajaran'=>false,'is_kurikulum'=>false,
        'is_manajemen_jurusan'=>false,'is_manajemen_kelas'=>false,
        'is_mata_pelajaran'=>false,'is_matpel'=>false,'is_kktp'=>false,
        'is_notif'=>false,'is_ekskul'=>false,'is_mitra'=>false,'is_kartu_ujian'=>true,
        'dashboard_url'         =>(new moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
        'tahun_ajaran_url'      =>(new moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
        'kurikulum_url'         =>(new moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
        'manajemen_jurusan_url' =>(new moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
        'manajemen_kelas_url'   =>(new moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
        'mata_pelajaran_url'    =>(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'matpel_url'            =>(new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
        'kktp_url'              =>(new moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
        'notif_url'             =>(new moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
        'ekskul_url'            =>(new moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
        'mitra_url'             =>(new moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
        'kartu_ujian_url'       =>(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
    ];
}

// Publish action
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'publish') {
    require_sesskey();
    $DB->set_field('kartu_ujian', 'status', 'published', ['id' => $id]);
    redirect(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]), 'Kartu ujian berhasil dipublikasikan.', null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($action === 'unpublish') {
    require_sesskey();
    $DB->set_field('kartu_ujian', 'status', 'draft', ['id' => $id]);
    redirect(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]), 'Kartu ujian kembali ke draft.', null, \core\output\notification::NOTIFY_WARNING);
}

// Ambil data kartu ujian
$sql = "SELECT ku.*, k.nama AS nama_kelas, k.tingkat, k.id_user AS walikelas_id,
               j.nama_jurusan, ta.tahun_ajaran AS tahun_label,
               kur.nama AS nama_kurikulum,
               u.firstname AS wk_first, u.lastname AS wk_last
          FROM {kartu_ujian} ku
          JOIN {kelas} k ON k.id = ku.id_kelas
          JOIN {jurusan} j ON j.id = k.id_jurusan
     LEFT JOIN {tahun_ajaran} ta ON ta.id = ku.id_tahun_ajaran
     LEFT JOIN {kurikulum} kur ON kur.id = ku.id_kurikulum
     LEFT JOIN {user} u ON u.id = k.id_user
         WHERE ku.id = :id";
$ku = $DB->get_record_sql($sql, ['id' => $id], MUST_EXIST);

$walikelasname = trim(($ku->wk_first ?? '') . ' ' . ($ku->wk_last ?? '')) ?: '-';

// Ambil peserta kelas
$pesertasql = "SELECT pk.id AS pkid, pk.id_user,
                      u.firstname, u.lastname, u.idnumber,
                      pk.id_role
                 FROM {peserta_kelas} pk
                 JOIN {user} u ON u.id = pk.id_user
                WHERE pk.id_kelas = :kelasid
                  AND (pk.id_role IS NULL OR pk.id_role NOT IN (
                       SELECT r.id FROM {role} r WHERE r.shortname = 'editingteacher' OR r.shortname = 'teacher'
                  ))
             ORDER BY u.lastname ASC, u.firstname ASC";
$peserta = $DB->get_records_sql($pesertasql, ['kelasid' => $ku->id_kelas]);

// Ambil nilai rata-rata & absensi dari gradebook Moodle per siswa
// Nilai dari mdl_grade_grades melalui course yang terhubung dengan kelas ini
$courseids = [];
$coursemapelrecs = $DB->get_records_sql(
    "SELECT DISTINCT cm.id_course FROM {course_mapel} cm
      JOIN {kurikulum_mapel} km ON km.id = cm.id_kurikulum_mapel
      JOIN {kurikulum_jurusan} kj ON kj.id = km.id_kurikulum_jurusan
     WHERE kj.id_jurusan = :jurusanid AND kj.id_tahun_ajaran = :tahunid",
    ['jurusanid' => $ku->id_kelas, 'tahunid' => $ku->id_tahun_ajaran]
);
// Simpel: kita ambil semua grade_items kategori course yang enrol pada kelas ini
// Status ujian: siswa layak jika nilai rata2 >= 60 dan kehadiran >= 75%

$siswarows = [];
$no = 1;
foreach ($peserta as $p) {
    $userid = (int)$p->id_user;
    $fullname = fullname($p);

    // Hitung absensi (dari mdl_attendance jika ada, atau kita set N/A)
    $absensi_pct = null;
    if ($DB->get_manager()->table_exists('attendance_log')) {
        // Hitung sederhana: hadir / total sesi * 100
        $total_sesi = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {attendance_sessions} ats
              JOIN {attendance} at ON at.id = ats.attendanceid
              JOIN {course_modules} cm2 ON cm2.instance = at.id AND cm2.module = (SELECT id FROM {modules} WHERE name='attendance')
             WHERE at.course IN (SELECT e.courseid FROM {enrol} e JOIN {user_enrolments} ue ON ue.enrolid = e.id WHERE ue.userid = :uid)",
            ['uid' => $userid]
        );
        $hadir = 0;
        if ($total_sesi > 0) {
            $hadir = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {attendance_log} al
                  JOIN {attendance_statuses} ast ON ast.id = al.statusid
                 WHERE al.studentid = :uid AND ast.acronym IN ('P','H','Hadir')",
                ['uid' => $userid]
            );
            $absensi_pct = $total_sesi > 0 ? round(($hadir / $total_sesi) * 100, 1) : 0;
        }
    }

    // Nilai rata-rata dari grade_grades
    $avg_nilai = $DB->get_field_sql(
        "SELECT AVG(gg.finalgrade)
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
          WHERE gg.userid = :uid AND gi.itemtype = 'course'",
        ['uid' => $userid]
    );
    $avg_nilai = $avg_nilai !== null ? round((float)$avg_nilai, 1) : null;

    // Cek kelayakan: nilai >= 60 (atau null = belum ada nilai = tidak layak)
    $layak = ($avg_nilai !== null && $avg_nilai >= 60);
    if ($absensi_pct !== null && $absensi_pct < 75) {
        $layak = false;
    }

    // Apakah sudah ada kartu ujian yang tergenerate untuk siswa ini
    $has_kartu = $DB->record_exists('kartu_ujian_siswa', [
        'id_kartu_ujian' => $id,
        'id_user'        => $userid,
    ]);

    $siswarows[] = [
        'no'           => $no++,
        'userid'       => $userid,
        'fullname'     => format_string($fullname),
        'idnumber'     => s($p->idnumber ?? '-'),
        'avg_nilai'    => $avg_nilai !== null ? $avg_nilai : '-',
        'absensi'      => $absensi_pct !== null ? $absensi_pct . '%' : 'N/A',
        'layak'        => $layak,
        'tidak_layak'  => !$layak,
        'has_kartu'    => $has_kartu,
        'status_label' => $layak ? 'Layak Ujian' : 'Tidak Layak',
        'download_url' => $has_kartu
            ? (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/download.php', ['kid' => $id, 'uid' => $userid, 'sesskey' => sesskey()]))->out(false)
            : '',
    ];
}

$ispublished = $ku->status === 'published';

$templatecontext = array_merge(local_akademikmonitor_kudetail_admin_urls(), [
    'ku_id'         => (int)$ku->id,
    'nama_ujian'    => format_string($ku->nama_ujian),
    'nama_kelas'    => format_string($ku->nama_kelas),
    'nama_jurusan'  => format_string($ku->nama_jurusan),
    'tahun_ajaran'  => format_string($ku->tahun_label ?? '-'),
    'semester'      => format_string($ku->semester),
    'nama_kurikulum'=> format_string($ku->nama_kurikulum ?? '-'),
    'penandatangan' => format_string($ku->penandatangan ?? '-'),
    'wali_kelas'    => $walikelasname,
    'status'        => $ku->status,
    'is_published'  => $ispublished,
    'is_draft'      => !$ispublished,
    'siswa'         => $siswarows,
    'has_siswa'     => !empty($siswarows),
    'publish_url'   => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id, 'action' => 'publish', 'sesskey' => sesskey()]))->out(false),
    'unpublish_url' => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id, 'action' => 'unpublish', 'sesskey' => sesskey()]))->out(false),
    'generate_url'  => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/generate.php', ['id' => $id, 'sesskey' => sesskey()]))->out(false),
    'edit_url'      => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/form.php', ['id' => $id]))->out(false),
    'back_url'      => (new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'))->out(false),
    'sesskey'       => sesskey(),
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/kartu_ujian_detail', $templatecontext);
echo $OUTPUT->footer();
