<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['attorney_name']) || $_SESSION['user_type'] !== 'attorney') {
    http_response_code(403);
    exit('Forbidden');
}

$zip = new ZipArchive();
$zipFile = sys_get_temp_dir() . '/attorney_documents_' . time() . '.zip';
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    exit('Could not create ZIP file.');
}

$result = $conn->query("SELECT file_name, file_path, case_id FROM attorney_documents ORDER BY case_id ASC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $filePath = $row['file_path'];
        $fileName = $row['file_name'];
        $caseId = $row['case_id'];
        
        if (file_exists($filePath)) {
            // Create organized filename with case number prefix
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
            $organizedFileName = sprintf("Case_%03d_%s.%s", $caseId, $nameWithoutExt, $extension);
            
            $zip->addFile($filePath, $organizedFileName);
        }
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="attorney_documents.zip"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);
@unlink($zipFile);
exit(); 