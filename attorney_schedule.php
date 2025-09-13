<?php
require_once 'session_manager.php';
validateUserAccess('attorney');
require_once 'config.php';
$attorney_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
}
if (!$profile_image || !file_exists($profile_image)) {
    // Fallback to an existing bundled image to avoid 404
    $profile_image = 'images/default-avatar.jpg';
}

// Fetch all cases for this attorney
$cases = [];
$stmt = $conn->prepare("SELECT ac.id, ac.title, uf.name as client_name FROM attorney_cases ac LEFT JOIN user_form uf ON ac.client_id = uf.id WHERE ac.attorney_id=? ORDER BY ac.id DESC");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $cases[] = $row;

// Fetch all clients for this attorney
$clients = [];
$stmt = $conn->prepare("SELECT DISTINCT uf.id, uf.name, uf.email FROM user_form uf 
    INNER JOIN attorney_cases ac ON uf.id = ac.client_id 
    WHERE ac.attorney_id = ? AND uf.user_type = 'client'
    ORDER BY uf.name");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clients[] = $row;

// Fetch all registered clients (for free legal advice sessions)
$all_clients = [];
$stmt = $conn->prepare("SELECT id, name, email FROM user_form WHERE user_type = 'client' ORDER BY name");
$stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) $all_clients[] = $row;

  // Handle edit event
  if (isset($_POST['action']) && $_POST['action'] === 'edit_event') {
      $event_id = intval($_POST['event_id']);
      $title = $_POST['title'];
      $type = $_POST['type'];
      $date = $_POST['date'];
      $time = $_POST['time'];
      $location = $_POST['location'];
      $description = $_POST['description'];

      // Update the event
      $stmt = $conn->prepare("UPDATE case_schedules SET type=?, title=?, description=?, date=?, time=?, location=? WHERE id=? AND attorney_id=?");
      $stmt->bind_param('ssssssii', $type, $title, $description, $date, $time, $location, $event_id, $attorney_id);
      $stmt->execute();
      echo $stmt->affected_rows > 0 ? 'success' : 'error';
      exit();
  }

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
    
    // If case_id is provided, get client_id from case
    if ($case_id) {
        $stmt = $conn->prepare("SELECT client_id FROM attorney_cases WHERE id=? AND attorney_id=?");
        $stmt->bind_param("ii", $case_id, $attorney_id);
        $stmt->execute();
        $q = $stmt->get_result();
        if ($r = $q->fetch_assoc()) {
            $client_id = $r['client_id'];
        }
    }
    
    // For free legal advice, allow any client
    if ($type === 'Free Legal Advice') {
        // Client can be any registered client
        if (!$client_id) {
            echo 'error: Client is required for free legal advice sessions';
            exit;
        }
    } else {
        // For other types, verify client belongs to this attorney if no case
        if ($client_id && !$case_id) {
            $stmt = $conn->prepare("SELECT 1 FROM attorney_cases WHERE client_id=? AND attorney_id=? LIMIT 1");
            $stmt->bind_param("ii", $client_id, $attorney_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo 'error: Unauthorized client access';
                exit;
            }
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO case_schedules (case_id, attorney_id, client_id, type, title, description, date, time, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiissssss', $case_id, $attorney_id, $client_id, $type, $title, $description, $date, $time, $location);
    $stmt->execute();
    echo $stmt->affected_rows > 0 ? 'success' : 'error';
    exit();
}
// Fetch all events for this attorney
$events = [];
$stmt = $conn->prepare("SELECT cs.*, ac.title as case_title, uf.name as client_name, uf_attorney.name as attorney_name FROM case_schedules cs LEFT JOIN attorney_cases ac ON cs.case_id = ac.id LEFT JOIN user_form uf ON cs.client_id = uf.id LEFT JOIN user_form uf_attorney ON cs.attorney_id = uf_attorney.id WHERE cs.attorney_id=? ORDER BY cs.date, cs.time");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $events[] = $row;

// Debug: Log session information
error_log("Session attorney_name: " . ($_SESSION['attorney_name'] ?? 'NULL'));
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'NULL'));

// Generate JavaScript events for FullCalendar
$js_events = [];
foreach ($events as $ev) {
    // Debug: Log attorney information
    error_log("Attorney Schedule Event: " . $ev['type'] . " - Attorney ID: " . ($ev['attorney_id'] ?? 'NULL') . " - Attorney Name: " . ($ev['attorney_name'] ?? 'NULL'));
    
    $js_events[] = [
        'title' => $ev['type'] . ': ' . ($ev['case_title'] ?? ''),
        'start' => $ev['date'] . 'T' . $ev['time'],
        'type' => $ev['type'],
        'description' => $ev['description'],
        'location' => $ev['location'],
        'case' => $ev['case_title'],
        'attorney' => $ev['attorney_name'],
        'client' => $ev['client_name'],
        'extendedProps' => [
            'eventType' => $ev['type'],
            'attorneyName' => $ev['attorney_name'] ?? $_SESSION['attorney_name'] ?? 'Attorney',
            'attorneyId' => $ev['attorney_id'] ?? 0,
            'case' => $ev['case_title'],
            'client' => $ev['client_name'],
            'description' => $ev['description']
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Management - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="assets/js/attorney-colors.js"></script>
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
        
        /* Fix spacing between FullCalendar toolbar buttons */
        .fc-toolbar-chunk {
            display: flex;
            gap: 8px !important;
        }
        
        .fc-toolbar-chunk .fc-button {
            margin: 0 4px !important;
        }
        
        /* Action Buttons Layout - Left/Right Positioning */
        .action-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 20px;
        }
        
        .view-options {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .btn-primary {
            background: #5D0E26;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(93, 14, 38, 0.3);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Super visible Add Event button - Smaller Size */
        #addEventBtn {
            background: linear-gradient(135deg, #5D0E26, #8B1538) !important;
            border: 2px solid #5D0E26 !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            padding: 12px 25px !important;
            min-width: 150px !important;
            height: 45px !important;
            box-shadow: 0 4px 15px rgba(93, 14, 38, 0.4) !important;
            position: relative !important;
            z-index: 1000 !important;
            opacity: 1 !important;
            visibility: visible !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            border-radius: 8px !important;
        }

        #addEventBtn:hover {
            background: linear-gradient(135deg, #4A0B1E, #6B0F2A) !important;
            border-color: #4A0B1E !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(93, 14, 38, 0.5) !important;
            scale: 1.02 !important;
        }

        #addEventBtn i {
            font-size: 16px !important;
            margin-right: 8px !important;
            color: white !important;
        }
        
        .btn-primary:hover {
            background: #4A0B1E;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #f4f6f8;
            color: #5D0E26;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
        
        .btn-secondary.active {
            background: #5D0E26;
            color: #fff;
        }
        
        .fc-button {
            background: var(--primary-color, #5D0E26);
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .fc-button:hover {
            background: var(--primary-dark, #4A0B1E);
            transform: translateY(-2px);
        }
        
        .fc-button.active {
            background: var(--secondary-color, #8B1538);
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
            transition: all 0.3s ease;
        }
        
        .fc-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        /* Attorney-based color coding */
        .fc-event[data-attorney-name="Mario Refrea"] {
            background-color: #74c0fc !important;
            border-color: #4dabf7 !important;
        }
        
        .fc-event[data-attorney-name="Ken Anthony Juson"] {
            background-color: #51cf66 !important;
            border-color: #40c057 !important;
        }
        
        .fc-event[data-attorney-name="Mar John Refrea"] {
            background-color: #ff6b6b !important;
            border-color: #ff5252 !important;
        }
        
        /* Attorney color legend */
        .attorney-legend {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e9ecef;
        }
        
        .attorney-legend h4 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 16px;
            font-weight: 600;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            margin-right: 10px;
            border: 2px solid #fff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
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
            <li><a href="attorney_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="attorney_cases.php" class="active"><i class="fas fa-gavel"></i><span>Manage Cases</span></a></li>
            <li><a href="attorney_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="attorney_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generation</span></a></li>
            <li><a href="attorney_schedule.php"><i class="fas fa-calendar-alt"></i><span>My Schedule</span></a></li>
            <li><a href="attorney_clients.php"><i class="fas fa-users"></i><span>My Clients</span></a></li>
            <li><a href="attorney_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="attorney_efiling.php"><i class="fas fa-paper-plane"></i><span>E-Filing</span></a></li>
            <li><a href="attorney_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>Schedule Management</h1>
                <p>Manage your court hearings and appointments</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Attorney" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['attorney_name']; ?></h3>
                    <p>Attorney at Law</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary" id="addEventBtn">
                <i class="fas fa-plus"></i> Add Event
            </button>
            <div class="view-options">
                <button class="btn btn-secondary" id="viewMonthBtn">
                    <i class="fas fa-calendar"></i> Month
                </button>
                <button class="btn btn-secondary" id="viewWeekBtn">
                    <i class="fas fa-calendar-week"></i> Week
                </button>
                <button class="btn btn-secondary" id="viewDayBtn">
                    <i class="fas fa-calendar-day"></i> Day
                </button>
            </div>
        </div>

        <!-- Calendar Container -->
        <div class="calendar-container">

            <div id="calendar"></div>
        </div>

        <!-- Upcoming Events -->
        <div class="upcoming-events-section">
            <div class="section-header">
                <h2><i class="fas fa-calendar-check"></i> Upcoming Events</h2>
            </div>
            
            <?php if (empty($events)): ?>
            <div class="no-events">
                <i class="fas fa-calendar-times"></i>
                <h3>No Upcoming Events</h3>
                <p>You have no scheduled events at the moment.</p>
            </div>
            <?php else: ?>
            <div class="events-grid">
                <?php foreach ($events as $ev): ?>
                <?php
                // Debug: Show what attorney name is being used
                $debug_attorney_name = $ev['attorney_name'] ?? $_SESSION['attorney_name'] ?? 'Attorney';
                error_log("Schedule Card Attorney Name: " . $debug_attorney_name);
                ?>
                <div class="event-card status-<?= strtolower($ev['status']) ?>" data-event-id="<?= $ev['id'] ?>" data-attorney-id="<?= $_SESSION['user_id'] ?>" data-attorney-name="<?= htmlspecialchars($debug_attorney_name) ?>">
                    <div class="event-card-header">
                        <div class="event-avatar">
                            <i class="fas fa-<?= $ev['type'] == 'Hearing' ? 'gavel' : 'calendar-check' ?>"></i>
                        </div>
                        <div class="event-info">
                            <h3><?= htmlspecialchars($ev['type']) ?></h3>
                            <p class="case-detail">
                                <i class="fas fa-folder"></i>
                                <?= htmlspecialchars($ev['case_title'] ?? 'No Case') ?>
                            </p>
                            <p class="client-detail">
                                <i class="fas fa-user"></i>
                                <?= htmlspecialchars($ev['client_name'] ?? 'N/A') ?>
                            </p>
                            <p class="attorney-indicator">
                                <i class="fas fa-user-tie"></i>
                                <span class="attorney-name"><?= htmlspecialchars($debug_attorney_name) ?></span>
                            </p>
                        </div>
                    </div>
                    

                    
                    <div class="event-actions">
                        <div class="status-management">
                            <select class="status-select" onchange="updateEventStatus(this)" data-previous-status="<?= htmlspecialchars($ev['status']) ?>">
                                <option value="Scheduled" <?= $ev['status'] == 'Scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                <option value="Completed" <?= $ev['status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="Rescheduled" <?= $ev['status'] == 'Rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                                <option value="Cancelled" <?= $ev['status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <button class="btn btn-warning btn-sm edit-event-btn" onclick="editEvent(this)" 
                            data-event-id="<?= $ev['id'] ?>"
                            data-type="<?= htmlspecialchars($ev['type']) ?>"
                            data-date="<?= htmlspecialchars($ev['date']) ?>"
                            data-time="<?= htmlspecialchars($ev['time']) ?>"
                            data-location="<?= htmlspecialchars($ev['location']) ?>"
                            data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                            data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"
                            data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-primary view-info-btn" 
                            data-type="<?= htmlspecialchars($ev['type']) ?>"
                            data-date="<?= htmlspecialchars($ev['date']) ?>"
                            data-time="<?= htmlspecialchars($ev['time']) ?>"
                            data-location="<?= htmlspecialchars($ev['location']) ?>"
                            data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                            data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"
                            data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Event Modal -->
    <div class="modal" id="addEventModal">
        <div class="modal-content three-column-modal">
            <div class="modal-header">
                <h2>Add New Event</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="eventForm" class="three-column-form">
                    <!-- Column 1 -->
                    <div class="form-column">
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
                    </div>

                    <!-- Column 2 -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="eventLocation">Location</label>
                            <input type="text" id="eventLocation" name="location" value="Cabuyao" placeholder="Enter specific location">
                            <small style="color: #666; font-style: italic;">Start with "Cabuyao" and add details</small>
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
                            <label for="eventType">Event Type</label>
                            <select id="eventType" name="type" required>
                                <option value="Hearing">Hearing</option>
                                <option value="Appointment">Appointment</option>
                                <option value="Free Legal Advice">Free Legal Advice</option>
                            </select>
                        </div>
                    </div>

                    <!-- Column 3 -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="eventClient">Client Selection</label>
                            <select id="eventClient" name="client_id" required>
                                <option value="">Select Client</option>
                                <optgroup label="My Clients (from cases)">
                                    <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['email']) ?>) - My Client</option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="All Clients (for free legal advice)">
                                    <?php foreach ($all_clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <small style="color: #666; font-size: 0.8rem;">Select from your clients for case-related appointments</small>
                        </div>
                        <div class="form-group">
                            <label for="eventDescription">Description</label>
                            <textarea id="eventDescription" name="description" rows="4" placeholder="Enter event description..."></textarea>
                        </div>
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
                        <label for="editEventDate">Time</label>
                        <input type="time" id="editEventTime" name="time" required>
                    </div>
                    <div class="form-group">
                        <label for="editEventLocation">Location</label>
                        <input type="text" id="editEventLocation" name="location" value="Cabuyao" required>
                    </div>
                    <div class="form-group full-width">
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
                            <span class="detail-label"><i class="fas fa-user-tie"></i> Attorney:</span>
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
        .calendar-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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
            gap: 1.5rem;
            max-width: 100%;
        }

        .event-card {
            background: #f8f9fa; /* Light default background that won't interfere with color coding */
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid #e9ecef; /* Neutral border that will be overridden by color coding */
            border-top: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            min-height: 200px;
            display: flex;
            flex-direction: column;
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
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #1976d2, #1565c0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
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

        .attorney-indicator {
            color: #9c27b0 !important;
            font-weight: 500;
        }

        .event-info i {
            font-size: 0.8rem;
            width: 16px;
            text-align: center;
        }



        .event-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.3rem;
            margin-top: auto;
        }

        .status-management {
            flex: 0 0 auto;
        }

        .status-select {
            width: 120px;
            padding: 0.4rem 0.5rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            background: white;
            color: #333;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
        }

        .status-select:focus {
            outline: none;
            border-color: #1976d2;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        .status-select option[value="Scheduled"] {
            color: #1976d2;
            font-weight: 600;
        }

        .status-select option[value="Completed"] {
            color: #2e7d32;
            font-weight: 600;
        }



        .edit-event-btn {
            background: #ffc107 !important;
            border: 1px solid #ffc107 !important;
            color: #212529 !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            padding: 0.2rem 0.4rem !important;
            font-size: 0.65rem !important;
        }

        .edit-event-btn:hover {
            background: #e0a800 !important;
            border-color: #d39e00 !important;
            color: #212529 !important;
        }

        .view-info-btn {
            background: #17a2b8 !important;
            border: 1px solid #17a2b8 !important;
            color: white !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            padding: 0.2rem 0.4rem !important;
            font-size: 0.65rem !important;
        }

        .view-info-btn:hover {
            background: #138496 !important;
            border-color: #138496 !important;
            color: white !important;
        }

        .status-select option[value="Rescheduled"] {
            color: #f57c00;
            font-weight: 600;
        }

        .status-select option[value="Cancelled"] {
            color: #6c757d;
            font-weight: 600;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Status-based Card Styling */
        .event-card.status-scheduled {
            border-left: 4px solid #1976d2;
        }

        .event-card.status-completed {
            border-left: 4px solid #2e7d32;
        }

        .event-card.status-rescheduled {
            border-left: 4px solid #f57c00;
        }

        .event-card.status-cancelled {
            border-left: 4px solid #6c757d;
        }

        /* Attorney Color Coding System - Based on Attorney Names - Higher specificity to override general classes */
        .event-card[data-attorney-name] {
            position: relative;
        }

        /* Ensure these rules have maximum specificity */
        .upcoming-events-section .events-grid .event-card[data-attorney-name="Laica Castillo Refrea"] {
            border-left: 4px solid #74c0fc !important;
            background: linear-gradient(135deg, rgba(116, 192, 252, 0.08) 0%, rgba(116, 192, 252, 0.15) 100%) !important;
        }

        .upcoming-events-section .events-grid .event-card[data-attorney-name="Mario Delmo Refrea"] {
            border-left: 4px solid #ffd43b !important;
            background: linear-gradient(135deg, rgba(255, 212, 59, 0.08) 0%, rgba(255, 212, 59, 0.15) 100%) !important;
        }

        .upcoming-events-section .events-grid .event-card[data-attorney-name="Mar John Refrea"] {
            border-left: 4px solid #c92a2a !important;
            background: linear-gradient(135deg, rgba(201, 42, 42, 0.08) 0%, rgba(201, 42, 42, 0.15) 100%) !important;
        }

        /* Default color for any new attorneys */
        .event-card[data-attorney-name]:not([data-attorney-name="Laica Castillo Refrea"]):not([data-attorney-name="Mario Delmo Refrea"]):not([data-attorney-name="Mar John Refrea"]) {
            border-left: 4px solid #ffd43b !important;
            background: linear-gradient(135deg, rgba(255, 212, 59, 0.08) 0%, rgba(255, 212, 59, 0.15) 100%) !important;
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

        /* Three Column Modal Styles */
        .three-column-modal {
            max-width: 1200px !important;
            width: 95% !important;
            max-height: 85vh !important;
        }

        .three-column-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 2rem;
            padding: 1.5rem;
            height: auto;
            overflow: visible;
        }

        .form-column {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            background: #fff;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #5D0E26;
            box-shadow: 0 0 0 3px rgba(93, 14, 38, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group small {
            color: #666;
            font-size: 0.75rem;
            font-style: italic;
            margin-top: 0.25rem;
        }

        .modal-header {
            background: #5D0E26;
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
            .add-event-modal {
                max-width: 700px !important;
                max-height: 90vh !important;
                margin: 1.5% auto !important;
                width: 95% !important;
            }

                    .add-event-modal .modal-body {
                padding: 0.8rem;
                overflow: visible;
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
                padding: 0.6rem 0.8rem;
                background: #f8f9fa;
                border-top: 1px solid #e0e0e0;
                display: flex;
                justify-content: flex-end;
                gap: 0.6rem;
                border-radius: 0 0 8px 8px;
            }

                    .add-event-modal .btn {
                padding: 0.5rem 1rem;
                border: none;
                border-radius: 5px;
                font-weight: 600;
                font-size: 0.8rem;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                min-width: 80px;
            }

        .add-event-modal .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }

        .add-event-modal .btn-primary {
            background: linear-gradient(135deg, #1976d2, #1565c0);
            color: white;
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
            color: #1976d2;
        }
        .event-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
        }
        .event-form-grid .form-group {
            margin-bottom: 0;
        }
        .event-form-grid .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 1rem;
            color: #555;
            margin-bottom: 4px;
            display: block;
            font-weight: 500;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            background: #fafbfc;
            margin-top: 2px;
            transition: border 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #5D0E26;
            outline: none;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 18px;
        }
        .btn-primary {
            background: #5D0E26;
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background: #4A0B1E;
        }
        .btn-secondary {
            background: #f5f5f5;
            color: #333;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        @media (max-width: 700px) {
            .modal-content { 
                max-width: 98vw; 
                padding: 10px 4vw; 
            }
            .three-column-form { 
                grid-template-columns: 1fr; 
                gap: 1rem; 
            }
            .three-column-modal {
                max-width: 98vw !important;
                width: 98% !important;
            }
        }

        @media (max-width: 1024px) {
            .three-column-form {
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
            }
        }
    </style>

    <script>
        // Use json_encode to safely pass PHP events to JS with attorney-based color coding
        var events = <?php echo json_encode(array_map(function($ev) {
            // Attorney-based color coding - consistent color per attorney
            $attorneyName = $_SESSION['attorney_name'] ?? 'Attorney';
            $eventColor = '#6c757d'; // Default gray
            
            // Dynamic color assignment based on attorney ID for consistency
            $attorneyId = $_SESSION['user_id'] ?? 1;
            $userType = $_SESSION['user_type'] ?? 'attorney';
            $eventColor = '#6c757d'; // Default gray
            
            // Admin gets Light Maroon color
            if ($userType === 'admin') {
                $eventColor = '#c92a2a'; // Light Maroon for admin
            } else {
                // Dynamic color palette for attorneys
                $colorPalette = [
                    '#51cf66', // Light Green (1st attorney)
                    '#74c0fc', // Light Blue (2nd attorney)
                    '#ffd43b', // Light Orange (3rd attorney)
                    '#da77f2', // Light Violet (4th attorney)
                    '#ffa8a8', // Light Pink (5th attorney)
                    '#69db7c', // Bright Green (6th attorney)
                    '#4dabf7', // Bright Blue (7th attorney)
                    '#e599f7', // Bright Violet (8th attorney)
                    '#ffb3bf', // Bright Pink (9th attorney)
                    '#96f2d7'  // Mint Green (10th attorney)
                ];
                
                // Use attorney ID for consistent color assignment
                $colorIndex = ($attorneyId - 1) % count($colorPalette);
                $eventColor = $colorPalette[$colorIndex];
            }
            
            return [
                "title" => ($ev['type'] ?? '') . ': ' . ($ev['title'] ?? ''),
                "start" => ($ev['date'] ?? '') . 'T' . ($ev['time'] ?? ''),
                "description" => $ev['description'] ?? '',
                "location" => $ev['location'] ?? '',
                "case" => $ev['case_title'] ?? '',
                "client" => $ev['client_name'] ?? '',
                "type" => $ev['type'] ?? '',
                "attorneyName" => $attorneyName,
                "attorneyId" => $_SESSION['user_id'] ?? 0,
                "color" => $eventColor,
                "backgroundColor" => $eventColor,
                "borderColor" => $eventColor,
                "textColor" => '#ffffff'
            ];
        }, $events), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

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
            
            // Get event ID from the card (you might need to add this data attribute)
            const eventId = eventCard.dataset.eventId || '1'; // Default fallback
            
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

        // Function to edit event
        window.editEvent = function(button) {
            const eventId = button.dataset.eventId;
            const eventType = button.dataset.type;
            const eventDate = button.dataset.date;
            const eventTime = button.dataset.time;
            const eventLocation = button.dataset.location;
            const eventCase = button.dataset.case;
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
        window.closeEditModal = function() {
            document.getElementById('editEventModal').style.display = 'none';
        }

        // Function to save event changes
        window.saveEventChanges = async function() {
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

            fetch('attorney_schedule.php', {
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

        // Function to update event card UI based on attorney color coding
        function updateEventCardUI(selectElement, newStatus) {
            const eventCard = selectElement.closest('.event-card');
            
            // Remove previous status classes
            eventCard.classList.remove('status-scheduled', 'status-completed', 'status-rescheduled', 'status-cancelled');
            
            // Add new status class
            eventCard.classList.add(`status-${newStatus.toLowerCase()}`);
            
            // Get attorney name for color coding
            const attorneyName = eventCard.dataset.attorneyName;
            
            // User color coding system based on actual database users
            const userColors = {
                'Laica Castillo Refrea': '#51cf66', // Light Green
                'Mario Delmo Refrea': '#74c0fc', // Light Blue
                'Santiago, Macky Refrea': '#ffd43b', // Light Orange
                'Mar John Refrea': '#ff6b6b', // Light Red
                'Yuhan Nerfy Refrea': '#da77f2' // Light Violet
            };
            
            // Apply attorney color coding (priority over status colors)
            if (attorneyName && userColors[attorneyName]) {
                eventCard.style.borderLeftColor = userColors[attorneyName];
            } else {
                // Fallback to status colors if no attorney name
                const statusColors = {
                    'Scheduled': '#1976d2',
                    'Completed': '#2e7d32',
                    'Rescheduled': '#f57c00',
                    'Cancelled': '#6c757d'
                };
                eventCard.style.borderLeftColor = statusColors[newStatus] || '#1976d2';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            
            // Debug: Log the events data
            console.log('Calendar events:', <?= json_encode($js_events) ?>);
            
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?= json_encode($js_events) ?>,
                eventColor: null,
                height: 'auto',
                eventDidMount: function(info) {
                    // Apply attorney-based color coding using the helper function
                    const attorneyName = info.event.extendedProps.attorneyName;
                    const userType = info.event.extendedProps.attorneyUserType || 'attorney';
                    
                    console.log('Event:', info.event.title, 'Attorney:', attorneyName, 'Type:', userType);
                    
                    // Use the centralized color system
                    applyAttorneyColors(info.el, attorneyName, userType);
                    
                    // Add data attributes for CSS styling
                    info.el.setAttribute('data-attorney-name', attorneyName);
                    info.el.setAttribute('data-attorney-id', info.event.extendedProps.attorneyId);
                    
                    console.log('Applied styles to element:', info.el);
                },
                eventClick: function(info) {
                    // Enhanced event click with better formatting
                    const eventDate = info.event.start ? new Date(info.event.start).toLocaleDateString() : 'N/A';
                    const eventTime = info.event.start ? new Date(info.event.start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'N/A';
                    
                    const message = `📅 ${info.event.title}\n\n` +
                                  `🕐 Date: ${eventDate}\n` +
                                  `⏰ Time: ${eventTime}\n` +
                                  `📍 Location: ${info.event.extendedProps.location || 'N/A'}\n` +
                                  `📋 Case: ${info.event.extendedProps.case || 'N/A'}\n` +
                                  `👤 Client: ${info.event.extendedProps.client || 'N/A'}\n` +
                                  `📝 Description: ${info.event.extendedProps.description || 'N/A'}`;
                    
                    alert(message);
                }
            });
            calendar.render();

            // Modal functionality
            const modal = document.getElementById('addEventModal');
            const addEventBtn = document.getElementById('addEventBtn');
            const closeModal = document.querySelector('.close-modal');
            const cancelEvent = document.getElementById('cancelEvent');

            addEventBtn.onclick = function() {
                modal.style.display = "block";
                
                // Set minimum date to today
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('eventDate').min = today;
                document.getElementById('eventDate').value = today;
            }

            closeModal.onclick = function() {
                modal.style.display = "none";
            }

            cancelEvent.onclick = function() {
                modal.style.display = "none";
            }

            // Close modal when clicking outside - REMOVED to prevent accidental closing
            // window.onclick = function(event) {
            //     if (event.target == modal) {
            //         modal.style.display = "none";
            //     }
            //     if (event.target == document.getElementById('eventInfoModal')) {
            //         document.getElementById('eventInfoModal').style.display = "none";
            //     }
            //     if (event.target == document.getElementById('editEventModal')) {
            //         document.getElementById('editEventModal').style.display = "none";
            //     }
            // }

            // View buttons functionality
            document.getElementById('viewDayBtn').onclick = function() {
                calendar.changeView('timeGridDay');
            }

            document.getElementById('viewWeekBtn').onclick = function() {
                calendar.changeView('timeGridWeek');
            }

            document.getElementById('viewMonthBtn').onclick = function() {
                calendar.changeView('dayGridMonth');
            }

            // Initialize attorney color coding
            initializeAttorneyColorCoding();
            
            // Initialize event handlers after calendar is ready
            initializeEventHandlers();

            // Add AJAX for saving event
            document.getElementById('saveEvent').onclick = function() {
                // Form validation
                const caseSelect = document.getElementById('eventCase');
                const clientSelect = document.getElementById('eventClient');
                const eventDate = document.getElementById('eventDate').value;
                const eventTime = document.getElementById('eventTime').value;
                
                // Check if date is in the past
                const selectedDateTime = new Date(eventDate + 'T' + eventTime);
                const now = new Date();
                
                if (selectedDateTime < now) {
                    alert('❌ Cannot schedule events in the past. Please select a future date and time.');
                    return;
                }
                
                // If no case is selected, client is required
                if (!caseSelect.value && !clientSelect.value) {
                    alert('Please select either a case or a client for this event.');
                    return;
                }
                
                const fd = new FormData(document.getElementById('eventForm'));
                fd.append('action', 'add_event');
                fetch('attorney_schedule.php', { method: 'POST', body: fd })
                    .then(r => r.text()).then(res => {
                        if (res === 'success') {
                            showNotification('✅ Event successfully created!', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification('❌ Error saving event. Please try again.', 'error');
                        }
                    });
            };

            // Event status management functions
            

            
            // Handle View Details button clicks
            function handleViewDetailsClick() {
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
                        document.getElementById('modalAttorney').innerText = '<?= htmlspecialchars($_SESSION['attorney_name'] ?? 'N/A') ?>';
                        document.getElementById('modalClient').innerText = this.dataset.client || '-';
                        document.getElementById('modalDescription').innerText = this.dataset.description || '-';
                        
                        // Show modal
                        document.getElementById('eventInfoModal').style.display = "block";
                        
                        // Add event listeners for close buttons after modal is shown
                        addModalCloseListeners();
                    });
                });
            }
            
            // Add event listeners for modal close buttons
            function addModalCloseListeners() {
                // Close button (X) in header for Event Details modal
                const closeModal = document.querySelector('#eventInfoModal .close-modal');
                if (closeModal) {
                    // Remove existing listeners first
                    closeModal.replaceWith(closeModal.cloneNode(true));
                    const newCloseModal = document.querySelector('#eventInfoModal .close-modal');
                    newCloseModal.addEventListener('click', function() {
                        document.getElementById('eventInfoModal').style.display = "none";
                    });
                }
                
                // Close button in footer for Event Details modal
                const closeEventInfoModal = document.getElementById('closeEventInfoModal');
                if (closeEventInfoModal) {
                    // Remove existing listeners first
                    closeEventInfoModal.replaceWith(closeEventInfoModal.cloneNode(true));
                    const newCloseEventInfoModal = document.getElementById('closeEventInfoModal');
                    newCloseEventInfoModal.addEventListener('click', function() {
                        document.getElementById('eventInfoModal').style.display = "none";
                    });
                }
                
                // Close button (X) in header for Add Event modal
                const addEventCloseModal = document.querySelector('#addEventModal .close-modal');
                if (addEventCloseModal) {
                    // Remove existing listeners first
                    addEventCloseModal.replaceWith(addEventCloseModal.cloneNode(true));
                    const newAddEventCloseModal = document.querySelector('#addEventModal .close-modal');
                    newAddEventCloseModal.addEventListener('click', function() {
                        document.getElementById('addEventModal').style.display = "none";
                    });
                }
            }
            
            // Initialize attorney color coding for all event cards
            function initializeAttorneyColorCoding() {
                const cards = document.querySelectorAll('.event-card[data-attorney-name]');
                
                console.log('Found', cards.length, 'attorney cards to process');
                
                cards.forEach(card => {
                    const attorneyName = card.dataset.attorneyName;
                    
                    // Debug: Log what attorney name is being processed
                    console.log('Processing attorney card:', attorneyName);
                    console.log('Card element:', card);
                    console.log('Current background:', window.getComputedStyle(card).background);
                    console.log('Current border-left:', window.getComputedStyle(card).borderLeft);
                    
                    // Let CSS handle the color coding - don't override with JavaScript
                    // This ensures our CSS rules with !important take precedence
                });
            }

            // Initialize calendar view options
            function initializeViewOptions() {
                const viewButtons = document.querySelectorAll('.view-options .btn');
                
                viewButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const buttonId = this.id;
                        
                        // Remove active class from all buttons
                        viewButtons.forEach(btn => btn.classList.remove('active'));
                        
                        // Add active class to clicked button
                        this.classList.add('active');
                        
                        // Change calendar view
                        if (calendar) {
                            switch(buttonId) {
                                case 'viewMonthBtn':
                                    calendar.changeView('dayGridMonth');
                                    break;
                                case 'viewWeekBtn':
                                    calendar.changeView('timeGridWeek');
                                    break;
                                case 'viewDayBtn':
                                    calendar.changeView('timeGridDay');
                                    break;
                            }
                        }
                    });
                });
                
                // Set Month as default active
                const monthBtn = document.getElementById('viewMonthBtn');
                if (monthBtn) {
                    monthBtn.classList.add('active');
                }
            }
            
            // Initialize all event handlers
            function initializeEventHandlers() {
                // Initialize status selects
                document.querySelectorAll('.status-select').forEach(select => {
                    select.dataset.previousStatus = select.value;
                });
                
                // Initialize view details buttons
                handleViewDetailsClick();
                
                // Initialize modal close functionality
                initializeModalHandlers();
                
                // Initialize calendar view options
                initializeViewOptions();
            }
            
            // Initialize modal handlers
            function initializeModalHandlers() {
                // Close modal when clicking outside - REMOVED to prevent accidental closing
                // window.onclick = function(event) {
                //     if (event.target == document.getElementById('eventInfoModal')) {
                //         document.getElementById('eventInfoModal').style.display = "none";
                //     }
                //     if (event.target == document.getElementById('editEventModal')) {
                //         document.getElementById('editEventModal').style.display = "none";
                //     }
                // }
            }
            
            // Initialize when DOM is ready
            document.addEventListener('DOMContentLoaded', function() {
                initializeEventHandlers();
            });
            
            // Modal close functionality is now handled in initializeModalHandlers()
        });
    </script>
</body>
</html> 