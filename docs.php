<?php  
include 'includes/db_connect.php';
include 'send_email.php';
session_start();

$login_error = "";
$reg_error   = "";
$reg_success = "";

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

    if (!preg_match("/^[A-Za-z\s]+$/", $first))        { $reg_error = "First name must contain letters only."; }
    elseif (!empty($middle) && !preg_match("/^[A-Za-z\s]+$/", $middle)) { $reg_error = "Middle name must contain letters only."; }
    elseif (!preg_match("/^[A-Za-z\s]+$/", $last))      { $reg_error = "Last name must contain letters only."; }
    elseif (!preg_match("/^[0-9]+$/", $phone))           { $reg_error = "Phone number must contain numbers only."; }
    elseif (!preg_match("/^[A-Za-z0-9]+$/", $username)) { $reg_error = "Username must contain letters and numbers only."; }

    if (empty($reg_error)) {
        $up = preg_match('@[A-Z]@',$password_raw);
        $lo = preg_match('@[a-z]@',$password_raw);
        $nm = preg_match('@[0-9]@',$password_raw);
        $sp = preg_match('@[^\w]@',$password_raw);
        $ml = strlen($password_raw) >= 8;
        if (!$up||!$lo||!$nm||!$sp||!$ml)
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
            // Auto-add suffix column if it doesn't exist
            $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) NOT NULL DEFAULT '' AFTER last_name");
            $stmt = $conn->prepare("INSERT INTO users (first_name,middle_name,last_name,suffix,fullname,email,phone,username,password,role,verified) VALUES (?,?,?,?,?,?,?,?,?,?,0)");
            if ($stmt) {
                $stmt->bind_param("ssssssssss",$first,$middle,$last,$suffix,$fullname,$email,$phone,$username,$password,$role);
                $stmt->execute();
                $subject = "Welcome to J.WHO Mushroom System!";
                $body = "Hello <b>$fullname</b>,<br><br>Your account has been created.<br><b>Username:</b> $username<br><b>Role:</b> $role<br><br>Thank you!<br><b>J.WHO Mushroom Farm</b>";
                $emailResult = sendEmail($email,$subject,$body);
                if ($emailResult !== "SUCCESS") error_log("Reg email failed for $email: ".$emailResult);
                $reg_success = "Account created! An admin will approve your access.";
            } else { $reg_error = "Database error: ".$conn->error; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Guide — J WHO? Mushroom Incubation</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --ivory:#f6f1e7;--ivory2:#ede7d6;--ink:#18201a;
  --charcoal:#2e3830;--forest:#2b4d30;--fern:#4a7a50;
  --moss:#7aab70;--moss-lt:#c2dabb;--amber:#c8883a;
  --amber-lt:#f5dfa8;--line:rgba(24,32,26,0.12);--r:12px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{background:var(--ivory);color:var(--ink);font-family:'Outfit',sans-serif;overflow-x:hidden;min-height:100vh;}
body::after{content:'';position:fixed;inset:0;z-index:9998;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23g)' opacity='0.04'/%3E%3C/svg%3E");opacity:.6;}

/* NAV */
nav{position:fixed;top:0;left:0;right:0;z-index:600;height:66px;display:flex;align-items:center;justify-content:space-between;padding:0 44px;background:rgba(18,26,18,0.55);backdrop-filter:blur(22px) saturate(1.2);border-bottom:1px solid rgba(255,255,255,0.08);min-width:0;}
.nav-logo{display:flex;align-items:center;gap:13px;text-decoration:none;flex-shrink:0;min-width:0;}
.nav-logo img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(194,218,187,0.4);box-shadow:0 2px 10px rgba(0,0,0,.3);}
.logo-text{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:600;color:#fff;letter-spacing:-.01em;line-height:1;}
.logo-sub{display:block;font-size:9px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--moss);margin-top:2px;}
.nav-right{display:flex;align-items:center;gap:10px;flex-shrink:0;margin-left:auto;}
.nav-clock{display:flex;flex-direction:column;align-items:flex-end;margin-right:6px;line-height:1.3;}
.nc-time{font-size:13px;font-weight:600;color:rgba(255,255,255,.9);font-variant-numeric:tabular-nums;letter-spacing:.02em;}
.nc-date{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.03em;}
.nav-sep{width:1px;height:28px;background:rgba(255,255,255,.12);flex-shrink:0;margin:0 4px;}
.nl{font-size:13px;font-weight:500;color:rgba(255,255,255,.75);text-decoration:none;padding:8px 18px;border-radius:100px;border:1px solid rgba(255,255,255,.22);transition:background .2s,color .2s,border-color .2s;white-space:nowrap;cursor:pointer;background:transparent;font-family:'Outfit',sans-serif;}
.nl:hover{background:rgba(255,255,255,.08);color:#fff;border-color:rgba(255,255,255,.4);}
.nl-cta{background:var(--forest)!important;color:#fff!important;border-color:var(--fern)!important;font-weight:600;box-shadow:0 4px 16px rgba(43,77,48,.35);}
.nl-cta:hover{background:var(--fern)!important;transform:translateY(-1px);box-shadow:0 8px 24px rgba(43,77,48,.45)!important;border-color:var(--moss)!important;}

/* ── SHARED MODAL BASE ── */
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

/* Modal info card */
.modal-info-card{
  margin-top:14px;
  border:1px solid rgba(122,171,112,0.2);
  border-radius:10px;
  overflow:hidden;
  cursor:pointer;
  transition:border-color .2s;
  background:rgba(122,171,112,0.05);
}
.modal-info-card:hover{border-color:rgba(122,171,112,0.35);}
.mic-header{
  display:flex;align-items:center;gap:8px;
  padding:9px 13px;
  user-select:none;
}
.mic-icon{color:var(--moss);font-size:13px;flex-shrink:0;}
.mic-label{flex:1;font-size:11px;font-weight:600;color:rgba(255,255,255,.45);letter-spacing:.04em;text-transform:uppercase;}
.mic-arrow{color:rgba(255,255,255,.25);font-size:10px;transition:transform .25s;}
.modal-info-card.expanded .mic-arrow{transform:rotate(180deg);}
.mic-body{
  max-height:0;overflow:hidden;
  transition:max-height .3s ease, padding .3s ease;
  padding:0 13px;
}
.modal-info-card.expanded .mic-body{
  max-height:120px;
  padding:0 13px 12px;
}
.mic-row{
  display:flex;align-items:center;gap:10px;
  padding:6px 0;
  border-top:1px solid rgba(255,255,255,.06);
  font-size:11.5px;
  color:rgba(255,255,255,.38);
  line-height:1.5;
}
.mic-row:first-child{border-top:none;}
.mic-badge{
  display:inline-flex;align-items:center;gap:4px;
  font-size:10px;font-weight:700;letter-spacing:.06em;
  padding:2px 8px;border-radius:100px;
  flex-shrink:0;white-space:nowrap;
}
.mic-staff{background:rgba(122,171,112,.15);color:var(--moss);}
.mic-owner{background:rgba(200,136,58,.15);color:var(--amber);}
.mic-text strong{color:rgba(255,255,255,.6);}

#loginModal .modal{max-width:360px;}
#registerModal .modal{max-width:420px;}

/* PAGE HEADER */
.page-header{position:relative;min-height:340px;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden;}
.ph-bg{position:absolute;inset:0;z-index:0;background:url('assets/img/bg-mushroom.jpg') center/cover no-repeat;}
.ph-overlay{position:absolute;inset:0;z-index:1;background:linear-gradient(to right,rgba(18,32,18,0.88) 0%,rgba(18,32,18,0.55) 60%,rgba(18,32,18,0.3) 100%),linear-gradient(to top,rgba(18,32,18,0.7) 0%,transparent 55%);}
.ph-deco{position:absolute;bottom:-60px;right:-30px;z-index:2;font-family:'Cormorant Garamond',serif;font-size:320px;font-weight:300;font-style:italic;color:rgba(255,255,255,.04);line-height:1;pointer-events:none;user-select:none;}
.ph-inner{position:relative;z-index:3;max-width:1200px;margin:0 auto;width:100%;padding:100px 44px 52px;display:flex;align-items:flex-end;justify-content:space-between;gap:40px;}
.ph-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:10px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--moss);margin-bottom:16px;animation:fadeUp .7s .1s both;}
.ph-eyebrow::before{content:'';width:20px;height:1px;background:var(--moss);}
.ph-title{font-family:'Cormorant Garamond',serif;font-size:clamp(44px,5.5vw,74px);font-weight:300;line-height:1.05;letter-spacing:-.03em;color:#fff;animation:fadeUp .7s .2s both;}
.ph-title em{font-style:italic;color:var(--moss);}
.ph-sub{font-size:14px;line-height:1.75;color:rgba(255,255,255,.5);max-width:480px;margin-top:12px;animation:fadeUp .7s .3s both;}
.ph-count{flex-shrink:0;text-align:right;animation:fadeUp .7s .35s both;}
.ph-num{font-family:'Cormorant Garamond',serif;font-size:120px;font-weight:300;font-style:italic;color:rgba(255,255,255,.07);line-height:1;user-select:none;}
.ph-num-lbl{font-size:10px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.25);margin-top:-12px;}

/* TAB BAR */
.tab-bar{background:var(--charcoal);border-bottom:1px solid rgba(255,255,255,.08);overflow-x:auto;position:sticky;top:66px;z-index:100;scrollbar-width:none;}
.tab-bar::-webkit-scrollbar{display:none;}
.tab-inner{max-width:none;padding:0 44px;display:flex;gap:0;width:max-content;min-width:100%;}
.tab-link{display:flex;align-items:center;gap:7px;padding:14px 18px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.4);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:color .2s,border-color .2s;flex-shrink:0;}
.tab-link:last-child{padding-right:44px;}
.tab-link:hover{color:rgba(255,255,255,.75);}
.tab-link.active{color:var(--moss);border-bottom-color:var(--moss);}
.tab-num{font-size:10px;background:rgba(255,255,255,.08);border-radius:100px;padding:1px 7px;font-variant-numeric:tabular-nums;}

/* MAIN LAYOUT */
.main-wrap{max-width:1200px;margin:0 auto;padding:0 44px 80px;display:grid;grid-template-columns:260px 1fr;gap:40px;align-items:start;}
.sidebar{padding-top:40px;position:sticky;top:calc(66px + 50px);max-height:calc(100vh - 140px);overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--line) transparent;}
.sidebar::-webkit-scrollbar{width:3px;}
.sidebar::-webkit-scrollbar-thumb{background:var(--line);border-radius:2px;}
.sidebar-label{font-size:9px;font-weight:700;letter-spacing:.25em;text-transform:uppercase;color:#8a9485;margin-bottom:14px;}
.sidebar-list{list-style:none;display:flex;flex-direction:column;gap:1px;}
.sl a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;font-size:13px;font-weight:400;color:#6a7a6a;text-decoration:none;transition:all .18s;}
.sl a:hover{background:var(--ivory2);color:var(--ink);}
.sl a.active{background:rgba(74,122,80,.1);color:var(--fern);font-weight:600;}
.sl-num{font-size:10px;color:var(--amber);font-variant-numeric:tabular-nums;width:18px;flex-shrink:0;}
.sl-ico{font-size:14px;width:20px;text-align:center;}
.content{padding-top:40px;}

/* STEP CARDS */
.step-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;margin-bottom:16px;opacity:0;transform:translateY(14px);transition:opacity .4s ease,transform .4s ease,box-shadow .2s;scroll-margin-top:132px;}
.step-card.visible{opacity:1;transform:translateY(0);}
.step-card:hover{box-shadow:0 8px 32px rgba(24,32,26,.07);}
.step-card-header{display:flex;align-items:stretch;border-bottom:1px solid var(--line);}
.step-number{display:flex;align-items:center;justify-content:center;min-width:68px;font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:300;font-style:italic;color:#fff;background:var(--forest);padding:16px;flex-shrink:0;}
.step-header-content{flex:1;padding:18px 22px 16px;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.step-tag{font-size:9px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--amber);}
.step-title{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:400;color:var(--ink);letter-spacing:-.01em;margin-top:3px;}
.step-icon-wrap{width:44px;height:44px;border-radius:12px;background:var(--ivory);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.step-body{padding:24px 26px;font-size:14px;line-height:1.8;color:#5a6a5a;}
.step-body ul{list-style:none;display:flex;flex-direction:column;gap:10px;}
.step-body li{padding-left:18px;position:relative;}
.step-body li::before{content:'—';position:absolute;left:0;color:var(--moss-lt);font-size:12px;top:4px;}
.step-body strong{color:var(--ink);font-weight:600;}
.callout{margin-top:18px;padding:14px 18px;background:var(--amber-lt);border-left:3px solid var(--amber);border-radius:0 10px 10px 0;font-size:13px;color:#7a5020;line-height:1.65;}

/* FOOTER */
footer{background:var(--charcoal);padding:28px 44px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,.06);}
.foot-copy{font-size:12px;color:rgba(255,255,255,.35);}
.foot-brand{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;font-style:italic;color:rgba(255,255,255,.5);}

@keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:1024px){.main-wrap{grid-template-columns:1fr;padding:0 32px 60px;}.sidebar{display:none;}.ph-inner{flex-direction:column;gap:0;padding:100px 32px 44px;}.ph-count{display:none;}.tab-inner{padding:0 24px;}}
@media(max-width:768px){nav{padding:0 20px;}.nc-date{display:none;}.ph-inner{padding:90px 24px 40px;}.ph-title{font-size:44px;}.main-wrap{padding:0 24px 60px;}}
@media(max-width:540px){.nav-clock{display:none;}.nav-sep{display:none;}footer{padding:20px 24px;flex-direction:column;gap:8px;text-align:center;}.mgrid{grid-template-columns:1fr;}}
/* ── MOBILE FIXES ── */
@media(max-width:480px){
  /* Nav — icon-only buttons on small screens */
  nav{padding:0 14px;}
  .nav-logo img{width:32px;height:32px;}
  .logo-text{font-size:16px;}
  .nav-clock{display:none;}
  .nav-sep{display:none;}
  .nl{padding:6px 11px;font-size:11px;letter-spacing:0;}

  /* Hero */
  .hero-inner{padding:80px 22px 52px;}
  .hero-title{font-size:38px;line-height:1.05;}
  .hero-desc{font-size:13.5px;max-width:100%;}
  .hero-btns{flex-direction:column;align-items:stretch;gap:10px;}
  .btn-hero-primary{justify-content:center;padding:14px 20px;font-size:14px;}

  /* Features */
  .features{padding:40px 18px;}
  .feat-grid{grid-template-columns:1fr;}
  .features-top{flex-direction:column;}
  .feat-heading{font-size:32px;}

  /* Footer */
  footer{padding:18px 20px;flex-direction:column;gap:6px;text-align:center;}

  /* Modals — comfortable on small phones */
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




/* ── SCROLL-TO-TOP FAB ── */
.scroll-top{
  position:fixed;
  bottom:28px;right:28px;
  z-index:900;
  width:44px;height:44px;
  background:var(--forest);
  border:1px solid var(--fern);
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
  box-shadow:0 4px 20px rgba(0,0,0,.35);
  opacity:0;pointer-events:none;
  transform:translateY(12px);
  transition:opacity .3s,transform .3s,background .2s;
  color:#fff;font-size:16px;
}
.scroll-top.show{opacity:1;pointer-events:all;transform:translateY(0);}
.scroll-top:hover{background:var(--fern);box-shadow:0 8px 28px rgba(43,77,48,.5);}
</style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="homepage.php" class="nav-logo">
    <img src="assets/img/logo.png" alt="Logo">
    <div><span class="logo-text">J WHO?</span><span class="logo-sub">Mushroom Incubation</span></div>
  </a>
  <div class="nav-right">
    <div class="nav-clock">
      <span class="nc-time" id="phTime">--:-- --</span>
      <span class="nc-date" id="phDate">--- --, ----</span>
    </div>
    <div class="nav-sep"></div>
    <a href="homepage.php" class="nl">← Home</a>
    <button class="nl nl-cta" onclick="openLogin()">Log In →</button>
  </div>
</nav>



<!-- ══════════ LOGIN MODAL ══════════ -->
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
    <div class="modal-switch">Don't have an account? <a onclick="switchTo('registerModal')">Create one</a></div>
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

<!-- ══════════ REGISTER MODAL ══════════ -->
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
          <div class="mfield-wrap"><input type="text" name="first_name" placeholder="First name" required oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
        </div>
        <div class="mfield">
          <label>Middle Name</label>
          <div class="mfield-wrap"><input type="text" name="middle_name" placeholder="Middle (optional)" oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
        </div>
      </div>
      <div class="mfield">
        <label>Last Name</label>
        <div class="mfield-wrap"><input type="text" name="last_name" placeholder="Last name" required oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"></div>
      </div>
      <div class="mfield">
        <label>Suffix <span style="font-weight:400;opacity:.5;">(e.g. Jr., Sr., III)</span></label>
        <div class="mfield-wrap">
          <input type="text" name="suffix" placeholder="Optional">
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
    <div class="modal-switch">Already have an account? <a onclick="switchTo('loginModal')">Sign in</a></div>
  </div>
</div>

<!-- PAGE HEADER -->
<header class="page-header">
  <div class="ph-bg"></div>
  <div class="ph-overlay"></div>
  <div class="ph-deco">G</div>
  <div class="ph-inner">
    <div>
      <div class="ph-eyebrow">📖 System Documentation</div>
      <h1 class="ph-title">The Complete<br><em>Usage Guide</em></h1>
      <p class="ph-sub">Everything you need to know — from first login to camera analysis, automation faults, and harvest analytics, in ten clear sections.</p>
    </div>
    <div class="ph-count">
      <div class="ph-num">10</div>
      <div class="ph-num-lbl">Sections</div>
    </div>
  </div>
</header>

<!-- TAB BAR -->
<div class="tab-bar">
  <div class="tab-inner">
    <a href="#s1"  class="tab-link active"><span class="tab-num">01</span> Setup</a>
    <a href="#s2"  class="tab-link"><span class="tab-num">02</span> Dashboard</a>
    <a href="#s3"  class="tab-link"><span class="tab-num">03</span> Camera</a>
    <a href="#s4"  class="tab-link"><span class="tab-num">04</span> Automation</a>
    <a href="#s5"  class="tab-link"><span class="tab-num">05</span> Records</a>
    <a href="#s6"  class="tab-link"><span class="tab-num">06</span> Reports</a>
    <a href="#s7"  class="tab-link"><span class="tab-num">07</span> Logs</a>
    <a href="#s8"  class="tab-link"><span class="tab-num">08</span> Settings</a>
    <a href="#s9"  class="tab-link"><span class="tab-num">09</span> Profile</a>
    <a href="#s10" class="tab-link"><span class="tab-num">10</span> Safety</a>
  </div>
</div>

<!-- MAIN -->
<div class="main-wrap">
  <aside class="sidebar">
    <div class="sidebar-label">Contents</div>
    <ul class="sidebar-list">
      <li class="sl"><a href="#s1"><span class="sl-num">01</span><span class="sl-ico">🚀</span>Getting Started</a></li>
      <li class="sl"><a href="#s2"><span class="sl-num">02</span><span class="sl-ico">📊</span>Dashboard</a></li>
      <li class="sl"><a href="#s3"><span class="sl-num">03</span><span class="sl-ico">📷</span>Camera Analysis</a></li>
      <li class="sl"><a href="#s4"><span class="sl-num">04</span><span class="sl-ico">⚙️</span>Automation</a></li>
      <li class="sl"><a href="#s5"><span class="sl-num">05</span><span class="sl-ico">🍄</span>Growth Records</a></li>
      <li class="sl"><a href="#s6"><span class="sl-num">06</span><span class="sl-ico">📈</span>Reports</a></li>
      <li class="sl"><a href="#s7"><span class="sl-num">07</span><span class="sl-ico">📋</span>Logs</a></li>
      <li class="sl"><a href="#s8"><span class="sl-num">08</span><span class="sl-ico">🔧</span>Settings</a></li>
      <li class="sl"><a href="#s9"><span class="sl-num">09</span><span class="sl-ico">👤</span>Profile</a></li>
      <li class="sl"><a href="#s10"><span class="sl-num">10</span><span class="sl-ico">🛡️</span>Safety</a></li>
    </ul>
  </aside>

  <div class="content">

    <!-- 01 GETTING STARTED -->
    <div class="step-card" id="s1">
      <div class="step-card-header"><div class="step-number">01</div><div class="step-header-content"><div><div class="step-tag">Getting Started</div><div class="step-title">First-time Setup</div></div><div class="step-icon-wrap">🚀</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Registration:</strong> Click "Create an account" on the login page. Fill in your full name, email, phone, username, and a strong password (8+ chars, uppercase, lowercase, number, and special character required).</li>
        <li><strong>Account Roles:</strong> Staff accounts require <strong>owner approval</strong> before they can log in. Owner accounts have immediate access with no approval needed.</li>
        <li><strong>Login:</strong> Use your approved username and password. After successful login you are redirected directly to the Dashboard.</li>
        <li><strong>Hardware:</strong> The system uses two boards — an <strong>ESP32 WROOM</strong> (sensors, relays, buzzer, LCD) and an <strong>ESP32-CAM</strong> (camera). Both connect independently to the same WiFi and server.</li>
        <li><strong>Server:</strong> The system runs on a local XAMPP server. Make sure XAMPP Apache and MySQL are running before powering the boards.</li>
      </ul></div>
    </div>

    <!-- 02 DASHBOARD -->
    <div class="step-card" id="s2">
      <div class="step-card-header"><div class="step-number">02</div><div class="step-header-content"><div><div class="step-tag">Dashboard</div><div class="step-title">Understanding the Dashboard</div></div><div class="step-icon-wrap">📊</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Live Gauges:</strong> Real-time temperature and humidity with color-coded status — blue = too low, green = ideal, red = too high. Shows "Offline" if the ESP32 stops sending data.</li>
        <li><strong>Device Control:</strong> Toggle between <strong>Auto</strong> and <strong>Manual</strong> modes. In Auto, the server controls all devices. In Manual, you control each relay directly from the dashboard.</li>
        <li><strong>Devices:</strong> Mist, Fan, Heater, Sprayer, and Exhaust — each shows its current ON/OFF state and trigger type (Auto, Manual, Schedule, Emergency, or Fault).</li>
        <li><strong>Monthly Harvest Chart:</strong> A bar chart showing total harvested mushrooms per month for the last 6 months. Click the arrows to navigate months. Click a bar to jump to that month's calendar records.</li>
        <li><strong>Growth Records:</strong> Log or view mushroom records by date using the calendar picker below the harvest chart.</li>
        <li><strong>Active Alerts:</strong> Unresolved temperature, humidity, offline, or fault alerts appear prominently. Alerts send email notifications based on your settings.</li>
      </ul></div>
    </div>

    <!-- 03 CAMERA -->
    <div class="step-card" id="s3">
      <div class="step-card-header"><div class="step-number">03</div><div class="step-header-content"><div><div class="step-tag">Camera</div><div class="step-title">Chamber Camera Analysis</div></div><div class="step-icon-wrap">📷</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Auto Capture:</strong> The ESP32-CAM automatically takes a photo every 30 minutes (configurable in Settings) and uploads it to the server.</li>
        <li><strong>First Capture:</strong> One photo is taken immediately on boot once WiFi is connected — no need to wait 30 minutes on first run.</li>
        <li><strong>AI Analysis:</strong> Each uploaded image is automatically analyzed for mushroom diameter (cm) and harvest status — results appear in the dashboard camera section.</li>
        <li><strong>Harvest Status Labels:</strong> Images are tagged as <em>Too Young</em>, <em>Growing</em>, <em>Ready for Harvest</em>, or <em>Overripe</em> based on AI analysis.</li>
        <li><strong>Harvest Email Alert:</strong> When a photo is analyzed as <em>Ready for Harvest</em> or <em>Overripe</em>, an email notification is sent automatically (10-minute cooldown between alerts).</li>
        <li><strong>Browse by Date:</strong> Use the date picker in the Camera card header to view all captures from a specific day. Images auto-refresh every 30 seconds.</li>
        <li><strong>Flash LED:</strong> The built-in flash on the ESP32-CAM activates briefly during each capture for consistent lighting.</li>
      </ul></div>
    </div>

    <!-- 04 AUTOMATION -->
    <div class="step-card" id="s4">
      <div class="step-card-header"><div class="step-number">04</div><div class="step-header-content"><div><div class="step-tag">Automation</div><div class="step-title">Device Control & Automation</div></div><div class="step-icon-wrap">⚙️</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Auto Mode:</strong> The server's auto engine runs automatically every time sensor data arrives. It controls all devices based on current temperature and humidity vs. your configured thresholds.</li>
        <li><strong>Manual Mode:</strong> Switch to Manual for direct control of each relay. Use this for targeted adjustments or emergencies. Always return to Auto when done.</li>
        <li><strong>Custom Rules:</strong> Add automation rules — choose a device, a sensor metric (temp/humidity), a condition (above/below), a threshold value, and a duration. Rules activate automatically when conditions match.</li>
        <li><strong>Scheduled Tasks:</strong> Schedule any device to run at a specific time for a set duration on selected days of the week (e.g. Sprayer every day at 8:00 AM for 15 minutes).</li>
        <li><strong>Trigger Types:</strong> Each device action is logged with its trigger type — <span style="color:#1a9e5c;font-weight:600;">Auto</span>, <span style="color:#1a6bba;font-weight:600;">Manual</span>, <span style="color:#7c3aed;font-weight:600;">Schedule</span>, <span style="color:#d97706;font-weight:600;">Emergency</span>, or <span style="color:#dc2626;font-weight:600;">Fault</span>.</li>
        <li><strong>Built-in Protections:</strong> The system has automatic safeguards that cannot be disabled — emergency shutoff when temp/humidity reaches critical levels, and fault detection for devices stuck ON or not responding.</li>
        <li><strong>Active Faults Panel:</strong> Unresolved device faults appear at the top of the Automation page. Each fault shows the device, fault type, and time detected. Click "Mark Resolved" once the hardware issue is fixed.</li>
        <li><strong>Buzzer:</strong> The buzzer on the ESP32 activates automatically on device fault or emergency — beeps for 30 seconds in a 300ms ON / 200ms OFF pattern.</li>
      </ul>
      <div class="callout">⚠️ Manual mode disables automatic device responses to sensor changes. Only use it temporarily — the chamber can go out of range quickly without Auto mode active.</div>
      </div>
    </div>

    <!-- 05 RECORDS -->
    <div class="step-card" id="s5">
      <div class="step-card-header"><div class="step-number">05</div><div class="step-header-content"><div><div class="step-tag">Records</div><div class="step-title">Tracking Mushroom Growth</div></div><div class="step-icon-wrap">🍄</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Add Records:</strong> From the Dashboard calendar, select a date and log the mushroom count, growth stage, and optional field notes.</li>
        <li><strong>Growth Stages:</strong> Spawn Run → Primordia Formation → Fruiting Body → Harvest. Select the current stage when logging.</li>
        <li><strong>Harvest Count:</strong> Records with stage "Harvest" are counted in the Monthly Harvest bar chart on the Dashboard.</li>
        <li><strong>Monthly View:</strong> Navigate months using the bar chart arrows. Click a bar to jump to that month's records in the calendar.</li>
        <li><strong>Historical Data:</strong> All records are stored permanently in the database — no automatic deletion. Review past growth anytime.</li>
      </ul></div>
    </div>

    <!-- 06 REPORTS -->
    <div class="step-card" id="s6">
      <div class="step-card-header"><div class="step-number">06</div><div class="step-header-content"><div><div class="step-tag">Reports</div><div class="step-title">Sensor Reports & Analytics</div></div><div class="step-icon-wrap">📈</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Date Range Filter:</strong> Set a custom start and end date to view sensor data summaries for any period.</li>
        <li><strong>Summary Table:</strong> Daily averages, min/max values for both temperature and humidity across the selected date range.</li>
        <li><strong>Charts:</strong> Visual line charts for temperature and humidity trends — quickly spot patterns and anomalies.</li>
        <li><strong>CSV Export:</strong> Download the full sensor report as a CSV file for offline analysis or record-keeping.</li>
        <li><strong>Data Persistence:</strong> All sensor data is stored permanently. No rolling deletions — you can review data from any past date.</li>
      </ul></div>
    </div>

    <!-- 07 LOGS -->
    <div class="step-card" id="s7">
      <div class="step-card-header"><div class="step-number">07</div><div class="step-header-content"><div><div class="step-tag">Logs</div><div class="step-title">System & Alert Logs</div></div><div class="step-icon-wrap">📋</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Alert Logs:</strong> All temperature, humidity, offline, and device fault alerts — with severity level (Info, Warning, Critical), timestamp, and resolved status.</li>
        <li><strong>System Logs:</strong> A record of all user actions — logins, setting changes, manual device overrides, approvals, and password changes.</li>
        <li><strong>Filter:</strong> Filter logs by type, severity, or date range. Up to 200 entries per view.</li>
        <li><strong>Resolve Alerts:</strong> Mark all active alerts as resolved in one click using the "Resolve All" button. Individual alerts auto-resolve when the condition normalizes.</li>
        <li><strong>Alert Summary:</strong> A quick count of active warnings and critical alerts is shown at the top of the Logs page.</li>
      </ul></div>
    </div>

    <!-- 08 SETTINGS -->
    <div class="step-card" id="s8">
      <div class="step-card-header"><div class="step-number">08</div><div class="step-header-content"><div><div class="step-tag">Settings</div><div class="step-title">System Configuration</div></div><div class="step-icon-wrap">🔧</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Alert Thresholds:</strong> Set ideal temperature range (min/max), humidity range (min/max), and emergency limits for temp and humidity. These are read by the auto engine and the ESP32 as fallback values.</li>
        <li><strong>Email Notifications (SMTP):</strong> Configure your Gmail SMTP credentials and recipient email. Test the connection before saving. Only the owner can modify these settings.</li>
        <li><strong>Notification Preferences:</strong> Toggle email alerts for temperature, humidity, device offline, and emergency events individually. Set a cooldown (minutes) between repeat alerts of the same type.</li>
        <li><strong>Auto Engine Settings:</strong> Configure how long a device must be continuously ON before a "stuck-on" fault is triggered (default: 60 min), and how long a device must be unresponsive before a "no-response" fault triggers (default: 60 min).</li>
        <li><strong>Camera Settings:</strong> Set the capture interval in seconds (default: 1800 = every 30 minutes). This value is used by the ESP32-CAM to schedule automatic captures.</li>
        <li><strong>Owner Only:</strong> All settings are only editable by the owner. Staff can view but not change any configuration.</li>
      </ul>
      <div class="callout">💡 After updating thresholds or SMTP settings, the ESP32 will sync the new values on its next poll cycle (every 6 seconds).</div>
      </div>
    </div>

    <!-- 09 PROFILE -->
    <div class="step-card" id="s9">
      <div class="step-card-header"><div class="step-number">09</div><div class="step-header-content"><div><div class="step-tag">Profile</div><div class="step-title">Profile & User Management</div></div><div class="step-icon-wrap">👤</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Edit Profile:</strong> Update your full name, email, and phone number at any time. Changes are saved immediately.</li>
        <li><strong>Change Password:</strong> Enter your current password, then a new password (same strength requirements as registration). Logged as a security event.</li>
        <li><strong>Activity Log:</strong> View a history of your own actions — logins, profile edits, device overrides, and password changes.</li>
        <li><strong>Pending Approvals (Owner):</strong> The owner sees a list of staff accounts awaiting approval. Click Approve to grant access (sends an email to the staff) or Reject to deny.</li>
        <li><strong>User Management (Owner):</strong> View all registered users, edit their details and roles, or add new users directly without going through registration.</li>
        <li><strong>Role Assignment (Owner):</strong> Assign staff or owner roles to any user. Only owners can change roles.</li>
      </ul></div>
    </div>

    <!-- 10 SAFETY -->
    <div class="step-card" id="s10">
      <div class="step-card-header"><div class="step-number">10</div><div class="step-header-content"><div><div class="step-tag">Safety</div><div class="step-title">Safety, Maintenance & Troubleshooting</div></div><div class="step-icon-wrap">🛡️</div></div></div>
      <div class="step-body"><ul>
        <li><strong>Electrical Safety:</strong> All relay-controlled devices should be properly fused and grounded. Keep AC wiring away from the ESP32 boards and sensor wires.</li>
        <li><strong>Moisture Protection:</strong> Protect the ESP32 WROOM and ESP32-CAM from direct humidity exposure — mount them outside the chamber if possible.</li>
        <li><strong>Sensor Calibration:</strong> Clean and verify the DHT22 sensor periodically. A faulty sensor can cause incorrect auto-control decisions.</li>
        <li><strong>Backup Power:</strong> Use a UPS or backup battery for the server and ESP32 boards to prevent data loss and keep automation running during power interruptions.</li>
        <li><strong>Offline Sensors:</strong> If the dashboard shows "Offline", check that the ESP32 WROOM is powered, connected to WiFi, and that XAMPP is running.</li>
        <li><strong>Camera Not Uploading:</strong> Re-seat the OV2640 ribbon cable, confirm the ESP32-CAM has 5V power and WiFi, and verify the server IP in the CAM code matches your XAMPP IP.</li>
        <li><strong>Login Issues:</strong> Confirm your account is approved by the owner. If you forgot your password, ask the owner to reset it from the Profile page.</li>
        <li><strong>Fault Alerts:</strong> If a device fault is triggered, check the physical relay and device wiring. Once resolved, mark the fault as resolved in the Automation page Active Faults panel.</li>
      </ul>
      <div class="callout">💡 Best practice: check the Dashboard daily, address all alerts promptly, and keep mushroom growth records updated consistently for accurate harvest analytics.</div>
      </div>
    </div>

  </div>
</div>

<footer>
  <span class="foot-copy">© 2025 J WHO? Mushroom Incubation System — All Rights Reserved</span>
  <span class="foot-brand">J WHO? MIS</span>
</footer>

<!-- SCROLL TO TOP -->
<button class="scroll-top" id="scrollTop" aria-label="Back to top" title="Back to top">&#8679;</button>

<script>
// PH Time
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

// Modal helpers
function openModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}
function handleBackdrop(e,id){if(e.target===document.getElementById(id))closeModal(id);}
function switchTo(id){['loginModal','registerModal'].forEach(m=>document.getElementById(m).classList.remove('open'));openModal(id);}
function openLogin(){openModal('loginModal');}

document.addEventListener('keydown',e=>{if(e.key==='Escape')['loginModal','registerModal'].forEach(id=>closeModal(id));});

<?php if(!empty($login_error)): ?>
document.addEventListener('DOMContentLoaded',()=>openModal('loginModal'));
<?php endif; ?>
<?php if(!empty($reg_error)||!empty($reg_success)): ?>
document.addEventListener('DOMContentLoaded',()=>openModal('registerModal'));
<?php endif; ?>

// Password toggle
function togglePw(inputId,iconId){
  const inp=document.getElementById(inputId),ico=document.getElementById(iconId);
  const show=inp.type==='password';
  inp.type=show?'text':'password';
  ico.classList.toggle('fa-eye',!show);
  ico.classList.toggle('fa-eye-slash',show);
}

// Strength bar
function checkStrength(v){
  let s=0;
  if(v.match(/[a-z]/))s++; if(v.match(/[A-Z]/))s++;
  if(v.match(/[0-9]/))s++; if(v.match(/[^a-zA-Z0-9]/))s++;
  if(v.length>=8)s++;
  const fill=document.getElementById('strFill');
  fill.style.width=(s/5*100)+'%';
  fill.style.background=s<=2?'#e74c3c':s===3?'#e67e22':'#4a7c4a';
}

// Scroll-reveal cards
const cards=document.querySelectorAll('.step-card');
const io=new IntersectionObserver((entries)=>{
  entries.forEach((e,i)=>{if(e.isIntersecting){setTimeout(()=>e.target.classList.add('visible'),i*40);io.unobserve(e.target);}});
},{threshold:0.06});
cards.forEach(c=>io.observe(c));

// Active sidebar & tab on scroll
const sections=document.querySelectorAll('.step-card[id]');
const sideLinks=document.querySelectorAll('.sl a');
const tabLinks=document.querySelectorAll('.tab-link');
const markActive=(id)=>{
  sideLinks.forEach(l=>l.classList.toggle('active',l.getAttribute('href')==='#'+id));
  tabLinks.forEach(l=>l.classList.toggle('active',l.getAttribute('href')==='#'+id));
};
const sio=new IntersectionObserver((entries)=>{entries.forEach(e=>{if(e.isIntersecting)markActive(e.target.id);});},{rootMargin:'-30% 0px -60% 0px',threshold:0});
sections.forEach(s=>sio.observe(s));

// Scroll-to-top
(function(){
  const btn = document.getElementById('scrollTop');
  window.addEventListener('scroll', () => {
    btn.classList.toggle('show', window.scrollY > 320);
  });
  btn.addEventListener('click', () => {
    window.scrollTo({top: 0, behavior: 'smooth'});
  });
})();

</script>
</body>
</html>