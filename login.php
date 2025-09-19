<?php
// Mulai session PHP
session_start();

// Periksa apakah admin sudah login, jika ya, arahkan ke dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_dashboard.php'); // Ganti dengan halaman dashboard admin Anda
    exit;
}

// Inisialisasi variabel untuk pesan error
$error_message = '';

// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

// Tangani proses login saat form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Sanitize input untuk mencegah SQL Injection
    $username = $conn->real_escape_string($username);
    // Password tidak perlu di-escape string karena akan diverifikasi dengan password_verify()

    // Query database untuk mengambil pengguna berdasarkan username
    $sql = "SELECT id, username, password FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username); // 's' menandakan string
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Verifikasi password yang dimasukkan dengan hash password di database
        if (password_verify($password, $user['password'])) {
            // Login berhasil
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            header('Location: admin_dashboard.php'); // Arahkan ke dashboard admin
            exit;
        } else {
            $error_message = "Username atau password salah.";
        }
    } else {
        $error_message = "Username atau password salah.";
    }

    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Presensi KMM</title>
    <link rel="icon" href="images/logo_kmm.jpg" type="image/png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-md bg-white p-8 rounded-xl shadow-lg border border-gray-200">
        <img src="images/logo_kmm.jpg" alt="Logo Admin" class="w-20 h-20 mx-auto mb-4 rounded-full border-2 border-indigo-400">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">Login</h2>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-semibold mb-2">Username:</label>
                <input type="text" id="username" name="username" required
                    class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                    placeholder="Masukkan username Anda">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password:</label>
                <input type="password" id="password" name="password" required
                    class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                    placeholder="Masukkan password Anda">
            </div>
            <div class="flex items-center justify-between">
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Login
                </button>
            </div>
        </form>
    </div>
</body>

</html>