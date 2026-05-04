<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Service sinkronisasi Tujuan Pembelajaran (TP) ke gradebook Moodle.
 *
 * Konsep:
 * - TP di plugin tetap disimpan di tabel {tujuan_pembelajaran}.
 * - TP dibuat sebagai grade category Moodle.
 * - Di dalam setiap TP dibuat default Penugasan 1 dan Penugasan 2.
 * - Guru tetap bisa menambahkan item nilai lain secara manual di dalam kategori TP.
 * - Total kategori TP disimpan ke tabel relasi {grade_items_tp}.
 *
 * Struktur akhir di Grader Report:
 *
 * Course
 * - Ujian
 *   - UTS
 *   - UAS
 * - TP 10 - ...
 *   - Penugasan 1
 *   - Penugasan 2
 * - TP 11 - ...
 *   - Penugasan 1
 *   - Penugasan 2
 */
class tp_gradebook_service {

    /**
     * Prefix lama.
     *
     * Dulu TP dibuat sebagai grade item langsung:
     * AM-TP-10
     *
     * Sekarang TP dibuat sebagai kategori.
     * Prefix ini tetap dipakai untuk migrasi nilai lama supaya tidak langsung hilang.
     */
    private const OLD_IDNUMBER_PREFIX = 'AM-TP-';

    /**
     * Prefix idnumber untuk total kategori TP.
     *
     * Contoh:
     * AM-TP-TOTAL-10
     */
    private const TP_TOTAL_PREFIX = 'AM-TP-TOTAL-';

    /**
     * Prefix idnumber untuk item penugasan default di dalam TP.
     *
     * Contoh:
     * AM-TP-ASSIGN-10-1
     * AM-TP-ASSIGN-10-2
     */
    private const TP_ASSIGNMENT_PREFIX = 'AM-TP-ASSIGN-';

    /**
     * Idnumber untuk item UTS.
     */
    private const UJIAN_UTS_IDNUMBER = 'am_uts';

    /**
     * Idnumber untuk item UAS.
     */
    private const UJIAN_UAS_IDNUMBER = 'am_uas';

    /**
     * Idnumber untuk total kategori Ujian.
     */
    private const UJIAN_TOTAL_IDNUMBER = 'am_ujian_total';

    /**
     * Source update gradebook.
     *
     * Ini dipakai Moodle untuk menandai perubahan gradebook berasal dari plugin ini.
     */
    private const SOURCE = 'local_akademikmonitor';

    /**
     * Membuat struktur gradebook untuk 1 TP ke semua course yang memakai mapel TP tersebut.
     *
     * Biasanya dipanggil setelah TP baru ditambahkan/disimpan.
     *
     * Kenapa perlu fungsi ini?
     * Karena saat admin membuat TP, TP itu harus otomatis muncul di gradebook course
     * yang menggunakan mapel terkait.
     */
    public static function ensure_grade_items_for_tp(int $tpid): void {
        global $DB;

        if ($tpid <= 0) {
            return;
        }

        $tp = self::get_tp_with_context($tpid);

        if (!$tp || empty($tp->kmid)) {
            return;
        }

        /*
         * Tidak pakai SELECT manual.
         * Ambil relasi course_mapel berdasarkan id_kurikulum_mapel.
         */
        $coursemaps = $DB->get_records('course_mapel', [
            'id_kurikulum_mapel' => (int)$tp->kmid,
        ]);

        if (!$coursemaps) {
            return;
        }

        $courseids = [];

        foreach ($coursemaps as $coursemap) {
            if (!empty($coursemap->id_course)) {
                $courseids[] = (int)$coursemap->id_course;
            }
        }

        $courseids = array_values(array_unique(array_filter($courseids)));

        if (!$courseids) {
            return;
        }

        /*
         * Ambil course memakai get_records_list, bukan query SQL manual.
         */
        $courses = $DB->get_records_list('course', 'id', $courseids);

        if (!$courses) {
            return;
        }

        foreach ($courses as $course) {
            self::ensure_grade_structure_for_tp_in_course($tp, (int)$course->id);
        }
    }

    /**
     * Membuat struktur semua TP dari satu kurikulum_mapel ke course tertentu.
     *
     * Biasanya dipanggil saat course Moodle digenerate dari kelas/rombel.
     *
     * Kenapa perlu fungsi ini?
     * Karena setelah course dibuat, gradebook harus langsung punya:
     * - Ujian
     * - TP 1
     * - TP 2
     * - dan seterusnya
     */
    public static function ensure_grade_items_for_kurikulum_mapel(int $kmid, int $courseid = 0): void {
        global $DB;

        if ($kmid <= 0) {
            return;
        }

        /*
         * Tidak pakai SELECT JOIN manual.
         * Ambil dulu semua CP berdasarkan kurikulum_mapel.
         */
        $cps = $DB->get_records('capaian_pembelajaran', [
            'id_kurikulum_mapel' => $kmid,
        ]);

        if (!$cps) {
            return;
        }

        $cpids = [];

        foreach ($cps as $cp) {
            if (!empty($cp->id)) {
                $cpids[] = (int)$cp->id;
            }
        }

        $cpids = array_values(array_unique(array_filter($cpids)));

        if (!$cpids) {
            return;
        }

        /*
         * Ambil semua TP berdasarkan daftar CP.
         * Ini tetap Moodle DB API.
         */
        $tpsraw = $DB->get_records_list(
            'tujuan_pembelajaran',
            'id_capaian_pembelajaran',
            $cpids,
            'id_capaian_pembelajaran ASC, id ASC'
        );

        if (!$tpsraw) {
            return;
        }

        $tps = [];

        foreach ($tpsraw as $tp) {
            $cpid = !empty($tp->id_capaian_pembelajaran)
                ? (int)$tp->id_capaian_pembelajaran
                : 0;

            if ($cpid <= 0 || empty($cps[$cpid])) {
                continue;
            }

            $cp = $cps[$cpid];

            /*
             * Tambahkan context CP ke object TP.
             * Ini menggantikan hasil JOIN.
             */
            $tp->cpid = (int)$cp->id;
            $tp->cp_deskripsi = $cp->deskripsi ?? '';
            $tp->kmid = !empty($cp->id_kurikulum_mapel)
                ? (int)$cp->id_kurikulum_mapel
                : 0;

            /*
             * Supaya aman kalau database lama belum punya kolom konten.
             */
            if (!property_exists($tp, 'konten')) {
                $tp->konten = '';
            }

            $tps[] = $tp;
        }

        if (!$tps) {
            return;
        }

        /*
         * Kalau courseid dikirim, langsung buat struktur gradebook hanya untuk course itu.
         */
        if ($courseid > 0) {
            self::ensure_ujian_structure($courseid);

            foreach ($tps as $tp) {
                self::ensure_grade_structure_for_tp_in_course($tp, $courseid);
            }

            self::ensure_course_total_aggregation($courseid);
            return;
        }

        /*
         * Kalau courseid tidak dikirim, cari semua course yang memakai kurikulum_mapel ini.
         */
        $coursemaps = $DB->get_records('course_mapel', [
            'id_kurikulum_mapel' => $kmid,
        ]);

        if (!$coursemaps) {
            return;
        }

        $courseids = [];

        foreach ($coursemaps as $coursemap) {
            if (!empty($coursemap->id_course)) {
                $courseids[] = (int)$coursemap->id_course;
            }
        }

        $courseids = array_values(array_unique(array_filter($courseids)));

        if (!$courseids) {
            return;
        }

        $courses = $DB->get_records_list('course', 'id', $courseids);

        if (!$courses) {
            return;
        }

        foreach ($courses as $course) {
            self::ensure_ujian_structure((int)$course->id);

            foreach ($tps as $tp) {
                self::ensure_grade_structure_for_tp_in_course($tp, (int)$course->id);
            }

            self::ensure_course_total_aggregation((int)$course->id);
        }
    }

    /**
     * Update nama kategori TP jika data TP diedit.
     *
     * Kenapa perlu?
     * Karena nama TP di tabel plugin dan nama kategori di gradebook harus tetap sinkron.
     *
     * Contoh:
     * TP awal:
     * TP 10 - Descriptive text
     *
     * Setelah diedit:
     * TP 10 - Descriptive text bidang kejuruan
     *
     * Maka nama di gradebook juga harus ikut berubah.
     */
    public static function sync_grade_item_name(int $tpid): void {
        global $DB, $CFG;

        if ($tpid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        $tp = self::get_tp_with_context($tpid);

        if (!$tp) {
            return;
        }

        /*
         * Tidak pakai SELECT JOIN manual.
         * Ambil relasi dari tabel plugin dulu.
         */
        $relations = $DB->get_records('grade_items_tp', [
            'id_tp' => $tpid,
        ]);

        if (!$relations) {
            return;
        }

        $gradeitemids = [];

        foreach ($relations as $relation) {
            if (!empty($relation->id_grade_items)) {
                $gradeitemids[] = (int)$relation->id_grade_items;
            }
        }

        $gradeitemids = array_values(array_unique(array_filter($gradeitemids)));

        if (!$gradeitemids) {
            return;
        }

        /*
         * Ambil grade_items memakai DB API.
         */
        $gradeitemrecords = $DB->get_records_list('grade_items', 'id', $gradeitemids);

        if (!$gradeitemrecords) {
            return;
        }

        foreach ($gradeitemrecords as $record) {
            /*
             * Pastikan yang disinkronkan adalah total kategori TP versi baru,
             * bukan grade item lain.
             */
            if ((string)$record->idnumber !== self::build_tp_total_idnumber($tpid)) {
                continue;
            }

            $gradeitem = \grade_item::fetch([
                'id' => (int)$record->id,
            ]);

            if (!$gradeitem || empty($gradeitem->iteminstance)) {
                continue;
            }

            $category = \grade_category::fetch([
                'id' => (int)$gradeitem->iteminstance,
            ]);

            if (!$category) {
                continue;
            }

            $newname = self::build_tp_category_name($tp);

            $changed = false;

            if ((string)$category->fullname !== $newname) {
                $category->fullname = $newname;
                $changed = true;
            }

            if ((int)$category->aggregation !== GRADE_AGGREGATE_MEAN) {
                $category->aggregation = GRADE_AGGREGATE_MEAN;
                $changed = true;
            }

            if ((int)$category->aggregateonlygraded !== 1) {
                $category->aggregateonlygraded = 1;
                $changed = true;
            }

            if ($changed) {
                $category->update(self::SOURCE);
            }

            self::set_category_total_idnumber(
                $category,
                self::build_tp_total_idnumber($tpid)
            );

            self::ensure_default_tp_assignments(
                (int)$record->courseid,
                $category,
                (int)$tp->id
            );

            self::ensure_course_total_aggregation((int)$record->courseid);

            grade_regrade_final_grades((int)$record->courseid);
        }
    }

    /**
     * Hapus struktur gradebook untuk TP.
     *
     * Catatan:
     * - Yang dihapus adalah kategori TP milik plugin.
     * - Item Penugasan 1 dan Penugasan 2 di dalamnya ikut hilang karena parent category dihapus.
     *
     * Kenapa perlu?
     * Supaya kalau TP dihapus dari plugin, gradebook tidak meninggalkan kategori lama.
     */
    public static function delete_grade_items_for_tp(int $tpid): void {
        global $DB, $CFG;

        if ($tpid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        /*
         * Tidak pakai SELECT JOIN manual.
         * Ambil relasi dulu dari tabel plugin.
         */
        $relations = $DB->get_records('grade_items_tp', [
            'id_tp' => $tpid,
        ]);

        if (!$relations) {
            return;
        }

        $gradeitemids = [];

        foreach ($relations as $relation) {
            if (!empty($relation->id_grade_items)) {
                $gradeitemids[] = (int)$relation->id_grade_items;
            }
        }

        $gradeitemids = array_values(array_unique(array_filter($gradeitemids)));

        if (!$gradeitemids) {
            $DB->delete_records('grade_items_tp', [
                'id_tp' => $tpid,
            ]);
            return;
        }

        $gradeitemrecords = $DB->get_records_list('grade_items', 'id', $gradeitemids);

        if ($gradeitemrecords) {
            foreach ($gradeitemrecords as $record) {
                /*
                 * Pastikan hanya kategori TP versi baru yang dihapus.
                 */
                if ((string)$record->idnumber !== self::build_tp_total_idnumber($tpid)) {
                    continue;
                }

                if (empty($record->iteminstance)) {
                    continue;
                }

                $category = \grade_category::fetch([
                    'id' => (int)$record->iteminstance,
                ]);

                if ($category) {
                    $courseid = !empty($record->courseid) ? (int)$record->courseid : 0;

                    $category->delete(self::SOURCE);

                    if ($courseid > 0) {
                        grade_regrade_final_grades($courseid);
                    }
                }
            }
        }

        $DB->delete_records('grade_items_tp', [
            'id_tp' => $tpid,
        ]);
    }

    /**
     * Membuat struktur TP di dalam satu course.
     *
     * Ini bagian inti:
     * - Pastikan kategori Ujian ada.
     * - Buat TP sebagai kategori.
     * - Set idnumber pada total kategori TP.
     * - Buat Penugasan 1 dan Penugasan 2 di dalam TP.
     * - Simpan relasi total kategori TP ke tabel {grade_items_tp}.
     */
    private static function ensure_grade_structure_for_tp_in_course(\stdClass $tp, int $courseid): void {
        global $DB, $CFG;

        if ($courseid <= 0 || empty($tp->id)) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        /*
         * Ujian tetap dibuat sebagai kategori khusus.
         */
        self::ensure_ujian_structure($courseid);

        /*
         * TP dibuat sebagai kategori, bukan item nilai langsung.
         */
        $category = self::ensure_tp_category($courseid, $tp);

        if (!$category) {
            return;
        }

        /*
         * Total kategori TP diberi idnumber khusus.
         * Ini penting agar rapor bisa mengambil nilai akhir TP.
         */
        self::set_category_total_idnumber(
            $category,
            self::build_tp_total_idnumber((int)$tp->id)
        );

        /*
         * Kalau sebelumnya TP sudah pernah dibuat sebagai grade item lama,
         * pindahkan item lama itu menjadi Penugasan 1 agar nilai lama tidak hilang.
         */
        self::migrate_old_tp_item_if_exists($courseid, $category, (int)$tp->id);

        /*
         * Buat item default di dalam kategori TP.
         */
        self::ensure_default_tp_assignments($courseid, $category, (int)$tp->id);

        /*
         * Ambil grade item total kategori.
         */
        $totalitem = $category->load_grade_item();

        if ($totalitem && !empty($totalitem->id)) {
            /*
             * Supaya tidak dobel relasi.
             */
            $DB->delete_records('grade_items_tp', [
                'id_tp' => (int)$tp->id,
            ]);

            $DB->insert_record('grade_items_tp', (object)[
                'id_grade_items' => (int)$totalitem->id,
                'id_tp' => (int)$tp->id,
            ]);
        }

        self::ensure_course_total_aggregation($courseid);

        grade_regrade_final_grades($courseid);
    }

    /**
     * Ambil atau buat kategori TP.
     *
     * Kenapa TP harus kategori?
     * Karena satu TP bisa memiliki banyak penilaian:
     * - Penugasan 1
     * - Penugasan 2
     * - Praktik
     * - Sumatif
     * - Projek
     *
     * Moodle akan menghitung total TP dari nilai-nilai di dalam kategori ini.
     */
    private static function ensure_tp_category(int $courseid, \stdClass $tp): ?\grade_category {
        global $CFG;

        require_once($CFG->libdir . '/grade/grade_category.php');

        $fullname = self::build_tp_category_name($tp);

        /*
         * Cari kategori TP berdasarkan idnumber total kategori.
         */
        $category = self::find_tp_category_by_total_idnumber($courseid, (int)$tp->id);

        /*
         * Kalau belum ketemu, coba cari berdasarkan nama.
         * Ini membantu saat kategori sudah ada tetapi idnumber total belum terset.
         */
        if (!$category) {
            $category = \grade_category::fetch([
                'courseid' => $courseid,
                'fullname' => $fullname,
            ]);
        }

        if ($category) {
            $changed = false;

            if ((string)$category->fullname !== $fullname) {
                $category->fullname = $fullname;
                $changed = true;
            }

            if ((int)$category->aggregation !== GRADE_AGGREGATE_MEAN) {
                $category->aggregation = GRADE_AGGREGATE_MEAN;
                $changed = true;
            }

            if ((int)$category->aggregateonlygraded !== 1) {
                $category->aggregateonlygraded = 1;
                $changed = true;
            }

            if ($changed) {
                $category->update(self::SOURCE);
            }

            return $category;
        }

        /*
         * Buat kategori baru untuk TP.
         */
        $category = new \grade_category();
        $category->courseid = $courseid;
        $category->fullname = $fullname;
        $category->aggregation = GRADE_AGGREGATE_MEAN;
        $category->aggregateonlygraded = 1;
        $category->insert(self::SOURCE);

        return $category;
    }

    /**
     * Cari kategori TP dari idnumber total kategorinya.
     *
     * Grade category tidak punya idnumber langsung.
     * Yang punya idnumber adalah grade item total dari kategori tersebut.
     */
    private static function find_tp_category_by_total_idnumber(int $courseid, int $tpid): ?\grade_category {
        global $CFG;

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        $totalitem = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'category',
            'idnumber' => self::build_tp_total_idnumber($tpid),
        ]);

        if (!$totalitem || empty($totalitem->iteminstance)) {
            return null;
        }

        $category = \grade_category::fetch([
            'id' => (int)$totalitem->iteminstance,
        ]);

        return $category ?: null;
    }

    /**
     * Membuat item default di dalam kategori TP:
     * - Penugasan 1
     * - Penugasan 2
     *
     * Kenapa default dibuat?
     * Supaya setelah course digenerate, guru langsung melihat struktur TP
     * dan bisa langsung mengisi nilai.
     */
    private static function ensure_default_tp_assignments(
        int $courseid,
        \grade_category $category,
        int $tpid
    ): void {
        self::ensure_manual_grade_item(
            $courseid,
            'Penugasan 1',
            self::build_tp_assignment_idnumber($tpid, 1),
            (int)$category->id,
            100.0
        );

        self::ensure_manual_grade_item(
            $courseid,
            'Penugasan 2',
            self::build_tp_assignment_idnumber($tpid, 2),
            (int)$category->id,
            100.0
        );
    }

    /**
     * Migrasi item TP versi lama.
     *
     * Versi lama:
     * - TP dibuat sebagai grade item langsung.
     * - Contoh idnumber: AM-TP-10
     *
     * Versi baru:
     * - TP menjadi kategori.
     * - Nilai lama dipindahkan menjadi Penugasan 1.
     *
     * Kenapa perlu?
     * Supaya ketika kamu sudah pernah isi nilai di struktur lama,
     * nilai itu tidak langsung hilang saat struktur diubah.
     */
    private static function migrate_old_tp_item_if_exists(
        int $courseid,
        \grade_category $category,
        int $tpid
    ): void {
        global $CFG;

        require_once($CFG->libdir . '/grade/grade_item.php');

        $oldidnumber = self::OLD_IDNUMBER_PREFIX . $tpid;
        $newidnumber = self::build_tp_assignment_idnumber($tpid, 1);

        $olditem = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => $oldidnumber,
        ]);

        if (!$olditem) {
            return;
        }

        $olditem->categoryid = (int)$category->id;
        $olditem->itemname = 'Penugasan 1';
        $olditem->idnumber = $newidnumber;
        $olditem->gradetype = GRADE_TYPE_VALUE;
        $olditem->grademin = 0;
        $olditem->grademax = 100;
        $olditem->hidden = 0;
        $olditem->locked = 0;
        $olditem->update(self::SOURCE);
    }

    /**
     * Membuat struktur kategori Ujian.
     *
     * Struktur:
     * Ujian
     * - UTS
     * - UAS
     *
     * Kenapa dipisah?
     * Karena UTS dan UAS bukan bagian dari satu TP tertentu.
     * Jadi lebih rapi kalau tetap berada di kategori Ujian.
     */
    private static function ensure_ujian_structure(int $courseid): void {
        global $CFG;

        if ($courseid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        /*
         * Cari kategori Ujian.
         * Cek juga nama "ujian" kecil untuk memperbaiki struktur lama.
         */
        $category = \grade_category::fetch([
            'courseid' => $courseid,
            'fullname' => 'Ujian',
        ]);

        if (!$category) {
            $category = \grade_category::fetch([
                'courseid' => $courseid,
                'fullname' => 'ujian',
            ]);
        }

        if ($category) {
            $changed = false;

            if ((string)$category->fullname !== 'Ujian') {
                $category->fullname = 'Ujian';
                $changed = true;
            }

            if ((int)$category->aggregation !== GRADE_AGGREGATE_MEAN) {
                $category->aggregation = GRADE_AGGREGATE_MEAN;
                $changed = true;
            }

            if ((int)$category->aggregateonlygraded !== 1) {
                $category->aggregateonlygraded = 1;
                $changed = true;
            }

            if ($changed) {
                $category->update(self::SOURCE);
            }
        } else {
            $category = new \grade_category();
            $category->courseid = $courseid;
            $category->fullname = 'Ujian';
            $category->aggregation = GRADE_AGGREGATE_MEAN;
            $category->aggregateonlygraded = 1;
            $category->insert(self::SOURCE);
        }

        self::ensure_manual_grade_item(
            $courseid,
            'UTS',
            self::UJIAN_UTS_IDNUMBER,
            (int)$category->id,
            100.0
        );

        self::ensure_manual_grade_item(
            $courseid,
            'UAS',
            self::UJIAN_UAS_IDNUMBER,
            (int)$category->id,
            100.0
        );

        self::set_category_total_idnumber(
            $category,
            self::UJIAN_TOTAL_IDNUMBER
        );
    }

    /**
     * Ambil atau buat manual grade item.
     *
     * Dipakai untuk:
     * - UTS
     * - UAS
     * - Penugasan 1
     * - Penugasan 2
     *
     * Kenapa dibuat dalam satu fungsi?
     * Supaya proses pembuatan grade item konsisten dan tidak mengulang kode.
     */
    private static function ensure_manual_grade_item(
        int $courseid,
        string $itemname,
        string $idnumber,
        int $categoryid,
        float $grademax = 100.0
    ): ?\grade_item {
        global $CFG;

        if ($courseid <= 0 || $categoryid <= 0 || trim($itemname) === '' || trim($idnumber) === '') {
            return null;
        }

        require_once($CFG->libdir . '/grade/grade_item.php');

        $gradeitem = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => $idnumber,
        ]);

        if ($gradeitem) {
            $changed = false;

            if ((string)$gradeitem->itemname !== $itemname) {
                $gradeitem->itemname = $itemname;
                $changed = true;
            }

            if ((int)$gradeitem->categoryid !== $categoryid) {
                $gradeitem->categoryid = $categoryid;
                $changed = true;
            }

            if ((int)$gradeitem->gradetype !== GRADE_TYPE_VALUE) {
                $gradeitem->gradetype = GRADE_TYPE_VALUE;
                $changed = true;
            }

            if ((float)$gradeitem->grademin !== 0.0) {
                $gradeitem->grademin = 0;
                $changed = true;
            }

            if ((float)$gradeitem->grademax !== (float)$grademax) {
                $gradeitem->grademax = $grademax;
                $changed = true;
            }

            if ((int)$gradeitem->hidden !== 0) {
                $gradeitem->hidden = 0;
                $changed = true;
            }

            if ((int)$gradeitem->locked !== 0) {
                $gradeitem->locked = 0;
                $changed = true;
            }

            if ($changed) {
                $gradeitem->update(self::SOURCE);
            }

            return $gradeitem;
        }

        $gradeitem = new \grade_item();
        $gradeitem->courseid = $courseid;
        $gradeitem->categoryid = $categoryid;
        $gradeitem->itemtype = 'manual';
        $gradeitem->itemname = $itemname;
        $gradeitem->idnumber = $idnumber;
        $gradeitem->gradetype = GRADE_TYPE_VALUE;
        $gradeitem->grademin = 0;
        $gradeitem->grademax = $grademax;
        $gradeitem->hidden = 0;
        $gradeitem->locked = 0;
        $gradeitem->insert(self::SOURCE);

        return $gradeitem;
    }

    /**
     * Set idnumber pada total kategori.
     *
     * Kenapa penting?
     * Karena total kategori TP inilah yang dibaca sebagai nilai akhir TP.
     *
     * Contoh:
     * TP 10
     * - Penugasan 1 = 80
     * - Penugasan 2 = 90
     *
     * Total kategori TP 10 = 85
     *
     * Total itulah yang bisa dipakai untuk rapor dan capaian kompetensi.
     */
    private static function set_category_total_idnumber(\grade_category $category, string $idnumber): void {
        $gradeitem = $category->load_grade_item();

        if (!$gradeitem) {
            return;
        }

        $changed = false;

        if ((string)$gradeitem->idnumber !== $idnumber) {
            $gradeitem->idnumber = $idnumber;
            $changed = true;
        }

        if ((float)$gradeitem->grademax !== 100.0) {
            $gradeitem->grademax = 100;
            $changed = true;
        }

        if ((int)$gradeitem->hidden !== 0) {
            $gradeitem->hidden = 0;
            $changed = true;
        }

        if ($changed) {
            $gradeitem->update(self::SOURCE);
        }
    }

    /**
     * Mengatur course total.
     *
     * Di konsep baru:
     * - Tidak pakai rumus khusus yang hanya membaca Ujian + Sumatif.
     * - Kategori Sumatif tidak dipakai lagi.
     * - Course total memakai rata-rata dari kategori:
     *   - Ujian
     *   - TP 10
     *   - TP 11
     *   - TP 12
     *
     * Kenapa calculation dikosongkan?
     * Karena kalau course total masih punya calculation lama,
     * Moodle akan tetap menghitung dari rumus lama dan TP baru bisa tidak ikut terbaca.
     */
    private static function ensure_course_total_aggregation(int $courseid): void {
        global $CFG;

        if ($courseid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        $coursecategory = \grade_category::fetch_course_category($courseid);

        if (!$coursecategory) {
            return;
        }

        $changed = false;

        if ((int)$coursecategory->aggregation !== GRADE_AGGREGATE_MEAN) {
            $coursecategory->aggregation = GRADE_AGGREGATE_MEAN;
            $changed = true;
        }

        if ((int)$coursecategory->aggregateonlygraded !== 1) {
            $coursecategory->aggregateonlygraded = 1;
            $changed = true;
        }

        if ($changed) {
            $coursecategory->update(self::SOURCE);
        }

        $courseitem = $coursecategory->load_grade_item();

        if ($courseitem && !empty($courseitem->calculation)) {
            $courseitem->calculation = null;
            $courseitem->update(self::SOURCE);
        }

        grade_regrade_final_grades($courseid);
    }

    /**
     * Ambil TP lengkap dengan context CP dan kurikulum_mapel.
     *
     * Tidak pakai SELECT JOIN manual.
     *
     * Alur datanya:
     * tujuan_pembelajaran
     * -> capaian_pembelajaran
     * -> id_kurikulum_mapel
     */
    private static function get_tp_with_context(int $tpid): ?\stdClass {
        global $DB;

        if ($tpid <= 0) {
            return null;
        }

        $tp = $DB->get_record(
            'tujuan_pembelajaran',
            ['id' => $tpid],
            '*',
            IGNORE_MISSING
        );

        if (!$tp) {
            return null;
        }

        if (empty($tp->id_capaian_pembelajaran)) {
            return null;
        }

        $cp = $DB->get_record(
            'capaian_pembelajaran',
            ['id' => (int)$tp->id_capaian_pembelajaran],
            '*',
            IGNORE_MISSING
        );

        if (!$cp) {
            return null;
        }

        /*
         * Tambahkan data CP ke object TP.
         * Ini pengganti hasil JOIN.
         */
        $tp->cpid = (int)$cp->id;
        $tp->cp_deskripsi = $cp->deskripsi ?? '';
        $tp->kmid = !empty($cp->id_kurikulum_mapel)
            ? (int)$cp->id_kurikulum_mapel
            : 0;

        /*
         * Kolom konten ini baru.
         * Jadi dibuat aman kalau database belum punya/record belum membawa property itu.
         */
        if (!property_exists($tp, 'konten')) {
            $tp->konten = '';
        }

        return $tp;
    }

    /**
     * Membuat nama kategori TP yang tampil di Grader Report.
     *
     * Contoh:
     * TP 10 - Descriptive text bidang kejuruan
     */
    private static function build_tp_category_name(\stdClass $tp): string {
        $label = self::get_tp_short_text($tp);

        if ($label === '') {
            $label = 'Tujuan Pembelajaran';
        }

        return self::shorten($label, 90);
    }

    /**
     * Membuat idnumber total kategori TP.
     *
     * Contoh:
     * AM-TP-TOTAL-10
     */
    private static function build_tp_total_idnumber(int $tpid): string {
        return self::TP_TOTAL_PREFIX . $tpid;
    }

    /**
     * Membuat idnumber item penugasan di dalam TP.
     *
     * Contoh:
     * AM-TP-ASSIGN-10-1
     * AM-TP-ASSIGN-10-2
     */
    private static function build_tp_assignment_idnumber(int $tpid, int $number): string {
        return self::TP_ASSIGNMENT_PREFIX . $tpid . '-' . $number;
    }

    /**
     * Menentukan teks utama untuk nama TP.
     *
     * Prioritas:
     * 1. konten
     * 2. kompetensi
     * 3. deskripsi
     *
     * Kenapa konten diprioritaskan?
     * Karena kamu sebelumnya menambahkan kolom konten untuk isi singkat TP.
     * Jadi lebih cocok untuk nama kolom/kategori di gradebook.
     */
    private static function get_tp_short_text(\stdClass $tp): string {
        foreach (['konten', 'kompetensi', 'deskripsi'] as $field) {
            if (!empty($tp->{$field}) && trim((string)$tp->{$field}) !== '') {
                return trim((string)$tp->{$field});
            }
        }

        return '';
    }

    /**
     * Memotong teks supaya nama kategori tidak terlalu panjang di Grader Report.
     */
    private static function shorten(string $text, int $max): string {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string)$text);

        if ($text === '') {
            return '';
        }

        if (\core_text::strlen($text) <= $max) {
            return $text;
        }

        return \core_text::substr($text, 0, $max - 3) . '...';
    }
}