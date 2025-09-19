<?php
session_start();

// Periksa apakah admin sudah login. Jika tidak, arahkan kembali ke halaman login.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

// Inisialisasi variabel untuk pesan feedback
$message = '';
$message_type = ''; // 'success' atau 'error'

// Ambil flash message dari session jika ada
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'info'; // Default type jika tidak diset

    // Hapus flash message dari session agar tidak muncul lagi setelah refresh
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Pilihan untuk dropdown
$jenis_kelamin_options = ['Laki-laki', 'Perempuan'];
$kelompok_options = ['Bintaran', 'Gedongkuning', 'Jombor', 'Sunten'];
$usia_options = ['Pra Remaja', 'Remaja', 'Pra Nikah', 'Usia Mandiri'];

// Fungsi untuk menghasilkan data barcode unik
function generateUniqueBarcodeData()
{
    // Menggunakan uniqid() dan mt_rand() untuk meningkatkan keunikan
    return 'PRESENSI-' . uniqid() . '-' . mt_rand(1000, 9999);
}

// Endpoint untuk mengambil semua barcode data peserta aktif (untuk fungsi download semua QR)
if (isset($_GET['get_all_barcodes']) && $_GET['get_all_barcodes'] === 'true') {
    header('Content-Type: application/json');
    $barcodes = [];
    // PERUBAHAN DI SINI: Tambahkan 'kelompok' di SELECT
    $sql_barcodes = "SELECT name, barcode_data, kelompok FROM participants WHERE is_active = TRUE";
    $result_barcodes = $conn->query($sql_barcodes);
    if ($result_barcodes->num_rows > 0) {
        while ($row = $result_barcodes->fetch_assoc()) {
            // PERUBAHAN DI SINI: Tambahkan 'kelompok' ke array
            $barcodes[] = ['name' => $row['name'], 'barcode_data' => $row['barcode_data'], 'kelompok' => $row['kelompok']];
        }
    }
    echo json_encode($barcodes);
    exit;
}

// Ambil semua kelompok unik untuk dropdown filter
$kelompok_options_filter = ['Semua Kelompok'];
$sql_kelompok = "SELECT DISTINCT kelompok FROM participants WHERE kelompok IS NOT NULL AND kelompok != '' ORDER BY kelompok ASC";
$result_kelompok = $conn->query($sql_kelompok);
if ($result_kelompok && $result_kelompok->num_rows > 0) { // Tambahkan pengecekan $result_kelompok
    while ($row = $result_kelompok->fetch_assoc()) {
        $kelompok_options_filter[] = htmlspecialchars($row['kelompok']);
    }
}

// Ambil semua kategori usia unik untuk dropdown filter
$kategori_usia_options_filter = ['Semua Kategori Usia'];
$sql_kategori_usia = "SELECT DISTINCT kategori_usia FROM participants WHERE kategori_usia IS NOT NULL AND kategori_usia != '' ORDER BY kategori_usia ASC";
$result_kategori_usia = $conn->query($sql_kategori_usia);
if ($result_kategori_usia && $result_kategori_usia->num_rows > 0) { // Tambahkan pengecekan $result_kategori_usia
    while ($row = $result_kategori_usia->fetch_assoc()) {
        $kategori_usia_options_filter[] = htmlspecialchars($row['kategori_usia']);
    }
}

// Dapatkan filter yang dipilih dari GET parameter
$selected_kelompok_filter = isset($_GET['kelompok']) ? htmlspecialchars($_GET['kelompok']) : 'Semua Kelompok';
$selected_kategori_usia_filter = isset($_GET['usia']) ? htmlspecialchars($_GET['usia']) : 'Semua Kategori Usia';

// --- LOGIKA CRUD ---

// 1. Tambah Peserta Baru (Melalui Form Biasa)
if (isset($_POST['add_participant'])) {
    $name = $_POST['name'] ?? '';
    $jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
    $kelompok = $_POST['kelompok'] ?? '';
    $kategori_usia = $_POST['kategori_usia'] ?? '';

    // Data barcode_data akan dibuat otomatis oleh sistem
    $barcode_data = generateUniqueBarcodeData();

    // Validasi input sederhana
    if (empty($name)) {
        $message = "Nama peserta tidak boleh kosong.";
        $message_type = 'error';
    } else {
        // Sanitize input
        $name = $conn->real_escape_string($name);
        $jenis_kelamin = $conn->real_escape_string($jenis_kelamin);
        $barcode_data = $conn->real_escape_string($barcode_data);
        $kelompok = $conn->real_escape_string($kelompok);
        $kategori_usia = $conn->real_escape_string($kategori_usia);

        $sql = "INSERT INTO participants (name, jenis_kelamin, barcode_data, kelompok, kategori_usia, is_active) VALUES (?, ?, ?, ?, ?, TRUE)"; // Default is_active TRUE
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $name, $jenis_kelamin, $barcode_data, $kelompok, $kategori_usia);

        if ($stmt->execute()) {
            $message = "Peserta berhasil ditambahkan dengan barcode: <span class='font-mono text-blue-700'>" . htmlspecialchars($barcode_data) . "</span>";
            $message_type = 'success';
        } else {
            $message = "Gagal menambahkan peserta: " . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// 2. Edit Peserta
if (isset($_POST['edit_participant'])) {
    $id = $_POST['participant_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
    $kelompok = $_POST['kelompok'] ?? '';
    $kategori_usia = $_POST['kategori_usia'] ?? '';
    // barcode_data tidak diedit karena dibuat otomatis

    if (empty($id) || empty($name)) {
        $message = "Nama peserta diperlukan untuk mengedit peserta.";
        $message_type = 'error';
    } else {
        $id = (int)$id; // Pastikan ID adalah integer
        $name = $conn->real_escape_string($name);
        $jenis_kelamin = $conn->real_escape_string($jenis_kelamin);
        $kelompok = $conn->real_escape_string($kelompok);
        $kategori_usia = $conn->real_escape_string($kategori_usia);

        // Hanya perbarui nama, email, kelompok, dan kategori_usia
        $sql = "UPDATE participants SET name = ?, jenis_kelamin = ?, kelompok = ?, kategori_usia = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", $name, $jenis_kelamin, $kelompok, $kategori_usia, $id);

        if ($stmt->execute()) {
            $_SESSION['flash_message'] = "Peserta berhasil diperbarui!";
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = "Gagal memperbarui peserta: " . $stmt->error;
            $_SESSION['flash_type'] = 'error';
        }
        $stmt->close();
        // Bangun URL redirect dengan filter yang dipertahankan
        $redirect_url = 'manage_participants.php?';
        $query_params = [];

        if (!empty($_POST['current_kelompok_filter']) && $_POST['current_kelompok_filter'] !== 'Semua Kelompok') {
            $query_params[] = 'kelompok=' . urlencode($_POST['current_kelompok_filter']);
        }
        if (!empty($_POST['current_usia_filter']) && $_POST['current_usia_filter'] !== 'Semua Kategori Usia') {
            $query_params[] = 'usia=' . urlencode($_POST['current_usia_filter']);
        }

        if (!empty($query_params)) {
            $redirect_url .= implode('&', $query_params);
        }
        header('Location: ' . $redirect_url);
        exit;
    }
}

// 3. Soft Delete Peserta
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'] ?? '';
    if (!empty($id)) {
        $id = (int)$id; // Pastikan ID adalah integer

        // Ubah is_active menjadi FALSE (soft delete)
        $sql = "UPDATE participants SET is_active = FALSE WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id); // 'i' menandakan integer

        if ($stmt->execute()) {
            $message = "Peserta berhasil di-nonaktifkan (soft delete)!";
            $message_type = 'success';
        } else {
            $message = "Gagal menonaktifkan peserta: " . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// 4. Hapus SEMUA Peserta (Fitur Baru)
if (isset($_POST['delete_all_confirmed']) && $_SESSION['admin_id'] === 1) { // Hanya Super Admin (ID 1)
    // Mulai transaksi untuk memastikan semua data attendance terkait juga terhapus
    $conn->begin_transaction();
    try {
        // Hapus semua data dari tabel participants
        // Karena ada FOREIGN KEY dengan ON DELETE CASCADE di tabel attendances,
        // semua catatan presensi terkait juga akan otomatis terhapus.
        $sql_delete_all = "DELETE FROM participants";
        if ($conn->query($sql_delete_all)) {
            $conn->commit();
            $message = "SEMUA peserta berhasil dihapus dari database!";
            $message_type = 'success';
        } else {
            throw new Exception("Gagal menghapus semua peserta: " . $conn->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Terjadi kesalahan saat menghapus semua peserta: " . $e->getMessage();
        $message_type = 'error';
    }
} elseif (isset($_POST['delete_all_confirmed']) && $_SESSION['admin_id'] !== 1) {
    $message = "Anda tidak memiliki izin untuk menghapus semua peserta.";
    $message_type = 'error';
}

// 5. Import Peserta dari CSV
if (isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($file_ext === 'csv') {
            $handle = fopen($file_tmp_path, "r");
            if ($handle !== FALSE) {
                // Lewati baris header
                fgetcsv($handle);

                $imported_count = 0;
                $skipped_count = 0;
                $errors = [];

                $insert_sql = "INSERT INTO participants (name, jenis_kelamin, barcode_data, kelompok, kategori_usia, is_active) VALUES (?, ?, ?, ?, ?, TRUE)"; // Default is_active TRUE
                $stmt_insert = $conn->prepare($insert_sql);

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    // Kolom CSV sekarang: no, name, email, kelompok, kategori_usia
                    // Index:     0,  1,    2,     3,         4
                    // Pastikan baris memiliki jumlah kolom yang diharapkan (minimal 5)
                    if (count($data) >= 5) {
                        $csv_name = trim($data[1]); // Kolom 'name'
                        $csv_jenis_kelamin = trim($data[2]); // Kolom 'jenis_kelamin'
                        $csv_kelompok = trim($data[3]); // Kolom 'kelompok'
                        $csv_kategori_usia = trim($data[4]); // Kolom 'kategori_usia'

                        // Barcode data akan dibuat otomatis untuk setiap entri
                        $generated_barcode_data = generateUniqueBarcodeData();

                        // Validasi sederhana untuk data penting
                        if (empty($csv_name)) {
                            $errors[] = "Baris dilewati karena Nama kosong: " . implode(", ", $data);
                            $skipped_count++;
                            continue;
                        }
                        // Opsional: validasi apakah kelompok/kategori usia ada di daftar opsi
                        if (!in_array($csv_kelompok, $kelompok_options) && !empty($csv_kelompok)) {
                            $errors[] = "Baris dilewati karena Kelompok tidak valid: " . implode(", ", $data);
                            $skipped_count++;
                            continue;
                        }
                        if (!in_array($csv_kategori_usia, $usia_options) && !empty($csv_kategori_usia)) {
                            $errors[] = "Baris dilewati karena Kategori Usia tidak valid: " . implode(", ", $data);
                            $skipped_count++;
                            continue;
                        }


                        // Bind parameter dan eksekusi
                        $stmt_insert->bind_param("sssss", $csv_name, $csv_jenis_kelamin, $generated_barcode_data, $csv_kelompok, $csv_kategori_usia);
                        if ($stmt_insert->execute()) {
                            $imported_count++;
                        } else {
                            $errors[] = "Gagal memasukkan baris: " . implode(", ", $data) . " (Error: " . $stmt_insert->error . ")";
                            $skipped_count++;
                        }
                    } else {
                        $errors[] = "Baris dilewati karena format tidak sesuai (kurang kolom): " . implode(", ", $data);
                        $skipped_count++;
                    }
                }
                fclose($handle);
                $stmt_insert->close();

                $message = "Import selesai. Berhasil menambahkan " . $imported_count . " peserta.";
                $message_type = 'success';
                if ($skipped_count > 0) {
                    $message .= " (" . $skipped_count . " baris dilewati karena kesalahan format, data kosong, atau data tidak valid).";
                    if (!empty($errors)) {
                        $message .= "<br>Detail Error:<br>" . implode("<br>", $errors);
                    }
                    $message_type = 'error'; // Jika ada yang dilewati, anggap sebagai error/warning
                }
            } else {
                $message = "Gagal membuka file CSV.";
                $message_type = 'error';
            }
        } else {
            $message = "File yang diunggah harus berformat CSV.";
            $message_type = 'error';
        }
    } else if (isset($_POST['import_csv'])) { // Jika tombol ditekan tapi tidak ada file/ada error upload
        $message = "Terjadi kesalahan saat mengunggah file. Kode error: " . $_FILES['csv_file']['error'];
        $message_type = 'error';
    }
}

// 6. Ambil Data Peserta (untuk ditampilkan) - Hanya yang aktif dan diurutkan
$participants = [];
$sql_participants = "SELECT id, name, jenis_kelamin, barcode_data, kelompok, kategori_usia FROM participants WHERE is_active = TRUE";
$params = [];
$types = "";

if ($selected_kelompok_filter !== 'Semua Kelompok') {
    $sql_participants .= " AND kelompok = ?";
    $params[] = $selected_kelompok_filter;
    $types .= "s";
}

if ($selected_kategori_usia_filter !== 'Semua Kategori Usia') {
    $sql_participants .= " AND kategori_usia = ?";
    $params[] = $selected_kategori_usia_filter;
    $types .= "s";
}

$sql_participants .= " ORDER BY kelompok ASC, name ASC";

$stmt_participants = $conn->prepare($sql_participants);
if (!empty($params)) {
    $stmt_participants->bind_param($types, ...$params);
}
$stmt_participants->execute();
$result = $stmt_participants->get_result();

if ($result && $result->num_rows > 0) { // Tambahkan pengecekan $result
    while ($row = $result->fetch_assoc()) {
        $participants[] = $row;
    }
}
if ($stmt_participants) {
    $stmt_participants->close();
}

function getParticipantStatistics($conn)
{
    // Definisi semua opsi yang mungkin ada (sesuai dengan yang Anda gunakan di form)
    $kelompok_options_all = ['Bintaran', 'Gedongkuning', 'Jombor', 'Sunten'];
    $usia_options_all = ['Pra Remaja', 'Remaja', 'Pra Nikah', 'Usia Mandiri'];
    $jenis_kelamin_options_all = ['Laki-laki', 'Perempuan'];

    $stats = [
        'overall_gender' => [
            'Laki-laki' => 0,
            'Perempuan' => 0,
        ],
        'kelompok' => [],
        'total_all_participants' => 0,
    ];

    // --- Inisialisasi struktur 'kelompok' dan 'kategori_usia' dengan nilai 0 ---
    foreach ($kelompok_options_all as $kelompok_name) {
        $stats['kelompok'][$kelompok_name] = [
            'total' => 0,
            'kategori_usia' => []
        ];
        foreach ($usia_options_all as $usia_name) {
            $stats['kelompok'][$kelompok_name]['kategori_usia'][$usia_name] = [
                'total' => 0,
                'Laki-laki' => 0,
                'Perempuan' => 0
            ];
        }
    }
    // --- Akhir Inisialisasi ---


    // Query 1: Total Laki-laki dan Perempuan keseluruhan
    $sql_overall_gender = "SELECT jenis_kelamin, COUNT(id) AS total_peserta FROM participants WHERE is_active = TRUE GROUP BY jenis_kelamin";
    $result_overall_gender = $conn->query($sql_overall_gender);
    if ($result_overall_gender && $result_overall_gender->num_rows > 0) {
        while ($row = $result_overall_gender->fetch_assoc()) {
            if (in_array($row['jenis_kelamin'], $jenis_kelamin_options_all)) { // Pastikan jenis kelamin valid
                $stats['overall_gender'][$row['jenis_kelamin']] = (int)$row['total_peserta'];
            }
        }
    }

    $stats['total_all_participants'] = array_sum($stats['overall_gender']);

    // Query 2: Detail per Kelompok, Kategori Usia, dan Jenis Kelamin
    $sql_detailed_stats = "SELECT kelompok, kategori_usia, jenis_kelamin, COUNT(id) AS total_peserta FROM participants WHERE is_active = TRUE GROUP BY kelompok, kategori_usia, jenis_kelamin ORDER BY kelompok, kategori_usia, jenis_kelamin";
    $result_detailed_stats = $conn->query($sql_detailed_stats);

    if ($result_detailed_stats && $result_detailed_stats->num_rows > 0) {
        while ($row = $result_detailed_stats->fetch_assoc()) {
            $kelompok = $row['kelompok'];
            $kategori_usia = $row['kategori_usia'];
            $jenis_kelamin = $row['jenis_kelamin'];
            $total_peserta = (int)$row['total_peserta'];

            // Hanya update jika kelompok, kategori usia, dan jenis kelamin ada dalam opsi yang diinisialisasi
            if (
                in_array($kelompok, $kelompok_options_all) &&
                in_array($kategori_usia, $usia_options_all) &&
                in_array($jenis_kelamin, $jenis_kelamin_options_all)
            ) {

                $stats['kelompok'][$kelompok]['total'] += $total_peserta;
                $stats['kelompok'][$kelompok]['kategori_usia'][$kategori_usia]['total'] += $total_peserta;
                $stats['kelompok'][$kelompok]['kategori_usia'][$kategori_usia][$jenis_kelamin] += $total_peserta;
            }
        }
    }

    return $stats;
}

$participant_stats = getParticipantStatistics($conn);

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presensi KMM Banguntapan 1</title>
    <link rel="icon" href="images/logo_kmm.jpg" type="image/png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            /* Pastikan body mengambil tinggi penuh viewport */
            display: flex;
            /* Jadikan body sebagai flex container */
            flex-direction: column;
            /* Sidebar dan konten utama berdampingan */
        }

        /* Sidebar styles */
        .sidebar {
            width: 250px;
            background-color: #1a202c;
            /* Tailwind gray-900 */
            color: #ffffff;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 1000;
            padding-top: 4rem;
            /* Space for fixed header/toggle button */
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }

        .sidebar-overlay.open {
            display: block;
        }

        .main-content-wrapper {
            margin-left: 0;
            /* Default untuk mobile */
            transition: margin-left 0.3s ease-in-out;
            flex-grow: 1;
            /* Izinkan mengambil sisa ruang horizontal */
            display: flex;
            /* Jadikan flex container untuk header, main, footer */
            flex-direction: column;
            /* Susun header, main, footer secara vertikal */
        }

        .main-content-wrapper main {
            /* Target elemen main di dalam wrapper */
            flex-grow: 1;
            /* Buat konten utama tumbuh dan dorong footer ke bawah */
        }

        .main-content-wrapper.shifted {
            margin-left: 250px;
            /* Sesuaikan jika sidebar terbuka */
        }

        .menu-toggle-button {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            /* Di atas sidebar */
            background-color: #4f46e5;
            /* indigo-600 */
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        @media (min-width: 768px) {

            /* md breakpoint */
            .sidebar {
                transform: translateX(0);
                /* Selalu terbuka di desktop */
            }

            .main-content-wrapper {
                margin-left: 250px;
                /* Selalu digeser di desktop */
            }

            .menu-toggle-button {
                display: none;
                /* Sembunyikan tombol toggle di desktop */
            }
        }

        /* --- PRINT SPECIFIC STYLES --- */
        @media print {
            body {
                background-color: #fff !important;
                margin: 0;
                padding: 0;
            }

            /* Sembunyikan elemen utama yang tidak relevan untuk dicetak */
            .sidebar,
            .sidebar-overlay,
            .menu-toggle-button,
            .main-content-wrapper>header,
            .main-content-wrapper>footer,
            .modal-overlay,
            /* Sembunyikan semua modal saat print */
            h2.text-3xl.font-bold.text-gray-800.mb-6.text-center,
            /* Judul "Daftar Peserta Event" */
            .mb-6.p-6.bg-gray-50.rounded-lg.shadow-inner,
            /* Filter Peserta Section */
            .flex.flex-wrap.justify-center.gap-4.mb-8

            /* Tombol-tombol di atas tabel (Tambah, Import, Hapus Semua, Unduh PDF) */
                {
                display: none !important;
            }

            .main-content-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                min-height: auto !important;
                display: block !important;
                /* Pastikan ini tidak menjadi flex column yang merusak layout print */
            }

            .main-content-wrapper main {
                flex-grow: 0 !important;
                /* Nonaktifkan flex-grow di main */
                padding: 0 !important;
            }

            .bg-white.p-8.rounded-xl.shadow-lg.border.border-gray-200 {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 auto !important;
                max-width: 100% !important;
            }

            /* Tampilkan header tabel aktif dengan gaya cetak */
            h3.text-2xl.font-semibold.text-gray-700.mb-4.mt-8 {
                display: block !important;
                /* Pastikan judul tabel tampil */
                text-align: center !important;
                color: #000 !important;
                font-size: 1.5rem !important;
                /* Sesuaikan ukuran font untuk print */
                margin-top: 20px !important;
                margin-bottom: 20px !important;
            }

            /* Pastikan tabel itu sendiri terlihat */
            .overflow-x-auto {
                overflow: visible !important;
                /* Jangan sembunyikan overflow saat print */
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
            }

            table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-top: 0 !important;
            }

            table th,
            table td {
                border: 1px solid #e5e7eb !important;
                padding: 8px 12px !important;
                font-size: 9pt !important;
                color: #000 !important;
            }

            table th {
                background-color: #f2f2f2 !important;
            }

            /* Menghilangkan kolom QR Code dan Aksi */
            table th.print-hide-column,
            table td.print-hide-column {
                display: none !important;
            }

            /* Kop surat untuk cetak */
            .print-only-letterhead {
                display: block !important;
                margin-bottom: 20px;
                padding-bottom: 20px;
                border-bottom: 1px solid #000;
            }

            .print-only-letterhead .letterhead-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }

            .print-only-letterhead .letterhead-logo {
                max-width: 60px;
                height: auto;
            }

            .print-only-letterhead .letterhead-info {
                text-align: center;
                flex-grow: 1;
            }

            .print-only-letterhead .letterhead-info h4 {
                font-size: 12pt;
                font-weight: bold;
                margin: 0;
            }

            .print-only-letterhead .letterhead-info p {
                font-size: 8pt;
                margin: 2px 0;
            }
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background-color: white;
            padding: 2rem;
            border-radius: 0.75rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: relative;
        }

        .close-button {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6B7280;
        }

        .close-button:hover {
            color: #1F2937;
        }

        /* Style untuk QR Code di modal */
        #qrCodeDisplay {
            display: block;
            margin: 1rem auto;
            max-width: 200px;
            /* Batasi ukuran QR code */
            height: auto;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
        }
    </style>
</head>

<body>

    <!-- Sidebar -->
    <div id="sidebar" class="sidebar flex flex-col p-4">
        <!-- Logo di atas "Admin Panel" -->
        <img src="images/logo_kmm.jpg" alt="Logo Admin" class="w-16 h-16 mx-auto mb-4 rounded-full border-2 border-indigo-400">
        <div class="text-2xl font-bold text-white mb-6 text-center">Admin Panel</div>
        <nav class="flex-grow">
            <ul class="space-y-2">
                <li><a href="admin_dashboard.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7m7-7v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                        </svg>
                        Home
                    </a></li>
                <?php if ($_SESSION['admin_id'] === 1): // Hanya tampilkan jika super admin 
                ?>
                    <li><a href="manage_admins.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0H9m7 0h-3m-1 9v-3m0 0V9m0 0h3m-3 0h-3m-3 0h-3m-3 0h-3"></path>
                            </svg>
                            Manajemen Admin
                        </a></li>
                <?php endif; ?>
                <li><a href="manage_participants.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h2a2 2 0 002-2V9.828a2 2 0 00-.586-1.414l-4.414-4.414A2 2 0 0012.172 2H5a2 2 0 00-2 2v16a2 2 0 002 2h12z"></path>
                        </svg>
                        Manajemen Peserta
                    </a></li>
                <li><a href="manage_inactive_participants.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                        Peserta Nonaktif
                    </a></li>
                <li><a href="manage_events.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Manajemen Event
                    </a></li>
                <li><a href="manage_izin_pages.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                        Kelola Halaman Izin
                    </a></li>
                <li><a href="attendance_report.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 2v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Laporan Presensi
                    </a></li>
            </ul>
        </nav>
        <div class="mt-auto p-3">
            <a href="logout.php" class="flex items-center justify-center p-3 rounded-lg bg-red-600 text-white hover:bg-red-700 transition duration-200">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </div>

    <!-- Sidebar Overlay (for mobile) -->
    <div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <!-- Main Content Wrapper -->
    <div id="main-content-wrapper" class="flex-grow main-content-wrapper">
        <!-- Top Bar for Mobile (with toggle button) -->
        <header class="md:hidden bg-indigo-700 text-white p-4 flex items-center justify-between shadow-md">
            <button id="menu-toggle" class="menu-toggle-button">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
            <h1 class="text-2xl font-bold ml-12">Manajemen Peserta</h1>
            <!-- Optional: Add other top bar elements if needed -->
        </header>

        <!-- Main Content Area -->
        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200">
                <!-- <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Daftar Peserta Event</h2> -->
                <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">Daftar Muda/i Desa Banguntapan 1</h1>

                <?php if (!empty($message)): ?>
                    <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                        <?php echo $message; // Gunakan echo tanpa htmlspecialchars karena pesan error bisa berisi <br> 
                        ?>
                    </div>
                <?php endif; ?>

                <!-- Tombol untuk membuka modal -->
                <div class="flex flex-wrap justify-center gap-4 mb-8">
                    <button onclick="openAddModal()"
                        class="inline-flex px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-md transition duration-300 transform hover:scale-105">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 mr-2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Tambah Peserta Manual
                    </button>
                    <button onclick="openImportModal()"
                        class="inline-flex px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-lg shadow-md transition duration-300 transform hover:scale-105">
                        <svg class="w-5 h-5 mr-2 fill-[#ffffff]" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
                            <!--! Font Awesome Free 6.4.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2023 Fonticons, Inc. -->
                            <path d="M128 64c0-35.3 28.7-64 64-64H352V128c0 17.7 14.3 32 32 32H512V448c0 35.3-28.7 64-64 64H192c-35.3 0-64-28.7-64-64V336H302.1l-39 39c-9.4 9.4-9.4 24.6 0 33.9s24.6 9.4 33.9 0l80-80c9.4-9.4 9.4-24.6 0-33.9l-80-80c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l39 39H128V64zm0 224v48H24c-13.3 0-24-10.7-24-24s10.7-24 24-24H128zM512 128H384V0L512 128z"></path>
                        </svg>
                        Import Peserta dari CSV
                    </button>
                    <button onclick="window.print()"
                        class="inline-flex px-6 py-3 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-300 transform hover:scale-105">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4"></path>
                        </svg>
                        Unduh Daftar Peserta PDF
                    </button>
                    <button onclick="downloadAllQrCodes()"
                        class="inline-flex px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-white font-bold rounded-lg shadow-md transition duration-300 transform hover:scale-105">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Unduh Semua QR Code
                    </button>

                    <?php if ($_SESSION['admin_id'] === 1): // Hanya Super Admin yang bisa melihat tombol ini 
                    ?>
                        <button onclick="confirmDeleteAllParticipants()"
                            class="inline-flex px-6 py-3 bg-red-700 hover:bg-red-800 text-white font-bold rounded-lg shadow-md transition duration-300 transform hover:scale-105">
                            <svg class="w-5 h-5 mr-2 fill-[#ffffff]" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg">

                                <!--! Font Awesome Free 6.4.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2023 Fonticons, Inc. -->
                                <path d="M135.2 17.7C140.6 6.8 151.7 0 163.8 0H284.2c12.1 0 23.2 6.8 28.6 17.7L320 32h96c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 96 0 81.7 0 64S14.3 32 32 32h96l7.2-14.3zM32 128H416V448c0 35.3-28.7 64-64 64H96c-35.3 0-64-28.7-64-64V128zm96 64c-8.8 0-16 7.2-16 16V432c0 8.8 7.2 16 16 16s16-7.2 16-16V208c0-8.8-7.2-16-16-16zm96 0c-8.8 0-16 7.2-16 16V432c0 8.8 7.2 16 16 16s16-7.2 16-16V208c0-8.8-7.2-16-16-16zm96 0c-8.8 0-16 7.2-16 16V432c0 8.8 7.2 16 16 16s16-7.2 16-16V208c0-8.8-7.2-16-16-16z"></path>

                            </svg>
                            Hapus Semua Peserta
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Ringkasan Data Peserta -->
                <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 mb-8">
                    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Statistik Peserta Aktif</h2>

                    <!-- Ringkasan Keseluruhan -->
                    <div class="mb-8 p-6 bg-blue-50 rounded-lg shadow-inner border border-blue-200">
                        <h3 class="text-2xl font-semibold text-blue-800 mb-4">Ringkasan Keseluruhan</h3>
                        <div class="mb-4 grid grid-cols-1 lg:grid-cols-1 gap-4 text-center">
                            <div class="p-4 bg-white rounded-lg shadow-sm">
                                <p class="text-sm text-gray-500">Total Semua Peserta</p>
                                <p class="text-3xl font-bold text-gray-800"><?php echo $participant_stats['total_all_participants']; ?></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 lg:grid-cols-2 gap-4 text-center">
                            <div class="p-4 bg-white rounded-lg shadow-sm">
                                <p class="text-sm text-gray-500">Total Laki-laki</p>
                                <p class="text-3xl font-bold text-blue-600"><?php echo $participant_stats['overall_gender']['Laki-laki'] ?? 0; ?></p>
                            </div>
                            <div class="p-4 bg-white rounded-lg shadow-sm">
                                <p class="text-sm text-gray-500">Total Perempuan</p>
                                <p class="text-3xl font-bold text-pink-600"><?php echo $participant_stats['overall_gender']['Perempuan'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Statistik per Kelompok (Menggunakan Expander) -->
                    <div class="mb-8 p-6 bg-green-50 rounded-lg shadow-inner border border-green-200">
                        <h3 class="text-2xl font-semibold text-green-800 mb-4">Statistik per Kelompok (Klik untuk Detail)</h3>
                        <?php if (empty($participant_stats['kelompok'])): ?>
                            <p class="text-center text-gray-500">Tidak ada data kelompok peserta aktif.</p>
                        <?php else: ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <?php foreach ($participant_stats['kelompok'] as $kelompok_name => $kelompok_data): ?>
                                    <div class="expander-container bg-white rounded-lg shadow-sm border border-gray-200">
                                        <div class="expander-header flex justify-between items-center p-4 cursor-pointer hover:bg-gray-50 transition duration-200">
                                            <h4 class="text-xl font-bold text-gray-700"><?php echo htmlspecialchars($kelompok_name); ?> (Total: <?php echo $kelompok_data['total']; ?>)</h4>
                                            <svg class="w-6 h-6 text-gray-500 transform transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                        </div>
                                        <div class="expander-content hidden p-4 bg-gray-50 rounded-b-lg">
                                            <div class="space-y-4 ml-4 border-l-2 border-gray-200 pl-4">
                                                <?php if (empty($kelompok_data['kategori_usia'])): ?>
                                                    <p class="text-gray-500 text-sm">Tidak ada data kategori usia untuk kelompok ini.</p>
                                                <?php else: ?>
                                                    <?php foreach ($kelompok_data['kategori_usia'] as $usia_name => $usia_data): ?>
                                                        <div class="bg-white p-3 rounded-lg shadow-inner border border-gray-100">
                                                            <h5 class="text-lg font-semibold text-gray-700 mb-2"><?php echo htmlspecialchars($usia_name); ?> (Total: <?php echo $usia_data['total']; ?>)</h5>
                                                            <p class="text-sm text-gray-600">
                                                                Laki-laki: <span class="font-bold text-blue-600"><?php echo $usia_data['Laki-laki'] ?? 0; ?></span>,
                                                                Perempuan: <span class="font-bold text-pink-600"><?php echo $usia_data['Perempuan'] ?? 0; ?></span>
                                                            </p>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filter Kelompok dan Kategori Usia -->
                <div class="mb-6 p-6 bg-gray-50 rounded-lg shadow-inner">
                    <h3 class="text-2xl font-semibold text-gray-700 mb-4">Filter Peserta</h3>
                    <form id="filter_participants_form" action="manage_participants.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                        <div>
                            <label for="kelompok_filter" class="block text-gray-700 text-sm font-semibold mb-2">Kelompok:</label>
                            <select id="kelompok_filter" name="kelompok"
                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <?php foreach ($kelompok_options_filter as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php echo ($option === $selected_kelompok_filter) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="kategori_usia_filter" class="block text-gray-700 text-sm font-semibold mb-2">Kategori Usia:</label>
                            <select id="kategori_usia_filter" name="usia"
                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <?php foreach ($kategori_usia_options_filter as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php echo ($option === $selected_kategori_usia_filter) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-2 text-center mt-4">
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300 transform hover:scale-105">
                                Terapkan Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabel Daftar Peserta -->
                <div class="mt-8 bg-gray-50 p-4 rounded-xl shadow-inner">
                    <h3 class="text-2xl font-semibold text-gray-700 mb-4 mt-8 text-center">Daftar Peserta Aktif</h3>
                    <?php if (empty($participants)): ?>
                        <p class="text-center text-gray-500">Belum ada peserta aktif yang terdaftar.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No.</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelompok</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jenis Kelamin</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kategori Usia</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider print-hide-column">QR Code</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider print-hide-column">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php $no = 1;
                                    foreach ($participants as $participant): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $no++; ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['kelompok']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['name']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['jenis_kelamin']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['kategori_usia']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 print-hide-column">
                                                <div class="flex flex-col space-y-1">
                                                    <button onclick="generateAndShowQrCode('<?php echo htmlspecialchars($participant['barcode_data']); ?>', '<?php echo htmlspecialchars($participant['name']); ?>', '<?php echo htmlspecialchars($participant['kelompok']); ?>')"
                                                        class="inline-flex items-center justify-center px-2 py-1 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-blue-500 hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-400 transition duration-200">
                                                        Lihat QR
                                                    </button>
                                                    <button onclick="generateAndDownloadQrCode('<?php echo htmlspecialchars($participant['barcode_data']); ?>', '<?php echo htmlspecialchars($participant['name']); ?>', '<?php echo htmlspecialchars($participant['kelompok']); ?>')"
                                                        class="inline-flex items-center justify-center px-2 py-1 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-yellow-500 hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-400 transition duration-200">
                                                        Unduh QR
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium print-hide-column">
                                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($participant)); ?>)"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 mr-2">
                                                    Edit
                                                </button>
                                                <a href="manage_participants.php?delete_id=<?php echo htmlspecialchars($participant['id']); ?>"
                                                    onclick="return confirm('Apakah Anda yakin ingin menonaktifkan peserta ini? Peserta yang dinonaktifkan tidak akan muncul di daftar aktif dan tidak bisa presensi baru, tetapi data kehadirannya akan tetap ada.');"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-200">
                                                    Nonaktifkan
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>

        <!-- Footer (Opsional) -->
        <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
            <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
        </footer>
    </div>

    <!-- Modal Tambah Peserta -->
    <div id="addParticipantModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeAddModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Tambah Peserta Baru (Manual)</h3>
            <form action="manage_participants.php" method="POST">
                <div class="mb-4">
                    <label for="name" class="block text-gray-700 text-sm font-semibold mb-2">Nama Peserta:</label>
                    <input type="text" id="name" name="name" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Masukkan nama lengkap">
                </div>
                <div class="mb-4">
                    <label for="jenis_kelamin" class="block text-gray-700 text-sm font-semibold mb-2">Jenis Kelamin:</label>
                    <select id="jenis_kelamin" name="jenis_kelamin" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Jenis Kelamin</option>
                        <?php foreach ($jenis_kelamin_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="kelompok" class="block text-gray-700 text-sm font-semibold mb-2">Kelompok:</label>
                    <select id="kelompok" name="kelompok" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Kelompok</option>
                        <?php foreach ($kelompok_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-6">
                    <label for="kategori_usia" class="block text-gray-700 text-sm font-semibold mb-2">Kategori Usia:</label>
                    <select id="kategori_usia" name="kategori_usia" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Kategori Usia</option>
                        <?php foreach ($usia_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_participant"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Tambah Peserta
                </button>
            </form>
        </div>
    </div>

    <!-- Modal Import CSV -->
    <div id="importCsvModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeImportModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Import Peserta dari CSV</h3>
            <form action="manage_participants.php" method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label for="csv_file" class="block text-gray-700 text-sm font-semibold mb-2">Pilih File CSV:</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                        class="block w-full text-sm text-gray-500
                           file:mr-4 file:py-2 file:px-4
                           file:rounded-md file:border-0
                           file:text-sm file:font-semibold
                           file:bg-indigo-50 file:text-indigo-700
                           hover:file:bg-indigo-100 transition duration-200">
                    <p class="text-xs text-gray-500 mt-2">
                        Pastikan format CSV sesuai template:
                        <a href="javascript:void(0);" onclick="downloadCsvTemplate()" class="text-blue-600 hover:underline">Unduh Template CSV</a>
                    </p>
                    <p class="text-xs text-gray-500 italic mt-1">Data barcode akan dibuat otomatis oleh sistem.</p>
                </div>
                <button type="submit" name="import_csv"
                    class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Import CSV
                </button>
            </form>
        </div>
    </div>

    <!-- Modal Edit Peserta -->
    <div id="editParticipantModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Edit Peserta</h3>
            <form action="manage_participants.php" method="POST">
                <input type="hidden" id="edit_participant_id" name="participant_id">
                <input type="hidden" name="current_kelompok_filter" value="<?php echo htmlspecialchars($selected_kelompok_filter); ?>">
                <input type="hidden" name="current_usia_filter" value="<?php echo htmlspecialchars($selected_kategori_usia_filter); ?>">
                <div class="mb-4">
                    <label for="edit_name" class="block text-gray-700 text-sm font-semibold mb-2">Nama Peserta:</label>
                    <input type="text" id="edit_name" name="name" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200">
                </div>
                <div class="mb-4">
                    <label for="edit_jenis_kelamin" class="block text-gray-700 text-sm font-semibold mb-2">Jenis Kelamin:</label>
                    <select id="edit_jenis_kelamin" name="jenis_kelamin" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Jenis Kelamin</option>
                        <?php foreach ($jenis_kelamin_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="edit_kelompok" class="block text-gray-700 text-sm font-semibold mb-2">Kelompok:</label>
                    <select id="edit_kelompok" name="kelompok" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Kelompok</option>
                        <?php foreach ($kelompok_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-6">
                    <label for="edit_kategori_usia" class="block text-gray-700 text-sm font-semibold mb-2">Kategori Usia:</label>
                    <select id="edit_kategori_usia" name="kategori_usia" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Kategori Usia</option>
                        <?php foreach ($usia_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-6">
                    <label for="edit_barcode_data" class="block text-gray-700 text-sm font-semibold mb-2">Data Barcode/QR Code Unik (Otomatis):</label>
                    <input type="text" id="edit_barcode_data" name="barcode_data" readonly disabled
                        class="shadow-sm appearance-none border border-gray-200 bg-gray-100 rounded-lg w-full py-2 px-3 text-gray-700 mb-3 leading-tight">
                    <p class="text-xs text-gray-500 italic">Data barcode dibuat otomatis oleh sistem dan tidak dapat diubah.</p>
                </div>
                <button type="submit" name="edit_participant"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Perbarui Peserta
                </button>
            </form>
        </div>
    </div>

    <!-- Modal Lihat QR Code -->
    <div id="qrCodeModal" class="modal-overlay hidden">
        <div class="modal-content text-center">
            <span class="close-button" onclick="closeQrCodeModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4" id="qrModalTitle">QR Code</h3>
            <p class="text-gray-600 mb-4">Data: <span id="qrCodeDataText" class="font-mono font-semibold text-blue-700 break-all"></span></p>
            <img id="qrCodeDisplay" src="" alt="QR Code" class="mx-auto my-4 p-2 border border-gray-300 rounded-lg">
            <button onclick="closeQrCodeModal()" class="mt-4 px-5 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition duration-200">Tutup</button>
        </div>
    </div>


    <!-- Footer (Opsional) -->
    <!-- <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
        <p>&copy; <?php echo date("Y"); ?> Presensi Event Bulanan. All rights reserved.</p>
    </footer> -->

    <!-- QRious Library CDN -->
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script>
        // Sidebar Toggle Functionality
        const sidebar = document.getElementById('sidebar');
        const mainContentWrapper = document.getElementById('main-content-wrapper');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const menuToggle = document.getElementById('menu-toggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('open');
            // On desktop, sidebar is always open, so no shifting of main content
            // On mobile, main content doesn't shift, overlay covers it
        }

        // Event listener for the menu toggle button
        if (menuToggle) {
            menuToggle.addEventListener('click', toggleSidebar);
        }

        // Close sidebar when clicking overlay
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', toggleSidebar);
        }
        // Fungsi untuk membuka modal tambah peserta
        function openAddModal() {
            document.getElementById('addParticipantModal').classList.remove('hidden');
        }

        // Fungsi untuk menutup modal tambah peserta
        function closeAddModal() {
            document.getElementById('addParticipantModal').classList.add('hidden');
        }

        // Fungsi untuk membuka modal import CSV
        function openImportModal() {
            document.getElementById('importCsvModal').classList.remove('hidden');
        }

        // Fungsi untuk menutup modal import CSV
        function closeImportModal() {
            document.getElementById('importCsvModal').classList.add('hidden');
        }

        // Fungsi untuk membuka modal edit peserta
        function openEditModal(participant) {
            document.getElementById('edit_participant_id').value = participant.id;
            document.getElementById('edit_name').value = participant.name;
            document.getElementById('edit_jenis_kelamin').value = participant.jenis_kelamin;
            document.getElementById('edit_barcode_data').value = participant.barcode_data;

            // Set nilai dropdown untuk edit
            document.getElementById('edit_kelompok').value = participant.kelompok;
            document.getElementById('edit_kategori_usia').value = participant.kategori_usia;

            document.getElementById('editParticipantModal').classList.remove('hidden');
        }

        // Fungsi untuk menutup modal edit peserta
        function closeEditModal() {
            document.getElementById('editParticipantModal').classList.add('hidden');
        }

        // Tutup modal saat mengklik di luar konten modal
        document.getElementById('addParticipantModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeAddModal();
            }
        });
        document.getElementById('importCsvModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeImportModal();
            }
        });
        document.getElementById('editParticipantModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeEditModal();
            }
        });

        // --- Fungsi untuk QR Code (TANPA LOGO) ---
        const qrCodeModal = document.getElementById('qrCodeModal');
        const qrCodeDisplay = document.getElementById('qrCodeDisplay');
        const qrCodeDataText = document.getElementById('qrCodeDataText');
        const qrModalTitle = document.getElementById('qrModalTitle');

        // Fungsi untuk menghasilkan dan menampilkan/mengunduh QR code
        function generateQrCode(barcodeData, participantName, actionType, participantKelompok) {
            const canvas = document.createElement('canvas');
            const qrOptions = {
                element: canvas,
                value: barcodeData,
                size: (actionType === 'show') ? 200 : 500, // Ukuran berbeda untuk tampil/unduh
                padding: (actionType === 'show') ? 10 : 20,
                level: 'H', // Error correction level
                // Properti 'image' dan 'imageRatio' dihapus untuk menghilangkan logo
            };

            new QRious(qrOptions);

            if (actionType === 'show') {
                qrModalTitle.textContent = `QR Code untuk ${participantName}`;
                qrCodeDataText.textContent = barcodeData;
                qrCodeDisplay.src = canvas.toDataURL('image/png');
                qrCodeModal.classList.remove('hidden');
            } else if (actionType === 'download') {
                const link = document.createElement('a');
                link.href = canvas.toDataURL('image/png');
                link.download = `QR_${participantKelompok}_${participantName.replace(/\s/g, '_')}.png`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Wrapper untuk fungsi onclick
        function generateAndShowQrCode(barcodeData, participantName, participantKelompok) {
            generateQrCode(barcodeData, participantName, 'show', participantKelompok);
        }

        function generateAndDownloadQrCode(barcodeData, participantName, participantKelompok) {
            generateQrCode(barcodeData, participantName, 'download', participantKelompok);
        }


        function closeQrCodeModal() {
            qrCodeModal.classList.add('hidden');
            qrCodeDisplay.src = ''; // Bersihkan gambar saat modal ditutup
        }

        // Tutup modal QR code saat mengklik di luar konten modal
        qrCodeModal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeQrCodeModal();
            }
        });

        // Fungsi untuk mengunduh template CSV
        function downloadCsvTemplate() {
            // Header CSV baru: no,name,jenis_kelamin,kelompok,kategori_usia
            const csvContent = "no,name,jenis_kelamin,kelompok,kategori_usia\n" +
                "1,Budi Santoso,Laki-laki,Bintaran,Remaja\n" +
                "2,Siti Aminah,Perempuan,Gedongkuning,Pra Nikah\n" +
                "3,Joko Susilo,Laki-laki,Jombor,Usia Mandiri";
            const blob = new Blob([csvContent], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            if (link.download !== undefined) { // feature detection
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'template_peserta.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // --- Fungsi Hapus Semua Peserta (Super Admin Only) ---
        function confirmDeleteAllParticipants() {
            if (confirm('PERINGATAN: Ini akan menghapus SEMUA peserta dan data kehadiran terkait secara PERMANEN. Tindakan ini TIDAK dapat dibatalkan. Apakah Anda YAKIN ingin melanjutkan?')) {
                const confirmationInput = prompt('KONFIRMASI TERAKHIR: Untuk melanjutkan, ketik "HAPUS SEMUA" (tanpa tanda kutip) di bawah:');
                if (confirmationInput === 'HAPUS SEMUA') {
                    // Buat form dinamis untuk mengirim POST request
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'manage_participants.php';

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'delete_all_confirmed';
                    input.value = 'true';
                    form.appendChild(input);

                    document.body.appendChild(form);
                    form.submit(); // Kirim form
                } else {
                    alert('Konfirmasi tidak cocok. Penghapusan dibatalkan.');
                }
            } else {
                alert('Penghapusan dibatalkan.');
            }
        }

        document.querySelectorAll('.expander-header').forEach(header => {
            header.addEventListener('click', () => {
                const content = header.nextElementSibling; // Dapatkan elemen konten (sibling berikutnya)
                const icon = header.querySelector('svg'); // Dapatkan ikon SVG di dalam header

                // Toggle kelas 'hidden' untuk menampilkan/menyembunyikan konten
                content.classList.toggle('hidden');

                // Putar ikon panah
                if (content.classList.contains('hidden')) {
                    icon.classList.remove('rotate-180'); // Panah menghadap ke bawah
                } else {
                    icon.classList.add('rotate-180'); // Panah menghadap ke atas
                }
            });
        });

        // --- Fungsi untuk Mengunduh Semua QR Code ---
        async function downloadAllQrCodes() {
            if (!confirm('Ini akan mengunduh SEMUA QR Code peserta aktif satu per satu. Browser mungkin meminta konfirmasi untuk setiap unduhan. Lanjutkan?')) {
                return;
            }

            try {
                // Ambil semua barcode data dari server via AJAX
                const response = await fetch('manage_participants.php?get_all_barcodes=true');
                const barcodes = await response.json(); // Mengasumsikan respons adalah JSON array of objects

                if (barcodes.length === 0) {
                    alert('Tidak ada peserta aktif untuk diunduh QR Code-nya.');
                    return;
                }

                alert('Memulai unduhan ' + barcodes.length + ' QR Code. Mohon izinkan unduhan jika diminta oleh browser.');

                let delay = 0;
                for (const participant of barcodes) {
                    // Tambahkan sedikit jeda antar unduhan untuk mengurangi kemungkinan diblokir browser
                    setTimeout(() => {
                        // PERUBAHAN DI SINI: Teruskan participant.kelompok sebagai parameter ketiga
                        generateAndDownloadQrCode(participant.barcode_data, participant.name, participant.kelompok);
                    }, delay);
                    delay += 300; // Jeda 300ms antar setiap unduhan
                }

            } catch (error) {
                console.error('Gagal mengambil barcode data atau terjadi kesalahan unduhan:', error);
                alert('Terjadi kesalahan saat mengunduh semua QR Code. Silakan periksa konsol browser.');
            }
        }
    </script>
</body>

</html>