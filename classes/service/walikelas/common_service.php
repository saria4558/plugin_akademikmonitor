<?php
namespace local_akademikmonitor\service\walikelas;

defined('MOODLE_INTERNAL') || die();

class common_service {

    private static function get_student_roleid(): int {
        global $DB;
        return (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
    }


    public static function get_generated_kelasid_from_courseid(int $courseid): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }

        $idnumber = (string)$DB->get_field('course', 'idnumber', ['id' => $courseid], IGNORE_MISSING);
        if ($idnumber === '') {
            return 0;
        }

        if (preg_match('/^AM-K(\d+)-KM(\d+)-S([12])$/', trim($idnumber), $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    public static function get_generated_kelasid_from_group(int $groupid): int {
        global $DB;

        if ($groupid <= 0) {
            return 0;
        }

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, courseid', IGNORE_MISSING);
        if (!$group || empty($group->courseid)) {
            return 0;
        }

        return self::get_generated_kelasid_from_courseid((int)$group->courseid);
    }

    public static function get_group_walikelas(int $userid): array {
        global $DB;

        $memberships = $DB->get_records('groups_members', ['userid' => $userid], 'groupid ASC', 'groupid');
        if (!$memberships) {
            return [];
        }

        $groupids = array_map('intval', array_keys($memberships));
        $groups = $DB->get_records_list('groups', 'id', $groupids, 'id ASC', 'id, name, courseid');
        if (!$groups) {
            return [];
        }

        $bykelas = [];
        foreach ($groups as $g) {
            $name = (string)$g->name;
            $courseid = (int)($g->courseid ?? 0);
            $generatedkelasid = self::get_generated_kelasid_from_courseid($courseid);

            // Course hasil generate punya idnumber AM-K{idkelas}-KM{idmapel}-S{semester}.
            // Jadi dedupe jangan hanya berdasarkan nama group, karena setiap mapel membuat group dengan nama sama.
            $key = $generatedkelasid > 0 ? ('kelas:' . $generatedkelasid) : ('name:' . $name);

            if (!isset($bykelas[$key])) {
                $bykelas[$key] = (object)[
                    'id' => (int)$g->id,
                    'name' => $name,
                    'courseid' => $courseid,
                    'generatedkelasid' => $generatedkelasid,
                ];
                continue;
            }

            $current = $bykelas[$key];
            $currentisgenerated = !empty($current->generatedkelasid);
            $newisgenerated = $generatedkelasid > 0;

            // Kalau ada pilihan antara group manual dan group hasil generate, utamakan group hasil generate.
            if ((!$currentisgenerated && $newisgenerated) ||
                    ($currentisgenerated === $newisgenerated && (int)$g->id < (int)$current->id)) {
                $bykelas[$key] = (object)[
                    'id' => (int)$g->id,
                    'name' => $name,
                    'courseid' => $courseid,
                    'generatedkelasid' => $generatedkelasid,
                ];
            }
        }

        $out = [];
        foreach ($bykelas as $g) {
            $out[$g->id] = $g;
        }

        ksort($out);
        return $out;
    }

    public static function get_first_group_walikelas(int $userid): ?\stdClass {
        $groups = self::get_group_walikelas($userid);
        if (!$groups) {
            return null;
        }
        return reset($groups) ?: null;
    }

    public static function get_siswa_group(int $groupid, int $waliuserid = 0): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, courseid', MUST_EXIST);
        $courseid = (int)($group->courseid ?? 0);

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $fields = 'u.id, u.idnumber, u.username, u.email, u.deleted, u.suspended, ' . $namefields;

        $members = groups_get_members($groupid, $fields, 'u.firstname ASC');
        if (!$members) {
            return [];
        }

        foreach ($members as $uid => $u) {
            if (($waliuserid > 0 && (int)$u->id === (int)$waliuserid) || !empty($u->deleted) || !empty($u->suspended)) {
                unset($members[$uid]);
            }
        }

        if (!$members) {
            return [];
        }

        // Simpan backup hasil member group setelah buang wali/deleted/suspended.
        $fallbackmembers = $members;

        $studentroleid = self::get_student_roleid();
        if ($studentroleid <= 0 || $courseid <= 0) {
            return $fallbackmembers;
        }

        $contextid = (int)\context_course::instance($courseid)->id;
        $userids = array_map('intval', array_keys($members));

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['roleid'] = $studentroleid;
        $params['ctxid'] = $contextid;

        $studentids = $DB->get_fieldset_select(
            'role_assignments',
            'userid',
            "roleid = :roleid AND contextid = :ctxid AND userid $insql",
            $params
        );

        // Kalau role assignment student tidak ketemu sama sekali,
        // jangan kosongkan semua siswa. Pakai fallback group members.
        if (empty($studentids)) {
            return $fallbackmembers;
        }

        $studentset = array_flip(array_map('intval', $studentids));

        foreach ($members as $uid => $u) {
            if (!isset($studentset[(int)$u->id])) {
                unset($members[$uid]);
            }
        }

        // Kalau setelah filter hasilnya kosong, pakai fallback juga.
        if (empty($members)) {
            return $fallbackmembers;
        }

        return $members;
    }

    public static function get_sidebar_data(string $active = ''): array {
        return [
            'is_dashboard' => ($active === 'dashboard'),
            'is_monitoring_kelas' => ($active === 'monitoring'),
            'is_ekskul_siswa' => ($active === 'ekskul'),
            'is_pkl_siswa' => ($active === 'pkl'),
            'is_raport' => ($active === 'rapor'),

            'dashboard_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/dashboard.php'))->out(false),
            'monitoring_kelas_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php'))->out(false),
            'ekskul_siswa_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php'))->out(false),
            'pkl_siswa_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php'))->out(false),
            'raport_url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/rapor/index.php'))->out(false),
        ];
    }

    public static function get_nisn_map_by_userids(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }

        $field = $DB->get_record('user_info_field', ['shortname' => 'nisn'], 'id', IGNORE_MISSING);
        if (!$field) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['fieldid'] = (int)$field->id;

        $records = $DB->get_records_select(
            'user_info_data',
            "fieldid = :fieldid AND userid $insql",
            $params,
            '',
            'userid, data'
        );

        $map = [];
        foreach ($records as $r) {
            $map[(int)$r->userid] = (string)$r->data;
        }

        return $map;
    }
}