<?php
require_once 'session_manager.php';
validateUserAccess('client');
require_once 'config.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';
$client_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_image FROM user_form WHERE id=?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$res = $stmt->get_result();
$profile_image = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
}
if (!$profile_image || !file_exists($profile_image)) {
        $profile_image = 'images/default-avatar.jpg';
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Generation - Opiña Law Office</title>
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
            <li>
                <a href="client_dashboard.php">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="client_documents.php">
                    <i class="fas fa-file-alt"></i>
                    <span>Document Generation</span>
                </a>
            </li>
            <li>
                <a href="client_cases.php">
                    <i class="fas fa-gavel"></i>
                    <span>My Cases</span>
                </a>
            </li>
            <li>
                <a href="client_schedule.php">
                    <i class="fas fa-calendar-alt"></i>
                    <span>My Schedule</span>
                </a>
            </li>
            <li>
                <a href="client_messages.php">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                </a>
            </li>

        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>Document Generation</h1>
                <p>Generate and manage your document storage</p>
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

         <!-- Document Generation Grid -->
         <div class="document-grid">
            <!-- Row 1 -->
            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>Affidavit of Loss</h3>
                <p>Generate affidavit of loss document</p>
                <a href="files-generation/generate_affidavit_of_loss.php" class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </a>
            </div>

            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-handshake"></i>
                </div>
                <h3>Deed of Sale</h3>
                <p>Generate deed of sale document</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-user"></i>
                </div>
                <h3>Sworn Affidavit of Solo Parent</h3>
                <p>Generate sworn affidavit of solo parent</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <!-- Row 2 -->
            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-female"></i>
                </div>
                <h3>Sworn Affidavit of Mother</h3>
                <p>Generate sworn affidavit of mother</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-male"></i>
                </div>
                <h3>Sworn Affidavit of Father</h3>
                <p>Generate sworn affidavit of father</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-female"></i>
                </div>
                <h3>Sworn Statement of Mother</h3>
                <p>Generate sworn statement of mother</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <!-- Row 3 -->
            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-male"></i>
                </div>
                <h3>Sworn Statement of Father</h3>
                <p>Generate sworn statement of father</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Joint Affidavit of Two Disinterested Persons</h3>
                <p>Generate joint affidavit of two disinterested persons</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>

            <div class="document-box">
                <div class="document-icon">
                    <i class="fas fa-file-contract"></i>
                </div>
                <h3>Agreement</h3>
                <p>Generate agreement document</p>
                <button class="btn btn-primary generate-btn">
                    <i class="fas fa-magic"></i> Generate
                </button>
            </div>
        </div>
    </div>

    <style>
        .document-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            padding: 20px;
        }

        .document-box {
            background: white;
            border-radius: 10px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 15px;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .document-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .document-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
        }

        .document-info h3 {
            margin: 0;
            font-size: 1.2rem;
            color: #333;
        }

        .document-info p {
            margin: 5px 0 0;
            color: #666;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        @media (max-width: 1024px) {
            .document-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .document-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    
    <script>
        // Profile dropdown functions removed - profile is non-clickable on this page
    </script>
</body>
</html> 