<?php
// Mulai session PHP
session_start();

// Hancurkan semua data session
session_unset(); // Hapus semua variabel session
session_destroy(); // Hancurkan session

// Arahkan kembali ke halaman login setelah logout
header('Location: login.php');
exit;
