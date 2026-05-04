<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_akademikmonitor.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_akademikmonitor_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Upgrade to 2026021600: add mitra_dudi table + revise pkl to use id_mitra_dudi.
    if ($oldversion < 2026021600) {

        // 1) Create table mitra_dudi if not exists.
        $mitratable = new xmldb_table('mitra_dudi');
        if (!$dbman->table_exists($mitratable)) {
            $mitratable->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $mitratable->add_field('nama', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $mitratable->add_field('alamat', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $mitratable->add_field('kontak', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $mitratable->add_field('is_active', XMLDB_TYPE_CHAR, '10', null, null, null, null);
            $mitratable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);
            $mitratable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);

            $mitratable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $dbman->create_table($mitratable);

            // Unique index on nama.
            $uxnama = new xmldb_index('ux_mitra_nama', XMLDB_INDEX_UNIQUE, ['nama']);
            if (!$dbman->index_exists($mitratable, $uxnama)) {
                $dbman->add_index($mitratable, $uxnama);
            }

            // Optional index on is_active.
            $idxactive = new xmldb_index('idx_mitra_active', XMLDB_INDEX_NOTUNIQUE, ['is_active']);
            if (!$dbman->index_exists($mitratable, $idxactive)) {
                $dbman->add_index($mitratable, $idxactive);
            }
        }

        // 2) Add field id_mitra_dudi to pkl (nullable first for migration).
        $pkltable = new xmldb_table('pkl');
        $fieldidm = new xmldb_field('id_mitra_dudi', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);

        if ($dbman->table_exists($pkltable) && !$dbman->field_exists($pkltable, $fieldidm)) {
            $dbman->add_field($pkltable, $fieldidm);
        }

        // 3) Migrate old data from pkl.mitra_dudi + pkl.alamat -> mitra_dudi and set pkl.id_mitra_dudi.
        $fieldoldnama = new xmldb_field('mitra_dudi');
        $fieldoldalamat = new xmldb_field('alamat');

        $now = time();

        // Ensure "Tidak diketahui" exists (fallback).
        $unknownname = 'Tidak diketahui';
        $unknown = $DB->get_record('mitra_dudi', ['nama' => $unknownname], 'id', IGNORE_MISSING);
        if (!$unknown) {
            $unknownid = $DB->insert_record('mitra_dudi', (object)[
                'nama' => $unknownname,
                'alamat' => '',
                'kontak' => null,
                'is_active' => '1',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } else {
            $unknownid = (int)$unknown->id;
        }

        // Only run migration if old columns exist.
        if ($dbman->table_exists($pkltable) && $dbman->field_exists($pkltable, $fieldoldnama)) {

            // Fetch all PKL rows.
            $selectfields = 'id, id_mitra_dudi, mitra_dudi';
            if ($dbman->field_exists($pkltable, $fieldoldalamat)) {
                $selectfields .= ', alamat';
            }

            $rs = $DB->get_recordset('pkl', null, '', $selectfields);

            foreach ($rs as $row) {
                $rawname = isset($row->mitra_dudi) ? (string)$row->mitra_dudi : '';
                $name = preg_replace('/\s+/', ' ', trim($rawname));
                if ($name === '') {
                    $name = $unknownname;
                }
                if (core_text::strlen($name) > 255) {
                    $name = core_text::substr($name, 0, 255);
                }

                $addr = '';
                if (property_exists($row, 'alamat') && $row->alamat !== null) {
                    $addr = trim((string)$row->alamat);
                }

                // Find or create mitra_dudi by nama (unique).
                $mitra = $DB->get_record('mitra_dudi', ['nama' => $name], 'id, alamat', IGNORE_MISSING);
                if (!$mitra) {
                    $mid = $DB->insert_record('mitra_dudi', (object)[
                        'nama' => $name,
                        'alamat' => $addr,
                        'kontak' => null,
                        'is_active' => '1',
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                } else {
                    $mid = (int)$mitra->id;

                    // If existing alamat empty but we have one, fill it.
                    if ($addr !== '' && trim((string)$mitra->alamat) === '') {
                        $DB->set_field('mitra_dudi', 'alamat', $addr, ['id' => $mid]);
                        $DB->set_field('mitra_dudi', 'timemodified', $now, ['id' => $mid]);
                    }
                }

                // Update pkl.id_mitra_dudi.
                if (empty($row->id_mitra_dudi)) {
                    $DB->set_field('pkl', 'id_mitra_dudi', $mid ?: $unknownid, ['id' => (int)$row->id]);
                }
            }

            $rs->close();
        }

        // Safety: any remaining NULL/0 -> unknown.
        $DB->execute("UPDATE {pkl} SET id_mitra_dudi = ? WHERE id_mitra_dudi IS NULL OR id_mitra_dudi = 0", [$unknownid]);

        // 4) Make id_mitra_dudi NOT NULL.
        $fieldidm_notnull = new xmldb_field('id_mitra_dudi', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        if ($dbman->table_exists($pkltable) && $dbman->field_exists($pkltable, $fieldidm_notnull)) {
            $dbman->change_field_notnull($pkltable, $fieldidm_notnull);
        }

        // 5) Drop old columns from pkl: mitra_dudi and alamat.
        if ($dbman->table_exists($pkltable) && $dbman->field_exists($pkltable, $fieldoldnama)) {
            $dbman->drop_field($pkltable, $fieldoldnama);
        }
        if ($dbman->table_exists($pkltable) && $dbman->field_exists($pkltable, $fieldoldalamat)) {
            $dbman->drop_field($pkltable, $fieldoldalamat);
        }

        // 6) Add indexes for pkl (id_mitra_dudi) and unique (id_siswa, id_kelas, semester).
        $idxpklmitra = new xmldb_index('idx_pkl_mitra', XMLDB_INDEX_NOTUNIQUE, ['id_mitra_dudi']);
        if (!$dbman->index_exists($pkltable, $idxpklmitra)) {
            $dbman->add_index($pkltable, $idxpklmitra);
        }

        // Deduplicate pkl before adding unique index (keep newest row per key).
        $dups = $DB->get_records_sql("
            SELECT MIN(id) AS id, id_siswa, id_kelas, semester, COUNT(1) AS cnt
              FROM {pkl}
          GROUP BY id_siswa, id_kelas, semester
            HAVING COUNT(1) > 1
        ");

        foreach ($dups as $dup) {
            $rows = $DB->get_records('pkl', [
                'id_siswa' => $dup->id_siswa,
                'id_kelas' => $dup->id_kelas,
                'semester' => $dup->semester
            ], 'timemodified DESC, id DESC', 'id, timemodified');

            $ids = array_keys($rows);
            if (count($ids) <= 1) {
                continue;
            }

            // Keep the first (newest), delete the rest.
            $keepid = array_shift($ids);
            foreach ($ids as $rid) {
                $DB->delete_records('pkl', ['id' => $rid]);
            }
        }

        $uxpkl = new xmldb_index('ux_pkl_siswa_kelas_sem', XMLDB_INDEX_UNIQUE, ['id_siswa', 'id_kelas', 'semester']);
        if (!$dbman->index_exists($pkltable, $uxpkl)) {
            $dbman->add_index($pkltable, $uxpkl);
        }

        // 7) Optional: align some field types with your DBML (WARNING: can truncate data).
        // - capaian_pembelajaran.deskripsi (TEXT -> CHAR 255)
        $cptable = new xmldb_table('capaian_pembelajaran');
        $cpdesc = new xmldb_field('deskripsi', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        if ($dbman->table_exists($cptable) && $dbman->field_exists($cptable, $cpdesc)) {
            $dbman->change_field_type($cptable, $cpdesc);
        }

        // - tujuan_pembelajaran.deskripsi (TEXT -> CHAR 255)
        $tptable = new xmldb_table('tujuan_pembelajaran');
        $tpdesc = new xmldb_field('deskripsi', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        if ($dbman->table_exists($tptable) && $dbman->field_exists($tptable, $tpdesc)) {
            $dbman->change_field_type($tptable, $tpdesc);
        }

        // - log_pengiriman_pesan.message_preview & error_message (TEXT -> CHAR 255)
        $logtable = new xmldb_table('log_pengiriman_pesan');
        $mp = new xmldb_field('message_preview', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        if ($dbman->table_exists($logtable) && $dbman->field_exists($logtable, $mp)) {
            $dbman->change_field_type($logtable, $mp);
        }
        $em = new xmldb_field('error_message', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        if ($dbman->table_exists($logtable) && $dbman->field_exists($logtable, $em)) {
            $dbman->change_field_type($logtable, $em);
        }

        // Savepoint.
        upgrade_plugin_savepoint(true, 2026021600, 'local', 'akademikmonitor');
    }
    // Upgrade to 2026021700: add kelas_member table.
    if ($oldversion < 2026021700) {

        $table = new xmldb_table('kelas_member');

        // Fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);

        $table->add_field('id_kelas', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);

        $table->add_field('id_user', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);

        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, null);

        // Keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Index untuk mempercepat query.
        $idxkelas = new xmldb_index('idx_kelas_member_kelas', XMLDB_INDEX_NOTUNIQUE, ['id_kelas']);
        $idxuser  = new xmldb_index('idx_kelas_member_user', XMLDB_INDEX_NOTUNIQUE, ['id_user']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            $dbman->add_index($table, $idxkelas);
            $dbman->add_index($table, $idxuser);
        }

        upgrade_plugin_savepoint(true, 2026021700, 'local', 'akademikmonitor');
    }


    if ($oldversion < 2026040201) {

        $records = $DB->get_records('kurikulum_mapel', [
            'tingkat_kelas' => 'X'
        ]);

        foreach ($records as $r) {

            // ===== XI =====
            $existsXI = $DB->record_exists('kurikulum_mapel', [
                'id_kurikulum_jurusan' => $r->id_kurikulum_jurusan,
                'id_mapel' => $r->id_mapel,
                'tingkat_kelas' => 'XI'
            ]);

            if (!$existsXI) {
                $new = new stdClass();
                $new->id_kurikulum_jurusan = $r->id_kurikulum_jurusan;
                $new->id_mapel = $r->id_mapel;
                $new->tingkat_kelas = 'XI';
                $new->kktp = $r->kktp;

                $DB->insert_record('kurikulum_mapel', $new);
            }

            // ===== XII =====
            $existsXII = $DB->record_exists('kurikulum_mapel', [
                'id_kurikulum_jurusan' => $r->id_kurikulum_jurusan,
                'id_mapel' => $r->id_mapel,
                'tingkat_kelas' => 'XII'
            ]);

            if (!$existsXII) {
                $new = new stdClass();
                $new->id_kurikulum_jurusan = $r->id_kurikulum_jurusan;
                $new->id_mapel = $r->id_mapel;
                $new->tingkat_kelas = 'XII';
                $new->kktp = $r->kktp;

                $DB->insert_record('kurikulum_mapel', $new);
            }
        }

        upgrade_plugin_savepoint(true, 2026040201, 'local', 'akademikmonitor');
    }


if ($oldversion < 2026040202) {

    // =========================
    // 1. CEK / INSERT JURUSAN
    // =========================
    $jurusan = $DB->get_record('jurusan', ['nama_jurusan' => 'TOI']);

    if (!$jurusan) {
        $jurusanid = $DB->insert_record('jurusan', [
            'nama_jurusan' => 'TOI',
            'kode_jurusan' => 999
        ]);
    } else {
        $jurusanid = $jurusan->id;
    }

    // =========================
    // 2. AMBIL KURIKULUM & TA
    // =========================
    $kurikulum = $DB->get_record('kurikulum', ['is_active' => 1]);
    $tas = $DB->get_records('tahun_ajaran');
    $ta = reset($tas);

    // =========================
    // 3. CEK / INSERT kurikulum_jurusan
    // =========================
    $kj = $DB->get_record('kurikulum_jurusan', [
        'id_jurusan' => $jurusanid,
        'id_kurikulum' => $kurikulum->id,
        'id_tahun_ajaran' => $ta->id
    ]);

    if (!$kj) {
        $kjid = $DB->insert_record('kurikulum_jurusan', [
            'id_jurusan' => $jurusanid,
            'id_kurikulum' => $kurikulum->id,
            'id_tahun_ajaran' => $ta->id
        ]);
    } else {
        $kjid = $kj->id;
    }

    // =========================
    // 4. COPY MAPEL DARI RPL
    // =========================
    $rpl = $DB->get_record('jurusan', ['nama_jurusan' => 'RPL']);

    if ($rpl) {

        $rplkj = $DB->get_record('kurikulum_jurusan', [
            'id_jurusan' => $rpl->id,
            'id_kurikulum' => $kurikulum->id,
            'id_tahun_ajaran' => $ta->id
        ]);

        if ($rplkj) {

            $mapels = $DB->get_records('kurikulum_mapel', [
                'id_kurikulum_jurusan' => $rplkj->id
            ]);

            foreach ($mapels as $m) {

                $exists = $DB->record_exists('kurikulum_mapel', [
                    'id_kurikulum_jurusan' => $kjid,
                    'id_mapel' => $m->id_mapel,
                    'tingkat_kelas' => $m->tingkat_kelas
                ]);

                if (!$exists) {
                    $DB->insert_record('kurikulum_mapel', [
                        'id_kurikulum_jurusan' => $kjid,
                        'id_mapel' => $m->id_mapel,
                        'tingkat_kelas' => $m->tingkat_kelas,
                        'kktp' => $m->kktp
                    ]);
                }
            }
        }
    }

    upgrade_plugin_savepoint(true, 2026040202, 'local', 'akademikmonitor');
}

if ($oldversion < 2026040206) {

    global $DB;

    $dbman = $DB->get_manager();
    $table = new xmldb_table('course_mapel');

    // =========================
    // 1. BACKUP DATA
    // =========================
    $backup = $DB->get_records('course_mapel');

    // =========================
    // 2. HAPUS DATA (biar aman ubah struktur)
    // =========================
    $DB->delete_records('course_mapel');

    // =========================
    // 3. DROP PRIMARY KEY LAMA
    // =========================
    $oldkey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id_course', 'id_kurikulum_mapel']);
    try {
        $dbman->drop_key($table, $oldkey);
    } catch (Exception $e) {}

    // =========================
    // 4. TAMBAH FIELD id (TANPA AUTO_INCREMENT)
    // =========================
    $field = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);

    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    // =========================
    // 5. TAMBAH UNIQUE id (pengganti PK)
    // =========================
    $indexid = new xmldb_index('uniq_id', XMLDB_INDEX_UNIQUE, ['id']);
    if (!$dbman->index_exists($table, $indexid)) {
        $dbman->add_index($table, $indexid);
    }

    // =========================
    // 6. TAMBAH UNIQUE course
    // =========================
    $indexcourse = new xmldb_index('uniq_course', XMLDB_INDEX_UNIQUE, ['id_course']);
    if (!$dbman->index_exists($table, $indexcourse)) {
        $dbman->add_index($table, $indexcourse);
    }

    // =========================
    // 7. RESTORE DATA + GENERATE ID MANUAL
    // =========================
    $i = 1;

    foreach ($backup as $row) {

    $DB->execute("
        INSERT INTO {course_mapel} (id, id_course, id_kurikulum_mapel)
        VALUES (?, ?, ?)
    ", [
        $i++,
        $row->id_course,
        $row->id_kurikulum_mapel
    ]);
    }

    // =========================
    // SAVEPOINT
    // =========================
    upgrade_plugin_savepoint(true, 2026040206, 'local', 'akademikmonitor');
}



    if ($oldversion < 2026042701) {

        // =============================
        // TABLE capaian_pembelajaran
        // =============================
        $table = new xmldb_table('capaian_pembelajaran');

        // FIELD deskripsi
        $field = new xmldb_field(
            'deskripsi',
            XMLDB_TYPE_TEXT,
            null,
            null,
            XMLDB_NOTNULL,
            null
        );

        // ubah field jika ada
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        // savepoint
        upgrade_plugin_savepoint(true, 2026042701, 'local', 'akademikmonitor');
    }

    if ($oldversion < 2026042703) {

        $table = new xmldb_table('tujuan_pembelajaran');

        $field = new xmldb_field(
            'konten',
            XMLDB_TYPE_CHAR,
            '100',
            null,
            null,
            null,
            null,
            'id_capaian_pembelajaran'
        );

        // kalau belum ada → tambah
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026042703, 'local', 'akademikmonitor');
    }
    if ($oldversion < 2026050403) {

        // Pastikan capaian_pembelajaran.deskripsi bisa menyimpan teks CP panjang.
        $table = new xmldb_table('capaian_pembelajaran');

        $field = new xmldb_field(
            'deskripsi',
            XMLDB_TYPE_TEXT,
            null,
            null,
            XMLDB_NOTNULL,
            null,
            null
        );

        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050403, 'local', 'akademikmonitor');
    }
    return true;
}
