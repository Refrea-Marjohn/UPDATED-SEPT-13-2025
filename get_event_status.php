<?php
// Prevent any output before JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start session manually to avoid conflicts
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get event ID from POST data
$event_id = $_POST['event_id'] ?? null;
$action = $_POST['action'] ?? null;

// Validate input
if (!$event_id || $action !== 'get_status') {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid parameters']);
    exit;
}

try {
    // Get the current status of the event
    $stmt = $conn->prepare("SELECT status FROM case_schedules WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $status = $row['status'] ?? 'Scheduled';
            echo json_encode(['success' => true, 'status' => $status]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch event status']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error fetching event status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}

$conn->close();
?>

