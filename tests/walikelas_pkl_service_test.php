<?php

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\pkl_service;

final class local_akademikmonitor_walikelas_pkl_service_test extends advanced_testcase {
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_get_pkl_siswa_returns_mitra_fields_and_fallback(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $tahunajaranid = $DB->insert_record('tahun_ajaran', (object)['tahun_ajaran' => '2025/2026']);
        $jurusanid = $DB->insert_record('jurusan', (object)['nama_jurusan' => 'RPL', 'kode_jurusan' => 10]);
        $kelasid = $DB->insert_record('kelas', (object)[
            'nama' => 'X RPL 1',
            'tingkat' => 'X',
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunajaranid,
            'id_user' => null,
        ]);
        $mitraid = $DB->insert_record('mitra_dudi', (object)[
            'nama' => 'PT Industri',
            'alamat' => 'Semarang',
            'kontak' => '08111',
            'is_active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('pkl', (object)[
            'id_siswa' => $student->id,
            'id_kelas' => $kelasid,
            'semester' => 1,
            'id_mitra_dudi' => $mitraid,
            'waktu_mulai' => '2026-01-10',
            'waktu_selesai' => '2026-03-10',
            'nilai' => 'A',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('pkl', (object)[
            'id_siswa' => $student->id,
            'id_kelas' => $kelasid,
            'semester' => 2,
            'id_mitra_dudi' => 99999,
            'waktu_mulai' => '2026-04-10',
            'waktu_selesai' => '2026-06-10',
            'nilai' => 'B',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $rows = pkl_service::get_pkl_siswa((int)$student->id);

        $this->assertCount(2, $rows);
        $this->assertSame('PT Industri', $rows[0]->nama);
        $this->assertSame('Semarang', $rows[0]->alamat);
        $this->assertSame('08111', $rows[0]->kontak);
        $this->assertSame('-', $rows[1]->nama);
        $this->assertSame('-', $rows[1]->alamat);
        $this->assertSame('-', $rows[1]->kontak);
    }

    public function test_save_inserts_new_record_and_updates_existing_record(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $tahunajaranid = $DB->insert_record('tahun_ajaran', (object)['tahun_ajaran' => '2025/2026']);
        $jurusanid = $DB->insert_record('jurusan', (object)['nama_jurusan' => 'RPL', 'kode_jurusan' => 10]);
        $kelasid = $DB->insert_record('kelas', (object)[
            'nama' => 'XI RPL 2',
            'tingkat' => 'XI',
            'id_jurusan' => $jurusanid,
            'id_tahun_ajaran' => $tahunajaranid,
            'id_user' => null,
        ]);
        $mitra1 = $DB->insert_record('mitra_dudi', (object)[
            'nama' => 'PT Awal', 'alamat' => 'A', 'kontak' => '1', 'is_active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $mitra2 = $DB->insert_record('mitra_dudi', (object)[
            'nama' => 'PT Baru', 'alamat' => 'B', 'kontak' => '2', 'is_active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        pkl_service::save((int)$student->id, (int)$kelasid, (int)$mitra1, 1, '2026-01-01', '2026-02-01', 'B');

        $record = $DB->get_record('pkl', ['id_siswa' => $student->id, 'id_kelas' => $kelasid, 'semester' => 1], '*', MUST_EXIST);
        $this->assertSame((int)$mitra1, (int)$record->id_mitra_dudi);
        $this->assertSame('B', $record->nilai);

        pkl_service::save((int)$student->id, (int)$kelasid, (int)$mitra2, 1, '2026-03-01', '2026-04-01', 'A');

        $updated = $DB->get_record('pkl', ['id' => $record->id], '*', MUST_EXIST);
        $this->assertSame((int)$mitra2, (int)$updated->id_mitra_dudi);
        $this->assertSame('2026-03-01', $updated->waktu_mulai);
        $this->assertSame('2026-04-01', $updated->waktu_selesai);
        $this->assertSame('A', $updated->nilai);
        $this->assertSame(1, $DB->count_records('pkl', ['id_siswa' => $student->id, 'id_kelas' => $kelasid, 'semester' => 1]));
    }
}
