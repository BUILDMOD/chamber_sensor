<?php 
include 'includes/db_connect.php';
session_start();

if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

// Login Check
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, fullname, username, password, role, verified FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows == 1) {

        $row = $result->fetch_assoc();

        if (password_verify($password, $row['password'])) {

            if ($row['verified'] == 1 || $row['role'] == 'owner') {
                $_SESSION['user'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['fullname'] = $row['fullname'];

                // Bug 5 fix: log login event to system_logs (read by logs.php)
                $conn->query("CREATE TABLE IF NOT EXISTS system_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_type VARCHAR(50) NOT NULL,
                    description TEXT NOT NULL,
                    user VARCHAR(100) NULL,
                    ip_address VARCHAR(45) NULL,
                    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $ip  = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $usr = $row['username'];
                $desc = "User '{$usr}' logged in successfully.";
                $evt = 'login';
                $ls = $conn->prepare("INSERT INTO system_logs (event_type, description, user, ip_address) VALUES (?,?,?,?)");
                if ($ls) { $ls->bind_param("ssss", $evt, $desc, $usr, $ip); $ls->execute(); $ls->close(); }

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Your account is pending approval.";
            }
        } else {
            $error = "Invalid username or password!";
        }

    } else {
        $error = "Invalid username or password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login — J WHO? Mushroom Farm</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

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
            width: 310px;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: 32px 28px 28px;
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
            margin-bottom: 24px;
        }

        /* Inputs */
        .field { margin-bottom: 12px; text-align: left; }

        .input-wrap { position: relative; }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px 36px 10px 12px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(200,232,184,0.20);
            border-radius: 9px;
            color: #f0ede6;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }
        input::placeholder { color: rgba(200,232,184,0.35); }
        input:focus {
            border-color: rgba(122,171,110,0.6);
            background: rgba(255,255,255,0.13);
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

        /* Error */
        .error-msg {
            font-size: 12px;
            color: #f08080;
            background: rgba(192,57,43,0.15);
            border: 1px solid rgba(192,57,43,0.30);
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 12px;
            text-align: left;
        }

        /* Buttons */
        .btn-login {
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
            margin-top: 4px;
            transition: opacity 0.2s, transform 0.2s;
        }
        .btn-login:hover { opacity: 0.9; transform: translateY(-1px); }

        .btn-home {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 10px;
            border-radius: 9px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(200,232,184,0.15);
            color: rgba(200,232,184,0.75);
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            text-decoration: none;
            transition: background 0.2s, color 0.2s;
        }
        .btn-home:hover { background: rgba(255,255,255,0.12); color: #c8e8b8; }

        /* Footer */
        .card-footer {
            margin-top: 20px;
        }
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

        .ph-time {
            position: fixed;
            bottom: 18px;
            right: 20px;
            z-index: 10;
            text-align: right;
            color: rgba(200,232,184,0.75);
            font-family: 'DM Sans', sans-serif;
        }
        .ph-time .time { font-size: 22px; font-weight: 600; letter-spacing: 0.02em; line-height: 1; }
        .ph-time .date { font-size: 11px; color: rgba(200,232,184,0.45); margin-top: 3px; }
        .ph-time .label { font-size: 10px; color: rgba(200,232,184,0.30); letter-spacing: 0.08em; text-transform: uppercase; margin-top: 2px; }
    </style>
</head>
<body>

<!-- Logo -->
<div class="logo">
    <img src="assets/img/logo.png" alt="J.WHO Logo">
</div>

<!-- Login card -->
<div class="card">

    <h2>J WHO? Mushroom</h2>
    <p class="subtitle">Management System</p>

    <?php if (!empty($error)): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">

        <div class="field">
            <div class="input-wrap">
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
            </div>
        </div>

        <div class="field">
            <div class="input-wrap">
                <input type="password" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                <span class="toggle-password" id="togglePassword">
                    <i class="fa fa-eye" id="eyeIcon"></i>
                </span>
            </div>
        </div>

        <button type="submit" class="btn-login">Login</button>

    </form>

    <a href="homepage.php" class="btn-home">← Back to Home</a>

    <div class="card-footer">
        <a href="register.php">Create an account</a>
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
function updatePHTime() {
    const now = new Date();
    const opts = { timeZone: 'Asia/Manila' };
    const time = now.toLocaleTimeString('en-PH', { ...opts, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    const date = now.toLocaleDateString('en-PH', { ...opts, weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    document.getElementById('phTime').textContent = time;
    document.getElementById('phDate').textContent = date;
}
updatePHTime();
setInterval(updatePHTime, 1000);

const toggle = document.getElementById('togglePassword');
const passInput = document.getElementById('password');
const eyeIcon = document.getElementById('eyeIcon');

toggle.addEventListener('click', () => {
    const show = passInput.type === 'password';
    passInput.type = show ? 'text' : 'password';
    eyeIcon.classList.toggle('fa-eye', !show);
    eyeIcon.classList.toggle('fa-eye-slash', show);
});
</script>

</body>
</html>