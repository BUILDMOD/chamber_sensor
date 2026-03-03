<?php  
include 'includes/db_connect.php';
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Documentation — J WHO? Mushroom Incubation System</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Mono:wght@300;400;500&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --ink: #0f0e0c;
    --cream: #f5f0e8;
    --moss: #2e4a2e;
    --sage: #6b8f5e;
    --spore: #c4a96d;
    --fog: rgba(245,240,232,0.06);
  }

  *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
  html { scroll-behavior: smooth; }

  body {
    background: var(--ink);
    color: var(--cream);
    font-family: 'DM Mono', monospace;
    overflow-x: hidden;
    min-height: 100vh;
  }

  /* ── NAV ── */
  nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 48px;
    border-bottom: 1px solid rgba(245,240,232,0.07);
    backdrop-filter: blur(14px);
    background: rgba(15,14,12,0.65);
  }

  .nav-brand { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
  .nav-brand img { width: 42px; height: 42px; object-fit: contain; }
  .nav-brand-text {
    font-family: 'Syne', sans-serif;
    font-size: 15px; font-weight: 700;
    letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--cream); line-height: 1.3;
  }
  .nav-brand-sub {
    display: block; font-family: 'DM Mono', monospace;
    font-size: 10px; font-weight: 300;
    letter-spacing: 0.15em; color: var(--spore); text-transform: uppercase;
  }

  /* PH Time */
  .ph-time-chip {
    display: flex; flex-direction: column; align-items: flex-end;
    flex-shrink: 0; line-height: 1.4;
  }
  .ph-time-value {
    font-size: 13px; font-weight: 500;
    color: rgba(245,240,232,0.8);
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.03em;
  }
  .ph-time-date {
    font-size: 11px;
    color: rgba(245,240,232,0.35);
    letter-spacing: 0.03em;
  }

  .nav-actions { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
  .pill-btn {
    font-family: 'DM Mono', monospace;
    font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase;
    padding: 9px 22px; border-radius: 100px;
    border: 1px solid rgba(245,240,232,0.2);
    background: transparent; color: var(--cream);
    cursor: pointer; transition: all 0.25s ease;
    text-decoration: none; display: inline-block; white-space: nowrap;
  }
  .pill-btn:hover { background: var(--fog); border-color: var(--spore); color: var(--spore); }
  .pill-btn.filled { background: var(--spore); border-color: var(--spore); color: var(--ink); font-weight: 500; }
  .pill-btn.filled:hover { background: #d4b97d; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(196,169,109,0.3); }

  /* ── PAGE HEADER ── */
  .page-header {
    position: relative; overflow: hidden;
    padding: 140px 64px 80px;
    border-bottom: 1px solid rgba(245,240,232,0.07);
  }

  .page-header-bg {
    position: absolute; inset: 0;
    background: url('assets/img/bg-mushroom.jpg') center 40%/cover no-repeat;
    opacity: 0.07; filter: grayscale(40%); z-index: 0;
  }
  .page-header-blob {
    position: absolute; top: -20%; right: 0;
    width: 50vw; height: 120%;
    background: radial-gradient(ellipse at 70% 50%, rgba(46,74,46,0.3) 0%, transparent 70%);
    z-index: 1; pointer-events: none;
  }

  .page-header-inner {
    position: relative; z-index: 2;
    max-width: 1100px; margin: 0 auto;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 40px;
  }

  .page-eyebrow {
    font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase;
    color: var(--spore); margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
    animation: fadeSlideUp 0.7s 0.1s both;
  }
  .page-eyebrow::before { content: ''; width: 20px; height: 1px; background: var(--spore); }

  .page-title {
    font-family: 'DM Serif Display', serif;
    font-size: clamp(40px, 4.5vw, 64px);
    line-height: 1.08; font-weight: 400;
    animation: fadeSlideUp 0.7s 0.2s both;
  }
  .page-title em { font-style: italic; color: var(--sage); }

  .page-subtitle {
    margin-top: 16px; font-size: 14px; line-height: 1.75;
    color: rgba(245,240,232,0.5); max-width: 480px;
    animation: fadeSlideUp 0.7s 0.3s both;
  }

  .page-header-meta {
    flex-shrink: 0; text-align: right;
    animation: fadeSlideUp 0.7s 0.35s both;
  }
  .meta-count {
    font-family: 'DM Serif Display', serif;
    font-size: 96px; line-height: 1;
    color: rgba(245,240,232,0.05); letter-spacing: -0.02em;
    user-select: none;
  }
  .meta-label {
    font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase;
    color: rgba(245,240,232,0.25); margin-top: -8px;
  }

  /* ── MAIN CONTENT ── */
  .docs-layout {
    max-width: 1100px; margin: 0 auto;
    display: grid; grid-template-columns: 220px 1fr;
    gap: 0; min-height: calc(100vh - 300px);
  }

  /* Sidebar TOC */
  .toc {
    border-right: 1px solid rgba(245,240,232,0.07);
    padding: 48px 32px 48px 0;
    position: sticky; top: 80px;
    align-self: start; height: calc(100vh - 100px);
    overflow-y: auto;
  }
  .toc::-webkit-scrollbar { width: 3px; }
  .toc::-webkit-scrollbar-track { background: transparent; }
  .toc::-webkit-scrollbar-thumb { background: rgba(245,240,232,0.1); border-radius: 2px; }

  .toc-label {
    font-size: 9px; letter-spacing: 0.25em; text-transform: uppercase;
    color: rgba(245,240,232,0.25); margin-bottom: 20px; padding-left: 16px;
  }
  .toc-list { list-style: none; display: flex; flex-direction: column; gap: 2px; }
  .toc-item a {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 12px; letter-spacing: 0.04em;
    color: rgba(245,240,232,0.4);
    text-decoration: none; transition: all 0.2s;
  }
  .toc-item a:hover { background: rgba(245,240,232,0.04); color: var(--cream); }
  .toc-item a.active { background: rgba(107,143,94,0.1); color: var(--sage); }
  .toc-num { font-size: 10px; color: var(--spore); opacity: 0.6; width: 18px; flex-shrink: 0; }

  /* Steps content */
  .steps-content {
    padding: 48px 0 80px 56px;
  }

  .steps-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2px;
    background: rgba(245,240,232,0.06);
    border: 1px solid rgba(245,240,232,0.06);
    border-radius: 16px;
    overflow: hidden;
  }

  .step-card {
    background: var(--ink);
    padding: 40px;
    position: relative; transition: background 0.3s ease; overflow: hidden;
  }
  .step-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--moss), transparent);
    transform: scaleX(0); transform-origin: left; transition: transform 0.4s ease;
  }
  .step-card:hover { background: rgba(46,74,46,0.08); }
  .step-card:hover::before { transform: scaleX(1); }

  .step-num { font-family: 'DM Mono', monospace; font-size: 11px; letter-spacing: 0.15em; color: var(--spore); opacity: 0.7; margin-bottom: 14px; }
  .step-icon { font-size: 28px; margin-bottom: 16px; display: block; }
  .step-heading { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 700; color: var(--cream); margin-bottom: 14px; letter-spacing: -0.01em; }
  .step-body { font-size: 13px; line-height: 1.8; color: rgba(245,240,232,0.55); }
  .step-body ul { list-style: none; display: flex; flex-direction: column; gap: 6px; }
  .step-body li { padding-left: 14px; position: relative; }
  .step-body li::before { content: '→'; position: absolute; left: 0; color: var(--sage); font-size: 11px; }
  .step-body strong { color: rgba(245,240,232,0.8); font-weight: 500; }

  .callout {
    display: flex; gap: 12px; align-items: flex-start;
    background: rgba(196,169,109,0.07);
    border: 1px solid rgba(196,169,109,0.2);
    border-radius: 8px; padding: 14px 16px; margin-top: 16px;
    font-size: 13px; line-height: 1.6; color: rgba(245,240,232,0.6);
  }
  .callout::before { content: '◆'; color: var(--spore); font-size: 10px; margin-top: 3px; flex-shrink: 0; }

  /* ── FOOTER ── */
  footer {
    border-top: 1px solid rgba(245,240,232,0.07);
    padding: 24px 48px;
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(0,0,0,0.3);
  }
  .footer-copy { font-size: 12px; letter-spacing: 0.06em; color: rgba(245,240,232,0.3); }
  .footer-brand { font-family: 'Syne', sans-serif; font-size: 12px; font-weight: 600; color: rgba(245,240,232,0.2); letter-spacing: 0.1em; text-transform: uppercase; }

  @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

  /* ── RESPONSIVE ── */
  @media (max-width: 1100px) {
    nav { padding: 16px 32px; }
    .ph-time-chip { display: none; }
    .page-header { padding: 130px 40px 64px; }
    .docs-layout { grid-template-columns: 1fr; }
    .toc { display: none; }
    .steps-content { padding: 40px 40px 64px; }
  }
  @media (max-width: 768px) {
    .steps-grid { grid-template-columns: 1fr; }
    .page-header-meta { display: none; }
    .page-header { padding: 120px 24px 56px; }
    .steps-content { padding: 32px 24px 56px; }
    nav { padding: 14px 20px; }
    .nav-brand-text { font-size: 13px; }
    .pill-btn { padding: 7px 14px; font-size: 10px; }
    footer { padding: 18px 24px; flex-direction: column; gap: 6px; text-align: center; }
    .step-card { padding: 28px 24px; }
  }
</style>
</head>
<body>

<!-- ── NAV ── -->
<nav>
  <div class="nav-brand">
    <img src="assets/img/logo.png" alt="Logo">
    <div>
      <div class="nav-brand-text">J WHO?</div>
      <span class="nav-brand-sub">Mushroom Incubation</span>
    </div>
  </div>

  <div class="nav-actions">
    <div class="ph-time-chip">
      <span class="ph-time-value" id="phTime">--:-- --</span>
      <span class="ph-time-date" id="phDate">---, --- --, ----</span>
    </div>
    <a href="homepage.php" class="pill-btn">← Home</a>
    <a href="index.php" class="pill-btn filled">Log In</a>
  </div>
</nav>

<!-- ── PAGE HEADER ── -->
<header class="page-header">
  <div class="page-header-bg"></div>
  <div class="page-header-blob"></div>
  <div class="page-header-inner">
    <div>
      <div class="page-eyebrow">System Documentation</div>
      <h1 class="page-title">How to use<br><em>the system</em></h1>
      <p class="page-subtitle">
        Everything you need to monitor, control, and maintain your
        mushroom incubation chamber — from first login to harvest.
      </p>
    </div>
    <div class="page-header-meta">
      <div class="meta-count">10</div>
      <div class="meta-label">Sections</div>
    </div>
  </div>
</header>

<!-- ── DOCS LAYOUT ── -->
<div class="docs-layout">

  <!-- Sidebar TOC -->
  <aside class="toc">
    <div class="toc-label">Contents</div>
    <ul class="toc-list">
      <li class="toc-item"><a href="#s1"><span class="toc-num">01</span> Getting Started</a></li>
      <li class="toc-item"><a href="#s2"><span class="toc-num">02</span> Dashboard</a></li>
      <li class="toc-item"><a href="#s3"><span class="toc-num">03</span> Environment</a></li>
      <li class="toc-item"><a href="#s4"><span class="toc-num">04</span> Device Control</a></li>
      <li class="toc-item"><a href="#s5"><span class="toc-num">05</span> Growth Records</a></li>
      <li class="toc-item"><a href="#s6"><span class="toc-num">06</span> Reports</a></li>
      <li class="toc-item"><a href="#s7"><span class="toc-num">07</span> Profile</a></li>
      <li class="toc-item"><a href="#s8"><span class="toc-num">08</span> Best Practices</a></li>
      <li class="toc-item"><a href="#s9"><span class="toc-num">09</span> Troubleshooting</a></li>
      <li class="toc-item"><a href="#s10"><span class="toc-num">10</span> Safety</a></li>
    </ul>
  </aside>

  <!-- Steps -->
  <main class="steps-content">
    <div class="steps-grid">

      <div class="step-card" id="s1">
        <div class="step-num">01 / Getting Started</div>
        <span class="step-icon">🚀</span>
        <div class="step-heading">First-time Setup</div>
        <div class="step-body">
          <ul>
            <li><strong>Registration:</strong> Click "Create an account" on the login page and fill in your details — approval from the owner is required before access is granted.</li>
            <li><strong>Login:</strong> Use your approved username and password to sign in.</li>
            <li><strong>Dashboard:</strong> After login you'll be redirected to the main control panel.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s2">
        <div class="step-num">02 / Dashboard</div>
        <span class="step-icon">📊</span>
        <div class="step-heading">Understanding the Dashboard</div>
        <div class="step-body">
          <ul>
            <li><strong>Live Status:</strong> Real-time temperature and humidity gauges with color-coded indicators.</li>
            <li><strong>Device Control:</strong> Toggle between <strong>Auto</strong> and <strong>Manual</strong> modes for full automation or direct control.</li>
            <li><strong>Alerts:</strong> Instant notifications when environmental conditions fall outside ideal ranges.</li>
            <li><strong>Mushroom Records:</strong> Log growth stages, counts, and field observations.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s3">
        <div class="step-num">03 / Environment</div>
        <span class="step-icon">🌡️</span>
        <div class="step-heading">Monitoring Conditions</div>
        <div class="step-body">
          <ul>
            <li><strong>Temperature:</strong> Target 22–28°C. Blue = too low, red = ideal, orange = too high.</li>
            <li><strong>Humidity:</strong> Target 85–95%. Blue = too low, green = ideal, red = too high.</li>
            <li><strong>Alerts:</strong> Address any out-of-range conditions promptly to prevent crop loss.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s4">
        <div class="step-num">04 / Control</div>
        <span class="step-icon">⚙️</span>
        <div class="step-heading">Device Control & Overrides</div>
        <div class="step-body">
          <ul>
            <li><strong>Auto Mode:</strong> Recommended. System automatically adjusts devices based on sensor readings.</li>
            <li><strong>Manual Mode:</strong> Direct control for emergencies or specific adjustments.</li>
            <li><strong>Status Badges:</strong> Check ON, OFF, or UNKNOWN states for each device.</li>
          </ul>
          <div class="callout">Manual overrides are for emergency use only. The system is optimized for Auto mode.</div>
        </div>
      </div>

      <div class="step-card" id="s5">
        <div class="step-num">05 / Records</div>
        <span class="step-icon">🍄</span>
        <div class="step-heading">Tracking Mushroom Growth</div>
        <div class="step-body">
          <ul>
            <li><strong>Add Records:</strong> Log the date, mushroom count, growth stage, and any observations.</li>
            <li><strong>Growth Stages:</strong> Spawn Run → Primordia Formation → Fruiting → Harvest.</li>
            <li><strong>Monthly View:</strong> Records are organized by month for easy progress monitoring.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s6">
        <div class="step-num">06 / Reports</div>
        <span class="step-icon">📈</span>
        <div class="step-heading">Viewing Reports & Analytics</div>
        <div class="step-body">
          <ul>
            <li><strong>Sensor Data:</strong> Temperature and humidity trends over the last 7 days.</li>
            <li><strong>Changes Report:</strong> Daily averages and overall environmental trend analysis.</li>
            <li><strong>Data Export:</strong> All reports are saved to the database for long-term review.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s7">
        <div class="step-num">07 / Profile</div>
        <span class="step-icon">👤</span>
        <div class="step-heading">Profile & System Management</div>
        <div class="step-body">
          <ul>
            <li><strong>Profile Update:</strong> Edit personal information and contact details at any time.</li>
            <li><strong>Password:</strong> Change your password regularly for account security.</li>
            <li><strong>Activity Log:</strong> Review your recent system interactions.</li>
            <li><strong>User Management (Owner):</strong> Approve new users and manage staff access levels.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s8">
        <div class="step-num">08 / Best Practices</div>
        <span class="step-icon">✅</span>
        <div class="step-heading">Tips for Optimal Results</div>
        <div class="step-body">
          <ul>
            <li>Check the dashboard daily to ensure conditions remain optimal.</li>
            <li>Address alerts promptly — delayed response can cause significant crop loss.</li>
            <li>Keep sensor data accurate for reliable automation decisions.</li>
            <li>Update mushroom growth records consistently for better long-term tracking.</li>
            <li>Use strong passwords and always log out when done.</li>
          </ul>
        </div>
      </div>

      <div class="step-card" id="s9">
        <div class="step-num">09 / Troubleshooting</div>
        <span class="step-icon">🔧</span>
        <div class="step-heading">Common Issues & Fixes</div>
        <div class="step-body">
          <ul>
            <li><strong>Offline Sensors:</strong> Check device connections and power supply.</li>
            <li><strong>Alerts Not Clearing:</strong> Verify environmental conditions and device responses.</li>
            <li><strong>Login Issues:</strong> Ensure your account is approved and credentials are correct.</li>
            <li><strong>Manual Controls:</strong> Switch to Manual mode explicitly before trying overrides.</li>
          </ul>
          <div class="callout">If problems persist, contact the system administrator or owner for assistance.</div>
        </div>
      </div>

      <div class="step-card" id="s10">
        <div class="step-num">10 / Safety</div>
        <span class="step-icon">🛡️</span>
        <div class="step-heading">Safety & Maintenance</div>
        <div class="step-body">
          <ul>
            <li><strong>Electrical Safety:</strong> Ensure all devices are properly grounded and protected from moisture.</li>
            <li><strong>Cleanliness:</strong> Keep the chamber and sensors clean to prevent contamination.</li>
            <li><strong>Calibration:</strong> Calibrate sensors periodically for accurate environmental readings.</li>
            <li><strong>Backup Power:</strong> Consider a UPS or backup power source for uninterrupted operation.</li>
          </ul>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- ── FOOTER ── -->
<footer>
  <span class="footer-copy">© 2025 J WHO? Mushroom Incubation System — All Rights Reserved</span>
  <span class="footer-brand">J WHO? MIS</span>
</footer>

<script>
  // PH Time clock
  function updatePHTime() {
    const now = new Date();
    const ph = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
    let h = ph.getHours(), m = ph.getMinutes(), s = ph.getSeconds();
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const pad = n => String(n).padStart(2, '0');
    document.getElementById('phTime').textContent = `${pad(h)}:${pad(m)}:${pad(s)} ${ampm}`;
    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    document.getElementById('phDate').textContent =
      `${days[ph.getDay()]}, ${months[ph.getMonth()]} ${ph.getDate()}, ${ph.getFullYear()}`;
  }
  updatePHTime();
  setInterval(updatePHTime, 1000);

  // Active TOC link on scroll
  const sections = document.querySelectorAll('.step-card[id]');
  const tocLinks = document.querySelectorAll('.toc-item a');
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        tocLinks.forEach(l => l.classList.remove('active'));
        const active = document.querySelector(`.toc-item a[href="#${e.target.id}"]`);
        if (active) active.classList.add('active');
      }
    });
  }, { threshold: 0.4 });
  sections.forEach(s => io.observe(s));

  // Step card scroll-in animation
  const cards = document.querySelectorAll('.step-card');
  const cardIO = new IntersectionObserver((entries) => {
    entries.forEach((e, i) => {
      if (e.isIntersecting) {
        e.target.style.animation = `fadeSlideUp 0.55s ${(i % 2) * 0.08}s both`;
        cardIO.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });
  cards.forEach(c => cardIO.observe(c));
</script>
</body>
</html>