<?php

require_once 'session_manager.php';

validateUserAccess('admin');

require_once 'config.php';

require_once 'audit_logger.php';

require_once 'action_logger_helper.php';

$admin_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");

$stmt->bind_param("i", $admin_id);

$stmt->execute();

$res = $stmt->get_result();

$profile_image = '';

if ($res && $row = $res->fetch_assoc()) {

    $profile_image = $row['profile_image'];

}

if (!$profile_image || !file_exists($profile_image)) {

        $profile_image = 'images/default-avatar.jpg';

    }

// Fetch all cases for dropdown

$cases = [];

$stmt = $conn->prepare("SELECT ac.id, ac.title, uf1.name as client_name, uf2.name as attorney_name FROM attorney_cases ac 

    LEFT JOIN user_form uf1 ON ac.client_id = uf1.id 

    LEFT JOIN user_form uf2 ON ac.attorney_id = uf2.id 

    ORDER BY ac.id DESC");

$stmt->execute();

$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) $cases[] = $row;



// Fetch all attorneys for dropdown

$attorneys = [];

$stmt = $conn->prepare("SELECT id, name, email FROM user_form WHERE user_type = 'attorney' ORDER BY name");

$stmt->execute();

$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) $attorneys[] = $row;



// Fetch all clients for dropdown

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

    $stmt->bind_param('iiissssssi', $case_id, $attorney_id, $client_id, $type, $title, $description, $date, $time, $location, $admin_id);

    $stmt->execute();

    echo $stmt->affected_rows > 0 ? 'success' : 'error';

    exit();

}



// Fetch all events with joins

$events = [];

$stmt = $conn->prepare("SELECT cs.*, ac.title as case_title, 

    CASE 

        WHEN cs.attorney_id IS NOT NULL THEN cs.attorney_id 

        ELSE ac.attorney_id 

    END as final_attorney_id,

    uf1.name as attorney_name, uf2.name as client_name, cs.created_by_employee_id,

    CASE 

        WHEN cs.attorney_id = ? THEN 1  -- Admin's own schedules first (priority 1)

        WHEN cs.attorney_id IS NOT NULL THEN 2  -- Other attorneys (priority 2)

        ELSE 3  -- Events without specific attorney (priority 3)

    END as priority_order

    FROM case_schedules cs

    LEFT JOIN attorney_cases ac ON cs.case_id = ac.id

    LEFT JOIN user_form uf1 ON (

        CASE 

            WHEN cs.attorney_id IS NOT NULL THEN cs.attorney_id 

            ELSE ac.attorney_id 

        END

    ) = uf1.id

    LEFT JOIN user_form uf2 ON cs.client_id = uf2.id

    ORDER BY priority_order ASC, uf1.name ASC, cs.date ASC, cs.time ASC");

$stmt->bind_param('i', $admin_id);

$stmt->execute();

$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) $events[] = $row;

$js_events = [];

foreach ($events as $ev) {

    // Determine color based on attorney/admin assigned to the event

    $color = '#6c757d'; // Default gray for unknown

    

    // Get the attorney name for color coding

    $attorney_name = $ev['attorney_name'] ?? 'Unknown';

    

    // Dynamic color assignment based on attorney ID for consistency
    $attorneyId = $ev['final_attorney_id'] ?? 0;
    $attorneyUserType = $ev['attorney_user_type'] ?? 'attorney';
    $color = '#6c757d'; // Default gray for unknown
    
    // Admin gets Light Maroon color
    if ($attorneyUserType === 'admin') {
        $color = '#c92a2a'; // Light Maroon for admin
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
        if ($attorneyId > 0) {
            $colorIndex = ($attorneyId - 1) % count($colorPalette);
            $color = $colorPalette[$colorIndex];
        }
    }

    

    // Debug: Log the attorney information and status

    error_log("Event: " . $ev['type'] . " - Attorney ID: " . ($ev['final_attorney_id'] ?? 'NULL') . " - Attorney Name: " . ($ev['attorney_name'] ?? 'NULL') . " - Status: " . ($ev['status'] ?? 'NULL'));

    

    $js_events[] = [

        'title' => $ev['type'] . ': ' . ($ev['case_title'] ?? ''),

        'start' => $ev['date'] . 'T' . $ev['time'],

        'type' => $ev['type'],

        'description' => $ev['description'],

        'location' => $ev['location'],

        'case' => $ev['case_title'],

        'attorney' => $ev['attorney_name'],

        'client' => $ev['client_name'],

        'color' => $color,

        'extendedProps' => [

            'eventType' => $ev['type'],

            'attorneyName' => $attorney_name,

            'attorneyId' => $ev['final_attorney_id'] ?? 0

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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">

    <link rel="stylesheet" href="assets/css/dashboard.css">

</head>

<body>

    <!-- Sidebar -->

    <div class="sidebar">

                <div class="sidebar-header">

            <img src="images/logo.jpg" alt="Logo">

            <h2>Opiña Law Office</h2>

        </div>

        <ul class="sidebar-menu">

            <li><a href="admin_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>

            <li><a href="admin_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>

            <li><a href="admin_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>

            <li><a href="admin_schedule.php" class="active"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>

            <li><a href="admin_usermanagement.php"><i class="fas fa-users-cog"></i><span>User Management</span></a></li>

            <li><a href="admin_managecases.php"><i class="fas fa-gavel"></i><span>Case Management</span></a></li>

            <li><a href="admin_clients.php"><i class="fas fa-users"></i><span>My Clients</span></a></li>

            <li><a href="admin_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>

            <li><a href="admin_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>

        </ul>

    </div>



    <!-- Main Content -->

    <div class="main-content">

        <!-- Header -->

        <div class="header">

            <div class="header-title">

                <h1>Schedule Management</h1>

                <p>Manage court hearings, meetings, and appointments</p>

            </div>

            <div class="user-info">

                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Admin" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">

                <div class="user-details">

                    <h3><?php echo $_SESSION['admin_name']; ?></h3>

                    <p>System Administrator</p>

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

                    if ($ev['final_attorney_id'] == $admin_id) {

                        $admin_events[] = $ev;

                    } elseif ($ev['final_attorney_id'] !== null) {

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

                        <h3>My Schedules (Admin)</h3>

                        <span class="priority-badge">Priority 1</span>

                    </div>

                    <div class="events-grid">

                        <?php foreach ($admin_events as $ev): ?>

                                                 <div class="event-card admin-event" data-event-id="<?= $ev['id'] ?>" data-event-type="<?= htmlspecialchars($ev['type']) ?>" data-attorney="<?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?>">

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

                                        data-location="<?= htmlspecialchars($ev['location']) ?>"

                                        data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"

                                        data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"

                                        data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"

                                        data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">

                                        <i class="fas fa-edit"></i> Edit

                                    </button>

                                    <button class="btn btn-info btn-sm view-details-btn" onclick="viewEventDetails(this)" 

                                        data-event-id="<?= $ev['id'] ?>"

                                        data-type="<?= htmlspecialchars($ev['type']) ?>"

                                        data-date="<?= htmlspecialchars($ev['date']) ?>"

                                        data-time="<?= htmlspecialchars($ev['time']) ?>"

                                        data-location="<?= htmlspecialchars($ev['location']) ?>"

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

                                             data-location="<?= htmlspecialchars($ev['location']) ?>"

                                             data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"

                                             data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"

                                             data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"

                                             data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">

                                             <i class="fas fa-edit"></i> Edit

                                         </button>

                                         <button class="btn btn-info btn-sm view-details-btn" onclick="viewEventDetails(this)" 

                                             data-event-id="<?= $ev['id'] ?>"

                                             data-type="<?= htmlspecialchars($ev['type']) ?>"

                                             data-date="<?= htmlspecialchars($ev['date']) ?>"

                                             data-time="<?= htmlspecialchars($ev['time']) ?>"

                                             data-location="<?= htmlspecialchars($ev['location']) ?>"

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

                

                <!-- Other Events -->

                <?php if (!empty($other_events)): ?>

                <div class="priority-section other-priority">

                    <div class="priority-header">

                        <i class="fas fa-calendar-alt"></i>

                        <h3>Other Events</h3>

                        <span class="priority-badge">Priority 3</span>

                    </div>

                    <div class="events-grid">

                        <?php foreach ($other_events as $ev): ?>

                                                                                                   <div class="event-card other-event" data-event-id="<?= $ev['id'] ?>" data-event-type="<?= htmlspecialchars($ev['type']) ?>" data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? 'Unknown Attorney') ?>">

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

                                        data-location="<?= htmlspecialchars($ev['location']) ?>"

                                        data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"

                                        data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"

                                        data-client="<?= htmlspecialchars($ev['client_name'] ?? '-') ?>"

                                        data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">

                                        <i class="fas fa-edit"></i> Edit

                                    </button>

                                    <button class="btn btn-info btn-sm view-details-btn" onclick="viewEventDetails(this)" 

                                        data-event-id="<?= $ev['id'] ?>"

                                        data-type="<?= htmlspecialchars($ev['type']) ?>"

                                        data-date="<?= htmlspecialchars($ev['date']) ?>"

                                        data-time="<?= htmlspecialchars($ev['time']) ?>"

                                        data-location="<?= htmlspecialchars($ev['location']) ?>"

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

                         <small style="color: #666; font-style: italic;">This will be the person assigned to handle this event</small>

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



         <!-- Edit Event Modal -->

     <div class="modal" id="editEventModal">

         <div class="modal-content edit-event-modal">

             <div class="modal-header">

                 <h2>Edit Event</h2>

             </div>

            <div class="modal-body">

                <form id="editEventForm" class="event-form-grid">

                    <input type="hidden" id="editEventId" name="event_id">

                    <div class="form-group">

                        <label for="editEventTitle">Event Title</label>

                        <input type="text" id="editEventTitle" name="title" required>

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

                        <input type="text" id="editEventLocation" name="location" required>

                    </div>

                    <div class="form-group">

                        <label for="editEventType">Event Type</label>

                        <select id="editEventType" name="type" required>

                            <option value="Hearing">Hearing</option>

                            <option value="Appointment">Appointment</option>

                            <option value="Free Legal Advice">Free Legal Advice</option>

                        </select>

                    </div>

                    <div class="form-group full-width">

                        <label for="editEventDescription">Description</label>

                        <textarea id="editEventDescription" name="description" rows="3"></textarea>

                    </div>

                </form>

            </div>

            <div class="modal-footer">

                <button class="btn btn-secondary" id="cancelEditEvent">Cancel</button>

                <button class="btn btn-primary" id="saveEditEvent">Save Changes</button>

            </div>

        </div>

    </div>



    <style>

        .calendar-container {

            background: white;

            border-radius: 10px;

            padding: 20px;

            margin-bottom: 30px;

            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.08);

            overflow: hidden;

        }

        .fc .fc-toolbar-title {

            font-size: 1.6em;

            font-weight: 600;

            color: #1976d2;

        }

        .fc .fc-daygrid-day.fc-day-today {

            background: #e3f2fd;

        }

        

        /* Calendar sizing and overflow control */

        .fc .fc-daygrid-day {

            min-height: 100px;

        }

        

        .fc .fc-daygrid-day-frame {

            min-height: 100px;

        }

        

        .fc .fc-daygrid-day-events {

            min-height: 0;

            max-height: none;

        }

        

        .fc .fc-daygrid-day-number {

            font-size: 0.9em;

            font-weight: 500;

        }

        .fc .fc-daygrid-event {

            border-radius: 6px;

            font-size: 1em;

            box-shadow: 0 1px 4px rgba(25, 118, 210, 0.08);

            padding: 4px 8px;

            margin: 2px 0;

            border: none;

            font-weight: 500;

        }



        /* Attorney-based Color Coding for Calendar Events */

        .fc .fc-daygrid-event {

            border-radius: 4px;

            font-size: 0.85em;

            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);

            padding: 3px 6px;

            margin: 1px 0;

            border: 1px solid !important;

            font-weight: 500;

            transition: all 0.2s ease;

            max-width: 100%;

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

        }



        /* Hover effects for all calendar events */

        .fc .fc-daygrid-event:hover {

            transform: translateY(-1px);

            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);

        }

        .fc .fc-button {

            background: #5D0E26;

            border: none;

            color: #fff;

            border-radius: 5px;

            padding: 6px 14px;

            font-weight: 500;

            margin: 0 2px;

            transition: background 0.2s;

        }

        .fc .fc-button:hover, .fc .fc-button:focus {

            background: #4A0B1E;

        }

        .fc .fc-button-primary:not(:disabled).fc-button-active, .fc .fc-button-primary:not(:disabled):active {

            background: #8B1538;

        }

        .view-options .btn {

            padding: 8px 15px;

            border-radius: 5px;

            border: none;

            background: #f4f6f8;

            color: #5D0E26;

            font-weight: 500;

            transition: background 0.2s, color 0.2s;

            }

        .view-options .btn.active, .view-options .btn:active {

            background: #5D0E26;

            color: #fff;

            }



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



        .btn-primary {

            background: #5D0E26;

            color: white;

            border: none;

            padding: 10px 20px;

            border-radius: 6px;

            cursor: pointer;

            font-size: 14px;

            font-weight: 500;

            transition: all 0.3s ease;

            display: inline-flex;

            align-items: center;

            gap: 8px;

        }

        

        .btn-primary:hover {

            background: #4A0B1E;

            transform: translateY(-1px);

        }



        .legend {

            font-size: 1em;

        }





        @media (max-width: 900px) {

            .calendar-container { padding: 5px; }

        }







        .btn-secondary {

            background: linear-gradient(135deg, #6c757d, #5a6268);

            color: white;

            border: none;

            padding: 0.5rem 1rem;

            border-radius: 5px;

            font-weight: 600;

            font-size: 0.8rem;

            cursor: pointer;

            transition: all 0.3s ease;

            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);

            min-width: 80px;

        }



        .btn-secondary:hover {

            background: linear-gradient(135deg, #5a6268, #4a4a4a);

            transform: translateY(-1px);

        }



        @media (max-width: 700px) {

            .event-form-grid { 

                grid-template-columns: 1fr; 

                gap: 12px; 

            }

            .action-buttons {

                flex-direction: column;

                gap: 1rem;

                align-items: stretch;

            }

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

            margin-bottom: 2rem;

        }



        .section-header .header-content {

            display: flex;

            justify-content: space-between;

            align-items: center;

            flex-wrap: wrap;

            gap: 1rem;

        }



        .section-header .header-text {

            text-align: left;

        }



        .section-header .header-actions {

            display: flex;

            gap: 0.5rem;

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



        .section-header h2 {

            color: #5d0e26;

            font-size: 2rem;

            font-weight: 700;

            margin: 0 0 0.5rem 0;

        }



        .section-header p {

            color: #666;

            font-size: 1.1rem;

            margin: 0;

        }



        .no-events {

            text-align: center;

            padding: 3rem;

            color: #666;

        }



        .no-events i {

            font-size: 4rem;

            margin-bottom: 1rem;

            opacity: 0.3;

        }



        .no-events h3 {

            margin: 0 0 1rem 0;

            color: #333;

        }



        .events-grid {

            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 0.75rem;

            align-items: start;

        }



        .event-card {

            background: white;

            border-radius: 8px;

            padding: 0.6rem;

            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);

            transition: all 0.3s ease;

            border: 1px solid #f0f0f0;

            cursor: pointer;

            min-height: 120px;

        }



        .event-card:hover {

            transform: translateY(-4px);

            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);

            border-color: #5d0e26;

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



        /* Admin Event Cards - Light Maroon Background */
        .event-card.admin-event {

            background: linear-gradient(135deg, rgba(201, 42, 42, 0.08) 0%, rgba(201, 42, 42, 0.15) 100%);
            border-left: 4px solid #c92a2a;
        }



        /* Attorney Event Cards - Default Light Green Background (fallback) */

        .event-card.attorney-event {

            background: linear-gradient(135deg, rgba(81, 207, 102, 0.08) 0%, rgba(81, 207, 102, 0.15) 100%);

            border-left: 4px solid #51cf66;

        }



        /* Other Event Cards - Light Blue Background */

        .event-card.other-event {

            background: linear-gradient(135deg, rgba(116, 192, 252, 0.08) 0%, rgba(116, 192, 252, 0.15) 100%);

            border-left: 4px solid #74c0fc;

        }



        /* Specific User Color Coding - Higher specificity to override general classes */

        .event-card[data-attorney="Laica Castillo Refrea"] {

            background: linear-gradient(135deg, rgba(116, 192, 252, 0.08) 0%, rgba(116, 192, 252, 0.15) 100%) !important;

            border-left: 4px solid #74c0fc !important;

        }



        .event-card[data-attorney="Mario Delmo Refrea"] {
            background: linear-gradient(135deg, rgba(255, 212, 59, 0.08) 0%, rgba(255, 212, 59, 0.15) 100%) !important;

            border-left: 4px solid #ffd43b !important;

        }



        .event-card[data-attorney="Mar John Refrea"] {

            background: linear-gradient(135deg, rgba(201, 42, 42, 0.08) 0%, rgba(201, 42, 42, 0.15) 100%) !important;
            border-left: 4px solid #c92a2a !important;
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



        /* Specific User Avatar Colors - Higher specificity to override general classes */

        .event-card[data-attorney="Laica Castillo Refrea"] .avatar-placeholder {

            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%) !important;

        }



        .event-card[data-attorney="Mario Delmo Refrea"] .avatar-placeholder {

            background: linear-gradient(135deg, #74c0fc 0%, #4dabf7 100%) !important;

        }



        .event-card[data-attorney="Santiago, Macky Refrea"] .avatar-placeholder {

            background: linear-gradient(135deg, #ffd43b 0%, #fcc419 100%) !important;

        }



        .event-card[data-attorney="Mar John Refrea"] .avatar-placeholder {

            background: linear-gradient(135deg, #ff6b6b 0%, #ff5252 100%) !important;

        }



        .event-card[data-attorney="Yuhan Nerfy Refrea"] .avatar-placeholder {

            background: linear-gradient(135deg, #da77f2 0%, #cc5de8 100%) !important;

        }



        .event-card-header {

            display: flex;

            align-items: center;

            gap: 0.5rem;

            margin-bottom: 0.5rem;

        }



        .event-avatar {

            width: 35px;

            height: 35px;

            border-radius: 50%;

            overflow: hidden;

            flex-shrink: 0;

        }



        .avatar-placeholder {

            width: 100%;

            height: 100%;

            background: linear-gradient(135deg, #5d0e26 0%, #8b1538 100%);

            display: flex;

            align-items: center;

            justify-content: center;

            color: white;

            font-size: 1.5rem;

        }



        .event-info h3 {

            margin: 0 0 0.25rem 0;

            color: #5d0e26;

            font-weight: 600;

        }



        .event-info p {

            margin: 0 0 0.3rem 0;

            color: #666;

            font-size: 0.8rem;

            display: flex;

            align-items: center;

            gap: 0.4rem;

        }



        .event-info p:last-child {

            margin-bottom: 0;

        }



        .case-detail {

            color: #1976d2 !important;

            font-weight: 500;

        }



        .attorney-detail {

            color: #43a047 !important;

            font-weight: 500;

        }



        .client-detail {

            color: #9c27b0 !important;

            font-weight: 500;

        }



        /* Attorney Name Display Styling */

        .attorney-name-display {

            background: linear-gradient(135deg, rgba(25, 118, 210, 0.1) 0%, rgba(25, 118, 210, 0.2) 100%);

            border: 1px solid rgba(25, 118, 210, 0.3);

            border-radius: 8px;

            padding: 0.5rem 0.75rem;

            margin: 0.5rem 0;

            display: flex;

            align-items: center;

            gap: 0.5rem;

            color: #1976d2;

            font-weight: 600;

            font-size: 1rem;

        }



        .attorney-name-display i {

            color: #1976d2;

            font-size: 1.1rem;

        }



        .attorney-name-display strong {

            color: #1976d2;

            font-weight: 700;

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



        .event-info i {

            font-size: 1rem;

            width: 16px;

            text-align: center;

        }



        .event-stats {

            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 0.5rem;

            margin-bottom: 0.75rem;

        }



        .stat-item {

            text-align: center;

            padding: 0.5rem;

            background: #f8f9fa;

            border-radius: 6px;

            border: 1px solid #e9ecef;

            height: 65px;

            display: flex;

            flex-direction: column;

            justify-content: center;

            align-items: center;

            min-height: 65px;

        }



        /* Colored boxes for date, time, and location */

        .stat-item.date-box {

            background: rgba(76, 175, 80, 0.15);

            border-color: rgba(76, 175, 80, 0.3);

            border-left: 4px solid #4caf50;

        }



        .stat-item.time-box {

            background: rgba(255, 193, 7, 0.15);

            border-color: rgba(255, 193, 7, 0.3);

            border-left: 4px solid #ffc107;

        }



        .stat-item.location-box {

            background: rgba(156, 39, 176, 0.15);

            border-color: rgba(156, 39, 176, 0.3);

            border-left: 4px solid #9c27b0;

        }



        .stat-item .stat-number {

            font-size: 1.1rem;

            font-weight: 700;

            color: #333;

            margin-bottom: 0.25rem;

        }



        .stat-item .stat-label {

            font-size: 0.8rem;

            color: #666;

            font-weight: 500;

            text-transform: uppercase;

            letter-spacing: 0.5px;

        }



        .stat-number {

            font-size: 1.5rem;

            font-weight: 700;

            color: #1976d2;

            margin-bottom: 0.25rem;

            line-height: 1;

            min-height: 1.5rem;

            display: flex;

            align-items: center;

            justify-content: center;

            text-align: center;

            width: 100%;

        }



        .stat-label {

            font-size: 0.8rem;

            color: #666;

            text-transform: uppercase;

            letter-spacing: 0.5px;

            line-height: 1;

            min-height: 1rem;

        }



        .client-name {

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;

            max-width: 100%;

            cursor: help;

            font-size: 0.9rem;

            line-height: 1.2;

            text-align: center;

            width: 100%;

        }



        .event-actions {

            display: flex;

            gap: 0.4rem;

            flex-wrap: wrap;

            align-items: center;

            margin-top: 0.4rem;

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



        .event-status {

            display: inline-block;

            padding: 0.25rem 0.75rem;

            border-radius: 20px;

            font-size: 0.75rem;

            font-weight: 600;

            text-transform: uppercase;

            letter-spacing: 0.5px;

        }



        .status-scheduled {

            background: rgba(25, 118, 210, 0.1);

            color: #1976d2;

            border: 1px solid rgba(25, 118, 210, 0.3);

        }



        .status-completed {

            background: rgba(76, 175, 80, 0.1);

            color: #4caf50;

            border: 1px solid rgba(76, 175, 80, 0.3);

        }



        .status-cancelled {

            background: rgba(244, 67, 54, 0.1);

            color: #f44336;

            border: 1px solid rgba(244, 67, 54, 0.3);

        }



        .status-rescheduled {

            background: rgba(255, 193, 7, 0.1);

            color: #ff9800;

            border: 1px solid rgba(255, 193, 7, 0.3);

        }



        /* Button Styles */

        .btn {

            border: none;

            border-radius: 8px;

            font-weight: 500;

            cursor: pointer;

            transition: all 0.3s ease;

            text-decoration: none;

            display: inline-flex;

            align-items: center;

            gap: 0.5rem;

        }



        .btn-primary {

            background: #5D0E26;

            color: white;

        }



        .btn-primary:hover {

            background: #4A0B1E;

        }



        .btn-secondary {

            background: #6c757d;

            color: white;

        }



        .btn-secondary:hover {

            background: #5a6268;

        }



        .btn-warning {

            background: #ffc107;

            color: #212529;

        }



        .btn-warning:hover {

            background: #e0a800;

        }



        .btn-outline-primary {

            background: transparent;

            color: #5d0e26;

            border: 2px solid #5d0e26;

        }



        .btn-outline-primary:hover {

            background: #5d0e26;

            color: white;

        }



        .btn-info {

            background: #17a2b8;

            color: white;

        }



        .btn-info:hover {

            background: #138496;

        }



        /* Modal Styles */

        .modal {

            display: none;

            position: fixed;

            z-index: 9999;

            left: 0;

            top: 0;

            width: 100%;

            height: 100%;

            background-color: rgba(0, 0, 0, 0.5);

            overflow: hidden;

        }



        .modal-content {

            background-color: #fefefe;

            margin: 1% auto;

            padding: 0;

            border: none;

            border-radius: 12px;

            width: 95%;

            max-width: 900px;

            max-height: 95vh;

            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);

            animation: modalSlideIn 0.3s ease-out;

            position: relative;

            display: flex;

            flex-direction: column;

            overflow: hidden;

        }



        @keyframes modalSlideIn {

            from {

                transform: translateY(-50px);

                opacity: 0;

            }

            to {

                transform: translateY(0);

                opacity: 1;

            }

        }



        .modal-header {

            background: linear-gradient(135deg, #5d0e26 0%, #8b1538 100%);

            color: white;

            padding: 1rem 1.5rem;

            border-radius: 12px 12px 0 0;

            display: flex;

            justify-content: space-between;

            align-items: center;

            flex-shrink: 0;

        }



        .modal-header h2 {

            margin: 0;

            font-size: 1.3rem;

            font-weight: 600;

        }



        .close-modal {

            background: none;

            border: none;

            color: white;

            font-size: 2rem;

            cursor: pointer;

            padding: 0;

            width: 30px;

            height: 30px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            transition: background 0.2s;

        }



        .close-modal:hover {

            background: rgba(255, 255, 255, 0.2);

        }



        .modal-body {

            padding: 1.5rem;

            flex: 1;

            overflow: visible;

        }



        .modal-footer {

            padding: 1rem 1.5rem;

            border-top: 1px solid #e9ecef;

            display: flex;

            justify-content: flex-end;

            gap: 1rem;

            flex-shrink: 0;

        }



        .event-form-grid {

            display: grid;

            grid-template-columns: 1fr 1fr 1fr;

            gap: 1rem;

            height: 100%;

        }



        .form-group.full-width {

            grid-column: 1 / -1;

        }



        .form-group {

            margin-bottom: 0.75rem;

        }



        .form-group label {

            margin-bottom: 0.25rem;

            font-size: 0.9rem;

        }



        .form-group input,

        .form-group select,

        .form-group textarea {

            padding: 0.5rem;

            font-size: 0.85rem;

        }



        .form-group textarea {

            height: 60px;

            resize: none;

        }



        .form-group label {

            display: block;

            margin-bottom: 0.5rem;

            font-weight: 500;

            color: #333;

        }



        .form-group input,

        .form-group select,

        .form-group textarea {

            width: 100%;

            padding: 0.75rem;

            border: 1px solid #ced4da;

            border-radius: 6px;

            font-size: 0.9rem;

            transition: border-color 0.2s, box-shadow 0.2s;

        }



        .form-group input:focus,

        .form-group select:focus,

        .form-group textarea:focus {

            border-color: #5d0e26;

            outline: none;

            box-shadow: 0 0 0 0.2rem rgba(93, 14, 38, 0.25);

        }



        .form-group small {

            display: block;

            margin-top: 0.25rem;

        }



        .form-group {

            margin-bottom: 1rem;

        }



        /* Event Details Modal Styles */

        .event-overview {

            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);

            border-radius: 12px;

            padding: 1.5rem;

            margin-bottom: 2rem;

            text-align: center;

        }



        .event-type-display {

            margin-bottom: 1rem;

        }



        .type-badge {

            background: linear-gradient(135deg, #5d0e26 0%, #8b1538 100%);

            color: white;

            padding: 0.5rem 1.5rem;

            border-radius: 25px;

            font-weight: 600;

            font-size: 1.1rem;

            text-transform: uppercase;

            letter-spacing: 0.5px;

        }



        .event-datetime {

            display: flex;

            justify-content: center;

            gap: 2rem;

        }



        .date-display,

        .time-display {

            padding: 1rem 1.5rem;

            border-radius: 8px;

            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);

            font-weight: 600;

            color: #333;

        }



        /* Date Display - Light Green Background */

        .date-display {

            background: linear-gradient(135deg, rgba(76, 175, 80, 0.15) 0%, rgba(76, 175, 80, 0.25) 100%);

            border: 1px solid rgba(76, 175, 80, 0.3);

            border-left: 4px solid #4caf50;

        }



        /* Time Display - Light Peach/Orange Background */

        .time-display {

            background: linear-gradient(135deg, rgba(255, 193, 7, 0.15) 0%, rgba(255, 193, 7, 0.25) 100%);

            border: 1px solid rgba(255, 193, 7, 0.3);

            border-left: 4px solid #ffc107;

        }



        .event-details-grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 2rem;

        }



        .detail-section h3 {

            color: #5d0e26;

            font-size: 1.2rem;

            font-weight: 600;

            margin-bottom: 1rem;

            display: flex;

            align-items: center;

            gap: 0.5rem;

        }



        .detail-item {

            display: flex;

            justify-content: space-between;

            align-items: center;

            padding: 0.75rem 0;

            border-bottom: 1px solid #f0f0f0;

        }



        .detail-item:last-child {

            border-bottom: none;

        }



        .detail-label {

            font-weight: 500;

            color: #666;

            display: flex;

            align-items: center;

            gap: 0.5rem;

        }



        .detail-value {

            font-weight: 600;

            color: #333;

            text-align: right;

            max-width: 200px;

            word-wrap: break-word;

        }



        .btn-close-modal {

            background: #6c757d;

            color: white;

            border: none;

            padding: 0.75rem 1.5rem;

            border-radius: 6px;

            font-weight: 500;

            cursor: pointer;

            transition: background 0.2s;

        }



        .btn-close-modal:hover {

            background: #5a6268;

        }



        .btn-sm {

            padding: 0.5rem 1rem;

            font-size: 0.85rem;

        }



        /* Responsive Modal */

        @media (max-width: 1200px) {

            .event-form-grid {

                grid-template-columns: 1fr 1fr;

            }

            

            .add-event-modal .event-form-grid {

                grid-template-columns: 1fr 1fr;

            }

            

            .modal-content {

                max-width: 800px;

                width: 90%;

            }

        }

        

        @media (max-width: 768px) {

            .event-form-grid {

                grid-template-columns: 1fr;

            }

            

            .event-details-grid {

                grid-template-columns: 1fr;

                gap: 1rem;

            }

            

            .event-datetime {

                flex-direction: column;

                gap: 1rem;

            }

            

            .modal-content {

                width: 95%;

                margin: 5% auto;

                max-width: 95vw;

            }

        }



        /* Ensure modal is above header */

        .header {

            z-index: 100;

        }



        .sidebar {

            z-index: 100;

        }



        /* Force modal to be on top */

        .modal {

            z-index: 99999 !important;

        }



        .modal-content {

            z-index: 100000 !important;

        }



        /* Add Event Modal Specific Styles */

        .add-event-modal {

            max-height: 90vh;

            overflow-y: auto;

        }



        .add-event-modal .modal-body {

            padding: 1.5rem;

            flex: 1;

            overflow-y: auto;

        }



        .add-event-modal .event-form-grid {

            display: grid;

            grid-template-columns: 1fr 1fr 1fr;

            gap: 1rem;

            height: auto;

        }



        .add-event-modal .form-group {

            margin-bottom: 1rem;

        }



        .add-event-modal .form-group.full-width {

            grid-column: 1 / -1;

        }



        .add-event-modal .form-group label {

            display: block;

            margin-bottom: 0.5rem;

            font-weight: 500;

            color: #333;

        }



        .add-event-modal .form-group input,

        .add-event-modal .form-group select,

        .add-event-modal .form-group textarea {

            width: 100%;

            padding: 0.75rem;

            border: 1px solid #ced4da;

            border-radius: 6px;

            font-size: 0.9rem;

            transition: border-color 0.2s, box-shadow 0.2s;

        }



        .add-event-modal .form-group input:focus,

        .add-event-modal .form-group select:focus,

        .add-event-modal .form-group textarea:focus {

            border-color: #5d0e26;

            outline: none;

            box-shadow: 0 0 0 0.2rem rgba(93, 14, 38, 0.25);

        }



        .add-event-modal .form-group textarea {

            height: 80px;

            resize: vertical;

            min-height: 60px;

        }



        .add-event-modal .form-group small {

            display: block;

            margin-top: 0.25rem;

            color: #666;

            font-style: italic;

        }



        .add-event-modal .modal-footer {

            padding: 1rem 1.5rem;

            border-top: 1px solid #e9ecef;

            display: flex;

            justify-content: flex-end;

            gap: 1rem;

            flex-shrink: 0;

        }



        .add-event-modal .btn {

            padding: 0.75rem 1.5rem;

            border: none;

            border-radius: 6px;

            font-size: 0.9rem;

            font-weight: 500;

            cursor: pointer;

            transition: all 0.2s;

        }



        .add-event-modal .btn-secondary {

            background: #6c757d;

            color: white;

        }



        .add-event-modal .btn-primary {

            background: #5d0e26;

            color: white;

        }



        .add-event-modal .btn:hover {

            transform: translateY(-1px);

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);

        }



        .add-event-modal .btn:active {

            transform: translateY(0);

        }



        /* Notification animation */

        @keyframes slideIn {

            from {

                transform: translateX(100%);

                opacity: 0;

            }

            to {

                transform: translateX(0);

                opacity: 1;

            }

        }



        /* Notification Styles */

        .notification {

            position: fixed;

            top: 20px;

            right: 20px;

            z-index: 9999;

            animation: slideIn 0.3s ease-out;

        }



        .notification-content {

            display: flex;

            align-items: center;

            gap: 1rem;

            padding: 1rem 1.5rem;

            border-radius: 8px;

            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);

            min-width: 300px;

        }



        .notification-success {

            background: #d4edda;

            border: 1px solid #c3e6cb;

            color: #155724;

        }



        .notification-error {

            background: #f8d7da;

            border: 1px solid #f5c6cb;

            color: #721c24;

        }



        .notification-info {

            background: #d1ecf1;

            border: 1px solid #bee5eb;

            color: #0c5460;

        }



        .notification-warning {

            background: #fff3cd;

            border: 1px solid #ffeaa7;

            color: #856404;

        }



        .notification-message {

            flex: 1;

            font-weight: 500;

        }



        .notification-close {

            background: none;

            border: none;

            font-size: 1.5rem;

            cursor: pointer;

            color: inherit;

            opacity: 0.7;

            transition: opacity 0.2s;

        }



        .notification-close:hover {

            opacity: 1;

        }











        /* Responsive Design */

        @media (max-width: 1200px) {

            .events-grid {

                grid-template-columns: repeat(3, 1fr);

            }

        }



        @media (max-width: 900px) {

            .events-grid {

                grid-template-columns: repeat(2, 1fr);

            }

        }



        @media (max-width: 768px) {

            .events-grid {

                grid-template-columns: 1fr;

            }

            

            .upcoming-events-section {

                padding: 1rem;

            }

            

            .section-header h2 {

                font-size: 1.5rem;

            }

        }

    </style>



    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <script src="assets/js/attorney-colors.js"></script>
    <script>

        console.log('Script loaded - waiting for DOM...');

        

        // Global calendar variable

        let calendar;

        

        document.addEventListener('DOMContentLoaded', function() {

            var calendarEl = document.getElementById('calendar');

            var events = <?php echo json_encode($js_events); ?>;

            calendar = new FullCalendar.Calendar(calendarEl, {

                initialView: 'dayGridMonth',

                                 height: 650,

                headerToolbar: {

                    left: 'prev,next today',

                    center: 'title',

                    right: 'dayGridMonth,timeGridWeek,timeGridDay'

                },

                events: events,

                eventDidMount: function(info) {

                    // Add data attributes for color coding

                    if (info.event.extendedProps.eventType) {

                        info.el.setAttribute('data-event-type', info.event.extendedProps.eventType);

                    }

                    

                    // Apply attorney-based color coding using the helper function
                    if (info.event.extendedProps.attorneyName) {

                        const attorneyName = info.event.extendedProps.attorneyName;

                        const userType = info.event.extendedProps.attorneyUserType || 'attorney';
                        
                        // Use the centralized color system
                        applyAttorneyColors(info.el, attorneyName, userType);
                    }

                }

            });

            calendar.render();











        });

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

        // Update event status
        async function updateEventStatus(selectElement) {

            const eventId = selectElement.dataset.eventId;

            const newStatus = selectElement.value;

            const currentStatus = selectElement.dataset.previousValue || 'Scheduled';

            

            console.log('🔄 Status Update Request:', {

                eventId: eventId,

                currentStatus: currentStatus,

                newStatus: newStatus,

                userType: '<?= $_SESSION['user_type'] ?? 'unknown' ?>',

                userId: '<?= $_SESSION['user_id'] ?? 'unknown' ?>'

            });

            

            // Show enhanced confirmation with warnings based on status
            let confirmMessage = '';
            
            switch(newStatus.toLowerCase()) {
                case 'completed':
                    confirmMessage = `⚠️ WARNING: Mark this event as COMPLETED?\n\nThis action will:\n• Mark the event as finished\n• Update the event history\n• Cannot be easily undone\n\nAre you sure you want to proceed?`;
                    break;
                case 'rescheduled':
                    confirmMessage = `🔄 WARNING: Mark this event as RESCHEDULED?\n\nThis action will:\n• Indicate event was postponed\n• Requires new date/time setup\n• May affect other schedules\n\nAre you sure you want to proceed?`;
                    break;
                case 'cancelled':
                    confirmMessage = `🚫 WARNING: CANCEL this event?\n\nThis action will:\n• Permanently cancel the event\n• May affect case progress\n• Requires immediate rescheduling\n\nAre you sure you want to proceed?`;
                    break;
                default:
                    confirmMessage = `ℹ️ Update event status to "${newStatus.toUpperCase()}"?\n\nThis will change the event status in the system.`;
            }
            
            // Show enhanced confirmation with typing requirement
            const confirmed = await showTypingConfirmation(confirmMessage, newStatus);
            if (!confirmed) {
                selectElement.value = currentStatus;
                return;
            }

            

            // Store current value for future reference

            selectElement.dataset.previousValue = newStatus;

            

            // Proceed with status update

            const formData = new FormData();

            formData.append('event_id', eventId);

            formData.append('new_status', newStatus);

            formData.append('action', 'update_status');

            

            console.log('📤 Sending status update request...');

            

            fetch('update_event_status.php', {

                method: 'POST',

                body: formData

            })

            .then(response => {

                console.log('📥 Response received:', response);

                return response.json();

            })

            .then(data => {

                console.log('📊 Response data:', data);

                if (data.success) {

                    showNotification(`✅ Event status updated to ${newStatus} successfully!`, 'success');

                    // Update the select styling

                    updateStatusSelectStyling(selectElement, newStatus);

                    // Force refresh after successful update

                    setTimeout(() => {

                        location.reload();

                    }, 1500);

                } else {

                    showNotification(`❌ Failed to update status: ${data.message}`, 'error');

                    // Reset to previous value on error

                    selectElement.value = currentStatus;

                }

            })

            .catch(error => {

                console.error('❌ Error updating event status:', error);

                showNotification('❌ Error occurred while updating status', 'error');

                // Reset to previous value on error

                selectElement.value = currentStatus;

            });

        }







        // Update status select styling

        function updateStatusSelectStyling(selectElement, status) {

            // Remove all status-specific classes

            selectElement.classList.remove('status-scheduled', 'status-completed', 'status-cancelled', 'status-rescheduled');

            

            // Add appropriate class

            selectElement.classList.add(`status-${status.toLowerCase()}`);

        }













        

        // Initialize calendar view options

        function initializeViewOptions() {

            const viewButtons = document.querySelectorAll('.view-options .btn');

            

            viewButtons.forEach(button => {

                button.addEventListener('click', function() {

                    const view = this.dataset.view;

                    

                    // Remove active class from all buttons

                    viewButtons.forEach(btn => btn.classList.remove('active'));

                    

                    // Add active class to clicked button

                    this.classList.add('active');

                    

                    // Change calendar view

                    if (calendar) {

                        switch(view) {

                            case 'month':

                                calendar.changeView('dayGridMonth');

                                break;

                            case 'week':

                                calendar.changeView('timeGridWeek');

                                break;

                            case 'day':

                                calendar.changeView('timeGridDay');

                                break;

                        }

                    }

                });

            });

        }

        

        // Initialize status select previous values

        function initializeStatusSelects() {

            const statusSelects = document.querySelectorAll('.status-select');

            statusSelects.forEach(select => {

                // Set previous value for change detection

                select.dataset.previousValue = select.value;

            });

        }



        // Add Event Modal Functionality

        document.addEventListener('DOMContentLoaded', function() {

            console.log('DOM Content Loaded - Initializing modals...');

            

            // Initialize calendar view options

            initializeViewOptions();

            

            const addEventBtn = document.getElementById('addEventBtn');

            const addEventModal = document.getElementById('addEventModal');

            const closeModal = document.querySelector('.close-modal');

            const cancelEvent = document.getElementById('cancelEvent');



            console.log('Add Event Button:', addEventBtn);

            console.log('Add Event Modal:', addEventModal);



            if (addEventBtn && addEventModal) {

                addEventBtn.onclick = function() {

                    console.log('Add Event button clicked!');

                    addEventModal.style.display = "block";

                    document.body.style.overflow = 'hidden'; // Prevent background scrolling

                    

                    // Set minimum date to today

                    const today = new Date().toISOString().split('T')[0];

                    const dateField = document.getElementById('eventDate');

                    if (dateField) {

                        dateField.min = today;

                        dateField.value = today;

                    }

                    

                    // Suggest Cabuyao as starting point for location

                    const locationField = document.getElementById('eventLocation');

                    if (locationField && !locationField.value.trim()) {

                        locationField.value = 'Cabuyao';

                    }

                }



                if (closeModal) {

                    closeModal.onclick = function() {

                        console.log('Close modal clicked');

                        addEventModal.style.display = "none";

                        document.body.style.overflow = 'auto';

                    }

                }



                if (cancelEvent) {

                    cancelEvent.onclick = function() {

                        console.log('Cancel event clicked');

                        addEventModal.style.display = "none";

                        document.body.style.overflow = 'auto';

                    }

                }

            } else {

                console.error('Modal elements not found!');

            }



            // Close modal when clicking outside - REMOVED to prevent accidental closing
            // window.onclick = function(event) {
            //     if (event.target == addEventModal) {
            //         addEventModal.style.display = "none";
            //         document.body.style.overflow = 'auto';
            //     }
            //     const eventInfoModal = document.getElementById('eventInfoModal');
            //     if (event.target == eventInfoModal) {
            //         eventInfoModal.style.display = "none";
            //         document.body.style.overflow = 'auto';
            //     }
            //     const editEventModal = document.getElementById('editEventModal');
            //     if (event.target == editEventModal) {
            //         editEventModal.style.display = "none";
            //         document.body.style.overflow = 'auto';
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

                

                fetch('admin_schedule.php', { method: 'POST', body: fd })

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



            // Initialize edit event functionality

            initializeEditEventHandlers();

            

            // Initialize view details functionality

            initializeViewDetailsHandlers();

            

            // Initialize status selects

            initializeStatusSelects();

            

            // Debug: Check if Save Event button exists

            const saveBtn = document.getElementById('saveEvent');

            if (saveBtn) {

                console.log('✅ Save Event button found and initialized');

            } else {

                console.error('❌ Save Event button not found!');

            }

        });



        // Initialize edit event handlers

        function initializeEditEventHandlers() {

            console.log('Initializing edit event handlers...');

            

            document.querySelectorAll('.edit-event-btn').forEach(function(btn) {

                btn.addEventListener('click', function(e) {

                    e.preventDefault();

                    e.stopPropagation();

                    

                    console.log('Edit button clicked for event:', this.dataset.eventId);

                    

                    const eventId = this.dataset.eventId;

                    const eventType = this.dataset.type;

                    const eventDate = this.dataset.date;

                    const eventTime = this.dataset.time;

                    const eventLocation = this.dataset.location;

                    const eventDescription = this.dataset.description;

                    

                    // Populate edit modal

                    document.getElementById('editEventId').value = eventId;

                    document.getElementById('editEventTitle').value = eventType;

                    document.getElementById('editEventDate').value = eventDate;

                    document.getElementById('editEventTime').value = eventTime;

                    document.getElementById('editEventLocation').value = eventLocation;

                    document.getElementById('editEventType').value = eventType;

                    document.getElementById('editEventDescription').value = eventDescription;

                    

                    // Show edit modal

                    document.getElementById('editEventModal').style.display = "block";

                });

            });



            // Edit modal close functionality

            const cancelEditEvent = document.getElementById('cancelEditEvent');

            if (cancelEditEvent) {

                cancelEditEvent.addEventListener('click', function() {

                    console.log('Cancel edit clicked');

                    document.getElementById('editEventModal').style.display = "none";

                });

            }



            // Save edit functionality

            const saveEditEvent = document.getElementById('saveEditEvent');

            if (saveEditEvent) {

                saveEditEvent.addEventListener('click', async function() {

                    console.log('Save edit clicked');

                    // Show enhanced confirmation with typing requirement
                    const confirmMessage = `⚠️ WARNING: Save changes to this event?\n\nThis action will:\n• Update the event details\n• Modify the schedule\n• Cannot be easily undone\n\nAre you sure you want to proceed?`;
                    
                    const confirmed = await showTypingConfirmation(confirmMessage, 'EDIT');
                    if (!confirmed) {
                        return;
                    }

                    const formData = new FormData(document.getElementById('editEventForm'));

                    formData.append('action', 'edit_event');

                    

                    fetch('update_event_details.php', { method: 'POST', body: formData })

                        .then(r => r.json()).then(data => {

                            if (data.success) {

                                showNotification('✅ Event updated successfully!', 'success');

                                setTimeout(() => location.reload(), 1500);

                            } else {

                                showNotification('❌ Error updating event: ' + data.message, 'error');

                            }

                        })

                        .catch(error => {

                            console.error('Error:', error);

                            showNotification('❌ Error updating event. Please try again.', 'error');

                        });

                });

            }

        }



        // Initialize view details handlers

        function initializeViewDetailsHandlers() {

            console.log('Initializing view details handlers...');

            

            document.querySelectorAll('.view-details-btn').forEach(function(btn) {

                btn.addEventListener('click', function(e) {

                    e.preventDefault();

                    e.stopPropagation();

                    

                    console.log('View details button clicked for event:', this.dataset.eventId);

                    

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

            const closeEventInfoModal = document.getElementById('closeEventInfoModal');

            if (closeEventInfoModal) {

                closeEventInfoModal.addEventListener('click', function() {

                    console.log('Close event info modal clicked');

                    document.getElementById('eventInfoModal').style.display = "none";

                });

            }

        }



        // Update user dropdown based on selected user type

        function updateUserDropdown() {

            const userTypeSelect = document.getElementById('selectedUserType');

            const userIdSelect = document.getElementById('selectedUserId');

            

            if (userTypeSelect && userIdSelect) {

                const selectedType = userTypeSelect.value;

                userIdSelect.innerHTML = '<option value="">Select User</option>';

                userIdSelect.disabled = !selectedType;

                

                if (selectedType === 'attorney') {

                    // Add attorneys

                    <?php foreach ($attorneys as $attorney): ?>

                    userIdSelect.innerHTML += '<option value="<?= $attorney['id'] ?>"><?= htmlspecialchars($attorney['name']) ?> (<?= htmlspecialchars($attorney['email']) ?>)</option>';

                    <?php endforeach; ?>

                } else if (selectedType === 'admin') {

                    // Add admins (including current admin)

                    userIdSelect.innerHTML += '<option value="<?= $admin_id ?>"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?> (Current Admin)</option>';

                }

            }

        }

        

















        // Show notification function

        function showNotification(message, type = 'info') {

            console.log('Showing notification:', message, type);

            

            // Remove existing notifications

            const existingNotifications = document.querySelectorAll('.notification');

            existingNotifications.forEach(notification => notification.remove());

            

            // Create notification element

            const notification = document.createElement('div');

            notification.className = `notification notification-${type}`;

            notification.innerHTML = `

                <div class="notification-content">

                    <span class="notification-message">${message}</span>

                    <button class="notification-close" onclick="this.parentElement.parentElement.remove()">&times;</button>

                </div>

            `;

            

            // Add to page

            document.body.appendChild(notification);

            

            // Auto-remove after 5 seconds

            setTimeout(() => {

                if (notification.parentElement) {

                    notification.remove();

                }

            }, 5000);

        }





    </script>

</body>

</html> 

