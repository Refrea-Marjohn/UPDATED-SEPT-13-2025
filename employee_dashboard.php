<?php
session_start();
if (!isset($_SESSION['employee_name']) || $_SESSION['user_type'] !== 'employee') {
    header('Location: login_form.php');
    exit();
}
require_once 'config.php';

// Total cases
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases");
$stmt->execute();
$total_cases = $stmt->get_result()->fetch_row()[0];
// Total documents
$stmt = $conn->prepare("SELECT COUNT(*) FROM employee_documents");
$stmt->execute();
$emp_docs = $stmt->get_result()->fetch_row()[0];
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_documents");
$stmt->execute();
$att_docs = $stmt->get_result()->fetch_row()[0];
$total_documents = $emp_docs + $att_docs;
// Total users
$stmt = $conn->prepare("SELECT COUNT(*) FROM user_form");
$stmt->execute();
$total_users = $stmt->get_result()->fetch_row()[0];
// Upcoming hearings (next 7 days)
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));
$stmt = $conn->prepare("SELECT COUNT(*) FROM case_schedules WHERE date BETWEEN ? AND ?");
$stmt->bind_param("ss", $today, $next_week);
$stmt->execute();
$upcoming_hearings = $stmt->get_result()->fetch_row()[0];

// Case status distribution
$status_counts = [];
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM attorney_cases GROUP BY status");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status_counts[$row['status'] ?? 'Unknown'] = (int)$row['cnt'];
}

// Recent activities
$recent_activities = [];
$stmt = $conn->prepare("SELECT 'case' as type, title, created_at FROM attorney_cases ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $recent_activities[] = ['type' => 'Case', 'title' => $row['title'], 'date' => $row['created_at']];
}

$stmt = $conn->prepare("SELECT 'document' as type, file_name, upload_date FROM employee_documents ORDER BY upload_date DESC LIMIT 2");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $recent_activities[] = ['type' => 'Document', 'title' => $row['file_name'], 'date' => $row['upload_date']];
}

// Sort by date
usort($recent_activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$recent_activities = array_slice($recent_activities, 0, 5);

// Monthly case trends
$monthly_cases = array_fill(1, 12, 0);
$year = date('Y');
$stmt = $conn->prepare("SELECT MONTH(created_at) as month, COUNT(*) as cnt FROM attorney_cases WHERE YEAR(created_at) = ? GROUP BY month");
$stmt->bind_param("i", $year);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $monthly_cases[(int)$row['month']] = (int)$row['cnt'];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="images/logo.jpg" alt="Logo">
            <h2>Opiña Law Office</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="employee_dashboard.php" class="active"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="employee_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="employee_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>
            <li><a href="employee_schedule.php"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>
            <li><a href="employee_clients.php"><i class="fas fa-users"></i><span>Client Management</span></a></li>
            <li><a href="employee_request_management.php"><i class="fas fa-clipboard-check"></i><span>Request Review</span></a></li>
            <li><a href="employee_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="employee_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>
    <div class="main-content">
        <?php 
        $page_title = 'Employee Dashboard';
        $page_subtitle = 'Overview of your work activities and system statistics';
        include 'components/profile_header.php'; 
        ?>
        

        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="employee_document_generation.php" class="btn">
                <i class="fas fa-file-plus"></i>
                Generate Document
            </a>
            <a href="employee_documents.php" class="btn">
                <i class="fas fa-upload"></i>
                Upload Document
            </a>
            <a href="employee_schedule.php" class="btn">
                <i class="fas fa-calendar-plus"></i>
                View Schedule
            </a>
            <a href="employee_clients.php" class="btn">
                <i class="fas fa-user-plus"></i>
                Manage Clients
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                </div>
                <div class="card-title">Total Cases</div>
                <div class="card-value"><?= number_format($total_cases) ?></div>
                <div class="card-description">All cases in the system</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
                <div class="card-title">Total Documents</div>
                <div class="card-value"><?= number_format($total_documents) ?></div>
                <div class="card-description">All documents stored</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="card-title">Total Users</div>
                <div class="card-value"><?= number_format($total_users) ?></div>
                <div class="card-description">Registered users</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="card-title">Upcoming Hearings</div>
                <div class="card-value"><?= number_format($upcoming_hearings) ?></div>
                <div class="card-description">Next 7 days</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px;">
            <div class="dashboard-graph">
                <h3><i class="fas fa-chart-pie"></i> Case Status Distribution</h3>
                <div class="chart-container">
                    <canvas id="caseStatusChart"></canvas>
                </div>
            </div>
            <div class="dashboard-graph">
                <h3><i class="fas fa-chart-line"></i> Monthly Case Trends (<?= $year ?>)</h3>
                <div class="chart-container">
                    <canvas id="monthlyTrendsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="dashboard-graph">
            <h3><i class="fas fa-clock"></i> Recent Activities</h3>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php if (count($recent_activities) > 0): ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div style="display: flex; align-items: center; padding: 16px; border-bottom: 1px solid #f0f0f0; transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='transparent'">
                            <div style="width: 40px; height: 40px; background: var(--gradient-primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-right: 16px;">
                                <i class="fas fa-<?= strtolower($activity['type']) === 'case' ? 'gavel' : 'file-alt' ?>"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: var(--primary-color); margin-bottom: 4px;">
                                    <?= htmlspecialchars($activity['title']) ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">
                                    <?= ucfirst($activity['type']) ?> • <?= date('M j, Y g:i A', strtotime($activity['date'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 16px; opacity: 0.3;"></i>
                        <p>No recent activities</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Case Status Chart
        const ctx = document.getElementById('caseStatusChart').getContext('2d');
        const caseStatusData = {
            labels: <?= json_encode(array_keys($status_counts)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($status_counts)) ?>,
                backgroundColor: [
                    '#5D0E26', '#8B1538', '#8B4A6B', '#27ae60', '#f39c12', '#e74c3c', '#3498db', '#9b59b6'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        };
        
        const caseStatusChart = new Chart(ctx, {
            type: 'doughnut',
            data: caseStatusData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    }
                },
                elements: {
                    arc: {
                        borderWidth: 3
                    }
                }
            }
        });

        // Monthly Trends Chart
        const ctx2 = document.getElementById('monthlyTrendsChart').getContext('2d');
        const monthlyData = {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Cases Created',
                data: <?= json_encode(array_values($monthly_cases)) ?>,
                backgroundColor: 'rgba(93, 14, 38, 0.1)',
                borderColor: '#5D0E26',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#5D0E26',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        };
        
        const monthlyTrendsChart = new Chart(ctx2, {
            type: 'line',
            data: monthlyData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        stepSize: 1,
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    }
                },
                elements: {
                    point: {
                        hoverRadius: 8
                    }
                }
            }
        });

        // Fix chart responsiveness on window resize
        window.addEventListener('resize', function() {
            caseStatusChart.resize();
            monthlyTrendsChart.resize();
        });

        // Force chart resize after page load
        setTimeout(function() {
            caseStatusChart.resize();
            monthlyTrendsChart.resize();
        }, 100);
    </script>
</body>
</html> 