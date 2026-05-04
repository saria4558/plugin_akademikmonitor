<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/akademikmonitor:manage', $context);
require_sesskey();

use local_akademikmonitor\service\mitra_service;

if (empty($_FILES['fileimport']['tmp_name'])) {
    redirect(
        new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
        'File tidak ditemukan',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$file = $_FILES['fileimport']['tmp_name'];
$data = [];

/**
 * Membersihkan header CSV.
 *
 * Kenapa perlu?
 * - Kadang file CSV dari Excel menyimpan BOM UTF-8 di kolom pertama.
 * - Misalnya header terbaca menjadi "\uFEFFnama", bukan "nama".
 */
function local_akademikmonitor_mitra_clean_csv_value(string $value): string {
    $value = trim($value);

    // Hapus BOM UTF-8 jika ada.
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    return trim($value);
}

if (($handle = fopen($file, 'r')) !== false) {
    /*
     * Template CSV sekarang menggunakan delimiter titik koma (;)
     * dan baris pertama "sep=;" supaya Excel langsung memecah kolom.
     */
    $firstrow = fgetcsv($handle, 0, ';');

    if ($firstrow === false) {
        fclose($handle);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
            'File CSV kosong.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $firstrow = array_map('local_akademikmonitor_mitra_clean_csv_value', $firstrow);

    /*
     * Jika file dibuka dari template Excel-friendly,
     * baris pertama adalah sep=;
     * Maka header asli ada di baris berikutnya.
     */
    if (
        count($firstrow) === 1
        && strtolower(str_replace(' ', '', $firstrow[0])) === 'sep=;'
    ) {
        $header = fgetcsv($handle, 0, ';');
    } else {
        $header = $firstrow;
    }

    if ($header === false) {
        fclose($handle);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
            'Header CSV tidak ditemukan.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $header = array_map('local_akademikmonitor_mitra_clean_csv_value', $header);

    if ($header !== ['nama', 'alamat', 'kontak']) {
        fclose($handle);

        redirect(
            new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
            'Format header salah. Harus: nama;alamat;kontak',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $row = array_map(static function($value) {
            return local_akademikmonitor_mitra_clean_csv_value((string)$value);
        }, $row);

        $nama = trim($row[0] ?? '');
        $alamat = trim($row[1] ?? '');
        $kontak = trim($row[2] ?? '');

        // Lewati baris kosong.
        if ($nama === '' && $alamat === '' && $kontak === '') {
            continue;
        }

        $data[] = [
            'nama' => $nama,
            'alamat' => $alamat,
            'kontak' => $kontak,
        ];
    }

    fclose($handle);
}

$result = mitra_service::import($data);

$message = "Import selesai. Berhasil: {$result['success']}, Gagal: {$result['failed']}";

redirect(
    new moodle_url('/local/akademikmonitor/pages/mitra/index.php'),
    $message,
    null,
    \core\output\notification::NOTIFY_SUCCESS
);