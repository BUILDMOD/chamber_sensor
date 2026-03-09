<?php  
include('includes/db_connect.php');
include 'send_email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user'])) { header("Location: dashboard.php"); exit; }

$login_error  = "";
$reg_error    = "";
$reg_success  = "";

/* ── LOGIN ── */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modal_login'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT id,fullname,username,password,role,verified FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows == 1) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password'])) {
            if ($row['verified'] == 1 || $row['role'] == 'owner') {
                $_SESSION['user']     = $row['username'];
                $_SESSION['role']     = $row['role'];
                $_SESSION['fullname'] = $row['fullname'];
                $ip  = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $usr = $row['username'];
                $desc = "User '{$usr}' logged in successfully.";
                $evt = 'login';
                $ls = $conn->prepare("INSERT INTO system_logs (event_type,description,user,ip_address) VALUES (?,?,?,?)");
                if ($ls) { $ls->bind_param("ssss",$evt,$desc,$usr,$ip); $ls->execute(); $ls->close(); }
                header("Location: dashboard.php"); exit;
            } else { $login_error = "Your account is pending approval."; }
        } else { $login_error = "Invalid username or password."; }
    } else { $login_error = "Invalid username or password."; }
}

/* ── REGISTER ── */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modal_register'])) {
    $first  = mysqli_real_escape_string($conn, $_POST['first_name']);
    $middle = mysqli_real_escape_string($conn, $_POST['middle_name']);
    $last   = mysqli_real_escape_string($conn, $_POST['last_name']);
    $suffix = mysqli_real_escape_string($conn, $_POST['suffix'] ?? '');
    $fullname = trim($first." ".($middle?$middle." ":"").$last.($suffix?", ".$suffix:""));
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $phone    = mysqli_real_escape_string($conn, $_POST['phone']);
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password_raw = $_POST['reg_password'];
    $role = "staff";

    if (!preg_match("/^[A-Za-z\s]+$/", $first))          { $reg_error = "First name must contain letters only."; }
    elseif (!empty($middle) && !preg_match("/^[A-Za-z\s]+$/", $middle)) { $reg_error = "Middle name must contain letters only."; }
    elseif (!preg_match("/^[A-Za-z\s]+$/", $last))        { $reg_error = "Last name must contain letters only."; }
    elseif (!preg_match("/^[0-9]+$/", $phone))             { $reg_error = "Phone number must contain numbers only."; }
    elseif (!preg_match("/^[A-Za-z0-9]+$/", $username))   { $reg_error = "Username must contain letters and numbers only."; }

    if (empty($reg_error)) {
        $up = preg_match('@[A-Z]@',$password_raw);
        $lo = preg_match('@[a-z]@',$password_raw);
        $nm = preg_match('@[0-9]@',$password_raw);
        $sp = preg_match('@[^\w]@',$password_raw);
        $ml = strlen($password_raw) >= 8;
        if (!$up || !$lo || !$nm || !$sp || !$ml)
            $reg_error = "Password must be 8+ chars with uppercase, lowercase, number, and special character.";
    }

    if (empty($reg_error)) {
        $password = password_hash($password_raw, PASSWORD_DEFAULT);
        $check = $conn->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->bind_param("ss",$username,$email);
        $check->execute(); $check->store_result();
        if ($check->num_rows > 0) {
            $reg_error = "Username or Email already exists.";
        } else {
            $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) NOT NULL DEFAULT '' AFTER last_name");
            $stmt = $conn->prepare("INSERT INTO users (first_name,middle_name,last_name,suffix,fullname,email,phone,username,password,role,verified) VALUES (?,?,?,?,?,?,?,?,?,?,0)");
            if ($stmt) {
                $stmt->bind_param("ssssssssss",$first,$middle,$last,$suffix,$fullname,$email,$phone,$username,$password,$role);
                if (!$stmt->execute()) {
                    $reg_error = "Database error: " . $stmt->error;
                } else {

                // ── Welcome email to registrant (only if insert succeeded) ──
                $subject = "Welcome to MushroomOS — J.WHO Mushroom Farm";
                $body = "<div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>"
                      . "<div style='background:#2b4d30;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>"
                      . "<h2 style='color:#c8e8b8;margin:0;font-size:20px;'>&#127812; MushroomOS</h2>"
                      . "<p style='color:rgba(200,232,184,0.6);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>"
                      . "</div>"
                      . "<div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>"
                      . "<p style='font-size:15px;margin:0 0 8px;'>Hello <strong>" . $fullname . "</strong>,</p>"
                      . "<p style='color:#555;font-size:13px;line-height:1.6;margin:0 0 16px;'>Your account has been successfully registered in the <strong>MushroomOS Cultivation System</strong>.</p>"
                      . "<table style='width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px;'>"
                      . "<tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;width:40%;'>Username</td><td style='padding:8px 12px;font-weight:600;'>" . $username . "</td></tr>"
                      . "<tr><td style='padding:8px 12px;color:#6e7681;'>Email</td><td style='padding:8px 12px;font-weight:600;'>" . $email . "</td></tr>"
                      . "<tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;'>Role</td><td style='padding:8px 12px;font-weight:600;'>Staff</td></tr>"
                      . "</table>"
                      . "<p style='background:#fff3e0;border-left:4px solid #e65100;padding:10px 14px;border-radius:4px;color:#bf360c;margin:0 0 16px;'>&#9203; Your account is <strong>pending approval</strong> by the owner before you can log in.</p>"
                      . "<p style='font-size:12px;color:#aaa;margin:0;'>If you did not register for this account, please ignore this email.</p>"
                      . "<hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>"
                      . "<p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm</p>"
                      . "</div></div>";
                $emailResult = sendEmail($email, $subject, $body);
                if ($emailResult !== "SUCCESS") error_log("Reg email failed for $email: " . $emailResult);

                // ── Notify owner of new pending staff ──
                $ownerQ = $conn->query("SELECT email, fullname FROM users WHERE role='owner' LIMIT 1");
                if ($ownerQ && $ownerQ->num_rows > 0) {
                    $owner = $ownerQ->fetch_assoc();
                    $ownerSubject = "MushroomOS — New Staff Registration Pending Approval";
                    $ownerBody = "<div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>"
                               . "<div style='background:#2b4d30;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>"
                               . "<h2 style='color:#c8e8b8;margin:0;font-size:20px;'>&#127812; MushroomOS</h2>"
                               . "<p style='color:rgba(200,232,184,0.6);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>"
                               . "</div>"
                               . "<div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>"
                               . "<p style='font-size:15px;margin:0 0 8px;'>Hello <strong>" . $owner['fullname'] . "</strong>,</p>"
                               . "<p style='color:#555;font-size:13px;line-height:1.6;margin:0 0 16px;'>A new staff account is waiting for your approval in MushroomOS.</p>"
                               . "<table style='width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px;'>"
                               . "<tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;width:40%;'>Full Name</td><td style='padding:8px 12px;font-weight:600;'>" . $fullname . "</td></tr>"
                               . "<tr><td style='padding:8px 12px;color:#6e7681;'>Username</td><td style='padding:8px 12px;font-weight:600;'>" . $username . "</td></tr>"
                               . "<tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;'>Email</td><td style='padding:8px 12px;font-weight:600;'>" . $email . "</td></tr>"
                               . "</table>"
                               . "<p style='color:#555;font-size:13px;margin:0 0 16px;'>Log in to MushroomOS and go to <strong>System Profile</strong> to approve or reject this account.</p>"
                               . "<hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>"
                               . "<p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm</p>"
                               . "</div></div>";
                    sendEmail($owner['email'], $ownerSubject, $ownerBody);
                }

                $reg_success = "Account created! An admin will approve your access.";
                } // end if execute succeeded
            } else { $reg_error = "Database error: " . $conn->error; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>J WHO? — Mushroom Incubation System</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --ivory:#f6f1e7;--ivory2:#ede7d6;--ink:#18201a;
  --charcoal:#2e3830;--forest:#2b4d30;--fern:#4a7a50;
  --moss:#7aab70;--moss-lt:#c2dabb;--amber:#c8883a;
  --line:rgba(24,32,26,0.12);--r:12px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{background:var(--ink);color:var(--ink);font-family:'Outfit',sans-serif;overflow-x:hidden;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;z-index:9998;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23g)' opacity='0.04'/%3E%3C/svg%3E");opacity:.6;}

/* NAV */
nav{position:fixed;top:0;left:0;right:0;z-index:600;height:66px;display:flex;align-items:center;justify-content:space-between;padding:0 44px;background:rgba(18,26,18,0.55);backdrop-filter:blur(22px) saturate(1.2);border-bottom:1px solid rgba(255,255,255,0.08);min-width:0;}
.nav-logo{display:flex;align-items:center;gap:13px;text-decoration:none;flex-shrink:0;min-width:0;}
.nav-logo img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(194,218,187,0.4);box-shadow:0 2px 10px rgba(0,0,0,.3);}
.logo-text{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;letter-spacing:-0.01em;line-height:1;}
.logo-sub{font-family:'Outfit',sans-serif;display:block;font-size:9px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--moss);margin-top:2px;}
.nav-right{display:flex;align-items:center;gap:10px;flex-shrink:0;margin-left:auto;}
.nav-clock{display:flex;flex-direction:column;align-items:flex-end;margin-right:6px;line-height:1.3;}
.nc-time{font-size:13px;font-weight:600;color:rgba(255,255,255,.9);font-variant-numeric:tabular-nums;letter-spacing:.02em;}
.nc-date{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.03em;}
.nav-sep{width:1px;height:28px;background:rgba(255,255,255,.12);flex-shrink:0;margin:0 4px;}
.nl{font-size:13px;font-weight:500;color:rgba(255,255,255,.75);text-decoration:none;padding:8px 18px;border-radius:100px;border:1px solid rgba(255,255,255,.22);transition:background .2s,color .2s,border-color .2s;white-space:nowrap;cursor:pointer;background:transparent;font-family:'Outfit',sans-serif;}
.nl:hover{background:rgba(255,255,255,.08);color:#fff;border-color:rgba(255,255,255,.4);}
.nl-cta{background:var(--forest)!important;color:#fff!important;border-color:var(--fern)!important;font-weight:600;box-shadow:0 4px 16px rgba(43,77,48,.35);}
.nl-cta:hover{background:var(--fern)!important;transform:translateY(-1px);box-shadow:0 8px 24px rgba(43,77,48,.45)!important;border-color:var(--moss)!important;}

/* HERO */
.hero{position:relative;min-height:100vh;display:flex;align-items:center;overflow:hidden;}
.hero-bg{position:absolute;inset:0;z-index:0;background:url('assets/img/bg-mushroom.jpg') center/cover no-repeat;}
.hero-overlay{position:absolute;inset:0;z-index:1;background:linear-gradient(to right,rgba(18,32,18,0.90) 0%,rgba(18,32,18,0.65) 50%,rgba(18,32,18,0.22) 100%),linear-gradient(to top,rgba(18,32,18,0.5) 0%,transparent 50%);}
.hero-deco-letter{position:absolute;top:-60px;left:-20px;z-index:2;font-family:'Cormorant Garamond',serif;font-size:clamp(300px,32vw,460px);font-weight:300;font-style:italic;color:rgba(255,255,255,0.025);line-height:1;pointer-events:none;user-select:none;letter-spacing:-.04em;}
.spore{position:absolute;border-radius:50%;z-index:2;background:rgba(122,171,112,.09);animation:sporeFloat var(--d) ease-in-out var(--delay) infinite alternate;pointer-events:none;}
@keyframes sporeFloat{from{transform:translateY(0) scale(1);}to{transform:translateY(-20px) scale(1.06);}}
.hero-logo-watermark{position:absolute;right:4%;top:50%;transform:translateY(-50%);z-index:3;pointer-events:none;user-select:none;width:clamp(300px,38vw,560px);height:clamp(300px,38vw,560px);animation:fadeIn 1.2s .3s both;}
.hero-logo-watermark img{width:100%;height:100%;object-fit:contain;opacity:0.82;filter:brightness(1.1) drop-shadow(0 0 60px rgba(122,171,112,0.45)) drop-shadow(0 0 20px rgba(122,171,112,0.3));border-radius:50%;}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
.hero-inner{position:relative;z-index:5;width:100%;padding:100px 80px 80px;max-width:820px;}
.hero-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:10px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--moss);margin-bottom:20px;animation:fadeUp .8s .15s both;}
.hero-eyebrow::before{content:'';width:24px;height:1px;background:var(--moss);}
.hero-title{font-family:'Cormorant Garamond',serif;font-size:clamp(60px,7vw,100px);font-weight:300;line-height:1.0;letter-spacing:-.03em;color:#fff;margin-bottom:28px;animation:fadeUp .8s .25s both;}
.hero-title em{font-style:italic;color:var(--moss);}
.hero-desc{font-size:15px;line-height:1.8;color:rgba(255,255,255,.5);max-width:480px;margin-bottom:44px;animation:fadeUp .8s .35s both;}
.hero-btns{display:flex;gap:14px;align-items:center;animation:fadeUp .8s .45s both;}
.btn-hero-primary{display:inline-flex;align-items:center;gap:10px;padding:14px 32px;background:var(--amber);color:#fff;font-family:'Outfit',sans-serif;font-size:14px;font-weight:600;border-radius:var(--r);text-decoration:none;box-shadow:0 4px 20px rgba(200,136,58,.4);transition:all .25s;letter-spacing:.01em;border:1px solid transparent;cursor:pointer;}
.btn-hero-primary:hover{background:#d4963e;transform:translateY(-2px);box-shadow:0 12px 32px rgba(200,136,58,.45);}
.btn-hero-ghost{display:inline-flex;align-items:center;gap:8px;padding:13px 24px;border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.8);font-size:14px;font-weight:400;border-radius:var(--r);text-decoration:none;transition:all .2s;backdrop-filter:blur(4px);background:rgba(255,255,255,.04);}
.btn-hero-ghost:hover{border-color:var(--moss);color:var(--moss);background:rgba(122,171,112,.06);}

/* MODAL */
.modal-backdrop{position:fixed;inset:0;z-index:1000;background:rgba(10,18,10,0.75);backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .3s ease;}
.modal-backdrop.open{opacity:1;pointer-events:all;}
.modal{background:rgba(20,30,20,0.97);border:1px solid rgba(255,255,255,0.10);border-radius:22px;padding:36px 32px 28px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.65);transform:translateY(28px) scale(0.97);transition:transform .38s cubic-bezier(.22,1,.36,1),opacity .3s;opacity:0;position:relative;overflow-y:auto;max-height:90vh;}
.modal-backdrop.open .modal{transform:translateY(0) scale(1);opacity:1;}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;color:rgba(255,255,255,.3);font-size:20px;cursor:pointer;transition:color .2s;line-height:1;padding:4px;}
.modal-close:hover{color:rgba(255,255,255,.7);}
.modal-logo{display:flex;align-items:center;gap:10px;margin-bottom:20px;}
.modal-logo img{width:34px;height:34px;border-radius:50%;border:2px solid rgba(194,218,187,.3);}
.modal-logo-text{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:#fff;line-height:1;}
.modal-logo-sub{font-size:9px;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--moss);display:block;margin-top:2px;}
.modal-title{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:400;color:#fff;margin-bottom:4px;letter-spacing:-.01em;}
.modal-sub{font-size:12px;color:rgba(255,255,255,.35);margin-bottom:20px;}
.m-error{font-size:12px;color:#f08080;background:rgba(192,57,43,.15);border:1px solid rgba(192,57,43,.25);border-radius:8px;padding:9px 12px;margin-bottom:14px;}
.m-success{font-size:12px;color:#9affb5;background:rgba(74,124,74,.2);border:1px solid rgba(74,124,74,.35);border-radius:8px;padding:9px 12px;margin-bottom:14px;}
.mfield{margin-bottom:11px;}
.mfield label{display:block;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:5px;}
.mfield-wrap{position:relative;}
.mfield input,.mfield select{width:100%;padding:10px 36px 10px 13px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.11);border-radius:10px;color:#fff;font-family:'Outfit',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s,background .2s;-webkit-appearance:none;}
.mfield input::placeholder{color:rgba(255,255,255,.2);}
.mfield input:focus,.mfield select:focus{border-color:rgba(122,171,112,.5);background:rgba(255,255,255,.09);}
.mfield select option{background:#1e3020;color:#fff;}
.mfield-icon{position:absolute;right:11px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.22);font-size:12px;pointer-events:none;}
.toggle-pw{pointer-events:all;cursor:pointer;transition:color .2s;}
.toggle-pw:hover{color:var(--moss);}
.mgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.str-bar{height:3px;background:rgba(255,255,255,.08);border-radius:3px;margin-top:5px;overflow:hidden;}
.str-fill{height:100%;width:0;border-radius:3px;transition:width .3s,background .3s;}
.modal-btn{width:100%;padding:12px;background:linear-gradient(135deg,var(--forest),var(--fern));border:none;border-radius:10px;color:#fff;font-family:'Outfit',sans-serif;font-size:14px;font-weight:600;cursor:pointer;margin-top:8px;transition:opacity .2s,transform .2s;letter-spacing:.02em;}
.modal-btn:hover{opacity:.88;transform:translateY(-1px);}
.modal-switch{margin-top:18px;text-align:center;font-size:12px;color:rgba(255,255,255,.3);}
.modal-switch a{color:var(--moss);text-decoration:none;cursor:pointer;}
.modal-switch a:hover{color:var(--moss-lt);}
#loginModal .modal{max-width:360px;}
#registerModal .modal{max-width:420px;}
.modal-info-card{margin-top:14px;border:1px solid rgba(122,171,112,0.2);border-radius:10px;overflow:hidden;cursor:pointer;transition:border-color .2s;background:rgba(122,171,112,0.05);}
.modal-info-card:hover{border-color:rgba(122,171,112,0.35);}
.mic-header{display:flex;align-items:center;gap:8px;padding:9px 13px;user-select:none;}
.mic-icon{color:var(--moss);font-size:13px;flex-shrink:0;}
.mic-label{flex:1;font-size:11px;font-weight:600;color:rgba(255,255,255,.45);letter-spacing:.04em;text-transform:uppercase;}
.mic-arrow{color:rgba(255,255,255,.25);font-size:10px;transition:transform .25s;}
.modal-info-card.expanded .mic-arrow{transform:rotate(180deg);}
.mic-body{max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;padding:0 13px;}
.modal-info-card.expanded .mic-body{max-height:120px;padding:0 13px 12px;}
.mic-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-top:1px solid rgba(255,255,255,.06);font-size:11.5px;color:rgba(255,255,255,.38);line-height:1.5;}
.mic-row:first-child{border-top:none;}
.mic-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;letter-spacing:.06em;padding:2px 8px;border-radius:100px;flex-shrink:0;white-space:nowrap;}
.mic-staff{background:rgba(122,171,112,.15);color:var(--moss);}
.mic-owner{background:rgba(200,136,58,.15);color:var(--amber);}
.mic-text strong{color:rgba(255,255,255,.6);}

/* MARQUEE */
.marquee-band{background:var(--forest);padding:13px 0;overflow:hidden;position:relative;z-index:2;}
.marquee-track{display:flex;width:max-content;animation:marquee 28s linear infinite;}
@keyframes marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.marquee-item{display:flex;align-items:center;gap:14px;padding:0 28px;white-space:nowrap;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);}
.marquee-dot{width:3px;height:3px;border-radius:50%;background:var(--moss);}

/* FEATURES */
.features{padding:80px 44px;background:var(--ivory2);border-top:1px solid var(--line);}
.features-top{max-width:1100px;margin:0 auto 52px;display:flex;align-items:flex-end;justify-content:space-between;gap:32px;}
.feat-heading{font-family:'Cormorant Garamond',serif;font-size:clamp(36px,4vw,54px);font-weight:300;line-height:1.1;color:var(--ink);letter-spacing:-.02em;}
.feat-heading em{font-style:italic;color:var(--fern);}
.feat-sub{max-width:320px;font-size:13.5px;line-height:1.75;color:#6a7a6a;}
.feat-grid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:16px;overflow:hidden;}
.feat-cell{background:var(--ivory);padding:32px 28px;transition:background .2s;}
.feat-cell:hover{background:#fff;}
.feat-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:18px;}
.fc1{background:rgba(200,136,58,.12);}.fc2{background:rgba(74,122,80,.12);}.fc3{background:rgba(43,77,48,.1);}.fc4{background:rgba(160,80,48,.1);}
.feat-name{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:400;color:var(--ink);margin-bottom:10px;letter-spacing:-.01em;}
.feat-desc{font-size:13px;line-height:1.75;color:#6a7a6a;}

/* FOOTER */
footer{background:var(--charcoal);padding:28px 44px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,.06);}
.foot-copy{font-size:12px;color:rgba(255,255,255,.35);letter-spacing:.03em;}
.foot-brand{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;font-style:italic;color:rgba(255,255,255,.5);}

@keyframes fadeUp{from{opacity:0;transform:translateY(22px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:1024px){.hero-logo-watermark{width:240px;height:240px;right:3%;}.feat-grid{grid-template-columns:1fr 1fr;}.features-top{flex-direction:column;align-items:flex-start;}}
@media(max-width:768px){.hero-inner{padding:90px 36px 60px;max-width:100%;}.hero-title{font-size:56px;}.hero-logo-watermark{display:none;}.hero-overlay{background:linear-gradient(to bottom,rgba(10,18,10,0.80) 0%,rgba(10,18,10,0.60) 60%,rgba(10,18,10,0.75) 100%);}nav{padding:0 20px;}.nc-date{display:none;}.feat-grid{grid-template-columns:1fr;}.features{padding:56px 24px;}}
@media(max-width:540px){.hero-title{font-size:44px;}.nav-clock{display:none;}.nav-sep{display:none;}footer{padding:20px 24px;flex-direction:column;gap:8px;text-align:center;}.mgrid{grid-template-columns:1fr;}}
@media(max-width:480px){
  nav{padding:0 14px;}
  .nav-logo img{width:32px;height:32px;}
  .logo-text{font-size:16px;}
  .nav-clock{display:none;}
  .nav-sep{display:none;}
  .nl{padding:6px 11px;font-size:11px;letter-spacing:0;}
  .hero{align-items:flex-start;}
  .hero-inner{padding:100px 22px 52px;max-width:100%;text-align:left;}
  .hero-eyebrow{justify-content:flex-start;margin-bottom:100px;}
  .hero-title{font-size:65px;line-height:1.03;text-align:left;margin-bottom:30px;}
  .hero-desc{font-size:13.5px;max-width:100%;text-align:left;margin-bottom:150px;}
  .hero-btns{flex-direction:column;align-items:stretch;gap:100px;}
  .btn-hero-primary{justify-content:center;padding:15px 20px;font-size:14px;width:100%;}
  .features{padding:40px 18px;}
  .feat-grid{grid-template-columns:1fr;}
  .features-top{flex-direction:column;}
  .feat-heading{font-size:32px;}
  footer{padding:18px 20px;flex-direction:column;gap:6px;text-align:center;}
  .modal-backdrop{padding:16px 14px;align-items:center;}
  .modal{border-radius:18px;padding:28px 18px 22px;max-height:88vh;}
  #loginModal .modal,#registerModal .modal{max-width:100%;}
  .mgrid{grid-template-columns:1fr;}
  .modal-title{font-size:22px;}
  .mfield input,.mfield select{font-size:14px;padding:11px 36px 11px 13px;}
  .modal-btn{padding:13px;font-size:14px;}
}
@media(max-width:380px){
  .hero-title{font-size:32px;}
  .logo-text{font-size:14px;}
}

/* SCROLL-TO-TOP */
.scroll-top{position:fixed;bottom:28px;right:28px;z-index:900;width:44px;height:44px;background:var(--forest);border:1px solid var(--fern);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 20px rgba(0,0,0,.35);opacity:0;pointer-events:none;transform:translateY(12px);transition:opacity .3s,transform .3s,background .2s;color:#fff;font-size:16px;}
.scroll-top.show{opacity:1;pointer-events:all;transform:translateY(0);}
.scroll-top:hover{background:var(--fern);box-shadow:0 8px 28px rgba(43,77,48,.5);}
</style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="homepage.php" class="nav-logo">
    <img src="assets/img/logo.png" alt="J.WHO Logo">
    <div><span class="logo-text">J WHO?</span><span class="logo-sub">Mushroom Incubation</span></div>
  </a>
  <div class="nav-right">
    <div class="nav-clock">
      <span class="nc-time" id="phTime">--:-- --</span>
      <span class="nc-date" id="phDate">--- --, ----</span>
    </div>
    <div class="nav-sep"></div>
    <a href="docs.php" class="nl">How It Works</a>
    <button class="nl nl-cta" onclick="openLogin()">Log In →</button>
  </div>
</nav>

<!-- LOGIN MODAL -->
<div class="modal-backdrop" id="loginModal" onclick="handleBackdrop(event,'loginModal')">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('loginModal')">&times;</button>
    <div class="modal-logo">
      <img src="assets/img/logo.png" alt="">
      <div><span class="modal-logo-text">J WHO?</span><span class="modal-logo-sub">Mushroom Incubation</span></div>
    </div>
    <div class="modal-title">Welcome back</div>
    <div class="modal-sub">Sign in to access your dashboard</div>
    <?php if (!empty($login_error)): ?>
      <div class="m-error"><?= htmlspecialchars($login_error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="modal_login" value="1">
      <div class="mfield">
        <label>Username</label>
        <div class="mfield-wrap">
          <input type="text" name="username" placeholder="Enter your username" required autocomplete="username">
          <span class="mfield-icon"><i class="fa fa-user"></i></span>
        </div>
      </div>
      <div class="mfield">
        <label>Password</label>
        <div class="mfield-wrap">
          <input type="password" id="loginPw" name="password" placeholder="Enter your password" required autocomplete="current-password">
          <span class="mfield-icon toggle-pw" onclick="togglePw('loginPw','loginPwEye')"><i class="fa fa-eye" id="loginPwEye"></i></span>
        </div>
      </div>
      <button type="submit" class="modal-btn">Sign In</button>
    </form>
    <div class="modal-switch">
      Don't have an account? <a onclick="switchTo('registerModal')">Create one</a>
    </div>
    <div class="modal-info-card" onclick="this.classList.toggle('expanded')" title="Click to expand">
      <div class="mic-header">
        <span class="mic-icon"><i class="fa fa-circle-info"></i></span>
        <span class="mic-label">Account Access Info</span>
        <span class="mic-arrow"><i class="fa fa-chevron-down"></i></span>
      </div>
      <div class="mic-body">
        <div class="mic-row">
          <span class="mic-badge mic-staff"><i class="fa fa-user"></i> Staff</span>
          <span class="mic-text">Requires <strong>owner approval</strong> before you can log in.</span>
        </div>
        <div class="mic-row">
          <span class="mic-badge mic-owner"><i class="fa fa-crown"></i> Owner</span>
          <span class="mic-text">Has <strong>immediate access</strong> — no approval needed.</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- REGISTER MODAL -->
<div class="modal-backdrop" id="registerModal" onclick="handleBackdrop(event,'registerModal')">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('registerModal')">&times;</button>
    <div class="modal-logo">
      <img src="assets/img/logo.png" alt="">
      <div><span class="modal-logo-text">J WHO?</span><span class="modal-logo-sub">Mushroom Incubation</span></div>
    </div>
    <div class="modal-title">Create account</div>
    <div class="modal-sub">Join J WHO? Mushroom Incubation System</div>
    <?php if (!empty($reg_error)): ?>
      <div class="m-error"><?= htmlspecialchars($reg_error) ?></div>
    <?php endif; ?>
    <?php if (!empty($reg_success)): ?>
      <div class="m-success"><?= htmlspecialchars($reg_success) ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="modal_register" value="1">
      <div class="mgrid">
        <div class="mfield">
          <label>First Name</label>
          <div class="mfield-wrap">
            <input type="text" name="first_name" placeholder="First name" required oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')">
          </div>
        </div>
        <div class="mfield">
          <label>Middle Name</label>
          <div class="mfield-wrap">
            <input type="text" name="middle_name" placeholder="Middle (optional)" oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')">
          </div>
        </div>
      </div>
      <div class="mfield">
        <label>Last Name</label>
        <div class="mfield-wrap">
          <input type="text" name="last_name" placeholder="Last name" required oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')">
        </div>
      </div>
      <div class="mfield">
        <label>Suffix <span style="font-weight:400;opacity:.5;">(e.g. Jr., Sr., III)</span></label>
        <div class="mfield-wrap">
          <input type="text" name="suffix" placeholder="Jr., Sr., III (optional)" oninput="this.value=this.value.replace(/[^A-Za-z0-9.\s]/g,'')" maxlength="10">
        </div>
      </div>
      <div class="mgrid">
        <div class="mfield">
          <label>Email</label>
          <div class="mfield-wrap">
            <input type="email" name="email" placeholder="Email address" required>
            <span class="mfield-icon"><i class="fa fa-envelope"></i></span>
          </div>
        </div>
        <div class="mfield">
          <label>Phone</label>
          <div class="mfield-wrap">
            <input type="text" name="phone" placeholder="Phone number" required maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            <span class="mfield-icon"><i class="fa fa-phone"></i></span>
          </div>
        </div>
      </div>
      <div class="mfield">
        <label>Username</label>
        <div class="mfield-wrap">
          <input type="text" name="username" placeholder="Choose a username" required oninput="this.value=this.value.replace(/[^A-Za-z0-9]/g,'')">
          <span class="mfield-icon"><i class="fa fa-user"></i></span>
        </div>
      </div>
      <div class="mfield">
        <label>Password</label>
        <div class="mfield-wrap">
          <input type="password" id="regPw" name="reg_password" placeholder="Create a password" required oninput="checkStrength(this.value)">
          <span class="mfield-icon toggle-pw" onclick="togglePw('regPw','regPwEye')"><i class="fa fa-eye" id="regPwEye"></i></span>
        </div>
        <div class="str-bar"><div class="str-fill" id="strFill"></div></div>
      </div>
      <button type="submit" class="modal-btn">Create Account</button>
    </form>
    <div class="modal-switch">
      Already have an account? <a onclick="switchTo('loginModal')">Sign in</a>
    </div>
  </div>
</div>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-overlay"></div>
  <div class="hero-deco-letter">M</div>
  <div class="spore" style="width:130px;height:130px;top:20%;left:42%;--d:8s;--delay:0s;"></div>
  <div class="spore" style="width:60px;height:60px;top:60%;left:22%;--d:10s;--delay:1.5s;"></div>
  <div class="spore" style="width:180px;height:180px;top:35%;left:58%;--d:12s;--delay:.6s;"></div>
  <div class="hero-logo-watermark"><img src="assets/img/logo.png" alt=""></div>
  <div class="hero-inner">
    <div class="hero-eyebrow">Smart Cultivation System</div>
    <h1 class="hero-title">Precision <em>incubation</em><br>for the harvest.</h1>
    <p class="hero-desc">A modern monitoring platform built for mushroom growers — real-time environment control, automated device management, and data-driven insights in one unified system.</p>
    <div class="hero-btns">
      <button class="btn-hero-primary" onclick="openLogin()">Access Dashboard →</button>
    </div>
  </div>
</section>

<!-- MARQUEE -->
<div class="marquee-band" aria-hidden="true">
  <div class="marquee-track">
    <?php $items=['Temperature Monitoring','Humidity Control','Auto Device Management','Growth Tracking','Harvest Analytics','Camera Analysis','Email Alerts','Data Reports']; ?>
    <?php for($r=0;$r<4;$r++) foreach($items as $it): ?>
      <div class="marquee-item"><span><?= $it ?></span><span class="marquee-dot"></span></div>
    <?php endforeach; ?>
  </div>
</div>

<!-- FEATURES -->
<section class="features">
  <div class="features-top">
    <h2 class="feat-heading">Everything you need<br>for <em>better yields</em></h2>
    <p class="feat-sub">From environment sensors to automated harvest detection — all connected in a single dashboard built for mushroom growers.</p>
  </div>
  <div class="feat-grid">
    <div class="feat-cell"><div class="feat-ico fc1">🌡️</div><div class="feat-name">Live Monitoring</div><p class="feat-desc">Real-time temperature and humidity tracking with instant alerts when conditions drift outside optimal ranges.</p></div>
    <div class="feat-cell"><div class="feat-ico fc2">⚙️</div><div class="feat-name">Auto Control</div><p class="feat-desc">Intelligent automation adjusts misting, fans, heaters, and sprayers based on live sensor data.</p></div>
    <div class="feat-cell"><div class="feat-ico fc3">📊</div><div class="feat-name">Growth Reports</div><p class="feat-desc">Track mushroom growth stages, harvest counts, and environmental trends over time with visual analytics.</p></div>
    <div class="feat-cell"><div class="feat-ico fc4">📷</div><div class="feat-name">Camera</div><p class="feat-desc">Image analysis estimates mushroom diameter and determines harvest readiness automatically.</p></div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <span class="foot-copy">© 2025 J WHO? Mushroom Incubation System — All Rights Reserved</span>
  <span class="foot-brand">J WHO? MIS</span>
</footer>

<!-- SCROLL TO TOP -->
<button class="scroll-top" id="scrollTop" aria-label="Back to top" title="Back to top">&#8679;</button>

<script>
function updatePHTime(){
  const now=new Date(),ph=new Date(now.toLocaleString('en-US',{timeZone:'Asia/Manila'}));
  let h=ph.getHours(),m=ph.getMinutes(),s=ph.getSeconds();
  const ampm=h>=12?'PM':'AM'; h=h%12||12;
  const pad=n=>String(n).padStart(2,'0');
  document.getElementById('phTime').textContent=`${pad(h)}:${pad(m)}:${pad(s)} ${ampm}`;
  const mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  document.getElementById('phDate').textContent=`${mo[ph.getMonth()]} ${ph.getDate()}, ${ph.getFullYear()}`;
}
updatePHTime(); setInterval(updatePHTime,1000);

function openModal(id){ document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function handleBackdrop(e,id){ if(e.target===document.getElementById(id)) closeModal(id); }
function switchTo(id){
  ['loginModal','registerModal'].forEach(m=>document.getElementById(m).classList.remove('open'));
  document.getElementById(id).classList.add('open');
  document.body.style.overflow='hidden';
}
function openLogin(){ openModal('loginModal'); }

document.addEventListener('keydown',e=>{
  if(e.key==='Escape') ['loginModal','registerModal'].forEach(id=>closeModal(id));
});

<?php if(!empty($login_error)): ?>
document.addEventListener('DOMContentLoaded',()=>openModal('loginModal'));
<?php endif; ?>
<?php if(!empty($reg_error)||!empty($reg_success)): ?>
document.addEventListener('DOMContentLoaded',()=>openModal('registerModal'));
<?php endif; ?>

function togglePw(inputId,iconId){
  const inp=document.getElementById(inputId),ico=document.getElementById(iconId);
  const show=inp.type==='password';
  inp.type=show?'text':'password';
  ico.classList.toggle('fa-eye',!show);
  ico.classList.toggle('fa-eye-slash',show);
}

function checkStrength(v){
  let s=0;
  if(v.match(/[a-z]/))s++; if(v.match(/[A-Z]/))s++;
  if(v.match(/[0-9]/))s++; if(v.match(/[^a-zA-Z0-9]/))s++;
  if(v.length>=8)s++;
  const fill=document.getElementById('strFill');
  fill.style.width=(s/5*100)+'%';
  fill.style.background=s<=2?'#e74c3c':s===3?'#e67e22':'#4a7c4a';
}

(function(){
  const btn=document.getElementById('scrollTop');
  window.addEventListener('scroll',()=>btn.classList.toggle('show',window.scrollY>320));
  btn.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
})();
</script>
</body>
</html>