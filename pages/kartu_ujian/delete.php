<?php
require_once(__DIR__ . '/../../../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
require_sesskey();
global $DB;
$id = required_param('id', PARAM_INT);
$DB->delete_records('kartu_ujian_siswa', ['id_kartu_ujian' => $id]);
$DB->delete_records('kartu_ujian', ['id' => $id]);
redirect(new moodle_url('/local/akademikmonitor/pages/kartu_ujian/index.php'), 'Kartu ujian berhasil dihapus.', null, \core\output\notification::NOTIFY_SUCCESS);
