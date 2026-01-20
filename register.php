<?php  
include 'includes/db_connect.php';
include 'send_email.php'; // EMAIL SENDER
session_start();

if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // SANITIZE INPUT
    $first = mysqli_real_escape_string($conn, $_POST['first_name']);
    $middle = mysqli_real_escape_string($conn, $_POST['middle_name']);
    $last = mysqli_real_escape_string($conn, $_POST['last_name']);

    $fullname = trim($first . " " . $middle . " " . $last);

    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $phone    = mysqli_real_escape_string($conn, $_POST['phone']);
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password_raw = $_POST['password'];
    $role     = mysqli_real_escape_string($conn, $_POST['role']);

    // LETTERS ONLY FOR NAMES
    if (!preg_match("/^[A-Za-z\s]+$/", $first)) {
        $error = "First name must contain letters only.";
    }
    elseif (!empty($middle) && !preg_match("/^[A-Za-z\s]+$/", $middle)) {
        $error = "Middle name must contain letters only.";
    }
    elseif (!preg_match("/^[A-Za-z\s]+$/", $last)) {
        $error = "Last name must contain letters only.";
    }
    // NUMBERS ONLY
    elseif (!preg_match("/^[0-9]+$/", $phone)) {
        $error = "Phone number must contain numbers only.";
    }
    // USERNAME LETTERS + NUMBERS ONLY
    elseif (!preg_match("/^[A-Za-z0-9]+$/", $username)) {
        $error = "Username must contain letters and numbers only.";
    }

    // PASSWORD CHECK
    $uppercase = preg_match('@[A-Z]@', $password_raw);
    $lowercase = preg_match('@[a-z]@', $password_raw);
    $number    = preg_match('@[0-9]@', $password_raw);
    $special   = preg_match('@[^\w]@', $password_raw);
    $minLength = strlen($password_raw) >= 8;

    if (empty($error) && (!$uppercase || !$lowercase || !$number || !$special || !$minLength)) {
        $error = "Password must be at least 8 characters and include uppercase, lowercase, number, and special character.";
    }

    // IF NO ERRORS → PROCEED
    if (empty($error)) {

        $password = password_hash($password_raw, PASSWORD_DEFAULT);

        // CHECK FOR DUPLICATE EMAIL OR USERNAME
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

                // SEND EMAIL NOTIFICATION
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
    <title>Register</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            min-height: 100vh;
            background: url("assets/img/bg-mushroom.jpg") no-repeat center center/cover;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.28);
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

        .logo img { width: 70px; }

        .register-box {
            position: relative;
            z-index: 1;
            background: rgba(40, 40, 40, 0.55);
            padding: 11px;
            width: 480px;
            max-width: 90%;
            border-radius: 15px;
            backdrop-filter: blur(8px);
            box-shadow: 0 0 30px rgba(0,0,0,0.45);
            color: white;
        }

        h2 {
            text-align: center;
            font-weight: 600;
            color: #7dff9c;
            margin-bottom: 25px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        input, select {
            width: 100%;
            padding: 12px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid #bcd8c5;
            background: #f2fff4;
            font-family: "Poppins";
            color: #000;
        }

        .input-container {
            position: relative;
            margin-top: 10px;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #37954f;
            cursor: pointer;
            font-size: 18px;
        }

        #strength-bar {
            height: 5px;
            width: 100%;
            background: #ccc;
            margin-top: 3px;
            border-radius: 4px;
        }

        #strength-fill {
            height: 100%;
            width: 0%;
            background: red;
            border-radius: 4px;
            transition: .3s;
        }

        button {
            width: 100%;
            margin-top: 15px;
            padding: 12px;
            background: linear-gradient(135deg, #4caf50, #74d88b);
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 17px;
            font-weight: 600;
            cursor: pointer;
        }

        .error, .success {
            margin-top: 12px;
            text-align: center;
            font-size: 14px;
        }

        .error { color: #ff5f5f; }
        .success { color: #9affb5; }

        .footer-text {
            margin-top: 18px;
            font-size: 13px;
            text-align: center;
            color: white;
        }
    </style>
</head>

<body>

<div class="logo">
    <img src="assets/img/logo.png" alt="Logo">
</div>

<div class="register-box">
    <h2>Create Your Account</h2>

    <form method="POST">

        <div class="grid-2">
            <input type="text" 
                   name="first_name" 
                   placeholder="First Name" 
                   required
                   pattern="[A-Za-z\s]+"
                   oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')">

            <input type="text" 
                   name="middle_name" 
                   placeholder="Middle Name"
                   pattern="[A-Za-z\s]+"
                   oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')">
        </div>

        <input type="text" 
               name="last_name" 
               placeholder="Last Name" 
               required
               pattern="[A-Za-z\s]+"
               oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')">

        <div class="grid-2">
            <input type="email" name="email" placeholder="Email" required>

            <input type="text" 
                   name="phone" 
                   placeholder="Phone"
                   required
                   maxlength="11"
                   pattern="[0-9]+"
                   oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        </div>

        <input type="text" 
               name="username" 
               placeholder="Username" 
               required
               pattern="[A-Za-z0-9]+"
               oninput="this.value = this.value.replace(/[^A-Za-z0-9]/g, '')">

        <div class="input-container">
            <input type="password" name="password" id="password" placeholder="Password" required>
            <span class="toggle-password" id="togglePassword"><i class="fa fa-eye"></i></span>
        </div>

        <div id="strength-bar"><div id="strength-fill"></div></div>

        <select name="role" required>
            <option value="staff" selected>Staff</option>
        </select>

        <button type="submit">Register</button>

        <?php 
            if (!empty($error)) echo "<div class='error'>$error</div>";
            if (!empty($success)) echo "<div class='success'>$success</div>";
        ?>
    </form>

    <div class="footer-text">
        <a href="index.php" style="color:#7dff9c;font-weight:600;text-decoration:none;">Already have an account? Login</a>
        <br><br>
        © 2025 J WHO? Mushroom Farm
    </div>
</div>

<script>
// PASSWORD TOGGLE
const passwordInput = document.getElementById("password");
const togglePassword = document.getElementById("togglePassword");
const icon = togglePassword.querySelector("i");

togglePassword.addEventListener("click", () => {
    const show = passwordInput.type === "password";
    passwordInput.type = show ? "text" : "password";
    icon.classList.toggle("fa-eye");
    icon.classList.toggle("fa-eye-slash");
});

// PASSWORD STRENGTH BAR
const strengthFill = document.getElementById("strength-fill");

passwordInput.addEventListener("input", () => {
    let val = passwordInput.value;
    let strength = 0;

    if (val.match(/[a-z]/)) strength++;
    if (val.match(/[A-Z]/)) strength++;
    if (val.match(/[0-9]/)) strength++;
    if (val.match(/[^a-zA-Z0-9]/)) strength++;
    if (val.length >= 8) strength++;

    let width = (strength / 5) * 100;
    strengthFill.style.width = width + "%";

    if (strength <= 2) strengthFill.style.background = "red";
    else if (strength === 3) strengthFill.style.background = "orange";
    else strengthFill.style.background = "green";
});
</script>

</body>
</html>
