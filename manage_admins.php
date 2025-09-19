<?php
session_start();

// Periksa apakah admin sudah login. Jika tidak, arahkan kembali ke halaman login.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Hanya izinkan admin dengan ID 1 untuk mengakses halaman ini
if ($_SESSION['admin_id'] !== 1) {
    header('Location: admin_dashboard.php'); // Arahkan ke dashboard jika bukan super admin
    exit;
}

// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

// Inisialisasi variabel untuk pesan feedback
$message = '';
$message_type = ''; // 'success' atau 'error'

// --- LOGIKA CRUD ADMIN ---

// 1. Tambah Admin Baru
if (isset($_POST['add_admin'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $message = "Username dan password tidak boleh kosong.";
        $message_type = 'error';
    } else {
        $username = $conn->real_escape_string($username);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT); // Hash password untuk keamanan

        // Periksa apakah username sudah ada
        $check_sql = "SELECT id FROM users WHERE username = ?";
        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = "Username ini sudah digunakan. Mohon gunakan username lain.";
            $message_type = 'error';
        } else {
            $sql = "INSERT INTO users (username, password) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $username, $hashed_password);

            if ($stmt->execute()) {
                $message = "Admin berhasil ditambahkan!";
                $message_type = 'success';
            } else {
                $message = "Gagal menambahkan admin: " . $stmt->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
        $stmt_check->close();
    }
}

// 2. Edit Admin
if (isset($_POST['edit_admin'])) {
    $id = $_POST['admin_id'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? ''; // Bisa kosong jika tidak ingin mengubah password

    if (empty($id) || empty($username)) {
        $message = "ID dan Username admin tidak boleh kosong.";
        $message_type = 'error';
    } elseif ((int)$id === 1 && $id != $_SESSION['admin_id']) { // Mencegah admin lain mengedit super admin ID 1
        $message = "Anda tidak diizinkan mengedit akun super admin.";
        $message_type = 'error';
    } else {
        $id = (int)$id;
        $username = $conn->real_escape_string($username);

        $update_fields = "username = ?";
        $types = "s";
        $params = [$username];

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $update_fields .= ", password = ?";
            $types .= "s";
            $params[] = $hashed_password;
        }
        $params[] = $id;
        $types .= "i";

        // Periksa apakah username sudah ada untuk admin lain (kecuali admin yang sedang diedit)
        $check_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("si", $username, $id);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = "Username ini sudah digunakan oleh admin lain. Mohon gunakan username lain.";
            $message_type = 'error';
        } else {
            $sql = "UPDATE users SET {$update_fields} WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $message = "Admin berhasil diperbarui!";
                $message_type = 'success';
            } else {
                $message = "Gagal memperbarui admin: " . $stmt->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
        $stmt_check->close();
    }
}

// 3. Hapus Admin
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'] ?? '';
    if (!empty($id)) {
        $id = (int)$id;

        // Mencegah super admin ID 1 menghapus dirinya sendiri atau admin lain menghapus super admin
        if ($id === 1) {
            $message = "Tidak dapat menghapus akun super admin ID 1.";
            $message_type = 'error';
        } elseif ($id === $_SESSION['admin_id']) {
            $message = "Anda tidak dapat menghapus akun Anda sendiri.";
            $message_type = 'error';
        } else {
            $sql = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $message = "Admin berhasil dihapus!";
                $message_type = 'success';
            } else {
                $message = "Gagal menghapus admin: " . $stmt->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
    }
}

// 4. Ambil Data Admin (untuk ditampilkan)
$admins = [];
// Ambil semua admin kecuali admin yang sedang login (untuk mencegah edit/hapus diri sendiri)
// Atau ambil semua dan tangani di UI
$sql = "SELECT id, username, created_at FROM users ORDER BY id ASC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
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
                    <li><a href="manage_admins.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
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
            <h1 class="text-2xl font-bold ml-12">Manajemen Admin</h1>
            <!-- Optional: Add other top bar elements if needed -->
        </header>

        <!-- Main Content Area -->
        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200">
                <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Daftar Admin</h2>

                <?php if (!empty($message)): ?>
                    <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Tombol untuk membuka modal Tambah Admin -->
                <div class="flex justify-center mb-8">
                    <button onclick="openAddAdminModal()"
                        class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-md transition duration-300 transform hover:scale-105">
                        Tambah Admin Baru
                    </button>
                </div>

                <!-- Tabel Daftar Admin -->
                <h3 class="text-2xl font-semibold text-gray-700 mb-4 mt-8">Daftar Admin Aktif</h3>
                <?php if (empty($admins)): ?>
                    <p class="text-center text-gray-500">Belum ada admin yang terdaftar.</p>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dibuat Pada</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($admin['id']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($admin['username']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d M Y H:i', strtotime($admin['created_at'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($admin['id'] !== 1): // Tidak izinkan edit/hapus super admin ID 1 
                                            ?>
                                                <button onclick="openEditAdminModal(<?php echo htmlspecialchars(json_encode($admin)); ?>)"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 mr-2">
                                                    Edit
                                                </button>
                                                <a href="manage_admins.php?delete_id=<?php echo htmlspecialchars($admin['id']); ?>"
                                                    onclick="return confirm('Apakah Anda yakin ingin menghapus admin ini?');"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-200">
                                                    Hapus
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs">Super Admin</span>
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

        <!-- Footer (Opsional) -->
        <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
            <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
        </footer>
    </div>

    <!-- Modal Tambah Admin -->
    <div id="addAdminModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeAddAdminModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Tambah Admin Baru</h3>
            <form action="manage_admins.php" method="POST">
                <div class="mb-4">
                    <label for="username" class="block text-gray-700 text-sm font-semibold mb-2">Username:</label>
                    <input type="text" id="username" name="username" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Masukkan username">
                </div>
                <div class="mb-6">
                    <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password:</label>
                    <input type="password" id="password" name="password" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Masukkan password">
                </div>
                <button type="submit" name="add_admin"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Tambah Admin
                </button>
            </form>
        </div>
    </div>

    <!-- Modal Edit Admin -->
    <div id="editAdminModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditAdminModal()">&times;</span>
            <h3 class="text-2xl font-semibold text-gray-700 mb-4">Edit Admin</h3>
            <form action="manage_admins.php" method="POST">
                <input type="hidden" id="edit_admin_id" name="admin_id">
                <div class="mb-4">
                    <label for="edit_username" class="block text-gray-700 text-sm font-semibold mb-2">Username:</label>
                    <input type="text" id="edit_username" name="username" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200">
                </div>
                <div class="mb-6">
                    <label for="edit_password" class="block text-gray-700 text-sm font-semibold mb-2">Password (Kosongkan jika tidak diubah):</label>
                    <input type="password" id="edit_password" name="password"
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Kosongkan jika tidak diubah">
                </div>
                <button type="submit" name="edit_admin"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Perbarui Admin
                </button>
            </form>
        </div>
    </div>

    <script>
        // Sidebar Toggle Functionality (dari file lain, dipertahankan untuk konsistensi)
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

        // Fungsi untuk membuka modal Tambah Admin
        function openAddAdminModal() {
            document.getElementById('addAdminModal').classList.remove('hidden');
        }

        // Fungsi untuk menutup modal Tambah Admin
        function closeAddAdminModal() {
            document.getElementById('addAdminModal').classList.add('hidden');
        }

        // Fungsi untuk membuka modal Edit Admin
        function openEditAdminModal(admin) {
            document.getElementById('edit_admin_id').value = admin.id;
            document.getElementById('edit_username').value = admin.username;
            document.getElementById('edit_password').value = ''; // Kosongkan password saat membuka edit
            document.getElementById('editAdminModal').classList.remove('hidden');
        }

        // Fungsi untuk menutup modal Edit Admin
        function closeEditAdminModal() {
            document.getElementById('editAdminModal').classList.add('hidden');
        }

        // Tutup modal saat mengklik di luar konten modal
        document.getElementById('addAdminModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeAddAdminModal();
            }
        });
        document.getElementById('editAdminModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeEditAdminModal();
            }
        });
    </script>
</body>

</html>