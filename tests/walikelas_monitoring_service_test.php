<?php

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\monitoring_service;

final class local_akademikmonitor_walikelas_monitoring_service_test extends advanced_testcase {
    protected function setUp(): void {
        global $CFG;

        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/group/lib.php');
    }

    public function test_get_mapel_by_kelas_matches_courses_by_group_name_tokens(): void {
        $generator = $this->getDataGenerator();

        $coursekelas = $generator->create_course(['fullname' => 'Kelas Induk Rombel']);
        $group = $generator->create_group([
            'courseid' => $coursekelas->id,
            'name' => 'X RPL 1',
        ]);

        $matching1 = $generator->create_course(['fullname' => 'Mapel X RPL Matematika']);
        $matching2 = $generator->create_course(['fullname' => 'Produktif X RPL']);
        $nomatch = $generator->create_course(['fullname' => 'Mapel XI TKJ']);

        $rows = monitoring_service::get_mapel_by_kelas((int)$group->id);

        $ids = array_column($rows, 'id');
        $this->assertContains((int)$matching1->id, $ids);
        $this->assertContains((int)$matching2->id, $ids);
        $this->assertNotContains((int)$nomatch->id, $ids);
    }

    public function test_get_monitoring_nilai_returns_rows_and_default_dash_when_no_course_total_exists(): void {
        global $DB;

        $generator = $this->getDataGenerator();

        $coursekelas = $generator->create_course(['fullname' => 'Kelas X RPL']);
        $group = $generator->create_group([
            'courseid' => $coursekelas->id,
            'name' => 'X RPL 1',
        ]);

        $studenthigh = $generator->create_and_enrol($coursekelas, 'student', ['firstname' => 'Tinggi']);
        $studentlow = $generator->create_and_enrol($coursekelas, 'student', ['firstname' => 'Rendah']);

        groups_add_member($group->id, $studenthigh->id);
        groups_add_member($group->id, $studentlow->id);

        $mapelid = $DB->insert_record('mata_pelajaran', (object)[
            'nama_mapel' => 'Matematika',
        ]);

        $kurikulumid = $DB->insert_record('kurikulum', (object)[
            'nama' => 'Kurikulum Merdeka',
            'is_active' => '1',
        ]);

        $tahunajaranid = $DB->insert_record('tahun_ajaran', (object)[
            'tahun_ajaran' => '2025/2026',
        ]);

        $jurusanid = $DB->insert_record('jurusan', (object)[
            'nama_jurusan' => 'RPL',
            'kode_jurusan' => 10,
        ]);

        $kjid = $DB->insert_record('kurikulum_jurusan', (object)[
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunajaranid,
        ]);

        $kmid = $DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kjid,
            'id_mapel' => $mapelid,
            'jam_pelajaran' => '00:00:00',
            'tingkat_kelas' => 'X',
            'kktp' => 75,
        ]);

        $DB->execute(
            "INSERT INTO {course_mapel} (id_course, id_kurikulum_mapel) VALUES (?, ?)",
            [$coursekelas->id, $kmid]
        );

        $result = monitoring_service::get_monitoring_nilai((int)$group->id, (int)$coursekelas->id);

        $this->assertSame([['name' => 'Course', 'count' => 1]], $result['groups']);
        $this->assertSame(['Course Total'], $result['columns']);
        $this->assertCount(2, $result['rows']);

        $nilai1 = $result['rows'][0]['nilai_list'][0];
        $nilai2 = $result['rows'][1]['nilai_list'][0];

        $this->assertSame('-', $nilai1);
        $this->assertSame('-', $nilai2);
    }

    public function test_pivot_nilai_formats_green_and_red_based_on_kktp(): void {
        $records = [
            (object)[
                'userid' => 101,
                'nama' => 'Tinggi',
                'itemtype' => 'course',
                'finalgrade' => 88,
                'nilai_kktp' => 75,
            ],
            (object)[
                'userid' => 102,
                'nama' => 'Rendah',
                'itemtype' => 'course',
                'finalgrade' => 60,
                'nilai_kktp' => 75,
            ],
        ];

        $method = new ReflectionMethod(monitoring_service::class, 'pivot_nilai');
        $method->setAccessible(true);

        $result = $method->invoke(null, $records);

        $this->assertSame([['name' => 'Course', 'count' => 1]], $result['groups']);
        $this->assertSame(['Course Total'], $result['columns']);
        $this->assertCount(2, $result['rows']);

        $joined = $result['rows'][0]['nilai_list'][0] . ' ' . $result['rows'][1]['nilai_list'][0];
        $this->assertStringContainsString('color:green', $joined);
        $this->assertStringContainsString('color:red', $joined);
    }
}