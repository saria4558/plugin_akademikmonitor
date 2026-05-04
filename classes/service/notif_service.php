<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class notif_service {

    public static function get_setting(): \stdClass {
        global $DB;

        $recs = $DB->get_records('setting_telegram', null, 'id ASC', '*', 0, 1);
        $rec = $recs ? reset($recs) : null;

        return $rec ?: (object)[
            'id' => 0,
            'bot_token' => '',
            'bot_username' => '',
            'is_enabled' => 0,
            'token_verified_at' => '',
            'timecreated' => 0,
            'timemodified' => 0,
        ];
    }

    public static function save_setting(string $token, string $username, int $enabled, string $verifiedat): void {
        global $DB;

        $token = trim($token);
        $username = trim($username);
        $now = time();

        $rec = self::get_setting();

        if (!empty($rec->id)) {
            $rec->bot_token = $token;
            $rec->bot_username = $username;
            $rec->is_enabled = $enabled ? 1 : 0;
            $rec->token_verified_at = $verifiedat;
            $rec->timemodified = $now;

            $DB->update_record('setting_telegram', $rec);
            return;
        }

        $DB->insert_record('setting_telegram', (object)[
            'bot_token' => $token,
            'bot_username' => $username,
            'is_enabled' => $enabled ? 1 : 0,
            'token_verified_at' => $verifiedat,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function list_rules(): array {
        global $DB;

        $rows = $DB->get_records('notif_rule', null, 'id ASC');

        $out = [];

        foreach ($rows as $r) {
            $label = (string)$r->rule_kode;

            if ($r->rule_kode === 'pengingat_tugas') {
                $label = 'Pengingat Tugas';
            } else if ($r->rule_kode === 'nilai_kktp') {
                $label = 'Pengingat Event Nilai < KKTP';
            } else if ($r->rule_kode === 'pengingat_event') {
                $label = 'Pengingat Event';
            }

            $isactive = !empty($r->is_enabled);

            $out[] = [
                'id' => (int)$r->id,
                'rule_kode' => (string)$r->rule_kode,
                'label' => $label,
                'send_time' => $r->send_time ?: '07:00:00',
                'offset_days' => $r->offset_days ?: '1',
                'event_keyword' => $r->event_keyword ?: '',
                'recipients' => $r->recipients ?: '',
                'is_enabled' => $isactive,
                'badge_class' => $isactive ? 'on' : 'off',
                'badge_text' => $isactive ? 'aktif' : 'nonaktif',
                'toggle_text' => $isactive ? 'nonaktif' : 'aktif',
            ];
        }

        return $out;
    }

    public static function update_rule(
        int $id,
        string $offsetdays,
        string $sendtime,
        string $eventkeyword,
        string $recipients
    ): void {
        global $DB;

        $rec = $DB->get_record('notif_rule', ['id' => $id], '*', MUST_EXIST);

        $rec->offset_days = trim($offsetdays);
        $rec->send_time = trim($sendtime);
        $rec->event_keyword = trim($eventkeyword);
        $rec->recipients = trim($recipients);
        $rec->timemodified = time();

        $DB->update_record('notif_rule', $rec);
    }

    public static function toggle_rule(int $id): int {
        global $DB;

        $current = (int)$DB->get_field('notif_rule', 'is_enabled', ['id' => $id], MUST_EXIST);
        $new = $current ? 0 : 1;

        $DB->set_field('notif_rule', 'is_enabled', $new, ['id' => $id]);
        $DB->set_field('notif_rule', 'timemodified', time(), ['id' => $id]);

        return $new;
    }

    public static function check_telegram_token(string $token): array {
        global $CFG;

        $token = trim($token);

        if ($token === '') {
            return [
                'ok' => false,
                'username' => '',
                'message' => 'Token bot tidak boleh kosong.',
            ];
        }

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $url = 'https://api.telegram.org/bot' . $token . '/getMe';

        $resp = $curl->get($url);

        if (!$resp) {
            return [
                'ok' => false,
                'username' => '',
                'message' => 'Tidak ada respon dari Telegram. Cek koneksi internet server / laptop.',
            ];
        }

        $json = json_decode($resp, true);

        if (!is_array($json) || empty($json['ok'])) {
            return [
                'ok' => false,
                'username' => '',
                'message' => $json['description'] ?? 'Token tidak valid / response Telegram tidak sesuai.',
            ];
        }

        return [
            'ok' => true,
            'username' => $json['result']['username'] ?? '',
            'message' => 'Koneksi Telegram berhasil.',
        ];
    }

    public static function get_user_link(int $userid): ?\stdClass {
        global $DB;

        $rec = $DB->get_record('telegram_user_link', ['moodle_userid' => $userid]);

        return $rec ?: null;
    }

    public static function save_user_link(int $userid, string $chatid, string $username = ''): \stdClass {
        global $DB;

        $userid = (int)$userid;
        $chatid = trim($chatid);
        $username = trim($username);
        $now = time();

        $existing = self::get_user_link($userid);

        if ($existing) {
            $existing->telegram_chat_id = $chatid;
            $existing->telegram_username = $username;
            $existing->is_linked = '1';
            $existing->linked_at = date('Y-m-d H:i:s', $now);
            $existing->timemodified = $now;

            $DB->update_record('telegram_user_link', $existing);

            return $DB->get_record('telegram_user_link', ['id' => $existing->id], '*', MUST_EXIST);
        }

        $id = $DB->insert_record('telegram_user_link', (object)[
            'moodle_userid' => $userid,
            'telegram_chat_id' => $chatid,
            'telegram_username' => $username,
            'is_linked' => '1',
            'linked_at' => date('Y-m-d H:i:s', $now),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return $DB->get_record('telegram_user_link', ['id' => $id], '*', MUST_EXIST);
    }

    public static function is_user_connected(int $userid): bool {
        $link = self::get_user_link($userid);

        if (!$link) {
            return false;
        }

        return !empty($link->telegram_chat_id) && (string)$link->is_linked === '1';
    }

    public static function build_telegram_connect_url(int $userid): string {
        $setting = self::get_setting();
        $username = trim((string)($setting->bot_username ?? ''));

        if ($username === '') {
            return '';
        }

        return 'https://t.me/' . $username . '?start=' . $userid;
    }

    public static function send_telegram(string $chatid, string $message): array {
        global $CFG;

        $setting = self::get_setting();

        if (empty($setting->bot_token)) {
            return [
                'ok' => false,
                'message' => 'Bot token belum disimpan.',
                'raw' => null,
            ];
        }

        if ((int)$setting->is_enabled !== 1) {
            return [
                'ok' => false,
                'message' => 'Bot Telegram belum diaktifkan.',
                'raw' => null,
            ];
        }

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $url = 'https://api.telegram.org/bot' . $setting->bot_token . '/sendMessage';

        $params = [
            'chat_id' => $chatid,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];

        $resp = $curl->post($url, $params);

        if (!$resp) {
            return [
                'ok' => false,
                'message' => 'Gagal mengirim pesan ke Telegram.',
                'raw' => null,
            ];
        }

        $json = json_decode($resp, true);

        if (!is_array($json) || empty($json['ok'])) {
            return [
                'ok' => false,
                'message' => $json['description'] ?? 'Response Telegram tidak valid.',
                'raw' => $json,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Pesan berhasil dikirim.',
            'raw' => $json,
        ];
    }

   public static function has_log_been_sent(
    int $userid,
    string $rulecode,
    int $assignid,
    int $eventid,
    string $scheduledat
): bool {
    global $DB;

    $params = [
        'moodle_userid' => $userid,
        'rule_code' => $rulecode,
        'scheduled_at' => $scheduledat,
        'status' => 'sent',
    ];

    if ($assignid > 0) {
        $params['assignid'] = $assignid;
    }

    if ($eventid > 0) {
        $params['eventid'] = $eventid;
    }

    return $DB->record_exists('log_pengiriman_pesan', $params);
}

protected static function cut_text(string $text, int $maxlength = 255): string {
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxlength, 'UTF-8');
    }

    return substr($text, 0, $maxlength);
}

public static function save_delivery_log(
    int $userid,
    int $courseid,
    string $rulecode,
    int $assignid,
    int $eventid,
    string $contexttitle,
    string $scheduledat,
    string $chatid,
    string $messagepreview,
    string $status,
    string $errormessage = ''
): void {
    global $DB;

    $now = time();

    $record = (object)[
        'moodle_userid' => $userid,
        'courseid' => $courseid > 0 ? $courseid : null,
        'rule_code' => $rulecode,
        'assignid' => $assignid > 0 ? $assignid : null,
        'eventid' => $eventid > 0 ? $eventid : null,
        'context_title' => self::cut_text($contexttitle, 255),
        'scheduled_at' => $scheduledat,
        'sent_at' => date('Y-m-d H:i:s', $now),
        'status' => self::cut_text($status, 50),
        'telegram_chat_id' => self::cut_text($chatid, 100),
        'message_preview' => self::cut_text(strip_tags($messagepreview), 255),
        'error_message' => self::cut_text($errormessage, 255),
        'timecreated' => $now,
        'timemodified' => $now,
    ];

    $DB->insert_record('log_pengiriman_pesan', $record);
}
}