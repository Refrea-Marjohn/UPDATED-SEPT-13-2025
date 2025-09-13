<?php
require_once 'session_manager.php';
validateUserAccess('attorney');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';
$attorney_id = $_SESSION['user_id'];
$res = $conn->query("SELECT profile_image FROM user_form WHERE id=$attorney_id");
$profile_image = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
}
if (!$profile_image || !file_exists($profile_image)) {
        $profile_image = 'images/default-avatar.jpg';
    }

// Log activity function for document actions
function log_attorney_activity($conn, $doc_id, $action, $user_id, $user_name, $case_id, $file_name, $category) {
    $stmt = $conn->prepare("INSERT INTO attorney_document_activity (document_id, action, user_id, user_name, case_id, file_name, category) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isissss', $doc_id, $action, $user_id, $user_name, $case_id, $file_name, $category);
    $stmt->execute();
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $docName = trim($_POST['doc_name']);
    $fileInfo = pathinfo($_FILES['document']['name']);
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    $safeDocName = preg_replace('/[^A-Za-z0-9 _\-]/', '', $docName); // remove special chars
    $fileName = $safeDocName . $extension;
    $targetDir = 'uploads/attorney/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $targetFile = $targetDir . time() . '_' . $fileName;
    $category = $_POST['category'];
    $uploadedBy = $_SESSION['user_id'] ?? 1; // fallback to 1 if not set
    $user_name = $_SESSION['attorney_name'] ?? 'Attorney';
    $caseId = !empty($_POST['case_id']) ? intval($_POST['case_id']) : null;
    if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
        $stmt = $conn->prepare("INSERT INTO attorney_documents (file_name, file_path, category, uploaded_by, case_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssii', $fileName, $targetFile, $category, $uploadedBy, $caseId);
        $stmt->execute();
        $doc_id = $conn->insert_id;
        log_attorney_activity($conn, $doc_id, 'Uploaded', $uploadedBy, $user_name, $caseId, $fileName, $category);
        
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $uploadedBy,
            $user_name,
            'attorney',
            'Document Upload',
            'Document Management',
            "Uploaded document: $fileName (Category: $category, Case ID: $caseId)",
            'success',
            'medium'
        );
        
        $success = 'Document uploaded successfully!';
        header('Location: attorney_documents.php');
        exit();
    } else {
        $error = 'Failed to upload document.';
    }
}

// Handle edit
if (isset($_POST['edit_id'])) {
    $edit_id = intval($_POST['edit_id']);
    $new_name = trim($_POST['edit_file_name']);
    $new_category = trim($_POST['edit_category']);
    $new_case_id = !empty($_POST['edit_case_id']) ? intval($_POST['edit_case_id']) : null;
    $uploadedBy = $_SESSION['user_id'] ?? 1;
    $user_name = $_SESSION['attorney_name'] ?? 'Attorney';
    $stmt = $conn->prepare("SELECT category FROM attorney_documents WHERE id=?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $old_category = '';
    if ($res && $row = $res->fetch_assoc()) {
        $old_category = $row['category'];
    }
    $stmt = $conn->prepare("UPDATE attorney_documents SET file_name=?, category=?, case_id=? WHERE id=?");
    $stmt->bind_param('ssii', $new_name, $new_category, $new_case_id, $edit_id);
    $stmt->execute();
    log_attorney_activity($conn, $edit_id, 'Edited', $uploadedBy, $user_name, $new_case_id, $new_name, $new_category);
    
    // Log to audit trail
    global $auditLogger;
    $auditLogger->logAction(
        $uploadedBy,
        $user_name,
        'attorney',
        'Document Edit',
        'Document Management',
        "Edited document: $new_name (Category: $new_category, Case ID: $new_case_id)",
        'success',
        'medium'
    );
    
    header('Location: attorney_documents.php');
    exit();
}

// Fetch documents for display
$documents = [];
$result = $conn->query("SELECT * FROM attorney_documents ORDER BY upload_date DESC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
}

// Count documents per category
$category_counts = [
    'All Documents' => count($documents),
    'Case Files' => 0,
    'Court Documents' => 0,
    'Client Documents' => 0
];
foreach ($documents as $doc) {
    if (isset($category_counts[$doc['category']])) {
        $category_counts[$doc['category']]++;
    }
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("SELECT file_path, file_name, case_id, uploaded_by, category FROM attorney_documents WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        @unlink($row['file_path']);
        $user_name = $_SESSION['attorney_name'] ?? 'Attorney';
        log_attorney_activity($conn, $id, 'Deleted', $row['uploaded_by'], $user_name, $row['case_id'], $row['file_name'], $row['category']);
        
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $_SESSION['user_id'] ?? $row['uploaded_by'],
            $user_name,
            'attorney',
            'Document Delete',
            'Document Management',
            "Deleted document: {$row['file_name']} (Category: {$row['category']}, Case ID: {$row['case_id']})",
            'success',
            'high' // HIGH priority for deletions
        );
    }
    $stmt = $conn->prepare("DELETE FROM attorney_documents WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header('Location: attorney_documents.php');
    exit();
}

// Fetch recent activity
$activity = [];
$actRes = $conn->query("SELECT * FROM attorney_document_activity ORDER BY timestamp DESC LIMIT 10");
if ($actRes && $actRes->num_rows > 0) {
    while ($row = $actRes->fetch_assoc()) {
        // $row['category'] is already set from the activity table
        $activity[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Management - Opiña Law Office</title>
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
                <h1>Document Management</h1>
                <p>Manage and organize your case documents</p>
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
            <button class="btn btn-primary" onclick="openUploadModal()">
                <i class="fas fa-upload"></i> Upload Document
            </button>
            <form method="post" action="download_all_attorney_documents.php" style="display:flex;align-items:center;" onsubmit="return confirmDownloadAll();">
                <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-download"></i> Download All
                </button>
            </form>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search documents by name or category...">
                <button type="button" onclick="document.getElementById('searchInput').value='';filterDocuments();" title="Clear search"><i class="fas fa-times"></i></button>
            </div>
        </div>

        <!-- Document Categories -->
        <div class="document-categories">
            <div class="category active">
                <span class="badge"><?= $category_counts['All Documents'] ?></span>
                <span>All Documents</span>
            </div>
            <div class="category">
                <span class="badge"><?= $category_counts['Case Files'] ?></span>
                <span>Case Files</span>
            </div>
            <div class="category">
                <span class="badge"><?= $category_counts['Court Documents'] ?></span>
                <span>Court Documents</span>
            </div>
            <div class="category">
                <span class="badge"><?= $category_counts['Client Documents'] ?></span>
                <span>Client Documents</span>
            </div>
        </div>

        <!-- Documents Grid -->
        <div class="documents-grid">
            <?php foreach ($documents as $doc): ?>
                <?php $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION)); ?>
                <div class="document-card custom-doc-card">
                    <div class="doc-card-main">
                        <div class="doc-card-icon">
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
                        <div class="doc-card-info">
                            <div class="doc-card-title">
                                <?= htmlspecialchars(pathinfo($doc['file_name'], PATHINFO_FILENAME)) ?>
                                <span style="font-size:0.95em;color:#888;margin-left:6px;">.<?= $ext ?></span>
                            </div>
                            <div class="doc-card-form"><?= htmlspecialchars($doc['category'] ?? '-') ?></div>
                            <div class="doc-card-meta">
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($doc['uploaded_by']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($doc['upload_date']) ?></span>
                            </div>
                        </div>
                        <div class="doc-card-actions">
                            <a class="btn btn-icon" href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" title="View document"><i class="fas fa-eye"></i></a>
                            <a class="btn btn-icon" href="<?= htmlspecialchars($doc['file_path']) ?>" download title="Download document"><i class="fas fa-download"></i></a>
                            <a class="btn btn-icon" href="?delete=<?= $doc['id'] ?>" onclick="return confirm('Delete this document?')" title="Delete document"><i class="fas fa-trash"></i></a>
                            <a class="btn btn-icon" href="#" onclick="openEditModal('<?= $doc['id'] ?>','<?= htmlspecialchars($doc['file_name'], ENT_QUOTES) ?>','<?= htmlspecialchars($doc['category'], ENT_QUOTES) ?>','<?= htmlspecialchars($doc['case_id'], ENT_QUOTES) ?>');return false;" title="Edit document"><i class="fas fa-edit"></i></a>
                        </div>
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
                        <th>Category</th>
                        <th>User</th>
                        <th>Case</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $act): ?>
                    <tr>
                        <td><?= htmlspecialchars($act['timestamp']) ?></td>
                        <td><?= htmlspecialchars($act['file_name']) ?></td>
                        <td><span class="status-badge status-<?= strtolower($act['action']) ?>" style="padding:3px 10px;border-radius:8px;font-weight:500;<?php if(strtolower($act['action'])=='uploaded'){echo 'background:#eaffea;color:#388e3c;';}elseif(strtolower($act['action'])=='deleted'){echo 'background:#ffeaea;color:#d32f2f;';}elseif(strtolower($act['action'])=='edited'){echo 'background:#fff8e1;color:#fbc02d;';} ?>">
                            <i class="fas fa-<?= strtolower($act['action'])=='uploaded'?'arrow-up':'edit' ?>"></i> <?= htmlspecialchars($act['action']) ?></span></td>
                        <td><?= htmlspecialchars($act['category']) ?></td>
                        <td><?= htmlspecialchars($act['user_name'] ?? 'Attorney') ?></td>
                        <td><?= htmlspecialchars($act['case_id']) ?></td>
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
            <?php if (!empty($error)) echo '<div class="alert-error"><i class="fas fa-exclamation-circle"></i> ' . $error . '</div>'; ?>
            <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:12px;">
                <label>Document Name</label>
                <input type="text" name="doc_name" placeholder="Enter document name" required>
                <label>Category</label>
                <select name="category" required>
                    <option value="">Select Category</option>
                    <option value="Case Files">Case Files</option>
                    <option value="Court Documents">Court Documents</option>
                    <option value="Client Documents">Client Documents</option>
                </select>
                <label>Case ID (optional)</label>
                <input type="number" name="case_id" placeholder="Enter case ID">
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
                <label>Category</label>
                <select name="edit_category" id="edit_category" required>
                    <option value="Case Files">Case Files</option>
                    <option value="Court Documents">Court Documents</option>
                    <option value="Client Documents">Client Documents</option>
                </select>
                <label>Case ID (optional)</label>
                <input type="number" name="edit_case_id" id="edit_case_id">
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
        function openEditModal(id, name, category, caseId) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_file_name').value = name;
            document.getElementById('edit_category').value = category;
            document.getElementById('edit_case_id').value = caseId || '';
            document.getElementById('editModal').style.display = 'block';
        }
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        function confirmDownloadAll() {
            return confirm('Do you want to download all the files?');
        }
        function openDeleteModal() { document.getElementById('deleteModal').style.display = 'block'; }
        function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
        function openAccessModal() { document.getElementById('accessModal').style.display = 'block'; }
        function closeAccessModal() { document.getElementById('accessModal').style.display = 'none'; }
        function openUploadModal() { document.getElementById('uploadModal').style.display = 'block'; }
        function closeUploadModal() { document.getElementById('uploadModal').style.display = 'none'; }

        // Category filter
        var categoryButtons = document.querySelectorAll('.category');
        categoryButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var cat = btn.querySelector('span:last-child').textContent.trim();
                document.querySelectorAll('.document-card').forEach(function(card) {
                    var cardCat = card.querySelector('.doc-card-form').textContent.trim();
                    if (cat === 'All Documents' || cardCat === cat) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
                categoryButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Search/filter logic
        function filterDocuments() {
            var input = document.getElementById('searchInput').value.toLowerCase();
            var cards = document.querySelectorAll('.document-card');
            cards.forEach(function(card) {
                var name = card.querySelector('.doc-card-title').textContent.toLowerCase();
                var cat = card.querySelector('.doc-card-form').childNodes[0].textContent.toLowerCase();
                card.style.display = (name.includes(input) || cat.includes(input)) ? '' : 'none';
            });
        }
        document.getElementById('searchInput').addEventListener('input', filterDocuments);
    </script>
    <style>
        .action-buttons {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 18px;
        }
        .action-buttons .btn-primary {
            font-size: 1.08em;
            padding: 10px 22px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-buttons .btn-secondary {
            font-size: 1.08em;
            background: #222;
            color: #fff;
            padding: 10px 22px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-buttons .search-box {
            position: relative;
            max-width: 220px;
            width: 220px;
            margin-left: 0;
        }
        .action-buttons .search-box input {
            width: 100%;
            padding: 9px 38px 9px 38px;
            border-radius: 7px;
            border: 1px solid #d0d0d0;
            font-size: 1em;
        }
        .action-buttons .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }
        .action-buttons .search-box button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #888;
            font-size: 1.1em;
            cursor: pointer;
        }

        .document-categories {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding: 10px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .category {
            padding: 10px 15px;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .category.active {
            background-color: var(--secondary-color);
            color: white;
        }

        .badge {
            background-color: var(--light-gray);
            color: var(--text-color);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }

        .category.active .badge {
            background-color: white;
            color: var(--secondary-color);
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .custom-doc-card {
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(25,118,210,0.08);
            background: #fff;
            padding: 18px 18px 18px 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            min-height: 120px;
        }
        .doc-card-main {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .doc-card-icon {
            font-size: 2.7rem;
            margin-right: 18px;
            color: #1976d2;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
        }
        .doc-card-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 2px;
            color: #222;
        }
        .doc-card-form {
            font-size: 1rem;
            color: #444;
            margin-bottom: 8px;
        }
        .doc-card-meta {
            display: flex;
            gap: 16px;
            font-size: 0.95rem;
            color: #555;
            align-items: center;
        }
        .doc-card-meta i {
            margin-right: 4px;
        }
        .doc-card-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-left: auto;
            align-items: center;
        }
        .doc-card-actions .btn-icon i {
            color: #1a2edb !important;
            font-size: 1.5rem;
            transition: color 0.2s;
        }
        .doc-card-actions .btn-icon:hover i {
            color: #0d1a8c !important;
        }
        @media (max-width: 700px) {
            .doc-card-main { flex-direction: column; align-items: flex-start; }
            .doc-card-actions { flex-direction: row; margin-left: 0; margin-top: 10px; }
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
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 110px;
            padding: 6px 14px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 1em;
            gap: 7px;
            text-align: center;
            vertical-align: middle;
            box-sizing: border-box;
        }
        .status-badge i {
            font-size: 1.1em;
            margin-right: 6px;
            vertical-align: middle;
        }
    </style>
</body>
</html> 