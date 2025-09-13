<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['employee_name']) || $_SESSION['user_type'] !== 'employee') {
    http_response_code(403);
    exit('Forbidden');
}

$zip = new ZipArchive();
$zipFile = sys_get_temp_dir() . '/employee_documents_' . time() . '.zip';
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    exit('Could not create ZIP file.');
}

$result = $conn->query("SELECT file_name, file_path, form_number FROM employee_documents ORDER BY form_number ASC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $filePath = $row['file_path'];
        $fileName = $row['file_name'];
        $formNumber = $row['form_number'];
        
        if (file_exists($filePath)) {
            // Create organized filename with form number prefix
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
            $organizedFileName = sprintf("Form_%03d_%s.%s", $formNumber, $nameWithoutExt, $extension);
            
            $zip->addFile($filePath, $organizedFileName);
        }
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="employee_documents.zip"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);
@unlink($zipFile);
exit(); 