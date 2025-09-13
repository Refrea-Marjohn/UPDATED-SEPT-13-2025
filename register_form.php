<?php
// Ensure proper session handling
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
@include 'config.php';


// Handle canceling OTP verification and going back to registration
if (isset($_POST['cancel_otp'])) {
    unset($_SESSION['pending_registration']);
    unset($_SESSION['show_otp_modal']);
    // Don't set error message since we're handling it client-side now
    // Persist session changes immediately and return a simple response
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: text/plain');
    echo 'OK';
    exit();
}

// Handle resending OTP
if (isset($_POST['resend_otp']) && isset($_SESSION['pending_registration'])) {
    $pending = $_SESSION['pending_registration'];
    
    // Generate new OTP
    $new_otp = (string)rand(100000, 999999); // Ensure OTP is stored as string
    $_SESSION['pending_registration']['otp'] = $new_otp;
    $_SESSION['pending_registration']['otp_expires'] = time() + 900; // 15 minutes
    
    // Release the session write lock before sending email to avoid blocking concurrent requests
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // Send new OTP email
    require_once 'send_otp_email.php';
    if (send_otp_email($pending['email'], $new_otp)) {
        header('Content-Type: text/plain');
        echo 'OK';
    } else {
        header('Content-Type: text/plain');
        echo 'ERROR';
    }
    exit();
}

// Handle OTP verification
if (isset($_POST['verify_otp']) && isset($_SESSION['pending_registration'])) {
    $input_otp = trim($_POST['otp'] ?? '');
    $pending = $_SESSION['pending_registration'];
    
    
    // Validate that we have all required data
    if (!isset($pending['otp']) || !isset($pending['email']) || !isset($pending['password'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Registration data corrupted. Please register again.']);
        exit();
    }
    
    if (time() > $pending['otp_expires']) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'OTP expired. Please click Resend OTP to get a new code.']);
        exit();
    } elseif ((string)$input_otp === (string)$pending['otp']) {
        // Insert user
        $hashed_password = password_hash($pending['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO user_form(name, email, phone_number, password, user_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssss', $pending['name'], $pending['email'], $pending['phone'], $hashed_password, $pending['user_type']);
        if ($stmt->execute()) {
            unset($_SESSION['pending_registration']);
            unset($_SESSION['show_otp_modal']);
            $_SESSION['success'] = 'Registration successful! You can now login.';
            // Force session write before response
            session_write_close();
            
            // Return JSON response for AJAX handling
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Registration successful!', 'redirect' => 'login_form.php']);
            exit();
        } else {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Registration failed. Please try again.']);
            exit();
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid OTP. Please check your email and try again.']);
        exit();
    }
}

// Handle registration form submission
if (isset($_POST['submit'])) {
    $lastname = mysqli_real_escape_string($conn, $_POST['lastname']);
    $firstname = mysqli_real_escape_string($conn, $_POST['firstname']);
    $middlename = mysqli_real_escape_string($conn, $_POST['middlename']);
    
    // Check for spaces in name fields
    if (strpos($lastname, ' ') !== false) {
        $_SESSION['error'] = "Lastname cannot contain spaces.";
        header("Location: register_form.php");
        exit();
    }
    if (strpos($firstname, ' ') !== false) {
        $_SESSION['error'] = "Firstname cannot contain spaces.";
        header("Location: register_form.php");
        exit();
    }
    if (strpos($middlename, ' ') !== false) {
        $_SESSION['error'] = "Middlename cannot contain spaces.";
        header("Location: register_form.php");
        exit();
    }
    
    $name = trim($lastname . ', ' . $firstname . ' ' . $middlename); // Format: Lastname, Firstname Middlename
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $cphone = mysqli_real_escape_string($conn, $_POST['cphone']);
    $pass = $_POST['password'];
    $cpass = $_POST['cpassword'];
    $user_type = 'client'; // Only clients can register through this form

    // Phone number validation (server-side)
    if (!preg_match('/^\d{11}$/', $phone)) {
        $_SESSION['error'] = "Phone number must be exactly 11 digits.";
        header("Location: register_form.php");
        exit();
    }

    // Confirm phone number validation (server-side)
    if (!preg_match('/^\d{11}$/', $cphone)) {
        $_SESSION['error'] = "Confirm phone number must be exactly 11 digits.";
        header("Location: register_form.php");
        exit();
    }

    // Phone number match check
    if ($phone != $cphone) {
        $_SESSION['error'] = "Phone numbers do not match!";
        header("Location: register_form.php");
        exit();
    }

    // Email validation (server-side) - accepts any valid email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
        header("Location: register_form.php");
        exit();
    }
    
    // Check for spaces in email
    if (strpos($email, ' ') !== false) {
        $_SESSION['error'] = "Email cannot contain spaces.";
        header("Location: register_form.php");
        exit();
    }

    // Password requirements check (server-side)
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%\^&*()_\-+={}\[\]:;"\'<>,.?\/~`|\\\\])[A-Za-z\d!@#$%\^&*()_\-+={}\[\]:;"\'<>,.?\/~`|\\\\]{8,}$/', $pass)) {
        $_SESSION['error'] = "Password must be at least 8 characters, include uppercase and lowercase letters, at least one number, and at least one allowed special character (! @ # $ % ^ & * ( ) _ + - = { } [ ] : ; \" ' < > , . ? / ~ ` | \\).";
        header("Location: register_form.php");
        exit();
    }
    
    // Check for spaces in password
    if (strpos($pass, ' ') !== false) {
        $_SESSION['error'] = "Password cannot contain spaces.";
        header("Location: register_form.php");
        exit();
    }
    
    // Check for spaces in confirm password
    if (strpos($cpass, ' ') !== false) {
        $_SESSION['error'] = "Confirm password cannot contain spaces.";
        header("Location: register_form.php");
        exit();
    }

    // Password match check
    if ($pass != $cpass) {
        $_SESSION['error'] = "Passwords do not match!";
        header("Location: register_form.php");
        exit();
    }

    // Check if user already exists (email only)
    $select = "SELECT * FROM user_form WHERE email = ?";
    $stmt = mysqli_prepare($conn, $select);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        $_SESSION['error'] = "User already exists!";
        header("Location: register_form.php");
        exit();
    }

    // OTP logic
    require_once __DIR__ . '/vendor/autoload.php';
    $otp = (string)rand(100000, 999999); // Ensure OTP is stored as string
    $_SESSION['pending_registration'] = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'password' => $pass,
        'user_type' => $user_type,
        'otp' => $otp,
        'otp_expires' => time() + 900 // 15 minutes
    ];
    // Send OTP email and ensure it succeeds before showing OTP modal
    require_once 'send_otp_email.php';
    $otpSent = send_otp_email($email, $otp);
    if ($otpSent) {
        $_SESSION['show_otp_modal'] = true;
    } else {
        // If sending failed, clear pending registration to avoid confusion and show error
        unset($_SESSION['pending_registration']);
        $_SESSION['error'] = 'We could not send the OTP to your email. Please check your email address or try again later.';
        header('Location: register_form.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Form</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: #f5f5f5;
        }

        .left-container {
            width: 45%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #5D0E26, #8B1538);
            padding: 20px;
            position: relative;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
        }

        .title-container {
            display: flex;
            align-items: center;
            position: absolute;
            top: 20px;
            left: 30px;
        }

        .title-container img {
            width: 45px;
            height: 45px;
            margin-right: 8px;
        }

        .title {
            font-size: 24px;
            font-weight: 600;
            color: #ffffff;
            letter-spacing: 1px;
        }

        .header-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            gap: 6px;
            
        }

        .header-container img {
            width: 35px;
            height: 35px;
        }

        .law-office-title {
            margin-top: 50px;
            text-align: center;
            font-size: 32px;
            font-weight: 800;
            color: #ffffff;
            font-family: "Playfair Display", serif;
            letter-spacing: 1.8px;
            text-shadow: 0 3px 8px rgba(0, 0, 0, 0.5);
            line-height: 1.2;
        }

        .form-header {
            font-size: 22px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 15px;
            color: #ffffff;
        }

        .form-container {
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
        }

        .form-container label {
            font-size: 12px;
            font-weight: 500;
            display: block;
            margin: 8px 0 2px;
            color: #ffffff;
            text-align: left;
        }

        .form-container input, .form-container select {
            width: 100%;
            padding: 8px 10px;
            font-size: 13px;
            border: none;
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
            background: transparent;
            color: #ffffff;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-container input:focus, .form-container select:focus {
            border-bottom: 2px solid #ffffff;
        }

        .form-container input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-container select option {
            background: #5D0E26;
            color: #ffffff;
        }

        .password-container {
            position: relative;
            width: 100%;
        }

        .password-container i {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .password-container i:hover {
            color: #ffffff;
        }

        .form-container .form-btn {
            background: #ffffff;
            color: #5D0E26;
            border: none;
            cursor: pointer;
            padding: 10px;
            font-size: 14px;
            font-weight: 600;
            width: 100%;
            margin-top: 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .form-container .form-btn:hover {
            background: #f8f8f8;
            color: #8B1538;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .right-container {
            width: 55%;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #5D0E26;
            text-align: center;
            padding: 20px;
            background: #ffffff;
            background-image: url('images/atty3.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            backdrop-filter: blur(5px);
            position: relative;
        }

        .right-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('images/atty3.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: blur(3px);
            z-index: -1;
        }

        .error-popup {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #ff6b6b;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            z-index: 9999;
            width: 90%;
            max-width: 400px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translate(-50%, -20px);
                opacity: 0;
            }
            to {
                transform: translate(-50%, 0);
                opacity: 1;
            }
        }

        .error-popup p {
            margin: 0;
            font-size: 14px;
        }

        .error-popup button {
            background: white;
            border: none;
            padding: 8px 15px;
            color: #ff6b6b;
            font-weight: 500;
            margin-top: 10px;
            cursor: pointer;
            border-radius: 4px;
            transition: background 0.3s ease;
        }

        .error-popup button:hover {
            background: #f0f0f0;
        }

        .message-popup {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #5D0E26;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            z-index: 9999;
            width: 90%;
            max-width: 400px;
            animation: slideIn 0.3s ease;
        }

        .message-popup button {
            background: white;
            border: none;
            padding: 8px 15px;
            color: #5D0E26;
            font-weight: 500;
            margin-top: 10px;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .message-popup.error button {
            color: #ff6b6b;
        }

        .message-popup.success button {
            color: #51cf66;
        }

        .message-popup button:hover {
            background: #f0f0f0;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        /* Disabled button styles */
        button:disabled {
            opacity: 0.6 !important;
            cursor: not-allowed !important;
            pointer-events: none;
        }

        .login-box h1 {
            font-size: 48px;
            font-weight: 700;
            color: #5D0E26;
            margin-bottom: 20px;
            line-height: 1.3;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .mirror-shine {
            position: relative;
            display: inline-block;
            background: linear-gradient(
                90deg,
                #5D0E26 0%,
                #5D0E26 45%,
                #ffffff 50%,
                #5D0E26 55%,
                #5D0E26 100%
            );
            background-size: 200% 100%;
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: mirrorShine 3s ease-in-out infinite;
        }

        @keyframes mirrorShine {
            0% {
                background-position: -100% 0;
            }
            100% {
                background-position: 100% 0;
            }
        }

        .login-btn {
            display: inline-block;
            background: linear-gradient(135deg, #5D0E26, #8B1538);
            color: white;
            text-decoration: none;
            padding: 18px 40px;
            font-size: 20px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(93, 14, 38, 0.4);
        }

        .login-btn:hover {
            background: linear-gradient(135deg, #8B1538, #5D0E26);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(93, 14, 38, 0.5);
        }

        @media (max-width: 1024px) {
            .left-container {
                width: 50%;
            }

            .right-container {
                width: 50%;
            }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }

            .left-container, .right-container {
                width: 100%;
                padding: 40px 20px;
            }

            .law-office-title {
                font-size: 34px;
            }

            .form-header {
                font-size: 24px;
            }

            .login-box h1 {
                font-size: 40px;
            }
        }

        @media (max-width: 480px) {
            .title {
                font-size: 20px;
            }

            .law-office-title {
                font-size: 28px;
            }

            .form-header {
                font-size: 22px;
            }

            .form-container input {
                font-size: 14px;
                padding: 10px 12px;
            }

            .form-container .form-btn {
                padding: 12px;
                font-size: 15px;
            }

            .login-box h1 {
                font-size: 32px;
            }

            .login-btn {
                padding: 16px 32px;
                font-size: 18px;
            }
        }

        /* Prevent tiny page scrollbar on desktop while allowing mobile scroll */
        @media (min-width: 769px) {
            html, body { height: 100%; overflow: hidden; }
            .left-container, .right-container { height: 100vh; overflow: hidden; }
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-popup">
            <p><?php echo $_SESSION['error']; ?></p>
            <button onclick="closePopup()">OK</button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="left-container">
        <div class="title-container">
            <img src="images/logo.jpg" alt="Logo">
            <div class="title">LawFirm.</div>
        </div>

        <div class="header-container">
            <h1 class="law-office-title">Opiña Law<br>Office</h1>
            <img src="images/justice.png" alt="Attorney Icon">
        </div>

        <div class="form-container">
            <h2 class="form-header">Register</h2>

            <form action="" method="post">
                <label for="lastname">Name</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" name="lastname" id="lastname" required placeholder="Lastname" style="flex:1;" oninput="this.value = this.value.replace(/\s/g, '')">
                    <input type="text" name="firstname" id="firstname" required placeholder="Firstname" style="flex:1;" oninput="this.value = this.value.replace(/\s/g, '')">
                    <input type="text" name="middlename" id="middlename" placeholder="Middlename" style="flex:1;" oninput="this.value = this.value.replace(/\s/g, '')">
                </div>

                <label for="email">Email</label>
                <input type="email" name="email" id="email" required placeholder="Enter your email" pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$" title="Email must be a valid @gmail.com address only" oninput="this.value = this.value.replace(/\s/g, '')">

                <label for="phone">Phone Number</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" name="phone" id="phone" required placeholder="Enter your phone number" maxlength="11" pattern="\d{11}" title="Phone number must be exactly 11 digits" style="flex:1;">
                    <input type="text" name="cphone" id="cphone" required placeholder="Confirm phone number" maxlength="11" pattern="\d{11}" title="Phone number must be exactly 11 digits" style="flex:1;">
                </div>

                <input type="hidden" name="user_type" value="client">

                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" required placeholder="Enter your password" oninput="this.value = this.value.replace(/\s/g, '')">
                    <i class="fas fa-eye" id="togglePassword"></i>
                </div>
                <ul style="color:#fff; font-size:12px; margin-bottom:8px; margin-top:2px; padding-left:18px;">
                    <li>Password requirements:</li>
                    <li>At least 8 characters</li>
                    <li>Must include uppercase and lowercase letters</li>
                    <li>Must include at least one number</li>
                    <li>Must include at least one special character</li>
                    <li>Allowed: ! @ # $ % ^ & * ( ) _ + - = { } [ ] : ; " ' < > , . ? / ~ ` | \</li>
                </ul>

                <label for="cpassword">Confirm Password</label>
                <div class="password-container">
                    <input type="password" name="cpassword" id="cpassword" required placeholder="Confirm your password" oninput="this.value = this.value.replace(/\s/g, '')">
                    <i class="fas fa-eye" id="toggleCPassword"></i>
                </div>

                <input type="submit" name="submit" value="Register" class="form-btn">
            </form>
        </div>
    </div>

    <div class="right-container">
        <div class="login-box">
            <h1 class="mirror-shine">Already have an account?</h1>
        </div>
        <a href="login_form.php" class="login-btn">Login Now</a>
    </div>

    <script>
        document.getElementById('togglePassword').addEventListener('click', function () {
            let passwordField = document.getElementById('password');
            let icon = this;
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        document.getElementById('toggleCPassword').addEventListener('click', function () {
            let passwordField = document.getElementById('cpassword');
            let icon = this;
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        function closePopup() {
            document.querySelector('.error-popup').style.display = 'none';
        }

        // Prevent spaces in critical fields (email, password, confirm password)
        ['email','password','cpassword'].forEach(function(id){
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('keydown', function(e){ if (e.key === ' ') e.preventDefault(); });
            el.addEventListener('input', function(){ this.value = this.value.replace(/\s+/g,''); });
        });

        // Password validation (client-side)
        document.querySelector('form').addEventListener('submit', function(e) {
            var pass = document.getElementById('password').value;
            var cpass = document.getElementById('cpassword').value;
            var phone = document.getElementById('phone').value;
            var cphone = document.getElementById('cphone').value;
            var requirements = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%\^&*()_\-+={}\[\]:;"'<>,.?\/~`|\\])[A-Za-z\d!@#$%\^&*()_\-+={}\[\]:;"'<>,.?\/~`|\\]{8,}$/;
            if (!requirements.test(pass)) {
                alert('Password must be at least 8 characters, include uppercase and lowercase letters, at least one number, and at least one allowed special character (! @ # $ % ^ & * ( ) _ + - = { } [ ] : ; " \'' + ' < > , . ? / ~ ` | \\).');
                e.preventDefault();
                return false;
            }
            if (pass !== cpass) {
                alert('Confirm password does not match the password.');
                e.preventDefault();
                return false;
            }
            if (phone !== cphone) {
                alert('Phone numbers do not match.');
                e.preventDefault();
                return false;
            }
        });

        // Limit phone input to 11 digits only (client-side)
        document.getElementById('phone').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^\d]/g, '').slice(0, 11);
        });

        // Limit confirm phone input to 11 digits only (client-side)
        document.getElementById('cphone').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^\d]/g, '').slice(0, 11);
        });
    </script>

    <script src="https://kit.fontawesome.com/cc86d7b31d.js" crossorigin="anonymous"></script>

    <!-- OTP Verification Modal -->
    <?php if (isset($_SESSION['show_otp_modal']) && isset($_SESSION['pending_registration'])): ?>
    <div class="otp-modal" id="otpModal" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(93, 14, 38, 0.8); display: flex; align-items: center; justify-content: center; z-index: 9999;">
        <div style="background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); padding: 40px 35px; max-width: 480px; width: 90%; position: relative; animation: slideIn 0.3s ease;">
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="color: #5D0E26; margin-bottom: 15px; font-size: 32px; font-weight: 700; font-family: 'Playfair Display', serif; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">Opiña Law Office</h2>
                <h3 style="color: #5D0E26; margin-bottom: 20px; font-size: 22px; font-weight: 600;">Verify Your Email</h3>
            </div>
            <div style="color: #666; margin-bottom: 25px; text-align: center; font-size: 15px; line-height: 1.5;">
                Enter the 6-digit OTP sent to<br><strong style="color: #5D0E26;"><?= htmlspecialchars($_SESSION['pending_registration']['email']) ?></strong>
            </div>
            
            <form method="post" style="margin-bottom: 20px;" id="otpForm" onsubmit="return false;">
                <div style="margin-bottom: 25px;">
                    <input type="text" name="otp" maxlength="6" pattern="\d{6}" placeholder="Enter 6-digit OTP" required autofocus 
                           style="width: 100%; padding: 15px; font-size: 18px; border: 2px solid #e0e0e0; border-radius: 8px; outline: none; transition: all 0.3s ease; text-align: center; letter-spacing: 3px; font-weight: 600; background: #f9f9f9;"
                           onfocus="this.style.borderColor='#5D0E26'; this.style.background='#fff';"
                           onblur="this.style.borderColor='#e0e0e0'; this.style.background='#f9f9f9';">
                </div>
                <button type="button" onclick="verifyOTP()" 
                        style="width: 100%; background: linear-gradient(135deg, #5D0E26, #8B1538); color: #fff; border: none; padding: 16px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(93, 14, 38, 0.4);"
                        onmouseover="this.style.background='linear-gradient(135deg, #8B1538, #5D0E26)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(93, 14, 38, 0.5)';"
                        onmouseout="this.style.background='linear-gradient(135deg, #5D0E26, #8B1538)'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(93, 14, 38, 0.4)';">
                    Verify OTP
                </button>
            </form>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div style="color: #e74c3c; margin-bottom: 15px; text-align: center; font-size: 13px; background: #ffe6e6; padding: 10px; border-radius: 6px; border-left: 4px solid #e74c3c;">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 20px;">
                <button onclick="closeOtpModal()" style="background: none; border: none; color: #5D0E26; text-decoration: none; font-size: 14px; font-weight: 500; cursor: pointer; padding: 8px 15px; border-radius: 6px; transition: all 0.3s ease; margin-right: 15px;" onmouseover="this.style.background='#f0f0f0'; this.style.color='#8B1538';" onmouseout="this.style.background='transparent'; this.style.color='#5D0E26';">
                    ← Cancel & Start Over
                </button>
                <button onclick="resendOTP()" style="background: none; border: none; color: #5D0E26; text-decoration: none; font-size: 14px; font-weight: 500; cursor: pointer; padding: 8px 15px; border-radius: 6px; transition: all 0.3s ease; margin-right: 15px;" onmouseover="this.style.background='#f0f0f0'; this.style.color='#8B1538';" onmouseout="this.style.background='transparent'; this.style.color='#5D0E26';">
                    ↻ Resend OTP
                </button>
            </div>
        </div>
    </div>

    <script>
        
        function closeOtpModal() {
            // Close the modal immediately
            const modal = document.getElementById('otpModal');
            if (modal) {
                modal.style.display = 'none';
            }
            
            // Clear the OTP session data via AJAX
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: 'cancel_otp=1'
            }).then(function(res) {
                return res.text();
            }).then(function(txt) {
                // Clear form fields for fresh registration
                clearRegistrationForm();
                
                // Show a message that they can register again
                if (typeof showMessage === 'function') {
                    showMessage('OTP verification canceled. You can now register again.', 'info');
                } else {
                    // Fallback if showMessage function doesn't exist
                    alert('OTP verification canceled. You can now register again.');
                }
            }).catch(function(error) {
                console.error('Error:', error);
            });
        }

        function clearRegistrationForm() {
            // Clear all form inputs
            const form = document.querySelector('form');
            if (form) {
                const inputs = form.querySelectorAll('input[type="text"], input[type="email"], input[type="password"]');
                inputs.forEach(input => {
                    input.value = '';
                });
                
                // Focus on the first input field
                const firstInput = form.querySelector('input[type="text"]');
                if (firstInput) {
                    firstInput.focus();
                }
            }
        }

        // Function to resend OTP (consolidated version)
        // Global flags to prevent race conditions between resend and verify
        window.__resending = window.__resending || false;
        window.__verifying = window.__verifying || false;

        function resendOTP() {
            if (window.__verifying) {
                showMessage('Verification in progress. Please wait for it to finish or try again in a moment.', 'info');
                return;
            }
            if (window.__resending) return;
            const resendBtn = document.querySelector('button[onclick="resendOTP()"]');
            const originalText = resendBtn.textContent;
            resendBtn.textContent = 'Sending...';
            resendBtn.disabled = true;
            window.__resending = true;
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: 'resend_otp=1'
            }).then(function(res) {
                return res.text();
            }).then(function(response) {
                if (response === 'OK') {
                    showMessage('New OTP sent successfully!', 'success');
                    // Clear the OTP input
                    const otpInput = document.querySelector('input[name="otp"]');
                    if (otpInput) {
                        otpInput.value = '';
                        otpInput.focus();
                    }
                    // Reset and restart 15-minute countdown
                    try {
                        const timerSpan = document.getElementById('otpTimer');
                        if (timerSpan && timerSpan.parentElement) {
                            timerSpan.parentElement.remove();
                        }
                    } catch (e) {}
                    if (window.__otpTimerInterval) {
                        clearInterval(window.__otpTimerInterval);
                        window.__otpTimerInterval = null;
                    }
                    startOTPCountdown();
                } else {
                    showMessage('Failed to send new OTP. Please try again.', 'error');
                }
            }).catch(function(error) {
                console.error('Error:', error);
                showMessage('Failed to send new OTP. Please try again.', 'error');
            }).finally(function() {
                // Reset button state
                resendBtn.textContent = originalText;
                resendBtn.disabled = false;
                window.__resending = false;
            });
        }
        

        // Auto-focus on OTP input when modal appears
        document.addEventListener('DOMContentLoaded', function() {
            const otpInput = document.querySelector('input[name="otp"]');
            if (otpInput) {
                otpInput.focus();
                // Clear any previous OTP input
                otpInput.value = '';
            }
            
            // Start OTP expiration countdown
            startOTPCountdown();
        });
        
        function startOTPCountdown() {
            // OTP expires in 15 minutes (900 seconds)
            let timeLeft = 900;
            const countdownElement = document.createElement('div');
            countdownElement.style.cssText = 'text-align: center; color: #e74c3c; font-size: 12px; margin-top: 10px; font-weight: 500;';
            countdownElement.innerHTML = `⏰ OTP expires in <span id="otpTimer">15:00</span>`;
            
            // Insert countdown after the OTP input
            const otpInput = document.querySelector('input[name="otp"]');
            if (otpInput && otpInput.parentNode) {
                otpInput.parentNode.appendChild(countdownElement);
            }
            
            // Keep reference globally to clear when resending
            if (window.__otpTimerInterval) {
                clearInterval(window.__otpTimerInterval);
            }
            const timer = setInterval(() => {
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                const timerSpan = document.getElementById('otpTimer');
                if (timerSpan) {
                    timerSpan.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                }
                
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    window.__otpTimerInterval = null;
                    showMessage('OTP has expired. Click Resend OTP to get a new code.', 'error');
                    // Keep the modal open so the user can press Resend OTP
                }
            }, 1000);
            window.__otpTimerInterval = timer;
        }
        
        function verifyOTP() {
            if (window.__resending) {
                showMessage('Please wait, a new OTP is being sent. Try again in a moment.', 'info');
                return;
            }
            
            const otpInput = document.querySelector('input[name="otp"]');
            const otp = otpInput.value.trim();
            
            if (!otp || otp.length !== 6 || !/^\d{6}$/.test(otp)) {
                showMessage('Please enter a valid 6-digit OTP', 'error');
                otpInput.focus();
                return;
            }
            
            // Show loading state
            const verifyBtn = document.querySelector('button[onclick="verifyOTP()"]');
            const originalText = verifyBtn.textContent;
            verifyBtn.textContent = 'Verifying...';
            verifyBtn.disabled = true;
            verifyBtn.style.opacity = '0.7';
            window.__verifying = true;
            
            // Clear any previous error messages
            const existingError = document.querySelector('.message-popup.error');
            if (existingError) {
                existingError.remove();
            }
            
            // Submit OTP for verification
            const requestBody = 'verify_otp=1&otp=' + encodeURIComponent(otp);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: requestBody
            }).then(function(res) {
                return res.text();
            }).then(function(response) {
                try {
                    // Try to parse as JSON
                    const data = JSON.parse(response);
                    
                    if (data.status === 'success') {
                        // Success - show success message and redirect
                        showMessage(data.message, 'success');
                        
                        // Close the modal
                        const modal = document.getElementById('otpModal');
                        if (modal) {
                            modal.style.display = 'none';
                        }
                        
                        // Redirect after showing success message
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 2000);
                    } else if (data.status === 'error') {
                        // Error - show error message
                        showMessage(data.message, 'error');
                        
                        if (data.message.includes('Invalid OTP')) {
                            otpInput.value = '';
                            otpInput.focus();
                        }
                    } else {
                        // Unknown status
                        showMessage('Verification failed. Please try again.', 'error');
                        otpInput.focus();
                    }
                } catch (e) {
                    // Not JSON - fallback to old text-based parsing
                    if (response.includes('login_form.php')) {
                        // Success - redirect to login
                        showMessage('Registration successful! Redirecting to login...', 'success');
                        setTimeout(() => {
                            window.location.href = 'login_form.php';
                        }, 2000);
                    } else if (response.includes('OTP expired')) {
                        // OTP expired
                        showMessage('OTP has expired. Click Resend OTP to get a new code.', 'error');
                        // Keep modal open so user can press Resend OTP
                    } else if (response.includes('Invalid OTP')) {
                        // Invalid OTP
                        showMessage('Invalid OTP. Please check and try again.', 'error');
                        otpInput.value = '';
                        otpInput.focus();
                    } else {
                        // Unknown response - show generic error
                        showMessage('Verification failed. Please try again.', 'error');
                        otpInput.focus();
                    }
                }
            }).catch(function(error) {
                showMessage('Verification failed. Please try again.', 'error');
            }).finally(function() {
                // Reset button state
                verifyBtn.textContent = originalText;
                verifyBtn.disabled = false;
                verifyBtn.style.opacity = '1';
                window.__verifying = false;
            });
        }

        // Add animation keyframes
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: scale(0.9) translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: scale(1) translateY(0);
                }
            }
        `;
        document.head.appendChild(style);

        // Function to show messages (if not already defined)
        function showMessage(message, type = 'info') {
            // Create message element
            const messageDiv = document.createElement('div');
            messageDiv.className = `message-popup ${type}`;
            
            // Set background color based on message type
            if (type === 'error') {
                messageDiv.style.background = '#ff6b6b';
            } else if (type === 'success') {
                messageDiv.style.background = '#51cf66';
            } else {
                messageDiv.style.background = '#5D0E26';
            }
            
            messageDiv.innerHTML = `
                <p style="margin: 0; font-size: 14px;">${message}</p>
                <button onclick="this.parentElement.remove()">
                    OK
                </button>
            `;
            
            document.body.appendChild(messageDiv);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (messageDiv.parentElement) {
                    messageDiv.remove();
                }
            }, 5000);
        }
        
        

    </script>
    <?php endif; ?>

</body>
</html>
