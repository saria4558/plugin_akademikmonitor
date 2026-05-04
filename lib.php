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