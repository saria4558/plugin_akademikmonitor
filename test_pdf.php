<?php
require_once(__DIR__ . '/../../config.php');

// 🔥 load dompdf
require_once(__DIR__ . '/lib/dompdf/autoload.inc.php');

use Dompdf\Dompdf;

// 🔥 inisialisasi dompdf
$dompdf = new Dompdf();

/*
KENAPA HARUS ADA INI?
- Dompdf butuh object untuk render HTML → PDF
*/
$html = "<h1 style='color:red;'>PDF BERHASIL</h1>
<p>Kalau ini muncul, berarti dompdf sudah jalan</p>";

// 🔥 load HTML
$dompdf->loadHtml($html);

/*
KENAPA SET PAPER?
- Supaya ukuran PDF jelas (default kadang aneh)
*/
$dompdf->setPaper('A4', 'portrait');

// 🔥 render PDF
$dompdf->render();

/*
🔥 INI PALING PENTING DI MOODLE
KENAPA?
- Moodle sering inject output (HTML/debug)
- Kalau tidak dibersihkan → PDF rusak
*/
while (ob_get_level()) {
    ob_end_clean();
}

// 🔥 download file
$dompdf->stream("test.pdf", ["Attachment" => true]);

exit;