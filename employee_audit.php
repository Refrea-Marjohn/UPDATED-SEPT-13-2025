<?php
session_start();
if (!isset($_SESSION['employee_name']) || $_SESSION['user_type'] !== 'employee') {
    header('Location: login_form.php');
    exit();
}
require_once 'config.php';
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

// Get audit data for employee documents
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employee_document_activity WHERE DATE(timestamp) = CURDATE()");
$stmt->execute();
$today_activities = $stmt->get_result()->fetch_assoc()['count'];
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employee_documents");
$stmt->execute();
$total_documents = $stmt->get_result()->fetch_assoc()['count'];
$stmt = $conn->prepare("SELECT * FROM employee_document_activity ORDER BY timestamp DESC LIMIT 10");
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            <li><a href="employee_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="employee_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="employee_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>
            <li><a href="employee_schedule.php"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>
            <li><a href="employee_clients.php"><i class="fas fa-users"></i><span>Client Management</span></a></li>
            <li><a href="employee_request_management.php"><i class="fas fa-clipboard-check"></i><span>Request Review</span></a></li>
            <li><a href="employee_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="employee_audit.php" class="active"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>Audit Trail</h1>
                <p>Track your document activities and actions</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Employee" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['employee_name']; ?></h3>
                    <p>Employee</p>
                </div>
            </div>
        </div>

        <!-- Audit Trail Dashboard -->
        <div class="dashboard-section" style="margin-bottom: 30px;">
            <h1>Audit Trail Dashboard</h1>
            <p>Overview of your document activities and actions.</p>
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-icon">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div class="card-info">
                        <h3>Today's Activities</h3>
                        <p><?= $today_activities ?></p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-icon">
                        <i class="fas fa-file-edit"></i>
                    </div>
                    <div class="card-info">
                        <h3>Total Documents</h3>
                        <p><?= $total_documents ?></p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-icon">
                        <i class="fas fa-upload"></i>
                    </div>
                    <div class="card-info">
                        <h3>Uploads Today</h3>
                        <p><?php 
                            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employee_document_activity WHERE action = 'Uploaded' AND DATE(timestamp) = CURDATE()");
                            $stmt->execute();
                            echo $stmt->get_result()->fetch_assoc()['count'];
                        ?></p>
                    </div>
                </div>
                <div class="card">
                    <div class="card-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="card-info">
                        <h3>Edits Today</h3>
                        <p><?= $conn->query("SELECT COUNT(*) as count FROM employee_document_activity WHERE action = 'Edited' AND DATE(timestamp) = CURDATE()")->fetch_assoc()['count'] ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="audit-section">
            <h2>Recent Activities</h2>
            <div class="activity-list">
                <?php if (empty($recent_activities)): ?>
                    <div class="no-activities">
                        <i class="fas fa-info-circle"></i>
                        <p>No recent activities found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <?php
                                $icon = 'fas fa-file';
                                $color = '#1976d2';
                                switch ($activity['action']) {
                                    case 'Uploaded':
                                        $icon = 'fas fa-upload';
                                        $color = '#4caf50';
                                        break;
                                    case 'Edited':
                                        $icon = 'fas fa-edit';
                                        $color = '#ff9800';
                                        break;
                                    case 'Deleted':
                                        $icon = 'fas fa-trash';
                                        $color = '#f44336';
                                        break;
                                    case 'Downloaded':
                                        $icon = 'fas fa-download';
                                        $color = '#2196f3';
                                        break;
                                }
                                ?>
                                <i class="<?= $icon ?>" style="color: <?= $color ?>;"></i>
                            </div>
                            <div class="activity-details">
                                <h4><?= htmlspecialchars($activity['action']) ?> - <?= htmlspecialchars($activity['file_name']) ?></h4>
                                <p><strong>User:</strong> <?= htmlspecialchars($activity['user_name']) ?></p>
                                <p><strong>Form Number:</strong> <?= htmlspecialchars($activity['form_number'] ?? 'N/A') ?></p>
                                <p><strong>Time:</strong> <?= date('M d, Y H:i:s', strtotime($activity['timestamp'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
        .dashboard-section {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            background: #1976d2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card-icon i {
            color: white;
            font-size: 20px;
        }
        
        .card-info h3 {
            margin: 0;
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        
        .card-info p {
            margin: 5px 0 0 0;
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }
        
        .audit-section {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            padding: 24px;
        }
        
        .audit-section h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .activity-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .activity-details h4 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 16px;
        }
        
        .activity-details p {
            margin: 4px 0;
            color: #666;
            font-size: 14px;
        }
        
        .no-activities {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .no-activities i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ccc;
        }
        
        @media (max-width: 768px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .activity-item {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</body>
</html> 