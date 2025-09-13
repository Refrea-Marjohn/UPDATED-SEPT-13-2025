<?php
// Get user profile image and email
$user_id = $_SESSION['user_id'];
$res = $conn->query("SELECT profile_image, email, phone_number FROM user_form WHERE id=$user_id");
$profile_image = '';
$user_email = '';
$user_phone = '';
if ($res && $row = $res->fetch_assoc()) {
    $profile_image = $row['profile_image'];
    $user_email = $row['email'];
    $user_phone = $row['phone_number'];
}
if (!$profile_image || !file_exists($profile_image)) {
    $profile_image = 'images/default-avatar.jpg';
}

// Get user display name
$user_name = '';
switch ($_SESSION['user_type']) {
    case 'admin':
        $user_name = $_SESSION['admin_name'] ?? 'Administrator';
        break;
    case 'attorney':
        $user_name = $_SESSION['attorney_name'] ?? 'Attorney';
        break;
    case 'employee':
        $user_name = $_SESSION['employee_name'] ?? 'Employee';
        break;
    case 'client':
        $user_name = $_SESSION['client_name'] ?? 'Client';
        break;
}

$user_title = ucfirst($_SESSION['user_type']);
?>

<!-- Enhanced Profile Header with Notifications -->
<div class="header">
    <div class="header-title">
        <h1><?= $page_title ?? 'Dashboard' ?></h1>
        <p><?= $page_subtitle ?? 'Overview of your activities' ?></p>
    </div>
    <div class="user-info" style="display: flex; align-items: center; gap: 20px;">
        <!-- Notifications Bell -->
        <div class="notifications-container" style="position: relative;">
            <button id="notificationsBtn" style="background: none; border: none; font-size: 20px; color: var(--primary-color); cursor: pointer; padding: 8px; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-color)'" onmouseout="this.style.color='var(--primary-color)'">
                <i class="fas fa-bell"></i>
                <span id="notificationBadge" style="position: absolute; top: 0; right: 0; background: var(--primary-color); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; display: none;">0</span>
            </button>
            
            <!-- Notifications Dropdown -->
            <div id="notificationsDropdown" style="position: absolute; top: 100%; right: 0; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); width: 350px; max-height: 400px; overflow-y: auto; z-index: 3000; display: none;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb;">
                    <h3 style="margin: 0; font-size: 16px; color: #374151;">Notifications</h3>
                </div>
                <div id="notificationsList" style="padding: 8px;">
                    <!-- Notifications will be loaded here -->
                </div>
                <div style="padding: 12px; border-top: 1px solid #e5e7eb; text-align: center;">
                    <button onclick="markAllAsRead()" style="background: #1976d2; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 12px;">Mark All as Read</button>
                </div>
            </div>
        </div>
        
        <!-- Profile Image with Dropdown -->
        <div class="profile-dropdown" style="display: flex; align-items: center; gap: 12px;">
            <img src="<?= htmlspecialchars($profile_image) ?>" alt="<?= $user_title ?>" style="object-fit: cover; width: 42px; height: 42px; border-radius: 50%; border: 2px solid var(--primary-color); cursor: pointer;" onclick="toggleProfileDropdown()">
            
            <div class="user-details">
                <h3 style="margin: 0; font-size: 16px; color: var(--primary-color);"><?= htmlspecialchars($user_name) ?></h3>
                <p style="margin: 0; font-size: 14px; color: var(--accent-color);"><?= $user_title ?></p>
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
        
        <!-- Edit Profile Modal -->
        <div id="editProfileModal" class="modal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px); align-items: center; justify-content: center;">
            <div class="modal-content" style="background-color: white; padding: 0; border-radius: 12px; width: 90%; max-width: 450px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); animation: modalSlideIn 0.3s ease-out;">
                <div class="modal-header" style="background: var(--primary-color); color: white; padding: 12px 16px; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="margin: 0; font-size: 1.2rem; font-weight: 600;">Edit Profile</h2>
                    <span class="close" onclick="closeEditProfileModal()" style="color: white; font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1; transition: opacity 0.2s ease;">&times;</span>
                </div>
                <div class="modal-body" style="padding: 14px;">
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
                        </form>
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

<!-- Move modal and notifications outside header -->
<script>
// Move modal and notifications to body level for proper layering
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('editProfileModal');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    
    if (modal && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    if (notificationsDropdown && notificationsDropdown.parentElement !== document.body) {
        document.body.appendChild(notificationsDropdown);
        
        // Update notifications dropdown positioning to work with body
        const notificationsBtn = document.getElementById('notificationsBtn');
        if (notificationsBtn) {
            notificationsBtn.addEventListener('click', function() {
                const dropdown = document.getElementById('notificationsDropdown');
                const btnRect = notificationsBtn.getBoundingClientRect();
                
                // Position dropdown relative to button
                dropdown.style.position = 'fixed';
                dropdown.style.top = (btnRect.bottom + 5) + 'px';
                dropdown.style.right = (window.innerWidth - btnRect.right) + 'px';
                dropdown.style.zIndex = '9999';
                
                // Toggle visibility
                const isVisible = dropdown.style.display === 'block';
                dropdown.style.display = isVisible ? 'none' : 'block';
                
                if (!isVisible) {
                    loadNotifications();
                }
            });
        }
    }
});
</script>

<script>
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
        }
        
        function editProfile() {
            document.getElementById('editProfileModal').style.display = 'block';
            // Close dropdown when opening modal
            document.getElementById('profileDropdown').classList.remove('show');
        }

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
    </div>
</div>

<script>
// Notifications functionality
let notificationsVisible = false;

// Close notifications when clicking outside
document.addEventListener('click', function(event) {
    const notificationsBtn = document.getElementById('notificationsBtn');
    const dropdown = document.getElementById('notificationsDropdown');
    
    if (notificationsBtn && dropdown && !notificationsBtn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
        notificationsVisible = false;
    }
});

function loadNotifications() {
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            updateNotificationBadge(data.unread_count);
            displayNotifications(data.notifications);
        })
        .catch(error => console.error('Error loading notifications:', error));
}

function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

function displayNotifications(notifications) {
    const container = document.getElementById('notificationsList');
    
    if (notifications.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No notifications</div>';
        return;
    }
    
    container.innerHTML = notifications.map(notification => `
        <div style="padding: 12px; border-bottom: 1px solid #f3f4f6; ${!notification.is_read ? 'background: #f0f8ff;' : ''}">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <div style="font-weight: 600; font-size: 14px; color: #1a202c; margin-bottom: 4px;">${notification.title}</div>
                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">${notification.message}</div>
                    <div style="font-size: 11px; color: #9ca3af;">${formatTime(notification.created_at)}</div>
                </div>
                <div style="width: 8px; height: 8px; border-radius: 50%; background: ${getNotificationColor(notification.type)}; margin-left: 8px; ${notification.is_read ? 'display: none;' : ''}"></div>
            </div>
        </div>
    `).join('');
}

function getNotificationColor(type) {
    switch (type) {
        case 'success': return '#10b981';
        case 'warning': return '#f59e0b';
        case 'error': return '#ef4444';
        default: return '#3b82f6';
    }
}

function formatTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
    
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
    if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
    return date.toLocaleDateString();
}

function markAllAsRead() {
    fetch('get_notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'mark_read=true'
    })
    .then(() => {
        loadNotifications();
    })
    .catch(error => console.error('Error marking notifications as read:', error));
}

// Load notifications on page load
document.addEventListener('DOMContentLoaded', function() {
    loadNotifications();
    // Refresh notifications every 30 seconds
    setInterval(loadNotifications, 30000);
});
</script> 