<?php
defined('MOODLE_INTERNAL') || die();

// use local_akademikmonitor\service\walikelas\common_service;

use local_akademikmonitor\service\walikelas\common_service;

final class local_akademikmonitor_walikelas_common_service_test extends advanced_testcase {
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_get_group_walikelas_deduplicates_same_name_and_returns_smallest_id(): void {
        $generator = $this->getDataGenerator();

        $course1 = $generator->create_course(['fullname' => 'Kelas 10 RPL A']);
        $course2 = $generator->create_course(['fullname' => 'Kelas 10 RPL B']);

        $teacher = $generator->create_user();

        $generator->enrol_user($teacher->id, $course1->id, null);
        $generator->enrol_user($teacher->id, $course2->id, null);

        $group1 = $generator->create_group(['courseid' => $course1->id, 'name' => 'X RPL 1']);
        $group2 = $generator->create_group(['courseid' => $course2->id, 'name' => 'X RPL 1']);
        $group3 = $generator->create_group(['courseid' => $course2->id, 'name' => 'X RPL 2']);

        groups_add_member($group1->id, $teacher->id);
        groups_add_member($group2->id, $teacher->id);
        groups_add_member($group3->id, $teacher->id);

        $groups = common_service::get_group_walikelas((int)$teacher->id);

        $this->assertCount(2, $groups);

        $smallestduplicateid = min($group1->id, $group2->id);
        $this->assertArrayHasKey($smallestduplicateid, $groups);
        $this->assertSame('X RPL 1', $groups[$smallestduplicateid]->name);

        $groupnames = array_map(static fn($g) => $g->name, $groups);
        $this->assertContains('X RPL 2', $groupnames);

        $first = common_service::get_first_group_walikelas((int)$teacher->id);
        $this->assertNotNull($first);
        $this->assertSame((int)array_key_first($groups), (int)$first->id);
    }

    public function test_get_siswa_group_returns_only_active_students_and_excludes_wali(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'X TKJ 1']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student1 = $generator->create_and_enrol($course, 'student', ['firstname' => 'Ani']);
        $student2 = $generator->create_and_enrol($course, 'student', ['firstname' => 'Budi']);
        $nonstudent = $generator->create_and_enrol($course, 'teacher', ['firstname' => 'GuruMapel']);
        $suspended = $generator->create_and_enrol($course, 'student', ['firstname' => 'Suspended', 'suspended' => 1]);

        groups_add_member($group->id, $teacher->id);
        groups_add_member($group->id, $student1->id);
        groups_add_member($group->id, $student2->id);
        groups_add_member($group->id, $nonstudent->id);
        groups_add_member($group->id, $suspended->id);

        $members = common_service::get_siswa_group((int)$group->id, (int)$teacher->id);

        $this->assertCount(2, $members);
        $this->assertArrayHasKey((int)$student1->id, $members);
        $this->assertArrayHasKey((int)$student2->id, $members);
        $this->assertArrayNotHasKey((int)$teacher->id, $members);
        $this->assertArrayNotHasKey((int)$nonstudent->id, $members);
        $this->assertArrayNotHasKey((int)$suspended->id, $members);
    }

    public function test_get_sidebar_data_marks_active_menu_and_contains_urls(): void {
        $data = common_service::get_sidebar_data('pkl');

        $this->assertFalse($data['is_dashboard']);
        $this->assertTrue($data['is_pkl_siswa']);
        $this->assertStringContainsString('/local/akademikmonitor/pages/walikelas/pkl/pkl.php', $data['pkl_siswa_url']);
        $this->assertStringContainsString('/local/akademikmonitor/pages/walikelas/rapor/index.php', $data['raport_url']);
    }

    public function test_get_nisn_map_by_userids_returns_expected_mapping(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $categoryid = $DB->insert_record('user_info_category', (object)[
            'name' => 'Testing',
            'sortorder' => 1,
        ]);

        $fieldid = $DB->insert_record('user_info_field', (object)[
            'shortname' => 'nisn',
            'name' => 'NISN',
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => 0,
            'categoryid' => $categoryid,
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => 2,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => 0,
            'param1' => '',
            'param2' => '',
            'param3' => '',
            'param4' => '',
            'param5' => '',
        ]);

        $DB->insert_record('user_info_data', (object)['userid' => $user1->id, 'fieldid' => $fieldid, 'data' => '12345', 'dataformat' => 0]);
        $DB->insert_record('user_info_data', (object)['userid' => $user2->id, 'fieldid' => $fieldid, 'data' => '67890', 'dataformat' => 0]);

        $map = common_service::get_nisn_map_by_userids([(int)$user1->id, (int)$user2->id]);

        $this->assertSame('12345', $map[(int)$user1->id]);
        $this->assertSame('67890', $map[(int)$user2->id]);
    }
}
