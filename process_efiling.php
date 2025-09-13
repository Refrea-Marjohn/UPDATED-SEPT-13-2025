<?php
session_start();
header('Content-Type: application/json');

require_once 'config.php';
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Security: only attorneys or admin_attorney
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['attorney','admin_attorney'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$attorney_id = (int)$_SESSION['user_id'];

// Handle clear history request
if (isset($_POST['action']) && $_POST['action'] === 'clear_history') {
    // Delete all eFiling history for this attorney
    $stmt = $conn->prepare("DELETE FROM efiling_history WHERE attorney_id=?");
    $stmt->bind_param("i", $attorney_id);
    $stmt->execute();
    
    // Also delete the stored files
    $stmt = $conn->prepare("SELECT stored_file_path FROM efiling_history WHERE attorney_id=?");
    $stmt->bind_param("i", $attorney_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['stored_file_path']) && file_exists($row['stored_file_path'])) {
            unlink($row['stored_file_path']);
        }
    }
    
    echo json_encode(['status' => 'success', 'message' => 'All eFiling history cleared']);
    exit();
}

// Validate inputs
$case_id = isset($_POST['case_id']) && $_POST['case_id'] !== '' ? (int)$_POST['case_id'] : null;
$case_number = trim($_POST['case_number'] ?? '');
$receiver_email = trim($_POST['receiver_email'] ?? '');
$receiver_email_confirm = trim($_POST['receiver_email_confirm'] ?? '');
$desired_filename = trim($_POST['desired_filename'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($receiver_email === '' || $receiver_email_confirm === '' || $desired_filename === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please complete all required fields.']);
    exit();
}

if (strtolower($receiver_email) !== strtolower($receiver_email_confirm)) {
    echo json_encode(['status' => 'error', 'message' => 'Receiver emails do not match.']);
    exit();
}

if (!filter_var($receiver_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
    exit();
}

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'Document upload failed.']);
    exit();
}

// Validate file size and type
$allowed_ext = ['pdf'];
$uploaded_name = $_FILES['document']['name'];
$uploaded_tmp = $_FILES['document']['tmp_name'];
$uploaded_ext = strtolower(pathinfo($uploaded_name, PATHINFO_EXTENSION));
$original_file_name = $uploaded_name; // Store the original uploaded filename

if (!in_array($uploaded_ext, $allowed_ext, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Only PDF files are allowed.']);
    exit();
}

if (filesize($uploaded_tmp) > 10 * 1024 * 1024) { // 10MB
    echo json_encode(['status' => 'error', 'message' => 'File too large (max 10MB).']);
    exit();
}

// Ensure desired filename has same extension
// Force desired filename to .pdf and sanitize
$base = pathinfo($desired_filename, PATHINFO_FILENAME);
$base = trim($base);
if ($base === '') { $base = 'document'; }
// Replace spaces with underscores and strip unsafe chars
$base = preg_replace('/\s+/', '_', $base);
$base = preg_replace('/[^A-Za-z0-9._-]/', '', $base);
if ($base === '' || $base === '.' || $base === '..') { $base = 'document'; }
$desired_filename = $base . '.pdf';

// Move the uploaded file to a temp path using the desired filename
$tempDir = sys_get_temp_dir();
$targetPath = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $desired_filename;
// If a file with the same name exists in temp, create a unique variant
if (file_exists($targetPath)) {
    $targetPath = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('ef_', true) . '_' . $desired_filename;
}
if (!move_uploaded_file($uploaded_tmp, $targetPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Could not process the uploaded file.']);
    exit();
}

// Prepare email
$mail = new PHPMailer(true);
$status = 'Failed';
try {
    if (defined('MAIL_DEBUG') && MAIL_DEBUG) {
        $mail->SMTPDebug = 2;
    }
    $mail->isSMTP();
    $mail->Host = MAIL_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_USERNAME;
    $mail->Password = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($receiver_email);
    if (defined('MAIL_FROM') && defined('MAIL_FROM_NAME')) {
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);
    }

    // Build body
    $body = "<p>Good day,</p>" .
            "<p>Please find attached the filing document for case <strong>" . htmlspecialchars($case_number) . "</strong>.</p>" .
            ($message !== '' ? ("<p>Notes: " . nl2br(htmlspecialchars($message)) . "</p>") : '') .
            "<hr><p>Details:</p>" .
            "<ul>" .
            "<li>Case Number: " . htmlspecialchars($case_number) . "</li>" .
            "<li>File Name: " . htmlspecialchars($desired_filename) . "</li>" .
            "</ul>" .
            "<p>Sent via Opiña Law Office eFiling System.</p>";

    $mail->isHTML(true);
    $mail->Subject = 'eFiling Submission - ' . $case_number . ' - ' . $desired_filename;
    $mail->Body = $body;
    $mail->AltBody = "eFiling Submission\n\nCase: $case_number\nFile: $desired_filename\n" . ($message !== '' ? ("Notes: $message\n") : '') . "Sent via Opiña Law Office eFiling System.";

    // Attach the file using the new path and desired filename
    $mail->addAttachment($targetPath, $desired_filename);

    if ($mail->send()) {
        $status = 'Sent';
    }
} catch (Exception $e) {
    error_log('eFiling send error: ' . $mail->ErrorInfo . ' Exception: ' . $e->getMessage());
}

// Log to history regardless of result
$stmt = $conn->prepare("INSERT INTO efiling_history (attorney_id, case_id, case_number, file_name, original_file_name, stored_file_path, receiver_email, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param('iisssssss', $attorney_id, $case_id, $case_number, $desired_filename, $original_file_name, $targetPath, $receiver_email, $message, $status);
$stmt->execute();

if ($status === 'Sent') {
    echo json_encode(['status' => 'success', 'message' => 'Submission sent successfully.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to send submission.']);
}
?>


