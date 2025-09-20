<?php
error_reporting(E_ALL); // Tampilkan semua error PHP
ini_set('display_errors', 1); // Tampilkan error di layar (Hapus di produksi!)

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

// Ambil semua kelompok unik untuk dropdown/radio button filter
$kelompok_options_filter = ['Semua Kelompok'];
$sql_kelompok = "SELECT DISTINCT kelompok FROM participants WHERE kelompok IS NOT NULL AND kelompok != '' ORDER BY kelompok ASC";
$result_kelompok = $conn->query($sql_kelompok);
if ($result_kelompok && $result_kelompok->num_rows > 0) {
    while ($row = $result_kelompok->fetch_assoc()) {
        $kelompok_options_filter[] = htmlspecialchars($row['kelompok']);
    }
}

// Dapatkan filter kelompok yang dipilih dari GET parameter
$selected_kelompok_filter = isset($_GET['kelompok']) ? htmlspecialchars($_GET['kelompok']) : 'Semua Kelompok';


// Dapatkan ID event dari URL
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Jika tidak ada event_id di URL, arahkan kembali ke halaman manajemen event
if ($event_id === 0) {
    header('Location: manage_events.php'); // Atau ke manage_izin_pages.php jika sudah ada
    exit;
}

// Ambil detail event
$event_name = '';
$event_date = '';
$sql_event = "SELECT event_name, event_date FROM events WHERE id = ?";
$stmt_event = $conn->prepare($sql_event);
$stmt_event->bind_param("i", $event_id);
$stmt_event->execute();
$result_event = $stmt_event->get_result();
if ($result_event->num_rows === 1) {
    $event_data = $result_event->fetch_assoc();
    $event_name = htmlspecialchars($event_data['event_name']);
    $event_date = date('d M Y', strtotime($event_data['event_date']));
} else {
    // Event tidak ditemukan
    header('Location: manage_events.php'); // Atau ke manage_izin_pages.php
    exit;
}
$stmt_event->close();

// --- LOGIKA PROSES PENGAJUAN IZIN ---
if (isset($_POST['process_request'])) {
    $request_id = $_POST['request_id'] ?? 0;
    $action = $_POST['action'] ?? ''; // 'approve' atau 'reject'
    $admin_notes = $_POST['admin_notes'] ?? '';

    if ($request_id > 0 && ($action === 'approve' || $action === 'reject')) {
        $request_id = (int)$request_id;
        $admin_id = $_SESSION['admin_id'];
        $admin_username = $_SESSION['admin_username'];
        $admin_notes = $conn->real_escape_string($admin_notes);
        $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';

        $conn->begin_transaction();
        try {
            // 1. Perbarui status pengajuan izin
            $sql_update_request = "UPDATE izin_requests SET status = ?, admin_notes = ?, processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?";
            $stmt_update_request = $conn->prepare($sql_update_request);
            $stmt_update_request->bind_param("ssii", $new_status, $admin_notes, $admin_id, $request_id);
            if (!$stmt_update_request->execute()) {
                throw new Exception("Gagal memperbarui status pengajuan izin: " . $stmt_update_request->error);
            }
            $stmt_update_request->close();

            // 2. Jika disetujui, perbarui status kehadiran peserta di tabel attendances
            if ($action === 'approve') {
                // Dapatkan participant_id DAN request_time dari izin_requests
                $stmt_get_participant_and_time = $conn->prepare("SELECT participant_id, request_time FROM izin_requests WHERE id = ?");
                $stmt_get_participant_and_time->bind_param("i", $request_id);
                $stmt_get_participant_and_time->execute();
                $result_get_participant_and_time = $stmt_get_participant_and_time->get_result();
                $izin_request_data = $result_get_participant_and_time->fetch_assoc();
                $participant_izin_id = $izin_request_data['participant_id'];
                $request_time = $izin_request_data['request_time']; // Ambil waktu pengajuan
                $stmt_get_participant_and_time->close();

                // Perbarui status di tabel attendances menjadi 'Izin' dan update attendance_time dengan request_time
                $sql_update_attendance = "UPDATE attendances SET status = 'Izin', attendance_time = ? WHERE participant_id = ? AND event_id = ?";
                $stmt_update_attendance = $conn->prepare($sql_update_attendance);
                $stmt_update_attendance->bind_param("sii", $request_time, $participant_izin_id, $event_id);
                if (!$stmt_update_attendance->execute()) {
                    throw new Exception("Gagal memperbarui status kehadiran peserta: " . $stmt_update_attendance->error);
                }
                $stmt_update_attendance->close();
            }

            $conn->commit();
            $message = "Pengajuan izin berhasil di-{$new_status} oleh {$admin_username}!";
            $message_type = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Terjadi kesalahan saat memproses pengajuan: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "Aksi tidak valid atau data tidak lengkap.";
        $message_type = 'error';
    }
}

// Ambil daftar pengajuan izin untuk event ini
$izin_requests = [];
$sql_requests = "
    SELECT 
        ir.id,
        p.name as participant_name,
        p.barcode_data,
        p.kelompok,
        p.kategori_usia,
        p.jenis_kelamin,
        ir.reason,
        ir.photo_proof_url,
        ir.request_time,
        ir.status,
        ir.admin_notes,
        u.username as processed_by_admin_username,
        ir.processed_at
    FROM izin_requests ir
    JOIN participants p ON ir.participant_id = p.id
    LEFT JOIN users u ON ir.processed_by_admin_id = u.id
    WHERE ir.event_id = ? AND reason != 'Pengajuan awal oleh sistem'";

$params_requests = [$event_id];
$types_requests = "i";

// Tambahkan filter kelompok jika dipilih
if ($selected_kelompok_filter !== 'Semua Kelompok') {
    $sql_requests .= " AND p.kelompok = ?";
    $params_requests[] = $selected_kelompok_filter;
    $types_requests .= "s";
}

$sql_requests .= " ORDER BY p.kelompok, participant_name ASC";

$stmt_requests = $conn->prepare($sql_requests);
if ($stmt_requests && count($params_requests) > 0) {
    $stmt_requests->bind_param($types_requests, ...$params_requests);
}
if ($stmt_requests) { // Pastikan statement berhasil disiapkan
    $stmt_requests->execute();
    $result_requests = $stmt_requests->get_result();

    if ($result_requests && $result_requests->num_rows > 0) {
        while ($row = $result_requests->fetch_assoc()) {
            $izin_requests[] = $row;
        }
    }
    $stmt_requests->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Izin - <?php echo htmlspecialchars($event_name); ?></title>
    <link rel="icon" href="images/logo_kmm.jpg" type="image/png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Sidebar styles */
        .sidebar {
            width: 250px;
            background-color: #1a202c;
            color: #ffffff;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 1000;
            padding-top: 4rem;
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
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .main-content-wrapper main {
            flex-grow: 1;
        }

        .main-content-wrapper.shifted {
            margin-left: 250px;
        }

        .menu-toggle-button {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background-color: #4f46e5;
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        @media (min-width: 768px) {
            .sidebar {
                transform: translateX(0);
            }

            .main-content-wrapper {
                margin-left: 250px;
            }

            .menu-toggle-button {
                display: none;
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

        .proof-image {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
            margin-top: 1rem;
            border: 1px solid #e2e8f0;
        }

        /* Print Specific Styles */
        @media print {
            body {
                background-color: #fff !important;
                margin: 0;
                padding: 0;
            }

            .sidebar,
            .sidebar-overlay,
            .menu-toggle-button,
            .filter-pengajuan-izin,
            .main-content-wrapper>header,
            .main-content-wrapper>footer,
            .mb-6.text-center,
            .action-buttons {
                /* Hide buttons and other non-report elements */
                display: none !important;
            }

            /* Menghilangkan kolom Bukti Foto dan Aksi di tabel saat print */
            table th:nth-child(5),
            /* Bukti Foto TH */
            table td:nth-child(5),
            /* Bukti Foto TD */
            table th:nth-child(9),
            /* Aksi TH */
            table td:nth-child(9) {
                /* Aksi TD */
                display: none !important;
            }

            .main-content-wrapper {
                margin-left: 0 !important;
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
            }

            table {
                width: 100% !important;
                border-collapse: collapse;
            }

            th,
            td {
                border: 1px solid #e5e7eb !important;
                padding: 8px 12px !important;
                font-size: 9pt !important;
                /* Slightly smaller font for print */
            }

            h1,
            h2,
            h3 {
                color: #000 !important;
                text-align: center !important;
            }

            /* Print-only content styles */
            .print-only-content {
                display: block !important;
                /* Show this block only on print */
                padding: 5px;
                /* Add some padding for the print content */
            }

            .letterhead {
                display: block !important;
                padding-bottom: 20px;
                margin-bottom: 20px;
                border-bottom: 1px solid #000;
            }

            .letterhead-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }

            .letterhead-logo {
                max-width: 60px;
                /* Adjust logo size for print */
                height: auto;
            }

            .letterhead-info {
                text-align: center;
                flex-grow: 1;
            }

            .letterhead-info h4 {
                font-size: 12pt;
                font-weight: bold;
                margin: 0;
            }

            .letterhead-info p {
                font-size: 8pt;
                margin: 2px 0;
            }

            .print-section-title {
                text-align: center;
                font-size: 16pt;
                margin-top: 20px;
                margin-bottom: 15px;
                color: #000;
            }

            .print-event-info {
                text-align: center;
                font-size: 12pt;
                margin-bottom: 20px;
                color: #000;
            }

            .proof-image {
                max-width: 150px;
                /* Smaller image in print */
            }

            table {
                width: 100%;
                /* Pastikan tabel mengisi lebar penuh container */
                /* table-layout: fixed; */
                /* Kunci lebar kolom agar tidak melebar */
                border-collapse: collapse;
            }

            table th,
            table td {
                border: 1px solid #e5e7eb !important;
                /* Light gray border */
                padding: 8px 12px !important;
                font-size: 10pt !important;
            }
        }

        /* Tambahan untuk modal bukti foto */
        #proofImage {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
        }

        /* Penyesuaian ukuran modal konten untuk foto */
        .modal-content.photo-modal {
            max-width: 600px;
            /* Lebar maksimum lebih besar untuk foto */
            width: 95%;
            /* Agak lebih lebar di mobile */
        }

        @media (min-width: 768px) {
            .modal-content.photo-modal {
                max-width: 800px;
                /* Lebih lebar di desktop */
            }
        }

        /* Sembunyikan tombol unduh di print */
        @media print {
            #downloadProofButton {
                display: none !important;
            }
        }
    </style>
</head>

<body>

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
                <li><a href="manage_izin_pages.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
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

    <div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div id="main-content-wrapper" class="flex-grow main-content-wrapper">
        <header class="md:hidden bg-indigo-700 text-white p-4 flex items-center justify-between shadow-md">
            <button id="menu-toggle" class="menu-toggle-button">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
            <h1 class="text-2xl font-bold ml-12">Kelola Pengajuan Izin</h1>
        </header>

        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200">
                <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Pengajuan Izin untuk Event: <span class="text-indigo-600"><?php echo $event_name; ?> (<?php echo $event_date; ?>)</span></h2>

                <?php if (!empty($message)): ?>
                    <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="mb-6 p-6 bg-gray-50 rounded-lg shadow-inner filter-pengajuan-izin">
                    <h3 class="text-2xl font-semibold text-gray-700 mb-4">Filter Pengajuan Izin</h3>
                    <form id="filter_kelompok_form" action="manage_izin_requests.php" method="GET" class="space-y-4">
                        <input type="hidden" name="event_id" value="<?php echo htmlspecialchars($event_id); ?>">

                        <div>
                            <label class="block text-gray-700 text-sm font-semibold mb-2">Filter Kelompok:</label>
                            <div class="mt-2 flex flex-wrap gap-4">
                                <?php foreach ($kelompok_options_filter as $option): ?>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="kelompok" value="<?php echo htmlspecialchars($option); ?>"
                                            class="form-radio text-indigo-600 h-4 w-4"
                                            <?php echo ($option === $selected_kelompok_filter) ? 'checked' : ''; ?>
                                            onchange="this.form.submit()"> <span class="ml-2 text-gray-700"><?php echo htmlspecialchars($option); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="mb-6 text-center">
                    <a href="manage_izin_pages.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-800 font-semibold rounded-lg shadow-md hover:bg-gray-300 transition duration-300">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Kembali ke Daftar Halaman Izin
                    </a>
                    <button onclick="window.print()"
                        class="inline-flex items-center px-6 py-3 bg-red-600 text-white font-semibold rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-300 transform hover:scale-105">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4"></path>
                        </svg>
                        Unduh Laporan PDF
                    </button>
                </div>

                <h3 class="text-2xl font-semibold text-gray-700 mb-4 mt-8">Daftar Pengajuan Izin</h3>
                <div class="print-only-content hidden">
                    <h3 class="text-2xl font-semibold text-gray-700 mb-1"><span class="text-indigo-600"><?php echo $event_name; ?> (<?php echo $event_date; ?>)</span></h3>
                    <?php if (!isset($_GET['kelompok']) || $_GET['kelompok'] == 'Semua Kelompok') {
                    ?>
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Desa Banguntapan 1</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Bintaran') {
                    ?>
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Kelompok Bintaran</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Gedongkuning') {
                    ?>
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Kelompok Gedongkuning</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Jombor') {
                    ?>
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Kelompok Jombor</h3>
                    <?php
                    } elseif ($_GET['kelompok'] == 'Sunten') {
                    ?>
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Kelompok Sunten</h3>
                    <?php
                    }
                    ?>
                </div>
                <?php if (empty($izin_requests)): ?>
                    <p class="text-center text-gray-500">Tidak ada pengajuan izin untuk event ini.</p>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Peserta</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Kelompok - Usia</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Alasan</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider print-hide-column-bukti-foto">Bukti Foto</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Pengajuan</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Diproses Oleh</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider print-hide-column-aksi">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php $no = 1;
                                foreach ($izin_requests as $request): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900"><?php echo $no++; ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700">
                                            <?php echo htmlspecialchars($request['participant_name']); ?><br>
                                            <!-- <span class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($request['barcode_data']); ?></span> -->
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                            <?php echo htmlspecialchars($request['kelompok']); ?><br>
                                            <?php echo htmlspecialchars($request['kategori_usia']); ?><br>
                                            ( <?php echo htmlspecialchars($request['jenis_kelamin']); ?> )
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700 max-w-xs overflow-hidden text-ellipsis"><?php echo htmlspecialchars($request['reason']); ?></td>
                                        <td class="px-6 py-4 text-sm print-hide-column-bukti-foto">
                                            <?php if (!empty($request['photo_proof_url'])): ?>
                                                <button onclick="window.openPhotoModal('<?php echo htmlspecialchars($request['photo_proof_url']); ?>')"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                                                    Lihat Bukti
                                                </button>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo convertDbTimeToWib(strtotime($request['request_time'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php
                                            if ($request['status'] === 'Approved') echo 'bg-green-100 text-green-800';
                                            else if ($request['status'] === 'Rejected') echo 'bg-red-100 text-red-800';
                                            else echo 'bg-yellow-100 text-yellow-800';
                                            ?>">
                                                <?php echo htmlspecialchars($request['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($request['processed_by_admin_username'] ?? '-'); ?><br> - <br>
                                            <?php echo $request['processed_at'] ? date('d M Y H:i', strtotime($request['processed_at'])) : ''; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium action-buttons print-hide-column-aksi">
                                            <?php if ($request['status'] === 'Pending'): ?>
                                                <button onclick="openProcessModal(<?php echo htmlspecialchars($request['id']); ?>, '<?php echo htmlspecialchars($request['participant_name']); ?>', 'approve')"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 mr-2">
                                                    Setujui
                                                </button>
                                                <button onclick="openProcessModal(<?php echo htmlspecialchars($request['id']); ?>, '<?php echo htmlspecialchars($request['participant_name']); ?>', 'reject')"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-200">
                                                    Tolak
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs">Sudah Diproses</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
            <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
        </footer>
    </div>

    <!-- Modal Proses Pengajuan -->
    <div id="processModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeProcessModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4" id="processModalTitle"></h3>
            <p class="text-gray-600 mb-4">Peserta: <span id="processModalParticipantName" class="font-semibold"></span></p>
            <form id="processForm" action="manage_izin_requests.php?event_id=<?php echo htmlspecialchars($event_id); ?>" method="POST">
                <input type="hidden" name="request_id" id="processModalRequestId">
                <input type="hidden" name="action" id="processModalAction">
                <div class="mb-4">
                    <label for="admin_notes" class="block text-gray-700 text-sm font-semibold mb-2">Catatan Admin (Opsional):</label>
                    <textarea id="admin_notes" name="admin_notes" rows="3"
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Tambahkan catatan jika perlu"></textarea>
                </div>
                <button type="submit" name="process_request" id="processSubmitButton"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Proses Pengajuan
                </button>
            </form>
        </div>
    </div>

    <!-- Modal Lihat Bukti Izin -->
    <div id="photoProofModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="window.closePhotoModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Bukti Foto Pengajuan Izin</h3>
            <img id="proofImage" src="" alt="Bukti Foto" class="proof-image mx-auto">
            <div class="mt-4 text-center">
                <a id="downloadProofButton" href="" download class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-md transition duration-300">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Unduh Foto
                </a>
            </div>
        </div>
    </div>


    <script>
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

        // Modal Proses Pengajuan Izin
        const processModal = document.getElementById('processModal');
        const processModalTitle = document.getElementById('processModalTitle');
        const processModalParticipantName = document.getElementById('processModalParticipantName');
        const processModalRequestId = document.getElementById('processModalRequestId');
        const processModalAction = document.getElementById('processModalAction');
        const processSubmitButton = document.getElementById('processSubmitButton');

        function openProcessModal(requestId, participantName, action) {
            processModalRequestId.value = requestId;
            processModalParticipantName.textContent = participantName;
            processModalAction.value = action;

            if (action === 'approve') {
                processModalTitle.textContent = 'Setujui Pengajuan Izin';
                processSubmitButton.textContent = 'Setujui';
                processSubmitButton.classList.remove('bg-red-600', 'hover:bg-red-700');
                processSubmitButton.classList.add('bg-green-600', 'hover:bg-green-700');
            } else {
                processModalTitle.textContent = 'Tolak Pengajuan Izin';
                processSubmitButton.textContent = 'Tolak';
                processSubmitButton.classList.remove('bg-green-600', 'hover:bg-green-700');
                processSubmitButton.classList.add('bg-red-600', 'hover:bg-red-700');
            }
            processModal.classList.remove('hidden');
        }

        function closeProcessModal() {
            processModal.classList.add('hidden');
        }

        processModal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeProcessModal();
            }
        });

        // --- Modal Bukti Foto ---
        const photoProofModal = document.getElementById('photoProofModal');
        const proofImage = document.getElementById('proofImage');
        const downloadProofButton = document.getElementById('downloadProofButton');

        window.openPhotoModal = function(photoUrl) {
            proofImage.src = photoUrl;
            downloadProofButton.href = photoUrl; // Set link unduh
            photoProofModal.classList.remove('hidden');
            // Tambahkan kelas khusus untuk modal foto agar CSS ukuran berlaku
            photoProofModal.querySelector('.modal-content').classList.add('photo-modal');
        }

        window.closePhotoModal = function() {
            photoProofModal.classList.add('hidden');
            proofImage.src = ''; // Bersihkan gambar
            photoProofModal.querySelector('.modal-content').classList.remove('photo-modal'); // Hapus kelas khusus
        }

        photoProofModal.addEventListener('click', function(event) {
            if (event.target === this) {
                window.closePhotoModal();
            }
        });
    </script>
</body>

</html>