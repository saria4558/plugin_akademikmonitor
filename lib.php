<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Menambahkan menu ke user dropdown (avatar).
 *
 * Konsep plugin:
 * - Pengaturan Notifikasi muncul untuk semua user yang login.
 * - Monitoring Siswa muncul jika user terdaftar sebagai wali kelas
 *   pada tabel kelas plugin.
 *
 * Kenapa tidak pakai user_has_role_assignment($user->id, 9)?
 * Karena ID role bisa berubah ketika pindah Moodle / database baru.
 * Di Moodle lama mungkin role wali kelas ID-nya 9, tetapi di Moodle baru
 * belum tentu sama. Selain itu, pada konsep plugin ini wali kelas ditentukan
 * dari rombel, bukan dari role global Moodle.
 */
function local_akademikmonitor_extend_navigation_user_settings(
    navigation_node $navigation,
    stdClass $user,
    context_user $usercontext,
    stdClass $course,
    context $context
) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $navigation->add(
        'Pengaturan Notifikasi',
        new moodle_url('/local/akademikmonitor/pages/telegram/index.php'),
        navigation_node::TYPE_SETTING,
        null,
        'telegramconnect',
        new pix_icon('i/settings', '')
    );

    if (local_akademikmonitor_is_wali_kelas_user((int)$user->id)) {
        $navigation->add(
            'Monitoring siswa',
            new moodle_url('/local/akademikmonitor/pages/walikelas/dashboard.php'),
            navigation_node::TYPE_SETTING,
            null,
            'walikelasdashboard',
            new pix_icon('i/dashboard', '')
        );
    }
}

/**
 * Mengecek apakah user adalah wali kelas berdasarkan data rombel.
 *
 * Sumber paling valid untuk plugin kamu adalah tabel kelas,
 * karena wali kelas dipilih saat membuat/mengedit rombel.
 *
 * Kalau user ada di kolom kelas.id_user, berarti dia adalah wali kelas
 * untuk minimal satu rombel.
 */
function local_akademikmonitor_is_wali_kelas_user(int $userid): bool {
    global $DB;

    if ($userid <= 0) {
        return false;
    }

    return $DB->record_exists('kelas', ['id_user' => $userid]);
}

/**
 * Menambahkan link Kartu Ujian pada halaman profil user Moodle.
 *
 * Link ini hanya muncul kalau user tersebut punya kartu ujian
 * dan kartu ujiannya sudah dipublish.
 */
function local_akademikmonitor_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
) {
    global $DB, $USER;

    if (empty($user->id) || !isloggedin() || isguestuser()) {
        return;
    }

    $userid = (int)$user->id;
    $currentuserid = (int)($USER->id ?? 0);

    $context = context_user::instance($userid);

    /*
     * Yang boleh melihat:
     * - siswa itu sendiri
     * - admin/manager yang punya izin melihat detail user.
     */
    $canview = ($currentuserid === $userid) || has_capability('moodle/user:viewdetails', $context);

    if (!$canview) {
        return;
    }

    /*
     * Status yang dianggap boleh tampil di profil.
     *
     * "layak" wajib dimasukkan karena dari kasusmu,
     * kartu ujian siswa memakai status layak.
     */
    $allowedstatuses = [
        'published',
        'publish',
        'aktif',
        'terbit',
        'layak',
    ];

    $params = [
        'userid' => $userid,
    ];

    $statusconditions = [];

    /*
     * Cek kolom status di tabel kartu_ujian.
     * Ini untuk status publish secara global di data kartu ujian.
     */
    $kucolumns = $DB->get_columns('kartu_ujian');

    if (isset($kucolumns['status'])) {
        [$kuinsql, $kuparams] = $DB->get_in_or_equal(
            $allowedstatuses,
            SQL_PARAMS_NAMED,
            'profilekustatus'
        );

        $statusconditions[] = "LOWER(ku.status) {$kuinsql}";

        foreach ($kuparams as $key => $value) {
            $params[$key] = strtolower((string)$value);
        }
    }

    /*
     * Cek kolom status di tabel kartu_ujian_siswa.
     * Ini untuk status per siswa, misalnya "layak".
     */
    $kuscolumns = $DB->get_columns('kartu_ujian_siswa');

    if (isset($kuscolumns['status'])) {
        [$kusinsql, $kusparams] = $DB->get_in_or_equal(
            $allowedstatuses,
            SQL_PARAMS_NAMED,
            'profilekusstatus'
        );

        $statusconditions[] = "LOWER(kus.status) {$kusinsql}";

        foreach ($kusparams as $key => $value) {
            $params[$key] = strtolower((string)$value);
        }
    }

    /*
     * Kalau ada kolom status, kartu hanya tampil jika statusnya termasuk daftar allowed.
     * Kalau tidak ada kolom status, kartu tetap tampil selama relasi user ada di kartu_ujian_siswa.
     */
    $statussql = '';

    if (!empty($statusconditions)) {
        $statussql = ' AND (' . implode(' OR ', $statusconditions) . ')';
    }

    $sql = "SELECT kus.id
              FROM {kartu_ujian_siswa} kus
              JOIN {kartu_ujian} ku ON ku.id = kus.id_kartu_ujian
             WHERE kus.id_user = :userid
                   {$statussql}
          ORDER BY ku.id DESC";

    $haspublishedcard = $DB->record_exists_sql($sql, $params);

    if (!$haspublishedcard) {
        return;
    }

    /*
     * Tambahkan kategori khusus di halaman profil.
     */
    $category = new \core_user\output\myprofile\category(
        'akademikmonitor',
        'Akademik & Monitoring',
        null
    );

    $tree->add_category($category);

    /*
     * Tambahkan node/link Kartu Ujian.
     */
    $url = new moodle_url('/local/akademikmonitor/pages/kartu_ujian/profil_siswa.php', [
        'userid' => $userid,
    ]);

    $node = new \core_user\output\myprofile\node(
        'akademikmonitor',
        'kartuujian',
        'Kartu Ujian',
        null,
        $url
    );

    $tree->add_node($node);
}