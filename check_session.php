<?php
require_once 'session_manager.php';
header('Content-Type: application/json');

// Handle AJAX requests
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'check_session':
        if (isSessionValid()) {
            $userInfo = getUserInfo();
            echo json_encode([
                'valid' => true,
                'user_id' => $userInfo['id'],
                'user_type' => $userInfo['type'],
                'user_name' => $userInfo['name'],
                'time_left' => $userInfo['time_left']
            ]);
        } else {
            echo json_encode(['valid' => false, 'message' => 'Session expired']);
        }
        break;
        
    case 'extend_session':
        if (isSessionValid()) {
            extendSession();
            echo json_encode(['success' => true, 'message' => 'Session extended']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Session expired']);
        }
        break;
        
    default:
        echo json_encode(['valid' => false, 'message' => 'Invalid action']);
        break;
}
?> 