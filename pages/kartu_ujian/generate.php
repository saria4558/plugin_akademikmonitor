<?php
/**
 * Generate kartu ujian untuk semua siswa yang layak di kelas ini.
 * Siswa yang tidak layak tidak akan dibuatkan kartu.
 */
require_once(__DIR__ . '/../../../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
require_sesskey();

global $DB;

$id = required_param('id', PARAM_INT);
$ku = $DB->get_record('kartu_ujian', ['id' => $id], '*', MUST_EXIST);

// Ambil semua peserta kelas
$pesertasql = "SELECT pk.id_user, u.firstname, u.lastname
                 FROM {peserta_kelas} pk
                 JOIN {user} u ON u.id = pk.id_user
                WHERE pk.id_kelas = :kelasid
                  AND (pk.id_role IS NULL OR pk.id_role NOT IN (
                       SELECT r.id FROM {role} r WHERE r.shortname IN ('editingteacher','teacher')
                  ))";
$peserta = $DB->get_records_sql($pesertasql, ['kelasid' => $ku->id_kelas]);

$generated = 0;
$skipped   = 0;

foreach ($peserta as $p) {
    $userid = (int)$p->id_user;

    // Nilai rata-rata
    $avg_nilai = $DB->get_field_sql(
        "SELECT AVG(gg.finalgrade)
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
          WHERE gg.userid = :uid AND gi.itemtype = 'course'",
        ['uid' => $userid]
    );
    $avg_nilai = $avg_nilai !== null ? (float)$avg_nilai : null;
    $layak = ($avg_nilai !== null && $avg_nilai >= 60);

    if (!$layak) { $skipped++; continue; }

    // Buat atau update record kartu_ujian_siswa
    if (!$DB->record_exists('kartu_ujian_siswa', ['id_kartu_ujian' => $id, 'id_user' => $userid])) {
        $rec = new stdClass();
        $rec->id_kartu_ujian = $id;
        $rec->id_user        = $userid;
        $rec->timecreated    = time();
        $DB->insert_record('kartu_ujian_siswa', $rec);
        $generated++;
    }
}

$msg = "Kartu ujian berhasil digenerate untuk $generated siswa.";
if ($skipped > 0) $msg .= " $skipped siswa tidak layak dilewati.";

redirect(
    new moodle_url('/local/akademikmonitor/pages/kartu_ujian/detail.php', ['id' => $id]),
    $msg, null, \core\output\notification::NOTIFY_SUCCESS
);
