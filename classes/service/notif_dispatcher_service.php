<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class notif_dispatcher_service {

    public static function run(): void {
        global $DB;

        mtrace('[akademikmonitor] Dispatcher notifikasi mulai.');

        $rules = $DB->get_records('notif_rule', ['is_enabled' => '1'], 'id ASC');

        if (!$rules) {
            mtrace('[akademikmonitor] STOP: Tidak ada rule notif aktif.');
            return;
        }

        mtrace('[akademikmonitor] Jumlah rule aktif: ' . count($rules));

        foreach ($rules as $rule) {
            $rulecode = trim((string)$rule->rule_kode);

            mtrace('[akademikmonitor] Proses rule: ' . $rulecode);

            try {
                switch ($rulecode) {
                    case 'pengingat_tugas':
                        self::process_pengingat_tugas($rule);
                        break;

                    case 'pengingat_event':
                        self::process_pengingat_event($rule);
                        break;

                    case 'nilai_kktp':
                        self::process_event_kktp($rule);
                        break;

                    default:
                        mtrace('[akademikmonitor] Rule tidak dikenali: ' . $rulecode);
                        break;
                }
            } catch (\Throwable $e) {
                mtrace('[akademikmonitor] ERROR rule ' . $rulecode . ': ' . $e->getMessage());
            }
        }

        mtrace('[akademikmonitor] Dispatcher notifikasi selesai.');
    }

    /* ============================================================
     * 1. PENGINGAT TUGAS
     * ============================================================ */

    protected static function process_pengingat_tugas(\stdClass $rule): void {
        global $DB;

        $offsetdays = (int)($rule->offset_days ?? 0);
        $sendtime = trim((string)($rule->send_time ?? '07:00:00'));
        $recipientconfig = (string)($rule->recipients ?? '');

        mtrace('[akademikmonitor] Rule pengingat_tugas mulai.');
        mtrace('[akademikmonitor] offset_days = ' . $offsetdays);
        mtrace('[akademikmonitor] send_time = ' . $sendtime);
        mtrace('[akademikmonitor] recipients = ' . $recipientconfig);

        if (!self::is_now_in_send_window($sendtime)) {
            mtrace('[akademikmonitor] STOP: belum masuk jam kirim.');
            return;
        }

        $sendtosiswa = self::has_recipient($recipientconfig, ['siswa', 'student']);
        $sendtowali = self::has_recipient($recipientconfig, ['wali', 'wali kelas', 'walikelas']);
        $sendtoguru = self::has_recipient($recipientconfig, ['guru', 'teacher']);

        mtrace('[akademikmonitor] target siswa = ' . ($sendtosiswa ? 'YA' : 'TIDAK'));
        mtrace('[akademikmonitor] target wali = ' . ($sendtowali ? 'YA' : 'TIDAK'));
        mtrace('[akademikmonitor] target guru = ' . ($sendtoguru ? 'YA' : 'TIDAK'));

        if (!$sendtosiswa && !$sendtowali && !$sendtoguru) {
            mtrace('[akademikmonitor] STOP: rule pengingat_tugas tidak punya target penerima.');
            return;
        }

        $start = strtotime('today +' . $offsetdays . ' day');
        $end = strtotime('tomorrow +' . $offsetdays . ' day') - 1;

        mtrace('[akademikmonitor] range deadline mulai = ' . date('Y-m-d H:i:s', $start));
        mtrace('[akademikmonitor] range deadline akhir = ' . date('Y-m-d H:i:s', $end));

        $assignments = $DB->get_records_select(
            'assign',
            'duedate > 0 AND duedate >= :starttime AND duedate <= :endtime',
            [
                'starttime' => $start,
                'endtime' => $end,
            ],
            'duedate ASC',
            'id, name, duedate, course'
        );

        mtrace('[akademikmonitor] jumlah tugas ditemukan = ' . count($assignments));

        if (!$assignments) {
            mtrace('[akademikmonitor] STOP: tidak ada tugas untuk pengingat.');
            return;
        }

        $courseids = [];

        foreach ($assignments as $assign) {
            $courseids[] = (int)$assign->course;
        }

        $courseids = array_values(array_unique(array_filter($courseids)));

        $courses = [];

        if ($courseids) {
            $courses = $DB->get_records_list(
                'course',
                'id',
                $courseids,
                'fullname ASC',
                'id, fullname'
            );
        }

        foreach ($assignments as $assign) {
            $courseid = (int)$assign->course;

            $assign->coursename = isset($courses[$courseid])
                ? format_string($courses[$courseid]->fullname)
                : '-';

            mtrace('[akademikmonitor] proses tugas: ' . format_string($assign->name));
            mtrace('[akademikmonitor] courseid = ' . $courseid);
            mtrace('[akademikmonitor] course = ' . $assign->coursename);

            $students = self::get_course_students($courseid);

            mtrace('[akademikmonitor] jumlah siswa course = ' . count($students));

            if (!$students) {
                mtrace('[akademikmonitor] SKIP: tidak ada siswa di course tugas.');
                continue;
            }

            $scheduledat = date(
                'Y-m-d H:i:s',
                strtotime(date('Y-m-d', (int)$assign->duedate) . ' ' . $sendtime)
            );

            $duedate = userdate((int)$assign->duedate, '%d %B %Y %H:%M');

            $walimap = [];
            $unsubmittedstudents = [];

            foreach ($students as $student) {
                mtrace('[akademikmonitor] cek siswa: ' . fullname($student) . ' | userid=' . (int)$student->id);

                if (self::has_student_submitted_assignment((int)$assign->id, (int)$student->id)) {
                    mtrace('[akademikmonitor] SKIP siswa sudah submit: ' . fullname($student));
                    continue;
                }

                $unsubmittedstudents[(int)$student->id] = $student;

                if ($sendtosiswa) {
                    self::send_assignment_reminder_to_student(
                        $student,
                        $assign,
                        $offsetdays,
                        $duedate,
                        $scheduledat
                    );
                }

                if ($sendtowali) {
                    $walis = self::get_walikelas_for_student_in_course(
                        (int)$student->id,
                        $courseid
                    );

                    mtrace('[akademikmonitor] jumlah wali untuk siswa ' . fullname($student) . ' = ' . count($walis));

                    foreach ($walis as $wali) {
                        $waliid = (int)$wali->id;

                        if (!isset($walimap[$waliid])) {
                            $walimap[$waliid] = [
                                'user' => $wali,
                                'students' => [],
                            ];
                        }

                        $walimap[$waliid]['students'][(int)$student->id] = $student;
                    }
                }
            }

            mtrace('[akademikmonitor] jumlah wali yang akan dikirimi = ' . count($walimap));

            if ($sendtowali && $walimap) {
                foreach ($walimap as $data) {
                    self::send_assignment_reminder_to_wali(
                        $data['user'],
                        array_values($data['students']),
                        $assign,
                        $offsetdays,
                        $duedate,
                        $scheduledat
                    );
                }
            }

            if ($sendtoguru && $unsubmittedstudents) {
                $teachers = self::get_course_teachers($courseid);

                mtrace('[akademikmonitor] jumlah guru course yang akan dicek = ' . count($teachers));

                foreach ($teachers as $teacher) {
                    self::send_assignment_reminder_to_teacher(
                        $teacher,
                        array_values($unsubmittedstudents),
                        $assign,
                        $offsetdays,
                        $duedate,
                        $scheduledat
                    );
                }
            }
        }
    }

    protected static function send_assignment_reminder_to_student(
        \stdClass $student,
        \stdClass $assign,
        int $offsetdays,
        string $duedate,
        string $scheduledat
    ): void {
        mtrace('[akademikmonitor] coba kirim ke siswa: ' . fullname($student) . ' | userid=' . (int)$student->id);

        $link = notif_service::get_user_link((int)$student->id);

        if (!$link) {
            mtrace('[akademikmonitor] STOP siswa: belum ada data telegram_user_link.');
            return;
        }

        if (empty($link->telegram_chat_id)) {
            mtrace('[akademikmonitor] STOP siswa: telegram_chat_id kosong.');
            return;
        }

        if ((string)$link->is_linked !== '1') {
            mtrace('[akademikmonitor] STOP siswa: is_linked bukan 1. Nilai sekarang=' . (string)$link->is_linked);
            return;
        }

        if (notif_service::has_log_been_sent((int)$student->id, 'pengingat_tugas_siswa', (int)$assign->id, 0, $scheduledat)) {
            mtrace('[akademikmonitor] SKIP siswa: log sent sudah ada.');
            return;
        }

        $message = "📚 <b>Pengingat Tugas</b>\n\n" .
            "Halo <b>" . s(fullname($student)) . "</b>,\n" .
            "Tugas <b>" . s(format_string($assign->name)) . "</b> pada course <b>" . s(format_string($assign->coursename)) . "</b> " .
            "akan berakhir <b>H-" . $offsetdays . "</b>.\n\n" .
            "⏰ Deadline: <b>" . s($duedate) . "</b>\n" .
            "Status: <b>belum mengerjakan / belum submit</b>.";

        $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

        mtrace('[akademikmonitor] hasil kirim siswa: ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL'));

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error Telegram siswa: ' . ($send['message'] ?? '-'));
        }

        self::safe_save_delivery_log(
            (int)$student->id,
            (int)$assign->course,
            'pengingat_tugas_siswa',
            (int)$assign->id,
            0,
            format_string($assign->name),
            $scheduledat,
            (string)$link->telegram_chat_id,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }

    protected static function send_assignment_reminder_to_wali(
        \stdClass $wali,
        array $students,
        \stdClass $assign,
        int $offsetdays,
        string $duedate,
        string $scheduledat
    ): void {
        if (!$students) {
            mtrace('[akademikmonitor] STOP wali: tidak ada siswa untuk dikirim.');
            return;
        }

        mtrace('[akademikmonitor] coba kirim ke wali: ' . fullname($wali) . ' | userid=' . (int)$wali->id);
        mtrace('[akademikmonitor] jumlah siswa belum submit untuk wali ini: ' . count($students));

        $link = notif_service::get_user_link((int)$wali->id);

        if (!$link) {
            mtrace('[akademikmonitor] STOP wali: belum ada data telegram_user_link.');
            return;
        }

        if (empty($link->telegram_chat_id)) {
            mtrace('[akademikmonitor] STOP wali: telegram_chat_id kosong.');
            return;
        }

        if ((string)$link->is_linked !== '1') {
            mtrace('[akademikmonitor] STOP wali: is_linked bukan 1. Nilai sekarang=' . (string)$link->is_linked);
            return;
        }

        if (notif_service::has_log_been_sent((int)$wali->id, 'pengingat_tugas_wali', (int)$assign->id, 0, $scheduledat)) {
            mtrace('[akademikmonitor] SKIP wali: log sent sudah ada.');
            return;
        }

        usort($students, function($a, $b) {
            return strcasecmp(fullname($a), fullname($b));
        });

        $studentlines = [];
        $number = 1;

        foreach ($students as $student) {
            $studentlines[] = $number . '. ' . s(fullname($student));
            $number++;
        }

        $jumlahsiswa = count($studentlines);

        $message = "👩‍🏫 <b>Pengingat Wali Kelas</b>\n\n" .
            "Halo <b>" . s(fullname($wali)) . "</b>,\n\n" .
            "Berikut daftar siswa yang <b>belum mengerjakan / belum submit</b> tugas:\n\n" .
            "📚 Tugas: <b>" . s(format_string($assign->name)) . "</b>\n" .
            "🏫 Course: <b>" . s(format_string($assign->coursename)) . "</b>\n" .
            "⏰ Deadline: <b>" . s($duedate) . "</b>\n" .
            "📌 Status: <b>H-" . $offsetdays . "</b>\n" .
            "👥 Jumlah siswa belum submit: <b>" . $jumlahsiswa . "</b>\n\n" .
            implode("\n", $studentlines);

        $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

        mtrace('[akademikmonitor] hasil kirim wali: ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL'));

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error Telegram wali: ' . ($send['message'] ?? '-'));
        }

        self::safe_save_delivery_log(
            (int)$wali->id,
            (int)$assign->course,
            'pengingat_tugas_wali',
            (int)$assign->id,
            0,
            format_string($assign->name) . ' - Wali Kelas',
            $scheduledat,
            (string)$link->telegram_chat_id,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }

    protected static function send_assignment_reminder_to_teacher(
        \stdClass $teacher,
        array $students,
        \stdClass $assign,
        int $offsetdays,
        string $duedate,
        string $scheduledat
    ): void {
        if (!$students) {
            mtrace('[akademikmonitor] STOP guru: tidak ada siswa untuk dikirim.');
            return;
        }

        mtrace('[akademikmonitor] coba kirim ke guru: ' . fullname($teacher) . ' | userid=' . (int)$teacher->id);
        mtrace('[akademikmonitor] jumlah siswa belum submit untuk guru ini: ' . count($students));

        $link = notif_service::get_user_link((int)$teacher->id);

        if (!$link) {
            mtrace('[akademikmonitor] STOP guru: belum ada data telegram_user_link.');
            return;
        }

        if (empty($link->telegram_chat_id)) {
            mtrace('[akademikmonitor] STOP guru: telegram_chat_id kosong.');
            return;
        }

        if ((string)$link->is_linked !== '1') {
            mtrace('[akademikmonitor] STOP guru: is_linked bukan 1. Nilai sekarang=' . (string)$link->is_linked);
            return;
        }

        if (notif_service::has_log_been_sent((int)$teacher->id, 'pengingat_tugas_guru', (int)$assign->id, 0, $scheduledat)) {
            mtrace('[akademikmonitor] SKIP guru: log sent sudah ada.');
            return;
        }

        usort($students, function($a, $b) {
            return strcasecmp(fullname($a), fullname($b));
        });

        $studentlines = [];
        $number = 1;

        foreach ($students as $student) {
            $studentlines[] = $number . '. ' . s(fullname($student));
            $number++;
        }

        $jumlahsiswa = count($studentlines);

        $message = "👨‍🏫 <b>Pengingat Guru Mata Pelajaran</b>\n\n" .
            "Halo <b>" . s(fullname($teacher)) . "</b>,\n\n" .
            "Berikut daftar siswa pada course Anda yang <b>belum mengerjakan / belum submit</b> tugas:\n\n" .
            "📚 Tugas: <b>" . s(format_string($assign->name)) . "</b>\n" .
            "🏫 Course: <b>" . s(format_string($assign->coursename)) . "</b>\n" .
            "⏰ Deadline: <b>" . s($duedate) . "</b>\n" .
            "📌 Status: <b>H-" . $offsetdays . "</b>\n" .
            "👥 Jumlah siswa belum submit: <b>" . $jumlahsiswa . "</b>\n\n" .
            implode("\n", $studentlines);

        $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

        mtrace('[akademikmonitor] hasil kirim guru: ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL'));

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error Telegram guru: ' . ($send['message'] ?? '-'));
        }

        self::safe_save_delivery_log(
            (int)$teacher->id,
            (int)$assign->course,
            'pengingat_tugas_guru',
            (int)$assign->id,
            0,
            format_string($assign->name) . ' - Guru',
            $scheduledat,
            (string)$link->telegram_chat_id,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }

    protected static function has_student_submitted_assignment(int $assignid, int $studentid): bool {
        global $DB;

        return $DB->record_exists('assign_submission', [
            'assignment' => $assignid,
            'userid' => $studentid,
            'latest' => 1,
            'status' => 'submitted',
        ]);
    }

    /* ============================================================
     * 2. EVENT NILAI DI BAWAH KKTP
     * ============================================================ */

    protected static function process_event_kktp(\stdClass $rule): void {
        global $DB;

        $offsetdays = (int)($rule->offset_days ?? 0);
        $sendtime = trim((string)($rule->send_time ?? '07:00:00'));
        $keyword = trim((string)($rule->event_keyword ?? ''));
        $recipientconfig = (string)($rule->recipients ?? '');

        mtrace('[akademikmonitor] Rule nilai_kktp mulai.');
        mtrace('[akademikmonitor] offset_days = ' . $offsetdays);
        mtrace('[akademikmonitor] send_time = ' . $sendtime);
        mtrace('[akademikmonitor] event_keyword = ' . $keyword);
        mtrace('[akademikmonitor] recipients = ' . $recipientconfig);

        if (!self::is_now_in_send_window($sendtime)) {
            mtrace('[akademikmonitor] STOP nilai_kktp: belum masuk jam kirim.');
            return;
        }

        $sendtosiswa = self::has_recipient($recipientconfig, ['siswa', 'student']);
        $sendtowali = self::has_recipient($recipientconfig, ['wali', 'wali kelas', 'walikelas']);
        $sendtoguru = self::has_recipient($recipientconfig, ['guru', 'teacher']);

        mtrace('[akademikmonitor] target siswa = ' . ($sendtosiswa ? 'YA' : 'TIDAK'));
        mtrace('[akademikmonitor] target wali = ' . ($sendtowali ? 'YA' : 'TIDAK'));
        mtrace('[akademikmonitor] target guru = ' . ($sendtoguru ? 'YA' : 'TIDAK'));

        if (!$sendtosiswa && !$sendtowali && !$sendtoguru) {
            mtrace('[akademikmonitor] STOP nilai_kktp: tidak punya target penerima.');
            return;
        }

        $start = strtotime('today +' . $offsetdays . ' day');
        $end = strtotime('tomorrow +' . $offsetdays . ' day') - 1;

        mtrace('[akademikmonitor] range event mulai = ' . date('Y-m-d H:i:s', $start));
        mtrace('[akademikmonitor] range event akhir = ' . date('Y-m-d H:i:s', $end));

        $params = [
            'starttime' => $start,
            'endtime' => $end,
        ];

        $select = 'timestart >= :starttime AND timestart <= :endtime AND courseid > 0';

        if ($keyword !== '') {
            $select .= ' AND (' .
                $DB->sql_like('name', ':kw1', false) .
                ' OR ' .
                $DB->sql_like('description', ':kw2', false) .
                ')';

            $params['kw1'] = '%' . $DB->sql_like_escape($keyword) . '%';
            $params['kw2'] = '%' . $DB->sql_like_escape($keyword) . '%';
        }

        $events = $DB->get_records_select(
            'event',
            $select,
            $params,
            'timestart ASC',
            'id, name, description, timestart, courseid'
        );

        mtrace('[akademikmonitor] jumlah event nilai_kktp ditemukan = ' . count($events));

        if (!$events) {
            mtrace('[akademikmonitor] STOP nilai_kktp: tidak ada event cocok.');
            return;
        }

        foreach ($events as $event) {
            $courseid = (int)($event->courseid ?? 0);

            if ($courseid <= 0) {
                mtrace('[akademikmonitor] SKIP event: event tidak punya courseid. Event ID=' . (int)$event->id);
                continue;
            }

            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);

            if (!$course) {
                mtrace('[akademikmonitor] SKIP event: course tidak ditemukan. courseid=' . $courseid);
                continue;
            }

            $event->coursename = format_string($course->fullname);

            mtrace('[akademikmonitor] proses event: ' . format_string($event->name));
            mtrace('[akademikmonitor] courseid = ' . $courseid);
            mtrace('[akademikmonitor] course = ' . $event->coursename);

            $kktpinfo = self::get_course_kktp_info($courseid);

            if (!$kktpinfo) {
                mtrace('[akademikmonitor] SKIP event: KKTP course belum ditemukan. courseid=' . $courseid);
                continue;
            }

            if ((float)$kktpinfo->kktp <= 0) {
                mtrace('[akademikmonitor] SKIP event: KKTP course masih 0/kosong. courseid=' . $courseid);
                continue;
            }

            $students = self::get_course_students($courseid);

            mtrace('[akademikmonitor] jumlah siswa course event = ' . count($students));

            if (!$students) {
                mtrace('[akademikmonitor] SKIP event: tidak ada siswa pada course event.');
                continue;
            }

            $studentids = array_map(function($student) {
                return (int)$student->id;
            }, $students);

            $grades = self::get_course_total_grades($courseid, $studentids);

            if (!$grades) {
                mtrace('[akademikmonitor] SKIP event: belum ada nilai course total untuk siswa.');
                continue;
            }

            $underkktp = [];

            foreach ($students as $student) {
                $userid = (int)$student->id;

                if (!array_key_exists($userid, $grades)) {
                    mtrace('[akademikmonitor] SKIP siswa nilai kosong: ' . fullname($student) . ' | userid=' . $userid);
                    continue;
                }

                $grade = $grades[$userid];

                if ($grade === null) {
                    mtrace('[akademikmonitor] SKIP siswa nilai null: ' . fullname($student) . ' | userid=' . $userid);
                    continue;
                }

                if ((float)$grade < (float)$kktpinfo->kktp) {
                    $underkktp[$userid] = [
                        'user' => $student,
                        'grade' => (float)$grade,
                        'kktp' => (float)$kktpinfo->kktp,
                        'mapel' => (string)$kktpinfo->nama_mapel,
                    ];

                    mtrace('[akademikmonitor] siswa di bawah KKTP: ' . fullname($student) .
                        ' | nilai=' . self::format_number($grade) .
                        ' | kktp=' . self::format_number($kktpinfo->kktp)
                    );
                }
            }

            mtrace('[akademikmonitor] jumlah siswa nilai < KKTP = ' . count($underkktp));

            if (!$underkktp) {
                mtrace('[akademikmonitor] SKIP event: tidak ada siswa nilai di bawah KKTP.');
                continue;
            }

            $scheduledat = date(
                'Y-m-d H:i:s',
                strtotime(date('Y-m-d', (int)$event->timestart) . ' ' . $sendtime)
            );

            $eventdate = userdate((int)$event->timestart, '%d %B %Y %H:%M');

            if ($sendtosiswa) {
                foreach ($underkktp as $item) {
                    self::send_event_kktp_to_student(
                        $item,
                        $event,
                        $kktpinfo,
                        $offsetdays,
                        $eventdate,
                        $scheduledat
                    );
                }
            }

            if ($sendtowali) {
                $walimap = [];

                foreach ($underkktp as $item) {
                    $student = $item['user'];

                    $walis = self::get_walikelas_for_student_in_course((int)$student->id, $courseid);

                    mtrace('[akademikmonitor] jumlah wali untuk siswa ' . fullname($student) . ' = ' . count($walis));

                    foreach ($walis as $wali) {
                        $waliid = (int)$wali->id;

                        if (!isset($walimap[$waliid])) {
                            $walimap[$waliid] = [
                                'user' => $wali,
                                'items' => [],
                            ];
                        }

                        $walimap[$waliid]['items'][(int)$student->id] = $item;
                    }
                }

                mtrace('[akademikmonitor] jumlah wali event_kktp yang akan dikirimi = ' . count($walimap));

                foreach ($walimap as $data) {
                    self::send_event_kktp_to_wali(
                        $data['user'],
                        array_values($data['items']),
                        $event,
                        $kktpinfo,
                        $offsetdays,
                        $eventdate,
                        $scheduledat
                    );
                }
            }

            if ($sendtoguru) {
                $teachers = self::get_course_teachers($courseid);

                mtrace('[akademikmonitor] jumlah guru event_kktp yang akan dikirimi = ' . count($teachers));

                foreach ($teachers as $teacher) {
                    self::send_event_kktp_to_teacher(
                        $teacher,
                        array_values($underkktp),
                        $event,
                        $kktpinfo,
                        $offsetdays,
                        $eventdate,
                        $scheduledat
                    );
                }
            }
        }
    }

    protected static function send_event_kktp_to_student(
        array $item,
        \stdClass $event,
        \stdClass $kktpinfo,
        int $offsetdays,
        string $eventdate,
        string $scheduledat
    ): void {
        $student = $item['user'];
        $grade = (float)$item['grade'];
        $kktp = (float)$item['kktp'];

        mtrace('[akademikmonitor] coba kirim event_kktp ke siswa: ' . fullname($student) . ' | userid=' . (int)$student->id);

        $link = notif_service::get_user_link((int)$student->id);

        if (!$link) {
            mtrace('[akademikmonitor] STOP event_kktp siswa: belum ada data telegram_user_link.');
            return;
        }

        if (empty($link->telegram_chat_id)) {
            mtrace('[akademikmonitor] STOP event_kktp siswa: telegram_chat_id kosong.');
            return;
        }

        if ((string)$link->is_linked !== '1') {
            mtrace('[akademikmonitor] STOP event_kktp siswa: is_linked bukan 1. Nilai sekarang=' . (string)$link->is_linked);
            return;
        }

        if (notif_service::has_log_been_sent((int)$student->id, 'pengingat_event_kktp_siswa', 0, (int)$event->id, $scheduledat)) {
            mtrace('[akademikmonitor] SKIP event_kktp siswa: log sent sudah ada.');
            return;
        }

        $message = "📅 <b>Pengingat Event Akademik</b>\n\n" .
            "Halo <b>" . s(fullname($student)) . "</b>,\n\n" .
            "Akan ada event <b>" . s(format_string($event->name)) . "</b> pada:\n" .
            "🗓️ <b>" . s($eventdate) . "</b>\n\n" .
            "Nilai Anda pada mapel/course berikut masih di bawah KKTP:\n\n" .
            "📚 Mapel: <b>" . s($kktpinfo->nama_mapel) . "</b>\n" .
            "🏫 Course: <b>" . s(format_string($event->coursename)) . "</b>\n" .
            "📊 Nilai Anda: <b>" . self::format_number($grade) . "</b>\n" .
            "🎯 KKTP: <b>" . self::format_number($kktp) . "</b>\n" .
            "⏰ Status event: <b>H-" . $offsetdays . "</b>\n\n" .
            "Mohon segera melakukan persiapan dan perbaikan belajar.";

        $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

        mtrace('[akademikmonitor] hasil kirim event_kktp siswa: ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL'));

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error event_kktp siswa: ' . ($send['message'] ?? '-'));
        }

        self::safe_save_delivery_log(
            (int)$student->id,
            (int)$event->courseid,
            'pengingat_event_kktp_siswa',
            0,
            (int)$event->id,
            format_string($event->name) . ' - Nilai < KKTP',
            $scheduledat,
            (string)$link->telegram_chat_id,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }

    protected static function send_event_kktp_to_wali(
        \stdClass $wali,
        array $items,
        \stdClass $event,
        \stdClass $kktpinfo,
        int $offsetdays,
        string $eventdate,
        string $scheduledat
    ): void {
        if (!$items) {
            return;
        }

        mtrace('[akademikmonitor] coba kirim event_kktp ke wali: ' . fullname($wali) . ' | userid=' . (int)$wali->id);
        mtrace('[akademikmonitor] jumlah siswa nilai < KKTP untuk wali ini: ' . count($items));

        $link = notif_service::get_user_link((int)$wali->id);

        if (!$link) {
            mtrace('[akademikmonitor] STOP event_kktp wali: belum ada data telegram_user_link.');
            return;
        }

        if (empty($link->telegram_chat_id)) {
            mtrace('[akademikmonitor] STOP event_kktp wali: telegram_chat_id kosong.');
            return;
        }

        if ((string)$link->is_linked !== '1') {
            mtrace('[akademikmonitor] STOP event_kktp wali: is_linked bukan 1. Nilai sekarang=' . (string)$link->is_linked);
            return;
        }

        if (notif_service::has_log_been_sent((int)$wali->id, 'pengingat_event_kktp_wali', 0, (int)$event->id, $scheduledat)) {
            mtrace('[akademikmonitor] SKIP event_kktp wali: log sent sudah ada.');
            return;
        }

        usort($items, function($a, $b) {
            return strcasecmp(fullname($a['user']), fullname($b['user']));
        });

        $lines = [];
        $number = 1;

        foreach ($items as $item) {
            $student = $item['user'];
            $lines[] = $number . '. ' . s(fullname($student)) . ' - Nilai: ' . self::format_number($item['grade']);
            $number++;
        }

        $message = "👩‍🏫 <b>Pengingat Wali Kelas - Event Akademik</b>\n\n" .
            "Halo <b>" . s(fullname($wali)) . "</b>,\n\n" .
            "Akan ada event <b>" . s(format_string($event->name)) . "</b> pada:\n" .
            "🗓️ <b>" . s($eventdate) . "</b>\n\n" .
            "Berikut anak wali Anda yang nilainya masih di bawah KKTP:\n\n" .
            "📚 Mapel: <b>" . s($kktpinfo->nama_mapel) . "</b>\n" .
            "🏫 Course: <b>" . s(format_string($event->coursename)) . "</b>\n" .
            "🎯 KKTP: <b>" . self::format_number($kktpinfo->kktp) . "</b>\n" .
            "⏰ Status event: <b>H-" . $offsetdays . "</b>\n" .
            "👥 Jumlah siswa: <b>" . count($items) . "</b>\n\n" .
            implode("\n", $lines);

        $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

        mtrace('[akademikmonitor] hasil kirim event_kktp wali: ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL'));

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error event_kktp wali: ' . ($send['message'] ?? '-'));
        }

        self::safe_save_delivery_log(
            (int)$wali->id,
            (int)$event->courseid,
            'pengingat_event_kktp_wali',
            0,
            (int)$event->id,
            format_string($event->name) . ' - Wali Nilai < KKTP',
            $scheduledat,
            (string)$link->telegram_chat_id,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }

    protected static function send_event_kktp_to_teacher(
        \stdClass $teacher,
        array $items,
        \stdClass $event,
        \stdClass $kktpinfo,
        int $offsetdays,
        string $eventdate,
        string $scheduledat
    ): void {
        if (!$items) {
            return;
        }

        mtrace('[akademikmonitor] coba kirim event_kktp ke guru: ' . fullname($teacher) . ' | userid=' . (int)$teacher->id);
        mtrace('[akademikmonitor] jumlah siswa nilai < KKTP untuk guru ini: ' . count($items));

        $link = notif_service::get_user_link((int)$teacher->id);

        if (!$link) {
            mtrace('[akademikmonitor] STOP event_kktp guru: belum ada data telegram_user_link.');
            return;
        }

        if (empty($link->telegram_chat_id)) {
            mtrace('[akademikmonitor] STOP event_kktp guru: telegram_chat_id kosong.');
            return;
        }

        if ((string)$link->is_linked !== '1') {
            mtrace('[akademikmonitor] STOP event_kktp guru: is_linked bukan 1. Nilai sekarang=' . (string)$link->is_linked);
            return;
        }

        if (notif_service::has_log_been_sent((int)$teacher->id, 'pengingat_event_kktp_guru', 0, (int)$event->id, $scheduledat)) {
            mtrace('[akademikmonitor] SKIP event_kktp guru: log sent sudah ada.');
            return;
        }

        usort($items, function($a, $b) {
            return strcasecmp(fullname($a['user']), fullname($b['user']));
        });

        $lines = [];
        $number = 1;

        foreach ($items as $item) {
            $student = $item['user'];
            $lines[] = $number . '. ' . s(fullname($student)) . ' - Nilai: ' . self::format_number($item['grade']);
            $number++;
        }

        $message = "👨‍🏫 <b>Pengingat Guru Mapel - Event Akademik</b>\n\n" .
            "Halo <b>" . s(fullname($teacher)) . "</b>,\n\n" .
            "Akan ada event <b>" . s(format_string($event->name)) . "</b> pada:\n" .
            "🗓️ <b>" . s($eventdate) . "</b>\n\n" .
            "Berikut siswa pada course Anda yang nilainya masih di bawah KKTP:\n\n" .
            "📚 Mapel: <b>" . s($kktpinfo->nama_mapel) . "</b>\n" .
            "🏫 Course: <b>" . s(format_string($event->coursename)) . "</b>\n" .
            "🎯 KKTP: <b>" . self::format_number($kktpinfo->kktp) . "</b>\n" .
            "⏰ Status event: <b>H-" . $offsetdays . "</b>\n" .
            "👥 Jumlah siswa: <b>" . count($items) . "</b>\n\n" .
            implode("\n", $lines);

        $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

        mtrace('[akademikmonitor] hasil kirim event_kktp guru: ' . ($send['ok'] ? 'BERHASIL' : 'GAGAL'));

        if (!$send['ok']) {
            mtrace('[akademikmonitor] pesan error event_kktp guru: ' . ($send['message'] ?? '-'));
        }

        self::safe_save_delivery_log(
            (int)$teacher->id,
            (int)$event->courseid,
            'pengingat_event_kktp_guru',
            0,
            (int)$event->id,
            format_string($event->name) . ' - Guru Nilai < KKTP',
            $scheduledat,
            (string)$link->telegram_chat_id,
            $message,
            $send['ok'] ? 'sent' : 'failed',
            $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
        );
    }

    protected static function get_course_kktp_info(int $courseid): ?\stdClass {
        global $DB;

        $courseid = (int)$courseid;

        if ($courseid <= 0) {
            return null;
        }

        $sql = "SELECT cm.id,
                       cm.id_course,
                       cm.id_kurikulum_mapel,
                       km.id_mapel,
                       km.kktp,
                       mp.nama_mapel AS nama_mapel
                  FROM {course_mapel} cm
                  JOIN {kurikulum_mapel} km ON km.id = cm.id_kurikulum_mapel
             LEFT JOIN {mata_pelajaran} mp ON mp.id = km.id_mapel
                 WHERE cm.id_course = :courseid
              ORDER BY cm.id ASC";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, 1);

        if (!$records) {
            return null;
        }

        $record = reset($records);
        $record->kktp = (float)($record->kktp ?? 0);

        if (empty($record->nama_mapel)) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
            $record->nama_mapel = $course ? format_string($course->fullname) : '-';
        }

        return $record;
    }

    protected static function get_course_total_grades(int $courseid, array $userids): array {
        global $DB;

        $courseid = (int)$courseid;
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));

        if ($courseid <= 0 || !$userids) {
            return [];
        }

        $gradeitem = $DB->get_record(
            'grade_items',
            [
                'courseid' => $courseid,
                'itemtype' => 'course',
            ],
            'id, courseid, grademax',
            IGNORE_MISSING
        );

        if (!$gradeitem) {
            mtrace('[akademikmonitor] grade item course total tidak ditemukan. courseid=' . $courseid);
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params['itemid'] = (int)$gradeitem->id;

        $sql = "SELECT gg.userid,
                       gg.finalgrade
                  FROM {grade_grades} gg
                 WHERE gg.itemid = :itemid
                   AND gg.userid {$insql}";

        $records = $DB->get_records_sql($sql, $params);

        if (!$records) {
            return [];
        }

        $grades = [];

        foreach ($records as $record) {
            $grades[(int)$record->userid] = $record->finalgrade === null
                ? null
                : (float)$record->finalgrade;
        }

        return $grades;
    }

    /* ============================================================
     * 3. PENGINGAT EVENT BIASA
     * ============================================================ */

    protected static function process_pengingat_event(\stdClass $rule): void {
        global $DB;

        $offsetdays = (int)($rule->offset_days ?? 0);
        $sendtime = trim((string)($rule->send_time ?? '07:00:00'));
        $keyword = trim((string)($rule->event_keyword ?? ''));

        mtrace('[akademikmonitor] Rule pengingat_event mulai.');

        if (!self::is_now_in_send_window($sendtime)) {
            mtrace('[akademikmonitor] STOP event: belum masuk jam kirim.');
            return;
        }

        $start = strtotime('today +' . $offsetdays . ' day');
        $end = strtotime('tomorrow +' . $offsetdays . ' day') - 1;

        $params = [
            'starttime' => $start,
            'endtime' => $end,
        ];

        $select = 'timestart >= :starttime AND timestart <= :endtime';

        if ($keyword !== '') {
            $select .= ' AND (' .
                $DB->sql_like('name', ':kw1', false) .
                ' OR ' .
                $DB->sql_like('description', ':kw2', false) .
                ')';

            $params['kw1'] = '%' . $DB->sql_like_escape($keyword) . '%';
            $params['kw2'] = '%' . $DB->sql_like_escape($keyword) . '%';
        }

        $events = $DB->get_records_select(
            'event',
            $select,
            $params,
            'timestart ASC',
            'id, name, description, timestart, courseid'
        );

        mtrace('[akademikmonitor] jumlah event ditemukan = ' . count($events));

        if (!$events) {
            mtrace('[akademikmonitor] Tidak ada event kalender untuk pengingat.');
            return;
        }

        foreach ($events as $event) {
            $recipients = self::resolve_event_recipients($event, (string)($rule->recipients ?? ''));

            foreach ($recipients as $user) {
                $link = notif_service::get_user_link((int)$user->id);

                if (!$link || empty($link->telegram_chat_id) || (string)$link->is_linked !== '1') {
                    continue;
                }

                $scheduledat = date(
                    'Y-m-d H:i:s',
                    strtotime(date('Y-m-d', (int)$event->timestart) . ' ' . $sendtime)
                );

                if (notif_service::has_log_been_sent((int)$user->id, 'pengingat_event', 0, (int)$event->id, $scheduledat)) {
                    continue;
                }

                $eventdate = userdate((int)$event->timestart, '%d %B %Y %H:%M');

                $message = "📅 <b>Pengingat Agenda</b>\n\n" .
                    "Halo <b>" . s(fullname($user)) . "</b>,\n" .
                    "Ada agenda <b>" . s(format_string($event->name)) . "</b> yang akan berlangsung <b>H-" . $offsetdays . "</b>.\n\n" .
                    "🗓️ Waktu: <b>" . s($eventdate) . "</b>";

                $send = notif_service::send_telegram((string)$link->telegram_chat_id, $message);

                self::safe_save_delivery_log(
                    (int)$user->id,
                    (int)($event->courseid ?? 0),
                    'pengingat_event',
                    0,
                    (int)$event->id,
                    format_string($event->name),
                    $scheduledat,
                    (string)$link->telegram_chat_id,
                    $message,
                    $send['ok'] ? 'sent' : 'failed',
                    $send['ok'] ? '' : ($send['message'] ?? 'Gagal kirim')
                );
            }
        }
    }

    /* ============================================================
     * 4. HELPER
     * ============================================================ */

    protected static function get_course_students(int $courseid): array {
        $context = \context_course::instance($courseid);

        $students = get_enrolled_users(
            $context,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email, u.deleted, u.suspended'
        );

        if (!$students) {
            return [];
        }

        $studentrole = self::get_student_role();

        if (!$studentrole) {
            mtrace('[akademikmonitor] Role student tidak ditemukan.');
            return [];
        }

        $walikelasroleids = self::get_walikelas_role_ids();

        $out = [];

        foreach ($students as $user) {
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }

            $userid = (int)$user->id;

            $isstudent = user_has_role_assignment(
                $userid,
                (int)$studentrole->id,
                (int)$context->id
            );

            if (!$isstudent) {
                continue;
            }

            $iswalikelas = false;

            foreach ($walikelasroleids as $roleid) {
                if (user_has_role_assignment($userid, (int)$roleid, (int)$context->id)) {
                    $iswalikelas = true;
                    break;
                }
            }

            if ($iswalikelas) {
                mtrace('[akademikmonitor] SKIP user wali kelas dari daftar siswa: ' . fullname($user) . ' | userid=' . $userid);
                continue;
            }

            $out[] = $user;
        }

        return $out;
    }

    protected static function get_course_teachers(int $courseid): array {
        $context = \context_course::instance($courseid);

        $users = get_enrolled_users(
            $context,
            '',
            0,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email, u.deleted, u.suspended'
        );

        if (!$users) {
            return [];
        }

        $teacherroleids = self::get_teacher_role_ids();

        if (!$teacherroleids) {
            mtrace('[akademikmonitor] Role guru tidak ditemukan.');
            return [];
        }

        $out = [];

        foreach ($users as $user) {
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }

            foreach ($teacherroleids as $roleid) {
                if (user_has_role_assignment((int)$user->id, (int)$roleid, (int)$context->id)) {
                    $out[(int)$user->id] = $user;
                    break;
                }
            }
        }

        return array_values($out);
    }

    protected static function get_walikelas_for_student_in_course(int $studentid, int $courseid): array {
        global $DB;

        $studentid = (int)$studentid;
        $courseid = (int)$courseid;

        if ($studentid <= 0) {
            return [];
        }

        $walikelasroleids = self::get_walikelas_role_ids();

        if (!$walikelasroleids) {
            mtrace('[akademikmonitor] Role wali kelas tidak ditemukan. Cek shortname/nama role.');
            return [];
        }

        $studentmemberships = $DB->get_records(
            'groups_members',
            ['userid' => $studentid],
            'groupid ASC',
            'id, groupid'
        );

        if (!$studentmemberships) {
            mtrace('[akademikmonitor] siswa userid=' . $studentid . ' tidak punya group.');
            return [];
        }

        $groupids = [];

        foreach ($studentmemberships as $membership) {
            $groupids[] = (int)$membership->groupid;
        }

        $groupids = array_values(array_unique(array_filter($groupids)));

        if (!$groupids) {
            return [];
        }

        $prioritygroupids = [];

        if ($courseid > 0) {
            $groupsincourse = $DB->get_records_list(
                'groups',
                'id',
                $groupids,
                'id ASC',
                'id, courseid'
            );

            foreach ($groupsincourse as $group) {
                if ((int)$group->courseid === $courseid) {
                    $prioritygroupids[] = (int)$group->id;
                }
            }

            $prioritygroupids = array_values(array_unique(array_filter($prioritygroupids)));
        }

        $walikelas = self::get_walikelas_users_from_groups(
            $prioritygroupids,
            $studentid,
            $walikelasroleids
        );

        if ($walikelas) {
            return $walikelas;
        }

        return self::get_walikelas_users_from_groups(
            $groupids,
            $studentid,
            $walikelasroleids
        );
    }

    protected static function get_walikelas_users_from_groups(
        array $groupids,
        int $studentid,
        array $walikelasroleids
    ): array {
        global $DB;

        $groupids = array_values(array_unique(array_filter(array_map('intval', $groupids))));
        $walikelasroleids = array_values(array_unique(array_filter(array_map('intval', $walikelasroleids))));
        $studentid = (int)$studentid;

        if (!$groupids || !$walikelasroleids || $studentid <= 0) {
            return [];
        }

        $memberships = $DB->get_records_list(
            'groups_members',
            'groupid',
            $groupids,
            'userid ASC',
            'id, groupid, userid'
        );

        if (!$memberships) {
            return [];
        }

        $candidateids = [];

        foreach ($memberships as $membership) {
            $userid = (int)$membership->userid;

            if ($userid <= 0) {
                continue;
            }

            if ($userid === $studentid) {
                continue;
            }

            $candidateids[] = $userid;
        }

        $candidateids = array_values(array_unique(array_filter($candidateids)));

        if (!$candidateids) {
            return [];
        }

        $roleassignments = $DB->get_records_list(
            'role_assignments',
            'userid',
            $candidateids,
            'userid ASC',
            'id, userid, roleid, contextid'
        );

        if (!$roleassignments) {
            return [];
        }

        $walikelasids = [];

        foreach ($roleassignments as $assignment) {
            if (in_array((int)$assignment->roleid, $walikelasroleids, true)) {
                $walikelasids[] = (int)$assignment->userid;
            }
        }

        $walikelasids = array_values(array_unique(array_filter($walikelasids)));

        if (!$walikelasids) {
            return [];
        }

        $users = $DB->get_records_list(
            'user',
            'id',
            $walikelasids,
            'firstname ASC, lastname ASC',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email, deleted, suspended'
        );

        if (!$users) {
            return [];
        }

        $result = [];

        foreach ($users as $user) {
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }

            $result[(int)$user->id] = $user;
        }

        return array_values($result);
    }

    protected static function resolve_event_recipients(\stdClass $event, string $recipientconfig): array {
        $users = [];

        if (!empty($event->courseid)) {
            if (self::has_recipient($recipientconfig, ['siswa', 'student'])) {
                $users = array_merge($users, self::get_course_students((int)$event->courseid));
            }

            if (self::has_recipient($recipientconfig, ['guru', 'teacher', 'wali', 'wali kelas', 'walikelas'])) {
                $users = array_merge($users, self::get_course_teachers((int)$event->courseid));
            }
        }

        $unique = [];

        foreach ($users as $user) {
            $unique[(int)$user->id] = $user;
        }

        return array_values($unique);
    }

    protected static function get_student_role(): ?\stdClass {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'student']);

        return $role ?: null;
    }

    protected static function get_teacher_role_ids(): array {
        global $DB;

        $roles = $DB->get_records_list(
            'role',
            'shortname',
            ['editingteacher', 'teacher'],
            'id ASC',
            'id, shortname'
        );

        if (!$roles) {
            return [];
        }

        $ids = [];

        foreach ($roles as $role) {
            $ids[] = (int)$role->id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected static function get_walikelas_role_ids(): array {
        global $DB;

        $roles = $DB->get_records(
            'role',
            null,
            'id ASC',
            'id, shortname, name'
        );

        if (!$roles) {
            return [];
        }

        $ids = [];

        foreach ($roles as $role) {
            $shortname = strtolower(trim((string)$role->shortname));
            $name = strtolower(trim((string)$role->name));

            $shortclean = str_replace([' ', '-', '_'], '', $shortname);
            $nameclean = str_replace([' ', '-', '_'], '', $name);

            $iswalikelas =
                $shortclean === 'walikelas'
                || $shortclean === 'homeroomteacher'
                || $nameclean === 'walikelas'
                || (
                    strpos($name, 'wali') !== false
                    && strpos($name, 'kelas') !== false
                );

            if ($iswalikelas) {
                $ids[] = (int)$role->id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected static function has_recipient(string $recipientconfig, array $needles): bool {
        $recipientconfig = strtolower(trim($recipientconfig));

        if ($recipientconfig === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if (strpos($recipientconfig, strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }

    protected static function is_now_in_send_window(string $sendtime): bool {
        $now = time();
        $today = date('Y-m-d', $now);
        $target = strtotime($today . ' ' . $sendtime);

        if (!$target) {
            return false;
        }

        return $now >= $target;
    }

    protected static function format_number($number): string {
        if ($number === null || $number === '') {
            return '-';
        }

        $number = (float)$number;

        if (floor($number) == $number) {
            return (string)(int)$number;
        }

        return number_format($number, 2, ',', '.');
    }

    protected static function safe_save_delivery_log(
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
        try {
            notif_service::save_delivery_log(
                $userid,
                $courseid,
                $rulecode,
                $assignid,
                $eventid,
                $contexttitle,
                $scheduledat,
                $chatid,
                $messagepreview,
                $status,
                $errormessage
            );
        } catch (\Throwable $e) {
            mtrace('[akademikmonitor] Gagal simpan log pengiriman: ' . $e->getMessage());
        }
    }
}