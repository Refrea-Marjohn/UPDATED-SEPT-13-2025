<?php
require_once 'session_manager.php';
validateUserAccess('employee');
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit();
}

$request_id = intval($_GET['id']);

try {
    // Fetch request details
    $stmt = $conn->prepare("
        SELECT crf.*, u.name as client_name, u.email as client_email
        FROM client_request_form crf
        JOIN user_form u ON crf.client_id = u.id
        WHERE crf.id = ?
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit();
    }
    
    $request = $result->fetch_assoc();
    
    // Convert privacy_consent to boolean for JavaScript
    $request['privacy_consent'] = (bool)$request['privacy_consent'];
    
    echo json_encode([
        'success' => true,
        'request' => $request
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
