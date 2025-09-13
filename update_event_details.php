<?php
require_once 'session_manager.php';
validateUserAccess('admin');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_POST['action']) || $_POST['action'] !== 'edit_event') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$event_id = intval($_POST['event_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$date = $_POST['date'] ?? '';
$time = $_POST['time'] ?? '';
$location = trim($_POST['location'] ?? '');
$type = $_POST['type'] ?? '';
$description = trim($_POST['description'] ?? '');

// Validation
if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit;
}

if (empty($title) || empty($date) || empty($time) || empty($location) || empty($type)) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Validate time format
if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    echo json_encode(['success' => false, 'message' => 'Invalid time format']);
    exit;
}

// Check if event exists and admin has permission to edit it
$stmt = $conn->prepare("SELECT * FROM case_schedules WHERE id = ?");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

$event = $result->fetch_assoc();

// Admin can edit any event
try {
    // Update the event
    $stmt = $conn->prepare("UPDATE case_schedules SET title = ?, date = ?, time = ?, location = ?, type = ?, description = ? WHERE id = ?");
    $stmt->bind_param("ssssssi", $title, $date, $time, $location, $type, $description, $event_id);
    
    if ($stmt->execute()) {
        // Log the action
        $admin_id = $_SESSION['user_id'];
        $action = "Updated event details";
        $details = "Event ID: $event_id, Title: $title, Date: $date, Time: $time, Location: $location, Type: $type";
        
        logAction($admin_id, 'admin', $action, $details, 'case_schedules', $event_id);
        
        echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update event']);
    }
} catch (Exception $e) {
    error_log("Error updating event: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}

$stmt->close();
$conn->close();
?>


