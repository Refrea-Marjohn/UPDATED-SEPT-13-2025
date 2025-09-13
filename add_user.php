<?php
session_start();
require_once 'config.php';
require_once 'send_password_email.php';
require_once 'audit_logger.php';
require_once 'action_logger_helper.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_name']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login_form.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $confirm_email = trim($_POST['confirm_email']);
    $phone_number = trim($_POST['phone_number']);
    $confirm_phone_number = trim($_POST['confirm_phone_number']);
    $user_type = $_POST['user_type'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Name is required";
    } elseif (strpos($name, ' ') !== false) {
        $errors[] = "Name cannot contain spaces";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    } elseif (strpos($email, ' ') !== false) {
        $errors[] = "Email cannot contain spaces";
    }
    
    if (empty($confirm_email)) {
        $errors[] = "Confirm email is required";
    } elseif (!filter_var($confirm_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid confirm email address";
    } elseif (strpos($confirm_email, ' ') !== false) {
        $errors[] = "Confirm email cannot contain spaces";
    }
    
    if ($email !== $confirm_email) {
        $errors[] = "Email addresses do not match";
    }
    
    if (empty($phone_number)) {
        $errors[] = "Phone number is required";
    } elseif (!preg_match('/^\d{11}$/', $phone_number)) {
        $errors[] = "Phone number must be exactly 11 digits";
    }
    
    if (empty($confirm_phone_number)) {
        $errors[] = "Confirm phone number is required";
    } elseif (!preg_match('/^\d{11}$/', $confirm_phone_number)) {
        $errors[] = "Confirm phone number must be exactly 11 digits";
    }
    
    if ($phone_number !== $confirm_phone_number) {
        $errors[] = "Phone numbers do not match";
    }
    
    if (empty($user_type)) {
        $errors[] = "User type is required";
    }
    
    // Prevent creating admin accounts through this form
    if ($user_type === 'admin') {
        $errors[] = "Admin accounts cannot be created through this interface";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strpos($password, ' ') !== false) {
        $errors[] = "Password cannot contain spaces";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()\-_+={}[\]:";\'<>.,?\/\\|~])[A-Za-z\d!@#$%^&*()\-_+={}[\]:";\'<>.,?\/\\|~]{8,}$/', $password)) {
        $errors[] = "Password must be at least 8 characters, include uppercase and lowercase letters, at least one number, and at least one special character (!@#$%^&*()...etc)";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (strpos($confirm_password, ' ') !== false) {
        $errors[] = "Confirm password cannot contain spaces";
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM user_form WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $errors[] = "Email already exists";
    }
    
    // If no errors, proceed with user creation
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO user_form (name, email, phone_number, password, user_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $email, $phone_number, $hashed_password, $user_type);
        
        if ($stmt->execute()) {
            // Send password email for employees and attorneys
            if ($user_type === 'employee' || $user_type === 'attorney') {
                $email_sent = send_password_email($email, $name, $password, $user_type);
                
                if ($email_sent) {
                    $_SESSION['success_message'] = "User '$name' has been successfully created as $user_type! Password email has been sent to $email.";
                } else {
                    // Log email sending failure for debugging
                    error_log("Failed to send password email to: $email for user: $name ($user_type)");
                    $_SESSION['success_message'] = "User '$name' has been successfully created as $user_type! However, there was an issue sending the password email to $email. Please contact the user directly with their credentials.";
                }
            } else {
                $_SESSION['success_message'] = "User '$name' has been successfully created as $user_type!";
            }
            
            // Log the activity
            $admin_id = $_SESSION['user_id'];
            $admin_name = $_SESSION['admin_name'];
            $action = "Created new $user_type account: $email";
            
            // Log to audit trail
            global $auditLogger;
            $auditLogger->logAction(
                $admin_id,
                $admin_name,
                'admin',
                'User Create',
                'User Management',
                "Created new $user_type account: $name ($email)",
                'success',
                'medium'
            );
            
            header('Location: admin_usermanagement.php');
            exit();
        } else {
            $errors[] = "Database error: " . $stmt->error;
        }
    }
    
    // If there are errors, store them in session and redirect back
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode(", ", $errors);
        header('Location: admin_usermanagement.php');
        exit();
    }
} else {
    // If not POST request, redirect to user management
    header('Location: admin_usermanagement.php');
    exit();
}
?> 