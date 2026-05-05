<?php
/**
 * Generate & download kartu ujian PDF untuk satu siswa.
 *
 * Akses:
 * - Admin           → boleh download siapa saja, layak maupun tidak layak
 * - Wali kelas      → boleh download siswa di kelasnya, layak maupun tidak layak
 * - Siswa           → hanya boleh download milik sendiri, dan hanya jika LAYAK
 */
require_once(__DIR__ . '/../../../../config.php');
global $CFG, $DB, $USER;

require_once($CFG->dirroot . '/local/akademikmonitor/lib/dompdf/autoload.inc.php');
require_once($CFG->dirroot . '/local/akademikmonitor/classes/service/walikelas/common_service.php');

use Dompdf\Dompdf;
use local_akademikmonitor\service\walikelas\common_service;

require_login();
require_sesskey();

$kid = required_param('kid', PARAM_INT);   // id kartu_ujian
$uid = required_param('uid', PARAM_INT);   // id user (siswa)

$context  = context_system::instance();
$isadmin  = has_capability('moodle/site:config', $context);
$curuser  = (int)$USER->id;

// Pastikan kartu sudah published
$ku = $DB->get_record('kartu_ujian', ['id' => $kid, 'status' => 'published'], '*', MUST_EXIST);

// Cek apakah siswa LAYAK (ada di kartu_ujian_siswa)
$kus     = $DB->get_record('kartu_ujian_siswa', ['id_kartu_ujian' => $kid, 'id_user' => $uid]);
$islayak = !empty($kus);

// Cek apakah user login adalah wali kelas dari kelas kartu ujian ini
$iswalikelas = false;
if (!$isadmin) {
    $kelasdipegang = common_service::get_group_walikelas($curuser);
    $iswalikelas   = isset($kelasdipegang[(int)$ku->id_kelas]);
}

// Aturan akses:
// - Admin          : boleh semua
// - Wali kelas     : boleh semua siswa di kelasnya (layak & tidak layak)
// - Siswa sendiri  : hanya boleh kalau LAYAK
if (!$isadmin && !$iswalikelas) {
    // Bukan admin & bukan wali kelas → harus siswa sendiri dan layak.
    if ($curuser !== $uid) {
        throw new \Exception('Download kartu ujian siswa lain tidak diizinkan.');
    }

    if (!$islayak) {
        throw new \Exception('Anda tidak dinyatakan layak mengikuti ujian ini. Hubungi wali kelas Anda.');
    }
}

// Kalau wali kelas download siswa tidak layak → buat data kus dummy (tidak simpan ke DB)
if (!$kus) {
    $kus           = new stdClass();
    $kus->id       = 0;
    $kus->id_user  = $uid;
    $islayak       = false;
}

// ─── Data siswa ───────────────────────────────────────────────────────────────
$usersql  = "SELECT u.*, u.idnumber AS nomor_induk FROM {user} u WHERE u.id = :uid";
$userdata = $DB->get_record_sql($usersql, ['uid' => $uid], MUST_EXIST);

/*
 * Ambil NIS/NISN dari custom profile field Moodle.
 *
 * Di Moodle, field tambahan siswa seperti nisn/nis biasanya tersimpan di:
 * - user_info_field
 * - user_info_data
 *
 * Jadi jangan hanya mengandalkan user.idnumber.
 */
$nisn = '';
$nis = '';

$fieldrecords = $DB->get_records_list(
    'user_info_field',
    'shortname',
    ['nisn', 'nis'],
    '',
    'id, shortname'
);

if ($fieldrecords) {
    foreach ($fieldrecords as $field) {
        $value = $DB->get_field(
            'user_info_data',
            'data',
            [
                'userid' => (int)$uid,
                'fieldid' => (int)$field->id,
            ],
            IGNORE_MISSING
        );

        $value = trim((string)$value);

        if ($field->shortname === 'nisn') {
            $nisn = $value;
        }

        if ($field->shortname === 'nis') {
            $nis = $value;
        }
    }
}

/*
 * Fallback:
 * Kalau NIS belum ada di custom profile field, pakai user.idnumber.
 */
if ($nis === '') {
    $nis = trim((string)($userdata->idnumber ?? ''));
}

$nisnisnlabel = '-';

if ($nis !== '' && $nisn !== '') {
    $nisnisnlabel = $nis . ' / ' . $nisn;
} else if ($nisn !== '') {
    $nisnisnlabel = $nisn;
} else if ($nis !== '') {
    $nisnisnlabel = $nis;
}

$kelassql = "SELECT k.nama AS nama_kelas, k.tingkat, j.nama_jurusan, ta.tahun_ajaran,
                    u2.firstname AS wk_first, u2.lastname AS wk_last,
                    kur.nama AS nama_kurikulum
               FROM {kelas} k
               JOIN {jurusan} j ON j.id = k.id_jurusan
          LEFT JOIN {tahun_ajaran} ta ON ta.id = k.id_tahun_ajaran
          LEFT JOIN {user} u2 ON u2.id = k.id_user
          LEFT JOIN {kurikulum} kur ON kur.id = :kurid
              WHERE k.id = :kelasid";
$kelasdata = $DB->get_record_sql($kelassql, ['kelasid' => $ku->id_kelas, 'kurid' => $ku->id_kurikulum ?? 0]);

// Nomor peserta — hanya untuk siswa layak
$nomorpeserta = '---';
if ($islayak && $kus->id > 0) {
    $n = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {kartu_ujian_siswa} WHERE id_kartu_ujian = :kid AND id <= :ksid",
        ['kid' => $kid, 'ksid' => $kus->id]
    );
    $nomorpeserta = str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

// ─── Profil sekolah ───────────────────────────────────────────────────────────
$namasekolah = get_config('local_akademikmonitor', 'namasekolah') ?: 'SMKS PGRI 2 Giri Banyuwangi';
$alamat      = get_config('local_akademikmonitor', 'alamatsekolah') ?: '';
$nss         = get_config('local_akademikmonitor', 'nss') ?: '';
$npsn        = get_config('local_akademikmonitor', 'npsn') ?: '';

$logopath = $CFG->dirroot . '/local/akademikmonitor/pix/logo.jpg';
$logob64  = '';
if (file_exists($logopath)) {
    $logob64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logopath));
}

$fullname  = fullname($userdata);
$namaujian = $ku->nama_ujian;
$tahun     = $kelasdata->tahun_ajaran ?? '-';
$semester  = $ku->semester;
$namakelas = $kelasdata ? $kelasdata->nama_kelas : '-';
$jurusan   = $kelasdata ? $kelasdata->nama_jurusan : '-';
$kurikulum = $kelasdata ? ($kelasdata->nama_kurikulum ?? '-') : '-';
$ttd       = $ku->penandatangan ?: 'Kepala Sekolah';

// Status box berbeda untuk layak vs tidak layak
$statushtml = $islayak
    ? '<div class="status-box"><span class="status-layak">✓ DINYATAKAN LAYAK MENGIKUTI UJIAN</span></div>'
    : '<div class="status-box"><span class="status-tidak-layak">✗ BELUM DINYATAKAN LAYAK — HUBUNGI WALI KELAS</span></div>';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  @page {
    margin: 10mm;
}

* {
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    font-size: 8pt;
    margin: 0;
    padding: 0;
    color: #000;
}

.card {
    width: 170mm;
    height: 95mm;
    padding: 5mm;
    border: 1.5px solid #000;
    overflow: hidden;
    margin: 0 auto;
}

.header {
    display: table;
    width: 100%;
    border-bottom: 2px double #000;
    padding-bottom: 3px;
    margin-bottom: 4px;
}

.header-logo {
    display: table-cell;
    width: 18mm;
    vertical-align: middle;
    text-align: center;
}

.header-logo img {
    width: 13mm;
    height: 13mm;
    object-fit: contain;
}

.header-text {
    display: table-cell;
    vertical-align: middle;
    text-align: center;
}

.sekolah-name {
    font-size: 10pt;
    font-weight: bold;
    text-transform: uppercase;
    line-height: 1.1;
}

.sekolah-sub {
    font-size: 6.5pt;
    line-height: 1.1;
}

.title-box {
    text-align: center;
    background: #1e3a5f;
    color: #fff;
    padding: 3px;
    margin: 4px 0;
    font-size: 9pt;
    font-weight: bold;
    letter-spacing: .8px;
}

.exam-box {
    text-align: center;
    background: #2563eb;
    color: #fff;
    padding: 3px;
    margin: 3px 0;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: .5px;
}

.nama-siswa {
    font-size: 9pt;
    font-weight: bold;
    text-align: center;
    margin: 3px 0;
}

.nomor-box {
    text-align: center;
    border: 1.5px solid #1e3a5f;
    padding: 3px;
    margin: 4px 0;
    border-radius: 3px;
}

.nomor-label {
    font-size: 6.5pt;
    color: #555;
    line-height: 1;
}

.nomor-value {
    font-size: 15pt;
    font-weight: bold;
    color: #1e3a5f;
    letter-spacing: 3px;
    line-height: 1.1;
}

.content-grid {
    display: table;
    width: 100%;
    margin-top: 3px;
}

.info-left {
    display: table-cell;
    width: 62%;
    vertical-align: top;
    padding-right: 4mm;
}

.info-right {
    display: table-cell;
    width: 38%;
    vertical-align: top;
}

table.info {
    width: 100%;
    border-collapse: collapse;
    font-size: 7.3pt;
    line-height: 1.15;
}

table.info td {
    padding: 1.5px 3px;
    vertical-align: top;
}

table.info td:first-child {
    width: 27mm;
    font-weight: bold;
}

table.info td:nth-child(2) {
    width: 3mm;
    text-align: center;
}

.status-box {
    text-align: center;
    margin: 3px 0 5px 0;
}

.status-layak {
    display: inline-block;
    background: #d1fae5;
    color: #065f46;
    border: 1.2px solid #059669;
    padding: 3px 8px;
    border-radius: 3px;
    font-weight: bold;
    font-size: 7pt;
}

.status-tidak-layak {
    display: inline-block;
    background: #fee2e2;
    color: #991b1b;
    border: 1.2px solid #dc2626;
    padding: 3px 7px;
    border-radius: 3px;
    font-weight: bold;
    font-size: 6.5pt;
}

.ttd-section {
    display: table;
    width: 100%;
    margin-top: 5px;
}

.ttd-left,
.ttd-right {
    display: table-cell;
    width: 50%;
    text-align: center;
    font-size: 7pt;
    vertical-align: top;
}

.ttd-space {
    height: 17px;
}

.ttd-name {
    border-top: 1px solid #000;
    padding-top: 2px;
    font-size: 6.7pt;
    font-weight: bold;
    line-height: 1.1;
}

.ttd-role {
    font-size: 6.3pt;
    line-height: 1.1;
}

.footer-note {
    font-size: 6pt;
    color: #666;
    text-align: center;
    margin-top: 4px;
    border-top: 1px solid #ddd;
    padding-top: 2px;
    line-height: 1.1;
}

.watermark {
    position: fixed;
    top: 32%;
    left: 0;
    width: 100%;
    text-align: center;
    font-size: 32pt;
    font-weight: bold;
    color: rgba(220,38,38,0.12);
    transform: rotate(-30deg);
    pointer-events: none;
    z-index: 0;
}
</style>
</head>
<body>
HTML;

if (!$islayak) {
    $html .= '<div class="watermark">BELUM LAYAK</div>';
}

$html .= '<div class="card"><div class="header"><div class="header-logo">';
if ($logob64) {
    $html .= '<img src="' . $logob64 . '" alt="Logo">';
}
$html .= '</div><div class="header-text">';
$html .= '<div class="sekolah-name">' . htmlspecialchars($namasekolah) . '</div>';
$html .= '<div class="sekolah-sub">' . htmlspecialchars($alamat) . '</div>';
if ($nss || $npsn) {
    $html .= '<div class="sekolah-sub">';
    if ($nss)  $html .= 'NSS: ' . htmlspecialchars($nss);
    if ($nss && $npsn) $html .= ' &nbsp;|&nbsp; ';
    if ($npsn) $html .= 'NPSN: ' . htmlspecialchars($npsn);
    $html .= '</div>';
}
$html .= '</div></div>';

$html .= '<div class="title-box">KARTU UJIAN</div>';
$html .= '<div class="title-box" style="background:#2563eb;font-size:11pt;padding:5px;">' . htmlspecialchars($namaujian) . '</div>';
$html .= '<div class="nama-siswa">' . htmlspecialchars($fullname) . '</div>';

$html .= '<div class="nomor-box"><div class="nomor-label">NOMOR PESERTA</div>';
$html .= '<div class="nomor-value">' . htmlspecialchars($nomorpeserta) . '</div></div>';

$html .= '<table class="info">';
$html .= '<tr><td>NIS/NISN</td><td>:</td><td>' . htmlspecialchars($nisnisnlabel) . '</td></tr>';
$html .= '<tr><td>Kelas</td><td>:</td><td>' . htmlspecialchars($namakelas) . '</td></tr>';
$html .= '<tr><td>Jurusan</td><td>:</td><td>' . htmlspecialchars($jurusan) . '</td></tr>';
$html .= '<tr><td>Kurikulum</td><td>:</td><td>' . htmlspecialchars($kurikulum) . '</td></tr>';
$html .= '<tr><td>Tahun Ajaran</td><td>:</td><td>' . htmlspecialchars($tahun) . '</td></tr>';
$html .= '<tr><td>Semester</td><td>:</td><td>' . htmlspecialchars($semester) . '</td></tr>';
$html .= '</table>';

$html .= $statushtml;

$html .= '<div class="ttd-section">';
$html .= '<div class="ttd-left"><div>Siswa,</div><div class="ttd-space"></div>';
$html .= '<div class="ttd-name">' . htmlspecialchars($fullname) . '</div>';
$html .= '<div class="ttd-role">Peserta Ujian</div></div>';
$html .= '<div class="ttd-right"><div>' . htmlspecialchars($ttd) . ',</div><div class="ttd-space"></div>';
$html .= '<div class="ttd-name">__________________________</div>';
$html .= '<div class="ttd-role">' . htmlspecialchars($ttd) . '</div></div>';
$html .= '</div>';

$html .= '<div class="footer-note">Kartu ini wajib dibawa saat pelaksanaan ujian. Dilarang dipinjamkan kepada orang lain.</div>';
$html .= '</div></body></html>';

$dompdf = new Dompdf(['isRemoteEnabled' => true]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'kartu_ujian_' . clean_filename($fullname) . '_' . clean_filename($namaujian) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
