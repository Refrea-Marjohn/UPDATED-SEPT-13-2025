<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session_manager.php';
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug: Check session variables (after session is started)
error_log("Session variables: " . print_r($_SESSION, true));

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    header('Location: login_form.php');
    exit;
}

// Validate user access
if ($_SESSION['user_type'] !== 'employee') {
    header('Location: login_form.php');
    exit;
}

$employee_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
}
if (!$profile_image || !file_exists($profile_image)) {
        $profile_image = 'images/default-avatar.jpg';
    }

// Fetch all registered attorneys and admins for dropdown
$attorneys_and_admins = [];
$stmt = $conn->prepare("SELECT id, name, user_type FROM user_form WHERE user_type IN ('attorney', 'admin') ORDER BY user_type, name");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $attorneys_and_admins[] = $row;

// Fetch all cases for dropdown
$cases = [];
$stmt = $conn->prepare("SELECT ac.id, ac.title, uf.name as client_name FROM attorney_cases ac LEFT JOIN user_form uf ON ac.client_id = uf.id ORDER BY ac.id DESC");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $cases[] = $row;

// Fetch all clients for dropdown (for free legal advice sessions)
$clients = [];
$stmt = $conn->prepare("SELECT id, name, email FROM user_form WHERE user_type = 'client' ORDER BY name");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clients[] = $row;

// Handle add event
if (isset($_POST['action']) && $_POST['action'] === 'add_event') {
    $title = $_POST['title'];
    $type = $_POST['type'];
    $date = $_POST['date'];
    $time = $_POST['time'];
    $location = $_POST['location'];
    $case_id = !empty($_POST['case_id']) ? intval($_POST['case_id']) : null;
    $client_id = !empty($_POST['client_id']) ? intval($_POST['client_id']) : null;
    $description = $_POST['description'];
    $selected_user_id = intval($_POST['selected_user_id']);
    $selected_user_type = $_POST['selected_user_type'];
    
    // If case_id is provided, get client_id from case
    if ($case_id) {
        $stmt = $conn->prepare("SELECT client_id FROM attorney_cases WHERE id=?");
        $stmt->bind_param("i", $case_id);
        $stmt->execute();
        $q = $stmt->get_result();
        if ($r = $q->fetch_assoc()) $client_id = $r['client_id'];
    }
    
    // Set attorney_id based on selected user type (both attorneys and admins are stored in attorney_id)
    $attorney_id = ($selected_user_type === 'attorney' || $selected_user_type === 'admin') ? $selected_user_id : null;
    
    $stmt = $conn->prepare("INSERT INTO case_schedules (case_id, attorney_id, client_id, type, title, description, date, time, location, created_by_employee_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiissssssi', $case_id, $attorney_id, $client_id, $type, $title, $description, $date, $time, $location, $employee_id);
    $stmt->execute();
    echo $stmt->affected_rows > 0 ? 'success' : 'error';
    exit();
}

// Handle edit event
if (isset($_POST['action']) && $_POST['action'] === 'edit_event') {
    $event_id = intval($_POST['event_id']);
    $title = $_POST['title'];
    $type = $_POST['type'];
    $date = $_POST['date'];
    $time = $_POST['time'];
    $location = $_POST['location'];
    $description = $_POST['description'];
    
    // Update the event (case_id and client_id remain unchanged)
    $stmt = $conn->prepare("UPDATE case_schedules SET type=?, title=?, description=?, date=?, time=?, location=? WHERE id=?");
    $stmt->bind_param('ssssssi', $type, $title, $description, $date, $time, $location, $event_id);
    $stmt->execute();
    echo $stmt->affected_rows > 0 ? 'success' : 'error';
    exit();
}

// Fetch all events with joins
$events = [];
try {
    $stmt = $conn->prepare("SELECT cs.*, ac.title as case_title, 
        CASE 
            WHEN cs.attorney_id IS NOT NULL THEN cs.attorney_id 
            ELSE ac.attorney_id 
        END as final_attorney_id,
        uf1.name as attorney_name, uf1.user_type as attorney_user_type, uf2.name as client_name, uf3.name as employee_name 
        FROM case_schedules cs
        LEFT JOIN attorney_cases ac ON cs.case_id = ac.id
        LEFT JOIN user_form uf1 ON (
            CASE 
                WHEN cs.attorney_id IS NOT NULL THEN cs.attorney_id 
                ELSE ac.attorney_id 
            END
        ) = uf1.id
        LEFT JOIN user_form uf2 ON cs.client_id = uf2.id
        LEFT JOIN user_form uf3 ON cs.created_by_employee_id = uf3.id
        ORDER BY CASE WHEN uf1.user_type = 'admin' THEN 1 WHEN uf1.user_type = 'attorney' THEN 2 ELSE 3 END, cs.date, cs.time");
    
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $events[] = $row;
        error_log("Events query executed successfully. Found " . count($events) . " events.");
    } else {
        error_log("Failed to prepare events query");
    }
} catch (Exception $e) {
    error_log("Error fetching events: " . $e->getMessage());
    $events = [];
}

// Debug: Log the events count
error_log("Total events fetched: " . count($events));

$js_events = [];
foreach ($events as $ev) {
    // Debug: Log the attorney information
    error_log("Employee Schedule Event: " . $ev['type'] . " - Attorney ID: " . ($ev['final_attorney_id'] ?? 'NULL') . " - Attorney Name: " . ($ev['attorney_name'] ?? 'NULL'));
    
    // Determine color based on attorney/admin assigned to the event
    $event_color = '#6c757d'; // Default gray for unknown
    
    // Get the attorney name for color coding
    $attorney_name = $ev['attorney_name'] ?? 'Unknown';
    
    // Assign specific colors to each attorney/admin
    if ($ev['attorney_user_type'] == 'admin') {
        // Admin - Light Maroon
        $event_color = '#c92a2a';
    } else {
        // Dynamic color assignment for attorneys based on creation order (attorney ID)
        $color_palette = [
            '#51cf66', // Light Green (1st attorney)
            '#74c0fc', // Light Blue (2nd attorney)
            '#ffd43b', // Light Orange (3rd attorney)
            '#da77f2', // Light Violet (4th attorney)
            '#ffa8a8', // Light Pink (5th attorney)
            '#69db7c', // Bright Green (6th attorney)
            '#4dabf7', // Bright Blue (7th attorney)
            '#ffd43b', // Bright Orange (8th attorney)
            '#e599f7', // Bright Violet (9th attorney)
            '#ffb3bf'  // Bright Pink (10th attorney)
        ];
        
        // Get attorney ID to determine creation order
        $attorney_id = $ev['final_attorney_id'] ?? 0;
        $color_index = ($attorney_id - 1) % count($color_palette); // -1 because IDs start at 1
        $event_color = $color_palette[$color_index];
    }
    
    $js_events[] = [
        'title' => $ev['type'] . ': ' . ($ev['case_title'] ?? ''),
        'start' => $ev['date'] . 'T' . $ev['time'],
        'type' => $ev['type'],
        'description' => $ev['description'],
        'location' => $ev['location'], // Full location for modal
        'location_display' => 'Cabuyao', // Short display for cards
        'case' => $ev['case_title'],
        'attorney' => $ev['attorney_name'],
        'attorney_user_type' => $ev['attorney_user_type'] ?? 'unknown',
        'client' => $ev['client_name'],
        'employee' => $ev['employee_name'],

        'extendedProps' => [
            'eventType' => $ev['type'],
            'attorneyName' => $attorney_name,
            'attorneyUserType' => $ev['attorney_user_type'] ?? 'unknown',
            'attorneyId' => $ev['final_attorney_id'] ?? 0
        ]
    ];
}

// Debug: Log the js_events count
error_log("Total js_events: " . count($js_events));
error_log("js_events data: " . print_r($js_events, true));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Management - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        /* Calendar container styles */
        .calendar-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            min-height: 600px;
        }
        
        #calendar {
            width: 100%;
            height: 100%;
            min-height: 500px;
        }
        
        /* FullCalendar custom styles */
        .fc {
            font-family: 'Poppins', sans-serif;
        }
        
        .fc-toolbar {
            margin-bottom: 20px;
        }
        
        .fc-button {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .fc-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .fc-button.active {
            background: var(--secondary-color);
        }
        
        .fc-daygrid-day {
            border: 1px solid #e9ecef;
        }
        
        .fc-event {
            border-radius: 6px;
            border: none;
            font-size: 12px;
            padding: 2px 6px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
                <div class="sidebar-header">
            <img src="images/logo.jpg" alt="Logo">
            <h2>Opiña Law Office</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="employee_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="employee_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="employee_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>
            <li><a href="employee_schedule.php" class="active"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>
            <li><a href="employee_clients.php"><i class="fas fa-users"></i><span>Client Management</span></a></li>
            <li><a href="employee_request_management.php"><i class="fas fa-clipboard-check"></i><span>Request Review</span></a></li>
            <li><a href="employee_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="employee_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>Schedule Management</h1>
                <p>Create and manage court hearings, meetings, and appointments</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Employee" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['name'] ?? 'Employee'; ?></h3>
                    <p>Employee</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary" id="addEventBtn">
                <i class="fas fa-plus"></i> Add Event
            </button>
            <div class="view-options">
                <button class="btn btn-secondary active" data-view="month">
                    <i class="fas fa-calendar"></i> Month
                </button>
                <button class="btn btn-secondary" data-view="week">
                    <i class="fas fa-calendar-week"></i> Week
                </button>
                <button class="btn btn-secondary" data-view="day">
                    <i class="fas fa-calendar-day"></i> Day
                </button>
            </div>
        </div>
        
        <!-- Calendar Container -->
        <div class="calendar-container">
            <div id="calendar"></div>
        </div>

        <!-- Enhanced Upcoming Events -->
        <div class="upcoming-events-section">
            <div class="section-header">
                <div class="header-content">
                    <div class="header-text">
                        <h2><i class="fas fa-calendar-check"></i> Upcoming Events</h2>
                        <p>Manage and monitor all scheduled activities</p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($events)): ?>
                <div class="no-events">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Upcoming Events</h3>
                    <p>No events are currently scheduled. Start by adding new events to your calendar.</p>
                </div>
            <?php else: ?>
                <?php
                // Group events by priority
                $admin_events = [];
                $attorney_events = [];
                $other_events = [];
                
                foreach ($events as $ev) {
                    if ($ev['attorney_user_type'] == 'admin') {
                        $admin_events[] = $ev;
                    } elseif ($ev['attorney_user_type'] == 'attorney') {
                        $attorney_events[] = $ev;
                    } else {
                        $other_events[] = $ev;
                    }
                }
                ?>
                
                <!-- Admin's Own Schedules -->
                <?php if (!empty($admin_events)): ?>
                <div class="priority-section admin-priority" style="margin-bottom: 3rem;">
                    <div class="priority-header">
                        <i class="fas fa-crown"></i>
                        <h3>Admin Schedules</h3>
                        <span class="priority-badge">Priority 1</span>
                    </div>
                    <div class="events-grid">
                        <?php foreach ($admin_events as $ev): ?>
                            <div class="event-card admin-event" data-event-id="<?= $ev['id'] ?>" data-event-type="<?= htmlspecialchars($ev['type']) ?>" data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? 'Admin') ?>">
                                <div class="event-card-header">
                                    <div class="event-avatar">
                                        <div class="avatar-placeholder">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                    </div>
                                    <div class="event-info">
                                        <h3><?= htmlspecialchars($ev['type']) ?></h3>
                                        <p class="case-detail"><i class="fas fa-folder"></i> <?= htmlspecialchars($ev['case_title'] ?? 'No Case') ?></p>
                                        <p class="attorney-detail"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($ev['attorney_name'] ?? 'No Attorney') ?></p>
                                        <p class="client-detail"><i class="fas fa-user"></i> <?= htmlspecialchars($ev['client_name'] ?? 'No Client') ?></p>
                                    </div>
                                </div>

                                <div class="event-actions">
                                    <div class="status-edit-section">
                                        <select class="status-select" data-event-id="<?= $ev['id'] ?>" onchange="updateEventStatus(this)" data-previous-status="<?= htmlspecialchars($ev['status']) ?>">
                                            <option value="Scheduled" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                                            <option value="Completed" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'completed') ? 'selected' : '' ?>>Completed</option>
                                            <option value="Cancelled" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                            <option value="Rescheduled" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'rescheduled') ? 'selected' : '' ?>>Rescheduled</option>
                                        </select>
                                        <button class="btn btn-warning btn-sm edit-event-btn" onclick="editEvent(this)" 
                                            data-event-id="<?= $ev['id'] ?>"
                                            data-type="<?= htmlspecialchars($ev['type']) ?>"
                                            data-date="<?= htmlspecialchars($ev['date']) ?>"
                                            data-time="<?= htmlspecialchars($ev['time']) ?>"
                                            data-location="<?= htmlspecialchars($ev['location'] ?? 'Cabuyao') ?>"
                                            data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                                            data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"
                                            data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"
                                            data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-info btn-sm view-info-btn" 
                                            data-event-id="<?= $ev['id'] ?>"
                                            data-type="<?= htmlspecialchars($ev['type']) ?>"
                                            data-date="<?= htmlspecialchars($ev['date']) ?>"
                                            data-time="<?= htmlspecialchars($ev['time']) ?>"
                                            data-location="<?= htmlspecialchars($ev['location'] ?? 'Cabuyao') ?>"
                                            data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                                            data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"
                                            data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"
                                            data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Other Attorneys' Schedules - Grouped by Attorney -->
                <?php if (!empty($attorney_events)): ?>
                <div class="priority-section attorney-priority" style="margin-top: 2rem;">
                    <div class="priority-header">
                        <i class="fas fa-user-tie"></i>
                        <h3>Attorney Schedules</h3>
                        <span class="priority-badge">Priority 2</span>
                    </div>
                    
                    <?php
                    // Group attorney events by attorney name
                    $attorney_groups = [];
                    foreach ($attorney_events as $ev) {
                        $attorney_name = $ev['attorney_name'] ?? 'Unknown Attorney';
                        if (!isset($attorney_groups[$attorney_name])) {
                            $attorney_groups[$attorney_name] = [];
                        }
                        $attorney_groups[$attorney_name][] = $ev;
                    }
                    
                    // Display each attorney's schedules separately
                    foreach ($attorney_groups as $attorney_name => $attorney_schedules):
                    ?>
                    <div class="attorney-schedule-group">
                        <div class="attorney-group-header">
                            <div class="attorney-info">
                                <i class="fas fa-user-tie"></i>
                                <h4><?= htmlspecialchars($attorney_name) ?></h4>
                                <span class="schedule-count"><?= count($attorney_schedules) ?> schedule<?= count($attorney_schedules) > 1 ? 's' : '' ?></span>
                            </div>
                        </div>
                        
                        <div class="events-grid">
                            <?php foreach ($attorney_schedules as $ev): ?>
                                <div class="event-card attorney-event" data-event-id="<?= $ev['id'] ?>" data-event-type="<?= htmlspecialchars($ev['type']) ?>" data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? 'Unknown Attorney') ?>">
                                    <div class="event-card-header">
                                        <div class="event-avatar">
                                            <div class="avatar-placeholder">
                                                <i class="fas fa-calendar-check"></i>
                                            </div>
                                        </div>
                                        <div class="event-info">
                                            <h3><?= htmlspecialchars($ev['type']) ?></h3>
                                            <p class="case-detail"><i class="fas fa-folder"></i> <?= htmlspecialchars($ev['case_title'] ?? 'No Case') ?></p>
                                            <p class="client-detail"><i class="fas fa-user"></i> <?= htmlspecialchars($ev['client_name'] ?? 'No Client') ?></p>
                                        </div>
                                    </div>

                                    <div class="event-actions">
                                        <div class="status-edit-section">
                                            <select class="status-select" data-event-id="<?= $ev['id'] ?>" onchange="updateEventStatus(this)" data-previous-status="<?= htmlspecialchars($ev['status']) ?>">
                                                <option value="Scheduled" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                                                <option value="Completed" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'completed') ? 'selected' : '' ?>>Completed</option>
                                                <option value="Cancelled" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                                <option value="Rescheduled" <?= (strtolower($ev['status'] ?? 'Scheduled') === 'rescheduled') ? 'selected' : '' ?>>Rescheduled</option>
                                            </select>
                                            <button class="btn btn-warning btn-sm edit-event-btn" onclick="editEvent(this)" 
                                                data-event-id="<?= $ev['id'] ?>"
                                                data-type="<?= htmlspecialchars($ev['type']) ?>"
                                                data-date="<?= htmlspecialchars($ev['date']) ?>"
                                                data-time="<?= htmlspecialchars($ev['time']) ?>"
                                                data-location="<?= htmlspecialchars($ev['location'] ?? 'Cabuyao') ?>"
                                                data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                                                data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"
                                                data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"
                                                data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-info btn-sm view-info-btn" 
                                                data-event-id="<?= $ev['id'] ?>"
                                                data-type="<?= htmlspecialchars($ev['type']) ?>"
                                                data-date="<?= htmlspecialchars($ev['date']) ?>"
                                                data-time="<?= htmlspecialchars($ev['time']) ?>"
                                                data-location="<?= htmlspecialchars($ev['location'] ?? 'Cabuyao') ?>"
                                                data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                                                data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"
                                                data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"
                                                data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                                                <i class="fas fa-eye"></i> View Details
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Event Modal -->
    <div class="modal" id="addEventModal">
        <div class="modal-content add-event-modal">
            <div class="modal-header">
                <h2>Add New Event</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="eventForm" class="event-form-grid">
                    <div class="form-group">
                        <label for="eventTitle">Event Title</label>
                        <input type="text" id="eventTitle" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="eventDate">Date</label>
                        <input type="date" id="eventDate" name="date" required min="<?= date('Y-m-d') ?>">
                        <small style="color: #666; font-style: italic;">Cannot schedule events in the past</small>
                    </div>
                    <div class="form-group">
                        <label for="eventTime">Time</label>
                        <input type="time" id="eventTime" name="time" required>
                    </div>
                    <div class="form-group">
                        <label for="eventLocation">Location</label>
                        <input type="text" id="eventLocation" name="location" value="Cabuyao" placeholder="Enter specific location (e.g., Brgy. Butong, City Hall)">
                        <small style="color: #666; font-style: italic;">Start with "Cabuyao" and add specific details (e.g., "Cabuyao - Brgy. Butong")</small>
                    </div>
                    <div class="form-group">
                        <label for="eventCase">Related Case (Optional)</label>
                        <select id="eventCase" name="case_id">
                            <option value="">Select Case (Optional)</option>
                            <?php foreach ($cases as $c): ?>
                            <option value="<?= $c['id'] ?>">#<?= $c['id'] ?> - <?= htmlspecialchars($c['title']) ?> (<?= htmlspecialchars($c['client_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="eventClient">Client (Required for appointments)</label>
                        <select id="eventClient" name="client_id" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="eventType">Event Type</label>
                        <select id="eventType" name="type" required>
                            <option value="Hearing">Hearing</option>
                            <option value="Appointment">Appointment</option>
                            <option value="Free Legal Advice">Free Legal Advice</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="selectedUserType">Select User Type</label>
                        <select id="selectedUserType" name="selected_user_type" required onchange="updateUserDropdown()">
                            <option value="">Choose User Type</option>
                            <option value="attorney">Attorney</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="selectedUserId">Select User</label>
                        <select id="selectedUserId" name="selected_user_id" required disabled>
                            <option value="">First select user type</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label for="eventDescription">Description</label>
                        <textarea id="eventDescription" name="description" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="cancelEvent">Cancel</button>
                <button class="btn btn-primary" id="saveEvent">Save Event</button>
            </div>
        </div>
    </div>

    <!-- Edit Event Modal -->
    <div class="modal" id="editEventModal">
        <div class="modal-content add-event-modal">
            <div class="modal-header">
                <h2>Edit Event</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editEventForm" class="event-form-grid">
                    <input type="hidden" id="editEventId" name="event_id">
                    <div class="form-group">
                        <label for="editEventTitle">Event Title</label>
                        <input type="text" id="editEventTitle" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="editEventType">Event Type</label>
                        <select id="editEventType" name="type" required>
                            <option value="Hearing">Hearing</option>
                            <option value="Appointment">Appointment</option>
                            <option value="Free Legal Advice">Free Legal Advice</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editEventDate">Date</label>
                        <input type="date" id="editEventDate" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="editEventTime">Time</label>
                        <input type="time" id="editEventTime" name="time" required>
                    </div>
                    <div class="form-group">
                        <label for="editEventLocation">Location</label>
                        <input type="text" id="editEventLocation" name="location" value="Cabuyao" required>
                    </div>

                    <div class="form-group">
                        <label for="editEventDescription">Description</label>
                        <textarea id="editEventDescription" name="description" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveEventChanges()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Event Details Modal -->
    <div class="modal" id="eventInfoModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="header-content">
                    <div class="header-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="header-text">
                        <h2>Event Details</h2>
                        <p>Complete event information and case details</p>
                    </div>
                </div>
            </div>
            <div class="modal-body">
                <div class="event-overview">
                    <div class="event-type-display">
                        <span class="type-badge" id="modalEventType">Event</span>
                    </div>
                    <div class="event-datetime">
                        <div class="date-display" id="modalEventDate">Date</div>
                        <div class="time-display" id="modalEventTime">Time</div>
                    </div>
                </div>
                <div class="event-details-grid">
                    <div class="detail-section">
                        <h3><i class="fas fa-info-circle"></i> Event Information</h3>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-tag"></i> Type:</span>
                            <span class="detail-value" id="modalType">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-calendar"></i> Date:</span>
                            <span class="detail-value" id="modalDate">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-clock"></i> Time:</span>
                            <span class="detail-value" id="modalTime">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Location:</span>
                            <span class="detail-value" id="modalLocation">-</span>
                        </div>
                    </div>
                    <div class="detail-section">
                        <h3><i class="fas fa-folder-open"></i> Case Details</h3>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-gavel"></i> Case:</span>
                            <span class="detail-value" id="modalCase">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-user-tie"></i> Attorney/Admin:</span>
                            <span class="detail-value" id="modalAttorney">-</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-user"></i> Client:</span>
                            <span class="detail-value" id="modalClient">-</span>
                        </div>

                        <div class="detail-item">
                            <span class="detail-label"><i class="fas fa-file-alt"></i> Description:</span>
                            <span class="detail-value" id="modalDescription">-</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-close-modal" id="closeEventInfoModal">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <style>
        .action-buttons {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .view-options {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #1976d2;
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            background: #1565c0;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .btn-secondary:hover, .btn-secondary.active {
            background: #1976d2;
            color: white;
            border-color: #1976d2;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .calendar-container {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            padding: 24px;
            margin-bottom: 24px;
        }

        /* Enhanced Upcoming Events Styles */
        .upcoming-events-section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-text {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .header-text p {
            margin: 0;
            color: #666;
            font-size: 1rem;
        }

        .section-header h2 {
            color: #1976d2;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-header h2 i {
            color: #9c27b0;
        }

        .no-events {
            text-align: center;
            padding: 3rem 2rem;
            color: #666;
        }

        .no-events i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
        }

        .no-events h3 {
            margin: 0 0 0.5rem 0;
            color: #999;
        }

        .no-events p {
            margin: 0;
            color: #bbb;
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            align-items: start;
        }

        .event-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .event-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .event-card-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .event-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .event-info h3 {
            margin: 0 0 0.5rem 0;
            color: #333;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .event-info p {
            margin: 0 0 0.25rem 0;
            color: #666;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .case-detail {
            color: #1976d2 !important;
            font-weight: 500;
        }

        .client-detail {
            color: #43a047 !important;
            font-weight: 500;
        }

        .attorney-detail {
            color: #9c27b0 !important;
            font-weight: 500;
        }

        .event-info i {
            font-size: 0.8rem;
            width: 16px;
            text-align: center;
        }

        /* Priority Sections */
        .priority-section {
            margin-bottom: 2rem;
        }

        .priority-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border-left: 4px solid;
        }

        .admin-priority .priority-header {
            border-left-color: #5d0e26;
            background: linear-gradient(135deg, rgba(93, 14, 38, 0.05) 0%, rgba(139, 21, 56, 0.1) 100%);
        }

        .attorney-priority .priority-header {
            border-left-color: #1976d2;
            background: linear-gradient(135deg, rgba(25, 118, 210, 0.05) 0%, rgba(25, 118, 210, 0.1) 100%);
        }

        .other-priority .priority-header {
            border-left-color: #6c757d;
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.05) 0%, rgba(108, 117, 125, 0.1) 100%);
        }

        .priority-header i {
            font-size: 1.5rem;
            color: #5d0e26;
        }

        .admin-priority .priority-header i {
            color: #5d0e26;
        }

        .attorney-priority .priority-header i {
            color: #1976d2;
        }

        .other-priority .priority-header i {
            color: #6c757d;
        }

        .priority-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
        }

        .priority-badge {
            background: #5d0e26;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: auto;
        }

        .admin-priority .priority-badge {
            background: #5d0e26;
        }

        .attorney-priority .priority-badge {
            background: #1976d2;
        }

        .other-priority .priority-badge {
            background: #6c757d;
        }

        /* Attorney Schedule Group Styling */
        .attorney-schedule-group {
            margin-bottom: 2rem;
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e9ecef;
        }

        .attorney-group-header {
            margin-bottom: 1.5rem;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, rgba(25, 118, 210, 0.08) 0%, rgba(25, 118, 210, 0.15) 100%);
            border-radius: 10px;
            border-left: 4px solid #1976d2;
        }

        .attorney-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .attorney-info i {
            font-size: 1.5rem;
            color: #1976d2;
        }

        .attorney-info h4 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            color: #1976d2;
        }

        .schedule-count {
            background: rgba(25, 118, 210, 0.1);
            color: #1976d2;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid rgba(25, 118, 210, 0.3);
            margin-left: auto;
        }

        /* Attorney-based Color Coding for Event Cards */
        .event-card {
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
            cursor: pointer;
        }

        /* Admin Event Cards - Light Red Background */
        .event-card.admin-event {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.08) 0%, rgba(255, 107, 107, 0.15) 100%);
            border-left: 4px solid #ff6b6b;
        }

        /* Attorney Event Cards - Light Green Background */
        .event-card.attorney-event {
            background: linear-gradient(135deg, rgba(81, 207, 102, 0.08) 0%, rgba(81, 207, 102, 0.15) 100%);
            border-left: 4px solid #51cf66;
        }

        /* Other Event Cards - Light Blue Background */
        .event-card.other-event {
            background: linear-gradient(135deg, rgba(116, 192, 252, 0.08) 0%, rgba(116, 192, 252, 0.15) 100%);
            border-left: 4px solid #74c0fc;
        }

        /* Attorney-based avatar colors */
        .avatar-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        /* Admin avatar - Light Red */
        .event-card.admin-event .avatar-placeholder {
            background: linear-gradient(135deg, #ff6b6b 0%, #ff5252 100%);
        }

        /* Attorney avatars - Light Green */
        .event-card.attorney-event .avatar-placeholder {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        }

        /* Other event avatars - Light Blue */
        .event-card.other-event .avatar-placeholder {
            background: linear-gradient(135deg, #74c0fc 0%, #4dabf7 100%);
        }

        /* Specific User Color Coding */
        .event-card[data-attorney="Laica Castillo Refrea"] {
            background: linear-gradient(135deg, rgba(116, 192, 252, 0.08) 0%, rgba(116, 192, 252, 0.15) 100%) !important;
            border-left: 4px solid #74c0fc !important;
        }

        .event-card[data-attorney="Laica Castillo Refrea"] .avatar-placeholder {
            background: linear-gradient(135deg, #74c0fc 0%, #4dabf7 100%) !important;
        }

        .event-card[data-attorney="Mario Delmo Refrea"] {
            background: linear-gradient(135deg, rgba(255, 212, 59, 0.08) 0%, rgba(255, 212, 59, 0.15) 100%) !important;
            border-left: 4px solid #ffd43b !important;
        }

        .event-card[data-attorney="Mario Delmo Refrea"] .avatar-placeholder {
            background: linear-gradient(135deg, #ffd43b 0%, #fcc419 100%) !important;
        }

        .event-card[data-attorney="Mar John Refrea"] {
            background: linear-gradient(135deg, rgba(201, 42, 42, 0.08) 0%, rgba(201, 42, 42, 0.15) 100%) !important;
            border-left: 4px solid #c92a2a !important;
        }

        .event-card[data-attorney="Mar John Refrea"] .avatar-placeholder {
            background: linear-gradient(135deg, #c92a2a 0%, #a61e4d 100%) !important;
        }

        .event-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            border-color: #5d0e26;
        }

        /* Attorney-based color coding for event info text */
        .event-info h3 {
            margin: 0 0 0.2rem 0;
            color: #5d0e26;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .status-edit-section {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .status-select {
            padding: 0.3rem 0.6rem;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
            background-color: #fff;
            font-size: 0.75rem;
            color: #495057;
            cursor: pointer;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .status-select:focus {
            border-color: #5d0e26;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(93, 14, 38, 0.25);
        }

        .status-select option[value="Scheduled"] {
            color: #1976d2;
        }

        .status-select option[value="Completed"] {
            color: #4caf50;
        }

        .status-select option[value="Cancelled"] {
            color: #f44336;
        }

        .status-select option[value="Rescheduled"] {
            color: #ff9800;
        }

        .edit-event-btn {
            background: #ffc107;
            border: 1px solid #ffc107;
            color: #212529;
            font-weight: 500;
        }

        .edit-event-btn:hover {
            background: #e0a800;
            border-color: #d39e00;
            color: #212529;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }



        .event-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .status-management {
            flex: 1;
        }

        .status-select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            background: white;
            color: #333;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .status-select:focus {
            outline: none;
            border-color: #1976d2;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        /* Status-based Card Styling */
        .event-card.status-completed {
            border-left: 4px solid #2e7d32;
        }



        .event-card.status-rescheduled {
            border-left: 4px solid #f57c00;
        }

        .event-card.status-cancelled {
            border-left: 4px solid #6c757d;
        }

        /* Professional Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            border-radius: 8px;
            padding: 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            max-width: 800px;
            width: 90%;
            margin: 2% auto;
            overflow: hidden;
            max-height: 90vh;
        }

        .add-event-modal {
            max-width: 700px !important;
            max-height: 90vh !important;
            margin: 1.5% auto !important;
            width: 95% !important;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: #1976d2;
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 8px 8px 0 0;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-icon {
            width: 35px;
            height: 35px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .header-text h2 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .header-text p {
            margin: 0.25rem 0 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }

        .close-modal {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 1rem;
        }

        .event-overview {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e0e0e0;
        }

        .event-type-display .type-badge {
            background: #9c27b0;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .event-datetime {
            display: flex;
            gap: 0.75rem;
        }

        .date-display, .time-display {
            background: white;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            text-align: center;
            min-width: 70px;
            border: 1px solid #e0e0e0;
            font-size: 0.9rem;
        }

        .date-display {
            color: #1976d2;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .time-display {
            color: #43a047;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .event-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .detail-section {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 1.2rem;
            border: 1px solid #e9ecef;
        }

        .detail-section h3 {
            color: #1976d2;
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 0.75rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e3f2fd;
        }

        .detail-section h3 i {
            color: #9c27b0;
            font-size: 1.2rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #555;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 100px;
            font-size: 0.85rem;
        }

        .detail-label i {
            color: #9c27b0;
            width: 18px;
            text-align: center;
            font-size: 1rem;
        }

        .detail-value {
            color: #333;
            font-weight: 600;
            text-align: right;
            max-width: 250px;
            word-wrap: break-word;
            font-size: 0.85rem;
            padding-left: 0.5rem;
            line-height: 1.3;
        }

        .modal-footer {
            padding: 1rem;
            background: #f8f9fa;
            text-align: right;
            border-top: 1px solid #e0e0e0;
            border-radius: 0 0 8px 8px;
            position: sticky;
            bottom: 0;
            z-index: 100;
        }

        .btn-close-modal {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-close-modal:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        /* Add Event Modal Specific Styles */
        .add-event-modal .modal-body {
            padding: 1rem;
            overflow-y: auto;
            flex: 1;
            max-height: 60vh;
        }

        .add-event-modal .event-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem;
        }

        .add-event-modal .form-group {
            margin-bottom: 0.6rem;
        }

        .add-event-modal .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .add-event-modal .form-group:nth-child(1) {
            grid-column: 1 / -1;
        }

        .add-event-modal .form-group:nth-child(1) label {
            color: #9c27b0;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .add-event-modal .form-group:nth-child(1) input {
            border-color: #9c27b0;
            background: linear-gradient(135deg, #fafbfc, #f3f4f6);
        }

        .add-event-modal .form-group:nth-child(1) input:focus {
            border-color: #9c27b0;
            box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
        }

        .add-event-modal .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            color: #1976d2;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1px;
        }

        .add-event-modal .form-group input,
        .add-event-modal .form-group select,
        .add-event-modal .form-group textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            font-size: 0.8rem;
            background: #ffffff;
            transition: all 0.3s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
        }

        .add-event-modal .form-group input:focus,
        .add-event-modal .form-group select:focus,
        .add-event-modal .form-group textarea:focus {
            outline: none;
            border-color: #1976d2;
            background: white;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
            transform: translateY(-1px);
        }

        .add-event-modal .form-group input:hover,
        .add-event-modal .form-group select:hover,
        .add-event-modal .form-group textarea:hover {
            border-color: #1976d2;
            background: white;
        }

        .add-event-modal .modal-footer {
            padding: 1.5rem;
            background: #f8f9fa;
            border-top: 2px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            border-radius: 0 0 8px 8px;
            position: sticky;
            bottom: 0;
            z-index: 1000;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        }

        .add-event-modal .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
            min-width: 100px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .add-event-modal .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }

        .add-event-modal .btn-primary {
            background: linear-gradient(135deg, #1976d2, #1565c0);
            color: white;
            border: 2px solid #1976d2;
            font-weight: 700;
        }
        
        .add-event-modal .btn-primary:hover {
            background: linear-gradient(135deg, #1565c0, #0d47a1);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(25, 118, 210, 0.3);
        }

        .add-event-modal .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .add-event-modal .btn:active {
            transform: translateY(0);
        }



        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            border-left: 4px solid;
            z-index: 10000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification-success {
            border-left-color: #2e7d32;
            color: #2e7d32;
        }

        .notification-error {
            border-left-color: #d32f2f;
            color: #d32f2f;
        }

        .notification i {
            font-size: 1.2rem;
        }

        #calendar {
            height: 600px;
        }

        .fc-event {
            cursor: pointer;
        }

        .fc-event-title {
            font-weight: 500;
        }

        .fc-event-time {
            font-size: 0.8em;
        }

        @media (max-width: 700px) {
            .modal-content { 
                max-width: 98vw; 
                padding: 10px 4vw; 
            }
            .event-form-grid { 
                grid-template-columns: 1fr; 
                gap: 12px; 
            }
            .event-details-grid {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="assets/js/attorney-colors.js"></script>
    <script>
        // Store attorneys and admins data for JavaScript
        var attorneysAndAdmins = <?php echo json_encode($attorneys_and_admins); ?>;
        
        // Function to update user dropdown based on selected user type
        function updateUserDropdown() {
            const userTypeSelect = document.getElementById('selectedUserType');
            const userIdSelect = document.getElementById('selectedUserId');
            const selectedType = userTypeSelect.value;
            
            // Clear current options
            userIdSelect.innerHTML = '<option value="">Select ' + (selectedType === 'attorney' ? 'Attorney' : 'Admin') + '</option>';
            
            if (selectedType) {
                // Filter users by selected type
                const filteredUsers = attorneysAndAdmins.filter(user => user.user_type === selectedType);
                
                // Add options for filtered users
                filteredUsers.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = user.name + ' (' + user.user_type.charAt(0).toUpperCase() + user.user_type.slice(1) + ')';
                    userIdSelect.appendChild(option);
                });
                
                // Enable the dropdown
                userIdSelect.disabled = false;
            } else {
                // Disable and reset dropdown
                userIdSelect.disabled = true;
                userIdSelect.innerHTML = '<option value="">First select user type</option>';
            }
        }

        // Enhanced confirmation with typing requirement
        function showTypingConfirmation(message, status) {
            // Create modal overlay
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(5px);
            `;
            
            // Create modal content
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                border-radius: 12px;
                padding: 2rem;
                max-width: 500px;
                width: 90%;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
                text-align: center;
                border: 3px solid #e74c3c;
            `;
            
            // Get confirmation text based on status
            let confirmText = '';
            let inputPlaceholder = '';
            
            switch(status.toLowerCase()) {
                case 'completed':
                    confirmText = 'COMPLETED';
                    inputPlaceholder = 'Type "COMPLETED" to confirm';
                    break;
                case 'rescheduled':
                    confirmText = 'RESCHEDULED';
                    inputPlaceholder = 'Type "RESCHEDULED" to confirm';
                    break;
                case 'cancelled':
                    confirmText = 'CANCELLED';
                    inputPlaceholder = 'Type "CANCELLED" to confirm';
                    break;
                case 'edit':
                    confirmText = 'EDIT';
                    inputPlaceholder = 'Type "EDIT" to confirm';
                    break;
                default:
                    confirmText = 'CONFIRM';
                    inputPlaceholder = 'Type "CONFIRM" to proceed';
            }
            
            modalContent.innerHTML = `
                <div style="margin-bottom: 1.5rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
                    <h3 style="color: #e74c3c; margin: 0 0 1rem 0; font-size: 1.3rem;">SECURITY CONFIRMATION REQUIRED</h3>
                    <p style="color: #666; margin: 0; line-height: 1.5; white-space: pre-line;">${message}</p>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">
                        To proceed, type: <strong style="color: #e74c3c;">${confirmText}</strong>
                    </label>
                    <input type="text" id="confirmationInput" placeholder="${inputPlaceholder}" 
                           style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 1rem; text-align: center; font-weight: 600; letter-spacing: 1px;">
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: center;">
                    <button id="cancelBtn" style="padding: 12px 24px; background: #6c757d; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                        ❌ Cancel
                    </button>
                    <button id="confirmBtn" disabled style="padding: 12px 24px; background: #e74c3c; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: not-allowed; opacity: 0.5; transition: all 0.3s;">
                        ✅ Confirm ${status.toUpperCase()}
                    </button>
                </div>
            `;
            
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            // Focus on input
            const input = modalContent.querySelector('#confirmationInput');
            const confirmBtn = modalContent.querySelector('#confirmBtn');
            const cancelBtn = modalContent.querySelector('#cancelBtn');
            
            input.focus();
            
            // Handle input validation
            input.addEventListener('input', function() {
                const typedValue = this.value.trim().toUpperCase();
                const isValid = typedValue === confirmText;
                
                if (isValid) {
                    confirmBtn.disabled = false;
                    confirmBtn.style.background = '#27ae60';
                    confirmBtn.style.cursor = 'pointer';
                    confirmBtn.style.opacity = '1';
                    this.style.borderColor = '#27ae60';
                    this.style.backgroundColor = '#f8fff8';
                } else {
                    confirmBtn.disabled = true;
                    confirmBtn.style.background = '#e74c3c';
                    confirmBtn.style.cursor = 'not-allowed';
                    confirmBtn.style.opacity = '0.5';
                    this.style.borderColor = '#e74c3c';
                    this.style.backgroundColor = '#fff5f5';
                }
            });
            
            // Handle Enter key
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !confirmBtn.disabled) {
                    confirmBtn.click();
                }
            });
            
            // Return promise
            return new Promise((resolve) => {
                confirmBtn.addEventListener('click', function() {
                    if (!this.disabled) {
                        document.body.removeChild(modal);
                        resolve(true);
                    }
                });
                
                cancelBtn.addEventListener('click', function() {
                    document.body.removeChild(modal);
                    resolve(false);
                });
                
                // Close on outside click
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        document.body.removeChild(modal);
                        resolve(false);
                    }
                });
            });
        }

        // Global function for updating event status
        async function updateEventStatus(selectElement) {
            const newStatus = selectElement.value;
            const previousStatus = selectElement.dataset.previousStatus;
            const eventCard = selectElement.closest('.event-card');
            
            // Don't show confirmation if status didn't change
            if (newStatus === previousStatus) {
                return;
            }
            
            // Show enhanced confirmation with warnings based on status
            let confirmMessage = '';
            let warningIcon = '';
            
            switch(newStatus.toLowerCase()) {
                case 'completed':
                    confirmMessage = `⚠️ WARNING: Mark this event as COMPLETED?\n\nThis action will:\n• Mark the event as finished\n• Update the event history\n• Cannot be easily undone\n\nAre you sure you want to proceed?`;
                    warningIcon = '✅';
                    break;

                case 'rescheduled':
                    confirmMessage = `🔄 WARNING: Mark this event as RESCHEDULED?\n\nThis action will:\n• Indicate event was postponed\n• Requires new date/time setup\n• May affect other schedules\n\nAre you sure you want to proceed?`;
                    warningIcon = '📅';
                    break;
                case 'cancelled':
                    confirmMessage = `🚫 WARNING: CANCEL this event?\n\nThis action will:\n• Permanently cancel the event\n• May affect case progress\n• Requires immediate rescheduling\n\nAre you sure you want to proceed?`;
                    warningIcon = '⏹️';
                    break;
                default:
                    confirmMessage = `ℹ️ Update event status to "${newStatus.toUpperCase()}"?\n\nThis will change the event status in the system.`;
                    warningIcon = 'ℹ️';
            }
            
            // Show enhanced confirmation with typing requirement
            const confirmed = await showTypingConfirmation(confirmMessage, newStatus);
            if (!confirmed) {
                selectElement.value = previousStatus;
                return;
            }
            
            // Get event ID from the card
            const eventId = eventCard.dataset.eventId || '1';
            
            // Show processing notification
            showNotification(`Updating event status to ${newStatus.toUpperCase()}...`, 'info');
            
            // Send AJAX request to update status
            fetch('update_event_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `event_id=${eventId}&new_status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(`✅ Event status successfully updated to ${newStatus.toUpperCase()}!`, 'success');
                    updateEventCardUI(selectElement, newStatus);
                    selectElement.dataset.previousStatus = newStatus;
                    
                    // Show additional warning for critical statuses
                    if (newStatus === 'cancelled') {
                        setTimeout(() => {
                            showNotification('⚠️ Remember to reschedule this cancelled event!', 'warning');
                        }, 2000);
                    }
                } else {
                    showNotification(`❌ Failed to update event status: ${data.message || 'Unknown error'}`, 'error');
                    selectElement.value = previousStatus;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Error updating event status. Please try again.', 'error');
                selectElement.value = previousStatus;
            });
        }

        // Global function for showing notifications
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => notification.classList.add('show'), 100);
            
            // Hide and remove notification
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Function to update event card UI based on status
        function updateEventCardUI(selectElement, newStatus) {
            const eventCard = selectElement.closest('.event-card');
            
            // Remove previous status classes
            eventCard.classList.remove('status-scheduled', 'status-completed', 'status-rescheduled', 'status-cancelled');
            
            // Add new status class
            eventCard.classList.add(`status-${newStatus}`);
            
            // Update border color based on status
            const borderColors = {
                'scheduled': '#1976d2',
                'completed': '#2e7d32',
                'rescheduled': '#f57c00',
                'cancelled': '#6c757d'
            };
            
            eventCard.style.borderLeftColor = borderColors[newStatus] || '#1976d2';
        }

        // Wait for FullCalendar to be available
        function waitForFullCalendar() {
            console.log('waitForFullCalendar called');
            if (typeof FullCalendar !== 'undefined') {
                console.log('FullCalendar is available, creating calendar...');
                var calendarEl = document.getElementById('calendar');
                console.log('Calendar element:', calendarEl);
                console.log('Events data:', <?= json_encode($js_events) ?>);
                
                // Ensure we have events data, even if empty
                const eventsData = <?= json_encode($js_events) ?> || [];
                console.log('Events data length:', eventsData.length);
                
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: eventsData,
                    eventClick: function(info) {
                        showEventDetails(info.event);
                    },
                    eventColor: null,
                    height: 'auto',
                    eventDidMount: function(info) {
                        // Apply attorney-based color coding using the helper function
                        if (info.event.extendedProps.attorneyName) {
                            const attorneyName = info.event.extendedProps.attorneyName;
                            const userType = info.event.extendedProps.attorneyUserType || 'attorney';
                            
                            console.log('Event:', info.event.title, 'Attorney:', attorneyName, 'Type:', userType);
                            
                            // Use the centralized color system
                            applyAttorneyColors(info.el, attorneyName, userType);
                        }
                    }
                });
                console.log('Calendar created, rendering...');
                calendar.render();
                console.log('Calendar rendered successfully');
                
                // Show message if no events
                if (eventsData.length === 0) {
                    console.log('No events to display');
                    // Add a message to the calendar
                    calendarEl.innerHTML += '<div style="text-align: center; padding: 40px; color: #666; font-size: 16px;"><i class="fas fa-calendar-times"></i><br>No events scheduled yet.<br><small>Click "Add Event" to create your first event.</small></div>';
                }
                
                // Initialize calendar functionality
                initializeCalendarFunctions(calendar);
                
                // Real-time status synchronization
                function refreshEventStatuses() {
                    // Refresh the page to get updated statuses
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }

                // Set up real-time synchronization
                function setupRealTimeSync() {
                    // Refresh statuses every 30 seconds
                    setInterval(refreshEventStatuses, 30000);
                    
                    // Also refresh when page becomes visible
                    document.addEventListener('visibilitychange', function() {
                        if (!document.hidden) {
                            refreshEventStatuses();
                        }
                    });
                }

                // Initialize real-time sync
                setupRealTimeSync();
            } else {
                console.error('FullCalendar is not available');
            }
        }
        
        // Initialize calendar functions
        function initializeCalendarFunctions(calendar) {
            // View buttons functionality
            document.querySelectorAll('.view-options .btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.view-options .btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.dataset.view;
                    if (view === 'month') {
                        calendar.changeView('dayGridMonth');
                    } else if (view === 'week') {
                        calendar.changeView('timeGridWeek');
                    } else if (view === 'day') {
                        calendar.changeView('timeGridDay');
                    }
                });
            });

            // Modal functionality
            const addEventModal = document.getElementById('addEventModal');
            const addEventBtn = document.getElementById('addEventBtn');
            const closeModal = document.querySelector('.close-modal');
            const cancelEvent = document.getElementById('cancelEvent');

            addEventBtn.onclick = function() {
                addEventModal.style.display = "block";
                addEventModal.style.visibility = "visible";
                addEventModal.style.opacity = "1";
                
                // Set minimum date to today
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('eventDate').min = today;
                document.getElementById('eventDate').value = today;
                
                // Suggest Cabuyao as starting point for location
                const locationField = document.getElementById('eventLocation');
                if (!locationField.value.trim()) {
                    locationField.value = 'Cabuyao';
                }
            }

            closeModal.onclick = function() {
                addEventModal.style.display = "none";
            }

            cancelEvent.onclick = function() {
                addEventModal.style.display = "none";
            }

            // Close modal when clicking outside - REMOVED to prevent accidental closing
            // window.onclick = function(event) {
            //     if (event.target == addEventModal) {
            //         addEventModal.style.display = "none";
            //     }
            //     if (event.target == document.getElementById('eventInfoModal')) {
            //         document.getElementById('eventInfoModal').style.display = "none";
            //     }
            //     if (event.target == document.getElementById('editEventModal')) {
            //         document.getElementById('editEventModal').style.display = "none";
            //     }
            // }

            // Add AJAX for saving event
            document.getElementById('saveEvent').onclick = function() {
                console.log('Save Event button clicked!'); // Debug log
                
                // Form validation
                const caseSelect = document.getElementById('eventCase');
                const clientSelect = document.getElementById('eventClient');
                const eventType = document.getElementById('eventType').value;
                const eventDate = document.getElementById('eventDate').value;
                const eventTime = document.getElementById('eventTime').value;
                
                console.log('Form values:', {
                    case: caseSelect.value,
                    client: clientSelect.value,
                    type: eventType,
                    date: eventDate,
                    time: eventTime
                }); // Debug log
                
                // Check if date is in the past
                const selectedDateTime = new Date(eventDate + 'T' + eventTime);
                const now = new Date();
                
                if (selectedDateTime < now) {
                    alert('❌ Cannot schedule events in the past. Please select a future date and time.');
                    return;
                }
                
                // Client is always required for appointments and free legal advice
                if (!clientSelect.value) {
                    alert('Please select a client for this event.');
                    return;
                }
                
                // Ensure location starts with Cabuyao if empty
                const locationField = document.getElementById('eventLocation');
                if (!locationField.value.trim()) {
                    locationField.value = 'Cabuyao';
                }
                
                // If no case is selected, that's fine - it can be a standalone appointment
                const fd = new FormData(document.getElementById('eventForm'));
                fd.append('action', 'add_event');
                
                console.log('Sending form data...'); // Debug log
                
                fetch('employee_schedule.php', { method: 'POST', body: fd })
                    .then(r => r.text()).then(res => {
                        console.log('Response:', res); // Debug log
                        if (res === 'success') {
                            showNotification('✅ Event successfully created!', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification('❌ Error saving event. Please try again.', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error); // Debug log
                        showNotification('❌ Error saving event. Please try again.', 'error');
                    });
            };

            // Initialize event handlers
            initializeEventHandlers();
            
            // Debug: Check if Save Event button exists
            const saveBtn = document.getElementById('saveEvent');
            if (saveBtn) {
                console.log('✅ Save Event button found and initialized');
            } else {
                console.error('❌ Save Event button not found!');
            }
        }
        
        function showEventDetails(event) {
            document.getElementById('modalEventType').innerText = event.title.split(':')[0] || 'Event';
            document.getElementById('modalEventDate').innerText = event.start.toLocaleDateString();
            document.getElementById('modalEventTime').innerText = event.start.toLocaleTimeString();
            document.getElementById('modalType').innerText = event.extendedProps.type || '-';
            document.getElementById('modalDate').innerText = event.start.toLocaleDateString();
            document.getElementById('modalTime').innerText = event.start.toLocaleTimeString();
            document.getElementById('modalLocation').innerText = event.extendedProps.location || '-';
            document.getElementById('modalCase').innerText = event.extendedProps.case || '-';
            document.getElementById('modalAttorney').innerText = event.extendedProps.attorney || '-';
            document.getElementById('modalClient').innerText = event.extendedProps.client || '-';

            document.getElementById('modalDescription').innerText = event.extendedProps.description || '-';
            
            document.getElementById('eventInfoModal').style.display = 'block';
        }
        
        // Function to edit event
        function editEvent(button) {
            const eventId = button.dataset.eventId;
            const eventType = button.dataset.type;
            const eventDate = button.dataset.date;
            const eventTime = button.dataset.time;
            const eventLocation = button.dataset.location;
            const eventCase = button.dataset.case;
            const eventAttorney = button.dataset.attorney;
            const eventClient = button.dataset.client;
            const eventDescription = button.dataset.description;

            // Populate the edit modal
            document.getElementById('editEventId').value = eventId;
            document.getElementById('editEventTitle').value = eventType;
            document.getElementById('editEventType').value = eventType;
            document.getElementById('editEventDate').value = eventDate;
            document.getElementById('editEventTime').value = eventTime;
            document.getElementById('editEventLocation').value = eventLocation;
            document.getElementById('editEventDescription').value = eventDescription;

            // Show the edit modal
            document.getElementById('editEventModal').style.display = 'block';
        }

        // Function to close edit modal
        function closeEditModal() {
            document.getElementById('editEventModal').style.display = 'none';
        }

        // Function to save event changes
        async function saveEventChanges() {
            // Show enhanced confirmation with typing requirement
            const confirmMessage = `⚠️ WARNING: Save changes to this event?\n\nThis action will:\n• Update the event details\n• Modify the schedule\n• Cannot be easily undone\n\nAre you sure you want to proceed?`;
            
            const confirmed = await showTypingConfirmation(confirmMessage, 'EDIT');
            if (!confirmed) {
                return;
            }
            
            const form = document.getElementById('editEventForm');
            const formData = new FormData(form);
            formData.append('action', 'edit_event');

            // Show loading state
            const saveBtn = document.querySelector('#editEventModal .btn-primary');
            const originalText = saveBtn.textContent;
            saveBtn.textContent = 'Saving...';
            saveBtn.disabled = true;

            fetch('employee_schedule.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(result => {
                if (result === 'success') {
                    showNotification('✅ Event updated successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('❌ Error updating event. Please try again.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Error updating event. Please try again.', 'error');
            })
            .finally(() => {
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
            });
        }

        // Initialize all event handlers
        function initializeEventHandlers() {
            // Initialize status selects
            document.querySelectorAll('.status-select').forEach(select => {
                select.dataset.previousStatus = select.value;
            });
            
            // Initialize view details buttons
            document.querySelectorAll('.view-info-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Populate modal with event data
                    document.getElementById('modalEventType').innerText = this.dataset.type || 'Event';
                    document.getElementById('modalEventDate').innerText = this.dataset.date || 'Date';
                    document.getElementById('modalEventTime').innerText = this.dataset.time ? new Date('1970-01-01T' + this.dataset.time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Time';
                    document.getElementById('modalType').innerText = this.dataset.type || '-';
                    document.getElementById('modalDate').innerText = this.dataset.date || '-';
                    document.getElementById('modalTime').innerText = this.dataset.time ? new Date('1970-01-01T' + this.dataset.time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-';
                    document.getElementById('modalLocation').innerText = this.dataset.location || '-';
                    document.getElementById('modalCase').innerText = this.dataset.case || '-';
                    document.getElementById('modalAttorney').innerText = this.dataset.attorney || '-';
                    document.getElementById('modalClient').innerText = this.dataset.client || '-';

                    document.getElementById('modalDescription').innerText = this.dataset.description || '-';
                    
                    // Show modal
                    document.getElementById('eventInfoModal').style.display = "block";
                });
            });
            
            // Initialize modal close functionality
            document.getElementById('closeEventInfoModal').addEventListener('click', function() {
                document.getElementById('eventInfoModal').style.display = "none";
            });
        }
        
        // Wait for DOM to be ready, then initialize FullCalendar
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM Content Loaded');
            console.log('FullCalendar available:', typeof FullCalendar !== 'undefined');
            
            // Test if calendar element exists
            const calendarEl = document.getElementById('calendar');
            console.log('Calendar element found:', calendarEl);
            
            if (calendarEl) {
                console.log('Calendar element dimensions:', calendarEl.offsetWidth, 'x', calendarEl.offsetHeight);
                console.log('Calendar element styles:', window.getComputedStyle(calendarEl));
            }
            
            // Check if FullCalendar is loaded
            if (typeof FullCalendar !== 'undefined') {
                console.log('Calling waitForFullCalendar...');
                waitForFullCalendar();
            } else {
                console.log('FullCalendar not loaded yet, waiting...');
                // If not loaded yet, wait a bit and try again
                setTimeout(function() {
                    console.log('Checking FullCalendar again...');
                    if (typeof FullCalendar !== 'undefined') {
                        console.log('FullCalendar now available, calling waitForFullCalendar...');
                        waitForFullCalendar();
                    } else {
                        console.error('FullCalendar failed to load');
                    }
                }, 1000);
            }
        });
    </script>
</body>
</html> 