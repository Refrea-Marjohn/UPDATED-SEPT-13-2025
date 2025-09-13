<?php
require_once 'session_manager.php';
validateUserAccess('employee');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';
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

// Log activity function for document actions
function log_activity($conn, $doc_id, $action, $user_id, $user_name, $form_number, $file_name) {
    $stmt = $conn->prepare("INSERT INTO employee_document_activity (document_id, action, user_id, user_name, form_number, file_name) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isisis', $doc_id, $action, $user_id, $user_name, $form_number, $file_name);
    $stmt->execute();
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $fileName = basename($_FILES['document']['name']);
    $targetDir = 'uploads/employee/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $targetFile = $targetDir . time() . '_' . $fileName;
    $formNumber = !empty($_POST['form_number']) ? intval($_POST['form_number']) : null;
    $uploadedBy = $_SESSION['user_id'] ?? 1; // fallback to 1 if not set
    $user_name = $_SESSION['employee_name'] ?? 'Employee';
    // Check for duplicate form number
    $dupCheck = $conn->prepare("SELECT id FROM employee_documents WHERE form_number = ?");
    $dupCheck->bind_param('i', $formNumber);
    $dupCheck->execute();
    $dupCheck->store_result();
    if ($dupCheck->num_rows > 0) {
        $error = 'A document with Form Number ' . $formNumber . ' already exists!';
    } else {
        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
            $stmt = $conn->prepare("INSERT INTO employee_documents (file_name, file_path, category, uploaded_by, form_number) VALUES (?, ?, 'Forms', ?, ?)");
            $stmt->bind_param('ssii', $fileName, $targetFile, $uploadedBy, $formNumber);
            $stmt->execute();
            $doc_id = $conn->insert_id;
            log_activity($conn, $doc_id, 'Uploaded', $uploadedBy, $user_name, $formNumber, $fileName);
            
            // Log to audit trail
            global $auditLogger;
            $auditLogger->logAction(
                $uploadedBy,
                $user_name,
                'employee',
                'Document Upload',
                'Document Management',
                "Uploaded document: $fileName (Form #: $formNumber)",
                'success',
                'medium'
            );
            
            $success = 'Document uploaded successfully!';
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
    $uploadedBy = $_SESSION['user_id'] ?? 1; // Ensure user_id is set
    $user_name = $_SESSION['employee_name'] ?? 'Employee';
    // Check for duplicate form number (excluding self)
    $dupCheck = $conn->prepare("SELECT id FROM employee_documents WHERE form_number = ? AND id != ?");
    $dupCheck->bind_param('ii', $new_form_number, $edit_id);
    $dupCheck->execute();
    $dupCheck->store_result();
    if ($dupCheck->num_rows > 0) {
        $error = 'A document with Form Number ' . $new_form_number . ' already exists!';
    } else {
        $stmt = $conn->prepare("UPDATE employee_documents SET file_name=?, form_number=? WHERE id=?");
        $stmt->bind_param('sii', $new_name, $new_form_number, $edit_id);
        $stmt->execute();
        log_activity($conn, $edit_id, 'Edited', $uploadedBy, $user_name, $new_form_number, $new_name);
        
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $uploadedBy,
            $user_name,
            'employee',
            'Document Edit',
            'Document Management',
            "Edited document: $new_name (Form #: $new_form_number)",
            'success',
            'medium'
        );
        
        header('Location: employee_documents.php');
        exit();
    }
}

// Date filter logic
$filter_from = isset($_GET['filter_from']) ? $_GET['filter_from'] : '';
$filter_to = isset($_GET['filter_to']) ? $_GET['filter_to'] : '';
$where = '';
if ($filter_from && $filter_to) {
    $where = "WHERE DATE(upload_date) >= '" . $conn->real_escape_string($filter_from) . "' AND DATE(upload_date) <= '" . $conn->real_escape_string($filter_to) . "'";
} elseif ($filter_from) {
    $where = "WHERE DATE(upload_date) = '" . $conn->real_escape_string($filter_from) . "'";
} elseif ($filter_to) {
    $where = "WHERE DATE(upload_date) <= '" . $conn->real_escape_string($filter_to) . "'";
}
$documents = [];
if (!empty($where)) {
    $stmt = $conn->prepare("SELECT * FROM employee_documents $where ORDER BY form_number ASC");
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $stmt = $conn->prepare("SELECT * FROM employee_documents ORDER BY form_number ASC");
    $stmt->execute();
    $result = $stmt->get_result();
}
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("SELECT file_path, file_name, form_number, uploaded_by FROM employee_documents WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        @unlink($row['file_path']);
        $user_name = $_SESSION['employee_name'] ?? 'Employee';
        log_activity($conn, $id, 'Deleted', $row['uploaded_by'], $user_name, $row['form_number'], $row['file_name']);
        
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $_SESSION['user_id'] ?? $row['uploaded_by'],
            $user_name,
            'employee',
            'Document Delete',
            'Document Management',
            "Deleted document: {$row['file_name']} (Form #: {$row['form_number']})",
            'success',
            'high' // HIGH priority for deletions
        );
    }
    $stmt = $conn->prepare("DELETE FROM employee_documents WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header('Location: employee_documents.php');
    exit();
}

// Fetch recent activity
$activity = [];
$actRes = $conn->query("SELECT * FROM employee_document_activity ORDER BY timestamp DESC LIMIT 10");
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
            <li><a href="employee_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="employee_documents.php" class="active"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="employee_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generations</span></a></li>
            <li><a href="employee_schedule.php"><i class="fas fa-calendar-alt"></i><span>Schedule</span></a></li>
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
                <h1>Document Storage</h1>
                <p>Manage and organize document storage</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Employee" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['employee_name']; ?></h3>
                    <p>Employee</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary" title="Upload a new document" style="font-size:1.1em; padding:10px 22px; box-shadow:0 2px 8px rgba(25,118,210,0.08);">
                <i class="fas fa-upload"></i> <span style="margin-left:6px;">Upload Document</span>
            </button>
            <form method="post" action="download_all_employee_documents.php" style="display:inline;" onsubmit="return confirmDownloadAll();">
                <button type="submit" class="btn btn-secondary" style="margin-left:10px; font-size:1.1em; padding:10px 22px;">
                    <i class="fas fa-download"></i> <span style="margin-left:6px;">Download All</span>
                </button>
            </form>
            <div class="search-box" style="max-width:350px;">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search documents by name or form number..." style="padding-right:30px;">
                <button type="button" onclick="document.getElementById('searchInput').value='';filterDocuments();" style="position:absolute;right:5px;top:50%;transform:translateY(-50%);background:none;border:none;color:#888;font-size:1.1em;cursor:pointer;" title="Clear search"><i class="fas fa-times"></i></button>
            </div>
        </div>

        <!-- Document Categories -->
        <div class="document-categories" style="align-items:center;">
            <div class="category active">
                <i class="fas fa-file-signature"></i>
                <span>Forms</span>
                <span class="count"><?php echo count($documents); ?></span>
            </div>
            <form method="get" class="date-filter-form-compact" style="display:flex;align-items:center;gap:10px;margin-left:18px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(25,118,210,0.08);padding:7px 16px;">
                <span style="font-weight:500;color:#1976d2;font-size:1em;margin-right:6px;"><i class='fas fa-calendar-alt'></i></span>
                <label for="filter_from" style="margin:0 2px 0 0;font-size:0.97em;color:#333;font-weight:500;">From</label>
                <input type="date" id="filter_from" name="filter_from" value="<?= isset($_GET['filter_from']) ? htmlspecialchars($_GET['filter_from']) : '' ?>" style="padding:5px 10px;font-size:0.97em;border:1px solid #d0d0d0;border-radius:5px;background:#f8f8f8;outline:none;">
                <label for="filter_to" style="margin:0 2px 0 8px;font-size:0.97em;color:#333;font-weight:500;">To</label>
                <input type="date" id="filter_to" name="filter_to" value="<?= isset($_GET['filter_to']) ? htmlspecialchars($_GET['filter_to']) : '' ?>" style="padding:5px 10px;font-size:0.97em;border:1px solid #d0d0d0;border-radius:5px;background:#f8f8f8;outline:none;">
                <button type="submit" class="btn btn-secondary" style="padding:6px 16px;font-size:1em;border-radius:5px;display:flex;align-items:center;gap:6px;"><i class="fas fa-filter"></i> Filter</button>
                <?php if (!empty($_GET['filter_from']) || !empty($_GET['filter_to'])): ?>
                    <a href="employee_documents.php" class="btn btn-clear" style="padding:6px 14px;font-size:1em;border-radius:5px;margin-left:4px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Documents Grid -->
        <div class="documents-grid">
            <?php foreach ($documents as $doc): ?>
                <div class="document-card" style="position:relative;">
                    <div class="document-icon" title="File type: <?= pathinfo($doc['file_name'], PATHINFO_EXTENSION) ?>">
                        <?php $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION)); ?>
                        <?php if($ext === 'pdf'): ?>
                            <i class="fas fa-file-pdf" style="color:#d32f2f;"></i>
                        <?php elseif($ext === 'doc' || $ext === 'docx'): ?>
                            <i class="fas fa-file-word" style="color:#1976d2;"></i>
                        <?php elseif($ext === 'xls' || $ext === 'xlsx'): ?>
                            <i class="fas fa-file-excel" style="color:#388e3c;"></i>
                        <?php else: ?>
                            <i class="fas fa-file-alt"></i>
                        <?php endif; ?>
                    </div>
                    <div class="document-info">
                        <h3><?= htmlspecialchars($doc['file_name']) ?></h3>
                        <p>Form #<?= htmlspecialchars($doc['form_number']) ?></p>
                        <div class="document-meta">
                            <span><i class="fas fa-user"></i> <?= htmlspecialchars($doc['uploaded_by']) ?></span>
                            <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($doc['upload_date']) ?></span>
                        </div>
                    </div>
                    <div class="document-actions" style="gap:8px;">
                        <a class="btn btn-icon" href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" title="View document"><i class="fas fa-eye"></i></a>
                        <a class="btn btn-icon" href="<?= htmlspecialchars($doc['file_path']) ?>" download title="Download document"><i class="fas fa-download"></i></a>
                        <a class="btn btn-icon" href="?delete=<?= $doc['id'] ?>" onclick="return confirm('Delete this document?')" title="Delete document"><i class="fas fa-trash"></i></a>
                        <a class="btn btn-icon" href="#" onclick="openEditModal('<?= $doc['id'] ?>','<?= htmlspecialchars($doc['file_name'], ENT_QUOTES) ?>','<?= htmlspecialchars($doc['form_number'], ENT_QUOTES) ?>');return false;" title="Edit document"><i class="fas fa-edit"></i></a>
                    </div>
                </div>
            <?php endforeach; ?>
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
                        <td><?= htmlspecialchars($act['user_name'] ?? 'Employee') ?></td>
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
            z-index: 10000;
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
            /* removed */
        }
        .date-filter-form-original {
            /* removed */
        }
        .date-filter-form-original label {
            /* removed */
        }
        .date-filter-form-original input[type="month"],
        .date-filter-form-original input[type="number"] {
            /* removed */
        }
        .date-filter-form-original .btn-secondary {
            /* removed */
        }
        .date-filter-form-original .btn-secondary:hover {
            /* removed */
        }
        .date-filter-form-original .btn-clear {
            /* removed */
        }
        .date-filter-form-original .btn-clear:hover {
            /* removed */
        }
        .date-filter-year {
            /* removed */
        }
    </style>
</body>
</html> 