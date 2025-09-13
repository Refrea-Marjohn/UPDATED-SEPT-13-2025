<?php
// Prevent any output before JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start session manually to avoid conflicts
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Check if user is logged in and is an attorney, employee, admin, or client
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['attorney', 'employee', 'admin', 'client'])) {
    error_log("Session check failed - user_id: " . ($_SESSION['user_id'] ?? 'not set') . ", user_type: " . ($_SESSION['user_type'] ?? 'not set'));
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

// Get event ID and new status from POST data
$event_id = $_POST['event_id'] ?? null;
$new_status = $_POST['new_status'] ?? null;

// Validate input
if (!$event_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Debug logging
error_log("Update event status request - Event ID: $event_id, New Status: $new_status, User ID: " . ($_SESSION['user_id'] ?? 'not set') . ", User Type: " . ($_SESSION['user_type'] ?? 'not set'));

// Validate status value
$valid_statuses = ['Scheduled', 'Completed', 'Cancelled', 'Rescheduled'];
if (!in_array($new_status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit;
}

try {
    // Build the WHERE clause based on user type
    $where_clause = "id = ?";
    $params = [$new_status, $event_id];
    $types = "si";
    
    if ($_SESSION['user_type'] === 'admin') {
        // Admins can update any event (no additional restrictions)
        // No additional WHERE conditions needed
    } elseif ($_SESSION['user_type'] === 'attorney') {
        // Attorneys can update events where they are the assigned attorney
        $where_clause .= " AND attorney_id = ?";
        $params[] = $_SESSION['user_id'];
        $types .= "i";
    } elseif ($_SESSION['user_type'] === 'employee') {
        // Employees can update ANY event status (no restrictions)
        // No additional WHERE conditions needed
    } elseif ($_SESSION['user_type'] === 'client') {
        // Clients can update events where they are the client
        $where_clause .= " AND client_id = ?";
        $params[] = $_SESSION['user_id'];
        $types .= "i";
    }
    
    // Update the event status in the database
    $stmt = $conn->prepare("UPDATE case_schedules SET status = ? WHERE $where_clause");
    $stmt->bind_param($types, ...$params);
    
    // Debug: Log the update attempt
    error_log("Attempting to update event $event_id to status '$new_status' for " . $_SESSION['user_type'] . " " . $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $affectedRows = $stmt->affected_rows;
        error_log("Update executed. Affected rows: $affectedRows");
        
        if ($affectedRows > 0) {
            // Log the status change to audit trail
            try {
                require_once 'audit_logger.php';
                $auditLogger = new AuditLogger($conn);
                $auditLogger->logAction(
                    $_SESSION['user_id'],
                    $_SESSION['user_name'] ?? 'Unknown User',
                    $_SESSION['user_type'],
                    'Event Status Update',
                    'Case Management',
                    "Updated event #$event_id status to: $new_status",
                    'success',
                    'medium'
                );
            } catch (Exception $auditError) {
                // Log audit error but don't fail the main operation
                error_log("Audit logging failed: " . $auditError->getMessage());
            }
            
            echo json_encode(['success' => true, 'message' => 'Event status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No event found with the specified ID or you do not have permission to update it']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update event status']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error updating event status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}

$conn->close();
?>
