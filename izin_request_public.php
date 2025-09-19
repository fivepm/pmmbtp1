<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// SERTAKAN FILE KONFIGURASI DATABASE
require_once 'config.php';

$message = '';
$message_type = '';
$event_id = 0;
$event_name = '';
$participant_id = 0;
$participant_name = '';
$participant_barcode = '';
$current_izin_status = ''; // Status izin peserta untuk event ini

// Dapatkan alias event dari URL
$event_alias = isset($_GET['alias']) ? $_GET['alias'] : '';

// Jika alias tidak ada, atau halaman izin tidak aktif, redirect atau tampilkan pesan
if (empty($event_alias)) {
    // die("Halaman pengajuan izin tidak valid.");
    $message = "Halaman pengajuan izin tidak valid.";
    $message_type = 'error';
}

// Ambil detail event berdasarkan alias dan status aktifnya
$sql_event = "SELECT id, event_name, event_date, izin_page_active FROM events WHERE izin_page_alias = ?";
$stmt_event = $conn->prepare($sql_event);
$stmt_event->bind_param("s", $event_alias);
$stmt_event->execute();
$result_event = $stmt_event->get_result();

if ($result_event->num_rows === 1) {
    $event_data = $result_event->fetch_assoc();
    $event_id = $event_data['id'];
    $event_name = htmlspecialchars($event_data['event_name'] . " (" . date('d M Y', strtotime($event_data['event_date'])) . ")");
    $izin_page_active = $event_data['izin_page_active'];

    if (!$izin_page_active) {
        // die("Halaman pengajuan izin untuk event ini sedang tidak aktif.");
        $message = 'Halaman pengajuan izin untuk event ini sedang tidak aktif. Silahkan Hubungi Ketua Muda/i Desa.';
        $message_type = 'warning';
    }
} else {
    // die("Halaman pengajuan izin tidak ditemukan.");
    $message = "Halaman pengajuan izin tidak ditemukan.";
    $message_type = 'error';
}
$stmt_event->close();

// --- LOGIKA PEMINDAIAN BARCODE (AJAX) ---
if (isset($_POST['scan_barcode_data'])) {
    header('Content-Type: application/json');
    $scanned_barcode_data = $_POST['scan_barcode_data'];
    $scanned_barcode_data = $conn->real_escape_string($scanned_barcode_data);

    $sql_participant = "SELECT id, name, jenis_kelamin, kelompok, kategori_usia FROM participants WHERE barcode_data = ? AND is_active = TRUE";
    $stmt_participant = $conn->prepare($sql_participant);
    $stmt_participant->bind_param("s", $scanned_barcode_data);
    $stmt_participant->execute();
    $result_participant = $stmt_participant->get_result();

    if ($result_participant->num_rows === 1) {
        $participant_data = $result_participant->fetch_assoc();
        $participant_id_scanned = $participant_data['id'];
        $participant_name_scanned = htmlspecialchars($participant_data['name']);
        $jenis_kelamin_scanned = htmlspecialchars($participant_data['jenis_kelamin']);
        $kelompok_scanned = htmlspecialchars($participant_data['kelompok']);
        $kategori_usia_scanned = htmlspecialchars($participant_data['kategori_usia']);

        // Cek apakah sudah ada pengajuan izin untuk peserta ini di event ini
        // Ambil juga alasan dan bukti foto jika ada dan statusnya Pending
        $sql_check_izin = "SELECT id, status, reason, photo_proof_url FROM izin_requests WHERE participant_id = ? AND event_id = ?";
        $stmt_check_izin = $conn->prepare($sql_check_izin);
        $stmt_check_izin->bind_param("ii", $participant_id_scanned, $event_id);
        $stmt_check_izin->execute();
        $result_check_izin = $stmt_check_izin->get_result();

        $izin_request_status = 'Belum Mengajukan';
        $existing_reason = '';
        $existing_photo_proof_url = '';

        if ($result_check_izin->num_rows > 0) {
            $existing_izin = $result_check_izin->fetch_assoc();
            $izin_request_status = $existing_izin['status'];
            if ($existing_izin['status'] != 'Belum Mengajukan') { // Hanya ambil reason jika sudah mengajukan
                $existing_reason = htmlspecialchars($existing_izin['reason']);
                $existing_photo_proof_url = htmlspecialchars($existing_izin['photo_proof_url']);
            }
        }
        $stmt_check_izin->close();

        echo json_encode([
            'success' => true,
            'participant' => [
                'id' => $participant_id_scanned,
                'name' => $participant_name_scanned,
                'jenis_kelamin' => $jenis_kelamin_scanned,
                'kelompok' => $kelompok_scanned,
                'kategori_usia' => $kategori_usia_scanned,
                'izin_status' => $izin_request_status, // Status pengajuan izin saat ini
                'existing_reason' => $existing_reason, // Alasan dari pengajuan Pending yang sudah ada
                'existing_photo_proof_url' => $existing_photo_proof_url // Bukti foto dari pengajuan Pending yang sudah ada
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Barcode tidak dikenal atau peserta tidak aktif.']);
    }
    $stmt_participant->close();
    exit;
}

// --- LOGIKA PENGAJUAN IZIN (FORM SUBMISSION) ---
if (isset($_POST['submit_izin'])) {
    $participant_id_form = $_POST['participant_id'] ?? 0;
    $reason = $_POST['reason'] ?? '';
    $photo_proof_url = '';

    // Validasi dasar
    if ($participant_id_form <= 0 || empty($reason)) {
        $message = "Nama peserta dan alasan tidak boleh kosong.";
        $message_type = 'error';
    } else {
        $participant_id_form = (int)$participant_id_form;
        $reason = $conn->real_escape_string($reason);

        // Handle photo upload (opsional)
        if (isset($_FILES['photo_proof']) && $_FILES['photo_proof']['error'] == UPLOAD_ERR_OK) {
            $target_dir = "uploads/izin_proofs/"; // Pastikan folder ini ada dan writable
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $file_extension = strtolower(pathinfo($_FILES['photo_proof']['name'], PATHINFO_EXTENSION));
            $new_file_name = uniqid('proof_') . '.' . $file_extension;
            $target_file = $target_dir . $new_file_name;
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_extension, $allowed_types) && $_FILES['photo_proof']['size'] < 5000000) { // Max 5MB
                if (move_uploaded_file($_FILES['photo_proof']['tmp_name'], $target_file)) {
                    $photo_proof_url = $target_file;
                } else {
                    $message = "Gagal mengunggah foto bukti.";
                    $message_type = 'error';
                }
            } else {
                $message = "Format foto tidak valid atau ukuran terlalu besar (maks 5MB).";
                $message_type = 'error';
            }
        }

        if (empty($message)) { // Lanjutkan jika tidak ada error upload
            // Cek apakah sudah ada pengajuan izin untuk peserta ini di event ini
            $sql_check_izin = "SELECT id, status, photo_proof_url FROM izin_requests WHERE participant_id = ? AND event_id = ?";
            $stmt_check_izin = $conn->prepare($sql_check_izin);
            $stmt_check_izin->bind_param("ii", $participant_id_form, $event_id);
            $stmt_check_izin->execute();
            $result_check_izin = $stmt_check_izin->get_result();

            if ($result_check_izin->num_rows > 0) {
                // Jika sudah ada, update yang sudah ada (hanya jika statusnya Pending)
                $existing_request = $result_check_izin->fetch_assoc();
                if ($existing_request['status'] === 'Belum Mengajukan' || $existing_request['status'] === 'Pending') {
                    // Jika ada upload foto baru, gunakan itu. Jika tidak, pertahankan yang lama.
                    if ($existing_request['status'] != 'Belum Mengajukan') {
                        $final_photo_proof_url = empty($photo_proof_url) ? htmlspecialchars($existing_request['photo_proof_url']) : $photo_proof_url;
                    } else {
                        $final_photo_proof_url = $photo_proof_url;
                    }
                    $sql_update_izin = "UPDATE izin_requests SET reason = ?, photo_proof_url = ?, request_time = NOW(), status = 'Pending' WHERE id = ?";
                    $stmt_update_izin = $conn->prepare($sql_update_izin);
                    $stmt_update_izin->bind_param("ssi", $reason, $final_photo_proof_url, $existing_request['id']);
                    if ($stmt_update_izin->execute()) {
                        $message = "Pengajuan izin berhasil diperbarui!";
                        $message_type = 'success';
                    } else {
                        $message = "Gagal memperbarui pengajuan izin: " . $stmt_update_izin->error;
                        $message_type = 'error';
                    }
                    $stmt_update_izin->close();
                } else {
                    $message = "Pengajuan izin Anda sudah diproses (Status: {$existing_request['status']}). Tidak dapat mengajukan ulang.";
                    $message_type = 'info';
                }
            } else {
                // Jika belum ada, buat pengajuan baru
                $sql_insert_izin = "INSERT INTO izin_requests (participant_id, event_id, reason, photo_proof_url, status) VALUES (?, ?, ?, ?, 'Pending')";
                $stmt_insert_izin = $conn->prepare($sql_insert_izin);
                $stmt_insert_izin->bind_param("iiss", $participant_id_form, $event_id, $reason, $photo_proof_url);
                if ($stmt_insert_izin->execute()) {
                    $message = "Pengajuan izin Anda berhasil dikirim!";
                    $message_type = 'success';
                } else {
                    $message = "Gagal mengirim pengajuan izin: " . $stmt_insert_izin->error;
                    $message_type = 'error';
                }
                $stmt_insert_izin->close();
            }
            $stmt_check_izin->close();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Izin</title>
    <link rel="icon" href="images/logo_kmm.jpg" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 1rem;
        }

        #video,
        #canvas {
            width: 100%;
            max-width: 400px;
            /* Lebih kecil untuk halaman publik */
            height: auto;
            display: block;
            margin: 0 auto;
            border-radius: 0.75rem;
        }

        .hidden {
            display: none !important;
        }

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
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex flex-col">
    <header class="bg-indigo-700 text-white p-4 shadow-md">
        <div class="container mx-auto text-center">
            <h1 class="text-2xl font-bold">Form Pengajuan Izin</h1>
            <p class="text-lg"><?php echo $event_name; ?></p>
        </div>
    </header>

    <div class="flex-grow container bg-white p-6 rounded-xl shadow-lg w-full max-w-2xl mt-8 mb-8">
        <?php if (!empty($message)): ?>
            <div class="mb-4 p-3 rounded-md <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div id="scan-section" class="mb-6 bg-gray-50 p-4 rounded-xl shadow-inner text-center">
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Pindai QR Code Anda</h2>
            <video id="video" class="bg-gray-200 rounded-xl" autoplay playsinline></video>
            <canvas id="canvas" class="hidden"></canvas>
            <div id="loadingMessage" class="text-gray-500 mt-4 text-sm hidden">Memuat pemindai video...</div>
            <div id="output" class="mt-4 p-3 bg-blue-50 text-blue-800 rounded-lg hidden">
                <p>Data Terdeteksi: <span id="outputData" class="font-medium"></span></p>
            </div>
            <button id="scanButton" class="mt-6 w-full px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition duration-300 transform hover:scale-105">
                Mulai Pindai Barcode
            </button>
        </div>

        <div id="participant-info-form" class="hidden bg-gray-50 p-4 rounded-xl shadow-inner">
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Data Anda</h2>
            <form action="izin_request_public.php?alias=<?php echo htmlspecialchars($event_alias); ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="participant_id" id="form_participant_id">
                <input type="hidden" name="event_id" value="<?php echo htmlspecialchars($event_id); ?>">

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Nama:</label>
                    <p id="info_name" class="p-2 bg-white border border-gray-300 rounded-lg"></p>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Jenis Kelamin:</label>
                    <p id="info_jenis_kelamin" class="p-2 bg-white border border-gray-300 rounded-lg"></p>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Kelompok:</label>
                    <p id="info_kelompok" class="p-2 bg-white border border-gray-300 rounded-lg"></p>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Kategori Usia:</label>
                    <p id="info_kategori_usia" class="p-2 bg-white border border-gray-300 rounded-lg"></p>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Status Pengajuan Izin Anda:</label>
                    <p id="info_izin_status" class="p-2 bg-white border border-gray-300 rounded-lg font-bold"></p>
                </div>

                <h3 class="text-xl font-semibold text-gray-700 mt-6 mb-4">Form Pengajuan Izin</h3>
                <div class="mb-4">
                    <label for="reason" class="block text-gray-700 text-sm font-semibold mb-2">Alasan Pengajuan Izin:</label>
                    <textarea id="reason" name="reason" rows="4" required
                        class="shadow-sm appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition duration-200"
                        placeholder="Contoh: Sakit, ada acara keluarga, dll."></textarea>
                </div>
                <div class="mb-6">
                    <label for="photo_proof" class="block text-gray-700 text-sm font-semibold mb-2">Unggah Foto Bukti (Opsional, maks 5MB):</label>
                    <input type="file" id="photo_proof" name="photo_proof" accept="image/*"
                        class="block w-full text-sm text-gray-500
                           file:mr-4 file:py-2 file:px-4
                           file:rounded-md file:border-0
                           file:text-sm file:font-semibold
                           file:bg-indigo-50 file:text-indigo-700
                           hover:file:bg-indigo-100 transition duration-200">
                </div>
                <button type="submit" name="submit_izin"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg focus:outline-none focus:shadow-outline transition duration-300 transform hover:scale-105">
                    Kirim Pengajuan Izin
                </button>
            </form>
        </div>
    </div>

    <footer class="bg-gray-800 text-white text-center p-4 mt-auto">
        <p>&copy; <?php echo date("Y"); ?> KMM Banguntapan 1. All rights reserved.</p>
    </footer>

    <div id="messageModal" class="modal-overlay hidden">
        <div class="modal-content">
            <span class="close-button" onclick="closeMessageModal()">&times;</span>
            <p id="modalMessage" class="text-lg text-gray-700 text-center"></p>
            <div class="mt-4 flex justify-center">
                <button onclick="closeMessageModal()" class="px-5 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition duration-200">Oke</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script>
        const video = document.getElementById('video');
        const canvasElement = document.getElementById('canvas');
        const canvasContext = canvasElement.getContext('2d');
        const loadingMessage = document.getElementById('loadingMessage');
        const outputDiv = document.getElementById('output');
        const outputDataSpan = document.getElementById('outputData');
        const scanButton = document.getElementById('scanButton');
        const participantInfoForm = document.getElementById('participant-info-form');
        const formParticipantId = document.getElementById('form_participant_id');
        const infoName = document.getElementById('info_name');
        const infoJenisKelamin = document.getElementById('info_jenis_kelamin');
        const infoKelompok = document.getElementById('info_kelompok');
        const infoKategoriUsia = document.getElementById('info_kategori_usia');
        const infoIzinStatus = document.getElementById('info_izin_status');
        const reasonTextarea = document.getElementById('reason'); // Tambahkan ini
        const photoProofInput = document.getElementById('photo_proof'); // Tambahkan ini


        let videoStream;
        let animationFrameId;
        const eventAlias = "<?php echo htmlspecialchars($event_alias); ?>";
        const eventId = "<?php echo htmlspecialchars($event_id); ?>";

        // Function to show custom modal messages
        window.showMessage = function(message, type = 'info') {
            const modal = document.getElementById('messageModal');
            const modalMessage = document.getElementById('modalMessage');
            modalMessage.innerHTML = message;

            const modalContent = modal.querySelector('.modal-content');
            modalContent.classList.remove('border-red-400', 'border-green-400', 'border-yellow-400', 'bg-red-50', 'bg-green-50', 'bg-blue-50', 'bg-yellow-50'); // Clear previous
            modalMessage.classList.remove('text-red-700', 'text-green-700', 'text-blue-700', 'text-yellow-700');

            if (type === 'error') {
                modalContent.classList.add('bg-red-50');
                modalContent.style.borderColor = '#FCA5A5';
                modalMessage.classList.add('text-red-700');
            } else if (type === 'success') {
                modalContent.classList.add('bg-green-50');
                modalContent.style.borderColor = '#B2F5EA';
                modalMessage.classList.add('text-green-700');
            } else if (type === 'info') {
                modalContent.classList.add('bg-blue-50');
                modalContent.style.borderColor = '#BFDBFE';
                modalMessage.classList.add('text-blue-700');
            } else if (type === 'warning') {
                modalContent.classList.add('bg-yellow-50');
                modalContent.style.borderColor = '#fbfebf';
                modalMessage.classList.add('text-yellow-700');
            } else { // Default to gray for other types or unknown
                modalContent.style.backgroundColor = '#fefefe';
                modalContent.style.borderColor = '#888';
                modalMessage.classList.add('text-gray-700');
            }

            modal.classList.remove('hidden');
        }

        window.closeMessageModal = function() {
            document.getElementById('messageModal').classList.add('hidden');
            // Hanya restart scanner jika form info peserta tersembunyi (artinya belum ada data peserta yang tampil)
            if (participantInfoForm.classList.contains('hidden')) {
                setTimeout(startScanner, 500); // Beri jeda sebentar sebelum mulai scan lagi
            }
        }

        async function startScanner() {
            loadingMessage.classList.remove('hidden');
            outputDiv.classList.add('hidden');
            outputDataSpan.textContent = '';
            participantInfoForm.classList.add('hidden'); // Sembunyikan form info peserta saat mulai scan

            // Bersihkan form saat mulai scan baru
            formParticipantId.value = '';
            infoName.textContent = '';
            infoJenisKelamin.textContent = '';
            infoKelompok.textContent = '';
            infoKategoriUsia.textContent = '';
            infoIzinStatus.textContent = '';
            reasonTextarea.value = ''; // Bersihkan textarea reason
            photoProofInput.value = ''; // Bersihkan input file


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

                    verifyBarcode(scannedData);
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

        async function verifyBarcode(barcodeData) {
            try {
                const response = await fetch('izin_request_public.php?alias=' + encodeURIComponent(eventAlias), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `scan_barcode_data=${encodeURIComponent(barcodeData)}`
                });
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    const jsonResponse = await response.json();
                    if (jsonResponse.success) {
                        formParticipantId.value = jsonResponse.participant.id;
                        infoName.textContent = jsonResponse.participant.name;
                        infoJenisKelamin.textContent = jsonResponse.participant.jenis_kelamin;
                        infoKelompok.textContent = jsonResponse.participant.kelompok;
                        infoKategoriUsia.textContent = jsonResponse.participant.kategori_usia;
                        infoIzinStatus.textContent = jsonResponse.participant.izin_status;

                        // Mengisi form reason jika sudah ada pengajuan Pending
                        if (jsonResponse.participant.izin_status != 'Belum Mengajukan' && jsonResponse.participant.existing_reason) {
                            reasonTextarea.value = jsonResponse.participant.existing_reason;
                        } else {
                            reasonTextarea.value = ''; // Kosongkan jika bukan Pending atau tidak ada alasan
                        }
                        // Status color (seperti sebelumnya)
                        if (jsonResponse.participant.izin_status === 'Approved') {
                            infoIzinStatus.classList.add('text-green-600');
                            infoIzinStatus.classList.remove('text-red-600', 'text-yellow-600', 'text-gray-600');
                        } else if (jsonResponse.participant.izin_status === 'Rejected') {
                            infoIzinStatus.classList.add('text-red-600');
                            infoIzinStatus.classList.remove('text-green-600', 'text-yellow-600', 'text-gray-600');
                        } else if (jsonResponse.participant.izin_status === 'Pending') {
                            infoIzinStatus.classList.add('text-yellow-600');
                            infoIzinStatus.classList.remove('text-green-600', 'text-red-600', 'text-gray-600');
                        } else {
                            infoIzinStatus.classList.add('text-gray-600'); // Belum Mengajukan
                            infoIzinStatus.classList.remove('text-green-600', 'text-red-600', 'text-yellow-600');
                        }

                        participantInfoForm.classList.remove('hidden'); // Tampilkan form
                        showMessage("Data peserta ditemukan. Silakan lengkapi form pengajuan izin.", 'success');
                        // Tidak perlu restart scanner di sini, karena form sudah tampil
                    } else {
                        showMessage(jsonResponse.message, 'error');
                        participantInfoForm.classList.add('hidden'); // Sembunyikan form jika verifikasi gagal
                    }
                } else {
                    const textResponse = await response.text();
                    console.error("Server returned non-JSON response:", textResponse);
                    showMessage("Respons server tidak valid. Silakan periksa konsol browser untuk detail.", 'error');
                    participantInfoForm.classList.add('hidden'); // Sembunyikan form jika respons tidak valid
                }
            } catch (error) {
                console.error("Error verifying barcode:", error);
                showMessage("Terjadi kesalahan saat verifikasi barcode. Detail: " + error.message, 'error');
                participantInfoForm.classList.add('hidden'); // Sembunyikan form jika ada error
            } finally {
                loadingMessage.textContent = "";
            }
        }

        scanButton.addEventListener('click', startScanner);

        // Initial check
        window.onload = function() {
            <?php if (!empty($message)): ?>
                showMessage("<?php echo htmlspecialchars($message); ?>", "<?php echo $message_type; ?>");
            <?php endif; ?>
        };
    </script>
</body>

</html>