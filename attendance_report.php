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

// Ambil semua event untuk dropdown filter
$events = [];
$sql_events = "SELECT id, event_name, event_date FROM events ORDER BY event_date DESC";
$result_events = $conn->query($sql_events);

if ($result_events->num_rows > 0) {
    while ($row = $result_events->fetch_assoc()) {
        $events[] = $row;
    }
}

// Ambil semua kelompok unik untuk dropdown filter
$kelompok_options_filter = ['Semua Kelompok']; // Default option
$sql_kelompok = "SELECT DISTINCT kelompok FROM participants WHERE kelompok IS NOT NULL AND kelompok != '' ORDER BY kelompok ASC";
$result_kelompok = $conn->query($sql_kelompok);
if ($result_kelompok->num_rows > 0) {
    while ($row = $result_kelompok->fetch_assoc()) {
        $kelompok_options_filter[] = htmlspecialchars($row['kelompok']);
    }
}

// Dapatkan ID event yang dipilih dari GET, default ke event pertama jika ada
$selected_event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (empty($events) ? 0 : $events[0]['id']);
$selected_event_name = '';

// Dapatkan kelompok yang dipilih dari GET, default ke 'Semua Kelompok'
$selected_kelompok = isset($_GET['kelompok']) ? htmlspecialchars($_GET['kelompok']) : 'Semua Kelompok';


// Temukan nama event yang dipilih
foreach ($events as $event) {
    if ($event['id'] == $selected_event_id) {
        $selected_event_name = htmlspecialchars($event['event_name'] . " (" . date('d M Y', strtotime($event['event_date'])) . ")");
        break;
    }
}

// --- LOGIKA LAPORAN KEHADIRAN & EKSPOR CSV ---
$total_active_participants = 0;
$attended_participants_count = 0;
$izin_participants_count = 0;
$tidak_hadir_participants_count = 0;
$report_data = []; // Akan berisi semua peserta aktif dengan status kehadiran mereka

if ($selected_event_id > 0) {
    // Bangun query dasar
    $base_sql = "
        SELECT
            p.id as participant_id,
            p.name as participant_name,
            p.barcode_data,
            p.kelompok,
            p.kategori_usia,
            a.attendance_time,
            a.status as status_kehadiran
        FROM participants p
        LEFT JOIN attendances a ON p.id = a.participant_id AND a.event_id = ?
        WHERE a.attendance_time != '' ";

    $params = [$selected_event_id];
    $types = "i";

    // Tambahkan filter kelompok jika dipilih
    if ($selected_kelompok !== 'Semua Kelompok') {
        $base_sql .= " AND p.kelompok = ?";
        $params[] = $selected_kelompok;
        $types .= "s";
    }

    $base_sql .= " ORDER BY p.kelompok ASC, p.name ASC";

    $stmt_report = $conn->prepare($base_sql);

    // Bind parameter secara dinamis
    if (count($params) > 0) {
        $stmt_report->bind_param($types, ...$params);
    }

    $stmt_report->execute();
    $result_report = $stmt_report->get_result();

    if ($result_report->num_rows > 0) {
        while ($row = $result_report->fetch_assoc()) {
            // Jika status dari attendances adalah NULL (karena LEFT JOIN dan belum ada entri), set ke 'Tidak Hadir'
            if ($row['status_kehadiran'] === null) {
                $row['status_kehadiran'] = 'Tidak Hadir';
            }
            $report_data[] = $row;

            // Hitung untuk ringkasan
            if ($row['status_kehadiran'] === 'Hadir') {
                $attended_participants_count++;
            } elseif ($row['status_kehadiran'] === 'Izin') {
                $izin_participants_count++;
            } elseif ($row['status_kehadiran'] === 'Tidak Hadir') {
                $tidak_hadir_participants_count++;
            }
        }
    }
    $stmt_report->close();

    // Total peserta aktif yang sesuai dengan filter (jika ada)
    $total_active_participants = count($report_data);
}

// Pastikan Anda memiliki $selected_event_id yang valid
if ($selected_event_id > 0) {
    // Hitung persentase
    $total_all = $total_active_participants;

    $hadir_percentage = $total_all > 0 ? round(($attended_participants_count / $total_all) * 100, 2) : 0;
    $izin_percentage = $total_all > 0 ? round(($izin_participants_count / $total_all) * 100, 2) : 0;
    $tidak_hadir_percentage = $total_all > 0 ? round(($tidak_hadir_participants_count / $total_all) * 100, 2) : 0;

    // Data untuk Chart.js (dalam format JSON untuk JavaScript)
    $chart_data_js = json_encode([
        'labels' => ['Hadir', 'Izin', 'Tidak Hadir'],
        'data' => [$hadir_percentage, $izin_percentage, $tidak_hadir_percentage],
        'counts' => [$attended_participants_count, $izin_participants_count, $tidak_hadir_participants_count],
        'total' => $total_all
    ]);
    // --- BARIS DEBUGGING SEMENTARA ---
    // echo '<pre>';
    // echo 'Debug Chart Data: ';
    // print_r(json_decode($chart_data_js, true));
    // echo '</pre>';
    // --- AKHIR BARIS DEBUGGING SEMENTARA ---
} else {
    // Jika tidak ada event yang dipilih, inisialisasi data kosong
    $chart_data_js = json_encode([
        'labels' => ['Hadir', 'Izin', 'Tidak Hadir'],
        'data' => [0, 0, 0],
        'counts' => [0, 0, 0],
        'total' => 0
    ]);
}

// --- LOGIKA EKSPOR CSV ---
if (isset($_GET['export_csv']) && $selected_event_id > 0) {
    // Re-run the query to ensure we have the latest data for export
    $export_data = [];

    $base_sql_export = "
        SELECT
            p.name as participant_name,
            p.barcode_data,
            p.kelompok,
            p.kategori_usia,
            a.attendance_time,
            a.status as status_kehadiran
        FROM participants p
        LEFT JOIN attendances a ON p.id = a.participant_id AND a.event_id = ?
        WHERE a.attendance_time != '' ";

    $params_export = [$selected_event_id];
    $types_export = "i";

    if ($selected_kelompok !== 'Semua Kelompok') {
        $base_sql_export .= " AND p.kelompok = ?";
        $params_export[] = $selected_kelompok;
        $types_export .= "s";
    }
    $base_sql_export .= " ORDER BY p.kelompok ASC,  p.name ASC";

    $stmt_export = $conn->prepare($base_sql_export);
    if (count($params_export) > 0) {
        $stmt_export->bind_param($types_export, ...$params_export);
    }
    $stmt_export->execute();
    $result_export = $stmt_export->get_result();

    if ($result_export->num_rows > 0) {
        while ($row = $result_export->fetch_assoc()) {
            if ($row['status_kehadiran'] === null) {
                $row['status_kehadiran'] = 'Tidak Hadir';
            }
            $export_data[] = $row;
        }
    }
    $stmt_export->close();

    // Persiapkan data untuk CSV
    $filename = "laporan_presensi_" . str_replace(" ", "_", strtolower(str_replace(["(", ")"], "", $selected_event_name))) . ".csv";

    // Tambahkan kelompok ke nama file jika difilter
    if ($selected_kelompok !== 'Semua Kelompok') {
        $filename = "laporan_presensi_" . str_replace(" ", "_", strtolower(str_replace(["(", ")"], "", $selected_event_name))) . "_" . strtolower($selected_kelompok) . ".csv";
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Header CSV
    fputcsv($output, ['Nama Peserta', 'Data Barcode', 'Kelompok', 'Kategori Usia', 'Waktu Presensi', 'Status Kehadiran']);

    // Data CSV
    foreach ($export_data as $row) {
        fputcsv($output, [
            $row['participant_name'],
            $row['barcode_data'],
            $row['kelompok'],
            $row['kategori_usia'],
            $row['attendance_time'] ? date('d M Y H:i:s', strtotime($row['attendance_time'])) : '', // Kosong jika belum hadir
            $row['status_kehadiran']
        ]);
    }

    fclose($output);
    exit; // Penting: Hentikan eksekusi script setelah CSV dihasilkan
}

function getAttendanceSummaryByGroupAndStatus($conn, $event_id)
{
    $summary = [
        'overall' => [
            'total_all_participants' => 0,
            'hadir' => 0,
            'izin' => 0,
            'tidak_hadir' => 0
        ],
        'by_status' => [
            'Total Peserta' => [],
            'Hadir' => [],
            'Izin' => [],
            'Tidak Hadir' => []
        ]
    ];

    // Dapatkan semua kelompok yang mungkin ada di database Anda
    $kelompok_options_all = [];
    $sql_all_kelompok = "SELECT DISTINCT kelompok FROM participants WHERE kelompok IS NOT NULL AND kelompok != '' ORDER BY kelompok ASC";
    $result_all_kelompok = $conn->query($sql_all_kelompok);
    if ($result_all_kelompok && $result_all_kelompok->num_rows > 0) {
        while ($row = $result_all_kelompok->fetch_assoc()) {
            $kelompok_options_all[] = $row['kelompok'];
        }
    }

    // Inisialisasi summary untuk setiap kelompok di setiap jenis status dengan 0
    foreach ($kelompok_options_all as $kelompok_name) {
        $summary['by_status']['Total Peserta'][$kelompok_name] = 0;
        $summary['by_status']['Hadir'][$kelompok_name] = 0;
        $summary['by_status']['Izin'][$kelompok_name] = 0;
        $summary['by_status']['Tidak Hadir'][$kelompok_name] = 0;
    }

    // Kembalikan array kosong jika ID event tidak valid
    if ($event_id <= 0) {
        return $summary;
    }

    // Query untuk mengambil semua peserta aktif beserta status kehadirannya untuk event tertentu
    $sql = "
        SELECT 
            p.kelompok, 
            a.status as status_kehadiran
        FROM participants p
        LEFT JOIN attendances a ON p.id = a.participant_id AND a.event_id = ?
        WHERE a.attendance_time != '' ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // Handle error jika prepare gagal
        error_log("Failed to prepare statement for getAttendanceSummaryByGroupAndStatus: " . $conn->error);
        return $summary;
    }

    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $kelompok = $row['kelompok'];
            $status = $row['status_kehadiran'] ?? 'Tidak Hadir'; // Jika null, berarti Tidak Hadir

            // Pastikan kelompok ada dalam daftar yang diinisialisasi
            if (in_array($kelompok, $kelompok_options_all)) {
                $summary['overall']['total_all_participants']++;
                $summary['by_status']['Total Peserta'][$kelompok]++;

                if ($status === 'Hadir') {
                    $summary['overall']['hadir']++;
                    $summary['by_status']['Hadir'][$kelompok]++;
                } elseif ($status === 'Izin') {
                    $summary['overall']['izin']++;
                    $summary['by_status']['Izin'][$kelompok]++;
                } else { // 'Tidak Hadir'
                    $summary['overall']['tidak_hadir']++;
                    $summary['by_status']['Tidak Hadir'][$kelompok]++;
                }
            }
        }
    }
    $stmt->close();
    return $summary;
}

// Panggil fungsi rekapan per kelompok hanya jika filter kelompok adalah "Semua Kelompok"
$rekap_kehadiran_terstruktur = getAttendanceSummaryByGroupAndStatus($conn, $selected_event_id);

// Ambil data overall dari rekap
$total_active_participants = $rekap_kehadiran_terstruktur['overall']['total_all_participants'];
$attended_participants_count = $rekap_kehadiran_terstruktur['overall']['hadir'];
$izin_participants_count = $rekap_kehadiran_terstruktur['overall']['izin'];
$tidak_hadir_participants_count = $rekap_kehadiran_terstruktur['overall']['tidak_hadir'];
// Total Peserta
$total_active_bintaran = $rekap_kehadiran_terstruktur['by_status']['Total Peserta']['Bintaran'];
$total_active_gedongkuning = $rekap_kehadiran_terstruktur['by_status']['Total Peserta']['Gedongkuning'];
$total_active_jombor = $rekap_kehadiran_terstruktur['by_status']['Total Peserta']['Jombor'];
$total_active_sunten = $rekap_kehadiran_terstruktur['by_status']['Total Peserta']['Sunten'];
// Hadir
$hadir_bintaran = $rekap_kehadiran_terstruktur['by_status']['Hadir']['Bintaran'];
$hadir_gedongkuning = $rekap_kehadiran_terstruktur['by_status']['Hadir']['Gedongkuning'];
$hadir_jombor = $rekap_kehadiran_terstruktur['by_status']['Hadir']['Jombor'];
$hadir_sunten = $rekap_kehadiran_terstruktur['by_status']['Hadir']['Sunten'];
// Izin
$izin_bintaran = $rekap_kehadiran_terstruktur['by_status']['Izin']['Bintaran'];
$izin_gedongkuning = $rekap_kehadiran_terstruktur['by_status']['Izin']['Gedongkuning'];
$izin_jombor = $rekap_kehadiran_terstruktur['by_status']['Izin']['Jombor'];
$izin_sunten = $rekap_kehadiran_terstruktur['by_status']['Izin']['Sunten'];
// Tidak Hadir
$tidak_hadir_bintaran = $rekap_kehadiran_terstruktur['by_status']['Tidak Hadir']['Bintaran'];
$tidak_hadir_gedongkuning = $rekap_kehadiran_terstruktur['by_status']['Tidak Hadir']['Gedongkuning'];
$tidak_hadir_jombor = $rekap_kehadiran_terstruktur['by_status']['Tidak Hadir']['Jombor'];
$tidak_hadir_sunten = $rekap_kehadiran_terstruktur['by_status']['Tidak Hadir']['Sunten'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Presensi - <?php echo htmlspecialchars($selected_event_name); ?></title>
    <link rel="icon" href="images/logo_kmm.jpg" type="image/png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
            transition: margin-left 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .main-content-wrapper.shifted {
            margin-left: 250px;
            /* Adjust if sidebar is open */
        }

        .menu-toggle-button {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            /* Above sidebar */
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
                /* Always open on desktop */
            }

            .main-content-wrapper {
                margin-left: 250px;
                /* Always shifted on desktop */
            }

            .menu-toggle-button {
                display: none;
                /* Hide toggle button on desktop */
            }
        }

        /* --- Print Specific Styles --- */
        @media print {
            body {
                background-color: #fff !important;
                /* White background for print */
                margin: 0;
                padding: 0;
            }

            /* Hide web-only UI elements */
            .sidebar,
            .sidebar-overlay,
            .menu-toggle-button,
            .main-content-wrapper>header,
            .main-content-wrapper>footer,
            .web-only-content {
                display: none !important;
            }

            .main-content-wrapper {
                margin-left: 0 !important;
                /* Remove sidebar margin */
                width: 100% !important;
                padding: 0 !important;
                min-height: auto !important;
            }

            .container {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 auto !important;
                max-width: 100% !important;
            }

            .bg-white {
                background-color: #fff !important;
            }

            .bg-gray-50 {
                background-color: #f9fafb !important;
                /* Lighter gray for table header */
            }

            table {
                width: 100% !important;
                border-collapse: collapse;
            }

            th,
            td {
                border: 1px solid #e5e7eb !important;
                /* Light gray border */
                padding: 8px 12px !important;
                font-size: 10pt !important;
            }

            h1,
            h2,
            h3 {
                color: #000 !important;
                /* Black text for headings */
                text-align: center !important;
            }

            /* Print-only content styles */
            .print-only-content {
                display: block !important;
                /* Show this block only on print */
                padding: 0px;
                /* Add some padding for the print content */
            }

            .letterhead {
                display: block !important;
                /* Show letterhead only on print */
                padding-bottom: 20px;
                /* Space after letterhead */
                margin-bottom: 20px;
                border-bottom: 1px solid #000;
                /* Line under letterhead */
            }

            .letterhead-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }

            .letterhead-logo {
                max-width: 80px;
                /* Adjust logo size for print */
                height: auto;
            }

            .letterhead-info {
                text-align: center;
                flex-grow: 1;
            }

            .letterhead-info h4 {
                font-size: 14pt;
                font-weight: bold;
                margin: 0;
            }

            .letterhead-info p {
                font-size: 9pt;
                margin: 2px 0;
            }

            .signature-block {
                display: block !important;
                /* Show signature block only on print */
                width: 250px;
                /* Lebar blok tanda tangan */
                margin-top: 20px;
                /* Jarak dari tabel */
                margin-left: auto;
                /* Posisikan ke kanan */
                text-align: center;
            }

            .signature-block p {
                margin: 5px 0;
                font-size: 10pt;
            }

            .signature-line {
                border-bottom: 1px solid #000;
                width: 80%;
                /* Panjang garis tanda tangan */
                margin: 5px auto 5px auto;
                /* Jarak untuk tanda tangan */
            }

            .print-report-title {
                text-align: center;
                font-size: 18pt;
                margin-top: 20px;
                margin-bottom: 15px;
                color: #000;
            }

            .print-event-info {
                text-align: center;
                font-size: 14pt;
                margin-bottom: 5px;
                color: #000;
            }

            .print-event-info-level {
                text-align: center;
                font-size: 12pt;
                margin-bottom: 20px;
                color: #000;
            }

            .print-summary-grid {
                display: grid;
                /* Use grid for summary layout in print */
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-bottom: 20px;
            }

            .print-summary-item {
                border: 1px solid #ccc;
                padding: 10px;
                text-align: center;
                background-color: #fff;
                /* Ensure white background */
            }

            .print-summary-item strong {
                font-size: 14pt;
                display: block;
                margin-bottom: 5px;
            }

            .print-summary-item span {
                font-size: 18pt;
                /* Adjust font size for print summary numbers */
            }

            .print-summary-item p {
                font-size: 12pt;
                /* Adjust font size for print summary numbers */
            }

            .print-detail-section {
                margin-top: 30px;
            }

            .print-detail-section h3 {
                font-size: 12pt;
                margin-bottom: 15px;
            }

            /* Tampilkan container grafik cetak dan sembunyikan yang web */
            .web-only-chart-block {
                /* Asumsi Anda memberi kelas ini ke div chart web */
                display: none !important;
            }

            .print-chart-block {
                display: block !important;
                margin-top: 20px !important;
                /* Sesuaikan margin */
                margin-bottom: 20px !important;
                padding: 10px !important;
                border: 1px solid #e5e7eb !important;
                box-shadow: none !important;
            }

            .print-chart-block .chart-container {
                width: 100% !important;
                max-width: 400px !important;
                /* Sesuaikan ukuran maks untuk cetak */
                height: 400px !important;
                /* Sesuaikan tinggi untuk cetak */
                margin: 0 auto !important;
                /* Pusatkan */
            }

            /* Pastikan elemen canvas di dalamnya tidak disembunyikan oleh parent */
            .print-chart-block canvas {
                display: block !important;
            }
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex">

    <!-- Sidebar -->
    <div id="sidebar" class="sidebar flex flex-col p-4">
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
                <li><a href="manage_participants.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h2a2 2 0 002-2V9.828a2 2 0 00-.586-1.414l-4.414-4.414A2 2 0 0012.172 2H5a2 2 0 00-2 2v16a2 2 0 002 2h12z"></path>
                        </svg>
                        Manajemen Peserta
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
                <li><a href="attendance_report.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
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
            <h1 class="text-2xl font-bold ml-12">Laporan Presensi</h1>
            <!-- Optional: Add other top bar elements if needed -->
        </header>

        <!-- Main Content Area -->
        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200">
                <!-- Konten yang hanya terlihat di web -->
                <div class="web-only-content">
                    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Laporan Kehadiran Event</h2>

                    <?php if (!empty($message)): ?>
                        <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-6 p-6 bg-gray-50 rounded-lg shadow-inner">
                        <h3 class="text-2xl font-semibold text-gray-700 mb-4">Pilih Event dan Filter</h3>
                        <form id="filter_form" action="attendance_report.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="event_selector" class="block text-gray-700 text-sm font-semibold mb-2">Pilih Event:</label>
                                <select id="event_selector" name="event_id"
                                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                    <?php if (empty($events)): ?>
                                        <option value="0">Tidak ada event tersedia</option>
                                    <?php else: ?>
                                        <?php foreach ($events as $event): ?>
                                            <option value="<?php echo htmlspecialchars($event['id']); ?>"
                                                <?php echo ($event['id'] == $selected_event_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($event['event_name']) . " (" . date('d M Y', strtotime($event['event_date'])) . ")"; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <?php if (empty($events)): ?>
                                    <p class="text-red-500 text-xs mt-2">Mohon buat event terlebih dahulu di halaman Manajemen Event.</p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="kelompok_filter" class="block text-gray-700 text-sm font-semibold mb-2">Filter Kelompok:</label>
                                <select id="kelompok_filter" name="kelompok"
                                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                    <?php foreach ($kelompok_options_filter as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>"
                                            <?php echo ($option === $selected_kelompok) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2 text-center mt-4">
                                <button type="submit" class="px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300 transform hover:scale-105">
                                    Terapkan Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if ($selected_event_id > 0): ?>
                        <div class="mb-8 p-6 bg-white rounded-xl shadow-lg border border-gray-200 web-only-chart-block">
                            <h3 class="text-2xl font-bold text-gray-800 mb-4 text-center">Persentase Kehadiran Event</h3>
                            <div class="chart-container relative w-full mx-auto" style="max-width: 400px; height: 400px;">
                                <canvas id="attendancePieChart"></canvas>
                            </div>
                            <!-- <div class="mt-4 text-center text-gray-600">
                                <p>Total peserta aktif yang difilter: <span class="font-bold"><?php echo $total_active_participants; ?></span></p>
                            </div> -->
                        </div>
                        <!-- Ringkasan Laporan (Web) -->
                        <div class="mb-8 p-6 bg-blue-50 rounded-lg shadow-inner border border-blue-200">
                            <h3 class="text-2xl font-semibold text-blue-800 mb-4">Ringkasan Kehadiran: <?php echo $selected_event_name; ?></h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-center">
                                <div class="p-4 bg-white rounded-lg shadow-sm summary-box">
                                    <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                                    ?>
                                        <p class="text-sm text-gray-500">Total Peserta</p>
                                        <p class="text-3xl font-bold text-gray-800"><?php echo $total_active_participants; ?></p>
                                        <hr class="my-2 border-t-2 border-gray-500">
                                        <p class="text-sm text-gray-800">Bintaran : <?php echo $total_active_bintaran; ?></p>
                                        <p class="text-sm text-gray-800">Gedongkuning : <?php echo $total_active_gedongkuning; ?></p>
                                        <p class="text-sm text-gray-800">Jombor : <?php echo $total_active_jombor; ?></p>
                                        <p class="text-sm text-gray-800">Sunten : <?php echo $total_active_sunten; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Bintaran') {
                                    ?>
                                        <p class="text-sm text-gray-500">Total Peserta</p>
                                        <p class="text-3xl font-bold text-gray-800"><?php echo $total_active_bintaran; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                                    ?>
                                        <p class="text-sm text-gray-500">Total Peserta</p>
                                        <p class="text-3xl font-bold text-gray-800"><?php echo $total_active_gedongkuning; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Jombor') {
                                    ?>
                                        <p class="text-sm text-gray-500">Total Peserta</p>
                                        <p class="text-3xl font-bold text-gray-800"><?php echo $total_active_jombor; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Sunten') {
                                    ?>
                                        <p class="text-sm text-gray-500">Total Peserta</p>
                                        <p class="text-3xl font-bold text-gray-800"><?php echo $total_active_sunten; ?></p>
                                    <?php
                                    }
                                    ?>
                                </div>
                                <div class="p-4 bg-white rounded-lg shadow-sm summary-box">
                                    <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                                    ?>
                                        <p class="text-sm text-gray-500">Hadir</p>
                                        <p class="text-3xl font-bold text-green-600"><?php echo $attended_participants_count; ?></p>
                                        <hr class="my-2 border-t-2 border-gray-500">
                                        <p class="text-sm text-green-800">Bintaran : <?php echo $hadir_bintaran; ?></p>
                                        <p class="text-sm text-green-800">Gedongkuning : <?php echo $hadir_gedongkuning; ?></p>
                                        <p class="text-sm text-green-800">Jombor : <?php echo $hadir_jombor; ?></p>
                                        <p class="text-sm text-green-800">Sunten : <?php echo $hadir_sunten; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Bintaran') {
                                    ?>
                                        <p class="text-sm text-gray-500">Hadir</p>
                                        <p class="text-3xl font-bold text-green-800"><?php echo $hadir_bintaran; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                                    ?>
                                        <p class="text-sm text-gray-500">Hadir</p>
                                        <p class="text-3xl font-bold text-green-800"><?php echo $hadir_gedongkuning; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Jombor') {
                                    ?>
                                        <p class="text-sm text-gray-500">Hadir</p>
                                        <p class="text-3xl font-bold text-green-800"><?php echo $hadir_jombor; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Sunten') {
                                    ?>
                                        <p class="text-sm text-gray-500">Hadir</p>
                                        <p class="text-3xl font-bold text-green-800"><?php echo $hadir_sunten; ?></p>
                                    <?php
                                    }
                                    ?>
                                </div>
                                <div class="p-4 bg-white rounded-lg shadow-sm summary-box">
                                    <!-- <p class="text-sm text-gray-500">Izin</p>
                                    <p class="text-3xl font-bold text-yellow-600"><?php echo $izin_participants_count; ?></p> -->
                                    <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                                    ?>
                                        <p class="text-sm text-gray-500">Izin</p>
                                        <p class="text-3xl font-bold text-yellow-600"><?php echo $izin_participants_count; ?></p>
                                        <hr class="my-2 border-t-2 border-gray-500">
                                        <p class="text-sm text-yellow-800">Bintaran : <?php echo $izin_bintaran; ?></p>
                                        <p class="text-sm text-yellow-800">Gedongkuning : <?php echo $izin_gedongkuning; ?></p>
                                        <p class="text-sm text-yellow-800">Jombor : <?php echo $izin_jombor; ?></p>
                                        <p class="text-sm text-yellow-800">Sunten : <?php echo $izin_sunten; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Bintaran') {
                                    ?>
                                        <p class="text-sm text-gray-500">Izin</p>
                                        <p class="text-3xl font-bold text-yellow-800"><?php echo $izin_bintaran; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                                    ?>
                                        <p class="text-sm text-gray-500">Izin</p>
                                        <p class="text-3xl font-bold text-yellow-800"><?php echo $izin_gedongkuning; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Jombor') {
                                    ?>
                                        <p class="text-sm text-gray-500">Izin</p>
                                        <p class="text-3xl font-bold text-yellow-800"><?php echo $izin_jombor; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Sunten') {
                                    ?>
                                        <p class="text-sm text-gray-500">Izin</p>
                                        <p class="text-3xl font-bold text-yellow-800"><?php echo $izin_sunten; ?></p>
                                    <?php
                                    }
                                    ?>
                                </div>
                                <div class="p-4 bg-white rounded-lg shadow-sm summary-box">
                                    <!-- <p class="text-sm text-gray-500">Tidak Hadir</p>
                                    <p class="text-3xl font-bold text-red-600"><?php echo $tidak_hadir_participants_count; ?></p> -->
                                    <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                                    ?>
                                        <p class="text-sm text-gray-500">Tidak Hadir</p>
                                        <p class="text-3xl font-bold text-red-600"><?php echo $tidak_hadir_participants_count; ?></p>
                                        <hr class="my-2 border-t-2 border-gray-500">
                                        <p class="text-sm text-red-800">Bintaran : <?php echo $tidak_hadir_bintaran; ?></p>
                                        <p class="text-sm text-red-800">Gedongkuning : <?php echo $tidak_hadir_gedongkuning; ?></p>
                                        <p class="text-sm text-red-800">Jombor : <?php echo $tidak_hadir_jombor; ?></p>
                                        <p class="text-sm text-red-800">Sunten : <?php echo $tidak_hadir_sunten; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Bintaran') {
                                    ?>
                                        <p class="text-sm text-gray-500">Tidak Hadir</p>
                                        <p class="text-3xl font-bold text-red-800"><?php echo $tidak_hadir_bintaran; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                                    ?>
                                        <p class="text-sm text-gray-500">Tidak Hadir</p>
                                        <p class="text-3xl font-bold text-red-800"><?php echo $tidak_hadir_gedongkuning; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Jombor') {
                                    ?>
                                        <p class="text-sm text-gray-500">Tidak Hadir</p>
                                        <p class="text-3xl font-bold text-red-800"><?php echo $tidak_hadir_jombor; ?></p>
                                    <?php
                                    } elseif ($_GET['kelompok'] == 'Sunten') {
                                    ?>
                                        <p class="text-sm text-gray-500">Tidak Hadir</p>
                                        <p class="text-3xl font-bold text-red-800"><?php echo $tidak_hadir_sunten; ?></p>
                                    <?php
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="mt-6 flex flex-wrap justify-center gap-4">
                                <a href="attendance_report.php?event_id=<?php echo htmlspecialchars($selected_event_id); ?>&kelompok=<?php echo htmlspecialchars($selected_kelompok); ?>&export_csv=1"
                                    class="inline-flex items-center px-6 py-3 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition duration-300 transform hover:scale-105">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    Unduh Laporan CSV
                                </a>
                                <button onclick="window.print()"
                                    class="inline-flex items-center px-6 py-3 bg-red-600 text-white font-semibold rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-300 transform hover:scale-105">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4"></path>
                                    </svg>
                                    Unduh Laporan PDF
                                </button>
                            </div>
                        </div>

                        <!-- Daftar Kehadiran Detail (Web) -->
                        <div class="mt-8 bg-gray-50 p-4 rounded-xl shadow-inner">
                            <h3 class="text-2xl font-semibold text-gray-700 mb-4 text-center">Detail Kehadiran Peserta Aktif</h3>
                            <?php if (empty($report_data)): ?>
                                <p class="text-center text-gray-500">Belum ada peserta aktif yang terdaftar atau data kehadiran untuk event ini dengan filter yang dipilih.</p>
                            <?php else: ?>
                                <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No.</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Peserta</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelompok</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kategori Usia</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Presensi</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php $no = 1;
                                            foreach ($report_data as $row): ?>
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $no++; ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['participant_name']); ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['kelompok']); ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($row['kategori_usia']); ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo $row['attendance_time'] ? convertDbTimeToWib(strtotime($row['attendance_time'])) : '-'; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold">
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $row['status_kehadiran'] === 'Hadir' ? 'bg-green-100 text-green-800' : ($row['status_kehadiran'] === 'Izin' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                                            <?php echo htmlspecialchars($row['status_kehadiran']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-6 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg">
                            <p class="font-semibold">Peringatan:</p>
                            <p>Silakan pilih event untuk melihat laporan presensi.</p>
                            <p>Jika tidak ada event, Anda dapat menambahkannya melalui halaman "Manajemen Event".</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Konten yang hanya terlihat saat dicetak -->
                <div class="print-only-content hidden">
                    <!-- Kop Surat untuk Cetak -->
                    <div class="letterhead">
                        <div class="letterhead-content">
                            <img src="images/logo_kmm.png" alt="Logo Kiri" class="letterhead-logo"> <!-- Ganti path logo1.png -->
                            <div class="letterhead-info">
                                <p style="font-weight: bold;">LEMBAGA DAKWAH ISLAM INDONESIA</p>
                                <h4>KMM BANGUNTAPAN 1</h4> <!-- Ganti dengan nama instansi Anda -->
                                <p>Jl. Sunten No.82, Kec. Banguntapan Kab. Bantul, Daerah Istimewa Yogyakarta.</p> <!-- Ganti dengan alamat -->
                                <p>Email : kmmbanguntapan354@gmail.com. Kode Pos : 55198</p> <!-- Ganti dengan kontak -->
                            </div>
                            <img src="images/logo_ldii.png" alt="Logo Kanan" class="letterhead-logo"> <!-- Ganti path logo2.png -->
                        </div>
                    </div>
                    <!-- Garis Pemisah Kop Surat -->
                    <!-- <hr style="border: none; border-top: 1px solid #000; margin: 20px 0;"> -->

                    <!-- Judul Laporan (hanya untuk cetak) -->
                    <h2 class="print-report-title">Laporan Presensi Event</h2>
                    <h3 class="print-event-info" style="padding-bottom: -px;"><?php echo $selected_event_name; ?></h3>
                    <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                    ?>
                        <h3 class="print-event-info-level">Desa Banguntapan 1</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Bintaran') {
                    ?>
                        <h3 class="print-event-info-level">Kelompok Bintaran</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                    ?>
                        <h3 class="print-event-info-level">Kelompok Gedongkuning</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Jombor') {
                    ?>
                        <h3 class="print-event-info-level">Kelompok Jombor</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Sunten') {
                    ?>
                        <h3 class="print-event-info-level">Kelompok Sunten</h3>
                    <?php
                    }
                    ?>

                    <!-- Ringkasan Laporan (hanya untuk cetak) -->
                    <div class="print-summary-grid">
                        <div class="print-summary-item">
                            <!-- <strong>Total Peserta</strong>
                            <span style="color: #333;"><?php echo $total_active_participants; ?></span> -->
                            <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                            ?>
                                <strong>Total Peserta</strong>
                                <span style="color: #333;"><?php echo $total_active_participants; ?></span>
                                <hr class="my-2 border-t-2 border-gray-500">
                                <p style="color: #333;">Bintaran : <?php echo $total_active_bintaran; ?></p>
                                <p style="color: #333;">Gedongkuning : <?php echo $total_active_gedongkuning; ?></p>
                                <p style="color: #333;">Jombor : <?php echo $total_active_jombor; ?></p>
                                <p style="color: #333;">Sunten : <?php echo $total_active_sunten; ?></p>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Bintaran') {
                            ?>
                                <strong>Total Peserta</strong>
                                <span style="color: #333;"><?php echo $total_active_bintaran; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                            ?>
                                <strong>Total Peserta</strong>
                                <span style="color: #333;"><?php echo $total_active_gedongkuning; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Jombor') {
                            ?>
                                <strong>Total Peserta</strong>
                                <span style="color: #333;"><?php echo $total_active_jombor; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Sunten') {
                            ?>
                                <strong>Total Peserta</strong>
                                <span style="color: #333;"><?php echo $total_active_sunten; ?></span>
                            <?php
                            }
                            ?>
                        </div>
                        <div class="print-summary-item">
                            <!-- <strong>Hadir</strong>
                            <span style="color: #28a745;"><?php echo $attended_participants_count; ?></span> -->
                            <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                            ?>
                                <strong>Hadir</strong>
                                <span style="color: #28a745;"><?php echo $attended_participants_count; ?></span>
                                <hr class="my-2 border-t-2 border-gray-500">
                                <p style="color: #28a745;">Bintaran : <?php echo $hadir_bintaran; ?></p>
                                <p style="color: #28a745;">Gedongkuning : <?php echo $hadir_gedongkuning; ?></p>
                                <p style="color: #28a745;">Jombor : <?php echo $hadir_jombor; ?></p>
                                <p style="color: #28a745;">Sunten : <?php echo $hadir_sunten; ?></p>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Bintaran') {
                            ?>
                                <strong>Hadir</strong>
                                <span style="color: #28a745;"><?php echo $hadir_bintaran; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                            ?>
                                <strong>Hadir</strong>
                                <span style="color: #28a745;"><?php echo $hadir_gedongkuning; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Jombor') {
                            ?>
                                <strong>Hadir</strong>
                                <span style="color: #28a745;"><?php echo $hadir_jombor; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Sunten') {
                            ?>
                                <strong>Hadir</strong>
                                <span style="color: #28a745;"><?php echo $hadir_sunten; ?></span>
                            <?php
                            }
                            ?>
                        </div>
                        <div class="print-summary-item">
                            <!-- <strong>Izin</strong>
                            <span style="color: #ffc107;"><?php echo $izin_participants_count; ?></span> -->
                            <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                            ?>
                                <strong>Izin</strong>
                                <span style="color: #ffc107;"><?php echo $izin_participants_count; ?></span>
                                <hr class="my-2 border-t-2 border-gray-500">
                                <p style="color: #ffc107;">Bintaran : <?php echo $izin_bintaran; ?></p>
                                <p style="color: #ffc107;">Gedongkuning : <?php echo $izin_gedongkuning; ?></p>
                                <p style="color: #ffc107;">Jombor : <?php echo $izin_jombor; ?></p>
                                <p style="color: #ffc107;">Sunten : <?php echo $izin_sunten; ?></p>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Bintaran') {
                            ?>
                                <strong>Izin</strong>
                                <span style="color: #ffc107;"><?php echo $izin_bintaran; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                            ?>
                                <strong>Izin</strong>
                                <span style="color: #ffc107;"><?php echo $izin_gedongkuning; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Jombor') {
                            ?>
                                <strong>Izin</strong>
                                <span style="color: #ffc107;"><?php echo $izin_jombor; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Sunten') {
                            ?>
                                <strong>Izin</strong>
                                <span style="color: #ffc107;"><?php echo $izin_sunten; ?></span>
                            <?php
                            }
                            ?>
                        </div>
                        <div class="print-summary-item">
                            <!-- <strong>Tidak Hadir</strong>
                            <span style="color: #dc3545;"><?php echo $tidak_hadir_participants_count; ?></span> -->
                            <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                            ?>
                                <strong>Tidak Hadir</strong>
                                <span style="color: #dc3545;"><?php echo $tidak_hadir_participants_count; ?></span>
                                <hr class="my-2 border-t-2 border-gray-500">
                                <p style="color: #dc3545;">Bintaran : <?php echo $tidak_hadir_bintaran; ?></p>
                                <p style="color: #dc3545;">Gedongkuning : <?php echo $tidak_hadir_gedongkuning; ?></p>
                                <p style="color: #dc3545;">Jombor : <?php echo $tidak_hadir_jombor; ?></p>
                                <p style="color: #dc3545;">Sunten : <?php echo $tidak_hadir_sunten; ?></p>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Bintaran') {
                            ?>
                                <strong>Tidak Hadir</strong>
                                <span style="color: #dc3545;"><?php echo $tidak_hadir_bintaran; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                            ?>
                                <strong>Tidak Hadir</strong>
                                <span style="color: #dc3545;"><?php echo $tidak_hadir_gedongkuning; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Jombor') {
                            ?>
                                <strong>Tidak Hadir</strong>
                                <span style="color: #dc3545;"><?php echo $tidak_hadir_jombor; ?></span>
                            <?php
                            } elseif ($_GET['kelompok'] == 'Sunten') {
                            ?>
                                <strong>Tidak Hadir</strong>
                                <span style="color: #dc3545;"><?php echo $tidak_hadir_sunten; ?></span>
                            <?php
                            }
                            ?>
                        </div>
                    </div>
                    <div class="mt-8 mb-8 p-6 bg-white rounded-xl shadow-lg border border-gray-200 print-chart-block">
                        <h3 class="text-2xl font-bold text-gray-800 mb-4 text-center">Persentase Kehadiran Event</h3>
                        <div class="chart-container relative w-full mx-auto" style="max-width: 400px; height: 400px;">
                            <canvas id="attendancePieChartPrint"></canvas>
                        </div>
                    </div>
                    <!-- Daftar Kehadiran Detail (hanya untuk cetak) -->
                    <div class="print-detail-section">
                        <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                        ?>
                            <br><br><br><br><br>
                        <?php
                        }
                        ?>
                        <h3>Detail Kehadiran Peserta Aktif:</h3>
                        <table border="1" cellpadding="5" cellspacing="0" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background-color: #f2f2f2;">
                                    <th style="width: 5%; text-align: center; font-weight: bold;">No.</th>
                                    <th style="width: 25%; text-align: left; font-weight: bold;">Nama Peserta</th>
                                    <th style="width: 20%; text-align: left; font-weight: bold;">Kelompok</th>
                                    <th style="width: 20%; text-align: left; font-weight: bold;">Kategori Usia</th>
                                    <th style="width: 15%; text-align: center; font-weight: bold;">Waktu Presensi</th>
                                    <th style="width: 15%; text-align: center; font-weight: bold;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1;
                                foreach ($report_data as $row): ?>
                                    <tr>
                                        <td style="text-align: center;"><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($row['participant_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['kelompok']); ?></td>
                                        <td><?php echo htmlspecialchars($row['kategori_usia']); ?></td>
                                        <td style="text-align: center;">
                                            <?php echo $row['attendance_time'] ? date('d M Y H:i:s', strtotime($row['attendance_time'])) : '-'; ?>
                                        </td>
                                        <td style="text-align: center; color: <?php echo $row['status_kehadiran'] === 'Hadir' ? '#28a745' : ($row['status_kehadiran'] === 'Izin' ? '#ffc107' : '#dc3545'); ?>; font-weight: bold;">
                                            <?php echo htmlspecialchars($row['status_kehadiran']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Tanda Tangan untuk Cetak (Hanya Muncul Saat Dicetak) -->
                    <div class="signature-block">
                        <p>Yogyakarta, <?php echo date('d M Y'); ?></p>
                        <p>Mengetahui,</p>
                        <!-- <br><br> Ruang untuk tanda tangan -->
                        <img src="images/ttd.png" alt="Tanda Tangan" style="width: 150px; height: auto; display: block; margin: 0 auto;"> <!-- Tambahkan baris ini -->
                        <div class="signature-line"></div>
                        <p>(Panca Aulia Rahman)</p> <!-- Ganti dengan nama penanggung jawab -->
                        <p>Ketua KMM Banguntapan 1</p> <!-- Ganti dengan jabatan -->
                    </div>
                    <!-- Akhir Tanda Tangan untuk Cetak -->
                </div>
                <!-- Akhir Konten Cetak -->

            </div>
        </main>

        <!-- Footer (Opsional) -->
        <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
            <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
        </footer>
    </div>

    <script>
        const selectedEventId = <?php echo htmlspecialchars($selected_event_id ?? 0); ?>;
        // Sidebar Toggle Functionality
        const sidebar = document.getElementById('sidebar');
        const mainContentWrapper = document.getElementById('main-content-wrapper');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const menuToggle = document.getElementById('menu-toggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('open');
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', toggleSidebar);
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', toggleSidebar);
        }

        window.addEventListener('load', () => {
            if (window.innerWidth >= 768) {
                mainContentWrapper.classList.add('shifted');
            }
            // Panggil renderAttendancePieChart untuk canvas tampilan web
            renderAttendancePieChart('attendancePieChart');
            // Panggil renderAttendancePieChart untuk canvas tampilan cetak
            renderAttendancePieChart('attendancePieChartPrint');
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                mainContentWrapper.classList.add('shifted');
                sidebar.classList.remove('open');
                sidebarOverlay.classList.remove('open');
            } else {
                mainContentWrapper.classList.remove('shifted');
            }
        });

        // Event listener for filter form submission
        document.getElementById('filter_form').addEventListener('submit', function(event) {
            // No need to prevent default, just let the form submit
        });

        // Data dari PHP, pastikan sudah di-encode JSON
        const chartData = <?php echo $chart_data_js; ?>;

        function renderAttendancePieChart(canvasId) {
            // Hanya render chart jika ada event yang dipilih dan total peserta > 0
            if (selectedEventId > 0 && chartData.total > 0) {
                const ctx = document.getElementById(canvasId).getContext('2d');
                // Pastikan Chart.js sudah di-load (CDN di HTML)
                if (typeof Chart === 'undefined') {
                    console.error("Chart.js belum dimuat.");
                    return;
                }
                const attendancePieChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            data: chartData.data,
                            backgroundColor: [
                                '#4CAF50', // Hadir
                                '#FFC107', // Izin
                                '#F44336' // Tidak Hadir
                            ],
                            borderColor: '#ffffff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: {
                                        size: 14
                                    },
                                    generateLabels: function(chart) {
                                        const data = chart.data.datasets[0].data;
                                        const labels = chart.data.labels;
                                        const counts = chartData.counts;
                                        const total = chartData.total;

                                        return labels.map((label, i) => {
                                            const percentage = total > 0 ? (data[i]).toFixed(2) : 0;
                                            return {
                                                text: `${label}: ${counts[i]} (${percentage}%)`,
                                                fillStyle: chart.data.datasets[0].backgroundColor[i],
                                                strokeStyle: chart.data.datasets[0].borderColor,
                                                lineWidth: chart.data.datasets[0].borderWidth,
                                                hidden: chart.getDatasetMeta(0).data[i].hidden,
                                                index: i
                                            };
                                        });
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed !== null) {
                                            label += context.raw + '%';
                                            if (chartData.counts && chartData.counts[context.dataIndex] !== undefined) {
                                                label += ` (${chartData.counts[context.dataIndex]} peserta)`;
                                            }
                                        }
                                        return label;
                                    }
                                }
                            },
                            title: {
                                display: false
                            }
                        }
                    }
                });
            } else {
                // Tampilkan pesan jika tidak ada data atau event tidak dipilih
                const chartContainer = document.querySelector(`#${canvasId}`).parentNode; // Dapatkan parent div dari canvas
                if (chartContainer) {
                    chartContainer.innerHTML = '<p class="text-center text-gray-500">Tidak ada data kehadiran untuk event ini atau event belum dipilih.</p>';
                }
            }
        }
    </script>
</body>

</html>