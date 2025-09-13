<?php
require_once 'session_manager.php';
validateUserAccess('client');
require_once 'config.php';
$client_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
}
if (!$profile_image || !file_exists($profile_image)) {
        $profile_image = 'images/default-avatar.jpg';
    }
// Fetch all events for this client
$events = [];
$stmt = $conn->prepare("SELECT cs.*, ac.title as case_title, 
    CASE 
        WHEN cs.attorney_id IS NOT NULL THEN cs.attorney_id 
        ELSE ac.attorney_id 
    END as final_attorney_id,
    uf.name as attorney_name 
    FROM case_schedules cs 
    LEFT JOIN attorney_cases ac ON cs.case_id = ac.id 
    LEFT JOIN user_form uf ON (
        CASE 
            WHEN cs.attorney_id IS NOT NULL THEN cs.attorney_id 
            ELSE ac.attorney_id 
        END
    ) = uf.id 
    WHERE cs.client_id=? 
    ORDER BY cs.date, cs.time");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $events[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <style>
        /* Calendar container styles */
        .calendar-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            min-height: 600px;
            max-height: 700px !important;
            overflow: hidden !important;
        }
        
        #calendar {
            width: 100%;
            height: 100%;
            min-height: 500px;
            overflow: auto !important;
            max-height: 600px !important;
        }
        
        /* Force scrollbar to always show */
        #calendar {
            scrollbar-width: thin !important;
            scrollbar-color: #C29292 #f1f1f1 !important;
        }
        
        /* Calendar scrollbar styling - Webkit browsers */
        #calendar::-webkit-scrollbar {
            width: 16px !important;
            height: 16px !important;
            background-color: #f1f1f1 !important;
        }
        
        #calendar::-webkit-scrollbar-track {
            background: #f1f1f1 !important;
            border-radius: 8px !important;
            border: 1px solid #ddd !important;
        }
        
        #calendar::-webkit-scrollbar-thumb {
            background: #C29292 !important;
            border-radius: 8px !important;
            border: 2px solid #f1f1f1 !important;
            min-height: 40px !important;
        }
        
        #calendar::-webkit-scrollbar-thumb:hover {
            background: #A67A7A !important;
        }
        
        #calendar::-webkit-scrollbar-corner {
            background: #f1f1f1 !important;
        }
        
        /* Force scrollbar visibility - even when no overflow */
        #calendar {
            overflow-y: scroll !important;
            scrollbar-gutter: stable !important;
        }
        
        /* Alternative scrollbar for Firefox */
        #calendar {
            scrollbar-width: auto !important;
        }
        
        /* FullCalendar custom styles */
        .fc {
            font-family: 'Poppins', sans-serif;
        }
        
        .fc-toolbar {
            margin-bottom: 20px;
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
        
        /* FullCalendar Toolbar Button Spacing */
        .fc-toolbar-chunk {
            display: flex;
            gap: 8px;
        }
        
        .fc-toolbar-chunk .fc-button {
            margin: 0 4px !important;
        }
        
        .fc-daygrid-day {
            border: 1px solid #e9ecef;
            min-height: 120px !important;
        }
        
        /* Force month view and proper event display */
        .fc-dayGridMonth-view {
            background: white !important;
        }
        
        .fc-daygrid-day-frame {
            min-height: 120px !important;
            max-height: none !important;
        }
        
        .fc-daygrid-day-events {
            max-height: 150px !important;
            overflow-y: auto !important;
            padding: 2px !important;
        }
        
        .fc-event {
            border-radius: 6px;
            border: 1px solid #C29292 !important;
            background-color: #F0D9DA !important;
            color: #5D0E26 !important;
            font-size: 12px;
            padding: 2px 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .fc-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background-color: #E0C9CA !important;
        }
        
        /* Upcoming Events Section Styles */
        .upcoming-events-section {
            margin: 2rem 0;
        }
        
        .section-header {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-text h2 {
            margin: 0;
            color: #333;
            font-size: 24px;
            font-weight: 600;
        }
        
        .header-text p {
            margin: 5px 0 0 0;
            color: #666;
            font-size: 14px;
        }
        
        /* Events Grid - 3 COLUMNS LAYOUT */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        /* Event Card Styles - WIDE BUT COMPACT HEIGHT */
        .event-card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e3f2fd;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 1px 5px rgba(0, 0, 0, 0.05);
            width: 100%;
            padding: 8px 12px;
            min-height: 120px;
        }
        
        .event-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .event-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }
        
        .event-avatar {
            flex-shrink: 0;
        }
        
        .avatar-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: white;
            background: #2196f3;
        }
        
        .event-info h3 {
            margin: 0 0 3px 0;
            color: #5D0E26;
            font-size: 15px;
            font-weight: 600;
        }
        
        .case-detail, .attorney-detail {
            margin: 1px 0;
            color: #666;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .case-detail i {
            color: #2196f3;
            width: 13px;
        }
        
        .attorney-detail i {
            color: #4caf50;
            width: 13px;
        }
        
        .event-actions {
            padding-top: 4px;
            border-top: 1px solid #e9ecef;
        }
        
        .status-section {
            margin-bottom: 0;
            text-align: left;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.1px;
            display: inline-block;
            background: white;
            border: 1px solid #e9ecef;
            color: #333;
        }
        
        .status-scheduled {
            background: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }
        
        .status-completed {
            background: #e8f5e8;
            color: #2e7d32;
            border-color: #c8e6c9;
        }
        
        .status-rescheduled {
            background: #fff3e0;
            color: #f57c00;
            border-color: #ffe0b2;
        }
        
        .status-cancelled {
            background: #ffebee;
            color: #c62828;
            border-color: #ffcdd2;
        }
        
        .status-upcoming {
            background: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }
        
        .action-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 11px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
            transform: translateY(-1px);
        }
        
        /* No Events Styling */
        .no-events {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .no-events i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        .no-events h3 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 20px;
        }
        
        .no-events p {
            margin: 0;
            color: #999;
            font-size: 14px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .event-card {
                margin: 0 10px;
            }
            
            .action-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .btn-sm {
                width: 100%;
                justify-content: center;
            }
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
            <li>
                <a href="client_dashboard.php">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="client_documents.php">
                    <i class="fas fa-file-alt"></i>
                    <span>Document Generation</span>
                </a>
            </li>
            <li>
                <a href="client_cases.php">
                    <i class="fas fa-gavel"></i>
                    <span>My Cases</span>
                </a>
            </li>
            <li>
                <a href="client_schedule.php" class="active">
                    <i class="fas fa-calendar-alt"></i>
                    <span>My Schedule</span>
                </a>
            </li>
            <li>
                <a href="client_messages.php">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                </a>
            </li>

        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>My Schedule</h1>
                <p>View your upcoming appointments and court hearings</p>
            </div>
            <div class="user-info">
                <div class="profile-dropdown" style="display: flex; align-items: center; gap: 12px;">
                    <img src="<?= htmlspecialchars($profile_image) ?>" alt="Client" style="object-fit:cover;width:42px;height:42px;border-radius:50%;border:2px solid #1976d2;">
                    <div class="user-details">
                        <h3><?php echo $_SESSION['client_name']; ?></h3>
                        <p>Client</p>
                    </div>
                </div>
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
                        <p>View and manage your scheduled appointments and court hearings</p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($events)): ?>
                <div class="no-events">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Upcoming Events</h3>
                    <p>You have no scheduled events at the moment. Your attorney will notify you of any upcoming appointments.</p>
                </div>
            <?php else: ?>
                <!-- All Events in Simple Cards - 3 ROWS LAYOUT -->
                <div class="events-grid">
                    <?php foreach ($events as $ev): ?>
                    <div class="event-card" data-event-id="<?= $ev['id'] ?>" data-event-type="<?= htmlspecialchars($ev['type']) ?>" data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? 'Attorney') ?>">
                        <div class="event-card-header">
                            <div class="event-avatar">
                                <div class="avatar-placeholder">
                                    <?php
                                    // Set icon based on event type
                                    switch($ev['type']) {
                                        case 'Hearing':
                                            echo '<i class="fas fa-gavel"></i>';
                                            break;
                                        case 'Appointment':
                                            echo '<i class="fas fa-calendar-check"></i>';
                                            break;
                                        case 'Free Legal Advice':
                                            echo '<i class="fas fa-question-circle"></i>';
                                            break;
                                        default:
                                            echo '<i class="fas fa-calendar-alt"></i>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="event-info">
                                <h3><?= htmlspecialchars($ev['type']) ?></h3>
                                <p class="case-detail"><i class="fas fa-folder"></i> <?= htmlspecialchars($ev['case_title'] ?? 'No Case') ?></p>
                                <p class="attorney-detail"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($ev['attorney_name'] ?? 'No Attorney') ?></p>
                            </div>
                        </div>

                        <div class="event-actions">
                            <div class="action-buttons">
                                <span class="status-badge status-<?= strtolower($ev['status'] ?? 'scheduled') ?>">
                                    <?= htmlspecialchars($ev['status'] ?? 'Scheduled') ?>
                                </span>
                                <button class="btn btn-info btn-sm view-info-btn" 
                                    data-type="<?= htmlspecialchars($ev['type']) ?>"
                                    data-date="<?= htmlspecialchars($ev['date']) ?>"
                                    data-time="<?= htmlspecialchars($ev['time']) ?>"
                                    data-location="<?= htmlspecialchars($ev['location']) ?>"
                                    data-case="<?= htmlspecialchars($ev['case_title'] ?? '-') ?>"
                                    data-attorney="<?= htmlspecialchars($ev['attorney_name'] ?? '-') ?>"
                                    data-description="<?= htmlspecialchars($ev['description'] ?? '-') ?>">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Event Details Modal -->
        <div class="modal" id="eventModal">
            <div class="modal-content professional-modal">
                <div class="modal-header">
                    <div class="header-content">
                        <div class="header-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="header-text">
                            <h2>Appointment Details</h2>
                            <p>Complete appointment information and case details</p>
                        </div>
                    </div>
                    <button class="close-modal" id="closeModalBtn">&times;</button>

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
                            <h3><i class="fas fa-info-circle"></i> Appointment Information</h3>
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
                                <span class="detail-label"><i class="fas fa-file-alt"></i> Description:</span>
                                <span class="detail-value" id="modalDescription">-</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <style>
        /* Basic Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex !important;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            margin: auto;
            border-radius: 12px;
            position: relative;
        }

        /* Professional Theme Styling */
        .action-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 16px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(93, 14, 38, 0.08);
            margin-bottom: 20px;
        }

        .calendar-views {
            display: flex;
            gap: 20px;
        }

        .calendar-views .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            background: white;
            color: var(--primary-color);
            margin: 0 4px;
            min-width: 80px;
        }

        .calendar-views .btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .calendar-views .btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(93, 14, 38, 0.2);
        }

        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            color: #666;
            font-size: 0.9rem;
        }

        .search-box input {
            padding: 8px 12px 8px 36px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            width: 250px;
            transition: border-color 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(93, 14, 38, 0.1);
        }

        .calendar-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0 40px 0;
            box-shadow: 0 2px 12px rgba(93, 14, 38, 0.08);
            border: 1px solid #f0f0f0;
            overflow: hidden;
        }

        /* Make calendar properly sized and contained */
        #calendar {
            max-height: 600px;
            width: 100%;
            overflow: hidden;
        }

        .fc {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 100%;
        }

        .fc-view-harness {
            overflow: hidden;
        }

        .fc-scroller {
            overflow: hidden !important;
        }

        /* Ensure calendar content stays within bounds */
        .fc-daygrid-day-frame {
            min-height: 80px !important;
            max-height: 100px !important;
        }

        .fc-daygrid-day-events {
            min-height: 0 !important;
            max-height: 80px !important;
            overflow: hidden !important;
        }

        .fc-daygrid-more-link {
            font-size: 0.8rem !important;
            padding: 1px 4px !important;
        }

        /* Fix calendar header spacing */
        .fc-header-toolbar {
            margin-bottom: 16px;
            padding: 0 0 16px 0;
        }

        .fc-toolbar-title {
            font-size: 1.2rem !important;
            font-weight: 600 !important;
            color: var(--primary-color) !important;
        }

        /* Add spacing between navigation and view buttons */
        .fc-prev-button,
        .fc-next-button {
            margin-right: 8px !important;
        }

        .fc-today-button {
            margin-right: 16px !important;
        }

        .fc-dayGridMonth-button,
        .fc-timeGridWeek-button {
            margin-left: 8px !important;
        }

        /* Ensure proper button spacing in header */
        .fc-toolbar-chunk {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .fc-toolbar-chunk:last-child {
            gap: 4px !important;
        }

        .fc {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .fc-header-toolbar {
            margin-bottom: 16px;
        }

        .fc-button {
            background: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            border-radius: 6px !important;
            font-weight: 500 !important;
            padding: 6px 12px !important;
            font-size: 0.9rem !important;
        }

        .fc-button:hover {
            background: var(--secondary-color) !important;
            border-color: var(--secondary-color) !important;
        }

        .fc-button:focus {
            box-shadow: 0 0 0 3px rgba(93, 14, 38, 0.2) !important;
        }

        .fc-daygrid-day {
            min-height: 80px !important;
        }

        .fc-event {
            border-radius: 4px !important;
            font-size: 0.85rem !important;
            padding: 2px 4px !important;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
            box-shadow: 0 2px 12px rgba(93, 14, 38, 0.08);
            border: 1px solid #f0f0f0;
        }

        .table-header {
            margin-bottom: 20px;
        }

        .table-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        th {
            background: #f8f9fa;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.9rem;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        /* Professional Modal Styles */
        .professional-modal {
            max-width: 800px;
            width: 85%;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            margin: 2vh auto;
            max-height: 90vh;
        }

        .professional-modal .modal-header {
            background: linear-gradient(135deg, #5d0e26, #8b1538);
            color: white;
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .professional-modal .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }

        .professional-modal .header-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .professional-modal .header-text h2 {
            margin: 0 0 0.25rem 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .professional-modal .header-text p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }



        .professional-modal .modal-body {
            padding: 1.5rem;
            background: #f8f9fa;
            max-height: 70vh;
            overflow-y: auto;
        }

        .professional-modal .event-overview {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .professional-modal .event-type-display .type-badge {
            background: linear-gradient(135deg, #5d0e26, #8b1538);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .professional-modal .event-datetime {
            display: flex;
            gap: 1rem;
        }

        .professional-modal .date-display,
        .professional-modal .time-display {
            background: #f8f9fa;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            border: 1px solid #e9ecef;
        }

        .professional-modal .event-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .professional-modal .detail-section {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .professional-modal .detail-section h3 {
            margin: 0 0 1rem 0;
            color: #5d0e26;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .professional-modal .detail-section h3 i {
            color: #8b1538;
        }

        .professional-modal .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .professional-modal .detail-item:last-child {
            border-bottom: none;
        }

        .professional-modal .detail-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #666;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .professional-modal .detail-label i {
            color: #8b1538;
            width: 16px;
        }

        .professional-modal .detail-value {
            color: #333;
            font-weight: 600;
            font-size: 0.9rem;
            text-align: right;
            max-width: 60%;
            word-wrap: break-word;
        }

        .professional-modal .close-modal {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            flex-shrink: 0;
            position: relative;
            z-index: 10;
        }

        .professional-modal .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }





        /* Responsive Design */
        @media (max-width: 768px) {
            .professional-modal .event-details-grid {
                grid-template-columns: 1fr;
            }
            
            .professional-modal .event-overview {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .professional-modal .event-datetime {
                justify-content: center;
            }
        }

        /* Laptop Optimized Modal */
        @media (min-width: 1024px) {
            .professional-modal {
                margin: 1vh auto;
                max-height: 85vh;
            }
            
            .professional-modal .modal-body {
                padding: 1.25rem;
                max-height: 65vh;
            }
            
            .professional-modal .event-overview {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .professional-modal .detail-section {
                padding: 1rem;
            }
            

        }

        /* Ultra-wide and Large Screens */
        @media (min-width: 1440px) {
            .professional-modal {
                margin: 0.5vh auto;
                max-height: 80vh;
            }
        }

        .status-upcoming {
            background: var(--primary-color);
            color: white;
        }

        .btn-info {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-info:hover {
            background: var(--secondary-color);
            transform: translateY(-1px);
        }

        .event-info {
            display: grid;
            gap: 20px;
        }

        .info-group {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }

        .info-group h3 {
            margin-bottom: 16px;
            color: var(--primary-color);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .label {
            color: #666;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .value {
            color: var(--text-color);
            font-weight: 500;
            text-align: right;
        }

        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }

            .calendar-views {
                justify-content: center;
            }

            .search-box {
                width: 100%;
            }

            .search-box input {
                width: 100%;
            }

            .info-item {
                flex-direction: column;
                gap: 4px;
                text-align: left;
            }

            .value {
                text-align: left;
            }

            #calendar {
                max-height: 400px;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Replace hardcoded events with PHP-generated events
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 800,
                width: '100%',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    week: 'Week',
                    day: 'Day'
                },
                dayMaxEvents: 3,
                moreLinkClick: 'popover',
                events: [
                    <?php foreach ($events as $ev): ?>
                    {
                        title: <?= json_encode($ev['type'] . ': ' . ($ev['case_title'] ?? '')) ?>,
                        start: '<?= $ev['date'] . 'T' . $ev['time'] ?>',
                        description: <?= json_encode($ev['description'] ?? '') ?>,
                        location: <?= json_encode($ev['location'] ?? '') ?>,
                        case: <?= json_encode($ev['case_title'] ?? '') ?>,
                        attorney: <?= json_encode($ev['attorney_name'] ?? '') ?>,
                        color: '#F0D9DA',
                        textColor: '#5D0E26',
                        borderColor: '#C29292'
                    },
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    // Fill modal with event details
                    document.getElementById('modalEventType').innerText = info.event.title.split(':')[0] || 'Event';
                    document.getElementById('modalEventDate').innerText = info.event.start ? info.event.start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : 'Date';
                    document.getElementById('modalEventTime').innerText = info.event.start ? info.event.start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : 'Time';
                    document.getElementById('modalType').innerText = info.event.title.split(':')[0] || '';
                    document.getElementById('modalDate').innerText = info.event.start ? info.event.start.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '';
                    document.getElementById('modalTime').innerText = info.event.start ? info.event.start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '';
                    document.getElementById('modalLocation').innerText = info.event.extendedProps.location || '';
                    document.getElementById('modalCase').innerText = info.event.extendedProps.case || '';
                    document.getElementById('modalAttorney').innerText = info.event.extendedProps.attorney || '';
                    document.getElementById('modalDescription').innerText = info.event.extendedProps.description || '';
                    document.getElementById('eventModal').classList.add('show');
                }
            });
            calendar.render();
            
            // Force calendar to update and show scrollbar
            setTimeout(function() {
                calendar.updateSize();
                console.log('Calendar updated, scrollbar should be visible');
            }, 100);

            // Calendar view switching
            document.querySelectorAll('.calendar-views .btn').forEach(button => {
                button.addEventListener('click', function() {
                    document.querySelectorAll('.calendar-views .btn').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    const view = this.dataset.view;
                    
                    // Handle different view types
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
                        default:
                            calendar.changeView('dayGridMonth');
                    }
                    
                    // Update button states
                    document.querySelectorAll('.calendar-views .btn').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    this.classList.add('active');
                });
            });

            // Modal functionality
            const modal = document.getElementById('eventModal');


            // Add click event to calendar events
            calendar.on('eventClick', function(info) {
                // Fill modal with event details
                document.getElementById('modalEventType').innerText = info.event.extendedProps.type || info.event.title.split(':')[0] || 'Event';
                document.getElementById('modalEventDate').innerText = info.event.start ? info.event.start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : 'Date';
                document.getElementById('modalEventTime').innerText = info.event.start ? info.event.start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : 'Time';
                document.getElementById('modalType').innerText = info.event.extendedProps.type || info.event.title.split(':')[0] || '';
                document.getElementById('modalDate').innerText = info.event.start ? info.event.start.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '';
                document.getElementById('modalTime').innerText = info.event.start ? info.event.start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '';
                document.getElementById('modalLocation').innerText = info.event.extendedProps.location || '';
                document.getElementById('modalCase').innerText = info.event.extendedProps.case || '';
                document.getElementById('modalAttorney').innerText = info.event.extendedProps.attorney || '';
                document.getElementById('modalDescription').innerText = info.event.extendedProps.description || '';
                document.getElementById('eventModal').classList.add('show');
            });





            // Close modal when clicking outside
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.classList.remove('show');
                }
                
                // Close dropdown when clicking outside
                if (!event.target.matches('img') && !event.target.closest('.profile-dropdown')) {
                    const dropdowns = document.getElementsByClassName('profile-dropdown-content');
                    for (let dropdown of dropdowns) {
                        if (dropdown.classList.contains('show')) {
                            dropdown.classList.remove('show');
                        }
                    }
                }
            }

            // Add event listener to close button
            const closeBtn = document.getElementById('closeModalBtn');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    modal.classList.remove('show');
                    console.log('Modal closed via close button');
                });
            } else {
                console.error('Close button not found!');
            }

            // Populate event table with PHP events
            document.querySelectorAll('.view-info-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    document.getElementById('modalEventType').innerText = this.dataset.type || 'Event';
                    document.getElementById('modalEventDate').innerText = this.dataset.date || 'Date';
                    document.getElementById('modalEventTime').innerText = this.dataset.time ? new Date('1970-01-01T' + this.dataset.time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Time';
                    document.getElementById('modalType').innerText = this.dataset.type || '';
                    document.getElementById('modalDate').innerText = this.dataset.date || '';
                    document.getElementById('modalTime').innerText = this.dataset.time ? new Date('1970-01-01T' + this.dataset.time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                    document.getElementById('modalLocation').innerText = this.dataset.location || '';
                    document.getElementById('modalCase').innerText = this.dataset.case || '';
                    document.getElementById('modalAttorney').innerText = this.dataset.attorney || '';
                    document.getElementById('modalDescription').innerText = this.dataset.description || '';
                    document.getElementById('eventModal').classList.add('show');
                });
            });
        });
        
        // Profile dropdown functions removed - profile is non-clickable on this page
        

    </script>
</body>
</html> 