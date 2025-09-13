<?php
require_once 'session_manager.php';
validateUserAccess('employee');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';

$employee_id = $_SESSION['user_id'];

// Check if specific conversation is requested
$specific_conversation_id = null;
if (isset($_GET['conversation_id'])) {
    $specific_conversation_id = intval($_GET['conversation_id']);
}

// Fetch employee profile image, email, and name
$stmt = $conn->prepare("SELECT profile_image, email, name FROM user_form WHERE id=?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
$employee_email = '';
$employee_name = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
    $employee_email = $row['email'];
    $employee_name = $row['name'];
}
if (!$profile_image || !file_exists($profile_image)) {
    $profile_image = 'images/default-avatar.jpg';
}

// Handle AJAX fetch messages
if (isset($_POST['action']) && $_POST['action'] === 'fetch_messages') {
    $conversation_id = intval($_POST['conversation_id']);
    $msgs = [];
    
    // Fetch employee profile image
    $employee_img = '';
    $stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) $employee_img = $row['profile_image'];
    if (!$employee_img || !file_exists($employee_img)) $employee_img = 'images/default-avatar.jpg';
    
    // Fetch client profile image
    $stmt = $conn->prepare("SELECT client_id FROM client_employee_conversations WHERE id = ?");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $client_id = $res->fetch_assoc()['client_id'];
    
    $client_img = '';
    $stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) $client_img = $row['profile_image'];
    if (!$client_img || !file_exists($client_img)) $client_img = 'images/default-avatar.jpg';
    
    // Fetch messages
    $stmt = $conn->prepare("SELECT sender_id, sender_type, message, sent_at FROM client_employee_messages WHERE conversation_id = ? ORDER BY sent_at ASC");
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $sent = $row['sender_type'] === 'employee';
        $row['profile_image'] = $sent ? $employee_img : $client_img;
        $msgs[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($msgs);
    exit();
}

// Handle AJAX send message
if (isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $conversation_id = intval($_POST['conversation_id']);
    $msg = $_POST['message'];
    
    $stmt = $conn->prepare("INSERT INTO client_employee_messages (conversation_id, sender_id, sender_type, message) VALUES (?, ?, 'employee', ?)");
    $stmt->bind_param('iis', $conversation_id, $employee_id, $msg);
    $stmt->execute();
    
    $result = $stmt->affected_rows > 0 ? 'success' : 'error';
    
    if ($result === 'success') {
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $employee_id,
            $employee_name,
            'employee',
            'Message Send',
            'Communication',
            "Sent message in conversation ID: $conversation_id",
            'success',
            'low'
        );
    }
    
    echo $result;
    exit();
}

// Handle AJAX mark concern identified
if (isset($_POST['action']) && $_POST['action'] === 'mark_concern_identified') {
    $conversation_id = intval($_POST['conversation_id']);
    $concern_description = trim($_POST['concern_description']);
    
    $stmt = $conn->prepare("UPDATE client_employee_conversations SET concern_identified = 1, concern_description = ? WHERE id = ?");
    $stmt->bind_param('si', $concern_description, $conversation_id);
    $stmt->execute();
    
    $result = $stmt->affected_rows > 0 ? 'success' : 'error';
    
    if ($result === 'success') {
        // Log to audit trail
        global $auditLogger;
        $auditLogger->logAction(
            $employee_id,
            $employee_name,
            'employee',
            'Concern Identified',
            'Communication',
            "Marked concern as identified in conversation ID: $conversation_id",
            'success',
            'medium'
        );
    }
    
    echo $result;
    exit();
}

// Fetch active conversations for this employee
$stmt = $conn->prepare("
    SELECT cec.id, cec.conversation_status, cec.concern_identified, cec.concern_description,
           u.name as client_name, u.profile_image as client_image,
           crf.request_id, crf.full_name, crf.address
    FROM client_employee_conversations cec
    JOIN user_form u ON cec.client_id = u.id
    JOIN client_request_form crf ON cec.request_form_id = crf.id
    WHERE cec.employee_id = ?
    ORDER BY cec.created_at DESC
");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$res = $stmt->get_result();
$conversations = [];
while ($row = $res->fetch_assoc()) {
    $img = $row['client_image'];
    if (!$img || !file_exists($img)) {
        $img = 'images/default-avatar.jpg';
    }
    $row['client_image'] = $img;
    $conversations[] = $row;
}

// If no conversations found, try to create them from approved requests
if (empty($conversations)) {
    // Get approved requests for this employee
    $stmt = $conn->prepare("
        SELECT crf.id as request_id, crf.client_id, crf.request_id as request_number
        FROM client_request_form crf
        JOIN employee_request_reviews err ON crf.id = err.request_form_id
        WHERE err.employee_id = ? AND err.action = 'Approved' AND crf.status = 'Approved'
        ORDER BY err.reviewed_at DESC
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    while ($request = $res->fetch_assoc()) {
        // Check if conversation already exists
        $stmt2 = $conn->prepare("SELECT id FROM client_employee_conversations WHERE request_form_id = ? AND employee_id = ?");
        $stmt2->bind_param("ii", $request['request_id'], $employee_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        
        if (!$res2->fetch_assoc()) {
            // Create conversation
            $stmt3 = $conn->prepare("INSERT INTO client_employee_conversations (request_form_id, client_id, employee_id, conversation_status) VALUES (?, ?, ?, 'Active')");
            $stmt3->bind_param("iii", $request['request_id'], $request['client_id'], $employee_id);
            $stmt3->execute();
        }
    }
    
    // Fetch conversations again
    $stmt = $conn->prepare("
        SELECT cec.id, cec.conversation_status, cec.concern_identified, cec.concern_description,
               u.name as client_name, u.profile_image as client_image,
               crf.request_id, crf.full_name, crf.address
        FROM client_employee_conversations cec
        JOIN user_form u ON cec.client_id = u.id
        JOIN client_request_form crf ON cec.request_form_id = crf.id
        WHERE cec.employee_id = ?
        ORDER BY cec.created_at DESC
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $conversations = [];
    while ($row = $res->fetch_assoc()) {
        $img = $row['client_image'];
        if (!$img || !file_exists($img)) {
            $img = 'images/default-avatar.jpg';
        }
        $row['client_image'] = $img;
        $conversations[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Opiña Law Office</title>
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
            <li><a href="employee_messages.php" class="active"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="employee_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>Messages</h1>
                <p>Communicate with approved clients</p>
            </div>
            <div class="user-info">
                <div class="profile-dropdown" style="display: flex; align-items: center; gap: 12px;">
                    <img src="<?= htmlspecialchars($profile_image) ?>" alt="Employee" style="object-fit:cover;width:42px;height:42px;border-radius:50%;border:2px solid #1976d2;cursor:pointer;" onclick="toggleProfileDropdown()">
                    <div class="user-details">
                        <h3><?php echo $_SESSION['employee_name']; ?></h3>
                        <p>Employee</p>
                    </div>
                    
                    <!-- Profile Dropdown Menu -->
                    <div class="profile-dropdown-content" id="profileDropdown">
                        <a href="#" onclick="editProfile()">
                            <i class="fas fa-user-edit"></i>
                            Edit Profile
                        </a>
                        <a href="logout.php" class="logout">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-container">
            <!-- Client List -->
            <div class="client-list">
                <h3>Active Conversations</h3>
                <ul id="clientList">
                    <?php if (empty($conversations)): ?>
                        <li class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No active conversations</p>
                        </li>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv): ?>
                            <li class="client-item" data-id="<?= $conv['id'] ?>" data-concern-identified="<?= $conv['concern_identified'] ? 'true' : 'false' ?>" onclick="selectClient(<?= $conv['id'] ?>, '<?= htmlspecialchars($conv['client_name']) ?>', <?= $conv['concern_identified'] ? 'true' : 'false' ?>)">
                                <img src='<?= htmlspecialchars($conv['client_image']) ?>' alt='Client' style='width:38px;height:38px;border-radius:50%;border:1.5px solid #1976d2;object-fit:cover;'>
                                <div class="client-info">
                                    <span><?= htmlspecialchars($conv['client_name']) ?></span>
                                    <small>Request ID: <?= htmlspecialchars($conv['request_id']) ?></small>
                                    <?php if ($conv['concern_identified']): ?>
                                        <div class="status-badge identified">
                                            <i class="fas fa-lightbulb"></i>
                                            Concern Identified
                                        </div>
                                    <?php else: ?>
                                        <div class="status-badge pending">
                                            <i class="fas fa-search"></i>
                                            Identifying Concern
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            
            <!-- Chat Area -->
            <div class="chat-area">
                <div class="chat-header">
                    <h2 id="selectedClient">Select a client</h2>
                    <div id="concernActions" style="display: none;">
                        <button class="btn btn-info" onclick="markConcernIdentified()">
                            <i class="fas fa-lightbulb"></i>
                            Mark Concern Identified
                        </button>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <p style="color:#888;text-align:center;">Select a client to start conversation.</p>
                </div>
                <div class="chat-compose" id="chatCompose" style="display:none;">
                    <textarea id="messageInput" placeholder="Type your message..."></textarea>
                    <button class="btn btn-primary" onclick="sendMessage()">Send</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mark Concern Identified Modal -->
    <div id="concernModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Mark Concern Identified</h2>
                <button class="close-modal" onclick="closeConcernModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="concernForm">
                    <input type="hidden" id="concernConversationId" name="conversation_id">
                    
                    <div class="form-group">
                        <label for="concernDescription">Concern Description</label>
                        <textarea id="concernDescription" name="concern_description" rows="4" placeholder="Describe the client's concern..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeConcernModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-lightbulb"></i>
                            Mark as Identified
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        .chat-container { 
            display: flex; 
            height: 75vh; 
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 50%, #ffffff 100%); 
            border-radius: 20px; 
            box-shadow: 
                0 8px 32px rgba(93, 14, 38, 0.12),
                0 4px 16px rgba(93, 14, 38, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.8); 
            overflow: hidden; 
            border: 1px solid rgba(93, 14, 38, 0.1);
            margin-top: 20px;
            position: relative;
        }

        .client-list { 
            width: 300px; 
            background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 50%, #f8f9fa 100%); 
            border-right: 2px solid rgba(93, 14, 38, 0.08); 
            padding: 24px 0; 
            position: relative;
            overflow: hidden;
        }
        
        .client-list h3 { 
            text-align: center; 
            margin-bottom: 24px; 
            color: var(--primary-color); 
            font-size: 1.4rem;
            font-weight: 700;
            padding: 0 20px;
            position: relative;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .client-list ul { 
            list-style: none; 
            padding: 0; 
            margin: 0; 
        }
        
        .client-item { 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            padding: 18px 24px; 
            cursor: pointer; 
            border-radius: 16px; 
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
            margin: 0 16px 10px 16px;
            border: 2px solid transparent;
            position: relative;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
        }
        
        .client-item:hover { 
            background: linear-gradient(135deg, #e3f2fd 0%, #f3f8ff 100%); 
            border-color: var(--primary-color);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 
                0 8px 25px rgba(93, 14, 38, 0.15),
                0 4px 15px rgba(93, 14, 38, 0.1);
        }
        
        .client-item.active { 
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); 
            color: white;
            box-shadow: 
                0 8px 25px rgba(93, 14, 38, 0.25),
                0 4px 15px rgba(93, 14, 38, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
        }
        
        .client-info {
            flex: 1;
        }
        
        .client-info span {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-color);
            display: block;
            margin-bottom: 4px;
        }
        
        .client-item.active .client-info span {
            color: white;
        }
        
        .client-info small {
            color: #666;
            font-size: 0.85rem;
            display: block;
            margin-bottom: 8px;
        }
        
        .client-item.active .client-info small {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .status-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 12px;
            width: fit-content;
        }
        
        .status-badge.identified {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .client-item.active .status-badge.identified {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .client-item.active .status-badge.pending {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ccc;
        }
        
        .chat-area { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            background: linear-gradient(135deg, #fafbfc 0%, #ffffff 100%);
        }
        
        .chat-header { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 24px 32px; 
            border-bottom: 2px solid rgba(93, 14, 38, 0.08); 
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 50%, #ffffff 100%);
            border-radius: 0 20px 0 0;
        }
        
        .chat-header h2 { 
            margin: 0; 
            font-size: 1.5rem; 
            color: var(--primary-color); 
            font-weight: 700;
        }

        .chat-messages { 
            flex: 1; 
            padding: 28px; 
            overflow-y: auto; 
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }

        .message-bubble { 
            max-width: 65%; 
            margin-bottom: 24px; 
            padding: 18px 22px; 
            border-radius: 24px; 
            font-size: 0.95rem; 
            position: relative; 
            line-height: 1.6;
            box-shadow: 
                0 4px 15px rgba(0, 0, 0, 0.08),
                0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: flex-end;
            gap: 14px;
        }
        
        .message-bubble.sent { 
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); 
            margin-left: auto; 
            color: white;
            border-bottom-right-radius: 12px;
        }
        
        .message-bubble.received { 
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); 
            border: 2px solid rgba(93, 14, 38, 0.08); 
            color: var(--text-color);
            border-bottom-left-radius: 12px;
        }
        
        .message-content {
            flex: 1;
        }
        
        .message-text p {
            margin: 0;
            word-wrap: break-word;
        }
        
        .message-meta { 
            font-size: 0.8rem; 
            color: rgba(255, 255, 255, 0.9); 
            margin-top: 10px; 
            text-align: right; 
            font-weight: 500;
        }
        
        .message-bubble.received .message-meta {
            color: #666;
        }

        .chat-compose { 
            display: flex; 
            gap: 18px; 
            padding: 28px 32px; 
            border-top: 2px solid rgba(93, 14, 38, 0.08); 
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 50%, #ffffff 100%);
            border-radius: 0 0 20px 20px;
        }
        
        .chat-compose textarea { 
            flex: 1; 
            border-radius: 16px; 
            border: 2px solid rgba(93, 14, 38, 0.1); 
            padding: 18px 22px; 
            resize: none; 
            font-size: 0.95rem; 
            font-family: inherit;
            line-height: 1.5;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            min-height: 55px;
            max-height: 120px;
        }
        
        .chat-compose textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 
                0 0 0 4px rgba(93, 14, 38, 0.1),
                inset 0 2px 4px rgba(93, 14, 38, 0.05);
            background: white;
            transform: translateY(-1px);
        }
        
        .chat-compose button { 
            padding: 18px 32px; 
            border-radius: 16px; 
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); 
            color: #fff; 
            border: none; 
            font-weight: 700; 
            cursor: pointer; 
            font-size: 1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            min-width: 120px;
            box-shadow: 
                0 4px 15px rgba(93, 14, 38, 0.2),
                0 2px 8px rgba(93, 14, 38, 0.1);
        }
        
        .chat-compose button:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 
                0 8px 25px rgba(93, 14, 38, 0.3),
                0 4px 15px rgba(93, 14, 38, 0.2);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            padding: 25px 30px;
            border-bottom: 2px solid rgba(93, 14, 38, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            color: var(--primary-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #999;
        }

        .close-modal:hover {
            color: var(--primary-color);
        }

        .modal-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 8px;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid rgba(93, 14, 38, 0.1);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(93, 14, 38, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        @media (max-width: 900px) { 
            .chat-container { 
                flex-direction: column; 
                height: auto; 
                margin: 20px 10px;
            } 
            .client-list { 
                width: 100%; 
                border-right: none; 
                border-bottom: 1px solid #e9ecef; 
                padding: 20px 0;
            }
            .client-item {
                margin: 0 20px 8px 20px;
            }
            .chat-messages {
                padding: 20px;
            }
            .chat-compose {
                padding: 20px;
            }
        }
    </style>

    <script>
        let selectedConversationId = null;
        let selectedClientName = '';
        let concernIdentified = false;

        function selectClient(conversationId, clientName, isConcernIdentified) {
            selectedConversationId = conversationId;
            selectedClientName = clientName;
            concernIdentified = isConcernIdentified;
            
            // Update UI
            document.getElementById('selectedClient').innerText = clientName;
            document.getElementById('chatCompose').style.display = 'flex';
            
            // Show/hide concern actions
            const concernActions = document.getElementById('concernActions');
            if (!concernIdentified) {
                concernActions.style.display = 'flex';
            } else {
                concernActions.style.display = 'none';
            }
            
            // Update active state
            document.querySelectorAll('.client-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`[data-id="${conversationId}"]`).classList.add('active');
            
            fetchMessages();
        }

        function sendMessage() {
            const input = document.getElementById('messageInput');
            if (!input.value.trim() || !selectedConversationId) return;
            
            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('conversation_id', selectedConversationId);
            fd.append('message', input.value);
            
            fetch('employee_messages.php', { method: 'POST', body: fd })
                .then(r => r.text()).then(res => {
                    if (res === 'success') {
                        input.value = '';
                        fetchMessages();
                    } else {
                        alert('Error sending message.');
                    }
                });
        }

        function fetchMessages() {
            if (!selectedConversationId) return;
            
            const fd = new FormData();
            fd.append('action', 'fetch_messages');
            fd.append('conversation_id', selectedConversationId);
            
            fetch('employee_messages.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(msgs => {
                    const chat = document.getElementById('chatMessages');
                    chat.innerHTML = '';
                    
                    msgs.forEach(m => {
                        const sent = m.sender_type === 'employee';
                        chat.innerHTML += `
                            <div class='message-bubble ${sent ? 'sent' : 'received'}'>
                                ${sent ? '' : `<img src='${m.profile_image}' alt='Client' style='width:42px;height:42px;border-radius:50%;border:2px solid var(--primary-color);object-fit:cover;margin-right:12px;'>`}
                                <div class='message-content'>
                                    <div class='message-text'><p>${m.message}</p></div>
                                    <div class='message-meta'><span>${m.sent_at}</span></div>
                                </div>
                                ${sent ? `<img src='${m.profile_image}' alt='Employee' style='width:42px;height:42px;border-radius:50%;border:2px solid var(--primary-color);object-fit:cover;margin-left:12px;'>` : ''}
                            </div>`;
                    });
                    chat.scrollTop = chat.scrollHeight;
                })
                .catch(error => {
                    console.error('Error fetching messages:', error);
                });
        }

        function markConcernIdentified() {
            document.getElementById('concernConversationId').value = selectedConversationId;
            document.getElementById('concernModal').style.display = 'block';
        }

        function closeConcernModal() {
            document.getElementById('concernModal').style.display = 'none';
            document.getElementById('concernForm').reset();
        }

        // Profile Dropdown Functions
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
        }
        
        function editProfile() {
            alert('Profile editing functionality will be implemented.');
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
            
            // Close modal when clicking outside
            if (event.target == document.getElementById('concernModal')) {
                closeConcernModal();
            }
        }

        // Handle concern form submission
        document.getElementById('concernForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'mark_concern_identified');
            
            fetch('employee_messages.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(result => {
                if (result === 'success') {
                    closeConcernModal();
                    location.reload();
                } else {
                    alert('Error marking concern as identified. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error marking concern as identified. Please try again.');
            });
        });

        // Auto-select conversation if specified in URL
        <?php if ($specific_conversation_id): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const conversationItem = document.querySelector('[data-id="<?= $specific_conversation_id ?>"]');
            if (conversationItem) {
                const clientName = conversationItem.querySelector('span').textContent;
                const concernIdentified = conversationItem.getAttribute('data-concern-identified') === 'true';
                selectClient(<?= $specific_conversation_id ?>, clientName, concernIdentified);
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
