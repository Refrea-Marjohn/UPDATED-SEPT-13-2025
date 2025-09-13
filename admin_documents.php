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

// Log activity function for document actions
function log_activity($conn, $doc_id, $action, $user_id, $user_name, $form_number, $file_name) {
    $stmt = $conn->prepare("INSERT INTO admin_document_activity (document_id, action, user_id, user_name, form_number, file_name) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isisis', $doc_id, $action, $user_id, $user_name, $form_number, $file_name);
    $stmt->execute();
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $docName = trim($_POST['doc_name']);
    $fileInfo = pathinfo($_FILES['document']['name']);
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    $safeDocName = preg_replace('/[^A-Za-z0-9 _\-]/', '', $docName); // remove special chars
    $fileName = $safeDocName . $extension;
    $targetDir = 'uploads/admin/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $targetFile = $targetDir . time() . '_' . $fileName;
    $category = $_POST['category'];
    $formNumber = !empty($_POST['form_number']) ? intval($_POST['form_number']) : null;
    $uploadedBy = $_SESSION['user_id'] ?? 1;
    $user_name = $_SESSION['admin_name'] ?? 'Admin';
    
    // Check for duplicate form number only if form number is provided
    if ($formNumber) {
        $dupCheck = $conn->prepare("SELECT id FROM admin_documents WHERE form_number = ?");
        $dupCheck->bind_param('i', $formNumber);
        $dupCheck->execute();
        $dupCheck->store_result();
        if ($dupCheck->num_rows > 0) {
            $error = 'A document with Form Number ' . $formNumber . ' already exists!';
        } else {
            if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
                $stmt = $conn->prepare("INSERT INTO admin_documents (file_name, file_path, category, uploaded_by, form_number) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sssii', $fileName, $targetFile, $category, $uploadedBy, $formNumber);
                $stmt->execute();
                $doc_id = $conn->insert_id;
                log_activity($conn, $doc_id, 'Uploaded', $uploadedBy, $user_name, $formNumber, $fileName);
                
                // Log to audit trail
                global $auditLogger;
                $auditLogger->logAction(
                    $uploadedBy,
                    $user_name,
                    'admin',
                    'Document Upload',
                    'Document Management',
                    "Uploaded document: $fileName (Category: $category)",
                    'success',
                    'medium'
                );
                $success = 'Document uploaded successfully!';
                header('Location: admin_documents.php');
                exit();
            } else {
                $error = 'Failed to upload document.';
            }
        }
    } else {
        // No form number provided, proceed with upload
        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
            $stmt = $conn->prepare("INSERT INTO admin_documents (file_name, file_path, category, uploaded_by, form_number) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssii', $fileName, $targetFile, $category, $uploadedBy, $formNumber);
            $stmt->execute();
            $doc_id = $conn->insert_id;
            log_activity($conn, $doc_id, 'Uploaded', $uploadedBy, $user_name, $formNumber, $fileName);
            $success = 'Document uploaded successfully!';
            header('Location: admin_documents.php');
            exit();
        } else {
            $error = 'Failed to upload document.';
        }
    }
}

// Handle edit
if (isset($_POST['edit_id'])) {
    $edit_id = intval($_POST['edit_id']);
    $new_name = trim($_POST['edit_file_name']);
    $new_form_number = !empty($_POST['edit_form_number']) ? intval($_POST['edit_form_number']) : null;
    $uploadedBy = $_SESSION['admin_id'] ?? 1; // Ensure user_id is set
    $user_name = $_SESSION['admin_name'] ?? 'Admin';
    // Check for duplicate form number (excluding self)
    $dupCheck = $conn->prepare("SELECT id FROM admin_documents WHERE form_number = ? AND id != ?");
    $dupCheck->bind_param('ii', $new_form_number, $edit_id);
    $dupCheck->execute();
    $dupCheck->store_result();
    if ($dupCheck->num_rows > 0) {
        $error = 'A document with Form Number ' . $new_form_number . ' already exists!';
    } else {
        $stmt = $conn->prepare("UPDATE admin_documents SET file_name=?, form_number=? WHERE id=?");
        $stmt->bind_param('sii', $new_name, $new_form_number, $edit_id);
        $stmt->execute();
        log_activity($conn, $edit_id, 'Edited', $uploadedBy, $user_name, $new_form_number, $new_name);
        
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $uploadedBy,
            $user_name,
            'admin',
            'Document Edit',
            'Document Management',
            "Edited document: $new_name (Form #: $new_form_number)",
            'success',
            'medium'
        );
        header('Location: admin_documents.php');
        exit();
    }
}

// Date filter logic
$filter_from = isset($_GET['filter_from']) ? $_GET['filter_from'] : '';
$filter_to = isset($_GET['filter_to']) ? $_GET['filter_to'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$user_type_filter = isset($_GET['user_type']) ? $_GET['user_type'] : '';

// Build WHERE clause with prepared statements
$where_conditions = [];
$where_params = [];
$where_types = '';

if ($filter_from && $filter_to) {
    $where_conditions[] = "DATE(upload_date) >= ? AND DATE(upload_date) <= ?";
    $where_params[] = $filter_from;
    $where_params[] = $filter_to;
    $where_types .= 'ss';
} elseif ($filter_from) {
    $where_conditions[] = "DATE(upload_date) = ?";
    $where_params[] = $filter_from;
    $where_types .= 's';
} elseif ($filter_to) {
    $where_conditions[] = "DATE(upload_date) <= ?";
    $where_params[] = $filter_to;
    $where_types .= 's';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Fetch all documents from different sources
$all_documents = [];

// Admin documents
if (!empty($where_clause)) {
    $stmt = $conn->prepare("SELECT 
        id, file_name, file_path, category, uploaded_by, upload_date, form_number,
        'admin' as source, 'Admin' as user_type_name
    FROM admin_documents 
    $where_clause 
    ORDER BY upload_date DESC");
    if (!empty($where_params)) {
        $stmt->bind_param($where_types, ...$where_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $stmt = $conn->prepare("SELECT 
        id, file_name, file_path, category, uploaded_by, upload_date, form_number,
        'admin' as source, 'Admin' as user_type_name
    FROM admin_documents 
    ORDER BY upload_date DESC");
    $stmt->execute();
    $result = $stmt->get_result();
}
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_documents[] = $row;
    }
}

// Attorney documents
if (!empty($where_clause)) {
    $stmt = $conn->prepare("SELECT 
        id, file_name, file_path, category, uploaded_by, upload_date, case_id as form_number,
        'attorney' as source, 'Attorney' as user_type_name
    FROM attorney_documents 
    $where_clause 
    ORDER BY upload_date DESC");
    if (!empty($where_params)) {
        $stmt->bind_param($where_types, ...$where_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $stmt = $conn->prepare("SELECT 
        id, file_name, file_path, category, uploaded_by, upload_date, case_id as form_number,
        'attorney' as source, 'Attorney' as user_type_name
    FROM attorney_documents 
    ORDER BY upload_date DESC");
    $stmt->execute();
    $result = $stmt->get_result();
}
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_documents[] = $row;
    }
}

// Employee documents
if (!empty($where_clause)) {
    $stmt = $conn->prepare("SELECT 
        id, file_name, file_path, category, uploaded_by, upload_date, form_number,
        'employee' as source, 'Employee' as user_type_name
    FROM employee_documents 
    $where_clause 
    ORDER BY upload_date DESC");
    if (!empty($where_params)) {
        $stmt->bind_param($where_types, ...$where_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $stmt = $conn->prepare("SELECT 
        id, file_name, file_path, category, uploaded_by, upload_date, form_number,
        'employee' as source, 'Employee' as user_type_name
    FROM employee_documents 
    ORDER BY upload_date DESC");
    $stmt->execute();
    $result = $stmt->get_result();
}
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_documents[] = $row;
    }
}

// Sort all documents by upload date
usort($all_documents, function($a, $b) {
    return strtotime($b['upload_date']) - strtotime($a['upload_date']);
});

// Apply category and user type filters
$documents = [];
foreach ($all_documents as $doc) {
    if ($category_filter && $doc['category'] !== $category_filter) continue;
    if ($user_type_filter && $doc['source'] !== $user_type_filter) continue;
    $documents[] = $doc;
}

// Get user names for display
$user_names = [];
$user_ids = array_unique(array_column($documents, 'uploaded_by'));
if (!empty($user_ids)) {
    $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
    $stmt = $conn->prepare("SELECT id, name FROM user_form WHERE id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($user_ids)), ...$user_ids);
    $stmt->execute();
    $user_result = $stmt->get_result();
    if ($user_result) {
        while ($row = $user_result->fetch_assoc()) {
            $user_names[$row['id']] = $row['name'];
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $source = $_GET['source'] ?? 'admin';
    
    if ($source === 'admin') {
        $stmt = $conn->prepare("SELECT file_path, file_name, form_number, uploaded_by FROM admin_documents WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            @unlink($row['file_path']);
            $user_name = $_SESSION['admin_name'] ?? 'Admin';
            $user_id = $_SESSION['user_id'] ?? 1;
            log_activity($conn, $id, 'Deleted', $row['uploaded_by'], $user_name, $row['form_number'], $row['file_name']);
            
            // Log to audit trail
            global $auditLogger;
            $auditLogger->logAction(
                $user_id,
                $user_name,
                'admin',
                'Document Delete',
                'Document Management',
                "Deleted document: {$row['file_name']} (Form #: {$row['form_number']})",
                'success',
                'high' // HIGH priority for deletions
            );
        }
        $stmt = $conn->prepare("DELETE FROM admin_documents WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    } elseif ($source === 'attorney') {
        $stmt = $conn->prepare("SELECT file_path, file_name, case_id, uploaded_by FROM attorney_documents WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            @unlink($row['file_path']);
            $user_name = $_SESSION['admin_name'] ?? 'Admin';
            $user_id = $_SESSION['user_id'] ?? 1;
            // Log attorney document deletion
            $stmt = $conn->prepare("INSERT INTO attorney_document_activity (document_id, action, user_id, user_name, case_id, file_name, category) VALUES (?, 'Deleted', ?, ?, ?, ?, 'Admin Deleted')");
            $stmt->bind_param('iisis', $id, $user_id, $user_name, $row['case_id'], $row['file_name']);
            $stmt->execute();
            
            // Log to audit trail
            global $auditLogger;
            $auditLogger->logAction(
                $user_id,
                $user_name,
                'admin',
                'Attorney Document Delete',
                'Document Management',
                "Deleted attorney document: {$row['file_name']} (Case ID: {$row['case_id']})",
                'success',
                'high' // HIGH priority for deletions
            );
        }
        $stmt = $conn->prepare("DELETE FROM attorney_documents WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    } elseif ($source === 'employee') {
        $stmt = $conn->prepare("SELECT file_path, file_name, form_number, uploaded_by FROM employee_documents WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            @unlink($row['file_path']);
            $user_name = $_SESSION['admin_name'] ?? 'Admin';
            $user_id = $_SESSION['user_id'] ?? 1;
            // Log employee document deletion
            $stmt = $conn->prepare("INSERT INTO employee_document_activity (document_id, action, user_id, user_name, form_number, file_name) VALUES (?, 'Deleted', ?, ?, ?, ?)");
            $stmt->bind_param('iisis', $id, $user_id, $user_name, $row['form_number'], $row['file_name']);
            $stmt->execute();
            
            // Log to audit trail
            global $auditLogger;
            $auditLogger->logAction(
                $user_id,
                $user_name,
                'admin',
                'Employee Document Delete',
                'Document Management',
                "Deleted employee document: {$row['file_name']} (Form #: {$row['form_number']})",
                'success',
                'high' // HIGH priority for deletions
            );
        }
        $stmt = $conn->prepare("DELETE FROM employee_documents WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    
    header('Location: admin_documents.php');
    exit();
}

// Fetch recent activity
$activity = [];
$stmt = $conn->prepare("SELECT * FROM admin_document_activity ORDER BY timestamp DESC LIMIT 10");
$stmt->execute();
$actRes = $stmt->get_result();
if ($actRes && $actRes->num_rows > 0) {
    while ($row = $actRes->fetch_assoc()) {
        $activity[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Storage - Opiña Law Office</title>
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
            <li><a href="admin_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="admin_documents.php" class="active"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="admin_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>
            <li><a href="admin_schedule.php"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>
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
                <h1>Document Management</h1>
                <p>Manage documents from Admin, Attorneys, and Employees</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Admin" style="object-fit:cover;width:50px;height:50px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['admin_name']; ?></h3>
                    <p>System Administrator</p>
                </div>
            </div>
        </div>

        <!-- Compact Document Statistics -->
        <?php
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM admin_documents");
        $stmt->execute();
        $admin_count = $stmt->get_result()->fetch_assoc()['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attorney_documents");
        $stmt->execute();
        $attorney_count = $stmt->get_result()->fetch_assoc()['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employee_documents");
        $stmt->execute();
        $employee_count = $stmt->get_result()->fetch_assoc()['count'];
        
        $total_count = $admin_count + $attorney_count + $employee_count;
        ?>
        <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
            <div style="background: #1976d2; color: white; padding: 12px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; font-size: 14px;">
                <i class="fas fa-folder-open"></i>
                <span><strong><?= $total_count ?></strong> Total</span>
            </div>
            <div style="background: #28a745; color: white; padding: 12px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; font-size: 14px;">
                <i class="fas fa-user-shield"></i>
                <span><strong><?= $admin_count ?></strong> Admin</span>
            </div>
            <div style="background: #ffc107; color: #212529; padding: 12px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; font-size: 14px;">
                <i class="fas fa-gavel"></i>
                <span><strong><?= $attorney_count ?></strong> Attorney</span>
            </div>
            <div style="background: #6f42c1; color: white; padding: 12px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; font-size: 14px;">
                <i class="fas fa-user-tie"></i>
                <span><strong><?= $employee_count ?></strong> Employee</span>
            </div>
        </div>

        <!-- Improved Action Buttons and Filters -->
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px; padding: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button onclick="openUploadModal()" style="background: #1976d2; color: white; border: none; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-size: 13px; font-weight: 500;">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                    <form method="post" action="download_all_admin_documents.php" style="display:inline;" onsubmit="return confirmDownloadAll();">
                        <button type="submit" style="background: #6c757d; color: white; border: none; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-size: 13px; font-weight: 500;">
                            <i class="fas fa-download"></i> Download All
                        </button>
                    </form>
                </div>
                
                <div style="display: flex; gap: 8px; align-items: center;">
                    <select id="userTypeFilter" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; background: white;" onchange="filterDocuments()">
                        <option value="">All Users</option>
                        <option value="admin">Admin</option>
                        <option value="attorney">Attorney</option>
                        <option value="employee">Employee</option>
                    </select>
                    
                    <select id="categoryFilter" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; background: white;" onchange="filterDocuments()">
                        <option value="">All Categories</option>
                        <option value="Forms">Forms</option>
                        <option value="Case Files">Case Files</option>
                        <option value="Court Documents">Court Documents</option>
                        <option value="Client Documents">Client Documents</option>
                        <option value="Legal Research">Legal Research</option>
                        <option value="Contracts">Contracts</option>
                    </select>
                    
                    <div style="position: relative;">
                        <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6b7280; font-size: 12px;"></i>
                        <input type="text" id="searchInput" placeholder="Search..." style="padding: 6px 12px 6px 30px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; background: white; width: 180px;" onkeyup="filterDocuments()">
                    </div>
                </div>
            </div>
            
            <!-- Date Filter integrated here -->
            <div class="date-filter-year" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f3f4f6;">
                <form method="get" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-calendar-alt" style="color: #1976d2; font-size: 14px;"></i>
                        <span style="font-weight: 500; color: #374151; font-size: 13px;">Date Range:</span>
                    </div>
                    <input type="date" id="filter_from" name="filter_from" value="<?= isset($_GET['filter_from']) ? htmlspecialchars($_GET['filter_from']) : '' ?>" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; background: white;">
                    <span style="color: #6b7280; font-size: 13px;">to</span>
                    <input type="date" id="filter_to" name="filter_to" value="<?= isset($_GET['filter_to']) ? htmlspecialchars($_GET['filter_to']) : '' ?>" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; background: white;">
                    <button type="submit" style="padding: 6px 12px; background: #1976d2; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <?php if (!empty($_GET['filter_from']) || !empty($_GET['filter_to'])): ?>
                        <a href="admin_documents.php" style="padding: 6px 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: 500;">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Compact Documents Table -->
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <?php if (empty($documents)): ?>
                <div style="text-align: center; padding: 40px 20px;">
                    <i class="fas fa-folder-open" style="font-size: 32px; color: #d1d5db; margin-bottom: 12px;"></i>
                    <h3 style="color: #6b7280; margin-bottom: 8px; font-size: 16px;">No documents found</h3>
                    <p style="color: #9ca3af; font-size: 14px;">Try adjusting your filters or upload a new document.</p>
                </div>
            <?php else: ?>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151; width: 30px;"></th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151;">Document Name</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151; width: 100px;">Category</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151; width: 80px;">User</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: #374151; width: 100px;">Date</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: #374151; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr style="border-bottom: 1px solid #f3f4f6; hover:background-color: #f9fafb;" data-user-type="<?= $doc['source'] ?>" data-category="<?= htmlspecialchars($doc['category']) ?>">
                                <td style="padding: 12px; text-align: center;">
                                    <?php $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION)); ?>
                                    <?php if($ext === 'pdf'): ?>
                                        <i class="fas fa-file-pdf" style="color: #d32f2f; font-size: 16px;"></i>
                                    <?php elseif($ext === 'doc' || $ext === 'docx'): ?>
                                        <i class="fas fa-file-word" style="color: #1976d2; font-size: 16px;"></i>
                                    <?php elseif($ext === 'xls' || $ext === 'xlsx'): ?>
                                        <i class="fas fa-file-excel" style="color: #388e3c; font-size: 16px;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-file-alt" style="color: #6b7280; font-size: 16px;"></i>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div>
                                        <div style="font-weight: 500; color: #1a202c; margin-bottom: 2px;"><?= htmlspecialchars($doc['file_name']) ?></div>
                                        <?php if ($doc['form_number']): ?>
                                            <div style="font-size: 12px; color: #6b7280;">Form/Case #<?= htmlspecialchars($doc['form_number']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="background: #f3f4f6; color: #374151; padding: 4px 8px; border-radius: 4px; font-size: 12px;"><?= htmlspecialchars($doc['category']) ?></span>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($doc['source'] === 'admin'): ?>
                                        <span style="background: #1976d2; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">Admin</span>
                                    <?php elseif ($doc['source'] === 'attorney'): ?>
                                        <span style="background: #ffc107; color: #212529; padding: 2px 6px; border-radius: 3px; font-size: 11px;">Attorney</span>
                                    <?php else: ?>
                                        <span style="background: #6f42c1; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">Employee</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; color: #6b7280; font-size: 12px;">
                                    <?= date('M d, Y', strtotime($doc['upload_date'])) ?>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <div style="display: flex; gap: 4px; justify-content: center;">
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" style="padding: 4px 8px; background: #eff6ff; color: #2563eb; border: 1px solid #93c5fd; border-radius: 3px; text-decoration: none; font-size: 11px;" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" download style="padding: 4px 8px; background: #dcfce7; color: #166534; border: 1px solid #86efac; border-radius: 3px; text-decoration: none; font-size: 11px;" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <a href="?delete=<?= $doc['id'] ?>&source=<?= $doc['source'] ?>" onclick="return confirm('Delete this document?')" style="padding: 4px 8px; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 3px; text-decoration: none; font-size: 11px;" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div class="recent-activity recent-activity-scroll">
            <h2><i class="fas fa-history"></i> Recent Activity</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Document</th>
                        <th>Action</th>
                        <th>User</th>
                        <th>Form</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $act): ?>
                    <tr>
                        <td><?= htmlspecialchars($act['timestamp']) ?></td>
                        <td><?= htmlspecialchars($act['file_name']) ?></td>
                        <td><span class="status-badge status-<?= strtolower($act['action']) ?>" style="padding:3px 10px;border-radius:8px;font-weight:500;<?php if(strtolower($act['action'])=='uploaded'){echo 'background:#eaffea;color:#388e3c;';}elseif(strtolower($act['action'])=='deleted'){echo 'background:#ffeaea;color:#d32f2f;';}elseif(strtolower($act['action'])=='edited'){echo 'background:#fff8e1;color:#fbc02d;';} ?>">
                            <i class="fas fa-<?= strtolower($act['action'])=='uploaded'?'arrow-up':'edit' ?>"></i> <?= htmlspecialchars($act['action']) ?></span></td>
                        <td><?= htmlspecialchars($act['user_name'] ?? 'Admin') ?></td>
                        <td><?= htmlspecialchars($act['form_number']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div id="uploadModal" class="modal-overlay" style="display:none;">
        <div class="modal-content modern-modal">
            <button class="close-modal-btn" onclick="closeUploadModal()" title="Close">&times;</button>
            <h2 style="margin-bottom:18px;">Upload Document</h2>
            <?php if (!empty($success)) echo '<div class="alert-success"><i class="fas fa-check-circle"></i> ' . $success . '</div>'; ?>
            <?php if (!empty($error)): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:12px;">
                <label>Form Number</label>
                <input type="number" name="form_number" id="form_number_input" min="1" required placeholder="Enter form number">
                <input type="hidden" name="category" value="Forms">
                <label>File</label>
                <input type="file" name="document" required>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">Upload</button>
            </form>
        </div>
    </div>
    <!-- Edit Document Modal -->
    <div id="editModal" class="modal-overlay" style="display:none;">
        <div class="modal-content modern-modal">
            <button class="close-modal-btn" onclick="closeEditModal()" title="Close">&times;</button>
            <h2 style="margin-bottom:18px;">Edit Document</h2>
            <?php if (!empty($error)) echo '<div class="alert-error"><i class="fas fa-exclamation-circle"></i> ' . $error . '</div>'; ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
                <input type="hidden" name="edit_id" id="edit_id">
                <label>Document Name</label>
                <input type="text" name="edit_file_name" id="edit_file_name" required>
                <label>Form Number</label>
                <input type="number" name="edit_form_number" id="edit_form_number" min="1" required>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">Save Changes</button>
            </form>
        </div>
    </div>
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Delete Document</h2>
            <p>Are you sure you want to delete this document?</p>
            <button class="btn btn-danger">Delete</button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
        </div>
    </div>
    <!-- Set Access Permissions Modal -->
    <div id="accessModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="closeAccessModal()">&times;</span>
            <h2>Set Access Permissions</h2>
            <form>
                <label>Grant Access To:</label>
                <select required>
                    <option value="">Select User Type</option>
                    <option value="Attorney">Attorney</option>
                    <option value="Admin Employee">Admin Employee</option>
                </select>
                <button type="submit" class="btn btn-primary">Set Access</button>
            </form>
        </div>
    </div>
    <script>
        function openEditModal(id, name, formNumber) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_file_name').value = name;
            document.getElementById('edit_form_number').value = formNumber || '';
            document.getElementById('editModal').style.display = 'block';
        }
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        function openDeleteModal() { document.getElementById('deleteModal').style.display = 'block'; }
        function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
        function openAccessModal() { document.getElementById('accessModal').style.display = 'block'; }
        function closeAccessModal() { document.getElementById('accessModal').style.display = 'none'; }
        function openUploadModal() { document.getElementById('uploadModal').style.display = 'block'; }
        function closeUploadModal() { document.getElementById('uploadModal').style.display = 'none'; }
        // Optional: Add event listeners to Upload button
        document.querySelector('.btn.btn-primary').onclick = openUploadModal;
        // Search/filter logic
        function filterDocuments() {
            var input = document.getElementById('searchInput').value.toLowerCase();
            var cards = document.querySelectorAll('.document-card');
            cards.forEach(function(card) {
                var name = card.querySelector('h3').textContent.toLowerCase();
                var formNumber = card.querySelector('p').textContent.toLowerCase();
                card.style.display = (name.includes(input) || formNumber.includes(input)) ? '' : 'none';
            });
        }
        document.getElementById('searchInput').addEventListener('input', filterDocuments);
        function confirmDownloadAll() {
            return confirm('Do you want to download all the files?');
        }
    </script>
    <?php if (!empty($error)): ?>
    <script>
    window.onload = function() {
        document.getElementById('uploadModal').style.display = 'block';
        var formInput = document.getElementById('form_number_input');
        if(formInput) formInput.focus();
    }
    </script>
    <?php endif; ?>
    <style>
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 300px;
        }

        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .search-box input {
            width: 100%;
            padding: 10px 10px 10px 35px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 0.9rem;
        }

        .document-categories {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            overflow-x: auto;
            padding-bottom: 10px;
        }

        .category {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            background: white;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .category:hover {
            background: var(--light-gray);
        }

        .category.active {
            background: var(--secondary-color);
            color: white;
        }

        .category i {
            font-size: 18px;
        }

        .category .count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .document-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            display: flex;
            gap: 15px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .document-icon {
            width: 50px;
            height: 50px;
            background: var(--light-gray);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .document-icon i {
            font-size: 24px;
            color: var(--secondary-color);
        }

        .document-info {
            flex: 1;
        }

        .document-info h3 {
            margin-bottom: 5px;
            font-size: 1rem;
        }

        .document-info p {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .document-meta {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 0.8rem;
            color: #666;
        }

        .document-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .document-actions {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
        }

        .recent-activity.recent-activity-scroll {
            max-height: 340px;
            overflow-y: auto;
            box-shadow: 0 4px 24px rgba(25, 118, 210, 0.08), 0 1.5px 4px rgba(0,0,0,0.04);
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            transition: box-shadow 0.2s;
        }
        .recent-activity.recent-activity-scroll:hover {
            box-shadow: 0 8px 32px rgba(25, 118, 210, 0.13), 0 2px 8px rgba(0,0,0,0.06);
        }
        .recent-activity.recent-activity-scroll::-webkit-scrollbar {
            width: 10px;
            background: #f3f6fa;
            border-radius: 8px;
        }
        .recent-activity.recent-activity-scroll::-webkit-scrollbar-thumb {
            background: #c5d6ee;
            border-radius: 8px;
            border: 2px solid #f3f6fa;
        }
        .recent-activity.recent-activity-scroll::-webkit-scrollbar-thumb:hover {
            background: #90b4e8;
        }
        .recent-activity.recent-activity-scroll table {
            border-collapse: collapse;
            width: 100%;
            min-width: 600px;
        }
        .recent-activity.recent-activity-scroll th, .recent-activity.recent-activity-scroll td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: 1em;
        }
        .recent-activity.recent-activity-scroll thead th {
            background: #f8f8f8;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 600;
            color: #1976d2;
            letter-spacing: 0.5px;
        }
        .recent-activity.recent-activity-scroll tbody tr:hover {
            background: #f5faff;
        }
        .status-uploaded {
            background: var(--success-color);
        }

        .status-modified {
            background: var(--warning-color);
        }

        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                max-width: none;
            }

            .document-categories {
                flex-wrap: nowrap;
            }

            .documents-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Modal Overlay and Modern Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 99990;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modern-modal {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            padding: 22px 18px 18px 18px;
            min-width: 0;
            max-width: 400px;
            width: 100%;
            position: relative;
            animation: modalPop 0.2s;
            margin: 0 auto;
        }
        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .close-modal-btn {
            position: absolute;
            top: 12px;
            right: 16px;
            background: none;
            border: none;
            font-size: 1.7rem;
            color: #888;
            cursor: pointer;
            transition: color 0.2s;
            z-index: 2;
        }
        .close-modal-btn:hover {
            color: #d32f2f;
        }
        .alert-error {
            background: #ffeaea;
            color: #d32f2f;
            border: 1px solid #d32f2f;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        .alert-error i {
            font-size: 1.2em;
        }
        .alert-success {
            background: #eaffea;
            color: #388e3c;
            border: 1px solid #388e3c;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        .alert-success i {
            font-size: 1.2em;
        }
        @media (max-width: 600px) {
            .modern-modal {
                padding: 12px 4vw 12px 4vw;
                max-width: 95vw;
            }
        }
        .date-filter-bar-centered {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }
        .date-filter-form-original {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .date-filter-form-original label {
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        .date-filter-form-original input[type="month"],
        .date-filter-form-original input[type="number"] {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }
        .date-filter-form-original .btn-secondary {
            padding: 8px 16px;
            background: #1976d2;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        .date-filter-form-original .btn-secondary:hover {
            background: #1565c0;
        }
        .date-filter-form-original .btn-clear {
            padding: 8px 16px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
        }
        .date-filter-form-original .btn-clear:hover {
            background: #e5e7eb;
        }
        
        .date-filter-year {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 10px 0;
            padding: 12px 0;
            border-top: 1px solid #f3f4f6;
        }
        
        /* Ensure all filter elements are visible */
        .date-filter-year form {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .date-filter-year input[type="date"] {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            background: white;
        }
        
        .date-filter-year button {
            padding: 6px 12px;
            background: #1976d2;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        
        .date-filter-year button:hover {
            background: #1565c0;
        }
    </style>
</head>
<body>
    <!-- Compact Upload Modal -->
    <div id="uploadModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow-y: auto;">
        <div style="background: white; margin: 5% auto; padding: 24px; border-radius: 8px; width: 90%; max-width: 500px; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
            <span onclick="closeUploadModal()" style="position: absolute; right: 16px; top: 16px; font-size: 24px; cursor: pointer; color: #666;">&times;</span>
            
            <div style="margin-bottom: 20px;">
                <h2 style="color: #1a202c; margin-bottom: 4px; font-size: 18px; font-weight: 600;">Upload Document</h2>
                <p style="color: #6b7280; font-size: 14px; margin: 0;">Add a new document to the system</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" onsubmit="return validateUploadForm()">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 500; color: #374151; font-size: 13px;">Document Name:</label>
                    <input type="text" name="doc_name" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background: white;" placeholder="Enter document name">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 500; color: #374151; font-size: 13px;">Category:</label>
                    <select name="category" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background: white;">
                        <option value="">Select Category</option>
                        <option value="Forms">Forms</option>
                        <option value="Case Files">Case Files</option>
                        <option value="Court Documents">Court Documents</option>
                        <option value="Client Documents">Client Documents</option>
                        <option value="Legal Research">Legal Research</option>
                        <option value="Contracts">Contracts</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 500; color: #374151; font-size: 13px;">Form/Case Number (Optional):</label>
                    <input type="number" name="form_number" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background: white;" placeholder="Enter form or case number">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 500; color: #374151; font-size: 13px;">Select File:</label>
                    <input type="file" name="document" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background: white;" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.jpg,.jpeg,.png">
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeUploadModal()" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; border-radius: 4px; cursor: pointer; font-size: 14px; color: #374151;">Cancel</button>
                    <button type="submit" style="padding: 8px 16px; background: #1976d2; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Upload Modal Functions
        function openUploadModal() {
            document.getElementById('uploadModal').style.display = 'block';
        }
        
        function closeUploadModal() {
            document.getElementById('uploadModal').style.display = 'none';
        }
        
        function validateUploadForm() {
            const docName = document.querySelector('input[name="doc_name"]').value.trim();
            const category = document.querySelector('select[name="category"]').value;
            const file = document.querySelector('input[name="document"]').files[0];
            
            if (!docName) {
                alert('Please enter a document name.');
                return false;
            }
            
            if (!category) {
                alert('Please select a category.');
                return false;
            }
            
            if (!file) {
                alert('Please select a file to upload.');
                return false;
            }
            
            return true;
        }
        
        // Document Filtering Functions
        function filterDocuments() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const userTypeFilter = document.getElementById('userTypeFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value;
            
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const fileName = row.querySelector('td:nth-child(2) div:first-child').textContent.toLowerCase();
                const userType = row.getAttribute('data-user-type');
                const category = row.getAttribute('data-category');
                
                const matchesSearch = fileName.includes(searchTerm);
                const matchesUserType = !userTypeFilter || userType === userTypeFilter;
                const matchesCategory = !categoryFilter || category === categoryFilter;
                
                if (matchesSearch && matchesUserType && matchesCategory) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update document count display
            updateDocumentCount();
        }
        
        function updateDocumentCount() {
            const visibleRows = document.querySelectorAll('tbody tr[style="display: table-row;"]').length;
            const totalRows = document.querySelectorAll('tbody tr').length;
            
            // You can add a counter display here if needed
            console.log(`Showing ${visibleRows} of ${totalRows} documents`);
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('uploadModal');
            if (event.target === modal) {
                closeUploadModal();
            }
        }
        
        // Confirm download all
        function confirmDownloadAll() {
            return confirm('This will download all documents. Continue?');
        }
    </script>
</body>
</html>
</body>
</html> 