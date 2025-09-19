<?php
// Mulai session PHP
session_start();

// Periksa apakah admin sudah login. Jika tidak, arahkan kembali ke halaman login.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Anda bisa mendapatkan ID dan username admin dari session jika diperlukan
$admin_id = $_SESSION['admin_id'] ?? 'Tidak Diketahui';
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

$conn->close(); // Tutup koneksi karena tidak ada query dinamis di dashboard ini
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
            flex-direction: row;
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
                <li><a href="admin_dashboard.php" class="flex items-center p-3 rounded-lg bg-indigo-600 text-white font-semibold shadow-md">
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
                <!-- Link Scan Presensi dihapus dari sini -->
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
            <h1 class="text-2xl font-bold ml-12">Dashboard Admin</h1>
            <!-- Optional: Add other top bar elements if needed -->
        </header>

        <!-- Main Content Area -->
        <main class="flex-grow p-6">
            <div class="bg-white p-8 rounded-xl shadow-lg border border-gray-200 text-center">
                <h2 class="text-4xl font-extrabold text-gray-800 mb-4">Selamat Datang, <?php echo htmlspecialchars($admin_username); ?>!</h2>
                <p class="text-lg text-gray-600 mb-8">Ini adalah panel admin Anda. Pilih salah satu opsi di sidebar untuk mulai mengelola data.</p>

                <div class="mb-6 p-4 bg-gray-50 rounded-lg shadow-inner text-center">
                    <p class="text-xl font-semibold text-gray-700 mb-2">Waktu Saat Ini</p>
                    <p id="realtimeClock" class="text-4xl md:text-5xl font-extrabold text-indigo-700">Loading...</p>
                    <p class="text-sm text-gray-500">Waktu sesuai perangkat Anda</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <a href="manage_participants.php" class="block p-6 bg-blue-500 hover:bg-blue-600 text-white rounded-xl shadow-md transition duration-300 transform hover:scale-105 group">
                        <h3 class="text-2xl font-bold mb-2">Manajemen Peserta</h3>
                        <p class="text-blue-100 group-hover:text-white">Tambah, edit, nonaktifkan, dan lihat data peserta event.</p>
                    </a>

                    <a href="manage_events.php" class="block p-6 bg-green-500 hover:bg-green-600 text-white rounded-xl shadow-md transition duration-300 transform hover:scale-105 group">
                        <h3 class="text-2xl font-bold mb-2">Manajemen Event</h3>
                        <p class="text-green-100 group-hover:text-white">Tambah, edit, hapus, dan lihat data event bulanan.</p>
                    </a>

                    <a href="manage_izin_pages.php" class="block p-6 bg-purple-500 hover:bg-purple-600 text-white rounded-xl shadow-md transition duration-300 transform hover:scale-105 group">
                        <h3 class="text-2xl font-bold mb-2">Kelola Halaman Izin</h3>
                        <p class="text-green-100 group-hover:text-white">Kelola pengajuan izin peserta.</p>
                    </a>

                    <!-- Link Scan Presensi dihapus dari sini -->

                    <a href="attendance_report.php" class="block p-6 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl shadow-md transition duration-300 transform hover:scale-105 group">
                        <h3 class="text-2xl font-bold mb-2">Laporan Presensi</h3>
                        <p class="text-yellow-100 group-hover:text-white">Lihat ringkasan dan detail laporan kehadiran event.</p>
                    </a>
                </div>
            </div>
        </main>

        <!-- Footer (Opsional) -->
        <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
            <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
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

        // Adjust main content margin on desktop load
        window.addEventListener('load', () => {
            if (window.innerWidth >= 768) { // md breakpoint
                mainContentWrapper.classList.add('shifted');
            }
        });

        // Adjust main content margin on resize
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                mainContentWrapper.classList.add('shifted');
                sidebar.classList.remove('open'); // Ensure sidebar is open on desktop
                sidebarOverlay.classList.remove('open'); // Hide overlay on desktop
            } else {
                mainContentWrapper.classList.remove('shifted');
            }
        });

        // --- Fungsi Jam Real-time ---
        function updateClock() {
            const now = new Date(); // Dapatkan objek tanggal dan waktu saat ini dari perangkat pengguna

            // Dapatkan komponen waktu
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();

            // Tambahkan nol di depan jika angka kurang dari 10
            hours = hours < 10 ? '0' + hours : hours;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;

            // Format waktu menjadi HH:MM:SS
            const timeString = `${hours}:${minutes}:${seconds}`;

            // Tampilkan di elemen HTML
            document.getElementById('realtimeClock').textContent = timeString;
        }

        // Panggil fungsi sekali saat halaman dimuat
        updateClock();

        // Perbarui jam setiap 1 detik
        setInterval(updateClock, 1000);
    </script>
</body>

</html>