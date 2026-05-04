<?php

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\ekskul_service;

final class local_akademikmonitor_ekskul_service_test extends advanced_testcase {
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_list_ekskul_returns_empty_array_when_no_data(): void {
        global $DB;

        // Hapus seed/default data supaya test benar-benar menguji kondisi kosong.
        $DB->delete_records('ekskul');

        $result = ekskul_service::list_ekskul();

        $this->assertSame([], $result);
    }

    public function test_list_ekskul_formats_pembina_and_status_fields(): void {
        global $DB;

        // Hapus seed/default data supaya jumlah hasil sesuai data test ini saja.
        $DB->delete_records('ekskul');

        $pembina = $this->getDataGenerator()->create_user([
            'firstname' => 'Siti',
            'lastname' => 'Aminah',
        ]);

        $idaktif = $DB->insert_record('ekskul', (object)[
            'nama' => 'Pramuka',
            'id_pembina' => $pembina->id,
            'is_active' => '1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $idfallback = $DB->insert_record('ekskul', (object)[
            'nama' => 'PMR',
            'id_pembina' => 999999,
            'is_active' => '0',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $rows = array_values(ekskul_service::list_ekskul());

        $this->assertCount(2, $rows);

        $this->assertSame((int)$idaktif, $rows[0]['id']);
        $this->assertSame('Pramuka', $rows[0]['nama']);
        $this->assertSame('Siti Aminah', $rows[0]['pembina']);
        $this->assertTrue($rows[0]['is_enabled']);
        $this->assertSame('on', $rows[0]['badge_class']);
        $this->assertSame('aktif', $rows[0]['badge_text']);
        $this->assertSame('nonaktif', $rows[0]['toggle_text']);

        $this->assertSame((int)$idfallback, $rows[1]['id']);
        $this->assertSame('PMR', $rows[1]['nama']);
        $this->assertSame('User ID: 999999', $rows[1]['pembina']);
        $this->assertFalse($rows[1]['is_enabled']);
        $this->assertSame('off', $rows[1]['badge_class']);
        $this->assertSame('nonaktif', $rows[1]['badge_text']);
        $this->assertSame('aktif', $rows[1]['toggle_text']);
    }

    public function test_list_pembina_options_returns_only_active_users_sorted_by_label(): void {
        global $DB;

        $roleid = $DB->insert_record('role', (object)[
            'name' => 'Pembina Ekskul',
            'shortname' => 'pembinaekskul',
            'description' => '',
            'sortorder' => 501,
            'archetype' => '',
        ]);

        $systemcontext = context_system::instance();

        $userb = $this->getDataGenerator()->create_user([
            'username' => 'beta',
            'firstname' => 'Beta',
            'lastname' => 'Teacher',
        ]);
        $usera = $this->getDataGenerator()->create_user([
            'username' => 'alpha',
            'firstname' => 'Alpha',
            'lastname' => 'Teacher',
        ]);
        $deleted = $this->getDataGenerator()->create_user([
            'username' => 'deletedteacher',
            'firstname' => 'Deleted',
            'lastname' => 'Teacher',
        ]);
        $suspended = $this->getDataGenerator()->create_user([
            'username' => 'suspendedteacher',
            'firstname' => 'Suspended',
            'lastname' => 'Teacher',
            'suspended' => 1,
        ]);

        role_assign($roleid, $userb->id, $systemcontext->id);
        role_assign($roleid, $usera->id, $systemcontext->id);
        role_assign($roleid, $deleted->id, $systemcontext->id);
        role_assign($roleid, $suspended->id, $systemcontext->id);

        // User dihapus setelah role terpasang, supaya tidak error saat role_assign().
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);

        $options = ekskul_service::list_pembina_options((int)$roleid);

        $this->assertCount(2, $options);
        $this->assertSame((int)$usera->id, $options[0]['id']);
        $this->assertStringStartsWith('Alpha Teacher', $options[0]['label']);
        $this->assertSame((int)$userb->id, $options[1]['id']);
        $this->assertStringStartsWith('Beta Teacher', $options[1]['label']);
    }

    public function test_create_update_and_toggle_persist_expected_changes(): void {
        global $DB;

        $pembina = $this->getDataGenerator()->create_user();
        $newpembina = $this->getDataGenerator()->create_user();

        $id = ekskul_service::create('Karawitan', (int)$pembina->id);

        $created = $DB->get_record('ekskul', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Karawitan', $created->nama);
        $this->assertSame((int)$pembina->id, (int)$created->id_pembina);
        $this->assertSame('1', (string)$created->is_active);

        ekskul_service::update($id, 'Karate', (int)$newpembina->id);
        $updated = $DB->get_record('ekskul', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Karate', $updated->nama);
        $this->assertSame((int)$newpembina->id, (int)$updated->id_pembina);

        ekskul_service::toggle($id);
        $this->assertSame('0', (string)$DB->get_field('ekskul', 'is_active', ['id' => $id]));

        ekskul_service::toggle($id);
        $this->assertSame('1', (string)$DB->get_field('ekskul', 'is_active', ['id' => $id]));
    }

    public function test_parse_pembina_input_handles_empty_numeric_text_and_fallback(): void {
        $matched = $this->getDataGenerator()->create_user([
            'username' => 'pembina.seni',
            'firstname' => 'Seni',
            'lastname' => 'Budaya',
        ]);

        $this->assertSame(1, ekskul_service::parse_pembina_input(''));
        $this->assertSame(25, ekskul_service::parse_pembina_input('25'));
        $this->assertSame((int)$matched->id, ekskul_service::parse_pembina_input('seni'));
        $this->assertSame(1, ekskul_service::parse_pembina_input('tidak-ada-user-cocok'));
    }
}