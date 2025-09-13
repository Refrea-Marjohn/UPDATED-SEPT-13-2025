<?php
require_once 'session_manager.php';
validateUserAccess('client');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';

$client_id = $_SESSION['user_id'];

// Check if client has an approved request
$stmt = $conn->prepare("SELECT id, status FROM client_request_form WHERE client_id = ? ORDER BY submitted_at DESC LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$request_status = $res->fetch_assoc();

// Set flag to show request access page instead of redirecting
$show_request_access = (!$request_status || $request_status['status'] !== 'Approved');

// Get employee conversation - simplified approach
$employee_conversation = null;

// First, get the approved request
$stmt = $conn->prepare("SELECT id, client_id FROM client_request_form WHERE client_id = ? AND status = 'Approved' ORDER BY submitted_at DESC LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$approved_request = $res->fetch_assoc();

if ($approved_request) {
    // Get employee conversation for this request
    $stmt = $conn->prepare("
        SELECT cec.id as conversation_id, cec.conversation_status, cec.concern_identified, cec.concern_description,
               u.name as employee_name, u.profile_image as employee_image
        FROM client_employee_conversations cec
        JOIN user_form u ON cec.employee_id = u.id
        WHERE cec.request_form_id = ?
    ");
    $stmt->bind_param("i", $approved_request['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $employee_conversation = $res->fetch_assoc();
    
    // If no conversation found, create one
    if (!$employee_conversation) {
        // Get the employee who approved this request
        $stmt = $conn->prepare("SELECT employee_id FROM employee_request_reviews WHERE request_form_id = ? AND action = 'Approved' ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("i", $approved_request['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $review = $res->fetch_assoc();
        
        if ($review) {
            // Create the conversation
            $stmt = $conn->prepare("INSERT INTO client_employee_conversations (request_form_id, client_id, employee_id, conversation_status) VALUES (?, ?, ?, 'Active')");
            $stmt->bind_param("iii", $approved_request['id'], $client_id, $review['employee_id']);
            $stmt->execute();
            
            // Get the newly created conversation
            $stmt = $conn->prepare("
                SELECT cec.id as conversation_id, cec.conversation_status, cec.concern_identified, cec.concern_description,
                       u.name as employee_name, u.profile_image as employee_image
                FROM client_employee_conversations cec
                JOIN user_form u ON cec.employee_id = u.id
                WHERE cec.request_form_id = ?
            ");
            $stmt->bind_param("i", $approved_request['id']);
            $stmt->execute();
            $res = $stmt->get_result();
            $employee_conversation = $res->fetch_assoc();
        }
    }
}

// Fix employee image path
if ($employee_conversation) {
    $img = $employee_conversation['employee_image'];
    if (!$img || !file_exists($img)) {
        $employee_conversation['employee_image'] = 'images/default-avatar.jpg';
    }
}

// Get attorney conversation if assigned
$attorney_conversation = null;
$stmt = $conn->prepare("
    SELECT cac.id as conversation_id, cac.conversation_status,
           u.name as attorney_name, u.profile_image as attorney_image, u.user_type,
           caa.assignment_reason
    FROM client_attorney_assignments caa
    JOIN client_attorney_conversations cac ON caa.id = cac.assignment_id
    JOIN user_form u ON caa.attorney_id = u.id
    WHERE caa.client_id = ? AND caa.status = 'Active'
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$attorney_conversation = $res->fetch_assoc();

// Fix attorney image path
if ($attorney_conversation) {
    $img = $attorney_conversation['attorney_image'];
    if (!$img || !file_exists($img)) {
        $attorney_conversation['attorney_image'] = 'images/default-avatar.jpg';
    }
}

// Fetch client profile image, email, and name
$stmt = $conn->prepare("SELECT profile_image, email, name FROM user_form WHERE id=?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
$client_email = '';
$client_name = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
    $client_email = $row['email'];
    $client_name = $row['name'];
}
if (!$profile_image || !file_exists($profile_image)) {
        $profile_image = 'images/default-avatar.jpg';
    }

// Handle AJAX fetch messages
if (isset($_POST['action']) && $_POST['action'] === 'fetch_messages') {
    $conversation_type = $_POST['conversation_type']; // 'employee' or 'attorney'
    $conversation_id = intval($_POST['conversation_id']);
    $msgs = [];
    
    // Fetch client profile image
    $client_img = '';
    $stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) $client_img = $row['profile_image'];
    if (!$client_img || !file_exists($client_img)) $client_img = 'images/default-avatar.jpg';
    
    // Fetch other party profile image
    $other_img = '';
    if ($conversation_type === 'employee') {
        $stmt = $conn->prepare("SELECT u.profile_image FROM client_employee_conversations cec JOIN user_form u ON cec.employee_id = u.id WHERE cec.id = ?");
    } else {
        $stmt = $conn->prepare("SELECT u.profile_image FROM client_attorney_assignments caa JOIN user_form u ON caa.attorney_id = u.id WHERE caa.id = ?");
    }
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) $other_img = $row['profile_image'];
    if (!$other_img || !file_exists($other_img)) $other_img = 'images/default-avatar.jpg';
    
    // Fetch messages based on conversation type
    if ($conversation_type === 'employee') {
        $stmt = $conn->prepare("SELECT sender_id, sender_type, message, sent_at FROM client_employee_messages WHERE conversation_id = ? ORDER BY sent_at ASC");
    } else {
        $stmt = $conn->prepare("SELECT sender_id, sender_type, message, sent_at FROM client_attorney_messages WHERE conversation_id = ? ORDER BY sent_at ASC");
    }
    $stmt->bind_param('i', $conversation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $sent = $row['sender_type'] === 'client';
        $row['profile_image'] = $sent ? $client_img : $other_img;
        $msgs[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($msgs);
    exit();
}

// Handle AJAX send message
if (isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $conversation_type = $_POST['conversation_type'];
    $conversation_id = intval($_POST['conversation_id']);
    $msg = $_POST['message'];
    
    if ($conversation_type === 'employee') {
        $stmt = $conn->prepare("INSERT INTO client_employee_messages (conversation_id, sender_id, sender_type, message) VALUES (?, ?, 'client', ?)");
        } else {
        $stmt = $conn->prepare("INSERT INTO client_attorney_messages (conversation_id, sender_id, sender_type, message) VALUES (?, ?, 'client', ?)");
        }
    $stmt->bind_param('iis', $conversation_id, $client_id, $msg);
        $stmt->execute();
    
        $result = $stmt->affected_rows > 0 ? 'success' : 'error';
        
        if ($result === 'success') {
            // Log to audit trail
            global $auditLogger;
            $auditLogger->logAction(
                $client_id,
                $client_name,
                'client',
                'Message Send',
                'Communication',
            "Sent message to " . ($conversation_type === 'employee' ? 'employee' : 'attorney') . " in conversation ID: $conversation_id",
                'success',
                'low'
            );
        }
        
        echo $result;
    exit();
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
            <li><a href="client_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="client_documents.php"><i class="fas fa-file-alt"></i><span>Document Generation</span></a></li>
            <li><a href="client_cases.php"><i class="fas fa-gavel"></i><span>My Cases</span></a></li>
            <li><a href="client_schedule.php"><i class="fas fa-calendar-alt"></i><span>My Schedule</span></a></li>
            <li><a href="client_messages.php" class="active"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>Messages</h1>
                <p>Communicate with our legal team</p>
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

        <?php if ($show_request_access): ?>
            <!-- Request Access Page -->
            <div class="request-access-container">
                <div class="request-access-card">
                    <div class="request-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h2>Access Required</h2>
                    <p>To start messaging with our legal team, you need to request access first. This helps us verify your identity and provide better service.</p>
                    
                    <?php if ($request_status): ?>
                        <?php if ($request_status['status'] === 'Pending'): ?>
                            <div class="status-info pending">
                                <i class="fas fa-clock"></i>
                                <h3>Request Under Review</h3>
                                <p>Your request is currently being reviewed by our team. You will be notified once it's approved.</p>
                            </div>
                        <?php elseif ($request_status['status'] === 'Rejected'): ?>
                            <div class="status-info rejected">
                                <i class="fas fa-times-circle"></i>
                                <h3>Previous Request Rejected</h3>
                                <p>Your previous request was rejected. Please submit a new request with updated information.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="request-actions">
                        <a href="client_request_form.php" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                            Request Access
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Messages Page -->
            <div class="chat-container">
                <!-- Conversation List -->
                <div class="conversation-list">
                    <h3>Your Conversations</h3>
                    <ul id="conversationList">
                        <?php if ($employee_conversation && isset($employee_conversation['conversation_id']) && isset($employee_conversation['employee_name'])): ?>
                            <li class="conversation-item" data-type="employee" data-id="<?= $employee_conversation['conversation_id'] ?>" onclick="selectConversation('employee', <?= $employee_conversation['conversation_id'] ?>, '<?= htmlspecialchars($employee_conversation['employee_name']) ?>')">
                                <img src='<?= htmlspecialchars($employee_conversation['employee_image'] ?? 'images/default-avatar.jpg') ?>' alt='Employee' style='width:38px;height:38px;border-radius:50%;border:1.5px solid #1976d2;object-fit:cover;'>
                                <div class="conversation-info">
                                    <span><?= htmlspecialchars($employee_conversation['employee_name']) ?></span>
                                    <small>Employee</small>
                                    <?php if (isset($employee_conversation['concern_identified']) && $employee_conversation['concern_identified']): ?>
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
                        <?php endif; ?>
                        
                        <?php if ($attorney_conversation && isset($attorney_conversation['conversation_id']) && isset($attorney_conversation['attorney_name'])): ?>
                            <li class="conversation-item" data-type="attorney" data-id="<?= $attorney_conversation['conversation_id'] ?>" onclick="selectConversation('attorney', <?= $attorney_conversation['conversation_id'] ?>, '<?= htmlspecialchars($attorney_conversation['attorney_name']) ?>')">
                                <img src='<?= htmlspecialchars($attorney_conversation['attorney_image'] ?? 'images/default-avatar.jpg') ?>' alt='Attorney' style='width:38px;height:38px;border-radius:50%;border:1.5px solid #1976d2;object-fit:cover;'>
                                <div class="conversation-info">
                                    <span><?= htmlspecialchars($attorney_conversation['attorney_name']) ?></span>
                                    <small><?= ucfirst($attorney_conversation['user_type'] ?? 'Attorney') ?></small>
                                    <div class="status-badge assigned">
                                        <i class="fas fa-user-tie"></i>
                                        Assigned
                                    </div>
                                </div>
                            </li>
                        <?php endif; ?>
                        
                        <?php if ((!$employee_conversation || !isset($employee_conversation['conversation_id'])) && (!$attorney_conversation || !isset($attorney_conversation['conversation_id']))): ?>
                            <li class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No conversations available</p>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- Chat Area -->
                <div class="chat-area">
                    <div class="chat-header">
                        <h2 id="selectedConversation">Select a conversation</h2>
                    </div>
                    <div class="chat-messages" id="chatMessages">
                        <p style="color:#888;text-align:center;">Select a conversation to start messaging.</p>
                    </div>
                    <div class="chat-compose" id="chatCompose" style="display:none;">
                        <textarea id="messageInput" placeholder="Type your message..."></textarea>
                        <button class="btn btn-primary" onclick="sendMessage()">Send</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .request-access-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 60vh;
            padding: 40px 20px;
        }

        .request-access-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 24px;
            padding: 50px 40px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(93, 14, 38, 0.15);
            border: 2px solid rgba(93, 14, 38, 0.1);
            max-width: 500px;
            width: 100%;
        }

        .request-icon {
            font-size: 4rem;
            color: #5D0E26;
            margin-bottom: 20px;
        }

        .request-access-card h2 {
            color: #5D0E26;
            margin: 0 0 15px 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .request-access-card p {
            color: #666;
            margin: 0 0 30px 0;
            font-size: 1.1rem;
            line-height: 1.6;
        }

        .status-info {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            border: 2px solid;
        }

        .status-info.pending {
            border-color: #8B1538;
            background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%);
        }

        .status-info.rejected {
            border-color: #dc3545;
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        }

        .status-info i {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .status-info.pending i {
            color: #8B1538;
        }

        .status-info.rejected i {
            color: #dc3545;
        }

        .status-info h3 {
            margin: 0 0 10px 0;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .status-info p {
            margin: 0;
            font-size: 1rem;
        }

        .request-actions {
            margin-top: 30px;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #5D0E26 0%, #8B1538 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(93, 14, 38, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(93, 14, 38, 0.4);
        }

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
        
        .conversation-list { 
            width: 300px; 
            background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 50%, #f8f9fa 100%); 
            border-right: 2px solid rgba(93, 14, 38, 0.08); 
            padding: 24px 0; 
            position: relative;
            overflow: hidden;
        }
        
        .conversation-list h3 { 
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
        
        .conversation-list ul { 
            list-style: none; 
            padding: 0; 
            margin: 0; 
        }
        
        .conversation-item { 
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
        
        .conversation-item:hover { 
            background: linear-gradient(135deg, #e3f2fd 0%, #f3f8ff 100%); 
            border-color: var(--primary-color);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 
                0 8px 25px rgba(93, 14, 38, 0.15),
                0 4px 15px rgba(93, 14, 38, 0.1);
        }
        
        .conversation-item.active { 
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); 
            color: white;
            box-shadow: 
                0 8px 25px rgba(93, 14, 38, 0.25),
                0 4px 15px rgba(93, 14, 38, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
        }
        
        .conversation-info {
            flex: 1;
        }
        
        .conversation-info span {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-color);
            display: block;
            margin-bottom: 4px;
        }
        
        .conversation-item.active .conversation-info span {
            color: white;
        }
        
        .conversation-info small {
            color: #666;
            font-size: 0.85rem;
            display: block;
            margin-bottom: 8px;
        }
        
        .conversation-item.active .conversation-info small {
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
        
        .status-badge.assigned {
            background: #cce5ff;
            color: #004085;
        }
        
        .conversation-item.active .status-badge {
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        @media (max-width: 900px) { 
            .chat-container { 
                flex-direction: column; 
                height: auto; 
                margin: 20px 10px;
            } 
            .conversation-list { 
                width: 100%; 
                border-right: none; 
                border-bottom: 1px solid #e9ecef; 
                padding: 20px 0;
            }
            .conversation-item {
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
        let selectedConversationType = null;
        let selectedConversationId = null;
        let selectedConversationName = '';

        function selectConversation(type, id, name) {
            selectedConversationType = type;
            selectedConversationId = id;
            selectedConversationName = name;
            
            // Update UI
            document.getElementById('selectedConversation').innerText = name;
            document.getElementById('chatCompose').style.display = 'flex';
            
            // Update active state
            document.querySelectorAll('.conversation-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`[data-type="${type}"][data-id="${id}"]`).classList.add('active');
            
            fetchMessages();
        }

        function sendMessage() {
            const input = document.getElementById('messageInput');
            if (!input.value.trim() || !selectedConversationId) return;
            
            const fd = new FormData();
            fd.append('action', 'send_message');
            fd.append('conversation_type', selectedConversationType);
            fd.append('conversation_id', selectedConversationId);
            fd.append('message', input.value);
            
            fetch('client_messages.php', { method: 'POST', body: fd })
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
            fd.append('conversation_type', selectedConversationType);
            fd.append('conversation_id', selectedConversationId);
            
            fetch('client_messages.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(msgs => {
                    const chat = document.getElementById('chatMessages');
                    chat.innerHTML = '';
                    
                    msgs.forEach(m => {
                        const sent = m.sender_type === 'client';
                        chat.innerHTML += `
                <div class='message-bubble ${sent ? 'sent' : 'received'}'>
                                ${sent ? '' : `<img src='${m.profile_image}' alt='${selectedConversationType === 'employee' ? 'Employee' : 'Attorney'}' style='width:42px;height:42px;border-radius:50%;border:2px solid var(--primary-color);object-fit:cover;margin-right:12px;'>`}
                    <div class='message-content'>
                        <div class='message-text'><p>${m.message}</p></div>
                                    <div class='message-meta'><span>${m.sent_at}</span></div>
                    </div>
                    ${sent ? `<img src='${m.profile_image}' alt='Client' style='width:42px;height:42px;border-radius:50%;border:2px solid var(--primary-color);object-fit:cover;margin-left:12px;'>` : ''}
                </div>`;
                    });
                    chat.scrollTop = chat.scrollHeight;
                })
                .catch(error => {
                    console.error('Error fetching messages:', error);
                });
        }
        
        // Profile dropdown functions removed - profile is non-clickable on this page
    </script>
</body>
</html> 