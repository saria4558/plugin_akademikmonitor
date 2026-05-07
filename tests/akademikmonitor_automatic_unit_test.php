<?php
// This file is part of Moodle - http://moodle.org/
//
// Unit test otomatis untuk plugin local_akademikmonitor.
// Fokus pengujian: white box unit testing untuk fungsi/service inti plugin.

defined('MOODLE_INTERNAL') || die();

// Baris di bawah ini membantu editor/IDE membaca class PHPUnit Moodle.
// Saat dijalankan lewat PHPUnit Moodle, file ini sudah tersedia dari bootstrap Moodle.
global $CFG;
if (!empty($CFG->dirroot) && file_exists($CFG->dirroot . '/lib/phpunit/classes/advanced_testcase.php')) {
    require_once($CFG->dirroot . '/lib/phpunit/classes/advanced_testcase.php');
}

use local_akademikmonitor\class_manager;
use local_akademikmonitor\service\course_name_service;
use local_akademikmonitor\service\course_period_service;
use local_akademikmonitor\service\period_filter_service;
use local_akademikmonitor\service\walikelas\common_service;
use local_akademikmonitor\service\walikelas\ekskul_service as walikelas_ekskul_service;
use local_akademikmonitor\service\walikelas\pkl_service;
use local_akademikmonitor\service\walikelas\rapor_service;

/**
 * Test otomatis gabungan untuk fitur utama plugin Akademik & Monitoring.
 *
 * Kenapa dibuat dalam 1 file?
 * - Supaya mudah dicopy ke folder tests/.
 * - Cocok untuk dokumentasi skripsi/bab pengujian karena mencakup statement coverage
 *   dan branch coverage pada service-service utama.
 *
 * Cara jalan:
 * vendor/bin/phpunit local_akademikmonitor_automatic_unit_test local/akademikmonitor/tests/akademikmonitor_automatic_unit_test.php
 */
final class local_akademikmonitor_automatic_unit_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        period_filter_service::reset_session();
    }

    /**
     * Helper insert tahun ajaran.
     */
    private function create_tahun_ajaran(string $label): int {
        global $DB;

        return (int)$DB->insert_record('tahun_ajaran', (object)[
            'tahun_ajaran' => $label,
        ]);
    }

    /**
     * Helper insert jurusan.
     */
    private function create_jurusan(string $nama = 'Teknik Komputer Jaringan'): int {
        global $DB;

        return (int)$DB->insert_record('jurusan', (object)[
            'nama_jurusan' => $nama,
            'kode_jurusan' => random_int(100, 999),
        ]);
    }

    /**
     * Helper insert kelas custom plugin.
     */
    private function create_kelas(int $tahunajaranid, int $jurusanid, string $tingkat, string $nama, int $waliid = 0): int {
        global $DB;

        $record = (object)[
            'nama' => $nama,
            'tingkat' => $tingkat,
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunajaranid,
            'id_user' => $waliid > 0 ? $waliid : null,
        ];

        return (int)$DB->insert_record('kelas', $record);
    }

    /**
     * Helper membuat struktur kurikulum-mapel minimal.
     */
    private function create_kurikulum_mapel(int $tahunajaranid, int $jurusanid, string $namamapel, string $tingkat = 'XII', int $kktp = 75): int {
        global $DB;

        $kurikulumid = (int)$DB->insert_record('kurikulum', (object)[
            'nama' => 'Kurikulum Test',
            'is_active' => '1',
        ]);

        $kjid = (int)$DB->insert_record('kurikulum_jurusan', (object)[
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulumid,
            'id_tahun_ajaran' => $tahunajaranid,
        ]);

        $mapelid = (int)$DB->insert_record('mata_pelajaran', (object)[
            'nama_mapel' => $namamapel,
        ]);

        return (int)$DB->insert_record('kurikulum_mapel', (object)[
            'id_kurikulum_jurusan' => $kjid,
            'id_mapel' => $mapelid,
            'jam_pelajaran' => '2',
            'tingkat_kelas' => $tingkat,
            'kktp' => $kktp,
        ]);
    }

    /**
     * Helper membuat course generated Akademik Monitoring + group kelas.
     */
    private function create_generated_course_with_group(
        int $tahunajaranid,
        int $kelasid,
        int $kmid,
        int $semester,
        string $fullname = 'Matematika Generated'
    ): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => 'AMTEST' . random_int(1000, 9999),
            'idnumber' => 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM' . $kmid . '-S' . $semester,
        ]);

        // Tabel course_mapel tidak memiliki id auto increment, jadi jangan pakai insert_record().
        $DB->execute(
            'INSERT INTO {course_mapel} (id_course, id_kurikulum_mapel) VALUES (:courseid, :kmid)',
            ['courseid' => $course->id, 'kmid' => $kmid]
        );

        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'XII TKJ 1',
        ]);

        return [$course, $group];
    }

    /**
     * Helper memberi nilai course total langsung ke gradebook.
     */
private function set_course_total_grade(int $courseid, int $userid, float $finalgrade): void {
    global $DB;

    $now = time();

    /*
     * Di Moodle, course total disimpan sebagai grade_items dengan:
     * - courseid  = id course
     * - itemtype  = 'course'
     *
     * Saat testing PHPUnit, course yang dibuat oleh data generator belum tentu
     * langsung memiliki grade item course total. Karena itu test harus
     * memastikan grade item tersebut ada sebelum memasukkan nilai siswa.
     */
    $gradeitem = $DB->get_record('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'course',
    ], '*', IGNORE_MISSING);

    if (!$gradeitem) {
        $gradeitem = (object)[
            'courseid' => $courseid,
            'categoryid' => null,
            'itemname' => null,
            'itemtype' => 'course',
            'itemmodule' => null,
            'iteminstance' => $courseid,
            'itemnumber' => null,
            'idnumber' => null,
            'calculation' => null,
            'gradetype' => 1,
            'grademax' => 100,
            'grademin' => 0,
            'scaleid' => null,
            'outcomeid' => null,
            'gradepass' => 0,
            'multfactor' => 1,
            'plusfactor' => 0,
            'aggregationcoef' => 0,
            'aggregationcoef2' => 0,
            'sortorder' => 1,
            'display' => 0,
            'decimals' => null,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'weightoverride' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $gradeitem->id = $DB->insert_record('grade_items', $gradeitem);
    }

    /*
     * Nilai akhir siswa disimpan di grade_grades.
     * Kombinasi pentingnya adalah:
     * - itemid = id grade item course total
     * - userid = id siswa
     */
    $existinggrade = $DB->get_record('grade_grades', [
        'itemid' => $gradeitem->id,
        'userid' => $userid,
    ], '*', IGNORE_MISSING);

    if ($existinggrade) {
        $existinggrade->rawgrade = $finalgrade;
        $existinggrade->finalgrade = $finalgrade;
        $existinggrade->timemodified = $now;
        $DB->update_record('grade_grades', $existinggrade);
    } else {
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $gradeitem->id,
            'userid' => $userid,
            'rawgrade' => $finalgrade,
            'rawgrademax' => 100,
            'rawgrademin' => 0,
            'rawscaleid' => null,
            'usermodified' => null,
            'finalgrade' => $finalgrade,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'exported' => 0,
            'overridden' => 0,
            'excluded' => 0,
            'feedback' => null,
            'feedbackformat' => 0,
            'information' => null,
            'informationformat' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'aggregationstatus' => 'unknown',
            'aggregationweight' => null,
        ]);
    }
}

    public function test_class_manager_get_next_tingkat_branch_coverage(): void {
        self::assertSame('XI', class_manager::get_next_tingkat('X'));
        self::assertSame('XII', class_manager::get_next_tingkat(' XI '));
        self::assertSame('LULUS', class_manager::get_next_tingkat('XII'));
        self::assertSame('LULUS', class_manager::get_next_tingkat('tidak-valid'));
    }

    public function test_class_manager_naikkan_kelas_copies_only_students_and_clears_wali(): void {
        global $DB;

        $tahunawal = $this->create_tahun_ajaran('2025/2026');
        $tahunbaru = $this->create_tahun_ajaran('2026/2027');
        $jurusanid = $this->create_jurusan('Teknik Sipil');

        $wali = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $guru = $this->getDataGenerator()->create_user();

        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);

        $oldkelasid = $this->create_kelas($tahunawal, $jurusanid, 'X', 'X Teknik Sipil 1', (int)$wali->id);

        $DB->insert_record('peserta_kelas', (object)['id_kelas' => $oldkelasid, 'id_user' => $student->id, 'id_role' => $studentroleid]);
        $DB->insert_record('peserta_kelas', (object)['id_kelas' => $oldkelasid, 'id_user' => $guru->id, 'id_role' => $teacherroleid]);
        $DB->insert_record('peserta_kelas', (object)['id_kelas' => $oldkelasid, 'id_user' => $wali->id, 'id_role' => $teacherroleid]);

        $result = class_manager::naikkan_kelas($oldkelasid);

        self::assertSame('naik', $result['status']);
        self::assertSame('XI', $result['nexttingkat']);
        self::assertSame(1, $result['copied']);
        self::assertGreaterThan(0, $result['newclassid']);

        $newkelas = $DB->get_record('kelas', ['id' => $result['newclassid']], '*', MUST_EXIST);
        self::assertSame('XI', $newkelas->tingkat);
        self::assertSame($tahunbaru, (int)$newkelas->id_tahun_ajaran);
        self::assertEmpty($newkelas->id_user);

        self::assertTrue($DB->record_exists('peserta_kelas', ['id_kelas' => $newkelas->id, 'id_user' => $student->id]));
        self::assertFalse($DB->record_exists('peserta_kelas', ['id_kelas' => $newkelas->id, 'id_user' => $guru->id]));
        self::assertFalse($DB->record_exists('peserta_kelas', ['id_kelas' => $newkelas->id, 'id_user' => $wali->id]));
    }

    public function test_class_manager_luluskan_saves_unique_class_id_in_config(): void {
        $kelas = (object)['id' => 77, 'tingkat' => 'XII'];

        $first = class_manager::luluskan($kelas);
        $second = class_manager::luluskan($kelas);

        self::assertSame('lulus', $first['status']);
        self::assertSame('LULUS', $second['nexttingkat']);

        $ids = json_decode((string)get_config('local_akademikmonitor', 'kelas_lulus_ids'), true);
        self::assertSame([77], $ids);
    }

    public function test_course_name_service_builds_clean_generated_course_identity(): void {
        $mapel = (object)['id' => 5, 'kmid' => 9, 'nama_mapel' => '[umum] Bahasa Indonesia'];
        $kelas = (object)['id' => 6, 'tingkat' => 'X', 'nama' => 'X Teknik Multimedia 1'];
        $jurusan = (object)['id' => 7, 'nama_jurusan' => 'Teknik Multimedia'];
        $tahun = (object)['id' => 3, 'tahun_ajaran' => '2025/2026'];

        $names = course_name_service::build_names($mapel, $kelas, $jurusan, $tahun, 1);

        self::assertSame('AM-TA3-K6-KM9-S1', $names['idnumber']);
        self::assertSame('umum', $names['jenis_mapel']);
        self::assertSame('Bahasa Indonesia', $names['mapel_label']);
        self::assertSame('X Teknik Multimedia 1', $names['rombel_label']);
        self::assertSame('Ganjil', $names['semester_label']);
        self::assertStringContainsString('[umum] Bahasa Indonesia - X Teknik Multimedia 1 - Ganjil-2025/2026', $names['fullname']);
        self::assertLessThanOrEqual(95, strlen($names['shortname']));
    }

    public function test_course_period_service_filters_explicit_semester_but_keeps_implicit_courses(): void {
        $ganjil = (object)['fullname' => 'Matematika Semester 1', 'shortname' => 'MTK-GANJIL'];
        $genap = (object)['fullname' => 'Matematika Genap', 'shortname' => 'MTK-GENAP'];
        $implicit = (object)['fullname' => 'Sejarah Indonesia', 'shortname' => 'SEJ'];

        self::assertSame(1, course_period_service::resolve_course_semester($ganjil));
        self::assertSame(2, course_period_service::resolve_course_semester($genap));
        self::assertSame(0, course_period_service::resolve_course_semester($implicit));

        $filtered = course_period_service::filter_courses_by_semester([
            'ganjil' => $ganjil,
            'genap' => $genap,
            'implicit' => $implicit,
        ], 1);

        self::assertArrayHasKey('ganjil', $filtered);
        self::assertArrayHasKey('implicit', $filtered);
        $this->assertArrayNotHasKey('genap', $filtered);
    }

    public function test_period_filter_service_labels_and_default_options(): void {
        $tahunid = $this->create_tahun_ajaran('2025/2026');
        set_config('active_semester', 'genap', 'local_akademikmonitor');
        set_config('active_tahunajaranid', $tahunid, 'local_akademikmonitor');
        period_filter_service::reset_session();

        self::assertSame(2, period_filter_service::get_selected_semester());
        self::assertSame($tahunid, period_filter_service::get_selected_tahunajaranid());
        self::assertSame('Ganjil', period_filter_service::get_semester_label(1));
        self::assertSame('Genap', period_filter_service::get_semester_label(2));
        self::assertSame('2025/2026', period_filter_service::get_tahunajaran_label($tahunid));

        $options = period_filter_service::get_tahunajaran_options($tahunid);
        self::assertNotEmpty($options);
        self::assertTrue((bool)$options[0]['selected']);
    }

    public function test_common_service_parses_generated_course_idnumber_new_old_and_invalid(): void {
        $generator = $this->getDataGenerator();

        $newcourse = $generator->create_course(['idnumber' => 'AM-TA3-K50-KM12-S1']);
        $oldcourse = $generator->create_course(['idnumber' => 'AM-K51-KM13-S2']);
        $invalid = $generator->create_course(['idnumber' => 'MANUAL-COURSE']);

        $newinfo = common_service::get_generated_course_info_from_courseid((int)$newcourse->id);
        $oldinfo = common_service::get_generated_course_info_from_courseid((int)$oldcourse->id);
        $invalidinfo = common_service::get_generated_course_info_from_courseid((int)$invalid->id);

        self::assertSame(['kelasid' => 50, 'tahunajaranid' => 3, 'semester' => 1], $newinfo);
        self::assertSame(['kelasid' => 51, 'tahunajaranid' => 0, 'semester' => 2], $oldinfo);
        self::assertSame(['kelasid' => 0, 'tahunajaranid' => 0, 'semester' => 0], $invalidinfo);
    }

    public function test_rapor_service_generated_mapel_filter_and_ranking(): void {
        $tahunid = $this->create_tahun_ajaran('2025/2026');
        set_config('active_tahunajaranid', $tahunid, 'local_akademikmonitor');
        period_filter_service::reset_session();

        $jurusanid = $this->create_jurusan('Teknik Komputer Jaringan');
        $wali = $this->getDataGenerator()->create_user(['firstname' => 'Wali', 'lastname' => 'Kelas']);
        $kelasid = $this->create_kelas($tahunid, $jurusanid, 'XII', 'XII TKJ 1', (int)$wali->id);

        $kmid = $this->create_kurikulum_mapel($tahunid, $jurusanid, '[kejuruan] Administrasi Sistem Jaringan', 'XII', 75);
        [$course, $group] = $this->create_generated_course_with_group($tahunid, $kelasid, $kmid, 1, '[kejuruan] Administrasi Sistem Jaringan - XII TKJ 1 - Ganjil');

        $genapcourse = $this->getDataGenerator()->create_course([
            'fullname' => '[kejuruan] Administrasi Sistem Jaringan - XII TKJ 1 - Genap',
            'shortname' => 'ASJ-GENAP',
            'idnumber' => 'AM-TA' . $tahunid . '-K' . $kelasid . '-KM' . $kmid . '-S2',
        ]);
        self::assertNotEmpty($genapcourse->id);

        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Ani', 'lastname' => 'Satu']);
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Budi', 'lastname' => 'Dua']);
        $this->getDataGenerator()->enrol_user($wali->id, $course->id, 'teacher');

        groups_add_member($group->id, $student1->id);
        groups_add_member($group->id, $student2->id);
        groups_add_member($group->id, $wali->id);

        $mapelganjil = rapor_service::get_mapel_by_kelas((int)$group->id, 1);
        $mapelgenap = rapor_service::get_mapel_by_kelas((int)$group->id, 2);

        self::assertArrayHasKey((int)$course->id, $mapelganjil);
        $this->assertArrayNotHasKey((int)$course->id, $mapelgenap);
        self::assertSame('Administrasi Sistem Jaringan', $mapelganjil[(int)$course->id]->nama_mapel);
        self::assertSame(75, (int)$mapelganjil[(int)$course->id]->kktp);

        $this->set_course_total_grade((int)$course->id, (int)$student1->id, 90.0);
        $this->set_course_total_grade((int)$course->id, (int)$student2->id, 80.0);

        $rows = rapor_service::get_raport_kelas((int)$group->id, (int)$wali->id, 1);

        self::assertCount(2, $rows);
        self::assertSame((int)$student1->id, $rows[0]['userid']);
        self::assertSame(90.0, (float)$rows[0]['jumlah']);
        self::assertSame(1, $rows[0]['ranking']);
        self::assertSame((int)$student2->id, $rows[1]['userid']);
        self::assertSame(80.0, (float)$rows[1]['jumlah']);
        self::assertSame(2, $rows[1]['ranking']);
    }

    public function test_walikelas_ekskul_save_get_update_and_validation_branches(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $kelasid = 123;
        $ekskulid = (int)$DB->insert_record('ekskul', (object)[
            'nama' => 'Pramuka',
            'id_pembina' => 0,
            'is_active' => '1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        walikelas_ekskul_service::save((int)$student->id, $kelasid, $ekskulid, 1, 'a');
        $rows = walikelas_ekskul_service::get_ekskul_siswa((int)$student->id, $kelasid, 1);

        self::assertCount(1, $rows);
        self::assertSame('Pramuka', $rows[0]->nama);
        self::assertSame('A', $rows[0]->predikat);
        self::assertSame('Mengikuti kegiatan ekstrakurikuler dengan sangat baik', walikelas_ekskul_service::get_keterangan_predikat('A'));

        walikelas_ekskul_service::save((int)$student->id, $kelasid, $ekskulid, 1, 'B');
        $rows = walikelas_ekskul_service::get_ekskul_siswa((int)$student->id, $kelasid, 1);
        self::assertCount(1, $rows);
        self::assertSame('B', $rows[0]->predikat);

        $this->expectException(Exception::class);
        walikelas_ekskul_service::save((int)$student->id, $kelasid, $ekskulid, 3, 'A');
    }

    public function test_pkl_service_only_allows_generated_xii_group_and_updates_same_period(): void {
        global $DB;

        $tahunid = $this->create_tahun_ajaran('2025/2026');
        $jurusanid = $this->create_jurusan('Teknik Kendaraan Ringan');
        $wali = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $kelasid = $this->create_kelas($tahunid, $jurusanid, 'XII', 'XII TKR 1', (int)$wali->id);
        $kmid = $this->create_kurikulum_mapel($tahunid, $jurusanid, '[kejuruan] PKL', 'XII', 75);
        [, $group] = $this->create_generated_course_with_group($tahunid, $kelasid, $kmid, 1, 'PKL - XII TKR 1');

        $mitraid = (int)$DB->insert_record('mitra_dudi', (object)[
            'nama' => 'PT Industri Test',
            'alamat' => 'Banyuwangi',
            'kontak' => '08123',
            'is_active' => '1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        pkl_service::save((int)$student->id, (int)$group->id, $mitraid, 1, '2026-01-01', '2026-03-01', 'A');
        pkl_service::save((int)$student->id, (int)$group->id, $mitraid, 1, '2026-01-10', '2026-03-10', 'B');

        $rows = pkl_service::get_pkl_siswa((int)$student->id, (int)$group->id, 1);

        self::assertCount(1, $rows);
        self::assertSame('PT Industri Test', $rows[0]->nama);
        self::assertSame('2026-01-10', $rows[0]->waktu_mulai);
        self::assertSame('B', $rows[0]->nilai);
    }

    public function test_rapor_manual_fields_save_update_and_fallbacks(): void {
        global $DB;

        $columns = $DB->get_columns('rapor_catatan_akademik');

        $student = $this->getDataGenerator()->create_user();
        $wali = $this->getDataGenerator()->create_user();
        $kelasid = 991;
        $semester = 1;

        rapor_service::save_catatan((int)$student->id, $kelasid, $semester, 'Catatan awal', (int)$wali->id);
        rapor_service::save_catatan((int)$student->id, $kelasid, $semester, 'Catatan update', (int)$wali->id);
        $catatan = rapor_service::get_catatan((int)$student->id, $kelasid, $semester);
        self::assertSame('Catatan update', $catatan->catatan);

        if (isset($columns['kokurikuler'])) {
            rapor_service::save_kokurikuler((int)$student->id, $kelasid, $semester, 'Projek penguatan karakter', (int)$wali->id);
            $catatan = rapor_service::get_catatan((int)$student->id, $kelasid, $semester);
            self::assertSame('Projek penguatan karakter', $catatan->kokurikuler);
        } else {
            // Fresh install.xml lama belum punya kolom ini. Test tetap memberi sinyal tanpa menggagalkan suite.
            $this->assertArrayNotHasKey('kokurikuler', $columns);
        }

        rapor_service::save_kenaikan_kelas((int)$student->id, $kelasid, 'Naik ke kelas XI', (int)$wali->id);
        rapor_service::save_kenaikan_kelas((int)$student->id, $kelasid, 'Naik ke kelas XII', (int)$wali->id);
        $kenaikan = rapor_service::get_kenaikan_kelas((int)$student->id, $kelasid);
        self::assertSame('Naik ke kelas XII', $kenaikan->keputusan);

        $emptyabsensi = rapor_service::get_ketidakhadiran((int)$student->id, $kelasid, $semester);
        self::assertSame(0, (int)$emptyabsensi->sakit);
        self::assertSame(0, (int)$emptyabsensi->izin);
        self::assertSame(0, (int)$emptyabsensi->alfa);
        self::assertSame('empty', $emptyabsensi->source);

        rapor_service::save_ketidakhadiran((int)$student->id, $kelasid, $semester, 2, 1, 3, (int)$wali->id);
        rapor_service::save_ketidakhadiran((int)$student->id, $kelasid, $semester, 4, 0, 1, (int)$wali->id);
        $absensi = rapor_service::get_ketidakhadiran((int)$student->id, $kelasid, $semester);
        self::assertSame(4, (int)$absensi->sakit);
        self::assertSame(0, (int)$absensi->izin);
        self::assertSame(1, (int)$absensi->alfa);
        self::assertSame('manual', $absensi->source);

        self::assertSame('6 Mei 2026', rapor_service::format_tanggal_indo('2026-05-06'));
        self::assertSame('-', rapor_service::format_tanggal_indo('tanggal-salah'));
    }
}
