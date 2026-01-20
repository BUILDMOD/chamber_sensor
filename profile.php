<?php 
// profile.php
include('includes/auth_check.php');
include('includes/db_connect.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine current user (username stored in session at login)
$currentUsername = null;
if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    $currentUsername = $_SESSION['user'];
} elseif (isset($_SESSION['user_name']) && !empty($_SESSION['user_name'])) {
    $currentUsername = $_SESSION['user_name'];
}

if (!$currentUsername) {
    header('Location: index.php');
    exit;
}

// Function to log activity
function logActivity($conn, $userId, $action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, ip_address) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $userId, $action, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// fetch user first (so we have user id for logging)
$user = null;
$stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, fullname, email, phone, username, created_at FROM users WHERE username = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $currentUsername);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user = $res->fetch_assoc();
    }
    $stmt->close();
}

$errors = [];
$success = "";

// ------------- ADD USER (OWNER ONLY) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {

    // Server-side guard: only allow if session role is owner
    $sessionRole = $_SESSION['role'] ?? '';
    if ($sessionRole !== 'owner') {
        $errors[] = "Access denied. Only owner can create new accounts.";
    } else {
        // collect inputs
        $a_first  = trim($_POST['a_first_name'] ?? '');
        $a_middle = trim($_POST['a_middle_name'] ?? '');
        $a_last   = trim($_POST['a_last_name'] ?? '');
        $a_email  = trim($_POST['a_email'] ?? '');
        $a_phone  = trim($_POST['a_phone'] ?? '');
        $a_username = trim($_POST['a_username'] ?? '');
        $a_password_raw = $_POST['a_password'] ?? '';
        $a_role = in_array($_POST['a_role'] ?? 'staff', ['owner','staff']) ? $_POST['a_role'] : 'staff';

        // basic validation
        if ($a_first === '') $errors[] = "First name is required for new user.";
        if ($a_last === '') $errors[] = "Last name is required for new user.";
        if ($a_email === '') $errors[] = "Email is required for new user.";
        if ($a_username === '') $errors[] = "Username is required for new user.";
        if ($a_password_raw === '') $errors[] = "Password is required for new user.";

        // strong password validation
        $uppercase = preg_match('@[A-Z]@', $a_password_raw);
        $lowercase = preg_match('@[a-z]@', $a_password_raw);
        $number    = preg_match('@[0-9]@', $a_password_raw);
        $special   = preg_match('@[^\w]@', $a_password_raw);
        $minLength = strlen($a_password_raw) >= 8;

        if (!$uppercase || !$lowercase || !$number || !$special || !$minLength) {
            $errors[] = "Password must be at least 8 characters and include uppercase, lowercase, number, and special character.";
        }

        if (empty($errors)) {
            // check duplicates (username or email)
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param("ss", $a_username, $a_email);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $errors[] = "Username or Email already exists.";
                }
                $chk->close();
            } else {
                $errors[] = "Failed to validate uniqueness.";
            }
        }

        if (empty($errors)) {
            $a_fullname = trim($a_first . ' ' . ($a_middle !== '' ? $a_middle . ' ' : '') . $a_last);
            $a_hashed = password_hash($a_password_raw, PASSWORD_DEFAULT);

            $ins = $conn->prepare("
                INSERT INTO users (first_name, middle_name, last_name, fullname, email, phone, username, password, role, verified)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            if ($ins) {
                $ins->bind_param(
                    "sssssssss",
                    $a_first, $a_middle, $a_last,
                    $a_fullname, $a_email, $a_phone,
                    $a_username, $a_hashed, $a_role
                );
                if ($ins->execute()) {
                    $success = "New user created successfully.";
                    // log activity using current user's id (creator)
                    if ($user && isset($user['id'])) {
                        logActivity($conn, $user['id'], "Created new user: {$a_username}");
                    }
                } else {
                    $errors[] = "Database error while creating user.";
                }
                $ins->close();
            } else {
                $errors[] = "Failed to prepare create user statement.";
            }
        }
    }
}
// ------------- END ADD USER -------------

// ------------- DELETE USER (OWNER ONLY) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {

    // Server-side guard: only allow if session role is owner
    $sessionRole = $_SESSION['role'] ?? '';
    if ($sessionRole !== 'owner') {
        $errors[] = "Access denied. Only owner can delete users.";
    } else {
        $delete_id = intval($_POST['delete_user_id'] ?? 0);
        if ($delete_id <= 0) {
            $errors[] = "Invalid user ID.";
        } else {
            // Fetch user to delete
            $del_user = null;
            $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $del_user = $res->fetch_assoc();
                }
                $stmt->close();
            }
            if (!$del_user) {
                $errors[] = "User not found.";
            } elseif ($del_user['id'] === $user['id']) {
                $errors[] = "You cannot delete your own account.";
            } else {
                // Delete
                $del_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                if ($del_stmt) {
                    $del_stmt->bind_param("i", $delete_id);
                    if ($del_stmt->execute()) {
                        $success = "User deleted successfully.";
                        logActivity($conn, $user['id'], "Deleted user: {$del_user['username']}");
                    } else {
                        $errors[] = "Database error while deleting user.";
                    }
                    $del_stmt->close();
                } else {
                    $errors[] = "Failed to prepare delete statement.";
                }
            }
        }
    }
}
// ------------- END DELETE USER -------------

// ------------- EDIT USER (OWNER ONLY) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {

    // Server-side guard: only allow if session role is owner
    $sessionRole = $_SESSION['role'] ?? '';
    if ($sessionRole !== 'owner') {
        $errors[] = "Access denied. Only owner can edit users.";
    } else {
        $edit_id = intval($_POST['edit_user_id'] ?? 0);
        $e_first = trim($_POST['e_first_name'] ?? '');
        $e_middle = trim($_POST['e_middle_name'] ?? '');
        $e_last = trim($_POST['e_last_name'] ?? '');
        $e_email = trim($_POST['e_email'] ?? '');
        $e_phone = trim($_POST['e_phone'] ?? '');
        $e_role = in_array($_POST['e_role'] ?? 'staff', ['owner','staff']) ? $_POST['e_role'] : 'staff';

        // basic validation
        if ($e_first === '') $errors[] = "First name is required.";
        if ($e_last === '') $errors[] = "Last name is required.";
        if ($e_email === '') $errors[] = "Email is required.";
        if ($edit_id <= 0) {
            $errors[] = "Invalid user ID.";
        }

        if (empty($errors)) {
            // Fetch user to edit
            $edit_user = null;
            $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $edit_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $edit_user = $res->fetch_assoc();
                }
                $stmt->close();
            }
            if (!$edit_user) {
                $errors[] = "User not found.";
            } elseif ($edit_user['id'] === $user['id']) {
                $errors[] = "You cannot edit your own account.";
            } else {
                // Check email uniqueness (if changed)
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                if ($chk) {
                    $chk->bind_param("si", $e_email, $edit_id);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows > 0) {
                        $errors[] = "Email already exists.";
                    }
                    $chk->close();
                }
                if (empty($errors)) {
                    $e_fullname = trim($e_first . ' ' . ($e_middle !== '' ? $e_middle . ' ' : '') . $e_last);
                    $upd = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, fullname = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("sssssssi", $e_first, $e_middle, $e_last, $e_fullname, $e_email, $e_phone, $e_role, $edit_id);
                        if ($upd->execute()) {
                            $success = "User updated successfully.";
                            logActivity($conn, $user['id'], "Edited user: {$edit_user['username']}");
                        } else {
                            $errors[] = "Database error while updating user.";
                        }
                        $upd->close();
                    } else {
                        $errors[] = "Failed to prepare update statement.";
                    }
                }
            }
        }
    }
}
// ------------- END EDIT USER -------------

// ------------- APPROVE/REJECT USER (OWNER ONLY) -------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_user'])) {

    // Server-side guard: only allow if session role is owner
    $sessionRole = $_SESSION['role'] ?? '';
    if ($sessionRole !== 'owner') {
        $errors[] = "Access denied. Only owner can approve users.";
    } else {
        $approve_id = intval($_POST['approve_user_id'] ?? 0);
        if ($approve_id <= 0) {
            $errors[] = "Invalid user ID.";
        } else {
            // Update verified to 1
            $upd = $conn->prepare("UPDATE users SET verified = 1 WHERE id = ?");
            if ($upd) {
                $upd->bind_param("i", $approve_id);
                if ($upd->execute()) {
                    $success = "User approved successfully.";
                    logActivity($conn, $user['id'], "Approved user ID: {$approve_id}");
                } else {
                    $errors[] = "Database error while approving user.";
                }
                $upd->close();
            } else {
                $errors[] = "Failed to prepare approve statement.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_user'])) {

    // Server-side guard: only allow if session role is owner
    $sessionRole = $_SESSION['role'] ?? '';
    if ($sessionRole !== 'owner') {
        $errors[] = "Access denied. Only owner can reject users.";
    } else {
        $reject_id = intval($_POST['reject_user_id'] ?? 0);
        if ($reject_id <= 0) {
            $errors[] = "Invalid user ID.";
        } else {
            // Delete the user
            $del = $conn->prepare("DELETE FROM users WHERE id = ?");
            if ($del) {
                $del->bind_param("i", $reject_id);
                if ($del->execute()) {
                    $success = "User rejected and removed successfully.";
                    logActivity($conn, $user['id'], "Rejected user ID: {$reject_id}");
                } else {
                    $errors[] = "Database error while rejecting user.";
                }
                $del->close();
            } else {
                $errors[] = "Failed to prepare reject statement.";
            }
        }
    }
}
// ------------- END APPROVE/REJECT USER -------------

// --- Handle profile update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $user) {
    $first = trim($_POST['first_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // basic validations
    if ($first === '') $errors[] = "First name is required.";
    if ($last === '') $errors[] = "Last name is required.";
    if ($email === '') $errors[] = "Email is required.";
    // optional: phone required? previous code required it, keep required
    if ($phone === '') $errors[] = "Phone number is required.";

    if (empty($errors)) {
        $fullname = trim($first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last);

        $u = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, fullname = ?, email = ?, phone = ? WHERE id = ?");
        if ($u) {
            $u->bind_param("ssssssi", $first, $middle, $last, $fullname, $email, $phone, $user['id']);
            if ($u->execute()) {
                $success = "Profile updated successfully.";
                logActivity($conn, $user['id'], "Profile updated");

                // re-fetch user to show updated values
                $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, fullname, email, phone, username, created_at FROM users WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $user = $res->fetch_assoc();
                    }
                    $stmt->close();
                }
            } else {
                $errors[] = "Database error while updating profile.";
            }
            $u->close();
        } else {
            $errors[] = "Failed to prepare profile update.";
        }
    }
}

// --- Handle password change ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && $user) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass     = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if ($current_pass === '' || $new_pass === '' || $confirm_pass === '') {
        $errors[] = "All password fields are required.";
    } elseif ($new_pass !== $confirm_pass) {
        $errors[] = "New password and confirmation do not match.";
    } else {
        // fetch current hashed password from DB
        $s = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        if ($s) {
            $s->bind_param("i", $user['id']);
            $s->execute();
            $s->bind_result($db_hashed);
            if ($s->fetch()) {
                $s->close();
                if (!password_verify($current_pass, $db_hashed)) {
                    $errors[] = "Current password is incorrect.";
                } else {
                    // strong password validation
                    $uppercase = preg_match('@[A-Z]@', $new_pass);
                    $lowercase = preg_match('@[a-z]@', $new_pass);
                    $number    = preg_match('@[0-9]@', $new_pass);
                    $special   = preg_match('@[^\w]@', $new_pass);
                    $minLength = strlen($new_pass) >= 8;

                    if (!$uppercase || !$lowercase || !$number || !$special || !$minLength) {
                        $errors[] = "New password must be at least 8 characters and include uppercase, lowercase, number, and special character.";
                    } else {
                        $new_hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                        $u = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        if ($u) {
                            $u->bind_param("si", $new_hashed, $user['id']);
                            if ($u->execute()) {
                                $success = "Password updated successfully.";
                                logActivity($conn, $user['id'], "Password changed");
                            } else {
                                $errors[] = "Database error while updating password.";
                            }
                            $u->close();
                        } else {
                            $errors[] = "Failed to prepare password update.";
                        }
                    }
                }
            } else {
                $s->close();
                $errors[] = "Unable to verify current password.";
            }
        } else {
            $errors[] = "Failed to fetch current password.";
        }
    }
}

// server time
date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>System Profile</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root{
  --bg: #f5f7fb;
  --panel: #ffffff;
  --muted: #6b7280;
  --text: #0f172a;
  --accent: #16a34a;
  --accent-2: #ef4444;
  --panel-shadow: 0 8px 30px rgba(15,23,42,0.06);
  --muted-ghost: rgba(15,23,42,0.04);
}

body{font-family:"Poppins",Inter,system-ui; margin:0; background:var(--bg);color:var(--text);}

/* FIXED: sidebar + topbar alignment */
.sidebar {
  position: fixed;
  left: 0;
  top: 0;
  width: 250px;
  height: 100vh;
  background:  #f8f9fa;
  border-right: 1px solid rgba(15,23,42,0.06);
  box-shadow: var(--panel-shadow);
  z-index: 50;
}

.sidebar-logo {
  padding: 20px;
  text-align: center;
  border-bottom: 1px solid rgba(15,23,42,0.1);
}
.sidebar-logo img {
  width: 60px;
  height: 60px;
  border-radius: 6px;
}

.sidebar-nav { padding: 20px 0; }
.sidebar-nav a {
  display: block;
  padding: 12px 20px;
  color: #495057;
  text-decoration: none;
  font-weight: 600;
  border-left: 3px solid transparent;
}
.sidebar-nav a:hover {
  background: rgba(15,23,42,0.05);
  color: #0f172a;
  border-left-color: #1e40af;
}
.sidebar-nav a.active {
  background: rgba(30,64,175,0.1);
  color: #1e40af;
  border-left-color: #1e40af;
}

.topbar {
  background: white;
  color: black;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:16px 22px;
  position:sticky;
  top:0;
  z-index:40;
  border-bottom:1px solid rgba(15,23,42,0.04);
  margin-left:250px;
}
.topbar h2 { color: black; }

.main-content {
  margin-left:250px;
  padding:26px;
  max-width:1100px;
}

/* cards */
.card{background:var(--panel);padding:18px;border-radius:12px;margin-bottom:18px;
      box-shadow:var(--panel-shadow); border:1px solid rgba(15,23,42,0.03);}

.profile-grid{display:grid; grid-template-columns:1fr 380px; gap:18px;}
.profile-item{display:flex; justify-content:space-between; padding:10px 0;
             border-bottom:1px solid rgba(255, 255, 255, 0.06);}
.profile-item:last-child{border-bottom:none;}

.label{font-weight:700;}
.value{color:var(--muted);}

input, select{width:100%; padding:10px; border-radius:8px; border:1px solid rgba(15,23,42,0.08);}
.btn{padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:700;
     background:var(--accent); color:white; border:none;}
.btn-muted{background:#eee;color:#333;}
.message-error{color:#ef4444;font-weight:700;}
.message-success{color:#16a34a;font-weight:700;}

/* small form styling */
.form-row{display:flex; gap:12px;}
.form-row .col{flex:1;}
.form-actions{display:flex; gap:8px; margin-top:12px;}
.small-note{font-size:13px;color:var(--muted); margin-top:6px;}

/* modal styles */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(8,10,12,0.45); display:none; align-items:center; justify-content:center; z-index:120;
}
.modal { width:520px; background:var(--panel); border-radius:12px; padding:18px; box-shadow:var(--panel-shadow); }
.modal h3 { margin-top:0; color:var(--accent); }
.modal .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.modal .small-note { font-size:12px; color:var(--muted); margin-top:6px; }
.modal .actions { display:flex; gap:8px; margin-top:12px; justify-content:flex-end; }
.modal .close-btn { background:#eee; color:#333; padding:8px 12px; border-radius:8px; border:none; cursor:pointer; }

/* inline small helper for success inside modal */
.modal .success-inline{ color: #16a34a; font-weight:700; margin-top:8px; }
.modal .error-inline{ color: #ef4444; font-weight:700; margin-top:8px; }

/* avatar styles */
.avatar {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: var(--accent);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
  font-weight: bold;
  margin: 0 auto 20px;
  text-transform: uppercase;
}

</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
  <div class="sidebar-logo">
    <img src="assets/img/logo.png" alt="Logo">
  </div>
  <nav class="sidebar-nav">
      <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
      <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
      <a class="active" href="profile.php"><i class="fas fa-user"></i> System Profile</a>
      <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </nav>
</div>

<!-- TOPBAR -->
<div class="topbar">
  <h2>System Profile</h2>
  <div style="display:flex; align-items:center; gap:14px;">
        <span id="phTime" data-server-ts="<?= $server_ts_ms ?>" class="muted-small" style="white-space:nowrap;">
          <?= htmlspecialchars($server_time_formatted) ?>
        </span>
  </div>
</div>

<!-- MAIN -->
<div class="main-content">

  <?php if (!empty($success)): ?>
    <div class="message-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php foreach ($errors as $e): ?>
    <div class="message-error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <div class="profile-grid">

    <!-- LEFT -->
    <div class="card">
      <h3>Profile Information</h3>

      <?php if ($user): ?>
        <div class="avatar"><i class="fas fa-user-tie"></i></div>
        <div class="profile-item"><span class="label">Full Name</span><span class="value"><?= htmlspecialchars($user['fullname']) ?></span></div>
        <div class="profile-item"><span class="label">Email</span><span class="value"><?= htmlspecialchars($user['email']) ?></span></div>
        <div class="profile-item"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($user['phone']) ?></span></div>
        <div class="profile-item"><span class="label">Username</span><span class="value"><?= htmlspecialchars($user['username']) ?></span></div>
        <div class="profile-item"><span class="label">Member Since</span><span class="value"><?= htmlspecialchars($user['created_at']) ?></span></div>

        <hr>
        <div class="form-actions">
          <button id="editToggle" class="btn">Edit Profile</button>
          <button id="passwdToggle" class="btn btn-muted">Change Password</button>

          <!-- SHOW ADD USER BUTTON ONLY IF CURRENT SESSION ROLE IS OWNER -->
          <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'owner'): ?>
            <button id="openAddUser" class="btn" style="background:#2b7a2b;">Add User</button>
          <?php endif; ?>
        </div>

        <!-- EDIT FORM -->
        <form method="POST" id="editForm" style="display:none;margin-top:15px;">
          <input type="hidden" name="update_profile" value="1">

          <div class="form-row">
            <div class="col">
              <label>First Name</label>
              <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
            </div>
            <div class="col">
              <label>Middle Name</label>
              <input type="text" name="middle_name" value="<?= htmlspecialchars($user['middle_name']) ?>">
            </div>
          </div>

          <div style="margin-top:10px;">
            <label>Last Name</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
          </div>

          <div class="form-row" style="margin-top:10px;">
            <div class="col">
              <label>Email</label>
              <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="col">
              <label>Phone</label>
              <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" required>
            </div>
          </div>

          <div class="small-note">Username: <strong><?= htmlspecialchars($user['username']) ?></strong> (cannot be changed here)</div>

          <div class="form-actions">
            <button type="submit" class="btn">Save</button>
            <button type="button" id="editCancel" class="btn btn-muted">Cancel</button>
          </div>
        </form>

        <!-- PASSWORD FORM -->
        <form method="POST" id="passwdForm" style="display:none;margin-top:15px;">
          <input type="hidden" name="change_password" value="1">

          <div style="margin-top:6px;">
            <label>Current Password</label>
            <div style="position:relative;">
              <input type="password" name="current_password" id="current_password" required style="width:100%; padding-right:40px;">
              <button type="button" id="toggle_current_pw" style="position:absolute; right:8px; top:6px; border:none; background:transparent; cursor:pointer;">
                <i class="fa fa-eye"></i>
              </button>
            </div>
          </div>

          <div style="margin-top:10px;">
            <label>New Password</label>
            <div style="position:relative;">
              <input type="password" name="new_password" id="new_password" required style="width:100%; padding-right:40px;">
              <button type="button" id="toggle_new_pw" style="position:absolute; right:8px; top:6px; border:none; background:transparent; cursor:pointer;">
                <i class="fa fa-eye"></i>
              </button>
            </div>
          </div>

          <div style="margin-top:10px;">
            <label>Confirm Password</label>
            <div style="position:relative;">
              <input type="password" name="confirm_password" id="confirm_password" required style="width:100%; padding-right:40px;">
              <button type="button" id="toggle_confirm_pw" style="position:absolute; right:8px; top:6px; border:none; background:transparent; cursor:pointer;">
                <i class="fa fa-eye"></i>
              </button>
            </div>
          </div>

          <div id="pw-strength" style="margin-top:8px;">
            <div style="height:6px;background:#eee;border-radius:6px;overflow:hidden;">
              <div id="pw-fill" style="width:0%;height:100%;background:red;transition:.3s;"></div>
            </div>
            <div class="small-note" id="pw-note">Password must be at least 8 chars, include uppercase, lowercase, number, and special char.</div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn">Update Password</button>
            <button type="button" id="passwdCancel" class="btn btn-muted">Cancel</button>
          </div>
        </form>

      <?php endif; ?>
    </div>

    <!-- RIGHT SYSTEM INFO -->
    <div class="card">
      <h3>System Info</h3>
      <p><strong>System:</strong> J.WHO Mushroom System<br>
         <strong>Version:</strong> 1.0.0<br>
         <strong>Database:</strong> mushroom_system</p>

      <hr>
      <h3>Latest Sensor</h3>
      <?php
      $ls = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
      if ($ls && $ls->num_rows > 0):
        $s = $ls->fetch_assoc();
      ?>
        <p><strong>Temp:</strong> <?= htmlspecialchars($s['temperature']) ?>°C<br>
           <strong>Hum:</strong> <?= htmlspecialchars($s['humidity']) ?>%<br>
           <strong>At:</strong> <?= htmlspecialchars($s['timestamp']) ?></p>
      <?php else: ?>
        <p>No sensor data.</p>
      <?php endif; ?>

      <hr>
      <h3>Activity Log</h3>
      <?php
      // Fetch last 10 activity logs for current user
      $logs_query = $conn->prepare("SELECT action, timestamp FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 10");
      if ($logs_query) {
          $logs_query->bind_param("i", $user['id']);
          $logs_query->execute();
          $logs_result = $logs_query->get_result();
          if ($logs_result->num_rows > 0) {
              echo '<ul>';
              while ($log = $logs_result->fetch_assoc()) {
                  echo '<li><span class="value">' . htmlspecialchars($log['action']) . ' - ' . htmlspecialchars($log['timestamp']) . '</span></li>';
              }
              echo '</ul>';
          } else {
              echo '<p class="value">No activity logs found.</p>';
          }
          $logs_query->close();
      } else {
          echo '<p class="value">Unable to load activity logs.</p>';
      }
      ?>
    </div>

  </div>

  <!-- USER MANAGEMENT CARD (OWNER ONLY) -->
  <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'owner'): ?>

    <!-- PENDING USERS APPROVAL -->
    <div class="card">
      <h3>Pending User Approvals</h3>
      <table style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid #ddd;">
            <th style="text-align:left; padding:8px;">Full Name</th>
            <th style="text-align:left; padding:8px;">Username</th>
            <th style="text-align:left; padding:8px;">Email</th>
            <th style="text-align:left; padding:8px;">Role</th>
            <th style="text-align:left; padding:8px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $pending_users = $conn->query("SELECT id, first_name, middle_name, last_name, fullname, username, email, phone, role FROM users WHERE verified = 0 ORDER BY id ASC");
          if ($pending_users && $pending_users->num_rows > 0) {
            while ($u = $pending_users->fetch_assoc()) {
              echo '<tr style="border-bottom:1px solid #eee;">';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['fullname']) . '</td>';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['username']) . '</td>';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['email']) . '</td>';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['role']) . '</td>';
              echo '<td style="padding:8px;">';
              echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to approve this user?\');">';
              echo '<input type="hidden" name="approve_user_id" value="' . $u['id'] . '">';
              echo '<button type="submit" name="approve_user" class="btn" style="background:#16a34a;">Yes</button>';
              echo '</form> ';
              echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to reject this user?\');">';
              echo '<input type="hidden" name="reject_user_id" value="' . $u['id'] . '">';
              echo '<button type="submit" name="reject_user" class="btn" style="background:#ef4444;">No</button>';
              echo '</form>';
              echo '</td>';
              echo '</tr>';
            }
          } else {
            echo '<tr><td colspan="5" style="padding:8px; text-align:center;">No pending users.</td></tr>';
          }
          ?>
        </tbody>
      </table>
    </div>

    <!-- EXISTING STAFF USERS MANAGEMENT -->
    <div class="card">
      <h3>Manage Users</h3>
      <table style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid #ddd;">
            <th style="text-align:left; padding:8px;">Full Name</th>
            <th style="text-align:left; padding:8px;">Username</th>
            <th style="text-align:left; padding:8px;">Email</th>
            <th style="text-align:left; padding:8px;">Role</th>
            <th style="text-align:left; padding:8px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $all_users = $conn->query("SELECT id, first_name, middle_name, last_name, fullname, username, email, phone, role FROM users WHERE role = 'staff' AND verified = 1 ORDER BY id ASC");
          if ($all_users && $all_users->num_rows > 0) {
            while ($u = $all_users->fetch_assoc()) {
              echo '<tr style="border-bottom:1px solid #eee;">';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['fullname']) . '</td>';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['username']) . '</td>';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['email']) . '</td>';
              echo '<td style="padding:8px;">' . htmlspecialchars($u['role']) . '</td>';
              echo '<td style="padding:8px;">';
              if ($u['id'] !== $user['id'] && $u['role'] !== 'owner') {
                echo '<button class="btn btn-muted edit-user-btn" data-id="' . $u['id'] . '" data-fullname="' . htmlspecialchars($u['fullname']) . '" data-first="' . htmlspecialchars($u['first_name'] ?? '') . '" data-middle="' . htmlspecialchars($u['middle_name'] ?? '') . '" data-last="' . htmlspecialchars($u['last_name']) . '" data-email="' . htmlspecialchars($u['email']) . '" data-phone="' . htmlspecialchars($u['phone'] ?? '') . '" data-role="' . htmlspecialchars($u['role']) . '">Edit</button> ';
                echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to delete this user?\');">';
                echo '<input type="hidden" name="delete_user_id" value="' . $u['id'] . '">';
                echo '<button type="submit" name="delete_user" class="btn" style="background:#ef4444;">Delete</button>';
                echo '</form>';
              } else {
                echo 'N/A';
              }
              echo '</td>';
              echo '</tr>';
            }
          } else {
            echo '<tr><td colspan="5" style="padding:8px; text-align:center;">No users found.</td></tr>';
          }
          ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ADD USER MODAL -->
<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addUserTitle">
    <h3 id="addUserTitle">Add New User</h3>

    <form id="addUserForm" method="POST">
      <input type="hidden" name="add_user" value="1">

      <div class="grid-2">
        <div>
          <label>First Name</label>
          <input type="text" name="a_first_name" required>
        </div>
        <div>
          <label>Middle Name</label>
          <input type="text" name="a_middle_name">
        </div>
      </div>

      <div style="margin-top:10px;">
        <label>Last Name</label>
        <input type="text" name="a_last_name" required>
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div>
          <label>Email</label>
          <input type="email" name="a_email" required>
        </div>
        <div>
          <label>Phone</label>
          <input type="text" name="a_phone">
        </div>
      </div>

      <div style="margin-top:10px;">
        <label>Username</label>
        <input type="text" name="a_username" required>
      </div>

      <div style="margin-top:10px;">
        <label>Password</label>
        <div style="position:relative;">
          <input type="password" name="a_password" id="a_password" required style="width:100%; padding-right:40px;">
          <button type="button" id="a_toggle_pw" style="position:absolute; right:8px; top:6px; border:none; background:transparent; cursor:pointer;">
            <i class="fa fa-eye"></i>
          </button>
        </div>

        <div style="margin-top:8px;">
          <div style="height:6px;background:#eee;border-radius:6px;overflow:hidden;">
            <div id="a_pw_fill" style="width:0%;height:100%;background:red;transition:.3s;"></div>
          </div>
          <div class="small-note" id="a_pw_note">At least 8 chars, uppercase, lowercase, number, special char.</div>
        </div>
      </div>

      <div style="margin-top:10px;">
        <label>Role</label>
        <select name="a_role" required>
          <option value="staff" selected>Staff</option>
        </select>
      </div>

      <div class="actions">
        <button type="button" class="close-btn" id="closeModal">Cancel</button>
        <button type="submit" class="btn">Create User</button>
      </div>

      <div id="modalMessages" style="margin-top:8px;"></div>
    </form>
  </div>
</div>

<!-- EDIT USER MODAL -->
<div id="editUserModalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editUserTitle">
    <h3 id="editUserTitle">Edit User</h3>

    <form id="editUserForm" method="POST">
      <input type="hidden" name="edit_user" value="1">
      <input type="hidden" name="edit_user_id" id="edit_user_id">

      <div class="grid-2">
        <div>
          <label>First Name</label>
          <input type="text" name="e_first_name" id="e_first_name" required>
        </div>
        <div>
          <label>Middle Name</label>
          <input type="text" name="e_middle_name" id="e_middle_name">
        </div>
      </div>

      <div style="margin-top:10px;">
        <label>Last Name</label>
        <input type="text" name="e_last_name" id="e_last_name" required>
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div>
          <label>Email</label>
          <input type="email" name="e_email" id="e_email" required>
        </div>
        <div>
          <label>Phone</label>
          <input type="text" name="e_phone" id="e_phone">
        </div>
      </div>

      <div style="margin-top:10px;">
        <label>Role</label>
        <select name="e_role" id="e_role" required>
          <option value="staff">Staff</option>
          <option value="owner">Owner</option>
        </select>
      </div>

      <div class="actions">
        <button type="button" class="close-btn" id="closeEditModal">Cancel</button>
        <button type="submit" class="btn">Update User</button>
      </div>

      <div id="editModalMessages" style="margin-top:8px;"></div>
    </form>
  </div>
</div>

<script>
const editToggle = document.getElementById("editToggle");
const passwdToggle = document.getElementById("passwdToggle");
const editForm = document.getElementById("editForm");
const passwdForm = document.getElementById("passwdForm");
const editCancel = document.getElementById("editCancel");
const passwdCancel = document.getElementById("passwdCancel");

editToggle.addEventListener("click", ()=> {
  editForm.style.display = "block";
  passwdForm.style.display = "none";
  window.scrollTo({ top: editForm.offsetTop - 120, behavior: 'smooth' });
});
passwdToggle.addEventListener("click", ()=> {
  passwdForm.style.display = "block";
  editForm.style.display = "none";
  window.scrollTo({ top: passwdForm.offsetTop - 120, behavior: 'smooth' });
});
editCancel.addEventListener("click", ()=> {
  editForm.style.display = "none";
});
passwdCancel.addEventListener("click", ()=> {
  passwdForm.style.display = "none";
});

// password strength meter for new password
const newPw = document.getElementById("new_password");
const pwFill = document.getElementById("pw-fill");
const pwNote = document.getElementById("pw-note");

if (newPw) {
  newPw.addEventListener("input", () => {
    let val = newPw.value;
    let strength = 0;
    if (val.match(/[a-z]/)) strength++;
    if (val.match(/[A-Z]/)) strength++;
    if (val.match(/[0-9]/)) strength++;
    if (val.match(/[^a-zA-Z0-9]/)) strength++;
    if (val.length >= 8) strength++;

    let width = (strength / 5) * 100;
    pwFill.style.width = width + "%";
    if (strength <= 2) {
      pwFill.style.background = "red";
      pwNote.textContent = "Weak — add uppercase, numbers, special chars.";
    } else if (strength === 3) {
      pwFill.style.background = "orange";
      pwNote.textContent = "Medium — try adding more characters and symbols.";
    } else {
      pwFill.style.background = "green";
      pwNote.textContent = "Strong password.";
    }
  });
}

// server time display (keeps same logic)
const ph = document.getElementById("phTime");
const server = parseInt(ph.dataset.serverTs);
const offset = Date.now() - server;
setInterval(()=>{
  const d = new Date(Date.now()-offset);
  ph.textContent = d.toLocaleString("en-US",{month:"short",day:"numeric",year:"numeric",
        hour:"numeric",minute:"2-digit",second:"2-digit",hour12:true}).replace(","," —");
},1000);

// ---------- Modal logic ----------
const openAddUser = document.getElementById("openAddUser");
const modalBackdrop = document.getElementById("modalBackdrop");
const closeModal = document.getElementById("closeModal");
const aTogglePw = document.getElementById("a_toggle_pw");
const aPassword = document.getElementById("a_password");
const aPwFill = document.getElementById("a_pw_fill");
const aPwNote = document.getElementById("a_pw_note");
const addUserForm = document.getElementById("addUserForm");
const modalMessages = document.getElementById("modalMessages");

if (openAddUser) {
  openAddUser.addEventListener("click", (e)=> {
    modalBackdrop.style.display = "flex";
    modalBackdrop.setAttribute('aria-hidden','false');
  });
}

if (closeModal) {
  closeModal.addEventListener("click", ()=> {
    modalBackdrop.style.display = "none";
    modalBackdrop.setAttribute('aria-hidden','true');
    modalMessages.innerHTML = "";
    addUserForm.reset();
    aPwFill.style.width = "0%";
    aPwFill.style.background = "red";
    aPwNote.textContent = "At least 8 chars, uppercase, lowercase, number, special char.";
  });
}

// toggle add-user password visibility
if (aTogglePw) {
  aTogglePw.addEventListener("click", ()=> {
    const show = aPassword.type === "password";
    aPassword.type = show ? "text" : "password";
    aTogglePw.querySelector("i").classList.toggle("fa-eye");
    aTogglePw.querySelector("i").classList.toggle("fa-eye-slash");
  });
}

// add-user password strength meter
if (aPassword) {
  aPassword.addEventListener("input", ()=> {
    let val = aPassword.value;
    let strength = 0;
    if (val.match(/[a-z]/)) strength++;
    if (val.match(/[A-Z]/)) strength++;
    if (val.match(/[0-9]/)) strength++;
    if (val.match(/[^a-zA-Z0-9]/)) strength++;
    if (val.length >= 8) strength++;

    let width = (strength / 5) * 100;
    aPwFill.style.width = width + "%";
    if (strength <= 2) {
      aPwFill.style.background = "red";
      aPwNote.textContent = "Weak — add uppercase, numbers, special chars.";
    } else if (strength === 3) {
      aPwFill.style.background = "orange";
      aPwNote.textContent = "Medium — try adding more characters and symbols.";
    } else {
      aPwFill.style.background = "green";
      aPwNote.textContent = "Strong password.";
    }
  });
}

// toggle password visibility for change password form
const toggleCurrentPw = document.getElementById("toggle_current_pw");
const currentPassword = document.getElementById("current_password");
const toggleNewPw = document.getElementById("toggle_new_pw");
const newPassword = document.getElementById("new_password");
const toggleConfirmPw = document.getElementById("toggle_confirm_pw");
const confirmPassword = document.getElementById("confirm_password");

if (toggleCurrentPw) {
  toggleCurrentPw.addEventListener("click", ()=> {
    const show = currentPassword.type === "password";
    currentPassword.type = show ? "text" : "password";
    toggleCurrentPw.querySelector("i").classList.toggle("fa-eye");
    toggleCurrentPw.querySelector("i").classList.toggle("fa-eye-slash");
  });
}

if (toggleNewPw) {
  toggleNewPw.addEventListener("click", ()=> {
    const show = newPassword.type === "password";
    newPassword.type = show ? "text" : "password";
    toggleNewPw.querySelector("i").classList.toggle("fa-eye");
    toggleNewPw.querySelector("i").classList.toggle("fa-eye-slash");
  });
}

if (toggleConfirmPw) {
  toggleConfirmPw.addEventListener("click", ()=> {
    const show = confirmPassword.type === "password";
    confirmPassword.type = show ? "text" : "password";
    toggleConfirmPw.querySelector("i").classList.toggle("fa-eye");
    toggleConfirmPw.querySelector("i").classList.toggle("fa-eye-slash");
  });
}

// Optional: simple client-side feedback for add user submit (keeps server flow)
addUserForm.addEventListener("submit", (e)=> {
  // allow server to handle validation — but show waiting feedback
  modalMessages.innerHTML = '<div class="small-note">Creating user…</div>';
});

// ---------- Edit User Modal Logic ----------
const editUserModalBackdrop = document.getElementById("editUserModalBackdrop");
const closeEditModal = document.getElementById("closeEditModal");
const editUserForm = document.getElementById("editUserForm");
const editModalMessages = document.getElementById("editModalMessages");

// Open edit modal when edit button is clicked
document.addEventListener("click", (e) => {
  if (e.target.classList.contains("edit-user-btn")) {
    const btn = e.target;
    const userId = btn.dataset.id;
    const first = btn.dataset.first || '';
    const middle = btn.dataset.middle || '';
    const last = btn.dataset.last || '';
    const email = btn.dataset.email || '';
    const phone = btn.dataset.phone || '';
    const role = btn.dataset.role || 'staff';

    // Populate form
    document.getElementById("edit_user_id").value = userId;
    document.getElementById("e_first_name").value = first;
    document.getElementById("e_middle_name").value = middle;
    document.getElementById("e_last_name").value = last;
    document.getElementById("e_email").value = email;
    document.getElementById("e_phone").value = phone;
    document.getElementById("e_role").value = role;

    // Show modal
    editUserModalBackdrop.style.display = "flex";
    editUserModalBackdrop.setAttribute('aria-hidden', 'false');
  }
});

// Close edit modal
if (closeEditModal) {
  closeEditModal.addEventListener("click", () => {
    editUserModalBackdrop.style.display = "none";
    editUserModalBackdrop.setAttribute('aria-hidden', 'true');
    editModalMessages.innerHTML = "";
    editUserForm.reset();
  });
}

  // Optional: simple client-side feedback for edit user submit
  editUserForm.addEventListener("submit", (e) => {
    // allow server to handle validation — but show waiting feedback
    editModalMessages.innerHTML = '<div class="small-note">Updating user…</div>';
  });
</script>
</body>
</html>
