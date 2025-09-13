<?php
// AJAX handler for modal content (MUST be before any HTML output)
if (isset($_GET['ajax_client_details']) && isset($_GET['client_id'])) {
    require_once 'config.php';
    session_start();
    $attorney_id = $_SESSION['user_id'];
    $cid = intval($_GET['client_id']);
    
    // Get client info with profile image
    $stmt = $conn->prepare("SELECT id, name, email, phone_number, profile_image FROM user_form WHERE id=?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $cinfo = $stmt->get_result()->fetch_assoc();
    
    // Get all cases for this client-attorney pair
    $cases = [];
    $stmt = $conn->prepare("SELECT * FROM attorney_cases WHERE attorney_id=? AND client_id=? ORDER BY created_at DESC");
    $stmt->bind_param("ii", $attorney_id, $cid);
    $stmt->execute();
    $cres = $stmt->get_result();
    while ($row = $cres->fetch_assoc()) $cases[] = $row;
    
    // Get recent messages (last 10)
    $msgs = [];
    $stmt = $conn->prepare("SELECT message, sent_at, 'client' as sender FROM client_messages WHERE client_id=? AND recipient_id=?
        UNION ALL
        SELECT message, sent_at, 'attorney' as sender FROM attorney_messages WHERE attorney_id=? AND recipient_id=?
        ORDER BY sent_at DESC LIMIT 10");
    $stmt->bind_param("iiii", $cid, $attorney_id, $attorney_id, $cid);
    $stmt->execute();
    $mres = $stmt->get_result();
    while ($row = $mres->fetch_assoc()) $msgs[] = $row;
    
    // Get client statistics
    $total_cases = count($cases);
    $active_cases = count(array_filter($cases, function($c) { return $c['status'] === 'Active'; }));
    $pending_cases = count(array_filter($cases, function($c) { return $c['status'] === 'Pending'; }));
    $closed_cases = count(array_filter($cases, function($c) { return $c['status'] === 'Closed'; }));
    ?>
    
    <div class="client-modal-header" style="z-index: 9999 !important;">
        <div class="client-profile">
            <div class="client-avatar">
                <?php if ($cinfo['profile_image'] && file_exists($cinfo['profile_image'])): ?>
                    <img src="<?= htmlspecialchars($cinfo['profile_image']) ?>" alt="Client Profile">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="client-info">
                <h2><?= htmlspecialchars($cinfo['name']) ?></h2>
                <div class="client-contact">
                    <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($cinfo['email']) ?></span>
                    <span><i class="fas fa-phone"></i> <?= htmlspecialchars($cinfo['phone_number']) ?></span>
                </div>
            </div>
        </div>
        
        <div class="client-stats">
            <div class="stat-item">
                <div class="stat-number"><?= $total_cases ?></div>
                <div class="stat-label">Total Cases</div>
            </div>
            <div class="stat-item active">
                <div class="stat-number"><?= $active_cases ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-item pending">
                <div class="stat-number"><?= $pending_cases ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-item closed">
                <div class="stat-number"><?= $closed_cases ?></div>
                <div class="stat-label">Closed</div>
            </div>
        </div>
    </div>

    <div class="modal-sections" style="z-index: 9999 !important;">
        <div class="section">
            <h3><i class="fas fa-gavel"></i> Case Overview</h3>
        <div class="case-list">
            <?php if (count($cases) === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No cases for this client yet.</p>
                        <button class="btn btn-primary" onclick="window.open('attorney_cases.php', '_blank')">
                            <i class="fas fa-plus"></i> Create New Case
                        </button>
                    </div>
            <?php else: foreach ($cases as $case): ?>
                <div class="case-item">
                        <div class="case-header">
                            <div class="case-title">
                                <span class="case-id">#<?= htmlspecialchars($case['id']) ?></span>
                                <h4><?= htmlspecialchars($case['title']) ?></h4>
                            </div>
                            <span class="case-status status-<?= strtolower($case['status']) ?>">
                                <?= htmlspecialchars($case['status']) ?>
                            </span>
                        </div>
                        <div class="case-details">
                            <span><i class="fas fa-tag"></i> <?= htmlspecialchars($case['case_type']) ?></span>
                            <?php if ($case['next_hearing']): ?>
                                <span><i class="fas fa-calendar"></i> Next Hearing: <?= date('M j, Y', strtotime($case['next_hearing'])) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-clock"></i> Created: <?= date('M j, Y', strtotime($case['created_at'])) ?></span>
                        </div>
                        <div class="case-actions">
                            <button class="btn btn-secondary btn-sm" onclick="window.open('attorney_cases.php?case_id=<?= $case['id'] ?>', '_blank')">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                        </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

        <div class="section">
            <h3><i class="fas fa-comments"></i> Recent Communication</h3>
    <div class="chat-area">
        <?php if (count($msgs) === 0): ?>
                    <div class="empty-state">
                        <i class="fas fa-comment-slash"></i>
                        <p>No messages yet. Start a conversation with your client.</p>
                        <button class="btn btn-primary" onclick="window.open('attorney_messages.php?client_id=<?= $cid ?>', '_blank')">
                            <i class="fas fa-envelope"></i> Send Message
                        </button>
                    </div>
                <?php else: ?>
                    <div class="message-list">
                        <?php foreach (array_reverse($msgs) as $m): ?>
                            <div class="message-bubble <?= $m['sender'] === 'attorney' ? 'sent' : 'received' ?>">
                                <div class="message-header">
                                    <span class="sender"><?= $m['sender'] === 'attorney' ? 'You' : 'Client' ?></span>
                                    <span class="time"><?= date('M j, g:i A', strtotime($m['sent_at'])) ?></span>
                                </div>
                                <div class="message-content"><?= htmlspecialchars($m['message']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="message-actions">
                        <button class="btn btn-primary" onclick="window.open('attorney_messages.php?client_id=<?= $cid ?>', '_blank')">
                            <i class="fas fa-reply"></i> Continue Conversation
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            </div>
    </div>
    <?php
    exit();
}

session_start();
if (!isset($_SESSION['attorney_name']) || $_SESSION['user_type'] !== 'attorney') {
    header('Location: login_form.php');
    exit();
}

require_once 'config.php';
$attorney_id = $_SESSION['user_id'];

// Get attorney profile image
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

// Fetch all clients for this attorney with enhanced data
$clients = [];
$stmt = $conn->prepare("SELECT uf.id, uf.name, uf.email, uf.phone_number, uf.profile_image FROM user_form uf WHERE uf.user_type='client' AND uf.id IN (SELECT client_id FROM attorney_cases WHERE attorney_id=?)");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clients[] = $row;

// Total clients
$total_clients = count($clients);

// Active cases for this attorney
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE attorney_id=? AND status='Active'");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$active_cases = $stmt->get_result()->fetch_row()[0];

// Unread messages for this attorney (from all clients)
$stmt = $conn->prepare("SELECT COUNT(*) FROM client_messages WHERE recipient_id=?");
$stmt->bind_param("i", $attorney_id);
$stmt->execute();
$unread_messages = $stmt->get_result()->fetch_row()[0];

// Upcoming appointments (next 7 days)
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));
$stmt = $conn->prepare("SELECT COUNT(*) FROM case_schedules WHERE attorney_id=? AND date BETWEEN ? AND ?");
$stmt->bind_param("iss", $attorney_id, $today, $next_week);
$stmt->execute();
$upcoming_appointments = $stmt->get_result()->fetch_row()[0];

// For each client, get their active cases count and last contact
$client_details = [];
foreach ($clients as $c) {
    $cid = $c['id'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE attorney_id=? AND client_id=? AND status='Active'");
    $stmt->bind_param("ii", $attorney_id, $cid);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_row()[0];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE attorney_id=? AND client_id=? AND status='Pending'");
    $stmt->bind_param("ii", $attorney_id, $cid);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_row()[0];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE attorney_id=? AND client_id=? AND status='Closed'");
    $stmt->bind_param("ii", $attorney_id, $cid);
    $stmt->execute();
    $closed = $stmt->get_result()->fetch_row()[0];
    
    $stmt = $conn->prepare("SELECT sent_at FROM (
        SELECT sent_at FROM client_messages WHERE client_id=? AND recipient_id=?
        UNION ALL
        SELECT sent_at FROM attorney_messages WHERE attorney_id=? AND recipient_id=?
        ORDER BY sent_at DESC LIMIT 1
    ) as t ORDER BY sent_at DESC LIMIT 1");
    $stmt->bind_param("iiii", $cid, $attorney_id, $attorney_id, $cid);
    $stmt->execute();
    $last_msg = $stmt->get_result()->fetch_row()[0] ?? '-';
    
    $status = $active > 0 ? 'Active' : ($pending > 0 ? 'Pending' : 'Inactive');
    $client_details[] = [
        'id' => $cid,
        'name' => $c['name'],
        'email' => $c['email'],
        'phone' => $c['phone_number'],
        'profile_image' => $c['profile_image'],
        'active_cases' => $active,
        'pending_cases' => $pending,
        'closed_cases' => $closed,
        'total_cases' => $active + $pending + $closed,
        'last_contact' => $last_msg,
        'status' => $status
    ];
}

// Sort clients by status (Active first, then Pending, then Inactive)
usort($client_details, function($a, $b) {
    $status_order = ['Active' => 1, 'Pending' => 2, 'Inactive' => 3];
    return $status_order[$a['status']] - $status_order[$b['status']];
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Clients - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>


        /* Enhanced Client Management Styles */
        .client-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8b1538 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .client-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .client-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2.5rem;
            font-weight: 700;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .quick-actions .btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .quick-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .client-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .client-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
            cursor: pointer;
        }

        .client-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }

        .client-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .client-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .client-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .client-info h3 {
            margin: 0 0 0.25rem 0;
            color: var(--primary-color);
            font-weight: 600;
        }

        .client-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .client-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .stat-item.active {
            background: rgba(39, 174, 96, 0.1);
            border-color: rgba(39, 174, 96, 0.2);
        }

        .stat-item.pending {
            background: rgba(243, 156, 18, 0.1);
            border-color: rgba(243, 156, 18, 0.2);
        }

        .stat-item.closed {
            background: rgba(108, 117, 125, 0.1);
            border-color: rgba(108, 117, 125, 0.2);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
            font-weight: 700;
        }

        .client-header p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .quick-actions .btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .quick-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .client-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .client-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
            cursor: pointer;
        }

        .client-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }

        .client-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .client-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .client-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .client-info h3 {
            margin: 0 0 0.25rem 0;
            color: var(--primary-color);
            font-weight: 600;
        }

        .client-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .client-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .stat-item.active {
            background: rgba(39, 174, 96, 0.1);
            border-color: rgba(39, 174, 96, 0.2);
        }

        .stat-item.pending {
            background: rgba(243, 156, 18, 0.1);
            border-color: rgba(243, 156, 18, 0.2);
        }

        .stat-item.closed {
            background: rgba(108, 117, 125, 0.1);
            border-color: rgba(108, 117, 125, 0.2);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .client-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .client-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .client-status.status-active {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.2);
        }

        .client-status.status-pending {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.2);
        }

        .client-status.status-inactive {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }

        /* Enhanced Modal Styles */
        .modal-bg {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 800px;
            width: 90%;
            margin: 40px auto;
            max-height: 85vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 25px;
            width: 40px;
            height: 40px;
            background: #f8f9fa;
            border: none;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .close-modal:hover {
            background: #e9ecef;
            color: #333;
            transform: rotate(90deg);
        }

        .client-modal-header {
            padding: 2rem 2rem 1rem 2rem;
            border-bottom: 1px solid #e9ecef;
        }

        .client-profile {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .client-profile .client-avatar {
            width: 80px;
            height: 80px;
        }

        .client-profile .client-info h2 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-color);
            font-size: 1.8rem;
        }

        .client-contact {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .client-contact span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #666;
            font-size: 0.95rem;
        }

        .client-contact i {
            width: 16px;
            color: var(--primary-color);
        }

        .client-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .modal-sections {
            padding: 1rem 2rem 2rem 2rem;
        }

        .section {
            margin-bottom: 2rem;
        }

        .section h3 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0 0 1rem 0;
            color: var(--primary-color);
            font-size: 1.3rem;
            font-weight: 600;
        }

        .section h3 i {
            color: var(--primary-color);
        }

        .case-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .case-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .case-item:hover {
            background: #e9ecef;
            border-color: var(--primary-color);
        }

        .case-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .case-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .case-id {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .case-title h4 {
            margin: 0;
            color: #333;
            font-size: 1.1rem;
        }

        .case-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .case-details {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .case-details span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .case-details i {
            color: var(--primary-color);
            width: 14px;
        }

        .case-actions {
            display: flex;
            justify-content: flex-end;
        }

        .chat-area {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e9ecef;
        }

        .message-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 1rem;
        }

        .message-bubble {
            margin-bottom: 1rem;
            max-width: 80%;
        }

        .message-bubble.sent {
            margin-left: auto;
        }

        .message-bubble.received {
            margin-right: auto;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
        }

        .message-bubble.sent .message-header {
            color: var(--primary-color);
        }

        .message-bubble.received .message-header {
            color: #666;
        }

        .message-content {
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            word-break: break-word;
        }

        .message-bubble.sent .message-content {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .message-actions {
            text-align: center;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .client-grid {
                grid-template-columns: 1fr;
            }

            .client-stats {
                grid-template-columns: repeat(2, 1fr);
            }

        .modal-content {
                width: 95%;
                margin: 20px auto;
            }

            .client-profile {
                flex-direction: column;
                text-align: center;
            }

            .case-details {
                flex-direction: column;
                gap: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .client-header {
                padding: 1.5rem;
            }

            .client-header h1 {
                font-size: 2rem;
            }

            .quick-actions {
                flex-direction: column;
            }

            .quick-actions .btn {
                justify-content: center;
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
            <li><a href="attorney_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li><a href="attorney_cases.php"><i class="fas fa-gavel"></i><span>Manage Cases</span></a></li>
            <li><a href="attorney_documents.php"><i class="fas fa-file-alt"></i><span>Document Storage</span></a></li>
            <li><a href="attorney_document_generation.php"><i class="fas fa-file-alt"></i><span>Document Generation</span></a></li>
            <li><a href="attorney_schedule.php"><i class="fas fa-calendar-alt"></i><span>My Schedule</span></a></li>
            <li><a href="attorney_clients.php" class="active"><i class="fas fa-users"></i><span>My Clients</span></a></li>
            <li><a href="attorney_messages.php"><i class="fas fa-envelope"></i><span>Messages</span></a></li>
            <li><a href="attorney_efiling.php"><i class="fas fa-paper-plane"></i><span>E-Filing</span></a></li>
            <li><a href="attorney_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>

    <div class="main-content">
        <!-- Enhanced Header -->
        <div class="client-header">
            <h1><i class="fas fa-users"></i> My Clients</h1>
            <p>Manage your client relationships and case portfolios with professional care</p>
            </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="attorney_cases.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                New Case
            </a>
            <a href="attorney_messages.php" class="btn btn-secondary">
                <i class="fas fa-envelope"></i>
                Send Message
            </a>
            <a href="attorney_schedule.php" class="btn btn-secondary">
                <i class="fas fa-calendar"></i>
                Schedule Meeting
            </a>
            <button class="btn btn-secondary" onclick="refreshClientData()">
                <i class="fas fa-sync-alt"></i>
                Refresh Data
            </button>
                </div>

        <!-- Statistics Overview -->
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
            </div>
        </div>
                <div class="card-title">Total Clients</div>
                <div class="card-value"><?= number_format($total_clients) ?></div>
                <div class="card-description">Active client relationships</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-gavel"></i>
                    </div>
                </div>
                <div class="card-title">Active Cases</div>
                <div class="card-value"><?= number_format($active_cases) ?></div>
                <div class="card-description">Ongoing legal proceedings</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                <div class="card-title">Unread Messages</div>
                <div class="card-value"><?= number_format($unread_messages) ?></div>
                <div class="card-description">Require your attention</div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="card-title">Upcoming Appointments</div>
                <div class="card-value"><?= number_format($upcoming_appointments) ?></div>
                <div class="card-description">Next 7 days</div>
            </div>
        </div>

        <!-- Client Grid -->
        <div class="client-grid">
            <?php if (count($client_details) === 0): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #666;">
                    <i class="fas fa-users" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <h3>No Clients Yet</h3>
                    <p>You haven't been assigned any clients yet. Start by creating your first case.</p>
                    <a href="attorney_cases.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Your First Case
                    </a>
            </div>
            <?php else: ?>
                    <?php foreach ($client_details as $c): ?>
                    <div class="client-card" data-client-id="<?= $c['id'] ?>" data-client-name="<?= htmlspecialchars($c['name']) ?>">
                        <div class="client-card-header">
                            <div class="client-avatar">
                                <?php if ($c['profile_image'] && file_exists($c['profile_image'])): ?>
                                    <img src="<?= htmlspecialchars($c['profile_image']) ?>" alt="Client Profile">
                                <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="client-info">
                                <h3><?= htmlspecialchars($c['name']) ?></h3>
                                <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($c['email']) ?></p>
                                <p><i class="fas fa-phone"></i> <?= htmlspecialchars($c['phone']) ?></p>
                            </div>
                        </div>

                        <div class="client-stats">
                            <div class="stat-item active">
                                <div class="stat-number"><?= $c['active_cases'] ?></div>
                                <div class="stat-label">Active</div>
                            </div>
                            <div class="stat-item pending">
                                <div class="stat-number"><?= $c['pending_cases'] ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="stat-item closed">
                                <div class="stat-number"><?= $c['closed_cases'] ?></div>
                                <div class="stat-label">Closed</div>
                            </div>
                        </div>

                        <div class="client-actions">
                            <span class="client-status status-<?= strtolower($c['status']) ?>">
                                <?= $c['status'] ?>
                            </span>
                            <button class="btn btn-primary btn-sm" onclick="viewClientDetails(<?= $c['id'] ?>, '<?= htmlspecialchars($c['name']) ?>')">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            <a href="attorney_messages.php?client_id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">
                                <i class="fas fa-envelope"></i> Message
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Client Details Modal -->
    <div class="modal-bg" id="clientModalBg" style="z-index: 9999 !important;">
        <div class="modal-content" id="client-modal-content" style="z-index: 9999 !important;" style="z-index: 10000 !important;">
            <button class="close-modal" onclick="closeClientModal()">
                <i class="fas fa-times"></i>
            </button>
            <div id="clientModalBody">
                <!-- AJAX content here -->
            </div>
        </div>
    </div>

    <script>
        function viewClientDetails(clientId, clientName) {
            fetch('attorney_clients.php?ajax_client_details=1&client_id=' + clientId)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('clientModalBody').innerHTML = html;
                    document.getElementById('clientModalBg').style.display = 'block';
                    document.body.style.overflow = 'hidden';
                });
        }

        function closeClientModal() {
            document.getElementById('clientModalBg').style.display = 'none';
            document.getElementById('clientModalBody').innerHTML = '';
            document.body.style.overflow = 'auto';
        }

        function refreshClientData() {
            location.reload();
        }

        // Close modal when clicking outside
        document.getElementById('clientModalBg').addEventListener('click', function(e) {
            if (e.target === this) {
                closeClientModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeClientModal();
            }
    });
    </script>
</body>
</html> 