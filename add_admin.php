<?php
// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

$username = 'admin'; // Username admin Anda
$password = 'admin123'; // Password admin Anda (Ganti dengan password yang kuat!)

// Hash password sebelum menyimpan ke database
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Masukkan pengguna baru
$sql = "INSERT INTO users (username, password) VALUES (?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $username, $hashed_password);

if ($stmt->execute()) {
    echo "Pengguna admin '" . $username . "' berhasil ditambahkan.";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
