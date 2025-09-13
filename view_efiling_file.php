<?php
session_start();
require_once 'config.php';

// Security: only attorneys or admin_attorney
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['attorney', 'admin_attorney'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$attorney_id = (int)$_SESSION['user_id'];
$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid file ID');
}

// Get file info - ensure it belongs to this attorney
$stmt = $conn->prepare("SELECT stored_file_path, file_name FROM efiling_history WHERE id=? AND attorney_id=?");
$stmt->bind_param("ii", $file_id, $attorney_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}

$row = $res->fetch_assoc();
$filePath = $row['stored_file_path'];
$fileName = $row['file_name'];

if (!file_exists($filePath)) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found on disk');
}

// Get file info
$mimeType = mime_content_type($filePath);
$fileSize = filesize($filePath);

// Set headers for inline viewing
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');
header('Cache-Control: private, max-age=3600');

// Output file
readfile($filePath);
exit();
?>
