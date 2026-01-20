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
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            height: 100vh;
            background: url("assets/img/bg-mushroom.jpg") no-repeat center center/cover;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            color: #000;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.25);
            z-index: 0;
        }

        .logo {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 10;
            background: white;
            border-radius: 50%;
            padding: 5px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.3);
        }

        .logo img {
            width: 70px;
        }

        .login-box {
            position: relative;
            z-index: 1;
            background: rgba(60, 60, 60, 0.55);
            padding: 30px 40px;
            width: 360px;
            max-width: 90%;
            border-radius: 12px;
            backdrop-filter: blur(8px); 
            box-shadow: 0 0 25px rgba(0, 0, 0, 0.4);
            text-align: center;
        }

        h2 {
            margin-bottom: 25px;
            font-weight: 600;
            color: #3fbf55;
            font-size: 19px;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 45px 12px 12px;
            margin: 10px 0;
            border-radius: 10px;
            border: 1px solid #bcd8c5;
            background: #f2fff4;
            color: #000;
            font-size: 15px;
            font-family: "Courier New", monospace;
            outline: none;
        }

        input:focus {
            border-color: #57d36c;
            background: #eaffea;
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #4caf50;
            font-size: 18px;
        }

        button {
            width: 100%;
            background: linear-gradient(135deg, #4caf50, #81c784);
            color: white;
            padding: 12px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
            font-weight: 600;
        }

        button:hover {
            transform: scale(1.03);
        }

        .back-home {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 14px;
            border-radius: 10px;
            background: #ffffffc8;
            border: 1px solid #bcd8c5;
            text-decoration: none;
            font-weight: 600;
            color: #2c662d;
        }

        .back-home:hover {
            background: white;
            transform: scale(1.03);
        }

        .error {
            color: #ff4d4d;
            margin-top: 12px;
            font-size: 14px;
        }

        .footer-text {
            margin-top: 25px;
            font-size: 13px;
            color: #fff;
            opacity: 0.9;
        }

        @media (max-width:1024px){ .login-box{max-width:400px} }
        @media (max-width:900px){ .login-box{max-width:380px} }
        @media (max-width:768px){ .login-box{padding:20px 30px; width:320px} .logo img{width:60px} h2{font-size:17px} input{padding:10px 40px 10px 10px; font-size:14px} button{padding:10px; font-size:15px} .back-home{padding:8px; font-size:14px} .footer-text{font-size:12px} }
        @media (max-width:600px){ .login-box{width:300px} .logo{top:15px; left:15px} .logo img{width:55px} }
        @media (max-width:520px){ .login-box{width:280px} h2{font-size:16px} input{font-size:13px} button{font-size:14px} }
        @media (max-width:480px){ .login-box{padding:15px 20px; width:280px} .logo img{width:50px} h2{font-size:16px} input{padding:8px 35px 8px 8px; font-size:13px} button{padding:8px; font-size:14px} .back-home{padding:6px; font-size:13px} .footer-text{font-size:11px} }
        @media (max-width:360px){ .login-box{padding:10px 15px; width:250px} .logo img{width:45px} h2{font-size:15px} input{padding:6px 30px 6px 6px; font-size:12px} button{padding:6px; font-size:13px} .back-home{padding:4px; font-size:12px} .footer-text{font-size:10px} }
    </style>
</head>

<body>

<div class="logo">
    <img src="assets/img/logo.png" alt="J.WHO Logo">
</div>

<div class="login-box">
    <h2>J WHO? Mushroom System</h2>

    <form method="POST" action="">
        <input type="text" name="username" placeholder="Enter Username" required>

        <div class="input-container">
            <input type="password" name="password" id="password" placeholder="Enter Password" required>
            <span class="toggle-password" id="togglePassword">
                <i class="fa fa-eye"></i>
            </span>
        </div>

        <button type="submit">Login</button>

        <?php 
            if (!empty($error)) echo "<div class='error'>$error</div>";
        ?>
    </form>

    <a href="homepage.php" class="back-home">⬅ Back to Home</a>

    <div class="footer-text">
        <a href="register.php" style="color:#9fffaf; text-decoration:none; font-weight:600;">
            Create an account
        </a>
        <br><br>
        © 2025 J WHO? Mushroom Farm
    </div>
</div>

<script>
const togglePassword = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');
const icon = togglePassword.querySelector('i');

togglePassword.addEventListener('click', () => {
  const show = passwordInput.type === 'password';
  passwordInput.type = show ? 'text' : 'password';
  icon.classList.toggle('fa-eye');
  icon.classList.toggle('fa-eye-slash');
});
</script>

</body>
</html>
