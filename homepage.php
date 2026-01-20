<?php  
include 'includes/db_connect.php';
session_start();

if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$error = ""
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>J WHO? Mushroom Incubation System</title>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&display=swap" rel="stylesheet">

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Montserrat", sans-serif; }

    body {
        overflow-x: hidden;
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
        background-attachment: fixed;
        position: relative;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    body::before {
        content: "";
        position: absolute;
        inset: 0;
        background: url('assets/img/bg-mushroom.jpg') no-repeat center center/cover,
                    linear-gradient(to right, rgba(0,0,0,0.8), rgba(0,0,0,0.45));
        background-blend-mode: overlay;
        z-index: 0;
    }

    /* Logo */
    .logo-container {
        position: fixed;
        top: 30px;
        left: 32px;
        width: 60px;
        z-index: 20;
    }
    .logo-container img { width: 100%; height: 100%; object-fit: contain; }

    .title-text {
        position: fixed;
        top: 33px;
        left: 115px;
        color: white;
        font-size: 28px;
        font-weight: 700;
        font-family: "Cinzel", serif;
        letter-spacing: 1px;
        text-shadow: 0 2px 4px rgba(0,0,0,0.6);
        z-index: 20;
        transition: opacity 0.3s ease;
        opacity: 1;
    }

    /* Hero Section */
    .hero-container {
        position: relative;
        z-index: 5;
        padding-left: 120px;
        padding-top: 150px;
        width: 55%;
        color: white;
        flex-grow: 1;
    }

    .hero-container h1 {
        font-family: "Playfair Display", serif;
        font-size: 62px;
        font-weight: 700;
        line-height: 1.1;
        margin-bottom: 14px;
        text-shadow: 0 3px 6px rgba(0,0,0,0.5);
    }

    .hero-container p {
        font-size: 18px;
        line-height: 1.7;
        max-width: 540px;
        opacity: 0.9;
        margin-bottom: 45px;
    }

    /* Buttons */
    .btn-group { display: flex; gap: 15px; }

    .login-btn {
        background: #d72638;
        padding: 15px 50px;
        font-size: 19px;
        color: white;
        border-radius: 10px;
        cursor: pointer;
        border: none;
        transition: 0.3s;
        font-weight: 600;
        box-shadow: 0 5px 18px rgba(0,0,0,0.4);
    }
    .login-btn:hover { background: #b81e2d; transform: translateY(-2px); }

    .learn-btn {
        background: transparent;
        padding: 15px 25px;
        font-size: 19px;
        border-radius: 10px;
        cursor: pointer;
        border: 2px solid white;
        color: white;
        font-weight: 600;
        transition: 0.3s;
        backdrop-filter: blur(4px);
    }
    .learn-btn:hover { background: rgba(255,255,255,0.15); transform: translateY(-2px); }

    /* Instructions Section */
    .instructions-section {
        position: relative;
        z-index: 5;
        padding: 80px 40px;
        color: white;
        max-width: 1000px;
        margin: 0 auto;
        line-height: 1.7;
    }

    .instructions-section h2 {
        font-family: "Cinzel", serif;
        font-size: 48px;
        font-weight: 700;
        text-align: center;
        margin-bottom: 40px;
        text-shadow: 0 3px 6px rgba(0,0,0,0.5);
    }

    .instructions-section h3 {
        font-family: "Montserrat", sans-serif;
        font-size: 28px;
        font-weight: 600;
        margin-top: 40px;
        margin-bottom: 20px;
        color: #d72638;
        text-shadow: 0 2px 4px rgba(0,0,0,0.6);
    }

    .instructions-section p, .instructions-section li {
        font-size: 18px;
        margin-bottom: 15px;
        opacity: 0.9;
    }

    .instructions-section ul {
        margin-left: 20px;
        margin-bottom: 20px;
    }

    .instructions-section .step {
        margin-bottom: 30px;
    }

    .instructions-section .highlight {
        background: rgba(255,255,255,0.1);
        padding: 15px;
        border-radius: 10px;
        border-left: 4px solid #d72638;
        margin-bottom: 20px;
    }

    /* Footer */
    footer {
        width: 100%;
        background: rgba(0,0,0,0.7);
        color: white;
        text-align: center;
        padding: 10px 0;
        font-size: 14px;
        z-index: 5;
        backdrop-filter: blur(3px);
    }

    /* responsive tweaks */
    @media (max-width:1024px){ .hero-container{width:60%; padding-left:110px} .title-text{left:110px; font-size:26px} }
    @media (max-width:900px){ .hero-container{width:100%; padding:150px 40px} .title-text{left:100px} }
    @media (max-width:768px){ .logo-container{left:20px; width:50px} .logo-container img{width:100%} .title-text{left:80px; font-size:24px; top:25px} .hero-container{padding:120px 30px 40px} .hero-container h1{font-size:50px} .hero-container p{font-size:16px; max-width:none} .btn-group{gap:12px} .main-btn, .learn-btn{padding:12px 25px; font-size:16px} }
    @media (max-width:600px){ .hero-container h1{font-size:45px} .hero-container p{font-size:15px} .main-btn, .learn-btn{padding:11px 22px; font-size:15px} }
    @media (max-width:520px){ .hero-container h1{font-size:42px} .hero-container p{font-size:14px} .main-btn, .learn-btn{padding:10px 20px; font-size:14px} }
    @media (max-width:480px){ .logo-container{left:15px; width:45px} .title-text{left:70px; font-size:20px; top:20px} .hero-container{padding:100px 20px 40px} .hero-container h1{font-size:40px; line-height:1.2} .hero-container p{font-size:14px} .btn-group{flex-direction:column; gap:10px} .main-btn, .learn-btn{padding:10px 20px; font-size:14px} }
    @media (max-width:360px){ .title-text{left:60px; font-size:18px} .hero-container h1{font-size:32px} .hero-container p{font-size:13px} .main-btn, .learn-btn{padding:8px 15px; font-size:13px} }
</style>
</head>

<body>

<!-- Floating Logo -->
<div class="logo-container">
    <img src="assets/img/logo.png" alt="Logo">
</div>

<!-- System Title -->
<div class="title-text">Mushroom Incubation System</div>

<!-- Hero Section -->
<div class="hero-container">
    <h1>Smart Incubation<br>for reliable yields</h1>

    <p>
        A modern monitoring system designed to optimize mushroom growth conditions
        with automated tracking, real-time environment control, and data-driven insights
        for consistently healthy yields.
    </p>

    <div class="btn-group">
        <button class="login-btn" onclick="window.location.href='index.php'">Log In</button>
        <button class="learn-btn" onclick="scrollToInstructions()">Learn More</button>
    </div>
</div>

<!-- Instructions Section -->
<div class="instructions-section" id="instructions">
    <h2>How to Use the J WHO? Mushroom Incubation System</h2>

    <div class="step">
        <h3>1. Getting Started</h3>
        <p>Welcome to the J WHO? Mushroom Incubation System! This system is designed to help you monitor and control the environment for optimal mushroom growth. Follow these steps to get started:</p>
        <ul>
            <li><strong>Registration:</strong> If you're new, create an account by clicking "Create an account" on the login page. Fill in your details and wait for approval from the owner.</li>
            <li><strong>Login:</strong> Use your approved username and password to log in to the system.</li>
            <li><strong>Dashboard Access:</strong> After logging in, you'll be redirected to the dashboard where you can monitor and control the incubation chamber.</li>
        </ul>
    </div>

    <div class="step">
        <h3>2. Understanding the Dashboard</h3>
        <p>The dashboard is the heart of the system. Here's what you'll find:</p>
        <div class="highlight">
            <strong>Live Environment Status:</strong> Real-time temperature and humidity readings with color-coded gauges. Ideal ranges are 22-28°C for temperature and 85-95% for humidity.
        </div>
        <ul>
            <li><strong>Device Control:</strong> Toggle between Auto and Manual modes. In Auto mode, the system controls devices automatically. Manual mode allows you to override device states.</li>
            <li><strong>Devices:</strong>
                <ul>
                    <li><strong>Mist:</strong> Maintains humidity levels</li>
                    <li><strong>Fan:</strong> Regulates temperature and air circulation</li>
                    <li><strong>Heater:</strong> Provides heat when temperature is too low</li>
                    <li><strong>Sprayer:</strong> Applies water or nutrients (not for humidity control)</li>
                </ul>
            </li>
            <li><strong>Alerts:</strong> Notifications when environmental conditions go out of ideal ranges</li>
            <li><strong>Mushroom Records:</strong> Manual tracking of mushroom growth stages and notes</li>
        </ul>
    </div>

    <div class="step">
        <h3>3. Monitoring Environmental Conditions</h3>
        <p>Keep an eye on the temperature and humidity gauges:</p>
        <ul>
            <li><strong>Temperature:</strong> Should stay between 22-28°C. Blue indicates too low, red indicates ideal, orange indicates too high.</li>
            <li><strong>Humidity:</strong> Should be 85-95%. Blue means too low, green means ideal, red means too high.</li>
            <li><strong>Alerts:</strong> Check the alerts section for any issues. The system will notify you if conditions are not optimal.</li>
        </ul>
    </div>

    <div class="step">
        <h3>4. Device Control and Manual Overrides</h3>
        <p>In most cases, leave the system in Auto mode for optimal performance. However, you can switch to Manual mode for specific needs:</p>
        <ul>
            <li><strong>Auto Mode:</strong> Recommended. The system automatically adjusts devices based on sensor readings.</li>
            <li><strong>Manual Mode:</strong> Allows direct control of individual devices. Use for emergency situations or specific adjustments.</li>
            <li><strong>Device Status:</strong> Check the status badges to see if devices are ON, OFF, or UNKNOWN.</li>
        </ul>
        <div class="highlight">
            <strong>Note:</strong> Manual overrides are for emergency use only. The system is designed to work best in Auto mode.
        </div>
    </div>

    <div class="step">
        <h3>5. Recording Mushroom Growth</h3>
        <p>Track your mushroom cultivation progress manually:</p>
        <ul>
            <li><strong>Add Records:</strong> Note the date, mushroom count, growth stage, and any observations.</li>
            <li><strong>Growth Stages:</strong> Common stages include Spawn Run, Primordia Formation, Fruiting, and Harvest.</li>
            <li><strong>Monthly Tracking:</strong> Records are organized by month for easy progress monitoring.</li>
        </ul>
    </div>

    <div class="step">
        <h3>6. Viewing Reports</h3>
        <p>Access detailed reports to analyze trends:</p>
        <ul>
            <li><strong>Sensor Data Report:</strong> View temperature and humidity readings over the last 7 days.</li>
            <li><strong>Changes Report:</strong> See daily changes in average conditions and overall trends.</li>
            <li><strong>Data Export:</strong> Reports are automatically saved to the database for long-term analysis.</li>
        </ul>
    </div>

    <div class="step">
        <h3>7. System Profile Management</h3>
        <p>Manage your account and system settings:</p>
        <ul>
            <li><strong>Profile Update:</strong> Edit your personal information and contact details.</li>
            <li><strong>Password Change:</strong> Update your password regularly for security.</li>
            <li><strong>Activity Log:</strong> View your recent actions and system interactions.</li>
            <li><strong>User Management (Owner Only):</strong> Approve new users, edit existing accounts, or manage staff access.</li>
        </ul>
    </div>

    <div class="step">
        <h3>8. Best Practices and Tips</h3>
        <ul>
            <li><strong>Regular Monitoring:</strong> Check the dashboard daily to ensure optimal conditions.</li>
            <li><strong>Alert Response:</strong> Address alerts promptly to prevent crop loss.</li>
            <li><strong>Data Accuracy:</strong> Keep sensor data up-to-date for reliable automation.</li>
            <li><strong>Manual Records:</strong> Regularly update mushroom growth records for better tracking.</li>
            <li><strong>System Maintenance:</strong> Ensure sensors and devices are clean and functioning properly.</li>
            <li><strong>Security:</strong> Use strong passwords and log out when not using the system.</li>
        </ul>
    </div>

    <div class="step">
        <h3>9. Troubleshooting</h3>
        <p>If you encounter issues:</p>
        <ul>
            <li><strong>Offline Sensors:</strong> Check device connections and power supply.</li>
            <li><strong>Alerts Not Clearing:</strong> Verify environmental conditions and device responses.</li>
            <li><strong>Login Issues:</strong> Ensure your account is approved and credentials are correct.</li>
            <li><strong>Manual Controls Not Working:</strong> Switch to Manual mode and try again.</li>
        </ul>
        <div class="highlight">
            <strong>Contact Support:</strong> If problems persist, contact the system administrator or owner for assistance.
        </div>
    </div>

    <div class="step">
        <h3>10. Safety and Maintenance</h3>
        <ul>
            <li><strong>Electrical Safety:</strong> Ensure all devices are properly grounded and protected from moisture.</li>
            <li><strong>Cleanliness:</strong> Keep the incubation chamber and sensors clean to prevent contamination.</li>
            <li><strong>Regular Calibration:</strong> Have sensors calibrated periodically for accurate readings.</li>
            <li><strong>Backup Power:</strong> Consider backup power sources for uninterrupted operation.</li>
        </ul>
    </div>
</div>

<footer>
    © 2025 J WHO? Mushroom Incubation System — All Rights Reserved
</footer>

<script>
function scrollToInstructions() {
    const instructionsSection = document.getElementById('instructions');
    if (instructionsSection) {
        instructionsSection.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
}

// Handle title fade on scroll
window.addEventListener('scroll', function() {
    const titleText = document.querySelector('.title-text');
    const scrollPosition = window.scrollY;

    // Fade out title when scrolling down (after 100px scroll)
    if (scrollPosition > 100) {
        titleText.style.opacity = '0';
    } else {
        titleText.style.opacity = '1';
    }
});
</script>

</body>
</html>
