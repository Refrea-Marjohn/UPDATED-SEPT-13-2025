<?php
// AJAX handler for modal content (MUST be before any HTML output)
if (isset($_GET['ajax_client_details']) && isset($_GET['client_id'])) {
    require_once 'config.php';
    session_start();
    $admin_id = $_SESSION['user_id'];
    $cid = intval($_GET['client_id']);
    
    // Get client info
    $stmt = $conn->prepare("SELECT id, name, email, phone_number FROM user_form WHERE id=?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $cinfo = $stmt->get_result()->fetch_assoc();
    
    // Get all cases for this client (either assigned to admin or any attorney)
    $cases = [];
    $stmt = $conn->prepare("SELECT ac.*, a.name as attorney_name 
                          FROM attorney_cases ac 
                          LEFT JOIN user_form a ON ac.attorney_id = a.id 
                          WHERE ac.client_id=? 
                          ORDER BY ac.created_at DESC");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $cres = $stmt->get_result();
    while ($row = $cres->fetch_assoc()) $cases[] = $row;
    
    // Get recent messages (last 10) - check admin_messages table for messages to/from admin
    $msgs = [];
    $stmt = $conn->prepare("SELECT message, sent_at, 'client' as sender FROM admin_messages WHERE recipient_id=? AND admin_id=?
        UNION ALL
        SELECT message, sent_at, 'admin' as sender FROM admin_messages WHERE admin_id=? AND recipient_id=?
        ORDER BY sent_at DESC LIMIT 10");
    $stmt->bind_param("iiii", $admin_id, $cid, $admin_id, $cid);
    $stmt->execute();
    $mres = $stmt->get_result();
    while ($row = $mres->fetch_assoc()) $msgs[] = $row;
    ?>
    <div style="margin-bottom:18px;">
        <h2 style="margin-bottom:6px;">Client: <?= htmlspecialchars($cinfo['name']) ?></h2>
        <div><b>Email:</b> <?= htmlspecialchars($cinfo['email']) ?> | <b>Phone:</b> <?= htmlspecialchars($cinfo['phone_number']) ?></div>
    </div>
    <div>
        <h3>Cases</h3>
        <div class="case-list">
            <?php if (count($cases) === 0): ?>
                <div style="color:#888;">No cases for this client.</div>
            <?php else: foreach ($cases as $case): ?>
                <div class="case-item">
                    <b>#<?= htmlspecialchars($case['id']) ?> - <?= htmlspecialchars($case['title']) ?></b> (<?= htmlspecialchars($case['status']) ?>)
                    <div style="font-size:0.97em; color:#666;">
                        Type: <?= htmlspecialchars($case['case_type']) ?> | 
                        Attorney: <?= htmlspecialchars($case['attorney_name'] ?? 'Unassigned') ?> | 
                        Next Hearing: <?= htmlspecialchars($case['next_hearing'] ?? 'N/A') ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <div class="chat-area">
        <h3 style="margin-bottom:8px;">Recent Messages</h3>
        <?php if (count($msgs) === 0): ?>
            <div style="color:#888;">No messages yet.</div>
        <?php else: foreach (array_reverse($msgs) as $m): ?>
            <div class="chat-bubble <?= $m['sender'] === 'admin' ? 'sent' : 'received' ?>">
                <b><?= $m['sender'] === 'admin' ? 'You' : 'Client' ?>:</b> <?= htmlspecialchars($m['message']) ?>
                <div class="chat-meta">Sent at: <?= htmlspecialchars($m['sent_at']) ?></div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php
    exit();
}

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

// Fetch all clients in the system (admin can see all potential clients)
$clients = [];
$stmt = $conn->prepare("SELECT id, name, email, phone_number FROM user_form WHERE user_type='client' ORDER BY name");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clients[] = $row;

// Total clients
$total_clients = count($clients);

// Total active cases in the system
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE status='Active'");
$stmt->execute();
$active_cases = $stmt->get_result()->fetch_row()[0];

// Unread messages for admin (from all clients)
$stmt = $conn->prepare("SELECT COUNT(*) FROM admin_messages WHERE recipient_id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$unread_messages = $stmt->get_result()->fetch_row()[0];

// Total cases in the system
$stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases");
$stmt->execute();
$total_cases = $stmt->get_result()->fetch_row()[0];

// For each client, get their active cases count and last contact
$client_details = [];
foreach ($clients as $c) {
    $cid = $c['id'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE client_id=? AND status='Active'");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_row()[0];
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attorney_cases WHERE client_id=?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $total_client_cases = $stmt->get_result()->fetch_row()[0];
    
    $stmt = $conn->prepare("SELECT sent_at FROM (
        SELECT sent_at FROM admin_messages WHERE recipient_id=? AND admin_id=?
        UNION ALL
        SELECT sent_at FROM admin_messages WHERE admin_id=? AND recipient_id=?
        ORDER BY sent_at DESC LIMIT 1
    ) as t ORDER BY sent_at DESC LIMIT 1");
    $stmt->bind_param("iiii", $admin_id, $cid, $admin_id, $cid);
    $stmt->execute();
    $last_msg = $stmt->get_result()->fetch_row()[0] ?? '-';
    
    $status = $active > 0 ? 'Active' : 'Inactive';
    $client_details[] = [
        'id' => $cid,
        'name' => $c['name'],
        'email' => $c['email'],
        'phone' => $c['phone_number'],
        'active_cases' => $active,
        'total_cases' => $total_client_cases,
        'last_contact' => $last_msg,
        'status' => $status
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        /* Enhanced Client Management Styles - Exact Copy from Attorney */
        .client-header {
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
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
            border-color: #1976d2;
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
            color: #1976d2;
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
            color: #1976d2;
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
            align-items: center;
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

        .status-active {
            background: rgba(39, 174, 96, 0.1);
            color: #2e7d32;
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .status-inactive {
            background: rgba(244, 67, 54, 0.1);
            color: #c62828;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        /* Button Styles */
        .btn {
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: #1976d2;
            color: white;
        }

        .btn-primary:hover {
            background: #1565c0;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }
        .modal-bg { display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index: 9999; }
        .modal-content {
            background:#fff;
            border-radius:10px;
            max-width:600px;
            margin:60px auto;
            padding:32px;
            position:relative;
            max-height:80vh;
            overflow-y:auto;
            word-wrap:break-word;
        }
        .close-modal { position:absolute; top:16px; right:20px; font-size:1.5em; cursor:pointer; color:#888; }
        .case-list { margin-top:18px; }
        .case-item { background:#f8f9fa; border-radius:8px; padding:10px 16px; margin-bottom:10px; }
        .section-divider { border-bottom:1px solid #e0e0e0; margin:24px 0 16px 0; }
        .chat-area { margin-top:28px; }
        .chat-bubble { 
            margin-bottom:10px; 
            padding:10px 14px; 
            border-radius:8px; 
            background:#f3f7fa; 
            display:inline-block; 
            word-break:break-word; 
            max-width:80%; 
        }
        .chat-bubble.sent { 
            background:#e3f0ff; 
            margin-left:auto; 
            display:block; 
        }
        .chat-bubble.received { 
            background:#f0f0f0; 
            margin-right:auto; 
            display:block; 
        }
        .chat-meta { 
            font-size:0.8em; 
            color:#666; 
            margin-top:5px; 
        }
        .section-title { font-size:1.2em; font-weight:600; margin-bottom:10px; margin-top:18px; }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
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
            <li><a href="admin_clients.php" class="active"><i class="fas fa-users"></i><span>My Clients</span></a></li>
            <li><a href="admin_messages.php"><i class="fas fa-comments"></i><span>Messages</span></a></li>
            <li><a href="admin_audit.php"><i class="fas fa-history"></i><span>Audit Trail</span></a></li>
        </ul>
    </div>
    <div class="main-content">
        <div class="header">
            <div class="header-title">
                <h1>Client Management</h1>
                <p>Manage all clients in the system and view their cases</p>
            </div>
            <div class="user-info">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="Admin" style="object-fit:cover;width:60px;height:60px;border-radius:50%;border:2px solid #1976d2;">
                <div class="user-details">
                    <h3><?php echo $_SESSION['user_name']; ?></h3>
                    <p>Administrator</p>
                </div>
            </div>
        </div>
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-icon"><i class="fas fa-users"></i></div>
                <div class="card-info"><h3>Total Clients</h3><p><?= $total_clients ?></p></div>
            </div>
            <div class="card">
                <div class="card-icon"><i class="fas fa-gavel"></i></div>
                <div class="card-info"><h3>Active Cases</h3><p><?= $active_cases ?></p></div>
            </div>
            <div class="card">
                <div class="card-icon"><i class="fas fa-envelope"></i></div>
                <div class="card-info"><h3>Unread Messages</h3><p><?= $unread_messages ?></p></div>
            </div>
            <div class="card">
                <div class="card-icon"><i class="fas fa-folder"></i></div>
                <div class="card-info"><h3>Total Cases</h3><p><?= $total_cases ?></p></div>
            </div>
        </div>


        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="admin_managecases.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                New Case
            </a>
            <a href="admin_messages.php" class="btn btn-secondary">
                <i class="fas fa-envelope"></i>
                Send Message
            </a>
            <a href="admin_schedule.php" class="btn btn-secondary">
                <i class="fas fa-calendar"></i>
                Schedule Meeting
            </a>
            <button class="btn btn-secondary" onclick="refreshClientData()">
                <i class="fas fa-sync-alt"></i>
                Refresh Data
            </button>
        </div>

        <!-- Client Grid -->
        <div class="client-grid">
            <?php if (count($client_details) === 0): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #666;">
                    <i class="fas fa-users" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <h3>No Clients Yet</h3>
                    <p>No clients found in the system. Start by adding new clients.</p>
                </div>
            <?php else: ?>
                <?php foreach ($client_details as $c): ?>
                <div class="client-card" data-client-id="<?= $c['id'] ?>" data-client-name="<?= htmlspecialchars($c['name']) ?>">
                    <div class="client-card-header">
                        <div class="client-avatar">
                            <div class="avatar-placeholder">
                                <i class="fas fa-user"></i>
                            </div>
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
                            <div class="stat-number">0</div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-item closed">
                            <div class="stat-number"><?= $c['total_cases'] - $c['active_cases'] ?></div>
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
                        <a href="admin_messages.php?client_id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-envelope"></i> Message
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <!-- Client Details Modal -->
    <div class="modal-bg" id="clientModalBg" style="z-index: 9999 !important;">
        <div class="modal-content" id="client-modal-content" style="z-index: 9999 !important;" style="z-index: 10000 !important;">
            <span class="close-modal" onclick="closeClientModal()">&times;</span>
            <div id="clientModalBody">
                <!-- AJAX content here -->
            </div>
        </div>
    </div>
    <script>
    function closeClientModal() {
        document.getElementById('clientModalBg').style.display = 'none';
        document.getElementById('clientModalBody').innerHTML = '';
    }
    
    function viewClientDetails(clientId, clientName) {
        fetch('admin_clients.php?ajax_client_details=1&client_id=' + clientId)
            .then(r => r.text())
            .then(html => {
                document.getElementById('clientModalBody').innerHTML = html;
                document.getElementById('clientModalBg').style.display = 'block';
                document.body.style.overflow = 'hidden';
            });
    }

    function refreshClientData() {
        location.reload();
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal-bg')) {
            closeClientModal();
        }
    }
    </script>
</body>
</html>
