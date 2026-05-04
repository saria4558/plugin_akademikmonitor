<?php
namespace local_akademikmonitor\service\walikelas;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/enrollib.php');

class dashboard_service {

    public static function get_page_data(int $userid): array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $data = common_service::get_sidebar_data('dashboard');
        $kelasdata = [];
        $totalclasses = 0;
        $totalstudents = 0;

        $groups = common_service::get_group_walikelas($userid);

        foreach ($groups as $group) {
            $siswas = common_service::get_siswa_group((int)$group->id, $userid);

            $rows = [];
            foreach ($siswas as $siswa) {
                $courses = [];

                $usercourses = enrol_get_users_courses(
                    (int)$siswa->id,
                    true,
                    'id, fullname',
                    'fullname ASC'
                );

                if (!empty($usercourses)) {
                    foreach ($usercourses as $course) {
                        // Opsional: skip site course.
                        if ((int)$course->id === SITEID) {
                            continue;
                        }

                        $courses[] = [
                            'coursename' => (string)$course->fullname,
                        ];
                    }
                }

                $rows[] = [
                    'nama' => fullname($siswa),
                    'username' => (string)($siswa->username ?? '-'),
                    'email' => (string)($siswa->email ?? '-'),
                    'courses' => $courses,
                    'has_courses' => !empty($courses),
                ];
            }

            $jumlahsiswa = count($siswas);
            $totalstudents += $jumlahsiswa;
            $totalclasses++;

            $kelasdata[] = [
                'nama' => (string)$group->name,
                'totalsiswa' => $jumlahsiswa,
                'siswa' => $rows,
                'has_siswa' => !empty($rows),
            ];
        }

        $data['welcometitle'] = 'Selamat Datang di Dashboard Wali Kelas';
        $data['welcomesubtitle'] = 'Kelola monitoring kelas, ekstrakurikuler, PKL, dan raport siswa dari satu halaman.';
        $data['welcomedesc'] = 'Dashboard ini membantu wali kelas memantau data peserta didik, membuka menu utama dengan cepat, dan melihat ringkasan kelas yang diampu.';

        $data['summarycards'] = [
            [
                'label' => 'Total Kelas',
                'value' => $totalclasses,
                'icon' => '🏫',
            ],
            [
                'label' => 'Total Siswa',
                'value' => $totalstudents,
                'icon' => '👨‍🎓',
            ],
            [
                'label' => 'Menu Aktif',
                'value' => 4,
                'icon' => '🧩',
            ],
        ];

        $data['cards'] = [
            [
                'title' => 'Monitoring Kelas',
                'desc' => 'Lihat data siswa dan pemantauan kelas yang Anda ampu.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php'))->out(false),
                'icon' => '📋',
            ],
            [
                'title' => 'Ekstrakurikuler Siswa',
                'desc' => 'Kelola data ekstrakurikuler siswa per kelas.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php'))->out(false),
                'icon' => '🏅',
            ],
            [
                'title' => 'PKL Siswa',
                'desc' => 'Lihat dan kelola data PKL siswa beserta mitra DU/DI.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/pkl/pkl.php'))->out(false),
                'icon' => '🏢',
            ],
            [
                'title' => 'Raport',
                'desc' => 'Akses halaman raport siswa.',
                'url' => (new \moodle_url('/local/akademikmonitor/pages/walikelas/rapor/index.php'))->out(false),
                'icon' => '📝',
            ],
        ];

        $data['kelas'] = $kelasdata;
        $data['has_kelas'] = !empty($kelasdata);

        return $data;
    }
}