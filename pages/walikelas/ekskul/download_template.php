<?php

require_once(__DIR__.'/../../../../../config.php');

require_login();

$filename = "template_ekskul.csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$output = fopen('php://output', 'w');

fputcsv($output, ['nisn','ekskul','predikat']);

fputcsv($output, ['1234567890','Pramuka','A']);
fputcsv($output, ['1234567891','Futsal','B']);

fclose($output);
exit;