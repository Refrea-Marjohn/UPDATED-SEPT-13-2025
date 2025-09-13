<?php
session_start();
if (!isset($_SESSION['attorney_name']) || $_SESSION['user_type'] !== 'attorney') {
    header('Location: login_form.php');
    exit();
}
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
        $profile_image = 'images/default-avatar.jpg';
    }
// Total cases handled
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE attorney_id=?");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$total_cases = $stmt->get_result()->fetch_row()[0];
// Total documents uploaded
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_documents WHERE uploaded_by=? AND uploaded_by IS NOT NULL AND uploaded_by > 0");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$total_documents = $stmt->get_result()->fetch_row()[0];
// Total clients
$stmt = $conn->prepare("SELECT uf.id FROM user_form uf WHERE uf.user_type='client' AND uf.id IN (SELECT client_id FROM attorney_cases WHERE attorney_id=?)");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$clients_res = $stmt->get_result();
$total_clients = $clients_res ? $clients_res->num_rows : 0;
// Upcoming hearings (next 7 days)
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));
$stmt = $conn->prepare("SELECT COUNT(*) FROM case_schedules WHERE attorney_id=? AND date BETWEEN ? AND ? AND type IN ('Hearing','Appointment')");
$stmt->bind_param("iss", $attorney_id, $today, $next_week);
$stmt->execute();
$upcoming_events = $stmt->get_result()->fetch_row()[0];
// Case status distribution for this attorney
$status_counts = [];
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM attorney_cases WHERE attorney_id=? GROUP BY status");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status_counts[$row['status'] ?? 'Unknown'] = (int)$row['cnt'];
}
// Upcoming hearings table (next 5)
$hearings = [];
$stmt = $conn->prepare("SELECT cs.*, ac.title as case_title, uf.name as client_name FROM case_schedules cs LEFT JOIN attorney_cases ac ON cs.case_id = ac.id LEFT JOIN user_form uf ON cs.client_id = uf.id WHERE cs.attorney_id=? AND cs.date >= ? ORDER BY cs.date, cs.time LIMIT 5");
$stmt->bind_param("is", $attorney_id, $today);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $hearings[] = $row;
// Recent activity (last 5): cases, documents, messages, hearings
$recent = [];
// Cases
$stmt = $conn->prepare("SELECT id, title, created_at FROM attorney_cases WHERE attorney_id=? ORDER BY created_at DESC LIMIT 2");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recent[] = ['type'=>'Case','title'=>$row['title'],'date'=>$row['created_at']];
// Documents
$stmt = $conn->prepare("SELECT file_name, upload_date FROM attorney_documents WHERE uploaded_by=? ORDER BY upload_date DESC LIMIT 2");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recent[] = ['type'=>'Document','title'=>$row['file_name'],'date'=>$row['upload_date']];
// Messages
$stmt = $conn->prepare("SELECT message, sent_at FROM attorney_messages WHERE attorney_id=? ORDER BY sent_at DESC LIMIT 1");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recent[] = ['type'=>'Message','title'=>mb_strimwidth($row['message'],0,30,'...'),'date'=>$row['sent_at']];
// Hearings
$stmt = $conn->prepare("SELECT title, date, time FROM case_schedules WHERE attorney_id=? ORDER BY date DESC, time DESC LIMIT 1");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recent[] = ['type'=>'Hearing','title'=>$row['title'],'date'=>$row['date'].' '.$row['time']];
// Sort by date desc
usort($recent, function($a,$b){ return strtotime($b['date'])-strtotime($a['date']); });
$recent = array_slice($recent,0,5);
// Cases per month (bar chart)
$cases_per_month = array_fill(1,12,0);
$year = date('Y');
$stmt = $conn->prepare("SELECT MONTH(created_at) as m, COUNT(*) as cnt FROM attorney_cases WHERE attorney_id=? AND YEAR(created_at)=? GROUP BY m");
$stmt->bind_param("ii", $attorney_id, $year);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $cases_per_month[(int)$row['m']] = (int)$row['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attorney Dashboard - Opiña Law Office</title>
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
            <li><a href="attorney_dashboard.php" class="active"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="attorney_cases.php"><i class="fas fa-gavel"></i><span>Manage Cases</span></a></li>
            <li><a href="attorney_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="attorney_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generation</span></a></li>
            <li><a href="attorney_schedule.php"><i class="fas fa-calendar-alt"></i><span>My Schedule</span></a></li>
            <li><a href="attorney_clients.php"><i class="fas fa-users"></i><span>My Clients</span></a></li>
            <li><a href="attorney_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="attorney_efiling.php"><i class="fas fa-paper-plane"></i><span>E-Filing</span></a></li>
            <li><a href="attorney_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>
    <div class="main-content">
        <?php 
        $page_title = 'Attorney Dashboard';
        $page_subtitle = 'Overview of your cases, clients, and schedule';
        include 'components/profile_header.php'; 
        ?>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="attorney_cases.php" class="btn">
                <i class="fas fa-plus"></i>
                New Case
            </a>
            <a href="attorney_documents.php" class="btn">
                <i class="fas fa-upload"></i>
                Upload Document
            </a>
            <a href="attorney_schedule.php" class="btn">
                <i class="fas fa-calendar-plus"></i>
                Schedule Meeting
            </a>
            <a href="attorney_messages.php" class="btn">
                <i class="fas fa-comment"></i>
                Send Message
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
                <div class="card-title">Total Cases Handled</div>
                <div class="card-value"><?= number_format($total_cases) ?></div>
                <div class="card-description">Cases you are handling</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
                <div class="card-title">Total Documents</div>
                <div class="card-value"><?= number_format($total_documents) ?></div>
                <div class="card-description">Your uploaded documents</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="card-title">Total Clients</div>
                <div class="card-value"><?= number_format($total_clients) ?></div>
                <div class="card-description">Unique clients you handle</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="card-title">Upcoming Events</div>
                <div class="card-value"><?= number_format($upcoming_events) ?></div>
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
                <h3><i class="fas fa-chart-bar"></i> Cases Per Month (<?= $year ?>)</h3>
                <div class="chart-container">
                    <canvas id="casesPerMonthChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Upcoming Hearings & Appointments -->
        <div class="dashboard-graph">
            <h3><i class="fas fa-calendar-alt"></i> Upcoming Hearings & Appointments</h3>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php if (count($hearings) > 0): ?>
                    <table class="upcoming-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Case</th>
                                <th>Client</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hearings as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['date']) ?></td>
                                <td><?= htmlspecialchars(date('h:i A', strtotime($h['time']))) ?></td>
                                <td><?= htmlspecialchars($h['type']) ?></td>
                                <td><?= htmlspecialchars($h['location']) ?></td>
                                <td><?= htmlspecialchars($h['case_title'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($h['client_name'] ?? '-') ?></td>
                                <td><span class="status-badge status-<?= strtolower($h['status'] ?? 'scheduled') ?>"><?= htmlspecialchars($h['status'] ?? '-') ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 16px; opacity: 0.3;"></i>
                        <p>No upcoming hearings or appointments</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="dashboard-graph">
            <h3><i class="fas fa-clock"></i> Recent Activity</h3>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php if (count($recent) > 0): ?>
                    <?php foreach ($recent as $r): ?>
                        <div style="display: flex; align-items: center; padding: 16px; border-bottom: 1px solid #f0f0f0; transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='transparent'">
                            <div style="width: 40px; height: 40px; background: var(--gradient-primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin-right: 16px;">
                                <i class="fas fa-<?= strtolower($r['type']) === 'case' ? 'gavel' : (strtolower($r['type']) === 'document' ? 'file-alt' : (strtolower($r['type']) === 'message' ? 'comment' : 'calendar')) ?>"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: var(--primary-color); margin-bottom: 4px;">
                                    <?= htmlspecialchars($r['title']) ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">
                                    <?= ucfirst($r['type']) ?> • <?= date('M j, Y g:i A', strtotime($r['date'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 16px; opacity: 0.3;"></i>
                        <p>No recent activity</p>
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

        // Cases Per Month Chart
        const ctx2 = document.getElementById('casesPerMonthChart').getContext('2d');
        const monthlyData = {
            labels: ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
            datasets: [{
                label: 'Cases Created',
                data: <?= json_encode(array_values($cases_per_month)) ?>,
                backgroundColor: 'rgba(93, 14, 38, 0.8)',
                borderColor: '#5D0E26',
                borderWidth: 2,
                borderRadius: 4,
                borderSkipped: false
            }]
        };
        
        const casesPerMonthChart = new Chart(ctx2, {
            type: 'bar',
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
                    bar: {
                        borderRadius: 6
                    }
                }
            }
        });

        // Fix chart responsiveness on window resize
        window.addEventListener('resize', function() {
            caseStatusChart.resize();
            casesPerMonthChart.resize();
        });

        // Force chart resize after page load
        setTimeout(function() {
            caseStatusChart.resize();
            casesPerMonthChart.resize();
        }, 100);
    </script>
</body>
</html> 