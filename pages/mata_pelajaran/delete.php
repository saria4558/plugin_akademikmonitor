<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

global $DB;

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$id = required_param('id', PARAM_INT);

// Pastikan data ada.
$record = $DB->get_record('mata_pelajaran', ['id' => $id], '*', MUST_EXIST);

/**
 * Cek apakah mata pelajaran masih dipakai di tabel kurikulum_mapel.
 *
 * Di install.xml plugin kamu, tabel kurikulum_mapel punya field id_mapel
 * yang mengarah ke mata_pelajaran.id.
 * Kalau masih dipakai, jangan langsung dihapus supaya relasi data tidak rusak.
 */
if ($DB->get_manager()->table_exists('kurikulum_mapel')) {
    $used = $DB->record_exists('kurikulum_mapel', [
        'id_mapel' => $id,
    ]);

    if ($used) {
        redirect(
            new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
            'Mata pelajaran tidak dapat dihapus karena masih digunakan pada data kurikulum.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$DB->delete_records('mata_pelajaran', [
    'id' => $record->id,
]);

redirect(
    new moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'),
    'Data mata pelajaran berhasil dihapus.',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);