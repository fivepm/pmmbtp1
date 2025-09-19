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

// --- LOGIKA AKSI ---

// 1. Reaktifkan Peserta
if (isset($_GET['reactivate_id'])) {
    $id = $_GET['reactivate_id'] ?? '';
    if (!empty($id)) {
        $id = (int)$id;

        $sql = "UPDATE participants SET is_active = TRUE WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $message = "Peserta berhasil diaktifkan kembali!";
            $message_type = 'success';
        } else {
            $message = "Gagal mengaktifkan kembali peserta: " . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// 2. Hapus Peserta Permanen (Hanya untuk Super Admin ID 1)
if (isset($_GET['delete_permanent_id'])) {
    $id = $_GET['delete_permanent_id'] ?? '';
    if (!empty($id)) {
        $id = (int)$id;

        if ($_SESSION['admin_id'] === 1) { // Hanya izinkan Super Admin (ID 1)
            $conn->begin_transaction();
            try {
                // Hapus entri terkait di attendances terlebih dahulu (jika ON DELETE CASCADE tidak diatur atau untuk kejelasan)
                // Jika attendances.participant_id sudah ON DELETE CASCADE, baris ini opsional
                $sql_delete_attendances = "DELETE FROM attendances WHERE participant_id = ?";
                $stmt_delete_attendances = $conn->prepare($sql_delete_attendances);
                $stmt_delete_attendances->bind_param("i", $id);
                $stmt_delete_attendances->execute();
                $stmt_delete_attendances->close();

                // Hapus entri terkait di izin_requests (jika ON DELETE CASCADE tidak diatur atau untuk kejelasan)
                // Jika izin_requests.participant_id sudah ON DELETE CASCADE, baris ini opsional
                $sql_delete_izin_requests = "DELETE FROM izin_requests WHERE participant_id = ?";
                $stmt_delete_izin_requests = $conn->prepare($sql_delete_izin_requests);
                $stmt_delete_izin_requests->bind_param("i", $id);
                $stmt_delete_izin_requests->execute();
                $stmt_delete_izin_requests->close();

                // Hapus peserta dari tabel participants
                $sql_delete_participant = "DELETE FROM participants WHERE id = ?";
                $stmt_delete_participant = $conn->prepare($sql_delete_participant);
                $stmt_delete_participant->bind_param("i", $id);

                if ($stmt_delete_participant->execute()) {
                    $conn->commit();
                    $message = "Peserta berhasil dihapus secara permanen!";
                    $message_type = 'success';
                } else {
                    throw new Exception("Gagal menghapus peserta permanen: " . $stmt_delete_participant->error);
                }
                $stmt_delete_participant->close();
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Terjadi kesalahan saat menghapus peserta permanen: " . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = "Anda tidak memiliki izin untuk menghapus peserta secara permanen.";
            $message_type = 'error';
        }
    }
}

// Ambil Data Peserta Nonaktif (untuk ditampilkan)
$inactive_participants = [];
$sql = "SELECT id, name, jenis_kelamin, barcode_data, kelompok, kategori_usia, created_at FROM participants WHERE is_active = FALSE ORDER BY created_at DESC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $inactive_participants[] = $row;
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
            display: flex;
            flex-direction: row;
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
                <li><a href="manage_inactive_participants.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
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

    <div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div id="main-content-wrapper" class="flex-grow main-content-wrapper">
        <header class="md:hidden bg-indigo-700 text-white p-4 flex items-center justify-between shadow-md">
            <button id="menu-toggle" class="menu-toggle-button">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
            <h1 class="text-2xl font-bold ml-12">Manajemen Peserta Nonaktif</h1>
        </header>

        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200">
                <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Daftar Peserta Nonaktif</h2>

                <?php if (!empty($message)): ?>
                    <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="mb-6 text-center">
                    <a href="manage_participants.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-800 font-semibold rounded-lg shadow-md hover:bg-gray-300 transition duration-300">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Kembali ke Daftar Peserta Aktif
                    </a>
                </div>

                <?php if (empty($inactive_participants)): ?>
                    <p class="text-center text-gray-500">Tidak ada peserta yang dinonaktifkan.</p>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jenis Kelamin</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelompok</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kategori Usia</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">QR Code</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dinonaktifkan Pada</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($inactive_participants as $participant): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($participant['id']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['jenis_kelamin']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['kelompok']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($participant['kategori_usia']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 font-mono"><?php echo htmlspecialchars($participant['barcode_data']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d M Y H:i', strtotime($participant['created_at'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="manage_inactive_participants.php?reactivate_id=<?php echo htmlspecialchars($participant['id']); ?>"
                                                onclick="return confirm('Apakah Anda yakin ingin mengaktifkan kembali peserta ini?');"
                                                class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200 mr-2">
                                                Reaktifkan
                                            </a>
                                            <?php if ($_SESSION['admin_id'] === 1): // Hanya Super Admin yang bisa hapus permanen 
                                            ?>
                                                <a href="manage_inactive_participants.php?delete_permanent_id=<?php echo htmlspecialchars($participant['id']); ?>"
                                                    onclick="return confirm('PERINGATAN: Ini akan menghapus peserta ini dan semua data terkait secara PERMANEN. Tindakan ini TIDAK dapat dibatalkan. Apakah Anda YAKIN ingin melanjutkan?');"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-200">
                                                    Hapus Permanen
                                                </a>
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
            <p>&copy; <?php echo date("Y"); ?> Presensi Event Bulanan. All rights reserved.</p>
        </footer>
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
    </script>
</body>

</html>