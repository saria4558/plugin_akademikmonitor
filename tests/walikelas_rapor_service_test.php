<?php

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use local_akademikmonitor\service\walikelas\rapor_service;

final class local_akademikmonitor_walikelas_rapor_service_test extends advanced_testcase {
    protected function setUp(): void {
        
        $this->resetAfterTest(true);
    }

    public function test_get_mapel_by_kelas_returns_matching_courses_with_trimmed_names(): void {
        $generator = $this->getDataGenerator();
        $coursekelas = $generator->create_course(['fullname' => 'X RPL 1']);
        $group = $generator->create_group(['courseid' => $coursekelas->id, 'name' => 'X RPL 1']);
        $matching = $generator->create_course(['fullname' => 'Produktif X RPL', 'shortname' => 'PXRPL']);
        $generator->create_course(['fullname' => 'XI RPL Matematika', 'shortname' => 'XIRPL']);

        $rows = rapor_service::get_mapel_by_kelas((int)$group->id);

        $this->assertArrayHasKey((int)$matching->id, $rows);
        $this->assertSame('Produktif X RPL', $rows[$matching->id]->nama_mapel);
        $this->assertSame('PXRPL', $rows[$matching->id]->shortname);
    }

    public function test_get_primary_groupid_by_student_returns_first_membership(): void {
        global $CFG;

        require_once($CFG->dirroot . '/group/lib.php');

        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['fullname' => 'Kelas X RPL']);
        $student = $generator->create_and_enrol($course, 'student', ['firstname' => 'Budi']);

        $group1 = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'X RPL 1',
        ]);
        $group2 = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'X RPL 2',
        ]);

        groups_add_member($group1->id, $student->id);
        groups_add_member($group2->id, $student->id);

        $result = \local_akademikmonitor\service\walikelas\rapor_service::get_primary_groupid_by_student((int)$student->id);

        $this->assertSame((int)$group1->id, (int)$result);
    }
}
