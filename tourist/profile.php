<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/tourist/profile.php');
    }

    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $age = (int)($_POST['age'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $phone_prefix = trim($_POST['phone_prefix'] ?? '+63');
        if ($phone !== '' && strpos($phone, '+') !== 0 && strpos($phone, '0') !== 0) {
            $phone = $phone_prefix . ltrim($phone, '0');
        }
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
        $emergency_prefix = trim($_POST['emergency_phone_prefix'] ?? '+63');
        if ($emergency_contact_number !== '' && strpos($emergency_contact_number, '+') !== 0 && strpos($emergency_contact_number, '0') !== 0) {
            $emergency_contact_number = $emergency_prefix . ltrim($emergency_contact_number, '0');
        }
        $disability = $_POST['disability'] ?? 'none';
        $disability_details = trim($_POST['disability_details'] ?? '');

        $errors = [];
        if (empty($name)) $errors[] = 'Full name is required.';
        if (empty($phone)) $errors[] = 'Contact number is required.';
        if (empty($emergency_contact)) $errors[] = 'Emergency contact is required.';
        if (empty($emergency_contact_number)) $errors[] = 'Emergency contact number is required.';
        if (!in_array($gender, ['male', 'female'])) $errors[] = 'Please select a valid gender.';
        if ($age < 1 || $age > 120) $errors[] = 'Please enter a valid age.';

        if (!empty($errors)) {
            flash_message('error', implode(' ', $errors));
            redirect('/tourist/profile.php');
        }

        $db->prepare("UPDATE users SET name = :name, gender = :gender, age = :age, phone = :phone, updated_at = db_now() WHERE id = :uid")
            ->execute([':name' => $name, ':gender' => $gender, ':age' => $age, ':phone' => $phone, ':uid' => $user_id]);

        $tp_check = $db->prepare("SELECT id FROM tourist_profiles WHERE user_id = :uid");
        $tp_check->execute([':uid' => $user_id]);
        if ($tp_check->fetch()) {
            $db->prepare("UPDATE tourist_profiles SET emergency_contact = :ec, emergency_contact_number = :ecn, disability = :d, disability_details = :dd WHERE user_id = :uid")
                ->execute([':ec' => $emergency_contact, ':ecn' => $emergency_contact_number, ':d' => $disability, ':dd' => $disability_details, ':uid' => $user_id]);
        } else {
            $db->prepare("INSERT INTO tourist_profiles (user_id, emergency_contact, emergency_contact_number, disability, disability_details) VALUES (:uid, :ec, :ecn, :d, :dd)")
                ->execute([':uid' => $user_id, ':ec' => $emergency_contact, ':ecn' => $emergency_contact_number, ':d' => $disability, ':dd' => $disability_details]);
        }

        ActivityLog::log($user_id, 'profile_updated', 'Tourist profile updated');
        flash_message('success', 'Profile updated successfully!');
        redirect('/tourist/profile.php');
    }

    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($new_password) < 8) {
            flash_message('error', 'New password must be at least 8 characters.');
            redirect('/tourist/profile.php?tab=security');
        }

        if ($new_password !== $confirm_password) {
            flash_message('error', 'New passwords do not match.');
            redirect('/tourist/profile.php?tab=security');
        }

        $pw_stmt = $db->prepare("SELECT password FROM users WHERE id = :uid");
        $pw_stmt->execute([':uid' => $user_id]);
        $pw_data = $pw_stmt->fetch();

        if (!password_verify($current_password, $pw_data['password'])) {
            flash_message('error', 'Current password is incorrect.');
            redirect('/tourist/profile.php?tab=security');
        }

        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = :pw, updated_at = db_now() WHERE id = :uid")
            ->execute([':pw' => $hashed, ':uid' => $user_id]);

        ActivityLog::log($user_id, 'password_changed', 'Password changed');
        flash_message('success', 'Password changed successfully!');
        redirect('/tourist/profile.php?tab=security');
    }

    if (isset($_POST['change_avatar'])) {
        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload = upload_file($_FILES['avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($upload['success']) {
                $db->prepare("UPDATE users SET avatar = :avatar WHERE id = :uid")
                    ->execute([':avatar' => 'avatars/' . $upload['filename'], ':uid' => $user_id]);
                ActivityLog::log($user_id, 'avatar_updated', 'Profile picture changed');
                flash_message('success', 'Profile picture updated!');
            } else {
                flash_message('error', 'Upload failed: ' . $upload['message']);
            }
        } else {
            flash_message('error', 'Please select an image to upload.');
        }
        redirect('/tourist/profile.php');
    }

    if (isset($_POST['toggle_status'])) {
        $new_status = $user['status'] === 'active' ? 'inactive' : 'active';
        if ($new_status === 'inactive') {
            if (!isset($_POST['confirm_deactivate']) || $_POST['confirm_deactivate'] !== '1') {
                flash_message('error', 'Please confirm deactivation.');
                redirect('/tourist/profile.php?tab=security');
            }
        }
        $db->prepare("UPDATE users SET status = :status WHERE id = :uid")
            ->execute([':status' => $new_status, ':uid' => $user_id]);
        ActivityLog::log($user_id, 'status_changed', "Account status changed to {$new_status}");
        flash_message('success', 'Account ' . ($new_status === 'active' ? 'activated' : 'deactivated') . ' successfully.');
        redirect('/tourist/profile.php?tab=security');
    }

    if (isset($_POST['update_preferences'])) {
        $theme = ($_POST['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $db->prepare("UPDATE users SET theme = :theme WHERE id = :uid")
            ->execute([':theme' => $theme, ':uid' => $user_id]);
        ActivityLog::log($user_id, 'preferences_updated', "Theme preference set to {$theme}");
        flash_message('success', 'Preferences updated successfully!');
        redirect('/tourist/profile.php?tab=preferences');
    }

    if (isset($_POST['submit_verification'])) {
        $id_type = $_POST['id_type'] ?? '';
        $valid_types = ['passport','drivers_license','national_id','voters_id','senior_citizen','other'];
        if (!in_array($id_type, $valid_types)) {
            flash_message('error', 'Invalid ID type.');
            redirect('/tourist/profile.php');
        }

        if (!isset($_FILES['id_file']) || $_FILES['id_file']['error'] !== UPLOAD_ERR_OK) {
            flash_message('error', 'Please upload a valid ID file.');
            redirect('/tourist/profile.php');
        }

        $upload = upload_file($_FILES['id_file'], 'verifications', ['jpg','jpeg','png','pdf']);
        if (!$upload['success']) {
            flash_message('error', 'Upload failed: ' . $upload['message']);
            redirect('/tourist/profile.php');
        }

        $db->prepare("INSERT INTO id_verifications (user_id, id_type, id_file_path, status, created_at) VALUES (:uid, :type, :path, 'pending', db_now())")
            ->execute([':uid' => $user_id, ':type' => $id_type, ':path' => $upload['path']]);

        ActivityLog::log($user_id, 'id_verification_submitted', "ID verification submitted: {$id_type}");
        flash_message('success', 'ID verification submitted successfully! Awaiting review.');
        redirect('/tourist/profile.php');
    }
}

$csrf = generate_token();
$active_tab = $_GET['tab'] ?? 'personal';
if (!in_array($active_tab, ['personal', 'security', 'preferences'])) $active_tab = 'personal';

$tp_stmt = $db->prepare("SELECT * FROM tourist_profiles WHERE user_id = :uid");
$tp_stmt->execute([':uid' => $user_id]);
$profile = $tp_stmt->fetch();

$verifications_stmt = $db->prepare("SELECT * FROM id_verifications WHERE user_id = :uid ORDER BY created_at DESC");
$verifications_stmt->execute([':uid' => $user_id]);
$verifications = $verifications_stmt->fetchAll();

$current_verification = null;
foreach ($verifications as $v) {
    if ($v['status'] === 'pending' || $v['status'] === 'approved') {
        $current_verification = $v;
        break;
    }
}

$id_type_labels = [
    'passport' => 'Passport',
    'drivers_license' => "Driver's License",
    'national_id' => 'National ID',
    'voters_id' => "Voter's ID",
    'senior_citizen' => 'Senior Citizen ID',
    'other' => 'Other',
];

// Parse phone into prefix + number
$parsed_phone = ['prefix' => '+63', 'number' => ''];
if (!empty($user['phone'])) {
    $ph = $user['phone'];
    if (strpos($ph, '+') === 0) {
        if (strpos($ph, '+63') === 0) { $parsed_phone['prefix'] = '+63'; $parsed_phone['number'] = substr($ph, 3); }
        else { $parsed_phone['prefix'] = substr($ph, 0, 4); $parsed_phone['number'] = substr($ph, 4); }
    } elseif (strpos($ph, '0') === 0) {
        $parsed_phone['number'] = substr($ph, 1);
    } else {
        $parsed_phone['number'] = $ph;
    }
}
$parsed_emergency = ['prefix' => '+63', 'number' => ''];
if (!empty($profile['emergency_contact_number'])) {
    $pe = $profile['emergency_contact_number'];
    if (strpos($pe, '+') === 0) {
        if (strpos($pe, '+63') === 0) { $parsed_emergency['prefix'] = '+63'; $parsed_emergency['number'] = substr($pe, 3); }
        else { $parsed_emergency['prefix'] = substr($pe, 0, 4); $parsed_emergency['number'] = substr($pe, 4); }
    } elseif (strpos($pe, '0') === 0) {
        $parsed_emergency['number'] = substr($pe, 1);
    } else {
        $parsed_emergency['number'] = $pe;
    }
}

$country_codes = ['+63' => '???? +63', '+1' => '???? +1', '+44' => '???? +44', '+61' => '???? +61', '+65' => '???? +65', '+81' => '???? +81', '+852' => '???? +852'];

render_page('tourist', 'profile.php', 'My Profile', function() use ($user, $profile, $verifications, $current_verification, $id_type_labels, $csrf, $active_tab, $parsed_phone, $parsed_emergency, $country_codes) {
?>
<style>
/* Banner */
.profile-hero{background:linear-gradient(135deg,#0c6e5e 0%,#0a5c4f 55%,#0e7490 100%);border-radius:20px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:0 16px 48px rgba(12,110,94,0.25);}
.profile-hero::before{content:'';position:absolute;top:-50px;right:-30px;width:200px;height:200px;background:rgba(255,255,255,0.07);border-radius:50%;}
.profile-hero::after{content:'';position:absolute;bottom:-40px;left:40px;width:140px;height:140px;background:rgba(255,255,255,0.04);border-radius:50%;}

/* Tabs */
.profile-tabs{display:flex;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:6px;margin-bottom:24px;overflow-x:auto;}
.profile-tab{flex:1;min-width:max-content;display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:10px;border:none;background:transparent;color:var(--text-muted,#64748b);font-weight:600;font-size:0.85rem;cursor:pointer;transition:all 0.25s;white-space:nowrap;}
.profile-tab:hover{background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#1e293b);}
.profile-tab.active{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;box-shadow:0 4px 14px rgba(12,110,94,0.3);}

/* Panels */
.profile-panel{animation:profFade 0.35s ease;}
@keyframes profFade{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}

/* Section cards */
.profile-section{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,0.02);}
.profile-section .section-header{padding:18px 22px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:12px;}
.profile-section .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);}
.profile-section .section-body{padding:22px;}

/* Inputs */
.profile-field{margin-bottom:18px;}
.profile-field .form-label{font-size:0.78rem;font-weight:600;color:var(--text-primary,#1e293b);margin-bottom:7px;display:flex;align-items:center;gap:6px;}
.profile-field .form-label i{color:var(--primary,#0c6e5e);font-size:0.8rem;width:16px;text-align:center;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-prefix{display:flex;align-items:center;gap:6px;border:1px solid var(--border-color,#e2e8f0);border-right:none;border-radius:10px 0 0 10px;padding:0 12px;background:var(--bg-secondary,#f8fafc);font-size:0.82rem;font-weight:600;color:var(--text-primary,#1e293b);cursor:pointer;transition:all 0.2s;height:44px;}
.input-prefix select{border:none;background:transparent;font-size:0.82rem;font-weight:600;color:var(--text-primary,#1e293b);outline:none;cursor:pointer;max-width:72px;}
.input-prefix select option{background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.input-prefix.has-select{pointer-events:auto;}
.profile-input{flex:1;border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:11px 14px;font-size:0.9rem;transition:all 0.2s;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);width:100%;}
.profile-input.prefixed{border-radius:0 10px 10px 0;border-left:none;}
.profile-input:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.profile-input:focus.prefixed{border-left:none;}
.profile-input:disabled{opacity:0.55;background:var(--bg-secondary,#f8fafc);cursor:not-allowed;}
.profile-input.has-error{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,0.08);}
.field-error{font-size:0.74rem;color:#ef4444;margin-top:5px;display:none;align-items:center;gap:4px;}
.field-error.visible{display:flex;}
.field-hint{font-size:0.74rem;color:var(--text-muted,#94a3b8);margin-top:5px;}
select.profile-input{-webkit-appearance:none;-moz-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:36px;}

/* Avatar */
.profile-sidebar-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,0.02);}
.profile-avatar-wrap{width:120px;height:120px;border-radius:50%;overflow:hidden;border:4px solid var(--border-color,#e2e8f0);margin:0 auto;cursor:pointer;position:relative;transition:all 0.3s;}
.profile-avatar-wrap:hover{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 6px rgba(12,110,94,0.08);}
.profile-avatar-wrap img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.profile-avatar-wrap .avatar-hover{position:absolute;inset:0;background:rgba(12,110,94,0.55);border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#fff;opacity:0;transition:opacity 0.3s;font-size:0.7rem;font-weight:600;}
.profile-avatar-wrap:hover .avatar-hover{opacity:1;}
.profile-avatar-btn{width:36px;height:36px;border-radius:50%;background:var(--primary,#0c6e5e);color:#fff;display:flex;align-items:center;justify-content:center;position:absolute;bottom:2px;right:2px;cursor:pointer;border:3px solid var(--card-bg,#fff);box-shadow:0 2px 6px rgba(0,0,0,0.3);z-index:2;transition:transform 0.2s;}
.profile-avatar-btn:hover{transform:scale(1.1);}

/* Status pill */
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:50px;font-size:0.78rem;font-weight:600;border:none;transition:all 0.2s;}
.status-pill.active{background:#d1fae5;color:#065f46;}
.status-pill.inactive{background:#e2e8f0;color:#475569;}

/* ID verification */
.vs-banner{border-radius:14px;padding:18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;}
.vs-banner .vs-icon{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;}
.drop-zone{border:2px dashed var(--border-color,#cbd5e1);border-radius:14px;padding:24px 16px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--bg-secondary,#f8fafc);}
.drop-zone:hover,.drop-zone.dragover{border-color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.04);}
.drop-zone .dz-icon{width:52px;height:52px;border-radius:14px;background:rgba(12,110,94,0.08);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;}
.file-chip{display:flex;align-items:center;gap:12px;background:var(--bg-secondary,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:12px 14px;text-align:left;}
.file-chip .fc-icon{width:40px;height:40px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.file-chip .fc-name{font-size:0.82rem;font-weight:600;color:var(--text-primary,#1e293b);word-break:break-all;}
.file-chip .fc-meta{font-size:0.72rem;color:var(--text-muted,#94a3b8);}
.file-chip .fc-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border-color,#e2e8f0);}
.guidelines-box{background:var(--bg-secondary,#f1f5f9);border-radius:12px;padding:14px 16px;margin-bottom:18px;}

/* Sticky action bar */
.save-bar{position:sticky;bottom:16px;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;padding:14px 20px;box-shadow:0 12px 40px rgba(0,0,0,0.14);opacity:0;transform:translateY(20px);pointer-events:none;transition:all 0.35s cubic-bezier(.4,0,.2,1);}
.save-bar.visible{opacity:1;transform:translateY(0);pointer-events:auto;}
.save-bar .sb-note{font-size:0.85rem;color:var(--text-muted,#64748b);display:flex;align-items:center;gap:8px;}
.save-bar .sb-note i{color:var(--primary,#0c6e5e);}
.btn-primary-gradient{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 26px;font-weight:600;border:none;transition:all 0.25s;display:inline-flex;align-items:center;gap:8px;}
.btn-primary-gradient:hover{box-shadow:0 6px 20px rgba(12,110,94,0.35);transform:translateY(-1px);color:#fff;}
.btn-outline-soft{border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);border-radius:10px;padding:10px 22px;font-weight:600;transition:all 0.25s;}
.btn-outline-soft:hover{background:var(--bg-secondary,#f8fafc);border-color:#cbd5e1;}

/* Password */
.password-wrap{position:relative;}
.password-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted,#94a3b8);cursor:pointer;font-size:0.9rem;padding:4px;}
.password-toggle:hover{color:var(--primary,#0c6e5e);}
.password-strength{height:6px;border-radius:6px;background:var(--bg-secondary,#e2e8f0);margin-top:8px;overflow:hidden;}
.password-strength .ps-fill{height:100%;border-radius:6px;width:0;transition:all 0.4s;}
.ps-labels{font-size:0.72rem;color:var(--text-muted,#94a3b8);margin-top:5px;}

/* Danger zone */
.danger-zone{border:1.5px solid #fecaca;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);}
.danger-zone .dz-header{padding:18px 22px;background:rgba(239,68,68,0.05);border-bottom:1px solid #fecaca;display:flex;align-items:center;gap:12px;}
.danger-zone .dz-header h6{margin:0;font-weight:700;color:#b91c1c;}
.danger-zone .dz-body{padding:22px;}
.danger-btn{border:1.5px solid #fecaca;background:rgba(239,68,68,0.06);color:#dc2626;border-radius:10px;padding:11px 22px;font-weight:600;transition:all 0.25s;display:inline-flex;align-items:center;gap:8px;}
.danger-btn:hover{background:#dc2626;color:#fff;border-color:#dc2626;}

/* Preference rows */
.pref-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid var(--border-color,#f1f5f9);}
.pref-row:last-child{border-bottom:none;}
.pref-row .pref-info h6{font-size:0.92rem;font-weight:700;color:var(--text-primary,#1e293b);margin-bottom:3px;}
.pref-row .pref-info p{font-size:0.8rem;color:var(--text-muted,#94a3b8);margin:0;}
.toggle-switch{position:relative;width:48px;height:26px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:var(--bg-secondary,#cbd5e1);border-radius:30px;cursor:pointer;transition:all 0.3s;}
.toggle-slider::before{content:'';position:absolute;width:20px;height:20px;border-radius:50%;background:#fff;top:3px;left:3px;transition:all 0.3s;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
.toggle-switch input:checked + .toggle-slider{background:linear-gradient(135deg,#0c6e5e,#10b981);}
.toggle-switch input:checked + .toggle-slider::before{transform:translateX(22px);}
.verify-step{display:flex;align-items:flex-start;gap:12px;padding:14px 0;border-bottom:1px dashed var(--border-color,#e2e8f0);}
.verify-step:last-child{border-bottom:none;}
.verify-step .vs-num{width:30px;height:30px;border-radius:50%;background:var(--bg-secondary,#f1f5f9);color:var(--text-muted,#64748b);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;flex-shrink:0;}
.verify-step.done .vs-num{background:#d1fae5;color:#059669;}
.verify-step.active .vs-num{background:#fef3c7;color:#b45309;}
.verify-step .vs-text h6{font-size:0.85rem;font-weight:700;color:var(--text-primary,#1e293b);margin-bottom:2px;}
.verify-step .vs-text p{font-size:0.78rem;color:var(--text-muted,#94a3b8);margin:0;}
.verify-step .vs-status{margin-left:auto;font-size:0.72rem;font-weight:600;white-space:nowrap;}
</style>

<!-- Banner -->
<div class="profile-hero">
    <div class="position-relative d-flex align-items-center justify-content-between flex-wrap gap-3" style="z-index:1;">
        <div>
            <h3 class="fw-bold mb-1"><i class="fas fa-user-circle me-2"></i>My Profile</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage your personal information, security, and preferences</p>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="profile-tabs">
    <a href="?tab=personal" class="profile-tab <?= $active_tab === 'personal' ? 'active' : '' ?>"><i class="fas fa-user"></i> Personal Info</a>
    <a href="?tab=security" class="profile-tab <?= $active_tab === 'security' ? 'active' : '' ?>"><i class="fas fa-shield-halved"></i> Security & Password</a>
    <a href="?tab=preferences" class="profile-tab <?= $active_tab === 'preferences' ? 'active' : '' ?>"><i class="fas fa-sliders"></i> Preferences</a>
</div>

<!-- ============ TAB: PERSONAL INFO ============ -->
<div class="profile-panel" id="panel-personal" style="<?= $active_tab === 'personal' ? '' : 'display:none;' ?>">
    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" id="profileForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="update_profile" value="1">

                <div class="profile-section">
                    <div class="section-header">
                        <div style="width:36px;height:36px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-user" style="color:#3b82f6;font-size:0.9rem;"></i>
                        </div>
                        <div>
                            <h6>Personal Information</h6>
                            <small style="color:var(--text-muted,#94a3b8);">Your basic identity details</small>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="name"><i class="fas fa-user"></i>Full Name <span class="text-danger">*</span></label>
                                    <input type="text" id="name" name="name" class="profile-input" value="<?= sanitize($user['name']) ?>" required>
                                    <div class="field-error" id="err-name"><i class="fas fa-circle-exclamation"></i> Full name is required.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="email"><i class="fas fa-envelope"></i>Email</label>
                                    <input type="email" id="email" class="profile-input" value="<?= sanitize($user['email']) ?>" readonly disabled>
                                    <div class="field-hint"><i class="fas fa-lock me-1"></i>Email is used for login and cannot be changed.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="gender"><i class="fas fa-venus-mars"></i>Gender <span class="text-danger">*</span></label>
                                    <select id="gender" name="gender" class="profile-input" required>
                                        <option value="male" <?= ($user['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                                        <option value="female" <?= ($user['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="age"><i class="fas fa-cake-candles"></i>Age <span class="text-danger">*</span></label>
                                    <input type="number" id="age" name="age" class="profile-input" value="<?= (int)($user['age'] ?? 18) ?>" min="1" max="120" required>
                                    <div class="field-error" id="err-age"><i class="fas fa-circle-exclamation"></i> Please enter a valid age (1–120).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="phone"><i class="fas fa-phone"></i>Contact Number <span class="text-danger">*</span></label>
                                    <div class="input-wrap">
                                        <div class="input-prefix has-select">
                                            <select name="phone_prefix" id="phone_prefix">
                                                <?php foreach ($country_codes as $code => $label): ?>
                                                    <option value="<?= $code ?>" <?= $parsed_phone['prefix'] === $code ? 'selected' : '' ?>><?= $code ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <input type="tel" id="phone" name="phone" class="profile-input prefixed" value="<?= sanitize($parsed_phone['number']) ?>" placeholder="9XX XXX XXXX" required>
                                    </div>
                                    <div class="field-error" id="err-phone"><i class="fas fa-circle-exclamation"></i> Contact number is required.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="username"><i class="fas fa-at"></i>Username</label>
                                    <input type="text" id="username" class="profile-input" value="<?= sanitize($user['username'] ?? '') ?>" readonly disabled>
                                    <div class="field-hint"><i class="fas fa-lock me-1"></i>Username is used for login and cannot be changed.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="profile-section">
                    <div class="section-header">
                        <div style="width:36px;height:36px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-heartbeat" style="color:#ef4444;font-size:0.9rem;"></i>
                        </div>
                        <div>
                            <h6>Emergency & Accessibility</h6>
                            <small style="color:var(--text-muted,#94a3b8);">Who to contact in case of emergency</small>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="emergency_contact"><i class="fas fa-user-shield"></i>Emergency Contact <span class="text-danger">*</span></label>
                                    <input type="text" id="emergency_contact" name="emergency_contact" class="profile-input" value="<?= sanitize($profile['emergency_contact'] ?? '') ?>" placeholder="Contact person name" required>
                                    <div class="field-error" id="err-emergency_contact"><i class="fas fa-circle-exclamation"></i> Emergency contact is required.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="emergency_contact_number"><i class="fas fa-phone-volume"></i>Emergency Contact Number <span class="text-danger">*</span></label>
                                    <div class="input-wrap">
                                        <div class="input-prefix has-select">
                                            <select name="emergency_phone_prefix" id="emergency_phone_prefix">
                                                <?php foreach ($country_codes as $code => $label): ?>
                                                    <option value="<?= $code ?>" <?= $parsed_emergency['prefix'] === $code ? 'selected' : '' ?>><?= $code ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <input type="tel" id="emergency_contact_number" name="emergency_contact_number" class="profile-input prefixed" value="<?= sanitize($parsed_emergency['number']) ?>" placeholder="9XX XXX XXXX" required>
                                    </div>
                                    <div class="field-error" id="err-emergency_contact_number"><i class="fas fa-circle-exclamation"></i> Emergency contact number is required.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="disability"><i class="fas fa-wheelchair"></i>Disability</label>
                                    <select id="disability" name="disability" class="profile-input">
                                        <?php
                                        $disability_opts = ['none'=>'None','physical'=>'Physical','visual'=>'Visual','hearing'=>'Hearing','other'=>'Other'];
                                        $current_dis = $profile['disability'] ?? 'none';
                                        foreach ($disability_opts as $val => $lbl):
                                        ?>
                                            <option value="<?= $val ?>" <?= $current_dis === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="profile-field">
                                    <label class="form-label" for="disability_details"><i class="fas fa-comment-medical"></i>Disability Details</label>
                                    <input type="text" id="disability_details" name="disability_details" class="profile-input" value="<?= sanitize($profile['disability_details'] ?? '') ?>" placeholder="Additional details (optional)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <!-- Profile Picture -->
            <div class="profile-sidebar-card">
                <div class="section-header">
                    <div style="width:36px;height:36px;border-radius:10px;background:#d1fae5;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-camera" style="color:#059669;font-size:0.9rem;"></i>
                    </div>
                    <h6>Profile Picture</h6>
                </div>
                <div class="text-center" style="padding:28px 20px;">
                    <div class="position-relative d-inline-block mb-3">
                        <form method="POST" enctype="multipart/form-data" id="avatarForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="change_avatar" value="1">
                            <div class="profile-avatar-wrap" onclick="document.getElementById('avatarInput').click();">
                                <img src="<?= get_avatar_url($user) ?>" id="avatarImg" alt="<?= sanitize($user['name']) ?>">
                                <div class="avatar-hover"><i class="fas fa-camera"></i><span>Change</span></div>
                            </div>
                            <div class="profile-avatar-btn"><i class="fas fa-camera" style="font-size:0.85rem;"></i></div>
                            <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAndSubmit(this);">
                        </form>
                    </div>
                    <h5 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);"><?= sanitize($user['name']) ?></h5>
                    <p class="text-muted small mb-2"><?= sanitize($user['email']) ?></p>
                    <p class="small text-muted mb-0"><i class="fas fa-camera me-1"></i>Click photo to change</p>
                    <div style="margin-top:14px;">
                        <span class="status-pill <?= $user['status'] === 'active' ? 'active' : 'inactive' ?>">
                            <i class="fas <?= $user['status'] === 'active' ? 'fa-circle-check' : 'fa-pause-circle' ?>"></i>
                            <?= ucfirst($user['status']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- ID Verification -->
            <div class="profile-sidebar-card">
                <div class="section-header">
                    <div style="width:36px;height:36px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-id-card" style="color:#3b82f6;font-size:0.9rem;"></i>
                    </div>
                    <h6>ID Verification</h6>
                </div>
                <div class="p-4">
                    <?php if ($current_verification):
                        $vs = $current_verification['status'];
                        $vsConfig = [
                            'approved' => ['icon' => 'fas fa-check-circle', 'color' => '#10b981', 'label' => 'Verified', 'bg' => '#d1fae5', 'iconBg' => '#10b98120'],
                            'pending'  => ['icon' => 'fas fa-clock', 'color' => '#f59e0b', 'label' => 'Pending Verification', 'bg' => '#fef3c7', 'iconBg' => '#f59e0b20'],
                            'rejected' => ['icon' => 'fas fa-times-circle', 'color' => '#ef4444', 'label' => 'Rejected', 'bg' => '#fee2e2', 'iconBg' => '#ef444420'],
                        ];
                        $vsInfo = $vsConfig[$vs] ?? $vsConfig['pending'];
                        $isImage = in_array(pathinfo($current_verification['id_file_path'], PATHINFO_EXTENSION), ['jpg','jpeg','png','gif','webp']);
                    ?>
                    <div class="vs-banner" style="background:<?= $vsInfo['bg'] ?>;">
                        <div class="vs-icon" style="background:<?= $vsInfo['iconBg'] ?>;">
                            <i class="<?= $vsInfo['icon'] ?>" style="color:<?= $vsInfo['color'] ?>;"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold" style="color:<?= $vsInfo['color'] ?>;font-size:0.95rem;"><?= $vsInfo['label'] ?></div>
                            <div class="small" style="color:var(--text-muted,#64748b);"><?= $id_type_labels[$current_verification['id_type']] ?? $current_verification['id_type'] ?> · <?= format_date($current_verification['created_at']) ?></div>
                        </div>
                    </div>

                    <?php if ($current_verification['id_file_path']): ?>
                    <div style="border:1px solid var(--border-color,#e2e8f0);border-radius:12px;overflow:hidden;margin-bottom:16px;">
                        <?php if ($isImage): ?>
                            <img src="<?= BASE_URL ?>/assets/uploads/<?= ltrim($current_verification['id_file_path'], '/') ?>" alt="Submitted ID" style="width:100%;max-height:200px;object-fit:contain;background:var(--bg-secondary,#f8fafc);">
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>/assets/uploads/<?= ltrim($current_verification['id_file_path'], '/') ?>" target="_blank" class="d-flex align-items-center justify-content-center gap-2 text-decoration-none" style="padding:32px 16px;background:var(--bg-secondary,#f8fafc);color:var(--text-primary,#1e293b);">
                                <i class="fas fa-file-pdf" style="font-size:2rem;color:#ef4444;"></i>
                                <span class="fw-semibold">View PDF Document</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($vs === 'rejected'): ?>
                        <button class="btn w-100 mb-3" onclick="document.getElementById('uploadSection').style.display='block';this.style.display='none'" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;font-weight:600;">
                            <i class="fas fa-redo me-1"></i>Re-submit ID
                        </button>
                        <div id="uploadSection" style="display:none;">
                    <?php else: ?>
                        <div id="uploadSection">
                    <?php endif; ?>
                <?php else: ?>
                    <div id="uploadSection">
                <?php endif; ?>

                <h6 class="small fw-semibold mb-3" style="color:var(--text-primary,#1e293b);">
                    <i class="fas fa-cloud-upload-alt me-1" style="color:var(--primary);"></i>Upload Government ID
                </h6>

                <form method="POST" enctype="multipart/form-data" id="idUploadForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="submit_verification" value="1">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">ID Type</label>
                        <select name="id_type" class="profile-input" style="font-size:0.85rem;" required>
                            <option value="">Select ID Type</option>
                            <?php foreach ($id_type_labels as $val => $lbl): ?>
                                <option value="<?= $val ?>"><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="dropZone" class="drop-zone mb-3" onclick="document.getElementById('idFileInput').click()">
                        <div id="dropPlaceholder">
                            <div class="dz-icon"><i class="fas fa-cloud-arrow-up" style="font-size:1.4rem;color:var(--primary);"></i></div>
                            <div class="small fw-semibold" style="color:var(--text-primary,#1e293b);">Click or drag to upload</div>
                            <div class="small" style="color:var(--text-muted,#94a3b8);">JPG, PNG, or PDF · Max 10MB</div>
                        </div>
                        <div id="filePreview" style="display:none;">
                            <div class="file-chip">
                                <div id="fcThumbWrap" class="d-none">
                                    <img id="fcThumb" class="fc-thumb" alt="Preview">
                                </div>
                                <div class="fc-icon" id="fcIcon"><i class="fas fa-file-pdf" style="color:#ef4444;"></i></div>
                                <div class="flex-grow-1 text-start">
                                    <div class="fc-name" id="fileName"></div>
                                    <div class="fc-meta" id="fileMeta"></div>
                                </div>
                                <button type="button" class="btn btn-sm p-0" onclick="event.stopPropagation();clearFilePreview()" style="color:#ef4444;"><i class="fas fa-times-circle"></i></button>
                            </div>
                        </div>
                        <input type="file" name="id_file" id="idFileInput" class="d-none" accept=".jpg,.jpeg,.png,.pdf" required>
                    </div>

                    <div class="guidelines-box">
                        <div class="small fw-semibold mb-2" style="color:var(--text-primary,#1e293b);"><i class="fas fa-info-circle me-1" style="color:var(--primary);"></i>Upload Guidelines</div>
                        <ul class="small mb-0" style="color:var(--text-muted,#64748b);padding-left:18px;margin:0;">
                            <li>Ensure all four corners of the ID are visible</li>
                            <li>Name and photo must be clearly readable</li>
                            <li>No screenshots or edited images</li>
                            <li>Original, valid (non-expired) government-issued ID</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn w-100" id="submitIdBtn" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;font-weight:600;">
                        <i class="fas fa-paper-plane me-1"></i>Submit for Verification
                    </button>
                </form>

                <?php if ($current_verification): ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($verifications)): ?>
            <div class="profile-sidebar-card">
                <div class="section-header">
                    <div style="width:36px;height:36px;border-radius:10px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-history" style="color:#64748b;font-size:0.9rem;"></i>
                    </div>
                    <h6>Verification History</h6>
                </div>
                <div style="padding:0;">
                    <?php foreach ($verifications as $v):
                        $vsc = $v['status'] === 'approved' ? '#10b981' : ($v['status'] === 'pending' ? '#f59e0b' : '#ef4444');
                        $vsi = $v['status'] === 'approved' ? 'fa-check-circle' : ($v['status'] === 'pending' ? 'fa-clock' : 'fa-times-circle');
                    ?>
                        <div style="padding:12px 20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;justify-content:space-between;align-items:center;<?php if ($v === end($verifications)) echo 'border-bottom:none;'; ?>">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:32px;height:32px;border-radius:8px;background:<?= $vsc ?>18;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= $vsi ?>" style="color:<?= $vsc ?>;font-size:0.85rem;"></i>
                                </div>
                                <div>
                                    <div class="small fw-semibold" style="color:var(--text-primary,#1e293b);"><?= $id_type_labels[$v['id_type']] ?? $v['id_type'] ?></div>
                                    <small style="color:var(--text-muted,#94a3b8);"><?= format_date($v['created_at']) ?></small>
                                </div>
                            </div>
                            <span class="small fw-semibold" style="color:<?= $vsc ?>;"><?= ucfirst($v['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sticky Save Bar -->
    <div class="save-bar" id="saveBar">
        <div class="sb-note"><i class="fas fa-circle-exclamation"></i><span>You have unsaved changes</span></div>
        <div class="d-flex gap-2">
            <button type="button" class="btn-outline-soft" onclick="resetProfileForm()">Cancel</button>
            <button type="button" class="btn-primary-gradient" onclick="submitProfileForm()"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- ============ TAB: SECURITY & PASSWORD ============ -->
<div class="profile-panel" id="panel-security" style="<?= $active_tab === 'security' ? '' : 'display:none;' ?>">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="profile-section">
                <div class="section-header">
                    <div style="width:36px;height:36px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-lock" style="color:#f59e0b;font-size:0.9rem;"></i>
                    </div>
                    <div>
                        <h6>Change Password</h6>
                        <small style="color:var(--text-muted,#94a3b8);">Use a strong, unique password</small>
                    </div>
                </div>
                <div class="section-body">
                    <form method="POST" id="passwordForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="change_password" value="1">
                        <div class="profile-field">
                            <label class="form-label" for="current_password"><i class="fas fa-key"></i>Current Password</label>
                            <div class="password-wrap">
                                <input type="password" id="current_password" name="current_password" class="profile-input" required>
                                <button type="button" class="password-toggle" onclick="togglePw('current_password', this)"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="field-error" id="err-current_password"><i class="fas fa-circle-exclamation"></i> Current password is required.</div>
                        </div>
                        <div class="profile-field">
                            <label class="form-label" for="new_password"><i class="fas fa-shield-halved"></i>New Password</label>
                            <div class="password-wrap">
                                <input type="password" id="new_password" name="new_password" class="profile-input" minlength="8" required>
                                <button type="button" class="password-toggle" onclick="togglePw('new_password', this)"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-strength"><div class="ps-fill" id="pwStrengthFill"></div></div>
                            <div class="ps-labels" id="pwStrengthLabel"><i class="fas fa-circle-info me-1"></i>Use at least 8 characters with numbers & symbols</div>
                            <div class="field-error" id="err-new_password"><i class="fas fa-circle-exclamation"></i> Password must be at least 8 characters.</div>
                        </div>
                        <div class="profile-field">
                            <label class="form-label" for="confirm_password"><i class="fas fa-check-double"></i>Confirm New Password</label>
                            <div class="password-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" class="profile-input" minlength="8" required>
                                <button type="button" class="password-toggle" onclick="togglePw('confirm_password', this)"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="field-error" id="err-confirm_password"><i class="fas fa-circle-exclamation"></i> Passwords do not match.</div>
                        </div>
                        <button type="submit" class="btn-primary-gradient"><i class="fas fa-key"></i> Change Password</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <!-- Account status card -->
            <div class="profile-sidebar-card mb-4">
                <div class="section-header">
                    <div style="width:36px;height:36px;border-radius:10px;background:#d1fae5;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-user-check" style="color:#059669;font-size:0.9rem;"></i>
                    </div>
                    <h6>Account Status</h6>
                </div>
                <div class="p-4 text-center">
                    <div class="mb-3">
                        <span class="status-pill <?= $user['status'] === 'active' ? 'active' : 'inactive' ?>" style="font-size:0.9rem;padding:8px 18px;">
                            <i class="fas <?= $user['status'] === 'active' ? 'fa-circle-check' : 'fa-pause-circle' ?>"></i>
                            <?= ucfirst($user['status']) ?>
                        </span>
                    </div>
                    <?php if ($user['status'] === 'active'): ?>
                        <p class="small mb-3" style="color:var(--text-muted,#94a3b8);">Your account is active and you can book tours.</p>
                        <button type="button" class="danger-btn w-100" data-bs-toggle="modal" data-bs-target="#deactivateModal">
                            <i class="fas fa-power-off"></i> Deactivate Account
                        </button>
                    <?php else: ?>
                        <p class="small mb-3" style="color:var(--text-muted,#94a3b8);">Your account is currently inactive. Reactivate to continue booking tours.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="toggle_status" value="1">
                            <button type="submit" class="btn-primary-gradient w-100"><i class="fas fa-play"></i> Reactivate Account</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Security tips -->
            <div class="profile-sidebar-card">
                <div class="section-header">
                    <div style="width:36px;height:36px;border-radius:10px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-lightbulb" style="color:#64748b;font-size:0.9rem;"></i>
                    </div>
                    <h6>Security Tips</h6>
                </div>
                <div class="p-4">
                    <div class="verify-step done">
                        <div class="vs-num">1</div>
                        <div class="vs-text">
                            <h6>Use a strong password</h6>
                            <p>Mix letters, numbers, and symbols.</p>
                        </div>
                    </div>
                    <div class="verify-step done">
                        <div class="vs-num">2</div>
                        <div class="vs-text">
                            <h6>Never share your password</h6>
                            <p>BINALGO staff will never ask for it.</p>
                        </div>
                    </div>
                    <div class="verify-step active">
                        <div class="vs-num">3</div>
                        <div class="vs-text">
                            <h6>Update regularly</h6>
                            <p>Change your password every few months.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ TAB: PREFERENCES ============ -->
<div class="profile-panel" id="panel-preferences" style="<?= $active_tab === 'preferences' ? '' : 'display:none;' ?>">
    <div class="profile-section">
        <div class="section-header">
            <div style="width:36px;height:36px;border-radius:10px;background:#d1fae5;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-sliders" style="color:#059669;font-size:0.9rem;"></i>
            </div>
            <div>
                <h6>Appearance</h6>
                <small style="color:var(--text-muted,#94a3b8);">Customize how the app looks for you</small>
            </div>
        </div>
        <form method="POST" id="prefForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="update_preferences" value="1">
            <div class="pref-row">
                <div class="pref-info">
                    <h6>Dark Mode</h6>
                    <p>Switch between light and dark themes across the app.</p>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="theme_toggle" id="theme_toggle" <?= ($user['theme'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
                <input type="hidden" name="theme" id="theme" value="<?= ($user['theme'] ?? 'light') === 'dark' ? 'dark' : 'light' ?>">
            </div>
            <div class="p-4 pt-3">
                <button type="submit" class="btn-primary-gradient"><i class="fas fa-save"></i> Save Preferences</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ Deactivate Modal ============ -->
<div class="modal fade" id="deactivateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 24px 60px rgba(0,0,0,0.2);overflow:hidden;">
            <div class="modal-header" style="border-bottom:none;padding:24px 24px 0;">
                <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2" style="color:#dc2626;"></i>Deactivate Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding:16px 24px 8px;">
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
                    <p class="small mb-0" style="color:#b91c1c;line-height:1.6;">
                        <i class="fas fa-circle-info me-1"></i>Once deactivated, you will <strong>not be able to book tours</strong> until you reactivate your account. Your profile data will remain intact.
                    </p>
                </div>
                <form method="POST" id="deactivateForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="toggle_status" value="1">
                    <input type="hidden" name="confirm_deactivate" value="1">
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:0.85rem;">Type <strong>DEACTIVATE</strong> to confirm</label>
                        <input type="text" id="deactivateConfirmInput" class="profile-input" placeholder="Type DEACTIVATE" autocomplete="off">
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top:none;padding:8px 24px 24px;gap:10px;">
                <button type="button" class="btn-outline-soft flex-fill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="danger-btn flex-fill" id="deactivateConfirmBtn" disabled>
                    <i class="fas fa-power-off"></i> Deactivate Account
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showToast(message, type) {
    type = type || 'warning';
    var existing = document.querySelector('.custom-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'custom-toast';
    var icon = type === 'error' ? 'fa-circle-exclamation' : (type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation');
    var colors = { error: '#ef4444', success: '#10b981', warning: '#f59e0b' };
    toast.innerHTML = '<div style="display:flex;align-items:center;gap:12px;"><div style="width:36px;height:36px;border-radius:10px;background:' + colors[type] + '15;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas ' + icon + '" style="color:' + colors[type] + ';font-size:0.95rem;"></i></div><span style="font-size:0.88rem;font-weight:500;color:#1e293b;">' + message + '</span></div>';
    toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:10000;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 20px;box-shadow:0 12px 40px rgba(0,0,0,0.12);transform:translateX(120%);transition:transform .35s cubic-bezier(.4,0,.2,1),opacity .35s;max-width:360px;';
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.style.transform = 'translateX(0)'; });
    setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        setTimeout(function() { toast.remove(); }, 350);
    }, 3000);
}

function togglePw(fieldId, btn) {
    var input = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (input.type === 'password') { input.type = 'text'; icon.classList.replace('fa-eye', 'fa-eye-slash'); }
    else { input.type = 'password'; icon.classList.replace('fa-eye-slash', 'fa-eye'); }
}

function previewAndSubmit(input) {
    var file = input.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        showToast('Please select an image file.', 'warning');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        showToast('Image must be under 5MB.', 'error');
        return;
    }
    var reader = new FileReader();
    reader.onload = function(ev) { document.getElementById('avatarImg').src = ev.target.result; };
    reader.readAsDataURL(file);
    setTimeout(function() { document.getElementById('avatarForm').submit(); }, 300);
}

// Save bar (personal info)
(function() {
    var form = document.getElementById('profileForm');
    if (!form) return;
    var saveBar = document.getElementById('saveBar');
    var snapshot = form.querySelector('#profileForm') ? null : null;

    function makeSnapshot() {
        var s = {};
        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
            s[el.name] = el.value;
        });
        return JSON.stringify(s);
    }
    var initial = makeSnapshot();

    function checkDirty() {
        var current = makeSnapshot();
        if (current !== initial) saveBar.classList.add('visible');
        else saveBar.classList.remove('visible');
    }

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);

    window.submitProfileForm = function() {
        // Validation
        var valid = true;
        var required = [
            { id: 'name', err: 'err-name', check: function(v){ return v.trim() !== ''; } },
            { id: 'phone', err: 'err-phone', check: function(v){ return v.trim() !== ''; } },
            { id: 'emergency_contact', err: 'err-emergency_contact', check: function(v){ return v.trim() !== ''; } },
            { id: 'emergency_contact_number', err: 'err-emergency_contact_number', check: function(v){ return v.trim() !== ''; } },
            { id: 'age', err: 'err-age', check: function(v){ var n = parseInt(v); return n >= 1 && n <= 120; } },
        ];
        required.forEach(function(r) {
            var input = document.getElementById(r.id);
            var err = document.getElementById(r.err);
            var ok = r.check(input.value);
            input.classList.toggle('has-error', !ok);
            if (err) err.classList.toggle('visible', !ok);
            if (!ok) valid = false;
        });
        if (!valid) {
            showToast('Please fix the highlighted fields.', 'error');
            return;
        }
        form.submit();
    };

    window.resetProfileForm = function() {
        var current = makeSnapshot();
        if (current !== initial) {
            var keys = Object.keys(JSON.parse(initial));
            keys.forEach(function(k) {
                var el = form.querySelector('[name="' + k + '"]');
                if (el) el.value = JSON.parse(initial)[k];
            });
            form.querySelectorAll('.has-error').forEach(function(e) { e.classList.remove('has-error'); });
            form.querySelectorAll('.field-error.visible').forEach(function(e) { e.classList.remove('visible'); });
            saveBar.classList.remove('visible');
        }
    };
})();

// Password strength
(function() {
    var np = document.getElementById('new_password');
    if (!np) return;
    np.addEventListener('input', function() {
        var v = np.value;
        var score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[0-9]/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        var fill = document.getElementById('pwStrengthFill');
        var label = document.getElementById('pwStrengthLabel');
        var colors = ['#e2e8f0', '#ef4444', '#f59e0b', '#3b82f6', '#10b981'];
        var names = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        fill.style.width = (score * 25) + '%';
        fill.style.background = colors[score];
        if (v.length > 0) label.innerHTML = '<i class="fas fa-circle-info me-1"></i>' + names[score] + ' password';
        else label.innerHTML = '<i class="fas fa-circle-info me-1"></i>Use at least 8 characters with numbers & symbols';
    });
})();

// Password form validation
(function() {
    var form = document.getElementById('passwordForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        var valid = true;
        var cur = document.getElementById('current_password');
        var neu = document.getElementById('new_password');
        var con = document.getElementById('confirm_password');
        var errCur = document.getElementById('err-current_password');
        var errNeu = document.getElementById('err-new_password');
        var errCon = document.getElementById('err-confirm_password');

        if (!cur.value) { cur.classList.add('has-error'); errCur.classList.add('visible'); valid = false; }
        else { cur.classList.remove('has-error'); errCur.classList.remove('visible'); }

        if (neu.value.length < 8) { neu.classList.add('has-error'); errNeu.classList.add('visible'); valid = false; }
        else { neu.classList.remove('has-error'); errNeu.classList.remove('visible'); }

        if (con.value !== neu.value || !con.value) { con.classList.add('has-error'); errCon.classList.add('visible'); valid = false; }
        else { con.classList.remove('has-error'); errCon.classList.remove('visible'); }

        if (!valid) { e.preventDefault(); showToast('Please fix the highlighted fields.', 'error'); }
    });
})();

// Theme toggle
(function() {
    var toggle = document.getElementById('theme_toggle');
    var theme = document.getElementById('theme');
    if (!toggle) return;
    toggle.addEventListener('change', function() {
        theme.value = toggle.checked ? 'dark' : 'light';
        showToast('Theme preference updated. Click Save to apply.', 'warning');
    });
})();

// Deactivate confirm
(function() {
    var input = document.getElementById('deactivateConfirmInput');
    var btn = document.getElementById('deactivateConfirmBtn');
    if (!input || !btn) return;
    input.addEventListener('input', function() {
        btn.disabled = input.value.trim().toUpperCase() !== 'DEACTIVATE';
    });
    btn.addEventListener('click', function() {
        document.getElementById('deactivateForm').submit();
    });
})();

// ID upload preview
(function() {
    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('idFileInput');
    var dropPlaceholder = document.getElementById('dropPlaceholder');
    var filePreview = document.getElementById('filePreview');
    var fileName = document.getElementById('fileName');
    var fileMeta = document.getElementById('fileMeta');
    var fcThumb = document.getElementById('fcThumb');
    var fcThumbWrap = document.getElementById('fcThumbWrap');
    var fcIcon = document.getElementById('fcIcon');

    if (!dropZone) return;

    ['dragenter','dragover'].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault(); e.stopPropagation();
            dropZone.classList.add('dragover');
        });
    });
    ['dragleave','drop'].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault(); e.stopPropagation();
            dropZone.classList.remove('dragover');
        });
    });
    dropZone.addEventListener('drop', function(e) {
        var files = e.dataTransfer.files;
        if (files.length > 0) { fileInput.files = files; handleFileSelect(files[0]); }
    });
    fileInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) handleFileSelect(e.target.files[0]);
    });

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function handleFileSelect(file) {
        var maxSize = 10 * 1024 * 1024;
        var allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
        if (!allowed.includes(file.type)) {
            showToast('Only JPG, PNG, GIF, WebP, or PDF files are allowed.', 'warning');
            clearFilePreview();
            return;
        }
        if (file.size > maxSize) {
            showToast('File size must be under 10MB.', 'error');
            clearFilePreview();
            return;
        }

        fileName.textContent = file.name;
        fileMeta.textContent = formatSize(file.size) + ' · ' + (file.type.split('/')[1] || '').toUpperCase();
        dropPlaceholder.style.display = 'none';
        filePreview.style.display = 'block';

        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                fcThumb.src = ev.target.result;
                fcThumbWrap.classList.remove('d-none');
                fcIcon.classList.add('d-none');
            };
            reader.readAsDataURL(file);
        } else {
            fcThumbWrap.classList.add('d-none');
            fcIcon.classList.remove('d-none');
            fcIcon.innerHTML = '<i class="fas fa-file-pdf" style="color:#ef4444;"></i>';
        }
    }

    window.clearFilePreview = function() {
        fileInput.value = '';
        dropPlaceholder.style.display = '';
        filePreview.style.display = 'none';
        fcThumbWrap.classList.add('d-none');
        fcThumb.src = '';
        fcIcon.classList.remove('d-none');
        fcIcon.innerHTML = '<i class="fas fa-file-pdf" style="color:#ef4444;"></i>';
        fileName.textContent = '';
        fileMeta.textContent = '';
    };
})();
</script>

<?php }); ?>
