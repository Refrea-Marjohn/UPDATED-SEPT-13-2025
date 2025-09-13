<?php
require_once 'session_manager.php';
validateUserAccess('admin');

require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';

$admin_id = $_SESSION['user_id'];

// Fetch all cases with client and attorney information
$cases = [];
$sql = "SELECT ac.*, 
        c.name as client_name, 
        a.name as attorney_name,
        ac.created_at as date_filed,
        ac.attorney_id as created_by
        FROM attorney_cases ac 
        LEFT JOIN user_form c ON ac.client_id = c.id 
        LEFT JOIN user_form a ON ac.attorney_id = a.id 
        ORDER BY ac.created_at DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cases[] = $row;
    }
}

// Debug: Log all case types from database
$case_types_debug = [];
foreach ($cases as $case) {
    if ($case['case_type'] && !in_array($case['case_type'], $case_types_debug)) {
        $case_types_debug[] = $case['case_type'];
    }
}
error_log("Case types in database: " . implode(', ', $case_types_debug));

// Fetch all clients for dropdown
$clients = [];
$stmt = $conn->prepare("SELECT id, name FROM user_form WHERE user_type='client'");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clients[] = $row;

// Fetch all attorneys for dropdown
$attorneys = [];
$stmt = $conn->prepare("SELECT id, name, user_type FROM user_form WHERE user_type IN ('attorney', 'admin', 'admin_attorney')");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $attorneys[] = $row;

// Handle AJAX add case
if (isset($_POST['action']) && $_POST['action'] === 'add_case') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $client_id = intval($_POST['client_id']);
    $attorney_id = intval($_POST['attorney_id']);
    $case_type = $_POST['case_type'];
    $status = 'Pending'; // Automatically set to Pending
    $next_hearing = null; // No next hearing field anymore
    
    $stmt = $conn->prepare("INSERT INTO attorney_cases (title, description, attorney_id, client_id, case_type, status, next_hearing) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssiisss', $title, $description, $attorney_id, $client_id, $case_type, $status, $next_hearing);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // Log case creation to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $admin_id,
            $_SESSION['admin_name'],
            'admin',
            'Case Create',
            'Case Management',
            "Created new case: $title (Type: $case_type, Status: Pending)",
            'success',
            'medium'
        );
        echo 'success';
    } else {
        echo 'error';
    }
    exit();
}

// Handle AJAX update case
if (isset($_POST['action']) && $_POST['action'] === 'edit_case') {
    $case_id = intval($_POST['case_id']);
    $status = $_POST['status'];
    $attorney_id = intval($_POST['attorney_id']);
    
    $stmt = $conn->prepare("UPDATE attorney_cases SET status=?, attorney_id=? WHERE id=?");
    $stmt->bind_param('sii', $status, $attorney_id, $case_id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        // Log case update to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $admin_id,
            $_SESSION['admin_name'],
            'admin',
            'Case Update',
            'Case Management',
            "Updated case ID: $case_id (Status: $status)",
            'success',
            'medium'
        );
        echo 'success';
    } else {
        echo 'error';
    }
    exit();
}

// Handle AJAX delete case
if (isset($_POST['action']) && $_POST['action'] === 'delete_case') {
    $case_id = intval($_POST['case_id']);
    
    // Get case details before deletion for audit logging
    $caseStmt = $conn->prepare("SELECT title, case_type FROM attorney_cases WHERE id = ?");
    $caseStmt->bind_param('i', $case_id);
    $caseStmt->execute();
    $caseResult = $caseStmt->get_result();
    $caseData = $caseResult->fetch_assoc();
    
    $stmt = $conn->prepare("DELETE FROM attorney_cases WHERE id=?");
    $stmt->bind_param('i', $case_id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        // Log case deletion to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $admin_id,
            $_SESSION['admin_name'],
            'admin',
            'Case Delete',
            'Case Management',
            "Deleted case: " . ($caseData['title'] ?? "ID: $case_id") . " (Type: " . ($caseData['case_type'] ?? 'Unknown') . ")",
            'success',
            'high' // HIGH priority for deletions
        );
        echo 'success';
    } else {
        echo 'error';
    }
    exit();
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case Management - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
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

    <div class="main-content">
        <div class="header">
            <div class="header-title">
                <h1>Case Management</h1>
                <p>Manage all cases in the system</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Admin" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['user_name']; ?></h3>
                    <p>Administrator</p>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="action-bar">
                <!-- Primary Action -->
                <div class="primary-action">
                    <button class="btn-primary" onclick="showAddCaseModal()">
                        <i class="fas fa-plus"></i> Add New Case
                    </button>
                </div>
                
                <!-- Filter Controls -->
                <div class="filter-controls">
                    <!-- Case Scope Filters -->
                    <div class="filter-group case-scope">
                        <label class="filter-label">Scope:</label>
                        <div class="case-filters">
                            <button class="case-filter-btn active" data-filter="all">All Cases</button>
                            <button class="case-filter-btn" data-filter="my">My Cases</button>
                        </div>
                    </div>
                    
                    <!-- Status Filters -->
                    <div class="filter-group status-scope">
                        <label class="filter-label">Status:</label>
                        <div class="filters">
                            <button class="filter-btn active" data-status="">All</button>
                            <button class="filter-btn" data-status="Active">Active</button>
                            <button class="filter-btn" data-status="Pending">Pending</button>
                            <button class="filter-btn" data-status="Closed">Closed</button>
                        </div>
                    </div>
                    
                    <!-- Type Filter -->
                    <div class="filter-group type-scope">
                        <label class="filter-label">Type:</label>
                        <div class="type-filters">
                            <select id="typeFilter">
                                <option value="">All Types</option>
                                <option value="Criminal">Criminal</option>
                                <option value="Civil">Civil</option>
                                <option value="Family">Family</option>
                                <option value="Corporate">Corporate</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Search -->
                    <div class="filter-group search-scope">
                        <label class="filter-label">Search:</label>
                        <div class="search-bar">
                            <input type="text" id="searchInput" placeholder="Search cases...">
                            <button><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cases-grid" id="casesGrid">
                <?php if (empty($cases)): ?>
                <div class="no-cases">
                    <i class="fas fa-folder-open"></i>
                    <h3>No cases found</h3>
                    <p>Add your first case using the button above</p>
                </div>
                <?php else: ?>
                <?php foreach ($cases as $case): ?>
                <div class="case-card" data-status="<?= htmlspecialchars($case['status']) ?>" data-type="<?= htmlspecialchars($case['case_type']) ?>" data-created-by="<?= htmlspecialchars($case['created_by'] ?? '') ?>">
                    <div class="case-header">
                        <div class="case-id">#<?= $case['id'] ?></div>
                        <div class="case-status status-<?= strtolower($case['status']) ?>"><?= htmlspecialchars($case['status']) ?></div>
                    </div>
                    
                    <div class="client-name">
                        <i class="fas fa-user"></i>
                        <?= htmlspecialchars($case['client_name'] ?? 'N/A') ?>
                    </div>
                    
                    <div class="case-actions">
                        <button class="btn-view" onclick="viewCase(<?= $case['id'] ?>, '<?= htmlspecialchars($case['title']) ?>', '<?= htmlspecialchars($case['client_name'] ?? 'N/A') ?>', '<?= htmlspecialchars($case['attorney_name'] ?? 'N/A') ?>', '<?= htmlspecialchars($case['case_type']) ?>', '<?= htmlspecialchars($case['status']) ?>', '<?= htmlspecialchars($case['description'] ?? '') ?>', '<?= date('M d, Y', strtotime($case['date_filed'])) ?>', '<?= htmlspecialchars($case['next_hearing'] ?? '') ?>')">
                            <i class="fas fa-eye"></i> View Case
                        </button>
                        <button class="btn-edit" onclick="editCase(<?= $case['id'] ?>, '<?= htmlspecialchars($case['title']) ?>', '<?= htmlspecialchars($case['status']) ?>', <?= $case['attorney_id'] ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-delete" onclick="deleteCase(<?= $case['id'] ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Case Modal -->
    <div id="addCaseModal" class="modal" style="z-index: 9999 !important;">
        <div class="modal-content" style="z-index: 9999 !important;" style="z-index: 10000 !important;">
            <div class="modal-header">
                <div class="header-content">
                    <div class="case-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <div class="header-text">
                        <h2>Add New Case</h2>
                        <p>Create a new case for client management</p>
                    </div>
                </div>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
            <form id="addCaseForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>Case Title</label>
                        <input type="text" name="title" required placeholder="Enter case title">
                    </div>
                    <div class="form-group">
                        <label>Case Type</label>
                        <select name="case_type" required>
                            <option value="">Select Type</option>
                            <option value="Criminal">Criminal</option>
                            <option value="Civil">Civil</option>
                            <option value="Family">Family</option>
                            <option value="Corporate">Corporate</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Client</label>
                        <select name="client_id" required>
                            <option value="">Select Client</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Attorney</label>
                        <select name="attorney_id" required>
                            <option value="">Select Attorney</option>
                            <?php foreach (
                                $attorneys as $attorney): ?>
                            <option value="<?= $attorney['id'] ?>">
                                <?= htmlspecialchars($attorney['name']) ?>
                                <?php if ($attorney['user_type'] === 'admin'): ?> (Admin)
                                <?php elseif ($attorney['user_type'] === 'admin_attorney'): ?> (Admin & Attorney)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group description-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" required placeholder="Enter detailed case description"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-save">Save Case</button>
                </div>
            </form>
            </div>
        </div>
    </div>

    <!-- Edit Case Modal -->
    <div id="editCaseModal" class="modal" style="z-index: 9999 !important;">
        <div class="modal-content" style="z-index: 9999 !important;" style="z-index: 10000 !important;">
            <div class="modal-header">
                <div class="header-content">
                    <div class="case-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="header-text">
                        <h2>Edit Case</h2>
                        <p>Update case information and status</p>
                    </div>
                </div>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
            <form id="editCaseForm">
                <input type="hidden" name="case_id" id="editCaseId">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="editCaseStatus" required>
                        <option value="Active">Active</option>
                        <option value="Pending">Pending</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Attorney</label>
                    <select name="attorney_id" id="editCaseAttorney" required>
                        <option value="">Select Attorney</option>
                        <?php foreach ($attorneys as $attorney): ?>
                        <option value="<?= $attorney['id'] ?>">
                            <?= htmlspecialchars($attorney['name']) ?>
                            <?php if ($attorney['user_type'] === 'admin'): ?> (Admin)
                            <?php elseif ($attorney['user_type'] === 'admin_attorney'): ?> (Admin & Attorney)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-save">Update Case</button>
                </div>
            </form>
            </div>
        </div>
    </div>

    <!-- View Case Modal -->
    <div id="viewCaseModal" class="modal" style="z-index: 9999 !important;">
        <div class="modal-content" style="z-index: 9999 !important;" style="z-index: 10000 !important;">
            <!-- Content will be dynamically populated -->
        </div>
    </div>

    <style>
        .dashboard-container { display: flex; min-height: 100vh; }
        .content { padding: 20px; }
        .btn-primary { 
            background: #5D0E26 !important; 
            color: white !important; 
            border: 2px solid white !important; 
            padding: 10px 20px; 
            border-radius: 5px; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filters { display: flex; gap: 8px; flex-wrap: wrap; }
        .type-filters { display: flex; gap: 8px; flex-wrap: wrap; }
        
        .action-bar { 
            display: flex; 
            flex-direction: column;
            gap: 20px; 
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border: 1px solid #e9ecef;
        }
        
        .primary-action {
            display: flex;
            justify-content: flex-start;
            align-items: center;
        }
        
        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
            width: 100%;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 120px;
        }
        
        .filter-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .case-scope, .status-scope {
            min-width: 140px;
        }
        
        .type-scope {
            min-width: 160px;
            flex: 1;
        }
        
        .search-scope {
            min-width: 300px;
            flex: 2;
        }
        
        .case-filters, .filters {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .type-filters, .search-bar {
            display: flex;
            width: 100%;
        }
        
        .search-bar {
            position: relative;
        }
        
        .search-bar input {
            flex: 1;
            padding: 10px 40px 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.9rem;
            min-width: 350px;
        }
        
        .search-bar button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #1976d2;
            color: white;
            border: none;
            padding: 8px;
            border-radius: 4px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .search-bar button:hover {
            background: #1565c0;
        }
        
        .case-filter-btn {
            padding: 10px 20px;
            border: 2px solid #1976d2;
            border-radius: 25px;
            background: white;
            color: #1976d2;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .case-filter-btn:hover {
            background: #1976d2;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
        }
        
        .case-filter-btn.active {
            background: #1976d2;
            color: white;
            box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #5D0E26;
            border-radius: 20px;
            background: white;
            color: #5D0E26;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-btn:hover {
            background: #5D0E26;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(93, 14, 38, 0.3);
        }
        
        .filter-btn.active {
            background: #5D0E26;
            color: white;
            box-shadow: 0 2px 8px rgba(93, 14, 38, 0.3);
        }
        
        .type-filters select {
            padding: 8px 16px;
            border: 2px solid #5D0E26;
            border-radius: 20px;
            background: white;
            color: #5D0E26;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%235D0E26' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
        }
        
        .type-filters select:hover {
            background-color: #f8f9fa;
            box-shadow: 0 2px 8px rgba(93, 14, 38, 0.2);
        }
        
        .type-filters select:focus {
            outline: none;
            border-color: #8B1538;
            box-shadow: 0 0 0 3px rgba(93, 14, 38, 0.1);
        }
        .search-bar { display: flex; gap: 5px; }
        .search-bar input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 200px; }
        .search-bar button { padding: 8px 12px; background: #1976d2; color: white; border: none; border-radius: 4px; cursor: pointer; }
        
        /* New Card Layout Styles */
        .cases-grid { 
            display: grid; 
            grid-template-columns: repeat(4, 1fr); 
            gap: 20px; 
            margin-top: 20px;
            justify-content: center;
        }
        
        .case-card { 
            background: white; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
            padding: 20px; 
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            width: 100%;
            box-sizing: border-box;
        }
        
        .case-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 25px rgba(0,0,0,0.15); 
        }
        
        .case-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 15px; 
        }
        
        .case-id { 
            font-size: 1.2em; 
            font-weight: 700; 
            color: #1976d2; 
        }
        
        .case-status { 
            padding: 6px 12px; 
            border-radius: 20px; 
            font-size: 0.8em; 
            font-weight: 600; 
            text-transform: uppercase; 
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-closed { background: #f8d7da; color: #721c24; }
        
        .client-name { 
            font-size: 1.4em; 
            font-weight: 600; 
            color: #1f2937; 
            margin-bottom: 20px; 
            text-align: center;
            padding: 15px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .client-name i { 
            color: #1976d2; 
            font-size: 1.2em; 
        }
        
        .case-actions { 
            display: flex; 
            gap: 8px; 
            align-items: center; 
        }
        
        .btn-view { 
            background: #1976d2; 
            color: white; 
            border: none; 
            padding: 10px 16px; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 500; 
            flex: 1; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 6px; 
        }
        
        .btn-view:hover { 
            background: #1565c0; 
        }
        
        .btn-edit, .btn-delete { 
            padding: 10px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            width: 40px; 
            height: 40px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        
        .btn-edit { 
            background: #ffc107; 
            color: #212529; 
        }
        
        .btn-delete { 
            background: #dc3545; 
            color: white; 
        }
        
        .btn-edit:hover { background: #e0a800; }
        .btn-delete:hover { background: #c82333; }
        
        .no-cases { 
            grid-column: 1 / -1; 
            text-align: center; 
            padding: 60px 20px; 
            color: #6b7280; 
        }
        
        .no-cases i { 
            font-size: 4em; 
            margin-bottom: 20px; 
            color: #d1d5db; 
        }
        
        .no-cases h3 { 
            margin-bottom: 10px; 
            color: #374151; 
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
            background-color: rgba(0,0,0,0.6); 
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        .modal-content { 
            background-color: white; 
            margin: 1% auto; 
            padding: 0; 
            border-radius: 12px; 
            width: 95%; 
            max-width: 900px; 
            height: auto;
            max-height: 95vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: 2px solid #5D0E26;
            animation: slideIn 0.4s ease-out;
            transform-origin: center;
            display: flex;
            flex-direction: column;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-30px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        
        /* Professional Modal Styles */
        .modal-header {
            background: linear-gradient(135deg, #5D0E26 0%, #8B1538 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .case-icon {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .header-text h2 {
            margin: 0 0 0.15rem 0;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .header-text p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.75rem;
        }

        .header-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .close {
            color: white;
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.2);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
            position: absolute;
            top: 10px;
            right: 15px;
        }
        
        .close:hover,
        .close:focus {
            color: #5D0E26;
            background: white;
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }



        .modal-body {
            padding: 1rem 1.5rem;
            background: #ffffff;
            flex: 1;
            overflow: visible;
        }

        .case-overview {
            text-align: center;
            margin-bottom: 0.5rem;
            padding: 0.4rem;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }

        .status-banner {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.3rem;
        }

        .status-banner.status-active {
            background: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .status-banner.status-pending {
            background: rgba(255, 152, 0, 0.1);
            color: #f57c00;
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        .status-banner.status-closed {
            background: rgba(158, 158, 158, 0.1);
            color: #616161;
            border: 1px solid rgba(158, 158, 158, 0.3);
        }

        .status-banner i {
            font-size: 0.6rem;
        }

        .case-title {
            margin: 0 0 0.2rem 0;
            font-size: 1rem;
            font-weight: 700;
            color: #1f2937;
        }

        .case-description {
            margin: 0;
            color: #6b7280;
            font-size: 0.8rem;
            line-height: 1.2;
        }

        /* Case Details Grid */
        .case-details-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 0.6rem; 
            margin: 0.6rem 0; 
        }
        
        .detail-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 8px;
            padding: 0.6rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(93, 14, 38, 0.08);
            transition: all 0.3s ease;
        }
        
        .detail-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(93, 14, 38, 0.12);
        }

        .detail-section h4 {
            margin: 0 0 0.4rem 0;
            font-size: 0.8rem;
            font-weight: 700;
            color: #5D0E26;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding-bottom: 0.3rem;
            border-bottom: 2px solid #5D0E26;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .detail-section h4 i {
            color: #5D0E26;
            font-size: 0.8rem;
        }
        
        .detail-item { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0;
            border-bottom: 1px solid rgba(93, 14, 38, 0.1);
            transition: all 0.2s ease;
        }

        .detail-item:hover {
            background: rgba(93, 14, 38, 0.02);
            padding-left: 0.3rem;
            padding-right: 0.3rem;
            border-radius: 4px;
        }

        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-label { 
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 600; 
            color: #5D0E26; 
            font-size: 0.75rem; 
            text-transform: uppercase; 
            letter-spacing: 0.3px; 
        }

        .detail-label i {
            color: #5D0E26;
            font-size: 0.7rem;
        }
        
        .detail-value { 
            color: #1f2937; 
            font-weight: 700; 
            font-size: 0.8rem;
            text-align: right;
            background: rgba(93, 14, 38, 0.05);
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            border: 1px solid rgba(93, 14, 38, 0.1);
        }
        
        .modal-footer {
            background: linear-gradient(135deg, #5D0E26 0%, #8B1538 100%);
            padding: 0.6rem 1.5rem;
            border-radius: 0 0 12px 12px;
            border-top: 2px solid #5D0E26;
            margin-top: auto;
        }

        .footer-actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 0.8rem;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.2);
        }

        .btn-primary {
            background: white;
            color: #5D0E26;
            border: 2px solid white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 0.8rem;
        }

        .btn-primary:hover {
            background: #5D0E26;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.2);
        }
        
        .form-group { 
            margin-bottom: 25px; 
        }
        .form-group label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: #5D0E26;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; 
            padding: 12px 16px; 
            border: 1px solid #d6d9de; 
            border-radius: 10px; 
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafbfc;
            box-sizing: border-box;
            box-shadow: inset 0 1px 2px rgba(16,24,40,0.04);
        }
        
        .form-group select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
            appearance: none;
        }
        .form-group input:hover, .form-group select:hover, .form-group textarea:hover {
            border-color: #c3cad5;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { 
            outline: none; 
            border-color: #8B1538; 
            box-shadow: 0 0 0 4px rgba(139, 21, 56, 0.12);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        /* Form layout improvements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-row .form-group {
            margin-bottom: 20px;
        }
        

        
        /* Description takes full width */
        .form-group.description-group {
            grid-column: 1 / -1;
        }
        .form-actions { 
            display: flex; 
            gap: 12px; 
            justify-content: flex-end; 
            margin-top: 24px; 
            padding-top: 16px;
            border-top: 1px solid #edf0f3;
        }
        .btn-cancel, .btn-save { 
            padding: 12px 20px; 
            border: none; 
            border-radius: 10px; 
            cursor: pointer; 
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.2px;
            transition: all 0.2s ease;
            min-width: 120px;
        }
        .btn-cancel { 
            background: #697586; 
            color: white; 
        }
        .btn-cancel:hover {
            background: #5c6778;
        }
        .btn-save { 
            background: #7C0F2F; 
            color: white; 
            box-shadow: 0 1px 2px rgba(16,24,40,0.06);
        }
        .btn-save:hover {
            background: #8B1538;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .cases-grid { 
                grid-template-columns: repeat(3, 1fr); 
            }
            .case-details-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }
            .modal-content {
                width: 95%;
                max-width: 850px;
                margin: 1% auto;
                max-height: 95vh;
            }
            .modal-body {
                padding: 0.8rem 1.2rem;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .cases-grid { 
                grid-template-columns: repeat(2, 1fr); 
            }
            
            .action-bar {
                padding: 15px;
                gap: 15px;
            }
            
            .filter-controls {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: unset;
                width: 100%;
            }
            
            .search-scope {
                min-width: unset;
                flex: 2;
            }
            
            .case-filters, .filters {
                justify-content: center;
                gap: 6px;
            }
            
            .filter-btn, .case-filter-btn {
                padding: 8px 16px;
                font-size: 0.85rem;
            }
            
            .type-filters select {
                padding: 8px 12px;
                font-size: 0.85rem;
                padding-right: 35px;
            }
            
            .search-bar input {
                padding: 8px 35px 8px 10px;
                font-size: 0.85rem;
                min-width: 280px;
            }
            .case-details-grid {
                grid-template-columns: 1fr;
                gap: 0.4rem;
            }
            .modal-content {
                width: 98%;
                margin: 1% auto;
                max-height: 98vh;
            }
            .modal-body {
                padding: 0.6rem 1rem;
            }
            .modal-header {
                padding: 0.6rem 1rem;
            }
            .modal-footer {
                padding: 0.5rem 1rem;
            }
            .footer-actions {
                flex-direction: column;
                gap: 0.4rem;
            }
            .btn-secondary, .btn-primary {
                width: 100%;
                justify-content: center;
                padding: 0.4rem 0.8rem;
            }
        }
        
        @media (max-width: 480px) {
            .filter-btn, .case-filter-btn {
                padding: 6px 12px;
                font-size: 0.8rem;
            }
            
            .filter-label {
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 400px) {
            .cases-grid { 
                grid-template-columns: 1fr; 
            }
        }
    </style>

    <script>
        // Modal functionality
        function showAddCaseModal() {
            document.getElementById('addCaseModal').style.display = 'block';
        }

        function editCase(caseId, title, status, attorneyId) {
            document.getElementById('editCaseId').value = caseId;
            document.getElementById('editCaseStatus').value = status;
            document.getElementById('editCaseAttorney').value = attorneyId;
            document.getElementById('editCaseModal').style.display = 'block';
        }

        function deleteCase(caseId) {
            if (confirm('Are you sure you want to delete this case?')) {
                const formData = new FormData();
                formData.append('action', 'delete_case');
                formData.append('case_id', caseId);
                
                fetch('admin_managecases.php', {
                    method: 'POST',
                    body: formData
                }).then(response => response.text()).then(result => {
                    if (result === 'success') {
                        location.reload();
                    } else {
                        alert('Error deleting case');
                    }
                });
            }
        }

        function viewCase(caseId, title, clientName, attorneyName, caseType, status, description, dateFiled, nextHearing) {
            const modalContent = document.getElementById('viewCaseModal').querySelector('.modal-content');
            modalContent.innerHTML = `
                <div class="modal-header">
                    <div class="header-content">
                        <div class="case-icon">
                            <i class="fas fa-gavel"></i>
                        </div>
                        <div class="header-text">
                            <h2>Case Details</h2>
                            <p>Comprehensive information about case #${caseId}</p>
                        </div>
                    </div>
                    <span class="close" onclick="closeViewModal()">&times;</span>
                </div>
                
                <div class="modal-body">
                    <div class="case-overview">
                        <div class="status-banner status-${status.toLowerCase()}">
                            <i class="fas fa-circle"></i>
                            <span>${status}</span>
                        </div>
                        <h3 class="case-title">${title}</h3>
                        <p class="case-description">${description || 'No description provided'}</p>
                    </div>
                    
                    <div class="case-details-grid">
                        <div class="detail-section">
                            <h4><i class="fas fa-info-circle"></i> Case Information</h4>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-hashtag"></i>
                                    <span>Case ID</span>
                                </div>
                                <div class="detail-value">#${caseId}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-tag"></i>
                                    <span>Type</span>
                                </div>
                                <div class="detail-value">${caseType}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span>Date Filed</span>
                                </div>
                                <div class="detail-value">${dateFiled}</div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4><i class="fas fa-users"></i> People Involved</h4>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-user"></i>
                                    <span>Client</span>
                                </div>
                                <div class="detail-value">${clientName}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-user-tie"></i>
                                    <span>Attorney</span>
                                </div>
                                <div class="detail-value">${attorneyName}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-clock"></i>
                                    <span>Next Hearing</span>
                                </div>
                                <div class="detail-value">${nextHearing || 'Not Scheduled'}</div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4><i class="fas fa-file-alt"></i> Case Details</h4>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-align-left"></i>
                                    <span>Title</span>
                                </div>
                                <div class="detail-value">${title}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-info"></i>
                                    <span>Status</span>
                                </div>
                                <div class="detail-value">${status}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">
                                    <i class="fas fa-comment"></i>
                                    <span>Description</span>
                                </div>
                                <div class="detail-value">${description || 'No description provided'}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <div class="footer-actions">
                        <button type="button" class="btn-secondary" onclick="editCase(${caseId}, '${title}', '${status}', ${attorneyName ? 'getAttorneyIdByName(\'' + attorneyName + '\')' : 'null'})">
                            <i class="fas fa-edit"></i> Edit Case
                        </button>
                        <button type="button" class="btn-primary" onclick="closeViewModal()">
                            <i class="fas fa-check"></i> Close
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('viewCaseModal').style.display = 'block';
        }
        
        function closeViewModal() {
            document.getElementById('viewCaseModal').style.display = 'none';
        }

        // Close modals
        document.querySelectorAll('.close').forEach(closeBtn => {
            closeBtn.onclick = function() {
                this.closest('.modal').style.display = 'none';
            }
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
                // Close button functionality for view case modal
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-cancel')) {
                e.target.closest('.modal').style.display = 'none';
            }
        });

        // Add case form submission
        document.getElementById('addCaseForm').onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'add_case');
            
            fetch('admin_managecases.php', {
                method: 'POST',
                body: formData
            }).then(response => response.text()).then(result => {
                if (result === 'success') {
                    alert('Case added successfully!');
                    location.reload();
                } else {
                    alert('Error adding case');
                }
            });
        };

        // Edit case form submission
        document.getElementById('editCaseForm').onsubmit = function(e) {
            e.preventDefault();
            
            // First confirmation
            const confirm1 = confirm('⚠️ WARNING: You are about to change the case status.\n\nAre you sure you want to proceed?');
            if (!confirm1) return;
            
            // Second confirmation with more details
            const formData = new FormData(this);
            const caseId = formData.get('case_id');
            const newStatus = formData.get('status');
            const confirm2 = confirm(`🚨 FINAL CONFIRMATION 🚨\n\nCase ID: ${caseId}\nNew Status: ${newStatus}\n\nThis action will permanently change the case status.\n\nClick OK to confirm, or Cancel to abort.`);
            if (!confirm2) return;
            
            formData.append('action', 'edit_case');
            
            fetch('admin_managecases.php', {
                method: 'POST',
                body: formData
            }).then(response => response.text()).then(result => {
                if (result === 'success') {
                    alert('✅ Case status updated successfully!');
                    location.reload();
                } else {
                    alert('❌ Error updating case status');
                }
            });
        };

        // Filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing filters...');
            
            // Test if elements exist
            const typeFilter = document.getElementById('typeFilter');
            const searchInput = document.getElementById('searchInput');
            const statusButtons = document.querySelectorAll('.filter-btn');
            
            console.log('Type filter exists:', !!typeFilter);
            console.log('Search input exists:', !!searchInput);
            console.log('Status buttons count:', statusButtons.length);
            
            // Case filter buttons (All Cases / My Cases)
            const caseFilterButtons = document.querySelectorAll('.case-filter-btn');
            caseFilterButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    console.log('Case filter button clicked:', this.textContent);
                    // Remove active class from all case filter buttons
                    document.querySelectorAll('.case-filter-btn').forEach(b => b.classList.remove('active'));
                    // Add active class to clicked button
                    this.classList.add('active');
                    filterCases();
                });
            });
            
            // Status filter buttons
            statusButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    console.log('Status button clicked:', this.textContent);
                    // Remove active class from all status buttons
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    // Add active class to clicked button
                    this.classList.add('active');
                    filterCases();
                });
            });
            
            // Type filter dropdown
            if (typeFilter) {
                typeFilter.addEventListener('change', function() {
                    console.log('Type filter changed to:', this.value);
                    filterCases();
                });
                
                // Test dropdown immediately
                console.log('Current dropdown value:', typeFilter.value);
            } else {
                console.error('Type filter element not found!');
            }
            
            // Search input
            if (searchInput) {
                searchInput.addEventListener('input', filterCases);
            }
            
            // Initial filter call
            setTimeout(() => {
                console.log('Running initial filter...');
                filterCases();
            }, 100);
        });

        function filterCases() {
            console.log('Filtering cases...');
            
            const activeStatusBtn = document.querySelector('.filter-btn.active');
            const activeCaseFilterBtn = document.querySelector('.case-filter-btn.active');
            const statusFilter = activeStatusBtn ? activeStatusBtn.getAttribute('data-status') : '';
            const caseFilter = activeCaseFilterBtn ? activeCaseFilterBtn.getAttribute('data-filter') : 'all';
            const typeFilter = document.getElementById('typeFilter') ? document.getElementById('typeFilter').value : '';
            const searchTerm = document.getElementById('searchInput') ? document.getElementById('searchInput').value.toLowerCase() : '';
            const rows = document.querySelectorAll('#casesGrid .case-card');

            console.log('Status filter:', statusFilter);
            console.log('Case filter:', caseFilter);
            console.log('Type filter:', typeFilter);
            console.log('Search term:', searchTerm);
            console.log('Total rows:', rows.length);

            // Debug: Log all case types found
            const caseTypes = new Set();
            rows.forEach(row => {
                const type = row.getAttribute('data-type');
                if (type) caseTypes.add(type);
            });
            console.log('Found case types:', Array.from(caseTypes));

            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                const type = row.getAttribute('data-type');
                const createdBy = row.getAttribute('data-created-by');
                const text = row.textContent.toLowerCase();
                
                // Case-insensitive matching for type filter
                const typeMatch = !typeFilter || 
                    (type && type.toLowerCase() === typeFilter.toLowerCase()) ||
                    (type && type.toLowerCase().includes(typeFilter.toLowerCase()));
                
                const statusMatch = !statusFilter || status === statusFilter;
                const searchMatch = !searchTerm || text.includes(searchTerm);
                
                // My Cases filter - only show cases created by current admin
                const caseFilterMatch = caseFilter === 'all' || 
                    (caseFilter === 'my' && createdBy === '<?= $admin_id ?>');
                
                const shouldShow = statusMatch && typeMatch && searchMatch && caseFilterMatch;
                row.style.display = shouldShow ? '' : 'none';
                
                if (!shouldShow) {
                    console.log('Hiding row:', row.querySelector('.case-id')?.textContent, 'Type:', type, 'Status:', status, 'Created by:', createdBy);
                } else {
                    console.log('Showing row:', row.querySelector('.case-id')?.textContent, 'Type:', type, 'Status:', status, 'Created by:', createdBy);
                }
            });
        }
    </script>
</body>
</html> 
