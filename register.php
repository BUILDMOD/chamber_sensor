<?php  
include 'includes/db_connect.php';
include 'send_email.php';
session_start();

if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $first  = mysqli_real_escape_string($conn, $_POST['first_name']);
    $middle = mysqli_real_escape_string($conn, $_POST['middle_name']);
    $last   = mysqli_real_escape_string($conn, $_POST['last_name']);
    $fullname = trim($first . " " . $middle . " " . $last);

    $email        = mysqli_real_escape_string($conn, $_POST['email']);
    $phone        = mysqli_real_escape_string($conn, $_POST['phone']);
    $username     = mysqli_real_escape_string($conn, $_POST['username']);
    $password_raw = $_POST['password'];
    $role         = mysqli_real_escape_string($conn, $_POST['role']);

    if (!preg_match("/^[A-Za-z\s]+$/", $first)) {
        $error = "First name must contain letters only.";
    } elseif (!empty($middle) && !preg_match("/^[A-Za-z\s]+$/", $middle)) {
        $error = "Middle name must contain letters only.";
    } elseif (!preg_match("/^[A-Za-z\s]+$/", $last)) {
        $error = "Last name must contain letters only.";
    } elseif (!preg_match("/^[0-9]+$/", $phone)) {
        $error = "Phone number must contain numbers only.";
    } elseif (!preg_match("/^[A-Za-z0-9]+$/", $username)) {
        $error = "Username must contain letters and numbers only.";
    }

    $uppercase = preg_match('@[A-Z]@', $password_raw);
    $lowercase = preg_match('@[a-z]@', $password_raw);
    $number    = preg_match('@[0-9]@', $password_raw);
    $special   = preg_match('@[^\w]@', $password_raw);
    $minLength = strlen($password_raw) >= 8;

    if (empty($error) && (!$uppercase || !$lowercase || !$number || !$special || !$minLength)) {
        $error = "Password must be at least 8 characters with uppercase, lowercase, number, and special character.";
    }

    if (empty($error)) {
        $password = password_hash($password_raw, PASSWORD_DEFAULT);

        $check = $conn->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username or Email already exists!";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO users (first_name, middle_name, last_name, fullname, email, phone, username, password, role, verified)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");

            if ($stmt) {
                $stmt->bind_param("sssssssss",
                    $first, $middle, $last,
                    $fullname, $email, $phone,
                    $username, $password, $role
                );
                $stmt->execute();

                $subject = "Welcome to J.WHO Mushroom System!";
                $body = "
                    Hello <b>$fullname</b>,<br><br>
                    Your account has been successfully created in the 
                    <b>J.WHO Mushroom Growth Chamber System</b>.<br><br>
                    <b>Username:</b> $username<br>
                    <b>Role:</b> $role<br><br>
                    You may now log in to your account.<br><br>
                    Thank you!<br>
                    <b>J.WHO Mushroom Farm</b>
                ";
                sendEmail($email, $subject, $body);

                $success = "Account created! Redirecting...";
                header("refresh:2; url=index.php");
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — J WHO? Mushroom Farm</title>

    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            background: url("assets/img/bg-mushroom.jpg") center/cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 80px 16px 40px;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(10, 22, 10, 0.60);
        }

        /* Logo */
        .logo {
            position: fixed;
            top: 18px; left: 18px;
            z-index: 10;
            background: white;
            border-radius: 50%;
            padding: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.25);
        }
        .logo img { width: 52px; height: 52px; border-radius: 50%; display: block; }

        /* Card */
        .card {
            position: relative;
            z-index: 1;
            width: 360px;
            max-width: 100%;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: 30px 28px 26px;
            backdrop-filter: blur(16px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.45);
            text-align: center;
        }

        h2 {
            font-family: 'DM Serif Display', serif;
            font-size: 20px;
            font-weight: 400;
            color: #c8e8b8;
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 11px;
            color: rgba(200,232,184,0.50);
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        /* Grid */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        /* Fields */
        .field { margin-bottom: 8px; text-align: left; }
        .input-wrap { position: relative; }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px 12px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(200,232,184,0.20);
            border-radius: 9px;
            color: #f0ede6;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
            -webkit-appearance: none;
        }

        input::placeholder { color: rgba(200,232,184,0.35); }

        input:focus, select:focus {
            border-color: rgba(122,171,110,0.6);
            background: rgba(255,255,255,0.13);
        }

        select option { background: #2d4a2d; color: #f0ede6; }

        /* Password field with toggle */
        .input-wrap input[type="password"],
        .input-wrap input[type="text"] {
            padding-right: 36px;
        }

        .toggle-password {
            position: absolute;
            right: 10px; top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: rgba(200,232,184,0.45);
            font-size: 13px;
        }
        .toggle-password:hover { color: #7aab6e; }

        /* Strength bar */
        .strength-bar {
            height: 3px;
            background: rgba(255,255,255,0.10);
            border-radius: 4px;
            margin-top: 5px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.3s, background 0.3s;
        }

        /* Messages */
        .error-msg, .success-msg {
            font-size: 12px;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 10px;
            text-align: left;
        }
        .error-msg {
            color: #f08080;
            background: rgba(192,57,43,0.15);
            border: 1px solid rgba(192,57,43,0.30);
        }
        .success-msg {
            color: #9affb5;
            background: rgba(74,124,74,0.20);
            border: 1px solid rgba(74,124,74,0.35);
        }

        /* Buttons */
        .btn-register {
            width: 100%;
            padding: 11px;
            border: none;
            border-radius: 9px;
            background: linear-gradient(135deg, #4a7c4a, #7aab6e);
            color: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 6px;
            transition: opacity 0.2s, transform 0.2s;
        }
        .btn-register:hover { opacity: 0.9; transform: translateY(-1px); }

        /* Footer */
        .card-footer { margin-top: 18px; }
        .card-footer a {
            color: #7aab6e;
            font-size: 12px;
            text-decoration: none;
        }
        .card-footer a:hover { color: #c8e8b8; }
        .card-footer .copy {
            margin-top: 10px;
            font-size: 10px;
            color: rgba(200,232,184,0.25);
        }

        /* PH Time */
        .ph-time {
            position: fixed;
            bottom: 18px;
            right: 20px;
            z-index: 10;
            text-align: right;
            color: rgba(200,232,184,0.75);
            font-family: 'DM Sans', sans-serif;
        }
        .ph-time .time  { font-size: 22px; font-weight: 600; letter-spacing: 0.02em; line-height: 1; }
        .ph-time .date  { font-size: 11px; color: rgba(200,232,184,0.45); margin-top: 3px; }
        .ph-time .label { font-size: 10px; color: rgba(200,232,184,0.30); letter-spacing: 0.08em; text-transform: uppercase; margin-top: 2px; }
    </style>
</head>
<body>

<!-- Logo -->
<div class="logo">
    <img src="assets/img/logo.png" alt="Logo">
</div>

<!-- Register Card -->
<div class="card">

    <h2>Create Account</h2>
    <p class="subtitle">J WHO? Mushroom Farm</p>

    <?php if (!empty($error)): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="success-msg"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">

        <div class="grid-2">
            <div class="field">
                <input type="text" name="first_name" placeholder="First Name" required
                       oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')">
            </div>
            <div class="field">
                <input type="text" name="middle_name" placeholder="Middle Name"
                       oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')">
            </div>
        </div>

        <div class="field">
            <input type="text" name="last_name" placeholder="Last Name" required
                   oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')">
        </div>

        <div class="grid-2">
            <div class="field">
                <input type="email" name="email" placeholder="Email" required>
            </div>
            <div class="field">
                <input type="text" name="phone" placeholder="Phone" required maxlength="11"
                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
        </div>

        <div class="field">
            <input type="text" name="username" placeholder="Username" required
                   oninput="this.value=this.value.replace(/[^A-Za-z0-9]/g,'')">
        </div>

        <div class="field">
            <div class="input-wrap">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <span class="toggle-password" id="togglePassword">
                    <i class="fa fa-eye" id="eyeIcon"></i>
                </span>
            </div>
            <div class="strength-bar">
                <div class="strength-fill" id="strengthFill"></div>
            </div>
        </div>

        <div class="field">
            <select name="role" required>
                <option value="staff" selected>Staff</option>
            </select>
        </div>

        <button type="submit" class="btn-register">Register</button>

    </form>

    <div class="card-footer">
        <a href="index.php">Already have an account? <strong>Login</strong></a>
        <p class="copy">© 2025 J WHO? Mushroom Farm</p>
    </div>

</div>

<!-- PH Time -->
<div class="ph-time">
    <div class="time" id="phTime">--:-- --</div>
    <div class="date" id="phDate">---</div>
    <div class="label">Philippine Time</div>
</div>

<script>
// PH Time
function updatePHTime() {
    const now = new Date();
    const opts = { timeZone: 'Asia/Manila' };
    document.getElementById('phTime').textContent = now.toLocaleTimeString('en-PH', { ...opts, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    document.getElementById('phDate').textContent = now.toLocaleDateString('en-PH', { ...opts, weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}
updatePHTime();
setInterval(updatePHTime, 1000);

// Password toggle
const toggle    = document.getElementById('togglePassword');
const passInput = document.getElementById('password');
const eyeIcon   = document.getElementById('eyeIcon');

toggle.addEventListener('click', () => {
    const show = passInput.type === 'password';
    passInput.type = show ? 'text' : 'password';
    eyeIcon.classList.toggle('fa-eye',      !show);
    eyeIcon.classList.toggle('fa-eye-slash', show);
});

// Password strength bar
const strengthFill = document.getElementById('strengthFill');
passInput.addEventListener('input', () => {
    const v = passInput.value;
    let s = 0;
    if (v.match(/[a-z]/)) s++;
    if (v.match(/[A-Z]/)) s++;
    if (v.match(/[0-9]/)) s++;
    if (v.match(/[^a-zA-Z0-9]/)) s++;
    if (v.length >= 8) s++;

    strengthFill.style.width = (s / 5 * 100) + '%';
    strengthFill.style.background = s <= 2 ? '#e74c3c' : s === 3 ? '#e67e22' : '#4a7c4a';
});
</script>

</body>
</html>