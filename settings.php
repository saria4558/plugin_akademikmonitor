<?php
defined('MOODLE_INTERNAL') || die();

/** @var admin_root $ADMIN */
/** @var bool $hassiteconfig */

if ($hassiteconfig) {

    // Menu utama plugin.
    // Pakai moodle/site:config supaya pasti muncul untuk admin site.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_akademikmonitor',
        'Akademik & Monitoring',
        new moodle_url('/local/akademikmonitor/pages/index.php'),
        'moodle/site:config'
    ));

    // Halaman pengaturan plugin.
    $settings = new admin_settingpage(
        'local_akademikmonitor_settings',
        'Pengaturan Akademik & Monitoring',
        'moodle/site:config'
    );

    // Identitas sekolah.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/school_identity_heading',
        'Identitas Sekolah',
        'Pengaturan ini dipakai untuk rapor, export PDF, dan tampilan identitas sekolah.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/namasekolah',
        'Nama sekolah',
        'Contoh: SMKS PGRI 2 Giri Banyuwangi',
        'SMKS PGRI 2 Giri Banyuwangi',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_akademikmonitor/alamatsekolah',
        'Alamat sekolah',
        'Alamat sekolah untuk ditampilkan di rapor/PDF.',
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/kotattd',
        'Kota penandatanganan',
        'Contoh: Banyuwangi',
        'Banyuwangi',
        PARAM_TEXT
    ));

    // Kepala sekolah.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/headmaster_heading',
        'Kepala Sekolah',
        'Data kepala sekolah untuk kebutuhan tanda tangan dokumen.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/namakepalasekolah',
        'Nama kepala sekolah',
        'Contoh: Wahyudi, ST',
        'Wahyudi, ST',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/npakepalasekolah',
        'NPA kepala sekolah',
        'Contoh: 1333.1.800.166',
        '1333.1.800.166',
        PARAM_TEXT
    ));

    // Default rapor.
    $settings->add(new admin_setting_heading(
        'local_akademikmonitor/rapor_heading',
        'Default Rapor',
        'Dipakai jika data dinamis belum tersedia.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/semesterdefault',
        'Semester default',
        'Contoh: Ganjil atau Genap',
        'Ganjil',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/tahunpelajarandefault',
        'Tahun pelajaran default',
        'Contoh: 2025/2026',
        '2025/2026',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_akademikmonitor/tahuncoverdefault',
        'Tahun cover default',
        'Contoh: 2025',
        date('Y'),
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}