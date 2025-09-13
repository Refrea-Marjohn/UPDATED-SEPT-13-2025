<?php
/**
 * Security Integration Examples
 * How to use the security monitoring in your existing systems
 */

require_once 'security_monitor.php';

// ========================================
// EXAMPLE 1: Enhanced Login Security
// ========================================

function enhancedLogin($email, $password) {
    // Your existing login logic here
    $user = checkUserCredentials($email, $password);
    
    if ($user) {
        // SUCCESSFUL LOGIN - Monitor for security threats
        $securityResult = monitorLoginSecurity($email, $password, $user['id'], $user['name'], $user['user_type']);
        
        if (!$securityResult['success']) {
            // Security threat detected - handle accordingly
            logSecurityThreat($securityResult['message']);
            return ['success' => false, 'message' => 'Access denied due to security concerns'];
        }
        
        // Log successful login
        logAuditAction($user['id'], $user['name'], $user['user_type'], 'User Login', 'Authentication', 'User logged in successfully');
        
        return ['success' => true, 'user' => $user];
    } else {
        // FAILED LOGIN - Monitor for security threats
        $securityResult = monitorFailedLoginSecurity($email);
        
        if ($securityResult['blocked']) {
            // IP blocked due to too many failed attempts
            return ['success' => false, 'message' => $securityResult['message']];
        }
        
        return ['success' => false, 'message' => 'Invalid credentials'];
    }
}

// ========================================
// EXAMPLE 2: Enhanced File Upload Security
// ========================================

function enhancedFileUpload($file, $userId, $userName, $userType) {
    // Check file security before processing
    $securityResult = monitorFileUploadSecurity(
        $file['name'], 
        $file['type'], 
        $userId, 
        $userName, 
        $userType
    );
    
    if (!$securityResult['secure']) {
        // Security issues detected
        $issues = implode(', ', $securityResult['issues']);
        return [
            'success' => false, 
            'message' => "File upload blocked: $issues",
            'security_issues' => $securityResult['issues']
        ];
    }
    
    // File is secure - proceed with upload
    $uploadResult = processFileUpload($file);
    
    if ($uploadResult['success']) {
        logAuditAction($userId, $userName, $userType, 'Document Upload', 'Document Management', "Uploaded: {$file['name']}");
    }
    
    return $uploadResult;
}

// ========================================
// EXAMPLE 3: Access Control Security
// ========================================

function secureAccessControl($userId, $userName, $userType, $requiredRole, $page) {
    if ($userType !== $requiredRole) {
        // Access violation detected
        monitorAccessViolation($userId, $userName, $userType, $page, $requiredRole);
        
        // Log the violation
        logAuditAction($userId, $userName, $userType, 'Access Violation', 'Security', "Attempted to access $page (Required: $requiredRole)");
        
        return false;
    }
    
    return true;
}

// ========================================
// EXAMPLE 4: Suspicious Activity Monitoring
// ========================================

function monitorUserActivity($userId, $userName, $userType, $action, $details) {
    // Monitor for suspicious patterns
    monitorSuspiciousActivity($userId, $userName, $userType, $action, $details);
    
    // Log the activity
    logAuditAction($userId, $userName, $userType, $action, 'User Activity', $details);
}

// ========================================
// EXAMPLE 5: Integration in Your Login Form
// ========================================

/*
// In your login_form.php or similar file:

require_once 'security_monitor.php';

if ($_POST['login']) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $loginResult = enhancedLogin($email, $password);
    
    if ($loginResult['success']) {
        // Login successful
        $_SESSION['user_id'] = $loginResult['user']['id'];
        $_SESSION['user_name'] = $loginResult['user']['name'];
        $_SESSION['user_type'] = $loginResult['user']['user_type'];
        
        header('Location: dashboard.php');
        exit;
    } else {
        // Login failed or blocked
        $error_message = $loginResult['message'];
    }
}
*/

// ========================================
// EXAMPLE 6: Integration in File Upload
// ========================================

/*
// In your file upload handler:

require_once 'security_monitor.php';

if ($_FILES['file']) {
    $uploadResult = enhancedFileUpload(
        $_FILES['file'],
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['user_type']
    );
    
    if ($uploadResult['success']) {
        echo "File uploaded successfully!";
    } else {
        echo "Upload failed: " . $uploadResult['message'];
        
        if (isset($uploadResult['security_issues'])) {
            echo "<br>Security issues: " . implode(', ', $uploadResult['security_issues']);
        }
    }
}
*/

// ========================================
// EXAMPLE 7: Page Access Control
// ========================================

/*
// At the top of each restricted page:

require_once 'security_monitor.php';

// Check if user has access to this page
if (!secureAccessControl(
    $_SESSION['user_id'],
    $_SESSION['user_name'],
    $_SESSION['user_type'],
    'admin', // Required role
    'admin_dashboard' // Page name
)) {
    // Access denied
    header('Location: access_denied.php');
    exit;
}
*/

// ========================================
// HELPER FUNCTIONS (Replace with your existing ones)
// ========================================

function checkUserCredentials($email, $password) {
    // Your existing user authentication logic
    // Return user array or false
    return false; // Placeholder
}

function processFileUpload($file) {
    // Your existing file upload logic
    return ['success' => false]; // Placeholder
}

function logSecurityThreat($message) {
    // Log security threats to your preferred logging system
    error_log("SECURITY THREAT: " . $message);
}

// ========================================
// SECURITY ALERTS AND NOTIFICATIONS
// ========================================

function getSecurityAlerts() {
    $securityStats = getSecurityStatistics();
    $alerts = [];
    
    if ($securityStats['critical_events_today'] > 0) {
        $alerts[] = [
            'type' => 'critical',
            'message' => "{$securityStats['critical_events_today']} critical security events today",
            'icon' => 'fas fa-exclamation-triangle'
        ];
    }
    
    if ($securityStats['blocked_attempts_today'] > 0) {
        $alerts[] = [
            'type' => 'warning',
            'message' => "{$securityStats['blocked_attempts_today']} access attempts blocked today",
            'icon' => 'fas fa-ban'
        ];
    }
    
    if ($securityStats['failed_logins_today'] > 10) {
        $alerts[] = [
            'type' => 'info',
            'message' => "{$securityStats['failed_logins_today']} failed login attempts today",
            'icon' => 'fas fa-user-lock'
        ];
    }
    
    return $alerts;
}

// ========================================
// USAGE SUMMARY
// ========================================

/*
TO INTEGRATE SECURITY MONITORING:

1. Include security_monitor.php in your files
2. Replace regular login with enhancedLogin()
3. Replace file uploads with enhancedFileUpload()
4. Add secureAccessControl() to restricted pages
5. Use monitorUserActivity() for suspicious activity
6. Check getSecurityAlerts() for real-time alerts

BENEFITS:
- Automatic security threat detection
- Real-time monitoring and logging
- IP blocking for repeated violations
- File upload security scanning
- Access violation tracking
- Comprehensive audit trail
- Security statistics and alerts
*/
?>
