<?php

// namespace local_akademikmonitor\output;

// defined('MOODLE_INTERNAL') || die();

// class user_preferences {

//     public static function extend_preferences(array &$preferences) {
//         $preferences['useraccount'][] = new core_user\output\preferences\link_preference(
//             'akademikmonitor_telegram',
//             'Pengaturan Notifikasi',
//             new moodle_url('/local/akademikmonitor/pages/telegram/index.php')
// );

//     }

// }

namespace local_akademikmonitor\output;

defined('MOODLE_INTERNAL') || die();

class user_preferences {

    public static function extend_preferences(array &$preferences) {
        global $USER;

        // ==============================
        // MENU TELEGRAM (SEMUA USER)
        // ==============================
        $preferences['useraccount'][] = new \core_user\output\preferences\link_preference(
            'akademikmonitor_telegram',
            'Pengaturan Notifikasi',
            new \moodle_url('/local/akademikmonitor/pages/telegram/index.php')
        );

        // ==============================
        // MENU KHUSUS WALI KELAS
        // ==============================
        if (user_has_role_assignment($USER->id, 9)) {

            $preferences['useraccount'][] = new \core_user\output\preferences\link_preference(
                'akademikmonitor_dashboard',
                'Dashboard Wali Kelas',
                new \moodle_url('/local/akademikmonitor/pages/walikelas/dashboard.php')
            );

            $preferences['useraccount'][] = new \core_user\output\preferences\link_preference(
                'akademikmonitor_monitoring',
                'Monitoring Kelas',
                new \moodle_url('/local/akademikmonitor/pages/walikelas/monitoring.php')
            );

            $preferences['useraccount'][] = new \core_user\output\preferences\link_preference(
                'akademikmonitor_ekskul',
                'Ekstrakurikuler Siswa',
                new \moodle_url('/local/akademikmonitor/pages/walikelas/ekskul.php')
            );

            $preferences['useraccount'][] = new \core_user\output\preferences\link_preference(
                'akademikmonitor_pkl',
                'PKL Siswa',
                new \moodle_url('/local/akademikmonitor/pages/walikelas/pkl.php')
            );

        }
    }

}