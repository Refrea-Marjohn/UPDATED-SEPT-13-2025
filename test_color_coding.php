<?php
require_once 'session_manager.php';
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login_form.php');
    exit();
}

$user_type = $_SESSION['user_type'] ?? 'unknown';
$user_name = $_SESSION['user_name'] ?? $_SESSION['attorney_name'] ?? $_SESSION['admin_name'] ?? $_SESSION['employee_name'] ?? $_SESSION['client_name'] ?? 'Unknown';

// Get some test data based on actual database users
$test_user_names = [
    'Laica Castillo Refrea',
    'Mario Delmo Refrea', 
    'Santiago, Macky Refrea',
    'Mar John Refrea',
    'Yuhan Nerfy Refrea'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Color Coding Test - Opiña Law Office</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
        }
        .test-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 10px 0;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        /* Test the user color coding based on actual database users */
        .test-card[data-attorney-name="Laica Castillo Refrea"] {
            border-left: 4px solid #51cf66;
            background: linear-gradient(135deg, rgba(81, 207, 102, 0.05) 0%, rgba(81, 207, 102, 0.1) 100%);
        }
        
        .test-card[data-attorney-name="Mario Delmo Refrea"] {
            border-left: 4px solid #74c0fc;
            background: linear-gradient(135deg, rgba(116, 192, 252, 0.05) 0%, rgba(116, 192, 252, 0.1) 100%);
        }
        
        .test-card[data-attorney-name="Santiago, Macky Refrea"] {
            border-left: 4px solid #ffd43b;
            background: linear-gradient(135deg, rgba(255, 212, 59, 0.05) 0%, rgba(255, 212, 59, 0.1) 100%);
        }
        
        .test-card[data-attorney-name="Mar John Refrea"] {
            border-left: 4px solid #ff6b6b;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.05) 0%, rgba(255, 107, 107, 0.1) 100%);
        }
        
        .test-card[data-attorney-name="Yuhan Nerfy Refrea"] {
            border-left: 4px solid #da77f2;
            background: linear-gradient(135deg, rgba(218, 119, 242, 0.05) 0%, rgba(218, 119, 242, 0.1) 100%);
        }
        
        .test-card[data-attorney-name]:not([data-attorney-name="Laica Castillo Refrea"]):not([data-attorney-name="Mario Delmo Refrea"]):not([data-attorney-name="Santiago, Macky Refrea"]):not([data-attorney-name="Mar John Refrea"]):not([data-attorney-name="Yuhan Nerfy Refrea"]) {
            border-left: 4px solid #6c757d;
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.05) 0%, rgba(108, 117, 125, 0.1) 100%);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .card-title {
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        
        .card-subtitle {
            color: #666;
            font-size: 0.9rem;
            margin: 0;
        }
        
        .debug-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.8rem;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Color Coding Test Page</h1>
        
        <div class="test-section">
            <h2>Session Information</h2>
            <div class="debug-info">
                <strong>User ID:</strong> <?= htmlspecialchars($_SESSION['user_id'] ?? 'NULL') ?><br>
                <strong>User Type:</strong> <?= htmlspecialchars($user_type) ?><br>
                <strong>User Name:</strong> <?= htmlspecialchars($user_name) ?><br>
                <strong>Attorney Name:</strong> <?= htmlspecialchars($_SESSION['attorney_name'] ?? 'NULL') ?><br>
                <strong>Admin Name:</strong> <?= htmlspecialchars($_SESSION['admin_name'] ?? 'NULL') ?><br>
                <strong>Employee Name:</strong> <?= htmlspecialchars($_SESSION['employee_name'] ?? 'NULL') ?><br>
                <strong>Client Name:</strong> <?= htmlspecialchars($_SESSION['client_name'] ?? 'NULL') ?>
            </div>
        </div>
        
        <div class="test-section">
            <h2>Test User Color Coding</h2>
            <p>These cards should show different colors based on the actual database users:</p>
            
            <?php foreach ($test_user_names as $user_name): ?>
            <div class="test-card" data-attorney-name="<?= htmlspecialchars($user_name) ?>">
                <div class="card-header">
                    <i class="fas fa-user-tie"></i>
                    <div>
                        <h3 class="card-title"><?= htmlspecialchars($user_name) ?></h3>
                        <p class="card-subtitle">Test User Card</p>
                    </div>
                </div>
                <div class="debug-info">
                    <strong>data-attorney-name:</strong> "<?= htmlspecialchars($user_name) ?>"<br>
                    <strong>CSS Selector:</strong> .test-card[data-attorney-name="<?= htmlspecialchars($user_name) ?>"]
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="test-card" data-attorney-name="Unknown Attorney">
                <div class="card-header">
                    <i class="fas fa-user-tie"></i>
                    <div>
                        <h3 class="card-title">Unknown Attorney</h3>
                        <p class="card-subtitle">This should get the default yellow color</p>
                    </div>
                </div>
                <div class="debug-info">
                    <strong>data-attorney-name:</strong> "Unknown Attorney"<br>
                    <strong>CSS Selector:</strong> Default fallback color
                </div>
            </div>
        </div>
        
        <div class="test-section">
            <h2>Instructions</h2>
            <p>If the color coding is working correctly, you should see:</p>
            <ul>
                <li><strong>Laica Castillo Refrea:</strong> Light Green border and background</li>
                <li><strong>Mario Delmo Refrea:</strong> Light Blue border and background</li>
                <li><strong>Santiago, Macky Refrea:</strong> Light Orange border and background</li>
                <li><strong>Mar John Refrea:</strong> Light Red border and background</li>
                <li><strong>Yuhan Nerfy Refrea:</strong> Light Violet border and background</li>
                <li><strong>Unknown Attorney:</strong> Light Gray border and background (default)</li>
            </ul>
        </div>
    </div>
</body>
</html>
