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

// Fungsi untuk menghasilkan string acak unik untuk alias halaman izin
function generateUniqueIzinAlias($conn)
{
    $alias = '';
    $is_unique = false;
    while (!$is_unique) {
        $alias = 'izin-' . uniqid() . '-' . rand(1000, 9999);
        $stmt = $conn->prepare("SELECT id FROM events WHERE izin_page_alias = ?");
        $stmt->bind_param("s", $alias);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) {
            $is_unique = true;
        }
        $stmt->close();
    }
    return $alias;
}

// --- LOGIKA CRUD ---

// 1. Tambah Event Baru
if (isset($_POST['add_event'])) {
    $event_name = $_POST['event_name'] ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $description = $_POST['description'] ?? '';

    // Validasi input sederhana
    if (empty($event_name) || empty($event_date)) {
        $message = "Nama event dan tanggal tidak boleh kosong.";
        $message_type = 'error';
    } else {
        // Sanitize input
        $event_name = $conn->real_escape_string($event_name);
        $event_date_db = $conn->real_escape_string($event_date . ' 00:00:00'); // Format tanggal event dengan waktu 00:00:00
        $description = $conn->real_escape_string($description);
        $izin_page_alias = generateUniqueIzinAlias($conn); // Generate alias baru

        // Mulai transaksi
        $conn->begin_transaction();
        try {
            // Masukkan event baru, termasuk alias dan status aktif
            $sql_insert_event = "INSERT INTO events (event_name, event_date, description, izin_page_alias, izin_page_active) VALUES (?, ?, ?, ?, TRUE)";
            $stmt_insert_event = $conn->prepare($sql_insert_event);
            $stmt_insert_event->bind_param("ssss", $event_name, $event_date, $description, $izin_page_alias);

            if (!$stmt_insert_event->execute()) {
                throw new Exception("Gagal menambahkan event: " . $stmt_insert_event->error);
            }
            $new_event_id = $stmt_insert_event->insert_id;
            $stmt_insert_event->close();

            // Ambil semua peserta aktif
            $sql_get_participants = "SELECT id FROM participants WHERE is_active = TRUE";
            $result_participants = $conn->query($sql_get_participants);

            if ($result_participants->num_rows > 0) {
                // Siapkan statement untuk attendances (sekarang menyertakan attendance_time)
                $sql_insert_attendance = "INSERT INTO attendances (participant_id, event_id, status, attendance_time) VALUES (?, ?, 'Tidak Hadir', ?)";
                $stmt_insert_attendance = $conn->prepare($sql_insert_attendance);

                // Siapkan statement untuk izin_requests
                $sql_insert_izin_request = "INSERT INTO izin_requests (participant_id, event_id, reason, status) VALUES (?, ?, 'Pengajuan awal oleh sistem', 'Belum Mengajukan')"; // Default reason dan status
                $stmt_insert_izin_request = $conn->prepare($sql_insert_izin_request);


                while ($participant = $result_participants->fetch_assoc()) {
                    $participant_id = $participant['id'];

                    // Masukkan ke tabel attendances
                    // attendance_time diisi dengan tanggal event + jam 00:00:00
                    $stmt_insert_attendance->bind_param("iis", $participant_id, $new_event_id, $event_date_db);
                    if (!$stmt_insert_attendance->execute()) {
                        error_log("Gagal menambahkan presensi default untuk peserta ID {$participant_id} di event ID {$new_event_id}: " . $stmt_insert_attendance->error);
                    }

                    // Masukkan ke tabel izin_requests
                    $stmt_insert_izin_request->bind_param("ii", $participant_id, $new_event_id);
                    if (!$stmt_insert_izin_request->execute()) {
                        error_log("Gagal menambahkan izin request default untuk peserta ID {$participant_id} di event ID {$new_event_id}: " . $stmt_insert_izin_request->error);
                    }
                }
                $stmt_insert_attendance->close();
                $stmt_insert_izin_request->close(); // Tutup statement izin_requests
            }

            $conn->commit();
            $message = "Event berhasil ditambahkan dan presensi default untuk semua peserta aktif telah dibuat! Halaman Izin publik otomatis dibuat dan nonaktif. Aktifkan di 'Kelola Halaman Izin'.";
            $message_type = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Terjadi kesalahan: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 2. Edit Event
if (isset($_POST['edit_event'])) {
    $id = $_POST['event_id'] ?? '';
    $event_name = $_POST['event_name'] ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $description = $_POST['description'] ?? '';

    if (empty($id) || empty($event_name) || empty($event_date)) {
        $message = "Semua bidang diperlukan untuk mengedit event.";
        $message_type = 'error';
    } else {
        $id = (int)$id; // Pastikan ID adalah integer
        $event_name = $conn->real_escape_string($event_name);
        $event_date = $conn->real_escape_string($event_date);
        $description = $conn->real_escape_string($description);

        $sql = "UPDATE events SET event_name = ?, event_date = ?, description = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $event_name, $event_date, $description, $id);

        if ($stmt->execute()) {
            $message = "Event berhasil diperbarui!";
            $message_type = 'success';
        } else {
            $message = "Gagal memperbarui event: " . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// 3. Hapus Event
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'] ?? '';
    if (!empty($id)) {
        $id = (int)$id; // Pastikan ID adalah integer

        // Karena ada FOREIGN KEY dengan ON DELETE CASCADE,
        // menghapus event akan otomatis menghapus semua catatan presensi terkait.
        $sql = "DELETE FROM events WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id); // 'i' menandakan integer

        if ($stmt->execute()) {
            $message = "Event berhasil dihapus dan semua catatan presensi terkait juga telah dihapus!";
            $message_type = 'success';
        } else {
            $message = "Gagal menghapus event: " . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// 4. Ambil Data Event (untuk ditampilkan)
// Tidak perlu mengambil izin_page_alias dan izin_page_active di sini lagi
$events = [];
$sql = "SELECT id, event_name, event_date, description, created_at FROM events ORDER BY event_date DESC, created_at DESC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
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
                <li><a href="manage_participants.php" class="flex items-center p-3 rounded-lg text-white hover:bg-gray-700 transition duration-200">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h2a2 2 0 002-2V9.828a2 2 0 00-.586-1.414l-4.414-4.414A2 2 0 0012.172 2H5a2 2 0 00-2 2v16a2 2 0 002 2h12z"></path>
                        </svg>
                        Manajemen Peserta
                    </a></li>
                <li><a href="manage_events.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
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

    <div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div id="main-content-wrapper" class="flex-grow main-content-wrapper">
        <header class="md:hidden bg-indigo-700 text-white p-4 flex items-center justify-between shadow-md">
            <button id="menu-toggle" class="menu-toggle-button">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
            <h1 class="text-2xl font-bold ml-12">Manajemen Event</h1>
        </header>

        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200">
                <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Daftar Event</h2>

                <?php if (!empty($message)): ?>
                    <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Tombol untuk membuka modal Tambah Event -->
                <div class="flex justify-center mb-8">
                    <button onclick="openAddEventModal()"
                        class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-md transition duration-300 transform hover:scale-105">
                        Tambah Event Baru
                    </button>
                </div>

                <!-- Tabel Daftar Event -->
                <h3 class="text-2xl font-semibold text-gray-700 mb-4 mt-8">Daftar Event</h3>
                <?php if (empty($events)): ?>
                    <p class="text-center text-gray-500">Belum ada event yang terdaftar.</p>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Event</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deskripsi</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dibuat Pada</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($event['id']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($event['event_name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo date('d M Y', strtotime($event['event_date'])); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 max-w-xs overflow-hidden text-ellipsis"><?php echo htmlspecialchars($event['description']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d M Y H:i', strtotime($event['created_at'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium flex space-x-2">
                                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($event)); ?>)"
                                                class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200">
                                                Edit
                                            </button>
                                            <a href="manage_events.php?delete_id=<?php echo htmlspecialchars($event['id']); ?>"
                                                onclick="return confirm('Apakah Anda yakin ingin menghapus event ini? Ini juga akan menghapus semua catatan presensi terkait.');"
                                                class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-200">
                                                Hapus
                                            </a>
                                            <a href="scan_attendance.php?event_id=<?php echo htmlspecialchars($event['id']); ?>"
                                                class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                                                Scan Presensi
                                            </a>
                                            <!-- Link Kelola Izin dipindahkan ke manage_izin_pages.php -->
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

    <!-- Modal Tambah Event -->
    <div id="addEventModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeAddEventModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Tambah Event Baru</h3>
            <form action="manage_events.php" method="POST">
                <div class="mb-4">
                    <label for="event_name" class="block text-gray-700 text-sm font-semibold mb-2">Nama Event:</label>
                    <input type="text" id="event_name" name="event_name" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Contoh: Event Bulanan Juli 2025">
                </div>
                <div class="mb-4">
                    <label for="event_date" class="block text-gray-700 text-sm font-semibold mb-2">Tanggal Event:</label>
                    <input type="date" id="event_date" name="event_date" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200">
                </div>
                <div class="mb-6">
                    <label for="description" class="block text-gray-700 text-sm font-semibold mb-2">Deskripsi (Opsional):</label>
                    <textarea id="description" name="description" rows="3"
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Deskripsi singkat tentang event..."></textarea>
                </div>
                <button type="submit" name="add_event"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Tambah Event
                </button>
            </form>
        </div>
    </div>

    <!-- Modal Edit Event -->
    <div id="editEventModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Edit Event</h3>
            <form action="manage_events.php" method="POST">
                <input type="hidden" id="edit_event_id" name="event_id">
                <div class="mb-4">
                    <label for="edit_event_name" class="block text-gray-700 text-sm font-semibold mb-2">Nama Event:</label>
                    <input type="text" id="edit_event_name" name="event_name" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200">
                </div>
                <div class="mb-4">
                    <label for="edit_event_date" class="block text-gray-700 text-sm font-semibold mb-2">Tanggal Event:</label>
                    <input type="date" id="edit_event_date" name="event_date" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200">
                </div>
                <div class="mb-6">
                    <label for="edit_description" class="block text-gray-700 text-sm font-semibold mb-2">Deskripsi (Opsional):</label>
                    <textarea id="edit_description" name="description" rows="3"
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"></textarea>
                </div>
                <button type="submit" name="edit_event"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Perbarui Event
                </button>
            </form>
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

        // Modal Tambah Event Functions
        function openAddEventModal() {
            document.getElementById('addEventModal').classList.remove('hidden');
        }

        function closeAddEventModal() {
            document.getElementById('addEventModal').classList.add('hidden');
        }

        // Modal Edit Event Functions
        function openEditModal(eventData) {
            document.getElementById('edit_event_id').value = eventData.id;
            document.getElementById('edit_event_name').value = eventData.event_name;
            document.getElementById('edit_event_date').value = eventData.event_date;
            document.getElementById('edit_description').value = eventData.description;
            document.getElementById('editEventModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editEventModal').classList.add('hidden');
        }

        // Tutup modal saat mengklik di luar konten modal
        document.getElementById('addEventModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeAddEventModal();
            }
        });
        document.getElementById('editEventModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>

</html>