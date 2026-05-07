<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class admin_presensi_service {

    /**
     * Mengecek apakah plugin Attendance sudah punya tabel yang dibutuhkan.
     *
     * Kenapa perlu?
     * Supaya kalau plugin Attendance belum terpasang, halaman admin tidak error SQL.
     */
    private static function attendance_tables_available(): bool {
        global $DB;

        $dbman = $DB->get_manager();

        return $dbman->table_exists(new \xmldb_table('attendance'))
            && $dbman->table_exists(new \xmldb_table('attendance_sessions'))
            && $dbman->table_exists(new \xmldb_table('attendance_log'))
            && $dbman->table_exists(new \xmldb_table('attendance_statuses'));
    }

    /**
     * Ambil label tahun ajaran secara fleksibel.
     */
    private static function tahun_label(?\stdClass $tahun): string {
        if (!$tahun) {
            return '-';
        }

        foreach (['tahun_ajaran', 'nama', 'label', 'periode'] as $field) {
            if (property_exists($tahun, $field) && trim((string)$tahun->{$field}) !== '') {
                return trim((string)$tahun->{$field});
            }
        }

        return '-';
    }

    /**
     * Data sidebar admin.
     *
     * Kenapa dibuat di service?
     * Supaya page index.php bersih dan tidak penuh data menu.
     */
    public static function get_admin_sidebar_data(string $active = ''): array {
        return [
            'is_dashboard' => $active === 'dashboard',
            'is_tahun_ajaran' => $active === 'tahun_ajaran',
            'is_kurikulum' => $active === 'kurikulum',
            'is_manajemen_jurusan' => $active === 'jurusan',
            'is_manajemen_kelas' => $active === 'kelas',
            'is_mata_pelajaran' => $active === 'mata_pelajaran',
            'is_matpel' => $active === 'mata_pelajaran',
            'is_kktp' => $active === 'kktp',
            'is_notif' => $active === 'notif',
            'is_ekskul' => $active === 'ekskul',
            'is_mitra' => $active === 'mitra',
            'is_kartu_ujian' => $active === 'kartu_ujian',
            'is_monitoring_presensi' => $active === 'monitoring_presensi',

            'dashboard_url' => (new \moodle_url('/local/akademikmonitor/pages/index.php'))->out(false),
            'tahun_ajaran_url' => (new \moodle_url('/local/akademikmonitor/pages/tahun_ajaran/index.php'))->out(false),
            'kurikulum_url' => (new \moodle_url('/local/akademikmonitor/pages/kurikulum/index.php'))->out(false),
            'manajemen_jurusan_url' => (new \moodle_url('/local/akademikmonitor/pages/jurusan/index.php'))->out(false),
            'manajemen_kelas_url' => (new \moodle_url('/local/akademikmonitor/pages/kelas/index.php'))->out(false),
            'mata_pelajaran_url' => (new \moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
            'matpel_url' => (new \moodle_url('/local/akademikmonitor/pages/mata_pelajaran/index.php'))->out(false),
            'kktp_url' => (new \moodle_url('/local/akademikmonitor/pages/kktp/index.php'))->out(false),
            'notif_url' => (new \moodle_url('/local/akademikmonitor/pages/notif/index.php'))->out(false),
            'ekskul_url' => (new \moodle_url('/local/akademikmonitor/pages/ekskul/index.php'))->out(false),
            'mitra_url' => (new \moodle_url('/local/akademikmonitor/pages/mitra/index.php'))->out(false),
            'monitoring_presensi_url' => (new \moodle_url('/local/akademikmonitor/pages/presensi/index.php'))->out(false),
        ];
    }

    /**
     * Ambil pilihan tahun ajaran.
     */
    private static function get_tahun_options(int $selectedid): array {
        global $DB;

        $records = $DB->get_records('tahun_ajaran', null, 'id DESC');

        $out = [];

        foreach ($records as $tahun) {
            $out[] = [
                'id' => (int)$tahun->id,
                'nama' => format_string(self::tahun_label($tahun)),
                'selected' => (int)$tahun->id === (int)$selectedid,
            ];
        }

        return $out;
    }

    /**
     * Ambil daftar kelas berdasarkan tahun ajaran.
     *
     * Kenapa berdasarkan tahun ajaran?
     * Karena admin bisa punya banyak kelas dari tahun ajaran lama.
     * Kalau tidak difilter, presensi tahun lain bisa ikut muncul.
     */
    private static function get_kelas_options(int $tahunajaranid, int $selectedkelasid): array {
        global $DB;

        if ($tahunajaranid <= 0) {
            return [];
        }

        $sql = "SELECT k.id,
                       k.nama,
                       k.tingkat,
                       k.id_jurusan,
                       k.id_tahun_ajaran,
                       j.nama_jurusan,
                       j.kode_jurusan
                  FROM {kelas} k
                  JOIN {jurusan} j ON j.id = k.id_jurusan
                 WHERE k.id_tahun_ajaran = :tahunajaranid
              ORDER BY k.tingkat ASC, j.nama_jurusan ASC, k.nama ASC";

        $records = $DB->get_records_sql($sql, [
            'tahunajaranid' => $tahunajaranid,
        ]);

        $out = [];

        foreach ($records as $kelas) {
            $label = course_name_service::rombel_label($kelas, (object)[
                'nama_jurusan' => $kelas->nama_jurusan,
            ]);

            $out[] = [
                'id' => (int)$kelas->id,
                'nama' => format_string($label),
                'selected' => (int)$kelas->id === (int)$selectedkelasid,
            ];
        }

        return $out;
    }

    /**
     * Ambil record kelas lengkap.
     */
    private static function get_kelas_record(int $kelasid): ?\stdClass {
        global $DB;

        if ($kelasid <= 0) {
            return null;
        }

        $sql = "SELECT k.id,
                       k.nama,
                       k.tingkat,
                       k.id_jurusan,
                       k.id_tahun_ajaran,
                       j.nama_jurusan,
                       j.kode_jurusan
                  FROM {kelas} k
                  JOIN {jurusan} j ON j.id = k.id_jurusan
                 WHERE k.id = :kelasid";

        $record = $DB->get_record_sql($sql, [
            'kelasid' => $kelasid,
        ], IGNORE_MISSING);

        return $record ?: null;
    }

/**
 * Ambil course/mapel hasil generate berdasarkan kelas, tahun ajaran, dan semester.
 *
 * Kenapa pakai idnumber?
 * Karena course hasil generate plugin kamu punya pola idnumber:
 * - Format baru: AM-TA{tahunajaranid}-K{kelasid}-KM{kurikulummapelid}-S{semester}
 * - Format lama: AM-K{kelasid}-KM{kurikulummapelid}-S{semester}
 *
 * Kenapa tidak pakai c.idnumber?
 * Karena function ini memakai $DB->get_records_select('course', ...).
 * Query bawaan Moodle untuk get_records_select tidak memberi alias "c" ke tabel course.
 * Jadi kalau ditulis c.idnumber, database akan error Unknown column 'c.idnumber'.
 */
private static function get_course_options(int $kelasid, int $tahunajaranid, int $semester, int $selectedcourseid): array {
    global $DB;

    if ($kelasid <= 0) {
        return [];
    }

    $conditions = [];
    $params = [];

    /*
     * Format lama:
     * AM-K1-KM9-S1
     */
    $oldpattern = 'AM-K' . $kelasid . '-KM%-S%';

    if (in_array($semester, [1, 2], true)) {
        $oldpattern = 'AM-K' . $kelasid . '-KM%-S' . $semester;
    }

    $conditions[] = $DB->sql_like('idnumber', ':oldpattern', false);
    $params['oldpattern'] = $oldpattern;

    /*
     * Format baru:
     * AM-TA2-K1-KM9-S1
     */
    if ($tahunajaranid > 0) {
        $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S%';

        if (in_array($semester, [1, 2], true)) {
            $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S' . $semester;
        }
    } else {
        $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S%';

        if (in_array($semester, [1, 2], true)) {
            $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S' . $semester;
        }
    }

    $conditions[] = $DB->sql_like('idnumber', ':newpattern', false);
    $params['newpattern'] = $newpattern;

    $courses = $DB->get_records_select(
        'course',
        '(' . implode(' OR ', $conditions) . ')',
        $params,
        'fullname ASC, id ASC',
        'id, fullname, shortname, idnumber'
    );

    if (!$courses) {
        return [];
    }

    $courseids = array_map('intval', array_keys($courses));

    [$courseinsql, $courseparams] = $DB->get_in_or_equal(
        $courseids,
        SQL_PARAMS_NAMED,
        'courseid'
    );

    $coursemapels = $DB->get_records_select(
        'course_mapel',
        "id_course {$courseinsql}",
        $courseparams,
        '',
        'id_course, id_kurikulum_mapel'
    );

    $kmids = [];
    $kmidbycourse = [];

    foreach ($coursemapels as $cm) {
        $courseid = (int)($cm->id_course ?? 0);
        $kmid = (int)($cm->id_kurikulum_mapel ?? 0);

        if ($courseid <= 0 || $kmid <= 0) {
            continue;
        }

        $kmidbycourse[$courseid] = $kmid;
        $kmids[$kmid] = $kmid;
    }

    $kurikulummapels = [];
    $mapels = [];

    if ($kmids) {
        $kurikulummapels = $DB->get_records_list(
            'kurikulum_mapel',
            'id',
            array_values($kmids),
            '',
            'id, id_mapel, kktp, tingkat_kelas'
        );

        $mapelids = [];

        foreach ($kurikulummapels as $km) {
            $mapelid = (int)($km->id_mapel ?? 0);

            if ($mapelid > 0) {
                $mapelids[$mapelid] = $mapelid;
            }
        }

        if ($mapelids) {
            $mapels = $DB->get_records_list(
                'mata_pelajaran',
                'id',
                array_values($mapelids),
                '',
                'id, nama_mapel'
            );
        }
    }

    $out = [];

    foreach ($courses as $course) {
        $courseid = (int)$course->id;
        $kmid = $kmidbycourse[$courseid] ?? 0;
        $km = ($kmid > 0 && isset($kurikulummapels[$kmid])) ? $kurikulummapels[$kmid] : null;

        $mapelname = '';

        if ($km) {
            $mapelid = (int)($km->id_mapel ?? 0);

            if ($mapelid > 0 && isset($mapels[$mapelid])) {
                $mapelname = (string)$mapels[$mapelid]->nama_mapel;
            }
        }

        if ($mapelname === '') {
            $mapelname = (string)$course->fullname;
        }

        $out[] = [
            'id' => $courseid,
            'nama_mapel' => format_string($mapelname),
            'fullname' => format_string((string)$course->fullname),
            'idnumber' => (string)$course->idnumber,
            'selected' => $courseid === (int)$selectedcourseid,
        ];
    }

    usort($out, static function($a, $b) {
        return strcasecmp((string)$a['nama_mapel'], (string)$b['nama_mapel']);
    });

    return array_values($out);
}

    /**
     * Cari group kelas di course yang dipilih.
     *
     * Kenapa butuh groupid?
     * Di Attendance, sesi bisa:
     * - groupid = 0 untuk semua siswa
     * - groupid = id group tertentu
     *
     * Karena admin memilih kelas, maka session yang diambil harus session umum
     * atau session milik group kelas itu.
     */
    private static function get_groupid_for_course_kelas(int $courseid, \stdClass $kelas): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }

        $groupname = course_name_service::rombel_label($kelas, (object)[
            'nama_jurusan' => $kelas->nama_jurusan,
        ]);

        $group = $DB->get_record(
            'groups',
            [
                'courseid' => $courseid,
                'name' => $groupname,
            ],
            'id',
            IGNORE_MISSING
        );

        if ($group) {
            return (int)$group->id;
        }

        $groups = $DB->get_records(
            'groups',
            ['courseid' => $courseid],
            'id ASC',
            'id, name'
        );

        if (!$groups) {
            return 0;
        }

        $first = reset($groups);

        return $first ? (int)$first->id : 0;
    }

    /**
     * Ambil siswa dari group course.
     *
     * Kalau group tidak ada, fallback ke tabel peserta_kelas.
     */
    private static function get_students(int $kelasid, int $courseid, int $groupid): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $fields = 'u.id, u.idnumber, u.username, u.email, u.deleted, u.suspended, ' . $namefields;

        $students = [];

        if ($groupid > 0) {
            $members = groups_get_members($groupid, $fields, 'u.firstname ASC, u.lastname ASC');

            if ($members) {
                foreach ($members as $uid => $user) {
                    if (!empty($user->deleted) || !empty($user->suspended)) {
                        continue;
                    }

                    $students[(int)$uid] = $user;
                }
            }
        }

        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);

        if ($students && $studentroleid > 0 && $courseid > 0) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);

            if ($context) {
                $userids = array_map('intval', array_keys($students));

                [$insql, $params] = $DB->get_in_or_equal(
                    $userids,
                    SQL_PARAMS_NAMED,
                    'uid'
                );

                $params['roleid'] = $studentroleid;
                $params['contextid'] = (int)$context->id;

                $studentids = $DB->get_fieldset_select(
                    'role_assignments',
                    'userid',
                    "roleid = :roleid
                     AND contextid = :contextid
                     AND userid {$insql}",
                    $params
                );

                if ($studentids) {
                    $studentset = array_flip(array_map('intval', $studentids));

                    foreach ($students as $uid => $user) {
                        if (!isset($studentset[(int)$uid])) {
                            unset($students[$uid]);
                        }
                    }
                }
            }
        }

        if ($students) {
            return $students;
        }

        if ($kelasid <= 0) {
            return [];
        }

        $params = ['kelasid' => $kelasid];

        $rolesql = '';

        if ($studentroleid > 0) {
            $rolesql = " AND (pk.id_role = :studentroleid OR pk.id_role IS NULL OR pk.id_role = 0)";
            $params['studentroleid'] = $studentroleid;
        }

        $sql = "SELECT u.id,
                       u.idnumber,
                       u.username,
                       u.email,
                       u.deleted,
                       u.suspended,
                       {$namefields}
                  FROM {peserta_kelas} pk
                  JOIN {user} u ON u.id = pk.id_user
                 WHERE pk.id_kelas = :kelasid
                   {$rolesql}
                   AND u.deleted = 0
                   AND u.suspended = 0
              ORDER BY u.firstname ASC, u.lastname ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Ambil semua instance Attendance di course.
     */
    private static function get_attendance_instances(int $courseid): array {
        global $DB;

        if ($courseid <= 0 || !self::attendance_tables_available()) {
            return [];
        }

        return $DB->get_records(
            'attendance',
            ['course' => $courseid],
            'id ASC',
            'id, course, name'
        );
    }

    /**
     * Ambil semua sesi presensi dari course terpilih.
     */
    private static function get_attendance_sessions(int $courseid, int $groupid): array {
        global $DB;

        $instances = self::get_attendance_instances($courseid);

        if (!$instances) {
            return [];
        }

        $attendanceids = array_map('intval', array_keys($instances));

        [$attinsql, $attparams] = $DB->get_in_or_equal(
            $attendanceids,
            SQL_PARAMS_NAMED,
            'attid'
        );

        $params = $attparams;

        $groupsql = '';

        if ($groupid > 0) {
            $groupsql = " AND (groupid = 0 OR groupid = :groupid)";
            $params['groupid'] = $groupid;
        }

        $sessions = $DB->get_records_select(
            'attendance_sessions',
            "attendanceid {$attinsql} {$groupsql}",
            $params,
            'sessdate ASC, id ASC',
            'id, attendanceid, groupid, sessdate, duration, description, lasttaken, statusset'
        );

        if (!$sessions) {
            return [];
        }

        $number = 1;
        $out = [];

        foreach ($sessions as $session) {
            $description = trim(strip_tags((string)($session->description ?? '')));

            if ($description === '') {
                $description = 'Pertemuan ' . $number;
            }

            $date = !empty($session->sessdate)
                ? userdate((int)$session->sessdate, '%d %B %Y')
                : '-';

            $out[(int)$session->id] = (object)[
                'id' => (int)$session->id,
                'attendanceid' => (int)$session->attendanceid,
                'groupid' => (int)$session->groupid,
                'sessdate' => (int)$session->sessdate,
                'description' => $description,
                'date_label' => $date,
                'column_label' => $description . '<br><small>' . $date . '</small>',
                'number' => $number,
            ];

            $number++;
        }

        return $out;
    }

    /**
     * Ambil log presensi siswa.
     */
    private static function get_logs_by_sessions_and_students(array $sessionids, array $userids): array {
        global $DB;

        $sessionids = array_values(array_unique(array_map('intval', $sessionids)));
        $userids = array_values(array_unique(array_map('intval', $userids)));

        if (!$sessionids || !$userids) {
            return [];
        }

        [$sessionsql, $sessionparams] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'sid'
        );

        [$usersql, $userparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'uid'
        );

        $params = array_merge($sessionparams, $userparams);

        $logs = $DB->get_records_select(
            'attendance_log',
            "sessionid {$sessionsql}
             AND studentid {$usersql}",
            $params,
            'id ASC',
            'id, sessionid, studentid, statusid, remarks'
        );

        if (!$logs) {
            return [];
        }

        $statusids = [];

        foreach ($logs as $log) {
            $statusid = (int)($log->statusid ?? 0);

            if ($statusid > 0) {
                $statusids[$statusid] = $statusid;
            }
        }

        $statuses = [];

        if ($statusids) {
            $statuses = $DB->get_records_list(
                'attendance_statuses',
                'id',
                array_values($statusids),
                '',
                'id, acronym, description, grade'
            );
        }

        $out = [];

        foreach ($logs as $log) {
            $sessionid = (int)$log->sessionid;
            $studentid = (int)$log->studentid;
            $statusid = (int)$log->statusid;

            $status = $statuses[$statusid] ?? null;

            $label = '-';
            $acronym = '';
            $description = '';

            if ($status) {
                $acronym = trim((string)($status->acronym ?? ''));
                $description = trim((string)($status->description ?? ''));

                if ($description !== '') {
                    $label = $description;
                } else if ($acronym !== '') {
                    $label = $acronym;
                }
            }

            $out[$studentid][$sessionid] = [
                'label' => $label,
                'acronym' => $acronym,
                'description' => $description,
                'remarks' => (string)($log->remarks ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Render badge status.
     */
    private static function render_status_badge(array $status): string {
        $label = trim((string)($status['label'] ?? '-'));
        $acronym = strtolower(trim((string)($status['acronym'] ?? '')));
        $description = strtolower(trim((string)($status['description'] ?? '')));
        $text = $acronym . ' ' . $description . ' ' . strtolower($label);

        if ($label === '-' || $label === '') {
            return '<span class="am-presensi-badge am-presensi-empty">-</span>';
        }

        $class = 'am-presensi-info';

        if (
            str_contains($text, 'present') ||
            str_contains($text, 'hadir') ||
            $acronym === 'p' ||
            $acronym === 'h'
        ) {
            $class = 'am-presensi-present';
        } else if (
            str_contains($text, 'absent') ||
            str_contains($text, 'alfa') ||
            str_contains($text, 'alpha') ||
            $acronym === 'a'
        ) {
            $class = 'am-presensi-absent';
        } else if (
            str_contains($text, 'late') ||
            str_contains($text, 'terlambat') ||
            $acronym === 'l' ||
            $acronym === 't'
        ) {
            $class = 'am-presensi-late';
        } else if (
            str_contains($text, 'excused') ||
            str_contains($text, 'izin') ||
            str_contains($text, 'sakit') ||
            $acronym === 'e' ||
            $acronym === 'i' ||
            $acronym === 's'
        ) {
            $class = 'am-presensi-excused';
        }

        return '<span class="am-presensi-badge ' . $class . '">' . s($label) . '</span>';
    }

    /**
     * Ambil data tabel presensi.
     */
    private static function get_monitoring_presensi(
        int $kelasid,
        int $courseid,
        int $groupid
    ): array {
        $students = self::get_students($kelasid, $courseid, $groupid);

        if (!$students || $courseid <= 0) {
            return [
                'columns' => [],
                'rows' => [],
                'total_students' => count($students),
                'total_sessions' => 0,
            ];
        }

        $sessions = self::get_attendance_sessions($courseid, $groupid);

        if (!$sessions) {
            $rows = [];

            foreach ($students as $student) {
                $rows[] = [
                    'userid' => (int)$student->id,
                    'nama' => fullname($student),
                    'presensi_list' => [],
                ];
            }

            return [
                'columns' => [],
                'rows' => $rows,
                'total_students' => count($students),
                'total_sessions' => 0,
            ];
        }

        $sessionids = array_map('intval', array_keys($sessions));
        $userids = array_map('intval', array_keys($students));

        $logs = self::get_logs_by_sessions_and_students($sessionids, $userids);

        $columns = [];

        foreach ($sessions as $session) {
            $columns[] = [
                'id' => (int)$session->id,
                'label' => $session->column_label,
            ];
        }

        $rows = [];

        foreach ($students as $student) {
            $studentid = (int)$student->id;
            $list = [];

            foreach ($sessions as $session) {
                $sessionid = (int)$session->id;

                $status = $logs[$studentid][$sessionid] ?? [
                    'label' => '-',
                    'acronym' => '',
                    'description' => '',
                    'remarks' => '',
                ];

                $list[] = self::render_status_badge($status);
            }

            $rows[] = [
                'userid' => $studentid,
                'nama' => fullname($student),
                'presensi_list' => $list,
            ];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'total_students' => count($students),
            'total_sessions' => count($sessions),
        ];
    }

    /**
     * Data utama halaman admin monitoring presensi.
     */
    public static function get_page_data(
        int $tahunajaranid,
        int $semester,
        int $kelasid,
        int $courseid
    ): array {
        if ($tahunajaranid <= 0) {
            $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
        }

        if (!in_array($semester, [1, 2], true)) {
            $semester = period_filter_service::get_selected_semester();
        }

        $data = self::get_admin_sidebar_data('monitoring_presensi');

        $tahunoptions = self::get_tahun_options($tahunajaranid);

        $kelasoptions = self::get_kelas_options($tahunajaranid, $kelasid);

        if ($kelasid <= 0 && $kelasoptions) {
            $firstkelas = reset($kelasoptions);
            $kelasid = (int)$firstkelas['id'];
        }

        foreach ($kelasoptions as &$kelasoption) {
            $kelasoption['selected'] = (int)$kelasoption['id'] === (int)$kelasid;
        }
        unset($kelasoption);

        $kelas = self::get_kelas_record($kelasid);

        $courseoptions = [];

        if ($kelas) {
            $courseoptions = self::get_course_options($kelasid, $tahunajaranid, $semester, $courseid);
        }

        if ($courseid <= 0 && $courseoptions) {
            $firstcourse = reset($courseoptions);
            $courseid = (int)$firstcourse['id'];
        }

        foreach ($courseoptions as &$courseoption) {
            $courseoption['selected'] = (int)$courseoption['id'] === (int)$courseid;
        }
        unset($courseoption);

        $selectedkelasname = '-';

        if ($kelas) {
            $selectedkelasname = course_name_service::rombel_label($kelas, (object)[
                'nama_jurusan' => $kelas->nama_jurusan,
            ]);
        }

        $selectedcoursename = '-';

        foreach ($courseoptions as $courseoption) {
            if ((int)$courseoption['id'] === (int)$courseid) {
                $selectedcoursename = (string)$courseoption['nama_mapel'];
                break;
            }
        }

        $groupid = 0;

        if ($kelas && $courseid > 0) {
            $groupid = self::get_groupid_for_course_kelas($courseid, $kelas);
        }

        $presensi = [
            'columns' => [],
            'rows' => [],
            'total_students' => 0,
            'total_sessions' => 0,
        ];

        if ($kelas && $courseid > 0 && self::attendance_tables_available()) {
            $presensi = self::get_monitoring_presensi($kelasid, $courseid, $groupid);
        }

        return array_merge($data, [
            'attendance_available' => self::attendance_tables_available(),

            'filter_action' => (new \moodle_url('/local/akademikmonitor/pages/presensi/index.php'))->out(false),

            'tahun_options' => $tahunoptions,
            'has_tahun_options' => !empty($tahunoptions),

            'kelas_options' => $kelasoptions,
            'has_kelas_options' => !empty($kelasoptions),

            'course_options' => $courseoptions,
            'has_course_options' => !empty($courseoptions),

            'selected_tahunajaranid' => $tahunajaranid,
            'selectedsemester' => $semester,
            'is_semester_1' => $semester === 1,
            'is_semester_2' => $semester === 2,

            'selected_kelasid' => $kelasid,
            'selected_courseid' => $courseid,
            'selected_kelas_name' => format_string($selectedkelasname),
            'selected_course_name' => format_string($selectedcoursename),

            'columns' => $presensi['columns'],
            'rows' => $presensi['rows'],
            'total_students' => (int)$presensi['total_students'],
            'total_sessions' => (int)$presensi['total_sessions'],
        ]);
    }
}