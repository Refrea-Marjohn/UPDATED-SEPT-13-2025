<?php
session_start();
require_once 'config.php';

// Access control: only attorneys (or admin_attorney) allowed
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['attorney', 'admin_attorney'])) {
    header('Location: login_form.php');
    exit();
}

$attorney_id = (int)$_SESSION['user_id'];

// Ensure efiling_history table exists (idempotent safety)
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS efiling_history (
  id int(11) NOT NULL AUTO_INCREMENT,
  attorney_id int(11) NOT NULL,
  case_id int(11) DEFAULT NULL,
  case_number varchar(100) NOT NULL,
  file_name varchar(255) NOT NULL,
  receiver_email varchar(255) NOT NULL,
  message text,
  status enum('Sent','Failed') NOT NULL DEFAULT 'Sent',
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY attorney_id (attorney_id),
  KEY case_id (case_id),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// Get profile image
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

// Fetch cases for dropdown (owned by this attorney) with client information
$cases = [];
$stmt = $conn->prepare("
    SELECT 
        c.id, 
        c.title, 
        c.case_type,
        u.name as client_name
    FROM attorney_cases c
    LEFT JOIN user_form u ON c.client_id = u.id
    WHERE c.attorney_id=? 
    ORDER BY c.title ASC
");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $cases[] = $row;

            // Fetch recent eFiling history for this attorney with case information
            $history = [];
            $stmt = $conn->prepare("
                SELECT 
                    ef.id, 
                    ef.case_id,
                    ef.case_number, 
                    ef.file_name, 
                    ef.original_file_name, 
                    ef.stored_file_path, 
                    ef.receiver_email, 
                    ef.status, 
                    ef.created_at,
                    c.title as case_title,
                    c.case_type,
                    u.name as client_name
                FROM efiling_history ef
                LEFT JOIN attorney_cases c ON ef.case_id = c.id
                LEFT JOIN user_form u ON c.client_id = u.id
                WHERE ef.attorney_id=? 
                ORDER BY ef.created_at DESC 
                LIMIT 100
            ");
            $stmt->bind_param("i", $attorney_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $history[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Filing - Opiña Law Office</title>
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
                <h1>E-Filing</h1>
                <p>Securely submit documents via official firm email</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Attorney" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['attorney_name'] ?? $_SESSION['user_name']; ?></h3>
                    <p>Attorney</p>
                </div>
            </div>
        </div>

        <!-- eFiling Card -->
        <div class="efiling-grid">
            <div class="efiling-card">
                <div class="card-header">
                    <h2><i class="fas fa-paper-plane"></i> New eFiling Submission</h2>
                    <p>Send documents via the firm's official Gmail. Fields marked with * are required.</p>
                </div>
                <div class="card-body">
                    <form id="efilingForm" enctype="multipart/form-data">
                        <div class="form-row">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; margin-bottom: 8px;">
                                    <i class="fas fa-gavel" style="color: #7C0F2F; margin-right: 8px; font-size: 16px;"></i>
                                    Case Selection (Optional)
                                    <span style="margin-left: auto; font-size: 12px; color: #6c757d; font-weight: normal;">
                                        <?= count($cases) ?> case(s) available
                                    </span>
                                </label>
                                <div style="position: relative;">
                                    <!-- Custom Dropdown Container -->
                                    <div class="custom-dropdown" style="position: relative;">
                                        <!-- Dropdown Trigger -->
                                        <div id="case_dropdown_trigger" style="width: 100%; padding: 15px 20px; border: 2px solid #e9ecef; border-radius: 12px; font-size: 15px; background: white; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: space-between;" onclick="toggleCaseDropdown()">
                                            <span id="case_selected_text" style="color: #6c757d; font-style: italic;">📋 Select a case to associate with this eFiling...</span>
                                            <i class="fas fa-chevron-down" id="case_dropdown_arrow" style="color: #6c757d; transition: transform 0.3s ease;"></i>
                                        </div>
                                        
                                        <!-- Hidden Input -->
                                        <input type="hidden" name="case_id" id="case_id_input" value="">
                                        
                                        <!-- Dropdown Menu -->
                                        <div id="case_dropdown_menu" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 2px solid #e9ecef; border-top: none; border-radius: 0 0 12px 12px; box-shadow: 0 8px 25px rgba(0,0,0,0.15); z-index: 1000; max-height: 300px; overflow: hidden; display: none;">
                                            <!-- Search Box Inside Dropdown -->
                                            <div style="padding: 15px; border-bottom: 1px solid #f0f2f5; background: #f8f9fa;">
                                                <div style="position: relative;">
                                                    <input type="text" id="case_search" placeholder="🔍 Search cases..." style="width: 100%; padding: 10px 15px 10px 40px; border: 1px solid #e9ecef; border-radius: 8px; font-size: 14px; background: white;" onkeyup="filterCasesInDropdown()" onfocus="this.style.borderColor='#7C0F2F'" onblur="this.style.borderColor='#e9ecef'">
                                                    <i class="fas fa-search" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #6c757d; font-size: 14px;"></i>
                                                </div>
                                                <div id="search_results_count" style="font-size: 12px; color: #6c757d; margin-top: 8px; text-align: right;">
                                                    <?= count($cases) ?> cases available
                                                </div>
                                            </div>
                                            
                                            <!-- Cases List -->
                                            <div id="cases_list" style="max-height: 200px; overflow-y: auto;">
                                                <?php 
                                                // Group cases by type for better organization
                                                $grouped_cases = [];
                                                foreach ($cases as $c) {
                                                    $type = $c['case_type'] ?: 'Other';
                                                    $grouped_cases[$type][] = $c;
                                                }
                                                ksort($grouped_cases);
                                                
                                                foreach ($grouped_cases as $type => $type_cases): 
                                                ?>
                                                    <div class="case-group" data-type="<?= strtolower($type) ?>" style="border-bottom: 1px solid #f0f2f5;">
                                                        <div style="padding: 10px 15px; background: #f8f9fa; font-weight: 600; color: #2c3e50; font-size: 13px; border-bottom: 1px solid #e9ecef;">
                                                            🏛️ <?= htmlspecialchars(ucfirst($type)) ?> Cases (<?= count($type_cases) ?>)
                                                        </div>
                                                        <?php foreach ($type_cases as $c): ?>
                                                        <div class="case-option" data-id="<?= $c['id'] ?>" data-title="<?= htmlspecialchars($c['title']) ?>" data-type="<?= htmlspecialchars($c['case_type'] ?? '') ?>" data-client="<?= htmlspecialchars($c['client_name'] ?? '') ?>" style="padding: 12px 15px; cursor: pointer; transition: background 0.2s ease; border-bottom: 1px solid #f8f9fa;" onmouseover="this.style.background='#e3f2fd'" onmouseout="this.style.background='white'" onclick="selectCase(<?= $c['id'] ?>, '<?= htmlspecialchars($c['title']) ?>', '<?= htmlspecialchars($c['case_type'] ?? '') ?>', '<?= htmlspecialchars($c['client_name'] ?? '') ?>')">
                                                            <div style="color: #2c3e50; font-weight: 600; font-size: 14px;">#<?= $c['id'] ?> — <?= htmlspecialchars($c['title']) ?></div>
                                                            <?php if (!empty($c['client_name'])): ?>
                                                                <div style="color: #6c757d; font-size: 12px; margin-top: 2px;">👤 <?= htmlspecialchars($c['client_name']) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Compact Case Details Card -->
                                <div id="case_details" style="margin-top: 10px; padding: 12px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px; border-left: 3px solid #7C0F2F; display: none;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                        <h5 style="margin: 0; color: #2c3e50; font-size: 14px; font-weight: 600;" id="case_title_display"></h5>
                                        <span style="background: #7C0F2F; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600;">ACTIVE</span>
                                    </div>
                                    
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 12px;">
                                        <div><strong style="color: #007bff;">ID:</strong> <span id="case_id_display"></span></div>
                                        <div><strong style="color: #28a745;">Type:</strong> <span id="case_type_display"></span></div>
                                        <div style="grid-column: 1 / -1;"><strong style="color: #6f42c1;">Client:</strong> <span id="case_client_display"></span></div>
                                    </div>
                                </div>
                </div>
                            <div class="form-group">
                                <label>Case Number (optional)</label>
                                <input type="text" name="case_number" placeholder="e.g., 2025-CR-001">
                </div>
            </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Receiver Email *</label>
                                <input type="email" name="receiver_email" id="receiver_email" required>
                                <small class="hint">Input the court/recipient email</small>
                </div>
                            <div class="form-group">
                                <label>Confirm Receiver Email *</label>
                                <input type="email" name="receiver_email_confirm" id="receiver_email_confirm" required>
                                <small id="emailMatchMsg" class="warn" style="display:none;">Emails do not match.</small>
            </div>
        </div>

                        <div class="form-row">
                        <div class="form-group">
                                <label>File Name To Use (with extension) *</label>
                                <input type="text" name="desired_filename" id="desired_filename" placeholder="CaseNumber_DocumentType.pdf" required>
                                <small class="hint">This exact name will be used for the attachment</small>
                        </div>
                        <div class="form-group">
                                <label>Upload Document (PDF only) *</label>
                                <input type="file" name="document" id="document" accept="application/pdf,.pdf" required>
                                <small class="hint">Allowed: PDF only • Max 10MB</small>
                        </div>
                        </div>

                        <div class="form-group">
                            <label>Message (optional)</label>
                            <textarea name="message" rows="3" placeholder="Additional notes to include in email body (optional)"></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="sendBtn">
                                <i class="fas fa-paper-plane"></i> Send eFiling
                            </button>
                            <button type="reset" class="btn btn-secondary" id="resetBtn">Clear</button>
                        </div>
                    </form>
                    <div id="resultMsg" class="result" style="display:none;"></div>
                </div>
            </div>

            <div class="efiling-card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> Submission History</h2>
                    <button class="btn-clear-history" onclick="clearHistoryPermanently()">
                        <i class="fas fa-trash"></i> Clear All History
                    </button>
                </div>
                <div class="card-body">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>File Name (Used)</th>
                                <th>Original File Name</th>
                                <th>Receiver Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr><td colspan="6" style="text-align:center;color:#666;padding:20px;">No submissions yet</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?= date('M d, Y g:i A', strtotime($h['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($h['file_name']) ?></td>
                                    <td><?= htmlspecialchars($h['original_file_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($h['receiver_email']) ?></td>
                                    <td><span class="status-badge status-<?= strtolower($h['status']) ?>"><?= htmlspecialchars($h['status']) ?></span></td>
                                    <td>
                                        <button onclick="viewCaseInfo(<?= $h['case_id'] ?? 'null' ?>, '<?= htmlspecialchars($h['case_title'] ?? '') ?>', '<?= htmlspecialchars($h['case_type'] ?? '') ?>', '<?= htmlspecialchars($h['client_name'] ?? '') ?>', '<?= htmlspecialchars($h['case_number'] ?? '') ?>')" class="btn-view-case" title="View Case Info">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                        <?php if (!empty($h['stored_file_path']) && file_exists($h['stored_file_path'])): ?>
                                            <a href="view_efiling_file.php?id=<?= $h['id'] ?>" target="_blank" class="btn-view-file" title="View File">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="download_efiling_file.php?id=<?= $h['id'] ?>" class="btn-download-file" title="Download File">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:12px;">File not stored (old submission)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <style>
        .efiling-grid { display: grid; grid-template-columns: 1fr; gap: 24px; }
        .efiling-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 6px 18px rgba(16,24,40,0.06); }
        .efiling-card .card-header { padding: 16px 20px; border-bottom: 1px solid #f0f2f5; }
        .efiling-card .card-header { display: flex; justify-content: space-between; align-items: center; }
        .efiling-card .card-header h2 { margin: 0; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        .efiling-card .card-header p { margin: 6px 0 0 28px; color: #6b7280; font-size: 0.9rem; }
        .btn-clear-history { 
            background: #dc3545;
            color: white;
            border: none; 
            padding: 6px 12px; 
            border-radius: 6px; 
            font-size: 12px; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 6px;
            transition: all 0.3s ease;
        }
        .btn-clear-history:hover { 
            background: #c82333; 
            transform: translateY(-1px);
        }
        .efiling-card .card-body { padding: 20px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display:block; margin-bottom:8px; font-weight:600; color:#5D0E26; font-size:14px; text-transform:uppercase; letter-spacing:.5px; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:12px 14px; border:1px solid #d6d9de; background:#fafbfc; border-radius:10px; font-size:14px; box-shadow: inset 0 1px 2px rgba(16,24,40,0.04); }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:#8B1538; box-shadow: 0 0 0 4px rgba(139,21,56,.12); }
        .hint { color:#6b7280; font-size:12px; display:block; margin-top:6px; }
        .warn { color:#b91c1c; font-size:12px; }
        .form-actions { display:flex; gap:12px; justify-content:flex-end; border-top:1px solid #edf0f3; padding-top:16px; margin-top:8px; }
        .btn { border:none; border-radius:10px; padding:12px 18px; cursor:pointer; font-weight:600; }
        .btn-primary { background:#7C0F2F; color:#fff; }
        .btn-primary:hover { background:#8B1538; }
        .btn-secondary { background:#697586; color:#fff; }
        .history-table { width:100%; border-collapse: collapse; }
        .history-table th, .history-table td { border-bottom:1px solid #f0f2f5; padding:12px; text-align:left; font-size:14px; }
        .history-table th { color:#374151; font-weight:700; }
        .status-badge { padding:6px 10px; border-radius:12px; font-size:12px; font-weight:600; }
        .status-sent { background:#e8f5e9; color:#2e7d32; }
        .status-failed { background:#ffebee; color:#c62828; }
        .result { margin-top:12px; padding:12px; border-radius:8px; }
        .result.success { background:#e8f5e9; color:#2e7d32; }
        .result.error { background:#ffebee; color:#c62828; }
        .btn-view-file, .btn-download-file { 
            display:inline-flex; 
            align-items:center; 
            justify-content:center;
            padding:8px 12px; 
            margin:0 4px; 
            border-radius:8px; 
            text-decoration:none; 
            font-size:13px; 
            font-weight:600;
            transition:all 0.3s ease;
            box-shadow:0 2px 4px rgba(0,0,0,0.1);
            min-width:40px;
            height:32px;
        }
        .btn-view-file { 
            background:linear-gradient(135deg, #1976d2, #1565c0); 
            color:#fff; 
            border:1px solid #1976d2;
        }
        .btn-view-file:hover { 
            background:linear-gradient(135deg, #1565c0, #0d47a1); 
            transform:translateY(-1px);
            box-shadow:0 4px 8px rgba(25,118,210,0.3);
        }
        .btn-download-file { 
            background:linear-gradient(135deg, #28a745, #218838); 
            color:#fff; 
            border:1px solid #28a745;
        }
        .btn-download-file:hover { 
            background:linear-gradient(135deg, #218838, #1e7e34); 
            transform:translateY(-1px);
            box-shadow:0 4px 8px rgba(40,167,69,0.3);
        }
        .btn-view-case { 
            background:linear-gradient(135deg, #6c757d, #495057); 
            color:#fff; 
            border:1px solid #6c757d;
            display:inline-flex; 
            align-items:center; 
            justify-content:center;
            padding:8px 12px; 
            margin:0 4px; 
            border-radius:8px; 
            text-decoration:none; 
            font-size:13px; 
            font-weight:600;
            transition:all 0.3s ease;
            box-shadow:0 2px 4px rgba(0,0,0,0.1);
            min-width:40px;
            height:32px;
            cursor:pointer;
        }
        .btn-view-case:hover { 
            background:linear-gradient(135deg, #495057, #343a40); 
            transform:translateY(-1px);
            box-shadow:0 4px 8px rgba(108,117,125,0.3);
        }
        .case-info {
            font-size: 13px;
            line-height: 1.4;
        }
        .case-info strong {
            color: #2c3e50;
            font-weight: 600;
        }
        .case-info small {
            color: #6c757d;
            display: block;
            margin-top: 2px;
        }
        .case-number {
            color: #007bff !important;
            font-weight: 500;
        }
        .case-type {
            color: #28a745 !important;
            font-style: italic;
        }
        .client-name {
            color: #6f42c1 !important;
        }
        .no-case {
            color: #6c757d;
            font-style: italic;
            font-size: 12px;
        }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
    </style>

    <script>
        const email1 = document.getElementById('receiver_email');
        const email2 = document.getElementById('receiver_email_confirm');
        const matchMsg = document.getElementById('emailMatchMsg');
        const desiredFilename = document.getElementById('desired_filename');
        const fileInput = document.getElementById('document');
        const resultMsg = document.getElementById('resultMsg');
        const form = document.getElementById('efilingForm');

        function validateEmails() {
            const same = email1.value.trim().toLowerCase() === email2.value.trim().toLowerCase();
            matchMsg.style.display = same ? 'none' : 'block';
            return same;
        }
        email1.addEventListener('input', validateEmails);
        email2.addEventListener('input', validateEmails);

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function getExtension(name) {
            const idx = name.lastIndexOf('.');
            return idx >= 0 ? name.substring(idx + 1).toLowerCase() : '';
        }

        function ensureDesiredFilenameExtension(uploadedExt) {
            let name = desiredFilename.value.trim();
            if (!name) return;
            const ext = getExtension(name);
            if (!ext) {
                desiredFilename.value = name + '.' + uploadedExt;
                return;
            }
            if (ext !== uploadedExt) {
                desiredFilename.value = name.slice(0, -(ext.length + 1)) + '.' + uploadedExt;
            }
        }

        fileInput.addEventListener('change', function() {
            if (!fileInput.files.length) return;
            const uploadedExt = getExtension(fileInput.files[0].name);
            if (uploadedExt !== 'pdf') {
                resultMsg.className = 'result error';
                resultMsg.textContent = 'Only PDF files are allowed.';
                resultMsg.style.display = 'block';
                fileInput.value = '';
                return;
            }
            ensureDesiredFilenameExtension(uploadedExt);
            resultMsg.style.display = 'none';
        });

        // Clear button functionality
        document.getElementById('resetBtn').addEventListener('click', function() {
            form.reset();
            resultMsg.style.display = 'none';
            matchMsg.style.display = 'none';
        });

        // Clear history permanently functionality
        function clearHistoryPermanently() {
            if (confirm('Are you sure you want to PERMANENTLY DELETE all eFiling history? This action cannot be undone!')) {
                const formData = new FormData();
                formData.append('action', 'clear_history');
                
                fetch('process_efiling.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('All eFiling history has been permanently deleted.');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to clear history'));
                    }
                })
                .catch(() => {
                    alert('Unexpected error occurred');
                });
            }
        }

        function viewCaseInfo(caseId, caseTitle, caseType, clientName, caseNumber) {
            // Check if no case was selected
            if (!caseId || caseId === 'null' || caseId === null) {
                let modalContent = `
                    <div style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); padding: 0; border-radius: 16px; max-width: 450px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 8px 25px rgba(0,0,0,0.1); overflow: hidden; border: 1px solid rgba(255,255,255,0.2);">
                        <!-- Header -->
                        <div style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); padding: 20px 25px; color: white; position: relative;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="margin: 0; font-size: 22px; font-weight: 700; display: flex; align-items: center;">
                                    <i class="fas fa-info-circle" style="margin-right: 12px; font-size: 24px; opacity: 0.9;"></i>
                                    Case Information
                                </h3>
                                <button onclick="closeCaseModal()" style="background: rgba(255,255,255,0.2); border: none; font-size: 20px; color: white; cursor: pointer; padding: 8px 12px; border-radius: 8px; transition: all 0.3s ease; backdrop-filter: blur(10px);">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div style="position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);"></div>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 40px 25px; text-align: center;">
                            <div style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); padding: 30px; border-radius: 16px; border-left: 4px solid #ffc107; box-shadow: 0 8px 25px rgba(255, 193, 7, 0.2); margin-bottom: 25px;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f57c00; margin-bottom: 15px; display: block;"></i>
                                <h4 style="margin: 0 0 10px 0; color: #e65100; font-size: 18px; font-weight: 600;">No Case Selected</h4>
                                <p style="color: #bf9000; font-size: 14px; margin: 0; line-height: 1.5;">This eFiling submission was sent without associating it to any specific case.</p>
                            </div>
                            
                            <div style="background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%); padding: 20px; border-radius: 12px; border-left: 4px solid #2196f3;">
                                <i class="fas fa-lightbulb" style="color: #2196f3; font-size: 24px; margin-bottom: 10px;"></i>
                                <p style="color: #1976d2; font-size: 13px; margin: 0; font-weight: 500;">Tip: You can select a case when submitting eFiling documents for better organization.</p>
                            </div>
                        </div>
                        
                        <!-- Footer -->
                        <div style="padding: 20px 25px; border-top: 1px solid #e9ecef; text-align: center;">
                            <button onclick="closeCaseModal()" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white; border: none; padding: 12px 30px; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);">
                                <i class="fas fa-check" style="margin-right: 8px;"></i>
                                Close
                            </button>
                        </div>
                    </div>
                `;
                
                // Create modal overlay
                const modal = document.createElement('div');
                modal.id = 'caseModal';
                modal.style.cssText = `
                    position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                    background: rgba(0,0,0,0.5); display: flex; justify-content: center; 
                    align-items: center; z-index: 10000; backdrop-filter: blur(3px);
                `;
                modal.innerHTML = modalContent;
                document.body.appendChild(modal);
                
                // Close on overlay click
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) closeCaseModal();
                });
                return;
            }

            // Show case information if case was selected
            let modalContent = `
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); padding: 0; border-radius: 16px; max-width: 550px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 8px 25px rgba(0,0,0,0.1); overflow: hidden; border: 1px solid rgba(255,255,255,0.2);">
                    <!-- Header -->
                    <div style="background: linear-gradient(135deg, #7C0F2F 0%, #8B1538 100%); padding: 20px 25px; color: white; position: relative;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; font-size: 22px; font-weight: 700; display: flex; align-items: center;">
                                <i class="fas fa-gavel" style="margin-right: 12px; font-size: 24px; opacity: 0.9;"></i>
                                Case Information
                            </h3>
                            <button onclick="closeCaseModal()" style="background: rgba(255,255,255,0.2); border: none; font-size: 20px; color: white; cursor: pointer; padding: 8px 12px; border-radius: 8px; transition: all 0.3s ease; backdrop-filter: blur(10px);">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div style="position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);"></div>
                    </div>
                    
                    <!-- Content -->
                    <div style="padding: 30px 25px;">
                        <!-- Case Title -->
                        <div style="margin-bottom: 25px; text-align: center;">
                            <div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 20px; border-radius: 12px; border-left: 4px solid #7C0F2F; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                                <h4 style="margin: 0; color: #2c3e50; font-size: 18px; font-weight: 600; line-height: 1.4;">${caseTitle}</h4>
                            </div>
                        </div>
                        
                        <!-- Case Details -->
                        <div style="space-y: 15px;">
                            ${caseNumber ? `
                                <div style="display: flex; align-items: center; padding: 15px; background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%); border-radius: 10px; border-left: 4px solid #2196f3;">
                                    <i class="fas fa-hashtag" style="color: #2196f3; font-size: 18px; margin-right: 12px; width: 20px;"></i>
                                    <div>
                                        <div style="color: #1976d2; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Case Number</div>
                                        <div style="color: #2c3e50; font-size: 16px; font-weight: 500;">${caseNumber}</div>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${caseType ? `
                                <div style="display: flex; align-items: center; padding: 15px; background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%); border-radius: 10px; border-left: 4px solid #4caf50;">
                                    <i class="fas fa-balance-scale" style="color: #4caf50; font-size: 18px; margin-right: 12px; width: 20px;"></i>
                                    <div>
                                        <div style="color: #388e3c; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Case Type</div>
                                        <div style="color: #2c3e50; font-size: 16px; font-weight: 500; text-transform: capitalize;">${caseType}</div>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${clientName ? `
                                <div style="display: flex; align-items: center; padding: 15px; background: linear-gradient(135deg, #f3e5f5 0%, #fce4ec 100%); border-radius: 10px; border-left: 4px solid #9c27b0;">
                                    <i class="fas fa-user-tie" style="color: #9c27b0; font-size: 18px; margin-right: 12px; width: 20px;"></i>
                                    <div>
                                        <div style="color: #7b1fa2; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;">Client</div>
                                        <div style="color: #2c3e50; font-size: 16px; font-weight: 500;">${clientName}</div>
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                        
                        <!-- Footer -->
                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; text-align: center;">
                            <button onclick="closeCaseModal()" style="background: linear-gradient(135deg, #7C0F2F 0%, #8B1538 100%); color: white; border: none; padding: 12px 30px; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(124, 15, 47, 0.3);">
                                <i class="fas fa-check" style="margin-right: 8px;"></i>
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Create modal overlay
            const modal = document.createElement('div');
            modal.id = 'caseModal';
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0,0,0,0.5); display: flex; justify-content: center; 
                align-items: center; z-index: 10000; backdrop-filter: blur(3px);
            `;
            modal.innerHTML = modalContent;
            document.body.appendChild(modal);
            
            // Close on overlay click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeCaseModal();
            });
        }

        function closeCaseModal() {
            const modal = document.getElementById('caseModal');
            if (modal) {
                modal.remove();
            }
        }

        // Toggle custom dropdown
        function toggleCaseDropdown() {
            const menu = document.getElementById('case_dropdown_menu');
            const arrow = document.getElementById('case_dropdown_arrow');
            
            if (menu.style.display === 'none' || menu.style.display === '') {
                menu.style.display = 'block';
                arrow.style.transform = 'rotate(180deg)';
                // Focus search input when dropdown opens
                setTimeout(() => {
                    document.getElementById('case_search').focus();
                }, 100);
            } else {
                menu.style.display = 'none';
                arrow.style.transform = 'rotate(0deg)';
            }
        }

        // Select case from dropdown
        function selectCase(caseId, title, type, client) {
            // Update hidden input
            document.getElementById('case_id_input').value = caseId;
            
            // Update display text
            document.getElementById('case_selected_text').textContent = `#${caseId} — ${title}`;
            document.getElementById('case_selected_text').style.color = '#2c3e50';
            document.getElementById('case_selected_text').style.fontStyle = 'normal';
            
            // Close dropdown
            document.getElementById('case_dropdown_menu').style.display = 'none';
            document.getElementById('case_dropdown_arrow').style.transform = 'rotate(0deg)';
            
            // Clear search
            document.getElementById('case_search').value = '';
            filterCasesInDropdown();
            
            // Show case details
            showCaseDetails(caseId, title, type, client);
        }

        // Show case details when case is selected
        function showCaseDetails(caseId, title, type, client) {
            const detailsDiv = document.getElementById('case_details');
            
            if (!caseId) {
                // Hide details if no case selected
                detailsDiv.style.display = 'none';
                return;
            }
            
            // Show case details
            detailsDiv.style.display = 'block';
            
            // Populate case information
            document.getElementById('case_title_display').textContent = title || 'N/A';
            document.getElementById('case_id_display').textContent = '#' + caseId;
            document.getElementById('case_type_display').textContent = type || 'Not specified';
            document.getElementById('case_client_display').textContent = client || 'No client assigned';
        }

        // Filter cases in dropdown
        function filterCasesInDropdown() {
            const searchTerm = document.getElementById('case_search').value.toLowerCase();
            const groups = document.querySelectorAll('.case-group');
            const options = document.querySelectorAll('.case-option');
            let visibleCount = 0;

            options.forEach(option => {
                const title = option.getAttribute('data-title')?.toLowerCase() || '';
                const type = option.getAttribute('data-type')?.toLowerCase() || '';
                const client = option.getAttribute('data-client')?.toLowerCase() || '';
                const caseId = option.getAttribute('data-id') || '';

                if (searchTerm === '' || 
                    title.includes(searchTerm) || 
                    type.includes(searchTerm) || 
                    client.includes(searchTerm) ||
                    caseId.includes(searchTerm)) {
                    option.style.display = 'block';
                    visibleCount++;
                } else {
                    option.style.display = 'none';
                }
            });

            // Show/hide groups based on visible options
            groups.forEach(group => {
                const groupOptions = group.querySelectorAll('.case-option');
                let groupVisible = false;
                
                groupOptions.forEach(option => {
                    if (option.style.display !== 'none') {
                        groupVisible = true;
                    }
                });
                
                group.style.display = groupVisible ? 'block' : 'none';
            });

            // Update search results counter
            const counter = document.getElementById('search_results_count');
            if (searchTerm === '') {
                counter.textContent = '<?= count($cases) ?> cases available';
                counter.style.color = '#6c757d';
            } else {
                counter.textContent = `${visibleCount} found`;
                if (visibleCount === 0) {
                    counter.style.color = '#dc3545';
                    counter.textContent = 'No matches found';
                } else {
                    counter.style.color = '#6c757d';
                }
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.querySelector('.custom-dropdown');
            const menu = document.getElementById('case_dropdown_menu');
            
            if (!dropdown.contains(event.target)) {
                menu.style.display = 'none';
                document.getElementById('case_dropdown_arrow').style.transform = 'rotate(0deg)';
            }
        });

        let isSubmitting = false;
        let submitAttempts = 0;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Prevent double submission
            if (isSubmitting) {
                resultMsg.className = 'result error';
                resultMsg.textContent = 'Please wait, submission is already in progress...';
                resultMsg.style.display = 'block';
                return;
            }

            // Double-click protection
            submitAttempts++;
            if (submitAttempts > 1) {
                if (!confirm('You have already clicked Send. Are you sure you want to send this eFiling again?')) {
                    submitAttempts = 0;
                    return;
                }
            }

            resultMsg.style.display = 'none';
            if (!validateEmails()) { return; }
            if (!isValidEmail(email1.value)) {
                resultMsg.className = 'result error';
                resultMsg.textContent = 'Invalid receiver email format.';
                resultMsg.style.display = 'block';
                return;
            }
            if (!fileInput.files.length) { return; }
            const uploadedExt = getExtension(fileInput.files[0].name);
            if (uploadedExt !== 'pdf') {
                resultMsg.className = 'result error';
                resultMsg.textContent = 'Only PDF files are allowed.';
                resultMsg.style.display = 'block';
                return;
            }
            ensureDesiredFilenameExtension(uploadedExt);

            // Set submitting state
            isSubmitting = true;
            const sendBtn = document.getElementById('sendBtn');
            const originalText = sendBtn.innerHTML;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            sendBtn.disabled = true;

            const fd = new FormData(form);
            fetch('process_efiling.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error(text || 'Invalid server response');
                    }
                })
                .then(data => {
                    if (data.status === 'success') {
                        resultMsg.className = 'result success';
                        resultMsg.textContent = data.message || 'Submission sent successfully.';
                        resultMsg.style.display = 'block';
                        setTimeout(() => window.location.reload(), 1200);
                    } else {
                        resultMsg.className = 'result error';
                        resultMsg.textContent = data.message || 'Failed to send submission.';
                        resultMsg.style.display = 'block';
                        // Reset button state on error
                        sendBtn.innerHTML = originalText;
                        sendBtn.disabled = false;
                        isSubmitting = false;
                        submitAttempts = 0;
                    }
                })
                .catch((err) => {
                    resultMsg.className = 'result error';
                    resultMsg.textContent = (err && err.message) ? err.message : 'Unexpected error. Please try again.';
                    resultMsg.style.display = 'block';
                    console.error('eFiling error:', err);
                    // Reset button state on error
                    sendBtn.innerHTML = originalText;
                    sendBtn.disabled = false;
                    isSubmitting = false;
                    submitAttempts = 0;
                });
        });
    </script>
</body>
</html> 