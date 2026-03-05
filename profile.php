<?php 
include('includes/auth_check.php');
include('includes/db_connect.php');

if (session_status() === PHP_SESSION_NONE) session_start();

$currentUsername = null;
if (!empty($_SESSION['user']))      $currentUsername = $_SESSION['user'];
elseif (!empty($_SESSION['user_name'])) $currentUsername = $_SESSION['user_name'];
if (!$currentUsername) { header('Location: index.php'); exit; }

function logActivity($conn, $userId, $action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Write to system_logs (read by logs.php)
    $conn->query("CREATE TABLE IF NOT EXISTS system_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(50) NOT NULL,
        description TEXT NOT NULL,
        user VARCHAR(100) NULL,
        ip_address VARCHAR(45) NULL,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Map action string to event_type
    $event_type = 'system';
    if (stripos($action, 'login')    !== false) $event_type = 'login';
    elseif (stripos($action, 'logout')   !== false) $event_type = 'logout';
    elseif (stripos($action, 'password') !== false) $event_type = 'password_change';
    elseif (stripos($action, 'profile')  !== false) $event_type = 'profile_update';
    elseif (stripos($action, 'device')   !== false) $event_type = 'device_control';
    elseif (stripos($action, 'user')     !== false) $event_type = 'profile_update';

    $username = $_SESSION['user'] ?? $_SESSION['fullname'] ?? 'Unknown';
    $sl = $conn->prepare("INSERT INTO system_logs (event_type, description, user, ip_address) VALUES (?,?,?,?)");
    if ($sl) { $sl->bind_param("ssss", $event_type, $action, $username, $ip); $sl->execute(); $sl->close(); }
}

$user = null;
$stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, fullname, email, phone, username, created_at FROM users WHERE username = ? LIMIT 1");
if ($stmt) { $stmt->bind_param("s", $currentUsername); $stmt->execute(); $res = $stmt->get_result(); if ($res && $res->num_rows > 0) $user = $res->fetch_assoc(); $stmt->close(); }

$errors = []; $success = "";

// ADD USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $sessionRole = $_SESSION['role'] ?? '';
    if ($sessionRole !== 'owner') { $errors[] = "Access denied."; } else {
        $a_first=$_POST['a_first_name']??''; $a_middle=$_POST['a_middle_name']??''; $a_last=$_POST['a_last_name']??'';
        $a_email=$_POST['a_email']??''; $a_phone=$_POST['a_phone']??''; $a_username=$_POST['a_username']??''; $a_password_raw=$_POST['a_password']??'';
        $a_role=in_array($_POST['a_role']??'staff',['owner','staff'])?$_POST['a_role']:'staff';
        if (!$a_first) $errors[]="First name required."; if (!$a_last) $errors[]="Last name required.";
        if (!$a_email) $errors[]="Email required."; if (!$a_username) $errors[]="Username required."; if (!$a_password_raw) $errors[]="Password required.";
        if (!preg_match('@[A-Z]@',$a_password_raw)||!preg_match('@[a-z]@',$a_password_raw)||!preg_match('@[0-9]@',$a_password_raw)||!preg_match('@[^\w]@',$a_password_raw)||strlen($a_password_raw)<8)
            $errors[]="Password must be 8+ chars with uppercase, lowercase, number, and special character.";
        if (empty($errors)) {
            $chk=$conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
            if ($chk) { $chk->bind_param("ss",$a_username,$a_email); $chk->execute(); $chk->store_result(); if ($chk->num_rows>0) $errors[]="Username or email already exists."; $chk->close(); }
        }
        if (empty($errors)) {
            $a_fullname=trim($a_first.' '.($a_middle?$a_middle.' ':'').$a_last); $a_hashed=password_hash($a_password_raw,PASSWORD_DEFAULT);
            $ins=$conn->prepare("INSERT INTO users (first_name,middle_name,last_name,fullname,email,phone,username,password,role,verified) VALUES (?,?,?,?,?,?,?,?,?,1)");
            if ($ins) { $ins->bind_param("sssssssss",$a_first,$a_middle,$a_last,$a_fullname,$a_email,$a_phone,$a_username,$a_hashed,$a_role);
                if ($ins->execute()) { $success="User created successfully."; if ($user) logActivity($conn,$user['id'],"Created user: {$a_username}"); } else $errors[]="Database error."; $ins->close(); }
        }
    }
}

// DELETE USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $sessionRole=$_SESSION['role']??''; if ($sessionRole!=='owner') { $errors[]="Access denied."; } else {
        $del_id=intval($_POST['delete_user_id']??0);
        if ($del_id<=0) { $errors[]="Invalid ID."; } else {
            $du=null; $s=$conn->prepare("SELECT id,username,role FROM users WHERE id=? LIMIT 1");
            if ($s) { $s->bind_param("i",$del_id); $s->execute(); $r=$s->get_result(); if ($r&&$r->num_rows>0) $du=$r->fetch_assoc(); $s->close(); }
            if (!$du) { $errors[]="User not found."; } elseif ($du['id']===$user['id']) { $errors[]="Cannot delete own account."; } else {
                $d=$conn->prepare("DELETE FROM users WHERE id=?");
                if ($d) { $d->bind_param("i",$del_id); if ($d->execute()) { $success="User deleted."; logActivity($conn,$user['id'],"Deleted: {$du['username']}"); } else $errors[]="DB error."; $d->close(); }
            }
        }
    }
}

// EDIT USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $sessionRole=$_SESSION['role']??''; if ($sessionRole!=='owner') { $errors[]="Access denied."; } else {
        $eid=intval($_POST['edit_user_id']??0); $ef=$_POST['e_first_name']??''; $em=$_POST['e_middle_name']??''; $el=$_POST['e_last_name']??''; $ee=$_POST['e_email']??''; $ep=$_POST['e_phone']??''; $er=in_array($_POST['e_role']??'staff',['owner','staff'])?$_POST['e_role']:'staff';
        if (!$ef) $errors[]="First name required."; if (!$el) $errors[]="Last name required."; if (!$ee) $errors[]="Email required."; if ($eid<=0) $errors[]="Invalid ID.";
        if (empty($errors)) {
            $eu=null; $s=$conn->prepare("SELECT id,username,role FROM users WHERE id=? LIMIT 1");
            if ($s) { $s->bind_param("i",$eid); $s->execute(); $r=$s->get_result(); if ($r&&$r->num_rows>0) $eu=$r->fetch_assoc(); $s->close(); }
            if (!$eu) { $errors[]="User not found."; } elseif ($eu['id']===$user['id']) { $errors[]="Cannot edit own account here."; } else {
                $chk=$conn->prepare("SELECT id FROM users WHERE email=? AND id!=? LIMIT 1");
                if ($chk) { $chk->bind_param("si",$ee,$eid); $chk->execute(); $chk->store_result(); if ($chk->num_rows>0) $errors[]="Email exists."; $chk->close(); }
                if (empty($errors)) {
                    $ef_full=trim($ef.' '.($em?$em.' ':'').$el);
                    $u=$conn->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,fullname=?,email=?,phone=?,role=? WHERE id=?");
                    if ($u) { $u->bind_param("sssssssi",$ef,$em,$el,$ef_full,$ee,$ep,$er,$eid); if ($u->execute()) { $success="User updated."; logActivity($conn,$user['id'],"Edited: {$eu['username']}"); } else $errors[]="DB error."; $u->close(); }
                }
            }
        }
    }
}

// APPROVE / REJECT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_user'])) {
    if (($_SESSION['role']??'')!=='owner') { $errors[]="Access denied."; } else {
        $aid=intval($_POST['approve_user_id']??0);
        $u=$conn->prepare("UPDATE users SET verified=1 WHERE id=?");
        if ($u) { $u->bind_param("i",$aid); if ($u->execute()) { $success="User approved."; logActivity($conn,$user['id'],"Approved ID: {$aid}"); } $u->close(); }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_user'])) {
    if (($_SESSION['role']??'')!=='owner') { $errors[]="Access denied."; } else {
        $rid=intval($_POST['reject_user_id']??0);
        $d=$conn->prepare("DELETE FROM users WHERE id=?");
        if ($d) { $d->bind_param("i",$rid); if ($d->execute()) { $success="User rejected."; logActivity($conn,$user['id'],"Rejected ID: {$rid}"); } $d->close(); }
    }
}

// UPDATE PROFILE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $user) {
    $first=trim($_POST['first_name']??''); $middle=trim($_POST['middle_name']??''); $last=trim($_POST['last_name']??''); $email=trim($_POST['email']??''); $phone=trim($_POST['phone']??'');
    if (!$first) $errors[]="First name required."; if (!$last) $errors[]="Last name required."; if (!$email) $errors[]="Email required."; if (!$phone) $errors[]="Phone required.";
    if (empty($errors)) {
        $fullname=trim($first.' '.($middle?$middle.' ':'').$last);
        $u=$conn->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,fullname=?,email=?,phone=? WHERE id=?");
        if ($u) { $u->bind_param("ssssssi",$first,$middle,$last,$fullname,$email,$phone,$user['id']); if ($u->execute()) { $success="Profile updated."; logActivity($conn,$user['id'],"Profile updated"); $stmt=$conn->prepare("SELECT id,first_name,middle_name,last_name,fullname,email,phone,username,created_at FROM users WHERE id=? LIMIT 1"); if ($stmt) { $stmt->bind_param("i",$user['id']); $stmt->execute(); $r=$stmt->get_result(); if ($r&&$r->num_rows>0) $user=$r->fetch_assoc(); $stmt->close(); } } else $errors[]="DB error."; $u->close(); }
    }
}

// CHANGE PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && $user) {
    $cp=$_POST['current_password']??''; $np=$_POST['new_password']??''; $pp=$_POST['confirm_password']??'';
    if (!$cp||!$np||!$pp) { $errors[]="All fields required."; } elseif ($np!==$pp) { $errors[]="Passwords do not match."; } else {
        $s=$conn->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
        if ($s) { $s->bind_param("i",$user['id']); $s->execute(); $s->bind_result($dbh); if ($s->fetch()) { $s->close();
            if (!password_verify($cp,$dbh)) { $errors[]="Current password incorrect."; } else {
                if (!preg_match('@[A-Z]@',$np)||!preg_match('@[a-z]@',$np)||!preg_match('@[0-9]@',$np)||!preg_match('@[^\w]@',$np)||strlen($np)<8) $errors[]="New password too weak.";
                else { $nh=password_hash($np,PASSWORD_DEFAULT); $u=$conn->prepare("UPDATE users SET password=? WHERE id=?"); if ($u) { $u->bind_param("si",$nh,$user['id']); if ($u->execute()) { $success="Password updated."; logActivity($conn,$user['id'],"Password changed"); } $u->close(); } }
            }
        } else { $s->close(); $errors[]="Cannot verify."; } }
    }
}

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

// Latest sensor
$latest_sensor = null;
$ls = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
if ($ls && $ls->num_rows > 0) $latest_sensor = $ls->fetch_assoc();

// Pending users
$pending_users = [];
if (isset($_SESSION['role']) && $_SESSION['role'] === 'owner') {
    $pu = $conn->query("SELECT id,fullname,username,email,role FROM users WHERE verified=0 ORDER BY id ASC");
    if ($pu) while ($r=$pu->fetch_assoc()) $pending_users[]=$r;
}

// Staff users
$staff_users = [];
if (isset($_SESSION['role']) && $_SESSION['role'] === 'owner') {
    $su = $conn->query("SELECT id,first_name,middle_name,last_name,fullname,username,email,phone,role FROM users WHERE role='staff' AND verified=1 ORDER BY id ASC");
    if ($su) while ($r=$su->fetch_assoc()) $staff_users[]=$r;
}

$isOwner = isset($_SESSION['role']) && $_SESSION['role'] === 'owner';
$initials = $user ? strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) : '?';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>System Profile</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --bg:       #f0f2f5;
  --surface:  #ffffff;
  --surface2: #f7f8fa;
  --border:   rgba(0,0,0,0.07);
  --text:     #0d1117;
  --muted:    #6e7681;
  --green:    #1a9e5c;
  --green-lt: #e6f7ef;
  --red:      #d93025;
  --red-lt:   #fdecea;
  --amber:    #b45309;
  --amber-lt: #fef3c7;
  --blue:     #1a6bba;
  --blue-lt:  #e8f1fb;
  --r:        12px;
  --shadow:   0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
  --shadow-lg:0 2px 8px rgba(0,0,0,0.08), 0 12px 40px rgba(0,0,0,0.06);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', system-ui, sans-serif; background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

/* ── SIDEBAR ── */
.sidebar { position: fixed; inset: 0 auto 0 0; width: 220px; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 50; }
.sidebar-logo { padding: 22px 20px 18px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); position: relative; }
    .sidebar-close{display:none;position:absolute;top:50%;right:14px;transform:translateY(-50%);width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:var(--surface2);align-items:center;justify-content:center;cursor:pointer;color:var(--muted);font-size:13px;transition:all .15s;}
    .sidebar-close:hover{background:var(--red-lt);color:var(--red);border-color:var(--red);}
.sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
.sidebar-logo-text { font-size: 14px; font-weight: 700; color: var(--text); line-height: 1.2; }
.sidebar-logo-sub  { font-size: 11px; color: var(--muted); }
.sidebar-nav{flex:1;padding:12px 10px;display:flex;flex-direction:column;gap:1px;overflow-y:auto;}
.sidebar-nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .15s;}
.sidebar-nav a i { width: 16px; text-align: center; font-size: 13px; }
.sidebar-nav a:hover  { background: var(--surface2); color: var(--text); }
.sidebar-nav a.active { background: var(--green-lt); color: var(--green); font-weight: 600; }
.sidebar-nav .nav-bottom { margin-top: auto; padding-top: 8px; border-top: 1px solid var(--border); }

/* ── MAIN ── */
.main { margin-left: 220px; min-height: 100vh; width: calc(100% - 220px); box-sizing: border-box; }
.topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 28px; height: 56px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 30; }
.topbar-title { font-size: 15px; font-weight: 700; color: var(--text); letter-spacing: -.2px; }
.topbar-time { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); background: var(--surface2); padding: 5px 12px; border-radius: 20px; border: 1px solid var(--border); }
.page { padding: 24px 28px; max-width: 1280px; width: 100%; box-sizing: border-box; }

/* ── FLASH MESSAGES ── */
.flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
.flash-ok  { background: var(--green-lt); color: var(--green); }
.flash-err { background: var(--red-lt);   color: var(--red);   }

/* ── LAYOUT ── */
.profile-layout { display: grid; grid-template-columns: 1fr 320px; gap: 16px; }
.profile-main { display: flex; flex-direction: column; gap: 16px; }
.profile-side { display: flex; flex-direction: column; gap: 16px; }

/* ── CARD ── */
.card { background: var(--surface); border-radius: var(--r); border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; }
.card-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--border); }
.card-title { font-size: 13px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.card-title .icon { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
.icon-green { background: var(--green-lt); color: var(--green); }
.icon-blue  { background: var(--blue-lt);  color: var(--blue);  }
.icon-amber { background: var(--amber-lt); color: var(--amber); }
.icon-red   { background: var(--red-lt);   color: var(--red);   }
.card-body  { padding: 20px; }

/* ── AVATAR + INFO ── */
.profile-hero { display: flex; align-items: center; gap: 16px; padding: 20px; border-bottom: 1px solid var(--border); }
.avatar { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, var(--green), #0d7a44); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; flex-shrink: 0; letter-spacing: -.5px; }
.profile-hero-info h2 { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.profile-hero-info span { font-size: 12px; color: var(--muted); font-family: 'DM Mono', monospace; }
.role-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-left: 8px; }
.role-owner { background: var(--amber-lt); color: var(--amber); }
.role-staff { background: var(--blue-lt);  color: var(--blue);  }

/* ── PROFILE FIELDS ── */
.field-list { display: flex; flex-direction: column; }
.field-row { display: flex; align-items: center; justify-content: space-between; padding: 11px 20px; border-bottom: 1px solid var(--border); }
.field-row:last-child { border-bottom: none; }
.field-label { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
.field-val   { font-size: 13px; color: var(--text); font-weight: 500; text-align: right; max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.field-val.mono { font-family: 'DM Mono', monospace; font-size: 12px; }

/* ── ACTION BUTTONS ── */
.action-bar { display: flex; gap: 8px; padding: 14px 20px; flex-wrap: wrap; border-top: 1px solid var(--border); }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all .15s; font-family: 'DM Sans', sans-serif; }
.btn-primary { background: var(--green); color: #fff; }
.btn-primary:hover { opacity: .88; }
.btn-ghost   { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--border); }
.btn-danger  { background: var(--red-lt); color: var(--red); border: 1px solid rgba(217,48,37,.15); }
.btn-danger:hover { background: var(--red); color: #fff; }
.btn-sm { padding: 5px 12px; font-size: 12px; }

/* ── INLINE FORMS ── */
.inline-form { padding: 20px; border-top: 1px solid var(--border); display: none; }
.form-section-title { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 14px; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.form-group label { font-size: 12px; font-weight: 600; color: var(--muted); }
.form-group input, .form-group select {
  width: 100%; padding: 9px 12px; border-radius: 8px;
  border: 1px solid var(--border); background: var(--surface2);
  font-size: 13px; color: var(--text); font-family: 'DM Sans', sans-serif;
  transition: border-color .15s;
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--green); background: var(--surface); }
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 38px; }
.pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted); font-size: 13px; }
.pw-eye:hover { color: var(--text); }
.strength-bar { height: 4px; background: var(--border); border-radius: 4px; overflow: hidden; margin-top: 6px; }
.strength-fill { height: 100%; width: 0; border-radius: 4px; transition: width .3s, background .3s; }
.strength-note { font-size: 11px; color: var(--muted); margin-top: 4px; }
.username-note { font-size: 11px; color: var(--muted); padding: 8px 0; }
.username-note strong { color: var(--text); font-family: 'DM Mono', monospace; }
.form-actions { display: flex; gap: 8px; margin-top: 4px; }

/* ── SIDE CARDS ── */
.info-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.info-row:last-child { border-bottom: none; }
.info-row .ik { font-size: 12px; font-weight: 600; color: var(--muted); }
.info-row .iv { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--text); }
.sensor-val { display: flex; align-items: baseline; gap: 3px; }
.sensor-val .num { font-size: 20px; font-weight: 700; font-family: 'DM Mono', monospace; color: var(--text); }
.sensor-val .unit { font-size: 12px; color: var(--muted); }
.sensor-row { display: flex; gap: 16px; padding: 14px 20px; border-bottom: 1px solid var(--border); }
.sensor-item { flex: 1; }
.sensor-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }

/* ── ACTIVITY LOG ── */
.empty-state { text-align: center; padding: 28px 20px; color: var(--muted); }
.empty-state i { font-size: 22px; display: block; margin-bottom: 6px; opacity: .35; }
.empty-state span { font-size: 12px; }

/* ── USER MGMT TABLES ── */
.full-width { grid-column: 1 / -1; }
table.mgmt { width: 100%; border-collapse: collapse; font-size: 13px; }
.mgmt thead th { text-align: left; padding: 9px 14px; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; background: var(--surface2); border-bottom: 1px solid var(--border); white-space: nowrap; }
.mgmt tbody td { padding: 10px 14px; border-bottom: 1px solid var(--border); }
.mgmt tbody tr:last-child td { border-bottom: none; }
.mgmt tbody tr:hover { background: var(--surface2); }
.mgmt td.name-col { font-weight: 600; }
.mgmt td.mono-col { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); }
.pill { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.pill-pending { background: var(--amber-lt); color: var(--amber); }
.pill-owner   { background: var(--amber-lt); color: var(--amber); }
.pill-staff   { background: var(--blue-lt);  color: var(--blue);  }
td.actions-col { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

/* ── MODAL ── */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; align-items: center; justify-content: center; z-index: 200; }
.modal-backdrop.open { display: flex; }
.modal { background: var(--surface); border-radius: var(--r); padding: 24px; width: 500px; max-width: 94vw; box-shadow: var(--shadow-lg); position: relative; max-height: 90vh; overflow-y: auto; }
.modal-close { position: absolute; top: 14px; right: 16px; background: none; border: none; font-size: 18px; color: var(--muted); cursor: pointer; }
.modal-close:hover { color: var(--text); }
.modal h3 { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 18px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.modal-msg { font-size: 12px; font-weight: 600; margin-top: 8px; }
.modal-msg.ok  { color: var(--green); }
.modal-msg.err { color: var(--red);   }

@media (max-width: 900px) {
  .profile-layout { grid-template-columns: 1fr; }
  .form-grid-2 { grid-template-columns: 1fr; }
}

    /* ============================================================
       RESPONSIVE / MOBILE
       ============================================================ */

    /* Hamburger button */
    .hamburger{
      display:none;position:fixed;top:4px;left:10px;z-index:500;
      width:38px;height:38px;border-radius:9px;
      background:var(--surface);border:1px solid var(--border);
      box-shadow:var(--shadow);
      align-items:center;justify-content:center;
      cursor:pointer;flex-direction:column;gap:4px;padding:9px;
      touch-action:manipulation;
      pointer-events:auto;
    }
    .hamburger span{display:block;width:16px;height:2px;background:var(--text);border-radius:2px;transition:all .25s;}
    .hamburger.open span:nth-child(1){transform:translateY(6px) rotate(45deg);}
    .hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
    .hamburger.open span:nth-child(3){transform:translateY(-6px) rotate(-45deg);}

    /* Overlay behind sidebar */
    .sidebar-overlay{
      display:none;position:fixed;inset:0;
      background:rgba(0,0,0,.4);z-index:99;
      backdrop-filter:blur(3px);
      -webkit-backdrop-filter:blur(3px);
    }
    .sidebar-overlay.open{display:block;}

    @media(max-width:768px){
      /* Show hamburger */
      .hamburger{display:flex;}
      .sidebar-close{display:flex;}

      /* Sidebar slides in */
      .sidebar{
        transform:translateX(-100%);
        transition:transform .28s cubic-bezier(.4,0,.2,1);
        z-index:100;
        box-shadow:4px 0 24px rgba(0,0,0,.12);
      }
      .sidebar.open{transform:translateX(0);}
      .hamburger.open{display:none!important;}
      .sidebar.open ~ * .hamburger, .hamburger.open{opacity:0;pointer-events:none;}

      /* Main fills full width */
      .main{margin-left:0!important;width:100%!important;overflow-x:hidden;}

      /* Topbar — room for hamburger on left */
      .topbar{padding:0 10px 0 58px;height:52px;gap:6px;position:fixed!important;top:0;left:0;right:0;z-index:40;}
      .topbar-title{font-size:14px;}
      .topbar-right{gap:6px;}
      .topbar-time{font-size:11px;padding:4px 10px;}
      .user-badge{padding:4px 10px;font-size:11px;}
      .user-badge .role-pill{display:none;}
      /* Hide button text labels on mobile, show icon only */
      .btn-label{display:none;}
      .btn{padding:7px 10px;gap:0;}
      .topbar .btn{min-width:34px;justify-content:center;}

      /* Page & grid padding */
      .page{padding:14px!important;}
      .grid{padding:14px!important;gap:10px!important;}

      /* All columns go full width */
      .col-3,.col-4,.col-5,.col-6,.col-7,.col-8,.col-9,.col-12{grid-column:span 12!important;}

      /* Stats row — 2 columns on tablet, handled below for phone */
      .stats-row{grid-template-columns:1fr 1fr!important;gap:10px;}

      /* Gauges — side by side and compact */
      .gauges-row{flex-direction:row;gap:8px;}
      .gauge-item{flex:1;padding:10px 6px;}
      .gauge-wrap{width:100px;height:62px;}
      .gauge-val{font-size:15px;}
      .gauge-label{font-size:10px;}
      .gauge-status{font-size:10px;}

      /* Cards */
      .card-header{flex-wrap:wrap;gap:8px;padding:12px 16px 10px;}
      .card-body{padding:12px 16px!important;}
      .card-title{font-size:12px;}

      /* Filters */
      .filter-bar{flex-direction:column;align-items:stretch!important;gap:8px;}
      .filter-bar select,.filter-bar input[type=date]{width:100%;font-size:12px;}

      /* Profile layout */
      .profile-layout{grid-template-columns:1fr!important;}
      .form-grid-2,.form-grid-3{grid-template-columns:1fr!important;}
      /* Profile page mobile fixes */
      .profile-hero{padding:14px;}
      .profile-hero-info h2{font-size:14px;}
      .field-row{padding:9px 14px;}
      .field-label{font-size:11px;}
      .field-val{font-size:12px;max-width:55%;}
      .field-val.mono{font-size:11px;}
      .action-bar{padding:10px 14px;gap:6px;}
      .action-bar .btn{flex:1;justify-content:center;}
      /* Manage users table scroll */
      .card .card-body{overflow-x:auto;}
      .tbl{min-width:420px;font-size:12px;}
      .tbl thead th,.tbl tbody td{padding:8px 10px;}

      /* Tabs */
      .tab-bar{overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}
      .tab{padding:6px 14px;font-size:12px;}

      /* Tables — horizontal scroll */
      div[style*="overflow-x"]{overflow-x:auto!important;-webkit-overflow-scrolling:touch;}
      table.tbl{font-size:12px;min-width:480px;}
      .tbl thead th,.tbl tbody td{padding:8px 10px;}

      /* Devices */
      .device-row{padding:8px 10px;}
      .device-name{font-size:12px;}
      .mode-row{padding:8px 12px;}

      /* Sensor status bar */
      .sensor-status-bar{flex-wrap:wrap;gap:6px;padding:10px 14px;}
      .sensor-reading{font-size:12px;}

      /* Stat cards */
      .stat-card{padding:12px 14px;gap:10px;}
      .stat-icon{width:36px;height:36px;font-size:14px;}
      .stat-val{font-size:18px;}
      .stat-label{font-size:10px;}
    }

    @media(max-width:480px){
      /* Single column stats on small phones */
      .stats-row{grid-template-columns:1fr!important;}

      /* Topbar compact */
      .topbar{height:48px;position:fixed!important;top:0;left:0;right:0;}
      .topbar-title{font-size:13px;}
      .topbar-time{display:none;}

      /* Gauges still side by side but smaller */
      .gauge-wrap{width:88px;height:55px;}
      .gauge-val{font-size:13px;}

      /* Page */
      .page{padding:10px!important;padding-top:58px!important;}
      .grid{padding:10px!important;gap:8px!important;}

      /* Buttons */
      .btn{padding:7px 12px;font-size:12px;}
      .btn-sm{padding:4px 8px;font-size:11px;}
    }

</style>
</head>
<body>
<button class="hamburger" id="hamburger" aria-label="Menu">
  <span></span><span></span><span></span>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>


<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="assets/img/logo.png" alt="logo">
    <div>
      <div class="sidebar-logo-text">MushroomOS</div>
      <div class="sidebar-logo-sub">Cultivation System</div>
    </div>
    <button class="sidebar-close" id="sidebarClose" aria-label="Close menu"><i class="fas fa-xmark"></i></button>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php" class="active"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<!-- ── MAIN ── -->
<main class="main">
  <header class="topbar">
    <span class="topbar-title">System Profile</span>
    <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
  </header>

  <div class="page">

    <!-- Flash messages -->
    <?php if ($success): ?>
      <div class="flash flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
      <div class="flash flash-err"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="profile-layout">

      <!-- ── LEFT COLUMN ── -->
      <div class="profile-main">

        <!-- Profile Card -->
        <div class="card">
          <!-- Hero -->
          <div class="profile-hero">
            <div class="avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="profile-hero-info">
              <h2>
                <?= htmlspecialchars($user['fullname'] ?? '—') ?>
                <span class="role-badge <?= $isOwner ? 'role-owner' : 'role-staff' ?>">
                  <i class="fas fa-<?= $isOwner ? 'crown' : 'user' ?>" style="font-size:10px;"></i>
                  <?= $isOwner ? 'Owner' : 'Staff' ?>
                </span>
              </h2>
              <span>@<?= htmlspecialchars($user['username'] ?? '') ?></span>
            </div>
          </div>

          <!-- Fields -->
          <div class="field-list">
            <div class="field-row">
              <span class="field-label">Email</span>
              <span class="field-val mono"><?= htmlspecialchars($user['email'] ?? '—') ?></span>
            </div>
            <div class="field-row">
              <span class="field-label">Phone</span>
              <span class="field-val mono"><?= htmlspecialchars($user['phone'] ?? '—') ?></span>
            </div>
            <div class="field-row">
              <span class="field-label">Username</span>
              <span class="field-val mono"><?= htmlspecialchars($user['username'] ?? '—') ?></span>
            </div>
            <div class="field-row">
              <span class="field-label">Member Since</span>
              <span class="field-val mono"><?= htmlspecialchars($user['created_at'] ?? '—') ?></span>
            </div>
          </div>

          <!-- Action Bar -->
          <div class="action-bar">
            <button class="btn btn-ghost" id="editToggle"><i class="fas fa-pen"></i><span class="btn-label"> Edit Profile</span></button>
            <button class="btn btn-ghost" id="passwdToggle"><i class="fas fa-lock"></i><span class="btn-label"> Change Password</span></button>
            <?php if ($isOwner): ?>
              <button class="btn btn-primary" id="openAddUser"><i class="fas fa-user-plus"></i><span class="btn-label"> Add User</span></button>
            <?php endif; ?>
          </div>

          <!-- Edit Profile Form -->
          <div class="inline-form" id="editForm">
            <form method="POST">
              <input type="hidden" name="update_profile" value="1">
              <p class="form-section-title">Edit Profile</p>
              <div class="form-grid-2">
                <div class="form-group">
                  <label>First Name</label>
                  <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label>Middle Name</label>
                  <input type="text" name="middle_name" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>">
                </div>
              </div>
              <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
              </div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label>Email</label>
                  <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label>Phone</label>
                  <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required>
                </div>
              </div>
              <p class="username-note">Username: <strong><?= htmlspecialchars($user['username'] ?? '') ?></strong> — cannot be changed</p>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                <button type="button" class="btn btn-ghost btn-sm" id="editCancel">Cancel</button>
              </div>
            </form>
          </div>

          <!-- Change Password Form -->
          <div class="inline-form" id="passwdForm">
            <form method="POST">
              <input type="hidden" name="change_password" value="1">
              <p class="form-section-title">Change Password</p>
              <div class="form-group">
                <label>Current Password</label>
                <div class="pw-wrap">
                  <input type="password" name="current_password" id="current_password" required>
                  <button type="button" class="pw-eye" data-target="current_password"><i class="fa fa-eye"></i></button>
                </div>
              </div>
              <div class="form-group">
                <label>New Password</label>
                <div class="pw-wrap">
                  <input type="password" name="new_password" id="new_password" required>
                  <button type="button" class="pw-eye" data-target="new_password"><i class="fa fa-eye"></i></button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="pw-fill"></div></div>
                <div class="strength-note" id="pw-note">8+ chars, uppercase, lowercase, number, special character.</div>
              </div>
              <div class="form-group">
                <label>Confirm Password</label>
                <div class="pw-wrap">
                  <input type="password" name="confirm_password" id="confirm_password" required>
                  <button type="button" class="pw-eye" data-target="confirm_password"><i class="fa fa-eye"></i></button>
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
                <button type="button" class="btn btn-ghost btn-sm" id="passwdCancel">Cancel</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Owner: Pending Approvals -->
        <?php if ($isOwner): ?>
        <div class="card">
          <div class="card-header">
            <div class="card-title">
              <span class="icon icon-amber"><i class="fas fa-user-clock"></i></span>
              Pending Approvals
            </div>
            <span style="font-size:11px;color:var(--muted);"><?= count($pending_users) ?> pending</span>
          </div>
          <?php if (empty($pending_users)): ?>
            <div class="empty-state"><i class="fas fa-check-circle"></i><span>No pending approvals.</span></div>
          <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="mgmt">
              <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($pending_users as $u): ?>
                <tr>
                  <td class="name-col"><?= htmlspecialchars($u['fullname']) ?></td>
                  <td class="mono-col"><?= htmlspecialchars($u['username']) ?></td>
                  <td class="mono-col"><?= htmlspecialchars($u['email']) ?></td>
                  <td><span class="pill pill-pending"><?= htmlspecialchars($u['role']) ?></span></td>
                  <td>
                    <div style="display:flex;gap:6px;">
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this user?')">
                        <input type="hidden" name="approve_user_id" value="<?= $u['id'] ?>">
                        <button type="submit" name="approve_user" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Approve</button>
                      </form>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Reject and remove this user?')">
                        <input type="hidden" name="reject_user_id" value="<?= $u['id'] ?>">
                        <button type="submit" name="reject_user" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Reject</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Owner: Manage Staff -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">
              <span class="icon icon-blue"><i class="fas fa-users"></i></span>
              Manage Users
            </div>
            <span style="font-size:11px;color:var(--muted);"><?= count($staff_users) ?> staff</span>
          </div>
          <?php if (empty($staff_users)): ?>
            <div class="empty-state"><i class="fas fa-users"></i><span>No staff users found.</span></div>
          <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="mgmt">
              <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($staff_users as $u): ?>
                <tr>
                  <td class="name-col"><?= htmlspecialchars($u['fullname']) ?></td>
                  <td class="mono-col"><?= htmlspecialchars($u['username']) ?></td>
                  <td class="mono-col"><?= htmlspecialchars($u['email']) ?></td>
                  <td><span class="pill pill-staff"><?= htmlspecialchars($u['role']) ?></span></td>
                  <td>
                    <?php if ($u['id'] !== $user['id']): ?>
                    <div style="display:flex;gap:6px;">
                      <button class="btn btn-ghost btn-sm edit-user-btn"
                        data-id="<?= $u['id'] ?>"
                        data-first="<?= htmlspecialchars($u['first_name']??'') ?>"
                        data-middle="<?= htmlspecialchars($u['middle_name']??'') ?>"
                        data-last="<?= htmlspecialchars($u['last_name']) ?>"
                        data-email="<?= htmlspecialchars($u['email']) ?>"
                        data-phone="<?= htmlspecialchars($u['phone']??'') ?>"
                        data-role="<?= htmlspecialchars($u['role']) ?>">
                        <i class="fas fa-pen"></i> Edit
                      </button>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?')">
                        <input type="hidden" name="delete_user_id" value="<?= $u['id'] ?>">
                        <button type="submit" name="delete_user" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                      </form>
                    </div>
                    <?php else: ?><span style="font-size:12px;color:var(--muted);">—</span><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div><!-- end profile-main -->

      <!-- ── RIGHT SIDEBAR ── -->
      <div class="profile-side">

        <!-- System Info -->
        <div class="card">
          <div class="card-header">
            <div class="card-title"><span class="icon icon-blue"><i class="fas fa-server"></i></span> System Info</div>
          </div>
          <div class="card-body" style="padding:0 20px;">
            <div class="info-row"><span class="ik">System</span><span class="iv">J.WHO Mushroom</span></div>
            <div class="info-row"><span class="ik">Version</span><span class="iv">1.0.0</span></div>
            <div class="info-row"><span class="ik">Database</span><span class="iv">mushroom_system</span></div>
          </div>
        </div>

        <!-- Latest Sensor -->
        <div class="card">
          <div class="card-header">
            <div class="card-title"><span class="icon icon-green"><i class="fas fa-microchip"></i></span> Latest Sensor</div>
          </div>
          <?php if ($latest_sensor): ?>
          <div class="sensor-row">
            <div class="sensor-item">
              <div class="sensor-label">Temperature</div>
              <div class="sensor-val">
                <span class="num"><?= number_format($latest_sensor['temperature'],1) ?></span>
                <span class="unit">°C</span>
              </div>
            </div>
            <div class="sensor-item">
              <div class="sensor-label">Humidity</div>
              <div class="sensor-val">
                <span class="num"><?= number_format($latest_sensor['humidity'],1) ?></span>
                <span class="unit">%</span>
              </div>
            </div>
          </div>
          <div class="field-row" style="padding:10px 20px;">
            <span class="field-label">Recorded At</span>
            <span class="field-val mono" style="font-size:11px;"><?= htmlspecialchars($latest_sensor['timestamp']) ?></span>
          </div>
          <?php else: ?>
            <div class="empty-state"><i class="fas fa-microchip"></i><span>No sensor data.</span></div>
          <?php endif; ?>
        </div>
      </div><!-- end profile-side -->
    </div><!-- end profile-layout -->
  </div><!-- end page -->
</main>

<!-- ── ADD USER MODAL ── -->
<div id="addUserModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="addUserModal">&times;</button>
    <h3><i class="fas fa-user-plus" style="color:var(--green);margin-right:8px;"></i>Add New User</h3>
    <form method="POST" id="addUserForm">
      <input type="hidden" name="add_user" value="1">
      <div class="form-grid-2">
        <div class="form-group"><label>First Name</label><input type="text" name="a_first_name" required></div>
        <div class="form-group"><label>Middle Name</label><input type="text" name="a_middle_name"></div>
      </div>
      <div class="form-group"><label>Last Name</label><input type="text" name="a_last_name" required></div>
      <div class="form-grid-2">
        <div class="form-group"><label>Email</label><input type="email" name="a_email" required></div>
        <div class="form-group"><label>Phone</label><input type="text" name="a_phone"></div>
      </div>
      <div class="form-group"><label>Username</label><input type="text" name="a_username" required></div>
      <div class="form-group">
        <label>Password</label>
        <div class="pw-wrap">
          <input type="password" name="a_password" id="a_password" required>
          <button type="button" class="pw-eye" data-target="a_password"><i class="fa fa-eye"></i></button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="a-pw-fill"></div></div>
        <div class="strength-note" id="a-pw-note">8+ chars, uppercase, lowercase, number, special character.</div>
      </div>
      <div class="form-group">
        <label>Role</label>
        <select name="a_role"><option value="staff" selected>Staff</option></select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="addUserModal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Create User</button>
      </div>
      <div id="addModalMsg" class="modal-msg"></div>
    </form>
  </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div id="editUserModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="editUserModal">&times;</button>
    <h3><i class="fas fa-pen" style="color:var(--blue);margin-right:8px;"></i>Edit User</h3>
    <form method="POST" id="editUserForm">
      <input type="hidden" name="edit_user" value="1">
      <input type="hidden" name="edit_user_id" id="edit_user_id">
      <div class="form-grid-2">
        <div class="form-group"><label>First Name</label><input type="text" name="e_first_name" id="e_first_name" required></div>
        <div class="form-group"><label>Middle Name</label><input type="text" name="e_middle_name" id="e_middle_name"></div>
      </div>
      <div class="form-group"><label>Last Name</label><input type="text" name="e_last_name" id="e_last_name" required></div>
      <div class="form-grid-2">
        <div class="form-group"><label>Email</label><input type="email" name="e_email" id="e_email" required></div>
        <div class="form-group"><label>Phone</label><input type="text" name="e_phone" id="e_phone"></div>
      </div>
      <div class="form-group">
        <label>Role</label>
        <select name="e_role" id="e_role"><option value="staff">Staff</option><option value="owner">Owner</option></select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="editUserModal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Update User</button>
      </div>
      <div id="editModalMsg" class="modal-msg"></div>
    </form>
  </div>
</div>

<script>
// ── PH Time ──
(function(){
  const el = document.getElementById('phTime');
  if (!el) return;
  let t = parseInt(el.dataset.serverTs, 10) || Date.now();
  const fmt = ms => new Date(ms).toLocaleString('en-PH',{
    timeZone:'Asia/Manila', month:'short', day:'numeric', year:'numeric',
    hour:'numeric', minute:'2-digit', second:'2-digit', hour12:true
  }).replace(',', ' —');
  el.textContent = fmt(t);
  setInterval(() => { t += 1000; el.textContent = fmt(t); }, 1000);
})();

// ── Password eye toggles ──
document.querySelectorAll('.pw-eye').forEach(btn => {
  btn.addEventListener('click', function() {
    const inp = document.getElementById(this.dataset.target);
    if (!inp) return;
    inp.type = inp.type === 'password' ? 'text' : 'password';
    this.querySelector('i').classList.toggle('fa-eye');
    this.querySelector('i').classList.toggle('fa-eye-slash');
  });
});

// ── Password strength ──
function bindStrength(inputId, fillId, noteId) {
  const inp = document.getElementById(inputId);
  const fill = document.getElementById(fillId);
  const note = document.getElementById(noteId);
  if (!inp || !fill || !note) return;
  inp.addEventListener('input', () => {
    const v = inp.value; let s = 0;
    if (v.match(/[a-z]/)) s++; if (v.match(/[A-Z]/)) s++; if (v.match(/[0-9]/)) s++; if (v.match(/[^\w]/)) s++; if (v.length >= 8) s++;
    const w = (s / 5) * 100;
    fill.style.width = w + '%';
    if (s <= 2)      { fill.style.background = 'var(--red)';   note.textContent = 'Weak — add uppercase, numbers, special chars.'; }
    else if (s === 3){ fill.style.background = 'var(--amber)'; note.textContent = 'Medium — add more variety.'; }
    else             { fill.style.background = 'var(--green)'; note.textContent = 'Strong password.'; }
  });
}
bindStrength('new_password', 'pw-fill', 'pw-note');
bindStrength('a_password', 'a-pw-fill', 'a-pw-note');

// ── Inline forms (edit profile / change password) ──
const editToggle  = document.getElementById('editToggle');
const passwdToggle= document.getElementById('passwdToggle');
const editForm    = document.getElementById('editForm');
const passwdForm  = document.getElementById('passwdForm');
const editCancel  = document.getElementById('editCancel');
const passwdCancel= document.getElementById('passwdCancel');

if (editToggle) editToggle.addEventListener('click', () => { editForm.style.display = editForm.style.display === 'block' ? 'none' : 'block'; passwdForm.style.display = 'none'; });
if (passwdToggle) passwdToggle.addEventListener('click', () => { passwdForm.style.display = passwdForm.style.display === 'block' ? 'none' : 'block'; editForm.style.display = 'none'; });
if (editCancel)   editCancel.addEventListener('click',   () => editForm.style.display = 'none');
if (passwdCancel) passwdCancel.addEventListener('click', () => passwdForm.style.display = 'none');

// ── Modal helpers ──
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

document.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', () => closeModal(el.dataset.close)));
document.querySelectorAll('.modal-backdrop').forEach(bd => bd.addEventListener('click', e => { if (e.target === bd) bd.classList.remove('open'); }));

const openAddUserBtn = document.getElementById('openAddUser');
if (openAddUserBtn) openAddUserBtn.addEventListener('click', () => openModal('addUserModal'));

document.getElementById('addUserForm')?.addEventListener('submit', () => {
  document.getElementById('addModalMsg').textContent = 'Creating user…';
  document.getElementById('addModalMsg').className = 'modal-msg ok';
});

// ── Edit user modal populate ──
document.addEventListener('click', e => {
  if (!e.target.closest('.edit-user-btn')) return;
  const btn = e.target.closest('.edit-user-btn');
  document.getElementById('edit_user_id').value  = btn.dataset.id;
  document.getElementById('e_first_name').value  = btn.dataset.first  || '';
  document.getElementById('e_middle_name').value = btn.dataset.middle || '';
  document.getElementById('e_last_name').value   = btn.dataset.last   || '';
  document.getElementById('e_email').value        = btn.dataset.email  || '';
  document.getElementById('e_phone').value        = btn.dataset.phone  || '';
  document.getElementById('e_role').value         = btn.dataset.role   || 'staff';
  openModal('editUserModal');
});

document.getElementById('editUserForm')?.addEventListener('submit', () => {
  document.getElementById('editModalMsg').textContent = 'Updating user…';
  document.getElementById('editModalMsg').className = 'modal-msg ok';
});

// ── Mobile sidebar toggle ──
(function(){
  const hamburger = document.getElementById('hamburger');
  const sidebar   = document.querySelector('.sidebar');
  const overlay   = document.getElementById('sidebarOverlay');
  if(!hamburger||!sidebar||!overlay) return;

  function openSidebar(){
    sidebar.classList.add('open');
    overlay.classList.add('open');
    hamburger.classList.add('open');
  }
  function closeSidebar(){
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    hamburger.classList.remove('open');
  }

  hamburger.addEventListener('click', ()=> sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
  const closeBtn = document.getElementById('sidebarClose');
  if(closeBtn) closeBtn.addEventListener('click', closeSidebar);
  overlay.addEventListener('click', closeSidebar);

  // Close sidebar when a nav link is tapped on mobile
  sidebar.querySelectorAll('.sidebar-nav a').forEach(a => {
    a.addEventListener('click', ()=>{ if(window.innerWidth<=768) closeSidebar(); });
  });
})();

</script>
</body>
</html>