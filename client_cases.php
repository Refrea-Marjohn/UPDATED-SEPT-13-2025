<?php
require_once 'session_manager.php';
validateUserAccess('client');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';
$client_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_image, email, name, phone_number FROM user_form WHERE id=?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
$user_email = '';
$user_name = '';
$user_phone = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
    $user_email = $row['email'];
    $user_name = $row['name'];
    $user_phone = $row['phone_number'];
}
if (!$profile_image || !file_exists($profile_image)) {
        $profile_image = 'images/default-avatar.jpg';
    }
$cases = [];
$sql = "SELECT ac.*, uf.name as attorney_name FROM attorney_cases ac LEFT JOIN user_form uf ON ac.attorney_id = uf.id WHERE ac.client_id=? ORDER BY ac.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $client_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cases[] = $row;
}

// Ensure document request tables exist (in case attorney page has not created them yet)
$conn->query("CREATE TABLE IF NOT EXISTS document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    attorney_id INT NOT NULL,
    client_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    due_date DATE NULL,
    status ENUM('Requested','Submitted','Reviewed','Approved','Rejected','Called') DEFAULT 'Requested',
    attorney_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);");

// Add attorney_comment column if it doesn't exist
$conn->query("ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS attorney_comment TEXT NULL AFTER status");
$conn->query("CREATE TABLE IF NOT EXISTS document_request_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    client_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);");

// Client uploads files for a request
if (isset($_POST['action']) && $_POST['action'] === 'upload_request_files') {
    $request_id = intval($_POST['request_id']);
    $upload_dir = __DIR__ . '/uploads/client/';
    if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0777, true); }
    $saved = 0;
    if (!empty($_FILES['files'])) {
        foreach ($_FILES['files']['tmp_name'] as $idx => $tmp) {
            if (!is_uploaded_file($tmp)) continue;
            $orig = basename($_FILES['files']['name'][$idx]);
            $ext = pathinfo($orig, PATHINFO_EXTENSION);
            $safe = $client_id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/','_', $orig);
            $dest = $upload_dir . $safe;
            if (move_uploaded_file($tmp, $dest)) {
                $rel = 'uploads/client/' . $safe;
                $stmt = $conn->prepare("INSERT INTO document_request_files (request_id, client_id, file_path, original_name) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('iiss', $request_id, $client_id, $rel, $orig);
                $stmt->execute();
                $saved++;
            }
        }
    }
    if ($saved > 0) {
        // Update request status and notify attorney
        $stmt = $conn->prepare("UPDATE document_requests SET status='Submitted' WHERE id=?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $client_id,
            $user_name,
            'client',
            'Document Upload',
            'Document Access',
            "Uploaded $saved files for document request ID: $request_id",
            'success',
            'medium'
        );
        if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows) {
            $stmt = $conn->prepare("SELECT attorney_id, title FROM document_requests WHERE id=?");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $nTitle = 'Client Document Submitted';
                $nMsg = 'Client uploaded files for request: ' . $row['title'];
                $userType = 'attorney';
                $notificationType = 'success';
                $stmtN = $conn->prepare("INSERT INTO notifications (user_id, user_type, title, message, type) VALUES (?, ?, ?, ?, ?)");
                $stmtN->bind_param('issss', $row['attorney_id'], $userType, $nTitle, $nMsg, $notificationType);
                $stmtN->execute();
            }
        }
    }
    echo $saved > 0 ? 'success' : 'error';
    exit();
}

// List requests for a given case
if (isset($_POST['action']) && $_POST['action'] === 'list_requests') {
    $case_id = intval($_POST['case_id']);
    $stmt = $conn->prepare("SELECT dr.*, (
        SELECT COUNT(*) FROM document_request_files f WHERE f.request_id = dr.id
    ) as upload_count FROM document_requests dr WHERE dr.case_id=? AND dr.client_id=? ORDER BY dr.created_at DESC");
    $stmt->bind_param('ii', $case_id, $client_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// Mark all cases as read for this client
$conn->query("UPDATE client_cases SET is_read=1 WHERE client_id=$client_id AND is_read=0");
if (isset($_POST['action']) && $_POST['action'] === 'add_case') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $stmt = $conn->prepare("INSERT INTO client_cases (title, description, client_id) VALUES (?, ?, ?)");
    $stmt->bind_param('ssi', $title, $description, $client_id);
    $stmt->execute();
    echo $stmt->affected_rows > 0 ? 'success' : 'error';
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case Tracking - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= time() ?>">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
                <div class="sidebar-header">
            <img src="images/logo.jpg" alt="Logo">
            <h2>Opiña Law Office</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="client_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="client_documents.php"><i class="fas fa-file-alt"></i><span>Document Generation</span></a></li>
            <li><a href="client_cases.php" class="active"><i class="fas fa-gavel"></i><span>My Cases</span></a></li>
            <li><a href="client_schedule.php"><i class="fas fa-calendar-alt"></i><span>My Schedule</span></a></li>
            <li><a href="client_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="header-title">
                <h1>My Cases</h1>
                <p>Track your cases, status, and schedule</p>
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

<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal" style="display:none; z-index: 9999 !important; position: fixed; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px); align-items: center; justify-content: center;">
    <div class="modal-content" style="z-index: 9999 !important; background-color: white; padding: 0; border-radius: 12px; width: 90%; max-width: 450px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); animation: modalSlideIn 0.3s ease-out;">
        <div class="modal-header" style="z-index: 9999 !important; background: var(--primary-color); color: white; padding: 12px 16px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 1.2rem; font-weight: 600;">Edit Profile</h2>
            <span class="close" onclick="closeEditProfileModal()" style="color: white; font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1; transition: opacity 0.2s ease;">&times;</span>
        </div>
        <div class="modal-body" style="z-index: 9999 !important; padding: 14px;">
            <div class="profile-edit-container">
                <form method="POST" enctype="multipart/form-data" class="profile-form" id="editProfileForm">
                    <div class="form-section" style="margin-bottom: 14px;">
                        <h3 style="color: var(--primary-color); margin-bottom: 10px; font-size: 1rem; border-bottom: 2px solid #f0f0f0; padding-bottom: 4px;">Profile Picture</h3>
                        <div class="profile-image-section" style="display: flex; align-items: center; gap: 12px;">
                            <img src="<?= htmlspecialchars($profile_image) ?>" alt="Current Profile" id="currentProfileImage" class="current-profile-image" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary-color); box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                            <div class="image-upload" style="display: flex; flex-direction: column; gap: 6px;">
                                <label for="profile_image" class="upload-btn" style="background: var(--primary-color); color: white; padding: 6px 12px; border-radius: 6px; cursor: pointer; text-align: center; transition: background 0.3s ease; display: inline-block; text-decoration: none; font-size: 0.8rem;">
                                    <i class="fas fa-camera"></i>
                                    Change Photo
                                </label>
                                <input type="file" id="profile_image" name="profile_image" accept="image/*" style="display: none;" onchange="previewImage(this)">
                                <p class="upload-hint" style="color: #666; font-size: 0.8rem; margin: 0;">JPG, PNG, or GIF. Max 5MB.</p>
                            </div>
                        </div>
                    </div>

                    <div class="form-section" style="margin-bottom: 14px;">
                        <h3 style="color: var(--primary-color); margin-bottom: 10px; font-size: 1rem; border-bottom: 2px solid #f0f0f0; padding-bottom: 4px;">Personal Information</h3>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="name" style="display: block; margin-bottom: 4px; color: #333; font-weight: 500; font-size: 0.8rem;">Full Name</label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($user_name) ?>" required style="width: 100%; padding: 6px 10px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 0.8rem; transition: border-color 0.3s ease;">
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="email" style="display: block; margin-bottom: 4px; color: #333; font-weight: 500; font-size: 0.8rem;">Email Address</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user_email ?? '') ?>" readonly style="background-color: #f5f5f5; cursor: not-allowed; width: 100%; padding: 6px 10px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 0.8rem;">
                            <small style="color: #666; font-size: 12px;">Email address cannot be changed for security reasons.</small>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="phone_number" style="display: block; margin-bottom: 4px; color: #333; font-weight: 500; font-size: 0.8rem;">Phone Number</label>
                            <input type="tel" id="phone_number" name="phone_number" value="<?= htmlspecialchars($user_phone ?? '') ?>" placeholder="09123456789" maxlength="11" style="width: 100%; padding: 6px 10px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 0.8rem; transition: border-color 0.3s ease;" oninput="validatePhoneNumber(this)">
                            <small style="color: #666; font-size: 0.7rem;">Must be exactly 11 digits starting with 09</small>
                        </div>
                    </div>

                    <div class="form-actions" style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px; padding-top: 12px; border-top: 1px solid #e1e5e9;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditProfileModal()" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 4px; transition: all 0.3s ease; font-size: 0.8rem; background: #6c757d; color: white;">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="verifyPasswordBeforeSave()" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 4px; transition: all 0.3s ease; font-size: 0.8rem; background: var(--primary-color); color: white;">
                            <i class="fas fa-save"></i>
                            Save Changes
                        </button>
                    </div>
            </div>
        </div>
    </div>
</div>

<!-- Password Verification Modal -->
<div id="passwordVerificationModal" class="modal" style="display: none; position: fixed; z-index: 2001; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px); align-items: center; justify-content: center;">
    <div class="modal-content" style="background-color: white; padding: 0; border-radius: 12px; width: 90%; max-width: 400px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
        <div class="modal-header" style="background: var(--primary-color); color: white; padding: 12px 16px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 1.2rem; font-weight: 600;">Security Verification</h2>
            <span class="close" onclick="closePasswordVerificationModal()" style="color: white; font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1; transition: opacity 0.2s ease;">&times;</span>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <i class="fas fa-shield-alt" style="font-size: 48px; color: var(--primary-color); margin-bottom: 10px;"></i>
                <h3 style="margin: 0; color: #333; font-size: 1.1rem;">Verify Your Identity</h3>
                <p style="margin: 10px 0 0 0; color: #666; font-size: 0.9rem;">Please enter your current password to save profile changes</p>
            </div>
            
            <form id="passwordVerificationForm">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="current_password" style="display: block; margin-bottom: 5px; color: #333; font-weight: 500; font-size: 0.9rem;">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required style="width: 100%; padding: 10px 12px; border: 2px solid #e1e5e9; border-radius: 6px; font-size: 0.9rem; transition: border-color 0.3s ease;">
                </div>
                
                <div class="form-actions" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordVerificationModal()" style="padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s ease; font-size: 0.9rem; background: #6c757d; color: white;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s ease; font-size: 0.9rem; background: var(--primary-color); color: white;">
                        <i class="fas fa-check"></i>
                        Verify & Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
        <div class="cases-container">
            <div class="cases-grid">
                    <?php foreach ($cases as $case): ?>
                <div class="case-card">
                    <div class="case-header">
                        <div class="case-icon">
                            <i class="fas fa-gavel"></i>
                        </div>
                        <div class="case-info">
                            <h3 class="case-title"><?= htmlspecialchars($case['title']) ?></h3>
                            <p class="case-type"><?= htmlspecialchars(ucfirst(strtolower($case['case_type'] ?? 'General'))) ?></p>
                        </div>
                    </div>
                    
                    <div class="case-status">
                        <span class="status-badge status-<?= strtolower($case['status'] ?? 'active') ?>">
                            <?= htmlspecialchars($case['status'] ?? 'Active') ?>
                        </span>
                    </div>
                    
                    <div class="case-actions">
                        <button class="btn btn-primary btn-view" onclick="viewCaseDetails(<?= $case['id'] ?>)">
                            <i class="fas fa-eye"></i>
                            View Case
                        </button>
                        <button class="btn btn-secondary btn-document" onclick="openRequestsModal(<?= $case['id'] ?>)">
                            <i class="fas fa-file-upload"></i>
                            Document Request
                            </button>
                    </div>
                </div>
                    <?php endforeach; ?>
                
                <?php if (empty($cases)): ?>
                <div class="no-cases">
                    <div class="no-cases-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <h3>No Cases Yet</h3>
                    <p>Your cases will appear here once assigned by your attorney.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Case Details Modal -->
        <div class="modal" id="caseModal" style="display:none; z-index: 9999 !important;">
            <div class="modal-content" style="z-index: 9999 !important;">
                <div class="modal-header" style="z-index: 9999 !important;">
                    <h2>Case Details</h2>
                    <button class="close-modal" onclick="closeCaseModal()">&times;</button>
                </div>
                <div class="modal-body" id="caseModalBody" style="z-index: 9999 !important;">
                    <!-- Dynamic case details here -->
                </div>
                <div class="modal-footer" style="z-index: 9999 !important;">
                    <button class="btn btn-secondary" onclick="closeCaseModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Conversation Modal -->
        <div class="modal" id="conversationModal" style="display:none; z-index: 9999 !important;">
            <div class="modal-content" style="max-width:600px; z-index: 9999 !important;">
                <div class="modal-header" style="z-index: 9999 !important;">
                    <h2>Conversation with <span id="convAttorneyName"></span></h2>
                    <button class="close-modal" onclick="closeConversationModal()">&times;</button>
                </div>
                <div class="modal-body" style="z-index: 9999 !important;">
                    <div class="chat-messages" id="convChatMessages" style="height:300px;overflow-y:auto;background:#f9f9f9;padding:16px;border-radius:8px;margin-bottom:10px;"></div>
                    <div class="chat-compose" id="convChatCompose" style="display:flex;gap:10px;">
                        <textarea id="convMessageInput" placeholder="Type your message..." style="flex:1;border-radius:8px;border:1px solid #ddd;padding:10px;resize:none;font-size:1rem;"></textarea>
                        <button class="btn btn-primary" onclick="sendConvMessage()">Send</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Requests Modal -->
        <div class="modal" id="requestsModal" style="display:none; z-index: 9999 !important;">
            <div class="modal-content" style="max-width:700px; max-height: 90vh; overflow-y: auto; z-index: 9999 !important;">
                <div class="modal-header" style="z-index: 9999 !important;">
                    <h2>Document Requests</h2>
                    <button class="close-modal" onclick="closeRequestsModal()">&times;</button>
                </div>
                <div class="modal-body" style="z-index: 9999 !important;">
                    <div id="clientRequestsList" style="margin-bottom:12px;"></div>
                    <form id="uploadRequestForm" style="display:none;">
                        <input type="hidden" name="request_id" id="uploadRequestId">
                        <div class="form-group">
                            <label>Upload Files</label>
                            <input type="file" name="files[]" multiple required>
                            <small style="color:#666;">Accepted: PDF, JPG, PNG. Max 10MB each.</small>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeRequestsModal()">Close</button>
                            <button type="submit" class="btn btn-primary">Submit Files</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <style>
        .cases-container { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); padding: 24px; margin-top: 24px; }
        
        /* Cases Grid Layout */
        .cases-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 20px;
        }
        
        /* Case Card Styling */
        .case-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .case-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        .case-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .case-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #1976d2, #1565c0);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .case-info {
            flex: 1;
        }
        
        .case-title {
            margin: 0 0 4px 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            line-height: 1.3;
        }
        
        .case-type {
            margin: 0;
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }
        
        .case-status {
            margin-bottom: 20px;
            text-align: center;
        }
        
        .case-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn-view, .btn-document {
            flex: 1;
            min-width: 120px;
            padding: 12px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-view {
            background: #1976d2;
            color: white;
            border: none;
        }
        
        .btn-view:hover {
            background: #1565c0;
            transform: translateY(-1px);
        }
        
        .btn-document {
            background: #6c757d;
            color: white;
            border: none;
        }
        
        .btn-document:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        /* No Cases State */
        .no-cases {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .no-cases-icon {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        .no-cases h3 {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .no-cases p {
            margin: 0;
            font-size: 0.95rem;
        }
        
        /* Status Badges */
        .status-badge { 
            padding: 8px 16px; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-active { background: #e8f5e8; color: #2e7d32; }
        .status-requested { background: #fff3cd; color: #856404; }
        .status-submitted { background: #d1ecf1; color: #0c5460; }
        .status-reviewed { background: #e2e3f5; color: #4a148c; }
        .status-approved { background: #e8f5e8; color: #2e7d32; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-called { background: #f8f9fa; color: #495057; }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .cases-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .cases-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .case-card {
                padding: 20px;
            }
            
            .case-actions {
                flex-direction: column;
            }
            
            .btn-view, .btn-document {
                min-width: auto;
            }
        }
        /* Case Details Modal Styling */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding-top: 3%;
            z-index: 9999;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 750px;
            width: 90%;
            height: auto;
            max-height: 50vh;
            overflow: visible;
            box-shadow: 0 30px 100px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            margin: 0 auto;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #800000 0%, #A52A2A 100%);
            color: white;
            padding: 20px 24px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .close-modal {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            font-size: 1.3rem;
            color: white;
            cursor: pointer;
            padding: 10px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: bold;
        }
        
        .close-modal:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }
        
        .modal-body {
            padding: 16px;
            overflow: visible;
            flex: 1;
            background: #fafafa;
        }
        
        .modal-footer {
            padding: 12px 24px;
            border-top: 1px solid #e0e0e0;
            background: white;
            border-radius: 0 0 20px 20px;
            text-align: center;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #800000 0%, #A52A2A 100%);
            color: white;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #660000 0%, #8B0000 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(128, 0, 0, 0.3);
        }
        
        .case-details {
            padding: 0;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .detail-section {
            margin-bottom: 12px;
            padding: 12px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #f0f0f0;
        }
        
        .detail-section:last-child {
            margin-bottom: 0;
        }
        
        .detail-section h3 {
            margin: 0 0 8px 0;
            color: #800000;
            font-size: 0.95rem;
            font-weight: 700;
            border-bottom: 2px solid #ffe6e6;
            padding-bottom: 4px;
            letter-spacing: 0.5px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #800000;
        }
        
        .detail-item label {
            font-weight: 700;
            color: #666;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .detail-item span {
            color: #333;
            font-size: 1.05rem;
            font-weight: 600;
        }
        
        .detail-section p {
            margin: 0;
            line-height: 1.4;
            color: #555;
            font-size: 0.85rem;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #800000;
            max-height: 60px;
            overflow-y: auto;
        }
        
        /* Responsive modal */
        @media (max-width: 768px) {
            .modal {
                padding-top: 2%;
            }
            
            .modal-content {
                max-width: 95%;
                max-height: 55vh;
                margin: 0 auto;
            }
            
            .modal-header {
                padding: 14px 18px;
            }
            
            .modal-body {
                padding: 14px;
            }
            
            .modal-footer {
                padding: 10px 18px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .detail-section {
                padding: 10px;
                margin-bottom: 10px;
            }
            
            .detail-item {
                padding: 6px;
            }
        }
    </style>
    <script>
        let convAttorneyId = null;
        function openConversationModal(attorneyId, attorneyName) {
            convAttorneyId = attorneyId;
            document.getElementById('convAttorneyName').innerText = attorneyName;
            document.getElementById('conversationModal').style.display = 'block';
            fetchConvMessages();
        }
        function closeConversationModal() {
            document.getElementById('conversationModal').style.display = 'none';
            document.getElementById('convChatMessages').innerHTML = '';
            document.getElementById('convMessageInput').value = '';
        }
        function fetchConvMessages() {
            if (!convAttorneyId) return;
            const fd = new FormData();
            fd.append('action', 'fetch_messages');
            fd.append('attorney_id', convAttorneyId);
            fetch('client_messages.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(msgs => {
                    const chat = document.getElementById('convChatMessages');
                    chat.innerHTML = '';
                    msgs.forEach(m => {
                        const sent = m.sender === 'client';
                        chat.innerHTML += `<div class='message-bubble ${sent ? 'sent' : 'received'}'><div class='message-text'><p>${m.message}</p></div><div class='message-meta'><span class='message-time'>${m.sent_at}</span></div></div>`;
                    });
                    chat.scrollTop = chat.scrollHeight;
                });
        }
        function sendConvMessage() {
            const input = document.getElementById('convMessageInput');
            if (!input.value.trim() || !convAttorneyId) return;
            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('attorney_id', convAttorneyId);
            fd.append('message', input.value);
            fetch('client_messages.php', { method: 'POST', body: fd })
                .then(r => r.text()).then(res => {
                    if (res === 'success') {
                        input.value = '';
                        fetchConvMessages();
                    } else {
                        alert('Error sending message.');
                    }
                });
        }
        // Requests UX
        let activeCaseId = null;
        function closeCaseModal() {
            document.getElementById('caseModal').style.display = 'none';
        }
        
        function viewCaseDetails(caseId) {
            // Find the case data
            const cases = <?= json_encode($cases) ?>;
            const caseData = cases.find(c => c.id == caseId);
            
            if (!caseData) {
                alert('Case not found');
                return;
            }
            
            // Populate modal with case details
            const modalBody = document.getElementById('caseModalBody');
            modalBody.innerHTML = `
                <div class="case-details">
                    <div class="detail-section">
                        <h3>Case Information</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>Case ID:</label>
                                <span>${caseData.id}</span>
                            </div>
                            <div class="detail-item">
                                <label>Title:</label>
                                <span>${caseData.title}</span>
                            </div>
                            <div class="detail-item">
                                <label>Type:</label>
                                <span>${caseData.case_type || 'General'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Status:</label>
                                <span class="status-badge status-${(caseData.status || 'active').toLowerCase()}">${caseData.status || 'Active'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Attorney:</label>
                                <span>${caseData.attorney_name || 'Not assigned'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Created:</label>
                                <span>${new Date(caseData.created_at).toLocaleDateString()}</span>
                            </div>
                        </div>
                    </div>
                    
                    ${caseData.description ? `
                    <div class="detail-section">
                        <h3>Description</h3>
                        <p>${caseData.description}</p>
                    </div>
                    ` : ''}
                    
                    ${caseData.next_hearing ? `
                    <div class="detail-section">
                        <h3>Next Hearing</h3>
                        <p>${caseData.next_hearing}</p>
                    </div>
                    ` : ''}
                </div>
            `;
            
            // Show the modal
            document.getElementById('caseModal').style.display = 'block';
        }
        
        function openRequestsModal(caseId) {
            activeCaseId = caseId;
            document.getElementById('clientRequestsList').innerHTML = '';
            document.getElementById('requestsModal').style.display = 'block';
            fetchClientRequests();
        }
        function closeRequestsModal() {
            document.getElementById('requestsModal').style.display = 'none';
            document.getElementById('uploadRequestForm').style.display = 'none';
        }
        function fetchClientRequests() {
            if (!activeCaseId) return;
            const fd = new FormData();
            fd.append('action','list_requests');
            fd.append('case_id', activeCaseId);
            fetch('client_cases.php', { method:'POST', body: fd })
                .then(r=>r.json()).then(rows=>{
                    const wrap = document.getElementById('clientRequestsList');
                    if (!rows.length) { wrap.innerHTML = '<p style="color:#888;">No document requests yet.</p>'; return; }
                                         wrap.innerHTML = rows.map(r=>`
                         <div style="border:1px solid #eee;border-radius:8px;padding:10px;margin-bottom:8px;">
                             <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                                 <div style="flex:1;">
                                     <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                         <strong>${r.title}</strong>
                                         <span class="status-badge status-${(r.status||'Requested').toLowerCase()}" style="padding:4px 8px;border-radius:12px;font-size:0.8rem;font-weight:500;">${r.status}</span>
                                     </div>
                                     <div style="color:#666;margin-bottom:4px;">${r.description || ''}</div>
                                     <div style="color:#888;font-size:0.9rem;">Due: ${r.due_date || '—'} • Uploads: ${r.upload_count}</div>
                                     <div style="color:#aaa;font-size:0.85rem;">Created: ${r.created_at}</div>
                                     ${r.attorney_comment ? `<div style="color:#1976d2;margin-top:4px;font-style:italic;background:#f0f8ff;padding:8px;border-radius:6px;border-left:3px solid #1976d2;"><strong>Attorney Feedback:</strong> ${r.attorney_comment}</div>` : ''}
                                     <div id="clientFiles-${r.id}" style="margin-top:8px;display:none;background:#f9f9f9;border:1px solid #eee;padding:8px;border-radius:6px;"></div>
                                 </div>
                                 <div style="display:flex;flex-direction:column;gap:6px;">
                                     <button class="btn btn-info btn-xs" onclick="viewClientFiles(${r.id})"><i class='fas fa-folder-open'></i> View Files</button>
                                     ${r.status === 'Requested' || r.status === 'Called' ? `<button class="btn btn-primary btn-xs" onclick="startUpload(${r.id})"><i class='fas fa-upload'></i> Upload</button>` : ''}
                                 </div>
                             </div>
                         </div>
                     `).join('');
                });
        }
        function startUpload(requestId) {
            document.getElementById('uploadRequestId').value = requestId;
            document.getElementById('uploadRequestForm').style.display = 'block';
        }
        
        function viewClientFiles(requestId) {
            const box = document.getElementById('clientFiles-'+requestId);
            const fd = new FormData();
            fd.append('action','list_request_files');
            fd.append('request_id', requestId);
            fetch('client_cases.php', { method:'POST', body: fd })
                .then(r=>r.json()).then(files=>{
                    if (files.length===0) { 
                        box.innerHTML = '<em style="color:#888;">No files uploaded yet.</em>'; 
                    } else {
                        box.innerHTML = files.map(f=>`
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">
                                <a href="${f.file_path}" target="_blank" style="color:#1976d2;text-decoration:none;">
                                    <i class="fas fa-file"></i> ${f.original_name}
                                </a>
                                <span style="color:#888;font-size:0.85rem;">${f.uploaded_at}</span>
                            </div>
                        `).join('');
                    }
                    box.style.display = box.style.display === 'none' ? 'block' : 'none';
                });
        }
        document.getElementById('uploadRequestForm').onsubmit = function(e){
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action','upload_request_files');
            fetch('client_cases.php', { method:'POST', body: fd })
                .then(r=>r.text()).then(res=>{
                    if (res==='success') {
                        alert('Files submitted.');
                        this.reset();
                        document.getElementById('uploadRequestForm').style.display = 'none';
                        fetchClientRequests();
                    } else {
                        alert('Upload failed. Please try again.');
                    }
                });
        };
        
        // Profile dropdown functions removed - profile is non-clickable on this page

        function closeEditProfileModal() {
            document.getElementById('editProfileModal').style.display = 'none';
        }
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('img') && !event.target.closest('.profile-dropdown')) {
                const dropdowns = document.getElementsByClassName('profile-dropdown-content');
                for (let dropdown of dropdowns) {
                    if (dropdown.classList.contains('show')) {
                        dropdown.classList.remove('show');
                    }
                }
            }
            
            // Modal close on outside click removed - users must use buttons to close
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('currentProfileImage').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Handle form submission - REMOVED since we now use password verification
        // The form submission is now handled by verifyPasswordAndSave() function

        // Password verification functions
        function verifyPasswordBeforeSave() {
            console.log('verifyPasswordBeforeSave called'); // Debug log
            
            // Validate phone number first
            const phoneInput = document.getElementById('phone_number');
            const phoneNumber = phoneInput.value.trim();
            
            if (phoneNumber && !/^09\d{9}$/.test(phoneNumber)) {
                alert('Phone number must be exactly 11 digits starting with 09 (e.g., 09123456789)');
                phoneInput.focus();
                return;
            }
            
            // Show confirmation first
            if (confirm('Are you sure you want to save these changes to your profile?')) {
                console.log('User confirmed, hiding edit modal and showing password modal'); // Debug log
                // Hide the edit profile modal
                document.getElementById('editProfileModal').style.display = 'none';
                // Show the password verification modal
                document.getElementById('passwordVerificationModal').style.display = 'block';
            }
        }

        function closePasswordVerificationModal() {
            document.getElementById('passwordVerificationModal').style.display = 'none';
            document.getElementById('current_password').value = '';
            // Show the edit profile modal again
            document.getElementById('editProfileModal').style.display = 'block';
        }

        // Phone number validation function
        function validatePhoneNumber(input) {
            let value = input.value.replace(/\D/g, ''); // Remove non-digits
            if (value.length > 11) {
                value = value.substring(0, 11);
            }
            input.value = value;
            
            // Visual feedback
            if (value.length === 11 && /^09\d{9}$/.test(value)) {
                input.style.borderColor = '#28a745'; // Green for valid
            } else if (value.length > 0) {
                input.style.borderColor = '#dc3545'; // Red for invalid
            } else {
                input.style.borderColor = '#e1e5e9'; // Default
            }
        }

        // Handle password verification form submission
        document.getElementById('passwordVerificationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const password = document.getElementById('current_password').value;
            if (!password) {
                alert('Please enter your current password');
                return;
            }

            // Verify password and save profile
            verifyPasswordAndSave(password);
        });

        function verifyPasswordAndSave(password) {
            const formData = new FormData(document.getElementById('editProfileForm'));
            formData.append('current_password', password);
            formData.append('security_token', generateSecurityToken());

            fetch('update_profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Profile updated successfully!');
                    closePasswordVerificationModal();
                    closeEditProfileModal();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    if (data.message.includes('password')) {
                        document.getElementById('current_password').value = '';
                        document.getElementById('current_password').focus();
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the profile.');
            });
        }

        function generateSecurityToken() {
            return Date.now().toString(36) + Math.random().toString(36).substr(2);
        }
    </script>
</body>
</html> 