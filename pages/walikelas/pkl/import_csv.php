<?php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
require_sesskey();

use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\pkl_service;

global $DB;

$redirectparams = period_filter_service::append_filter_params([]);

try {
    $kelasid = required_param('kelasid', PARAM_INT);
    $semesterform = optional_param('semester', 0, PARAM_INT);
    $semesteraktif = in_array($semesterform, [1, 2], true)
        ? $semesterform
        : period_filter_service::get_selected_semester();

    if ($kelasid <= 0) {
        throw new moodle_exception('Kelas tidak valid saat import PKL');
    }

    if (!in_array($semesteraktif, [1, 2], true)) {
        throw new moodle_exception('Semester aktif tidak valid');
    }

    if (empty($_FILES['csvfile']['tmp_name'])) {
        throw new moodle_exception('File tidak ditemukan');
    }

    $file = $_FILES['csvfile']['tmp_name'];
    $handle = fopen($file, 'r');

    if ($handle === false) {
        throw new moodle_exception('File tidak bisa dibaca');
    }

    $firstline = fgets($handle);
    if ($firstline === false) {
        fclose($handle);
        throw new moodle_exception('File kosong');
    }

    $firstline = preg_replace('/^\xEF\xBB\xBF/', '', $firstline);

    $delimiters = [',', ';', "\t"];
    $delimiter = ',';
    $bestcount = -1;

    foreach ($delimiters as $candidate) {
        $count = count(str_getcsv($firstline, $candidate));
        if ($count > $bestcount) {
            $bestcount = $count;
            $delimiter = $candidate;
        }
    }

    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);
    $header = array_map(function($v) {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$v)));
    }, $header ?: []);

    // SEKARANG TIDAK LAGI WAJIB ADA KOLOM SEMESTER.
    $expected = ['nisn', 'mitra', 'waktu_mulai', 'waktu_selesai', 'nilai'];
    if ($header !== $expected) {
        fclose($handle);
        throw new moodle_exception(
            'Format header salah. Terbaca: ' . implode('|', $header) .
            '. Harus: nisn,mitra,waktu_mulai,waktu_selesai,nilai'
        );
    }

    $nisnfield = $DB->get_record('user_info_field', ['shortname' => 'nisn'], 'id', IGNORE_MISSING);
    if (!$nisnfield) {
        fclose($handle);
        throw new moodle_exception('Field profile NISN tidak ditemukan');
    }

    $success = 0;
    $failed = 0;
    $errors = [];
    $lineno = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineno++;

        $row = array_map(fn($v) => trim((string)$v), $row);

        if (count($row) === 1) {
            $single = $row[0];
            foreach ([',', ';', "\t"] as $fallbackdelimiter) {
                $parsed = str_getcsv($single, $fallbackdelimiter);
                if (count($parsed) > 1) {
                    $row = array_map(fn($v) => trim((string)$v), $parsed);
                    break;
                }
            }
        }

        $nisn = $row[0] ?? '';
        $mitranama = $row[1] ?? '';
        $waktu_mulai = $row[2] ?? '';
        $waktu_selesai = $row[3] ?? '';
        $nilai = $row[4] ?? '';

        if (
            $nisn === '' &&
            $mitranama === '' &&
            $waktu_mulai === '' &&
            $waktu_selesai === '' &&
            $nilai === ''
        ) {
            continue;
        }

        if ($nisn === '' || $mitranama === '' || $waktu_mulai === '' || $waktu_selesai === '' || $nilai === '') {
            $failed++;
            $errors[] = "Baris {$lineno}: data wajib belum lengkap [nisn={$nisn}] [mitra={$mitranama}] [waktu_mulai={$waktu_mulai}] [waktu_selesai={$waktu_selesai}] [nilai={$nilai}]";
            continue;
        }

        $sql = "fieldid = :fieldid AND " . $DB->sql_compare_text('data') . " = :nisn";
        $params = [
            'fieldid' => (int)$nisnfield->id,
            'nisn' => $nisn,
        ];

        $userinfodata = $DB->get_record_select(
            'user_info_data',
            $sql,
            $params,
            'userid',
            IGNORE_MISSING
        );

        if (!$userinfodata) {
            $failed++;
            $errors[] = "Baris {$lineno}: siswa dengan NISN {$nisn} tidak ditemukan";
            continue;
        }

        $user = $DB->get_record('user', ['id' => (int)$userinfodata->userid], 'id', IGNORE_MISSING);
        if (!$user) {
            $failed++;
            $errors[] = "Baris {$lineno}: user untuk NISN {$nisn} tidak ditemukan";
            continue;
        }

        $mitra = $DB->get_record('mitra_dudi', ['nama' => $mitranama], 'id, nama', IGNORE_MISSING);
        if (!$mitra) {
            $failed++;
            $errors[] = "Baris {$lineno}: mitra \"{$mitranama}\" tidak ditemukan";
            continue;
        }

        try {
            pkl_service::save(
                (int)$user->id,
                (int)$kelasid,
                (int)$mitra->id,
                (int)$semesteraktif,
                $waktu_mulai,
                $waktu_selesai,
                $nilai
            );
            $success++;
        } catch (Throwable $e) {
            $failed++;
            $errors[] = "Baris {$lineno}: " . $e->getMessage();
        }
    }

    fclose($handle);

    $message = "Import PKL selesai. Berhasil: {$success}, dilewati: {$failed}";
    if (!empty($errors)) {
        $message .= '. ' . implode(' | ', array_slice($errors, 0, 5));
    }

    $notifytype = ($success > 0)
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_ERROR;

    redirect(
        new moodle_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php', $redirectparams),
        $message,
        null,
        $notifytype
    );
} catch (Throwable $e) {
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }

    redirect(
        new moodle_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php', $redirectparams),
        'Import PKL gagal: ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}