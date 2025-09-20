<?php
error_reporting(E_ALL); // Tampilkan semua error PHP
ini_set('display_errors', 1); // Tampilkan error di layar (Hapus di produksi!)

ob_start(); // MULAI OUTPUT BUFFERING DI SINI UNTUK MENANGKAP SEMUA OUTPUT

session_start();

// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

// Periksa koneksi
if ($conn->connect_error) {
    ob_end_clean(); // Hapus buffer jika ada error koneksi
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Koneksi database gagal: ' . $conn->connect_error, 'type' => 'error']);
        exit;
    } else {
        die("Koneksi database gagal: " . $conn->connect_error);
    }
}

// Inisialisasi variabel untuk feedback messages
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

// Dapatkan ID event yang dipilih dari POST atau GET, default ke event pertama jika tersedia
$selected_event_id = isset($_REQUEST['event_id']) ? (int)$_REQUEST['event_id'] : (empty($events) ? 0 : $events[0]['id']);
$selected_event_name = '';

// Temukan nama event yang dipilih untuk ditampilkan
foreach ($events as $event) {
    if ($event['id'] == $selected_event_id) {
        $selected_event_name = htmlspecialchars($event['event_name'] . " (" . date('d M Y', strtotime($event['event_date'])) . ")");
        break;
    }
}

// Tentukan apakah ini adalah permintaan AJAX (dari fungsi recordAttendance atau update status)
$is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';


// --- Validasi Autentikasi di awal ---
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ob_end_clean(); // Hapus buffer
    if ($is_ajax_request) {
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Sesi admin berakhir. Mohon login kembali.', 'type' => 'error']);
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
}

// Validasi ID Event untuk non-AJAX request
if ($selected_event_id === 0 && !$is_ajax_request) {
    header('Location: manage_events.php');
    exit;
}

// ... (Kode PHP Anda sebelumnya hingga setelah pengambilan detail event: $selected_event_name) ...

// Ambil daftar peserta yang BELUM ada di presensi event ini
$participants_not_in_attendance = [];
if ($selected_event_id > 0) {
    $sql_not_in_attendance = "
        SELECT p.id, p.name, p.kelompok, p.kategori_usia
        FROM participants p
        WHERE p.is_active = TRUE
        AND p.id NOT IN (SELECT participant_id FROM attendances WHERE event_id = ?)
        ORDER BY p.name ASC";

    $stmt_not_in_attendance = $conn->prepare($sql_not_in_attendance);
    $stmt_not_in_attendance->bind_param("i", $selected_event_id);
    $stmt_not_in_attendance->execute();
    $result_not_in_attendance = $stmt_not_in_attendance->get_result();

    if ($result_not_in_attendance->num_rows > 0) {
        while ($row = $result_not_in_attendance->fetch_assoc()) {
            $participants_not_in_attendance[] = $row;
        }
    }
    $stmt_not_in_attendance->close();
}

// Ambil detail event yang dipilih (hanya jika $selected_event_id valid)
if ($selected_event_id > 0) {
    $sql_event_detail = "SELECT event_name, event_date FROM events WHERE id = ?";
    $stmt_event_detail = $conn->prepare($sql_event_detail);
    $stmt_event_detail->bind_param("i", $selected_event_id);
    $stmt_event_detail->execute();
    $result_event_detail = $stmt_event_detail->get_result();

    if ($result_event_detail->num_rows === 1) {
        $event_detail = $result_event_detail->fetch_assoc();
        $selected_event_name = htmlspecialchars($event_detail['event_name'] . " (" . date('d M Y', strtotime($event_detail['event_date'])) . ")");
    } else {
        // Event tidak ditemukan di database
        ob_end_clean(); // Hapus buffer
        if ($is_ajax_request) {
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Event dengan ID ini tidak ditemukan.', 'type' => 'error']);
            exit;
        } else {
            header('Location: manage_events.php');
            exit;
        }
    }
    $stmt_event_detail->close();
}

// Logika untuk menambah peserta ke presensi event secara manual via modal
if (isset($_POST['add_participant_to_event'])) {
    ob_start(); // Mulai output buffering

    header('Content-Type: application/json'); // Pastikan header JSON dikirim

    $participant_id_to_add = $_POST['participant_id_to_add'] ?? 0;
    $event_id_for_add_manual = $_POST['event_id_for_add_manual'] ?? 0;

    // Validasi dasar
    if ($participant_id_to_add === 0 || $event_id_for_add_manual === 0 || $event_id_for_add_manual != $selected_event_id) {
        ob_end_clean();
        echo json_encode(['message' => 'Data tidak lengkap atau ID event tidak cocok untuk penambahan manual.', 'type' => 'error']);
        exit;
    }

    // Ambil nama peserta untuk pesan feedback
    $stmt_get_name = $conn->prepare("SELECT name FROM participants WHERE id = ?");
    $stmt_get_name->bind_param("i", $participant_id_to_add);
    $stmt_get_name->execute();
    $result_get_name = $stmt_get_name->get_result();
    $participant_name_added = $result_get_name->fetch_assoc()['name'] ?? 'Peserta tidak dikenal';
    $stmt_get_name->close();

    // Dapatkan tanggal event untuk attendance_time (00:00:00)
    $sql_get_event_date = "SELECT event_date FROM events WHERE id = ?";
    $stmt_get_event_date = $conn->prepare($sql_get_event_date);
    $stmt_get_event_date->bind_param("i", $event_id_for_add_manual);
    $stmt_get_event_date->execute();
    $result_event_date = $stmt_get_event_date->get_result();
    $event_date_for_attendance = '';
    if ($result_event_date->num_rows > 0) {
        $event_data_row = $result_event_date->fetch_assoc();
        $event_date_for_attendance = $event_data_row['event_date'] . ' 00:00:00';
    }
    $stmt_get_event_date->close();

    try {
        // Masukkan peserta ke tabel attendances dengan status 'Tidak Hadir'
        $sql_insert_attendance = "INSERT INTO attendances (participant_id, event_id, status, attendance_time) VALUES (?, ?, 'Tidak Hadir', ?)";
        $stmt_insert_attendance = $conn->prepare($sql_insert_attendance);
        $stmt_insert_attendance->bind_param("iis", $participant_id_to_add, $event_id_for_add_manual, $event_date_for_attendance);

        if ($stmt_insert_attendance->execute()) {
            $message = "Peserta '{$participant_name_added}' berhasil ditambahkan ke presensi event.";
            $message_type = 'success';
        } else {
            // Ini bisa terjadi jika ada constraint unik lain (meskipun sudah difilter oleh NOT IN)
            $message = "Gagal menambahkan peserta: " . $stmt_insert_attendance->error;
            $message_type = 'error';
        }
        $stmt_insert_attendance->close();
    } catch (Exception $e) {
        $message = "Error database saat menambahkan peserta: " . $e->getMessage();
        $message_type = 'error';
    }

    ob_end_clean(); // Hapus buffer output sebelum mengirim JSON
    echo json_encode(['message' => $message, 'type' => $message_type]);
    exit;
}

// Logika untuk menghapus peserta dari presensi event
if (isset($_POST['delete_from_attendance'])) {
    ob_start(); // Mulai output buffering

    header('Content-Type: application/json'); // Pastikan header JSON dikirim

    $attendance_id_to_delete = $_POST['attendance_id_to_delete'] ?? 0;
    $event_id_from_delete = $_POST['event_id_from_delete'] ?? 0;

    // Validasi dasar
    if ($attendance_id_to_delete === 0 || $event_id_from_delete === 0 || $event_id_from_delete != $selected_event_id) {
        ob_end_clean();
        echo json_encode(['message' => 'Data tidak lengkap atau ID event tidak cocok untuk penghapusan presensi.', 'type' => 'error']);
        exit;
    }

    try {
        // Hapus entri dari tabel attendances
        $sql_delete_attendance = "DELETE FROM attendances WHERE id = ? AND event_id = ?";
        $stmt_delete_attendance = $conn->prepare($sql_delete_attendance);
        $stmt_delete_attendance->bind_param("ii", $attendance_id_to_delete, $event_id_from_delete);

        if ($stmt_delete_attendance->execute()) {
            $message = "Peserta berhasil dihapus dari daftar presensi event ini.";
            $message_type = 'success';
        } else {
            throw new Exception("Gagal menghapus presensi: " . $stmt_delete_attendance->error);
        }
        $stmt_delete_attendance->close();
    } catch (Exception $e) {
        $message = "Error database saat menghapus presensi: " . $e->getMessage();
        $message_type = 'error';
    }

    ob_end_clean(); // Hapus buffer output sebelum mengirim JSON
    echo json_encode(['message' => $message, 'type' => $message_type]);
    exit;
}


// Logic for recording attendance (via AJAX for scanned barcode)
if (isset($_POST['scan_barcode_data'])) {
    ob_start(); // Mulai output buffering HANYA di sini

    header('Content-Type: application/json'); // Pastikan header JSON dikirim

    if ($selected_event_id === 0) { // Validasi ulang event_id untuk AJAX request
        ob_end_clean();
        echo json_encode(['message' => 'ID Event tidak valid atau tidak diberikan saat pemindaian.', 'type' => 'error']);
        exit;
    }

    $scanned_barcode_data = $_POST['scan_barcode_data'];
    $scanned_barcode_data = $conn->real_escape_string($scanned_barcode_data);

    $sql_participant = "SELECT id, name FROM participants WHERE barcode_data = ? AND is_active = TRUE";
    $stmt_participant = $conn->prepare($sql_participant);
    $stmt_participant->bind_param("s", $scanned_barcode_data);
    $stmt_participant->execute();
    $result_participant = $stmt_participant->get_result();

    if ($result_participant->num_rows === 1) {
        $participant = $result_participant->fetch_assoc();
        $participant_id = $participant['id'];
        $participant_name = htmlspecialchars($participant['name']);

        try {
            $sql_update_attendance = "UPDATE attendances SET status = 'Hadir', attendance_time = NOW() WHERE participant_id = ? AND event_id = ?";
            $stmt_update_attendance = $conn->prepare($sql_update_attendance);
            $stmt_update_attendance->bind_param("ii", $participant_id, $selected_event_id);

            if ($stmt_update_attendance->execute()) {
                if ($stmt_update_attendance->affected_rows > 0) {
                    $message = "Presensi '{$participant_name}' berhasil dicatat sebagai HADIR.";
                    $message_type = 'success';
                } else {
                    $message = "Presensi '{$participant_name}' sudah tercatat sebelumnya.";
                    $message_type = 'info';
                }
            } else {
                throw new Exception("Gagal mengeksekusi update presensi: " . $stmt_update_attendance->error);
            }
            $stmt_update_attendance->close();
        } catch (Exception $e) {
            $message = "Error database saat mencatat presensi: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "Barcode tidak dikenal atau peserta tidak aktif.";
        $message_type = 'error';
    }
    $stmt_participant->close();

    ob_end_clean(); // Hapus buffer output sebelum mengirim JSON
    echo json_encode(['message' => $message, 'type' => $message_type]);
    exit;
}

// Logic for manually updating attendance status
if (isset($_POST['update_attendance_status'])) {
    ob_start(); // Mulai output buffering HANYA di sini

    header('Content-Type: application/json'); // Pastikan header JSON dikirim

    $attendance_id = $_POST['attendance_id'] ?? 0;
    $new_status = $_POST['new_status'] ?? '';
    $current_event_id_from_form = $_POST['event_id'] ?? 0; // Ambil event_id dari form modal

    // Validasi ulang event ID dari form modal
    if ($current_event_id_from_form != $selected_event_id || $current_event_id_from_form === 0) {
        ob_end_clean();
        echo json_encode(['message' => 'Error: ID Event tidak cocok atau tidak valid dari form.', 'type' => 'error']);
        exit;
    }

    if ($attendance_id > 0 && !empty($new_status)) {
        $attendance_id = (int)$attendance_id;
        $new_status = $conn->real_escape_string($new_status);

        // Atur attendance_time berdasarkan status baru
        $attendance_time_to_set = 'NULL'; // Default jika bukan Hadir/Izin
        if ($new_status === 'Hadir' || $new_status === 'Tidak Hadir') {
            $attendance_time_to_set = 'NOW()'; // Waktu persetujuan jika Hadir
        } elseif ($new_status === 'Izin') {
            // Ambil request_time dari izin_requests untuk peserta ini di event ini
            // Join dengan attendances untuk memastikan request_time dari pengajuan izin yang benar
            $stmt_get_request_time = $conn->prepare("SELECT ir.request_time FROM izin_requests ir JOIN attendances a ON ir.participant_id = a.participant_id WHERE a.id = ? AND ir.event_id = ? AND ir.reason != 'Pengajuan awal oleh sistem'");
            $stmt_get_request_time->bind_param("ii", $attendance_id, $selected_event_id);
            $stmt_get_request_time->execute();
            $result_request_time = $stmt_get_request_time->get_result();
            if ($result_request_time->num_rows > 0) {
                $row_request_time = $result_request_time->fetch_assoc();
                $attendance_time_to_set = "'" . $conn->real_escape_string($row_request_time['request_time']) . "'";
            } else {
                // Jika tidak ada pengajuan izin yang cocok, gunakan NOW() sebagai fallback
                $attendance_time_to_set = 'NOW()';
            }
            $stmt_get_request_time->close();
        }

        try {
            $sql_update_manual = "UPDATE attendances SET status = ?, attendance_time = {$attendance_time_to_set} WHERE id = ?";
            $stmt_update_manual = $conn->prepare($sql_update_manual);
            $stmt_update_manual->bind_param("si", $new_status, $attendance_id);

            if ($stmt_update_manual->execute()) {
                $message = "Status kehadiran berhasil diperbarui menjadi '{$new_status}'.";
                $message_type = 'success';
            } else {
                throw new Exception("Gagal mengeksekusi update status manual: " . $stmt_update_manual->error);
            }
            $stmt_update_manual->close();
        } catch (Exception $e) {
            $message = "Error database saat memperbarui status: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "Data tidak lengkap untuk memperbarui status kehadiran.";
        $message_type = 'error';
    }

    ob_end_clean(); // Hapus buffer output sebelum mengirim JSON
    echo json_encode(['message' => $message, 'type' => $message_type]);
    exit;
}


// Fetch attendance records for the selected event (all participants, showing their status)
$attendances = [];
if ($selected_event_id > 0) {
    $sql_attendances = "
        SELECT 
            a.id as attendance_id,
            p.name as participant_name, 
            p.barcode_data, 
            a.attendance_time,
            a.status
        FROM attendances a
        JOIN participants p ON a.participant_id = p.id
        WHERE a.event_id = ?
        ORDER BY a.attendance_time DESC, p.name ASC"; // Urutkan berdasarkan nama peserta

    $stmt_attendances = $conn->prepare($sql_attendances);
    $stmt_attendances->bind_param("i", $selected_event_id);
    $stmt_attendances->execute();
    $result_attendances = $stmt_attendances->get_result();

    if ($result_attendances->num_rows > 0) {
        while ($row = $result_attendances->fetch_assoc()) {
            $attendances[] = $row;
        }
    }
    $stmt_attendances->close();
}

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
            background-color: #f0f2f5;
            /* Light gray background */
            min-height: 100vh;
            /* Pastikan body mengambil tinggi penuh viewport */
            display: flex;
            /* Jadikan body sebagai flex container */
            flex-direction: column;
            /* Susun konten secara vertikal */
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Camera/Scanner specific styles */
        #video,
        #canvas {
            width: 100%;
            max-width: 600px;
            height: auto;
            display: block;
            margin: 0 auto;
            border-radius: 0.75rem;
        }

        .hidden {
            display: none !important;
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
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 400px;
            border-radius: 0.75rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: relative;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        /* Fullscreen button specific style */
        #fullscreenToggle {
            background-color: #4f46e5;
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: background-color 0.3s;
        }

        #fullscreenToggle:hover {
            background-color: #4338ca;
        }
    </style>
</head>

<body>

    <!-- Header/Navbar (Standard, non-sidebar) -->
    <header class="bg-indigo-700 text-white p-4 shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">Scan Presensi</h1>
            <nav>
                <ul class="flex space-x-4 items-center">
                    <!-- <li><a href="admin_dashboard.php" class="hover:text-indigo-200 transition duration-200">Home</a></li>
                    <li><a href="manage_participants.php" class="hover:text-indigo-200 transition duration-200">Manajemen Peserta</a></li>
                    <li><a href="manage_events.php" class="hover:text-indigo-200 transition duration-200">Manajemen Event</a></li>
                    <li><a href="attendance_report.php" class="hover:text-indigo-200 transition duration-200">Laporan Presensi</a></li>
                    <li><a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white py-1 px-3 rounded-md transition duration-200">Logout</a></li> -->
                    <li>
                        <button id="fullscreenToggle" title="Toggle Fullscreen">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m0 0l-5 5m-5 11v4m0 0h-4m0 0l-5-5m11 5v-4m0 0h4m0 0l5-5"></path>
                            </svg>
                        </button>
                    </li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="flex-grow container bg-white p-6 rounded-xl shadow-lg w-full max-w-2xl mt-8">
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-6">Pemindai Presensi Event</h1>

        <!-- Debugging: Tampilkan ID Event Terpilih -->
        <p class="text-center text-sm text-gray-500 mb-4">ID Event Terpilih: <span class="font-bold"><?php echo $selected_event_id; ?></span></p>

        <!-- Tombol Kembali ke Halaman Sebelumnya -->
        <div class="mb-6 text-center">
            <button onclick="history.back()" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-800 font-semibold rounded-lg shadow-md hover:bg-gray-300 transition duration-300">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Kembali ke Halaman Sebelumnya
            </button>
            <button onclick="window.openAddParticipantToEventModal()"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300 ml-4">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"></path>
                </svg>
                Tambah Peserta Manual
            </button>
            <button id="fullscreenToggle" title="Toggle Fullscreen" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300 ml-4">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m0 0l-5 5m-5 11v4m0 0h-4m0 0l-5-5m11 5v-4m0 0h4m0 0l5-5"></path>
                </svg>
                <span class="ml-2">Fullscreen</span>
            </button>
        </div>


        <!-- Pilih Event Section -->
        <div class="mb-6 bg-gray-50 p-4 rounded-xl shadow-inner">
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Event Terpilih</h2>
            <form id="event_select_form" action="scan_attendance.php" method="GET">
                <!-- <select id="event_selector" name="event_id"
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
                </select> -->
                <input type="text" id="event_selector" name="event_id" readonly value="<?php echo htmlspecialchars($selected_event_name); ?>"
                    class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200">
            </form>
            <?php if (empty($events)): ?>
                <p class="text-red-500 text-sm mt-2">Mohon buat event terlebih dahulu di halaman Manajemen Event.</p>
            <?php endif; ?>
        </div>

        <?php if ($selected_event_id > 0): ?>
            <div id="scanner-section" class="mb-6 bg-gray-50 p-4 rounded-xl shadow-inner text-center">
                <h2 class="text-xl font-semibold text-gray-700 mb-4">Pemindai Barcode/QR Code</h2>
                <video id="video" class="bg-gray-200 rounded-xl" autoplay playsinline></video>
                <canvas id="canvas" class="hidden"></canvas>
                <div id="loadingMessage" class="text-gray-500 mt-4 text-sm hidden">Memuat pemindai video...</div>
                <div id="output" class="mt-4 p-3 bg-blue-50 text-blue-800 rounded-lg hidden">
                    <p>Data Terdeteksi: <span id="outputData" class="font-medium"></span></p>
                </div>
                <button id="scanButton" class="mt-6 w-full md:w-auto px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300 transform hover:scale-105">
                    Mulai Pindai Barcode
                </button>
            </div>

            <div class="mt-8 bg-gray-50 p-4 rounded-xl shadow-inner">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Daftar Kehadiran Event Terpilih</h2>
                <?php if (!empty($attendances)): ?>
                    <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Peserta</th>
                                    <!-- <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Barcode</th> -->
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Presensi</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <?php if ($_SESSION['admin_id'] === 1): // Hanya tampilkan jika super admin 
                                    ?>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($attendances as $attendance): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($attendance['participant_name']); ?></td>
                                        <!-- <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 font-mono"><?php echo htmlspecialchars($attendance['barcode_data']); ?></td> -->
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $attendance['attendance_time'] ? convertDbTimeToWib(strtotime($attendance['attendance_time'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        if ($attendance['status'] === 'Hadir') echo 'bg-green-100 text-green-800';
                                        else if ($attendance['status'] === 'Izin') echo 'bg-yellow-100 text-yellow-800';
                                        else echo 'bg-red-100 text-red-800';
                                        ?>">
                                                <?php echo htmlspecialchars($attendance['status']); ?>
                                            </span>
                                        </td>
                                        <?php if ($_SESSION['admin_id'] === 1): // Hanya tampilkan jika super admin 
                                        ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <button onclick="openStatusModal(<?php echo htmlspecialchars($attendance['attendance_id']); ?>, '<?php echo htmlspecialchars($attendance['participant_name']); ?>', '<?php echo htmlspecialchars($attendance['status']); ?>')"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-300">
                                                    Ubah Status
                                                </button>
                                                <button onclick="confirmDeleteFromAttendance(<?php echo htmlspecialchars($attendance['attendance_id']); ?>, '<?php echo htmlspecialchars($attendance['participant_name']); ?>')"
                                                    class="ml-2 inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-300">
                                                    Hapus dari Presensi
                                                </button>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-gray-500" id="noAttendanceMessage">Belum ada kehadiran yang tercatat untuk event ini.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="text-center p-6 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg max-w-2xl mx-auto">
                <p class="font-semibold">Peringatan:</p>
                <p>Silakan pilih event untuk memulai pemindaian presensi.</p>
                <p>Jika tidak ada event, Anda dapat menambahkannya melalui halaman "Manajemen Event".</p>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
        <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
    </footer>

    <!-- Custom Modal for Messages -->
    <div id="messageModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeMessageModal()">&times;</span>
            <p id="modalMessage" class="text-lg text-gray-700 text-center"></p>
            <div class="mt-4 flex justify-center">
                <button onclick="closeMessageModal()" class="px-5 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition duration-200">Oke</button>
            </div>
        </div>
    </div>

    <!-- Modal for Status Update -->
    <div id="statusUpdateModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="window.closeStatusModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Ubah Status Kehadiran</h3>
            <p class="text-gray-600 mb-4">Peserta: <span id="statusModalParticipantName" class="font-semibold"></span></p>
            <form id="statusUpdateForm" action="scan_attendance.php" method="POST">
                <input type="hidden" name="attendance_id" id="statusModalAttendanceId">
                <input type="hidden" name="update_attendance_status" value="true">
                <input type="hidden" name="event_id" value="<?php echo htmlspecialchars($selected_event_id); ?>">
                <div class="mb-4">
                    <label for="new_status" class="block text-gray-700 text-sm font-semibold mb-2">Status Baru:</label>
                    <select id="new_status" name="new_status" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="Hadir">Hadir</option>
                        <option value="Izin">Izin</option>
                        <option value="Tidak Hadir">Tidak Hadir</option>
                    </select>
                </div>
                <button type="submit" id="processSubmitButton"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Perbarui Status
                </button>
            </form>
        </div>
    </div>

    <div id="addParticipantToEventModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="window.closeAddParticipantToEventModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Tambahkan Peserta ke Presensi</h3>
            <p class="text-gray-600 mb-4">Event: <span class="font-semibold"><?php echo $selected_event_name; ?></span></p>
            <form id="addParticipantToEventForm" action="scan_attendance.php" method="POST">
                <input type="hidden" name="add_participant_to_event" value="true">
                <input type="hidden" name="event_id_for_add_manual" value="<?php echo htmlspecialchars($selected_event_id); ?>">

                <div class="mb-4">
                    <label for="participant_id_to_add" class="block text-gray-700 text-sm font-semibold mb-2">Pilih Peserta:</label>
                    <select id="participant_id_to_add" name="participant_id_to_add" required
                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="">Pilih Peserta yang Belum Ada</option>
                        <?php if (empty($participants_not_in_attendance)): ?>
                            <option value="" disabled>Tidak ada peserta yang perlu ditambahkan</option>
                        <?php else: ?>
                            <?php foreach ($participants_not_in_attendance as $participant): ?>
                                <option value="<?php echo htmlspecialchars($participant['id']); ?>">
                                    <?php echo htmlspecialchars($participant['name']); ?> (<?php echo htmlspecialchars($participant['kelompok']); ?> - <?php echo htmlspecialchars($participant['kategori_usia']); ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($participants_not_in_attendance)): ?>
                        <p class="text-red-500 text-xs mt-2">Semua peserta aktif sudah ada di daftar presensi event ini.</p>
                    <?php endif; ?>
                </div>
                <button type="submit" id="addParticipantSubmitButton"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Tambahkan
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script>
        // Camera/Scanner Logic
        const video = document.getElementById('video');
        const canvasElement = document.getElementById('canvas');
        const canvasContext = canvasElement.getContext('2d');
        const loadingMessage = document.getElementById('loadingMessage');
        const outputDiv = document.getElementById('output');
        const outputDataSpan = document.getElementById('outputData');
        const scanButton = document.getElementById('scanButton');
        const eventSelector = document.getElementById('event_selector');
        const fullscreenToggle = document.getElementById('fullscreenToggle');
        let videoStream;
        let animationFrameId;
        let selectedEventId = <?php echo htmlspecialchars($selected_event_id); ?>; // PHP variable to JS

        // Function to show custom modal messages
        window.showMessage = function(message, type = 'info') {
            const modal = document.getElementById('messageModal');
            const modalMessage = document.getElementById('modalMessage');
            modalMessage.innerHTML = message;

            const modalContent = modal.querySelector('.modal-content');
            modalContent.classList.remove('border-red-400', 'border-green-400', 'border-yellow-400', 'bg-red-50', 'bg-green-50', 'bg-blue-50'); // Clear previous
            modalMessage.classList.remove('text-red-700', 'text-green-700', 'text-blue-700', 'text-yellow-700');

            if (type === 'error') {
                modalContent.classList.add('bg-red-50');
                modalContent.style.borderColor = '#FCA5A5'; // border-red-300
                modalMessage.classList.add('text-red-700');
            } else if (type === 'success') {
                modalContent.classList.add('bg-green-50');
                modalContent.style.borderColor = '#B2F5EA'; // border-green-300
                modalMessage.classList.add('text-green-700');
            } else if (type === 'info') {
                modalContent.classList.add('bg-blue-50');
                modalContent.style.borderColor = '#BFDBFE'; // border-blue-300
                modalMessage.classList.add('text-blue-700');
            } else { // Default to gray for other types or unknown
                modalContent.style.backgroundColor = '#fefefe';
                modalContent.style.borderColor = '#888';
                modalMessage.classList.add('text-gray-700');
            }

            modal.classList.remove('hidden');
        }

        window.closeMessageModal = function() {
            document.getElementById('messageModal').classList.add('hidden');
            // Restart scanner setelah pesan, jika itu bukan dari form Ubah Status
            // dan jika video sedang tersembunyi (artinya scan sudah selesai)
            if (video.classList.contains('hidden')) {
                setTimeout(startScanner, 500);
            }
        }

        async function startScanner() {
            if (selectedEventId === 0) {
                showMessage("Silakan pilih event terlebih dahulu.", 'error');
                return;
            }

            loadingMessage.classList.remove('hidden');
            outputDiv.classList.add('hidden');
            outputDataSpan.textContent = '';

            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                videoStream = null;
            }
            if (animationFrameId) {
                cancelAnimationFrame(animationFrameId);
                animationFrameId = null;
            }

            video.classList.remove('hidden');

            try {
                videoStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: "environment"
                    }
                });
                video.srcObject = videoStream;
                video.setAttribute("playsinline", true);
                video.play();

                loadingMessage.classList.add('hidden');
                requestAnimationFrame(tick);
            } catch (err) {
                console.error(`Gagal mengakses kamera: ${err.name} - ${err.message}`);
                loadingMessage.textContent = "Gagal mengakses kamera. Pastikan izin kamera diberikan.";
                showMessage("Gagal mengakses kamera. Mohon berikan izin kamera untuk menggunakan pemindai. Error: " + err.message, 'error');
                video.classList.add('hidden');
            }
        }

        function tick() {
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvasElement.hidden = false;
                canvasElement.height = video.videoHeight;
                canvasElement.width = video.videoWidth;
                canvasContext.willReadFrequently = true;
                canvasContext.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
                let imageData = canvasContext.getImageData(0, 0, canvasElement.width, canvasElement.height);
                let code = jsQR(imageData.data, imageData.width, imageData.height, {
                    inversionAttempts: "dontInvert",
                });

                if (code) {
                    const scannedData = code.data;
                    outputDataSpan.textContent = scannedData;
                    outputDiv.classList.remove('hidden');
                    console.log("QR Code Terdeteksi:", scannedData);

                    if (videoStream) {
                        videoStream.getTracks().forEach(track => track.stop());
                        videoStream = null;
                    }
                    if (animationFrameId) {
                        cancelAnimationFrame(animationFrameId);
                        animationFrameId = null;
                    }
                    video.classList.add('hidden');
                    loadingMessage.textContent = "Barcode terdeteksi. Memproses...";

                    recordAttendance(scannedData);
                    return;
                } else {
                    outputDiv.classList.add('hidden');
                    outputDataSpan.textContent = '';
                }
            }
            if (videoStream && videoStream.active) {
                animationFrameId = requestAnimationFrame(tick);
            } else {
                console.log("Scanner berhenti.");
                loadingMessage.textContent = "";
            }
        }

        async function recordAttendance(barcodeData) {
            loadingMessage.textContent = "Mencatat presensi...";

            try {
                const response = await fetch('scan_attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `scan_barcode_data=${encodeURIComponent(barcodeData)}&event_id=${selectedEventId}`
                });
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const jsonResponse = await response.json();
                    showMessage(jsonResponse.message, jsonResponse.type);
                } else {
                    const textResponse = await response.text();
                    console.error("Server returned non-JSON response:", textResponse);
                    showMessage("Respons server tidak valid. Silakan periksa konsol browser untuk detail.", 'error');
                }

                setTimeout(() => {
                    window.location.reload();
                }, 2000);

            } catch (error) {
                console.error("Error recording attendance:", error);
                showMessage("Terjadi kesalahan saat mencatat presensi. Silakan coba lagi. Detail: " + error.message, 'error');
            } finally {
                loadingMessage.textContent = "";
            }
        }

        scanButton.addEventListener('click', startScanner);

        // Event listener for event selector change
        eventSelector.addEventListener('change', function() {
            document.getElementById('event_select_form').submit(); // Submit form on change
        });

        // Initial check for selected event and start scanner if applicable
        window.onload = function() {
            // Display initial message if any
            <?php if (!empty($message)): ?>
                showMessage("<?php echo htmlspecialchars($message); ?>", "<?php echo $message_type; ?>");
            <?php endif; ?>

            // Auto-start scanner if an event is already selected
            if (selectedEventId > 0) {
                // startScanner(); // Mengaktifkan ini jika ingin auto-start kamera saat halaman dimuat
            }
        };

        // --- Manual Status Update Modal ---
        // Variabel-variabel ini sudah dideklarasikan di luar fungsi untuk akses global
        const statusUpdateModal = document.getElementById('statusUpdateModal');
        const statusModalParticipantName = document.getElementById('statusModalParticipantName');
        const statusModalAttendanceId = document.getElementById('statusModalAttendanceId');
        const newStatusSelect = document.getElementById('new_status'); // Elemen select di modal
        const processSubmitButton = document.getElementById('processSubmitButton'); // Ambil referensi tombol submit
        const statusUpdateForm = document.getElementById('statusUpdateForm'); // Ambil referensi form

        window.openStatusModal = function(attendanceId, participantName, currentStatus) {
            statusModalAttendanceId.value = attendanceId;
            statusModalParticipantName.textContent = participantName;
            newStatusSelect.value = currentStatus; // Set dropdown ke status saat ini

            // Atur teks tombol submit dan warna sesuai konteks
            processSubmitButton.textContent = 'Perbarui Status';
            processSubmitButton.classList.remove('bg-red-600', 'hover:bg-red-700', 'bg-green-600', 'hover:bg-green-700');
            processSubmitButton.classList.add('bg-blue-600', 'hover:bg-blue-700'); // Warna default untuk update

            statusUpdateModal.classList.remove('hidden'); // Tampilkan modal
        }

        window.closeStatusModal = function() {
            document.getElementById('statusUpdateModal').classList.add('hidden');
        }

        // Event listener untuk menutup modal saat klik di luar konten
        document.getElementById('statusUpdateModal').addEventListener('click', function(event) {
            if (event.target === this) {
                window.closeStatusModal(); // Panggil fungsi global
            }
        });

        // Event listener untuk submit form update status (AJAX)
        statusUpdateForm.addEventListener('submit', async function(event) {
            event.preventDefault(); // Mencegah form dari POST request penuh

            const formData = new FormData(statusUpdateForm);
            const urlParams = new URLSearchParams(formData).toString();

            try {
                const response = await fetch('scan_attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: urlParams
                });

                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const jsonResponse = await response.json();
                    showMessage(jsonResponse.message, jsonResponse.type);
                } else {
                    const textResponse = await response.text();
                    console.error("Server returned non-JSON response for status update:", textResponse);
                    showMessage("Respons server tidak valid untuk update status. Silakan periksa konsol browser.", 'error');
                }

                // Tutup modal setelah submit
                window.closeStatusModal();

                // Reload halaman setelah beberapa saat untuk menampilkan perubahan
                setTimeout(() => {
                    window.location.reload();
                }, 2000);

            } catch (error) {
                console.error("Error updating status:", error);
                showMessage("Terjadi kesalahan saat memperbarui status. Detail: " + error.message, 'error');
            }
        });

        // --- Modal Tambah Peserta Manual ke Event ---
        const addParticipantToEventModal = document.getElementById('addParticipantToEventModal');
        const addParticipantToEventForm = document.getElementById('addParticipantToEventForm');
        const addParticipantSubmitButton = document.getElementById('addParticipantSubmitButton');

        window.openAddParticipantToEventModal = function() {
            addParticipantToEventModal.classList.remove('hidden');
        }

        window.closeAddParticipantToEventModal = function() {
            addParticipantToEventModal.classList.add('hidden');
            // Opsional: reset form setelah ditutup
            addParticipantToEventForm.reset();
        }

        addParticipantToEventModal.addEventListener('click', function(event) {
            if (event.target === this) {
                window.closeAddParticipantToEventModal();
            }
        });

        // Event listener untuk submit form tambah peserta manual (AJAX)
        addParticipantToEventForm.addEventListener('submit', async function(event) {
            event.preventDefault(); // Mencegah form dari POST request penuh

            const formData = new FormData(addParticipantToEventForm);
            const urlParams = new URLSearchParams(formData).toString();

            try {
                const response = await fetch('scan_attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: urlParams
                });

                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const jsonResponse = await response.json();
                    showMessage(jsonResponse.message, jsonResponse.type);
                } else {
                    const textResponse = await response.text();
                    console.error("Server returned non-JSON response for add participant manual:", textResponse);
                    showMessage("Respons server tidak valid untuk penambahan peserta manual. Silakan periksa konsol browser.", 'error');
                }

                // Tutup modal setelah submit
                window.closeAddParticipantToEventModal();

                // Reload halaman setelah beberapa saat untuk menampilkan perubahan
                setTimeout(() => {
                    window.location.reload();
                }, 2000);

            } catch (error) {
                console.error("Error adding participant manually:", error);
                showMessage("Terjadi kesalahan saat menambahkan peserta manual. Detail: " + error.message, 'error');
            }
        });

        // --- Fungsi Hapus dari Presensi ---
        async function confirmDeleteFromAttendance(attendanceId, participantName) {
            if (!confirm(`Apakah Anda yakin ingin menghapus presensi peserta "${participantName}" dari event ini?`)) {
                return;
            }

            try {
                const response = await fetch('scan_attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `delete_from_attendance=true&attendance_id_to_delete=${attendanceId}&event_id_from_delete=${selectedEventId}`
                });

                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const jsonResponse = await response.json();
                    showMessage(jsonResponse.message, jsonResponse.type);
                } else {
                    const textResponse = await response.text();
                    console.error("Server returned non-JSON response for delete from attendance:", textResponse);
                    showMessage("Respons server tidak valid untuk penghapusan presensi. Silakan periksa konsol browser.", 'error');
                }

                // Tutup modal jika ada, lalu reload halaman setelah beberapa saat
                window.closeStatusModal(); // Tutup modal Ubah Status jika terbuka
                setTimeout(() => {
                    window.location.reload();
                }, 2000);

            } catch (error) {
                console.error("Error deleting from attendance:", error);
                showMessage("Terjadi kesalahan saat menghapus presensi. Detail: " + error.message, 'error');
            }
        }


        // --- Fullscreen Functionality ---
        fullscreenToggle.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    console.error(`Error attempting to enable fullscreen: ${err.message} (${err.name})`);
                    alert('Gagal mengaktifkan mode layar penuh. Browser Anda mungkin tidak mendukungnya atau ada batasan keamanan.');
                });
            } else {
                document.exitFullscreen();
            }
        });
    </script>
</body>

</html>