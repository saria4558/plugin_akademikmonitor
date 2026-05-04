<?php

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\mitra_service;

final class local_akademikmonitor_mitra_service_test extends advanced_testcase {
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_create_trims_input_and_defaults_to_active(): void {
        global $DB;

        $id = mitra_service::create('  PT Maju  ', '  Jakarta  ', '  08123  ');
        $record = $DB->get_record('mitra_dudi', ['id' => $id], '*', MUST_EXIST);

        $this->assertSame('PT Maju', $record->nama);
        $this->assertSame('Jakarta', $record->alamat);
        $this->assertSame('08123', $record->kontak);
        $this->assertSame('1', (string)$record->is_active);
    }

    public function test_create_throws_exception_when_name_empty(): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Nama mitra wajib diisi');

        mitra_service::create('   ');
    }

    public function test_list_mitra_filters_active_and_archived_rows(): void {
        global $DB;

        $activeid = $DB->insert_record('mitra_dudi', (object)[
            'nama' => 'Active Co',
            'alamat' => 'Bandung',
            'kontak' => '111',
            'is_active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $archivedid = $DB->insert_record('mitra_dudi', (object)[
            'nama' => 'Archived Co',
            'alamat' => 'Surabaya',
            'kontak' => '222',
            'is_active' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $active = array_values(mitra_service::list_mitra('active'));
        $archived = array_values(mitra_service::list_mitra('archived'));

        $activeids = array_column($active, 'id');
        $archivedids = array_column($archived, 'id');

        $this->assertContains((int)$activeid, $activeids);
        $this->assertContains((int)$archivedid, $archivedids);

        foreach ($active as $row) {
            $this->assertSame(1, (int)$row['is_active']);
            $this->assertSame('Aktif', $row['badge_text']);
            $this->assertSame('Arsipkan', $row['toggle_text']);
        }

        foreach ($archived as $row) {
            $this->assertSame(0, (int)$row['is_active']);
            $this->assertSame('Diarsipkan', $row['badge_text']);
            $this->assertSame('Aktifkan', $row['toggle_text']);
        }
    }

    public function test_update_and_toggle_change_existing_record(): void {
        global $DB;

        $id = mitra_service::create('PT Awal', 'Alamat Awal', '0800');

        mitra_service::update($id, 'PT Baru', 'Alamat Baru', '0899');
        $updated = $DB->get_record('mitra_dudi', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('PT Baru', $updated->nama);
        $this->assertSame('Alamat Baru', $updated->alamat);
        $this->assertSame('0899', $updated->kontak);

        $newstatus = mitra_service::toggle($id);
        $this->assertSame(0, $newstatus);
        $this->assertSame('0', (string)$DB->get_field('mitra_dudi', 'is_active', ['id' => $id]));

        $newstatus = mitra_service::toggle($id);
        $this->assertSame(1, $newstatus);
        $this->assertSame('1', (string)$DB->get_field('mitra_dudi', 'is_active', ['id' => $id]));
    }

    public function test_import_counts_success_failures_and_duplicate_detection(): void {
        global $DB;

        $DB->insert_record('mitra_dudi', (object)[
            'nama' => 'PT Existing',
            'alamat' => 'Bogor',
            'kontak' => '000',
            'is_active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = mitra_service::import([
            ['nama' => 'PT Satu', 'alamat' => 'A', 'kontak' => '1'],
            ['nama' => '', 'alamat' => 'B', 'kontak' => '2'],
            ['nama' => 'PT Dua', 'alamat' => 'C', 'kontak' => '3'],
            ['nama' => 'PT Dua', 'alamat' => 'D', 'kontak' => '4'],
            ['nama' => 'PT Existing', 'alamat' => 'E', 'kontak' => '5'],
        ]);

        $this->assertSame(2, $result['success']);
        $this->assertSame(3, $result['failed']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('Nama kosong', $result['errors'][0]);
        $this->assertStringContainsString('Duplikat nama di file', $result['errors'][1]);
        $this->assertStringContainsString('Mitra sudah ada', $result['errors'][2]);

        $this->assertTrue($DB->record_exists('mitra_dudi', ['nama' => 'PT Satu']));
        $this->assertTrue($DB->record_exists('mitra_dudi', ['nama' => 'PT Dua']));
    }
}
