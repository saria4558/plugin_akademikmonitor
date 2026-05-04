<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\ekskul_service;

global $DB;

$redirectparams = period_filter_service::append_filter_params([]);

try {
    $kelasid = required_param('kelasid', PARAM_INT);
    $semesterform = optional_param('semester', 0, PARAM_INT);
    $semesteraktif = in_array($semesterform, [1, 2], true)
        ? $semesterform
        : period_filter_service::get_selected_semester();

    if ($kelasid <= 0) {
        throw new moodle_exception('Kelas tidak valid saat import ekskul');
    }

    if (!in_array($semesteraktif, [1, 2], true)) {
        throw new moodle_exception('Semester aktif tidak valid');
    }

    if (empty($_FILES['csvfile']) || empty($_FILES['csvfile']['tmp_name'])) {
        throw new moodle_exception('File CSV belum dipilih');
    }

    $tmpname = $_FILES['csvfile']['tmp_name'];
    $handle = fopen($tmpname, 'r');

    if (!$handle) {
        throw new moodle_exception('Gagal membuka file CSV');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new moodle_exception('Header CSV tidak ditemukan');
    }

    $header = array_map(function($value) {
        return strtolower(trim((string)$value));
    }, $header);

    $requiredcolumns = ['nisn', 'ekskul', 'predikat'];
    foreach ($requiredcolumns as $column) {
        if (!in_array($column, $header, true)) {
            fclose($handle);
            throw new moodle_exception('Kolom CSV wajib: nisn, ekskul, predikat');
        }
    }

    $nisnindex = array_search('nisn', $header, true);
    $ekskulindex = array_search('ekskul', $header, true);
    $predikatindex = array_search('predikat', $header, true);

    $field = $DB->get_record('user_info_field', ['shortname' => 'nisn'], 'id', IGNORE_MISSING);
    if (!$field) {
        fclose($handle);
        throw new moodle_exception('Field profil NISN tidak ditemukan');
    }

    $imported = 0;
    $skipped = 0;
    $rownum = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $rownum++;

        $nisn = trim((string)($row[$nisnindex] ?? ''));
        $namaekskul = trim((string)($row[$ekskulindex] ?? ''));
        $predikat = strtoupper(trim((string)($row[$predikatindex] ?? '')));

        // Lewati baris kosong penuh.
        if ($nisn === '' && $namaekskul === '' && $predikat === '') {
            continue;
        }

        // Lewati baris tidak lengkap.
        if ($nisn === '' || $namaekskul === '' || $predikat === '') {
            $skipped++;
            continue;
        }

        if (!in_array($predikat, ['A', 'B', 'C', 'D'], true)) {
            $skipped++;
            continue;
        }

        // Penting: kolom user_info_data.data biasanya TEXT,
        // jadi harus pakai sql_compare_text().
        $sql = "SELECT uid.userid
                  FROM {user_info_data} uid
                 WHERE uid.fieldid = :fieldid
                   AND " . $DB->sql_compare_text('uid.data') . " = " . $DB->sql_compare_text(':nisn');

        $userdata = $DB->get_record_sql($sql, [
            'fieldid' => (int)$field->id,
            'nisn' => $nisn,
        ], IGNORE_MISSING);

        if (!$userdata || empty($userdata->userid)) {
            $skipped++;
            continue;
        }

        $user = $DB->get_record(
            'user',
            ['id' => (int)$userdata->userid, 'deleted' => 0],
            'id',
            IGNORE_MISSING
        );

        if (!$user) {
            $skipped++;
            continue;
        }

        $ekskul = $DB->get_record('ekskul', ['nama' => $namaekskul], 'id', IGNORE_MISSING);
        if (!$ekskul) {
            $skipped++;
            continue;
        }

        ekskul_service::save(
            (int)$user->id,
            (int)$kelasid,
            (int)$ekskul->id,
            (int)$semesteraktif,
            $predikat
        );

        $imported++;
    }

    fclose($handle);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php', $redirectparams),
        'Import ekskul selesai. Berhasil: ' . $imported . ', dilewati: ' . $skipped,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );

} catch (Throwable $e) {
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php', $redirectparams),
        'Import ekskul gagal: ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}