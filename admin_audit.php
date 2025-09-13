<?php
require_once 'session_manager.php';
validateUserAccess('admin');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'security_monitor.php';
require_once 'action_logger_helper.php';

// Handle Delete All request
if (isset($_GET['delete_all']) && $_GET['delete_all'] == '1') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Delete all records from audit_trail table
            $deleteQuery = "DELETE FROM audit_trail";
            $result = $conn->query($deleteQuery);
            
            if ($result) {
                echo "SUCCESS: All audit trail records deleted";
            } else {
                echo "ERROR: Failed to delete audit trail records";
            }
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage();
        }
        exit;
    }
}

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

// Check if audit_trail table exists
$tableExists = $conn->query("SHOW TABLES LIKE 'audit_trail'")->num_rows > 0;

// Get filters from URL parameters
$userType = $_GET['user_type'] ?? 'all';
$module = $_GET['module'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Apply filters
$filters = [
    'user_type' => $userType,
    'module' => $module,
    'status' => $status,
    'priority' => $priority,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => $search
];

// Get audit trail data and stats if table exists
$auditData = [];
$auditStats = [];
$securityStats = [];
$modules = [];

if ($tableExists) {
    $auditData = $auditLogger->getAuditTrail($filters);
    $auditStats = $auditLogger->getAuditStats();
    $securityStats = getSecurityStatistics();
    
    // Get unique modules for filter
    $modulesQuery = $conn->query("SELECT DISTINCT module FROM audit_trail ORDER BY module");
    while ($row = $modulesQuery->fetch_assoc()) {
        $modules[] = $row['module'];
    }
}
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
    <style>
        .audit-container {
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.admin {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .stat-card.attorney {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .stat-card.employee {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .stat-card.client {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .stat-card.security {
            background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .stat-details {
            margin-top: 10px;
            opacity: 0.8;
            font-size: 0.8rem;
        }
        
        .filters-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .filter-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .btn-filter {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-filter:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        .audit-table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .table-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .table-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn-export {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-export:hover {
            background: #218838;
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .audit-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .audit-table td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        
        .audit-table tr:hover {
            background: #f8f9fa;
        }
        
        .user-type-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .user-type-badge.admin {
            background: #f093fb;
            color: white;
        }
        
        .user-type-badge.attorney {
            background: #4facfe;
            color: white;
        }
        
        .user-type-badge.employee {
            background: #43e97b;
            color: white;
        }
        
        .user-type-badge.client {
            background: #fa709a;
            color: white;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-low {
            background: #d4edda;
            color: #155724;
        }
        
        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .priority-high {
            background: #f8d7da;
            color: #721c24;
        }
        
        .priority-critical {
            background: #721c24;
            color: white;
        }
        
        .module-badge {
            background: #e9ecef;
            color: #495057;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .timestamp {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .no-data {
            text-align: center;
            padding: 50px;
            color: #6c757d;
        }
        
        .no-data i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .setup-notice {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .setup-notice h2 {
            margin-bottom: 15px;
            font-size: 1.8rem;
        }
        
        .setup-notice p {
            margin-bottom: 20px;
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .btn-setup {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            padding: 12px 30px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-setup:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .audit-table {
                font-size: 0.9rem;
            }
            
            .audit-table th,
            .audit-table td {
                padding: 10px 8px;
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
            <li><a href="admin_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="admin_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="admin_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>
            <li><a href="admin_schedule.php"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>
            <li><a href="admin_usermanagement.php"><i class="fas fa-users-cog"></i><span>User Management</span></a></li>
            <li><a href="admin_managecases.php"><i class="fas fa-gavel"></i><span>Case Management</span></a></li>
            <li><a href="admin_clients.php"><i class="fas fa-users"></i><span>My Clients</span></a></li>
            <li><a href="admin_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>
            <li><a href="admin_audit.php" class="active"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1><i class="fas fa-history"></i> Audit Trail</h1>
                <p>Comprehensive tracking of all system activities and user actions</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Admin" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['admin_name']; ?></h3>
                    <p>System Administrator</p>
                </div>
            </div>
        </div>

        <div class="audit-container">
            <?php if (!$tableExists): ?>
                <!-- Setup Notice -->
                <div class="setup-notice">
                    <h2><i class="fas fa-database"></i> Audit Trail Setup Required</h2>
                    <p>The audit trail system needs to be set up in your database first.</p>
                    <p>Please import the updated <code>lawfirm.sql</code> file in phpMyAdmin to create the required tables.</p>
                    <a href="#" onclick="showSetupInstructions()" class="btn-setup">
                        <i class="fas fa-info-circle"></i> View Setup Instructions
                    </a>
                </div>
            <?php else: ?>
                <!-- Statistics Dashboard -->
                <div class="stats-grid">
                    <div class="stat-card admin">
                        <div class="stat-number"><?= $auditStats['by_user_type']['admin'] ?? 0 ?></div>
                        <div class="stat-label">Admin Actions Today</div>
                    </div>
                    <div class="stat-card attorney">
                        <div class="stat-number"><?= $auditStats['by_user_type']['attorney'] ?? 0 ?></div>
                        <div class="stat-label">Attorney Actions Today</div>
                    </div>
                    <div class="stat-card employee">
                        <div class="stat-number"><?= $auditStats['by_user_type']['employee'] ?? 0 ?></div>
                        <div class="stat-label">Employee Actions Today</div>
                    </div>
                    <div class="stat-card client">
                        <div class="stat-number"><?= $auditStats['by_user_type']['client'] ?? 0 ?></div>
                        <div class="stat-label">Client Actions Today</div>
                    </div>
                                    <div class="stat-card security">
                    <div class="stat-number"><?= $securityStats['security_events_today'] ?? 0 ?></div>
                    <div class="stat-label">Security Events Today</div>
                    <div class="stat-details">
                        <small>
                            <?= $securityStats['critical_events_today'] ?? 0 ?> Critical | 
                            <?= $securityStats['blocked_attempts_today'] ?? 0 ?> Blocked | 
                            <?= $securityStats['failed_logins_today'] ?? 0 ?> Failed Logins
                        </small>
                    </div>
                </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <h3><i class="fas fa-filter"></i> Filter Audit Trail</h3>
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="user_type">User Type</label>
                                <select name="user_type" id="user_type">
                                    <option value="all" <?= $userType === 'all' ? 'selected' : '' ?>>All User Types</option>
                                    <option value="admin" <?= $userType === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="attorney" <?= $userType === 'attorney' ? 'selected' : '' ?>>Attorney</option>
                                    <option value="employee" <?= $userType === 'employee' ? 'selected' : '' ?>>Employee</option>
                                    <option value="client" <?= $userType === 'client' ? 'selected' : '' ?>>Client</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="module">Module</label>
                                <select name="module" id="module">
                                    <option value="all" <?= $module === 'all' ? 'selected' : '' ?>>All Modules</option>
                                    <?php foreach ($modules as $mod): ?>
                                        <option value="<?= htmlspecialchars($mod) ?>" <?= $module === $mod ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mod) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="status">Status</label>
                                <select name="status" id="status">
                                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success</option>
                                    <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                                    <option value="warning" <?= $status === 'warning' ? 'selected' : '' ?>>Warning</option>
                                    <option value="info" <?= $status === 'info' ? 'selected' : '' ?>>Info</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="priority">Priority</label>
                                <select name="priority" id="priority">
                                    <option value="all" <?= $priority === 'all' ? 'selected' : '' ?>>All Priorities</option>
                                    <option value="low" <?= $priority === 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= $priority === 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= $priority === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= $priority === 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="date_from">Date From</label>
                                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="date_to">Date To</label>
                                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="search">Search</label>
                                <input type="text" name="search" id="search" placeholder="Search actions, users, descriptions..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="admin_audit.php" class="btn-reset">
                                <i class="fas fa-times"></i> Reset Filters
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Audit Trail Table -->
                <div class="audit-table-container">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="fas fa-list"></i> 
                            Audit Trail Log 
                            <span style="font-size: 1rem; color: #6c757d; font-weight: normal;">
                                (<?= count($auditData) ?> records found)
                            </span>
                        </h3>
                        <div class="table-actions">
                            <button class="btn-export" onclick="exportAuditTrail()">
                                <i class="fas fa-download"></i> Export CSV
                            </button>
                            <button class="btn-delete-all" onclick="deleteAllAuditTrail()" style="background: #dc3545; margin-left: 10px;">
                                <i class="fas fa-trash"></i> Delete All
                            </button>
                        </div>
                    </div>

                    <?php if (empty($auditData)): ?>
                        <div class="no-data">
                            <i class="fas fa-search"></i>
                            <h3>No audit records found</h3>
                            <p>Try adjusting your filters or check back later for new activity.</p>
                        </div>
                    <?php else: ?>
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Module</th>
                                    <th>Description</th>
                                    <th>IP Address</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditData as $record): ?>
                                    <tr>
                                        <td class="timestamp">
                                            <i class="fas fa-clock"></i><br>
                                            <?= date('M d, Y', strtotime($record['timestamp'])) ?><br>
                                            <small><?= date('h:i A', strtotime($record['timestamp'])) ?></small>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?= strtoupper(substr($record['user_name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <strong><?= htmlspecialchars($record['user_name']) ?></strong><br>
                                                    <span class="user-type-badge <?= $record['user_type'] ?>">
                                                        <?= ucfirst($record['user_type']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($record['action']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="module-badge"><?= htmlspecialchars($record['module']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($record['description']) ?>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars($record['ip_address']) ?></code>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $record['status'] ?>">
                                                <?= ucfirst($record['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?= $record['priority'] ?>">
                                                <?= ucfirst($record['priority']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function exportAuditTrail() {
            // Get current filters
            const urlParams = new URLSearchParams(window.location.search);
            const exportUrl = 'export_audit_trail.php?' + urlParams.toString();
            
            // Create temporary link and trigger download
            const link = document.createElement('a');
            link.href = exportUrl;
            link.download = 'audit_trail_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function deleteAllAuditTrail() {
            if (confirm('⚠️ WARNING: This will permanently delete ALL audit trail records!\n\nAre you sure you want to continue?\n\nThis action cannot be undone.')) {
                if (confirm('🚨 FINAL CONFIRMATION:\n\nYou are about to delete ALL audit trail history.\n\nType "DELETE" to confirm:')) {
                    const userInput = prompt('Type "DELETE" to confirm deletion:');
                    if (userInput === 'DELETE') {
                        // Send delete request
                        fetch('admin_audit.php?delete_all=1', {
                            method: 'POST'
                        })
                        .then(response => response.text())
                        .then(data => {
                            alert('✅ All audit trail records have been deleted successfully!');
                            location.reload();
                        })
                        .catch(error => {
                            alert('❌ Error deleting audit trail: ' + error);
                        });
                    } else {
                        alert('❌ Deletion cancelled. Audit trail records are safe.');
                    }
                }
            }
        }

        function showSetupInstructions() {
            alert('To set up the audit trail system:\n\n1. Go to phpMyAdmin\n2. Select your "lawfirm" database\n3. Click on "Import"\n4. Choose the updated "lawfirm.sql" file\n5. Click "Go" to import\n\nThis will create the audit_trail table and sample data.');
        }

        // Auto-refresh disabled - user can manually refresh if needed
        // setInterval(function() {
        //     // Only refresh if no filters are applied
        //     const urlParams = new URLSearchParams(window.location.search);
        //     if (urlParams.toString() === '') {
        //         location.reload();
        //     }
        // }, 30000);
    </script>
</body>
</html> 