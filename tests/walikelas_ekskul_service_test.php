<?php

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\walikelas\ekskul_service;

final class local_akademikmonitor_walikelas_ekskul_service_test extends advanced_testcase {
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_get_ekskul_siswa_returns_rows_with_master_name_and_fallback(): void {
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
        $knownid = $DB->insert_record('ekskul', (object)[
            'nama' => 'Basket',
            'id_pembina' => null,
            'is_active' => '1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('ekskul_rapor', (object)[
            'id_ekskul' => $knownid,
            'id_siswa' => $student->id,
            'id_kelas' => $kelasid,
            'semester' => 1,
            'predikat' => 'A',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('ekskul_rapor', (object)[
            'id_ekskul' => 99999,
            'id_siswa' => $student->id,
            'id_kelas' => $kelasid,
            'semester' => 2,
            'predikat' => 'B',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $rows = ekskul_service::get_ekskul_siswa((int)$student->id);

        $this->assertCount(2, $rows);
        $this->assertSame('Basket', $rows[0]->nama);
        $this->assertSame('A', $rows[0]->predikat);
        $this->assertSame('-', $rows[1]->nama);
        $this->assertSame('B', $rows[1]->predikat);
    }

    public function test_get_keterangan_predikat_maps_all_supported_values(): void {
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan sangat baik', ekskul_service::get_keterangan_predikat('A'));
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan baik', ekskul_service::get_keterangan_predikat('B'));
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan cukup baik', ekskul_service::get_keterangan_predikat('C'));
        $this->assertSame('Mengikuti kegiatan ekstrakurikuler dengan kurang baik', ekskul_service::get_keterangan_predikat('D'));
        $this->assertSame('-', ekskul_service::get_keterangan_predikat('X'));
    }

    public function test_get_ekskul_siswa_returns_empty_array_when_student_has_no_rows(): void {
        $student = $this->getDataGenerator()->create_user();

        $this->assertSame([], ekskul_service::get_ekskul_siswa((int)$student->id));
    }
}
