<?php

// Sertakan autoloader Composer
require __DIR__ . '/vendor/autoload.php';

// Muat variabel lingkungan dari file .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Kredensial Database diambil dari variabel lingkungan
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
define('DB_NAME', $_ENV['DB_NAME']);

// Buat koneksi ke database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Atur zona waktu default ke Jakarta (GMT+7)
date_default_timezone_set('Asia/Jakarta');

// Asumsi: Waktu di database disimpan dalam UTC atau zona waktu default server MySQL
function convertDbTimeToWib($dbDateTimeString)
{
    // Jika string waktu kosong atau '0000-00-00 00:00:00', kembalikan kosong/strip
    if (empty($dbDateTimeString) || $dbDateTimeString === '0000-00-00 00:00:00') {
        return '-';
    }
    try {
        // Buat objek DateTime dengan asumsi waktu dari DB adalah UTC (paling aman)
        // Jika server MySQL Anda bukan UTC, Anda perlu menyesuaikan 'UTC' ini
        $db_timezone = new DateTimeZone('UTC'); // Asumsi DB menyimpan dalam UTC
        $datetime_obj = new DateTime($dbDateTimeString, $db_timezone);

        // Atur zona waktu objek ke Asia/Jakarta (WIB)
        $wib_timezone = new DateTimeZone('Asia/Jakarta');
        $datetime_obj->setTimezone($wib_timezone);

        // Kembalikan waktu dalam format yang diinginkan
        return $datetime_obj->format('d M Y H:i:s');
    } catch (Exception $e) {
        // Tangani jika string tanggal tidak valid
        error_log("Error converting DB time to WIB: " . $e->getMessage() . " for input: " . $dbDateTimeString);
        return 'Invalid Date';
    }
}

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi database GAGAL: " . $conn->connect_error);
}

// Opsional: Atur charset koneksi jika diperlukan
// $conn->set_charset("utf8");
