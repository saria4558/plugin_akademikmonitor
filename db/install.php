<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_akademikmonitor_install() {
    \local_akademikmonitor\service\seed::run();
}
