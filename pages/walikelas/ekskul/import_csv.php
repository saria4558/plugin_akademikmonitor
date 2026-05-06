<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\ekskul_service;

global $DB;

$redirectparams = period_filter_service::append_filter_params([]);

/**
 * Membersihkan BOM UTF-8 dari value CSV.
 *
 * Kenapa function ini perlu?
 * Karena file CSV dari Excel sering menyimpan BOM di awal file.
 * Kalau tidak dibersihkan, header pertama bisa terbaca sebagai:
 *
 *   ﻿nisn
 *
 * bukan:
 *
 *   nisn
 *
 * Akibatnya validasi kolom "nisn" bisa gagal walaupun kelihatannya benar.
 */
function local_akademikmonitor_clean_csv_value(string $value): string {
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    return trim($value);
}

/**
 * Mendeteksi delimiter CSV.
 *
 * Kenapa function ini perlu?
 * Karena CSV bisa dibuat dengan koma (,) atau titik koma (;).
 * Excel Indonesia biasanya memakai titik koma (;), sedangkan beberapa editor
 * atau sistem lain memakai koma (,).
 *
 * Dengan deteksi ini, import ekskul lebih aman dan tidak terpaku pada satu format.
 */
function local_akademikmonitor_detect_csv_delimiter(string $line): string {
    $semicoloncount = substr_count($line, ';');
    $commacount = substr_count($line, ',');

    return ($semicoloncount >= $commacount) ? ';' : ',';
}

/**
 * Membaca baris pertama yang benar-benar header.
 *
 * Kenapa function ini perlu?
 * Karena template CSV memakai baris:
 *
 *   sep=;
 *
 * Baris itu berguna untuk Excel, tapi bukan header data.
 * Jadi saat import, baris sep=; harus dilewati.
 */
function local_akademikmonitor_read_csv_header($handle, string $delimiter): ?array {
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && strtolower(trim((string)$row[0])) === 'sep=;') {
            continue;
        }

        $row = array_map(function($value) {
            return strtolower(local_akademikmonitor_clean_csv_value((string)$value));
        }, $row);

        if (!empty(array_filter($row, function($value) {
            return $value !== '';
        }))) {
            return $row;
        }
    }

    return null;
}

try {
    $kelasid = required_param('kelasid', PARAM_INT);
    $semesterform = optional_param('semester', 0, PARAM_INT);
    $tahunajaranid = period_filter_service::get_selected_tahunajaranid();

    /*
     * Import termasuk aksi mengubah data.
     * Jadi hanya boleh dilakukan pada tahun ajaran aktif.
     */
    period_filter_service::require_editable_selected_period($tahunajaranid);

    $semesteraktif = in_array($semesterform, [1, 2], true)
        ? $semesterform
        : period_filter_service::get_selected_semester();

    if ($kelasid <= 0) {
        throw new \Exception('Kelas tidak valid saat import ekskul');
    }

    if (!in_array($semesteraktif, [1, 2], true)) {
        throw new \Exception('Semester aktif tidak valid');
    }

    if (empty($_FILES['csvfile']) || empty($_FILES['csvfile']['tmp_name'])) {
        throw new \Exception('File CSV belum dipilih');
    }

    $tmpname = $_FILES['csvfile']['tmp_name'];

    /*
     * Deteksi delimiter dari isi file.
     *
     * Kenapa tidak langsung fgetcsv($handle)?
     * Karena kita perlu membaca baris pertama dulu untuk tahu apakah file
     * memakai delimiter ; atau ,.
     */
    $samplehandle = fopen($tmpname, 'r');
    if (!$samplehandle) {
        throw new \Exception('Gagal membuka file CSV');
    }

    $firstline = '';
    while (($line = fgets($samplehandle)) !== false) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        /*
         * Jika baris pertama adalah sep=;, lanjut ambil baris berikutnya
         * sebagai sample header asli.
         */
        if (strtolower($line) === 'sep=;') {
            continue;
        }

        $firstline = $line;
        break;
    }

    fclose($samplehandle);

    if ($firstline === '') {
        throw new \Exception('File CSV kosong');
    }

    $delimiter = local_akademikmonitor_detect_csv_delimiter($firstline);

    $handle = fopen($tmpname, 'r');

    if (!$handle) {
        throw new \Exception('Gagal membuka file CSV');
    }

    $header = local_akademikmonitor_read_csv_header($handle, $delimiter);

    if (!$header) {
        fclose($handle);
        throw new \Exception('Header CSV tidak ditemukan');
    }

    $requiredcolumns = ['nisn', 'ekskul', 'predikat'];
    foreach ($requiredcolumns as $column) {
        if (!in_array($column, $header, true)) {
            fclose($handle);
            throw new \Exception('Kolom CSV wajib: nisn, ekskul, predikat');
        }
    }

    $nisnindex = array_search('nisn', $header, true);
    $ekskulindex = array_search('ekskul', $header, true);
    $predikatindex = array_search('predikat', $header, true);

    $field = $DB->get_record(
        'user_info_field',
        ['shortname' => 'nisn'],
        'id',
        IGNORE_MISSING
    );

    if (!$field) {
        fclose($handle);
        throw new \Exception('Field profil NISN tidak ditemukan');
    }

    $imported = 0;
    $skipped = 0;
    $rownum = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rownum++;

        /*
         * Lewati baris kosong.
         */
        if (empty(array_filter($row, function($value) {
            return trim((string)$value) !== '';
        }))) {
            continue;
        }

        $nisn = local_akademikmonitor_clean_csv_value((string)($row[$nisnindex] ?? ''));
        $namaekskul = local_akademikmonitor_clean_csv_value((string)($row[$ekskulindex] ?? ''));
        $predikat = strtoupper(local_akademikmonitor_clean_csv_value((string)($row[$predikatindex] ?? '')));

        if ($nisn === '' && $namaekskul === '' && $predikat === '') {
            continue;
        }

        if ($nisn === '' || $namaekskul === '' || $predikat === '') {
            $skipped++;
            continue;
        }

        if (!in_array($predikat, ['A', 'B', 'C', 'D'], true)) {
            $skipped++;
            continue;
        }

        /*
         * user_info_data.data bertipe TEXT.
         * Karena itu dibandingkan memakai sql_compare_text().
         */
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

        /*
         * Nama ekskul harus sudah ada di tabel ekskul.
         *
         * Kenapa tidak otomatis membuat ekskul baru?
         * Karena menu ekskul admin biasanya menjadi master data.
         * Import wali kelas sebaiknya hanya memilih dari master yang sudah sah,
         * supaya tidak muncul data ganda seperti "Pramuka", "pramuka", "PRAMUKA".
         */
        $ekskul = $DB->get_record(
            'ekskul',
            ['nama' => $namaekskul],
            'id',
            IGNORE_MISSING
        );

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

} catch (\Throwable $e) {
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