<?php  
include 'includes/db_connect.php';
session_start();

if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>J WHO? Mushroom Incubation System</title>
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
    display: flex;
    flex-direction: column;
  }

  /* ── NAV ── */
  nav {
    position: fixed; top: 0; left: 0; right: 0;
    z-index: 100;
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 48px;
    border-bottom: 1px solid rgba(245,240,232,0.07);
    backdrop-filter: blur(14px);
    background: rgba(15,14,12,0.65);
  }

  .nav-brand {
    display: flex; align-items: center; gap: 14px;
    flex-shrink: 0;
  }
  .nav-brand img { width: 42px; height: 42px; object-fit: contain; }
  .nav-brand-text {
    font-family: 'Syne', sans-serif;
    font-size: 15px; font-weight: 700;
    letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--cream); line-height: 1.3;
  }
  .nav-brand-sub {
    display: block;
    font-family: 'DM Mono', monospace;
    font-size: 10px; font-weight: 300;
    letter-spacing: 0.15em; color: var(--spore);
    text-transform: uppercase;
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
    padding: 9px 22px;
    border-radius: 100px;
    border: 1px solid rgba(245,240,232,0.2);
    background: transparent;
    color: var(--cream);
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none; display: inline-block;
    white-space: nowrap;
  }
  .pill-btn:hover { background: var(--fog); border-color: var(--spore); color: var(--spore); }
  .pill-btn.filled {
    background: var(--spore); border-color: var(--spore);
    color: var(--ink); font-weight: 500;
  }
  .pill-btn.filled:hover { background: #d4b97d; border-color: #d4b97d; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(196,169,109,0.3); }

  /* ── HERO ── */
  .hero {
    flex: 1;
    min-height: 100vh;
    display: grid;
    grid-template-columns: 1fr 1fr;
    position: relative;
    overflow: hidden;
  }

  .hero-bg {
    position: absolute; inset: 0;
    background: url('assets/img/bg-mushroom.jpg') center/cover no-repeat;
    opacity: 0.12; filter: grayscale(30%); z-index: 0;
  }

  .hero-blob {
    position: absolute; top: -10%; right: -5%;
    width: 55vw; height: 85vh;
    background: radial-gradient(ellipse at 60% 40%, rgba(46,74,46,0.45) 0%, rgba(107,143,94,0.15) 50%, transparent 80%);
    border-radius: 30% 70% 60% 40% / 40% 30% 70% 60%;
    z-index: 1;
    animation: blobFloat 14s ease-in-out infinite alternate;
  }
  @keyframes blobFloat {
    0%   { border-radius: 30% 70% 60% 40% / 40% 30% 70% 60%; transform: scale(1); }
    50%  { border-radius: 60% 40% 30% 70% / 60% 70% 30% 40%; }
    100% { border-radius: 40% 60% 70% 30% / 30% 40% 60% 70%; transform: scale(1.04); }
  }

  .spores { position: absolute; inset: 0; z-index: 1; pointer-events: none; overflow: hidden; }
  .spore {
    position: absolute; border-radius: 50%;
    background: var(--spore); opacity: 0;
    animation: sporeRise var(--dur) ease-in var(--delay) infinite;
  }
  @keyframes sporeRise {
    0%   { opacity: 0; transform: translateY(0) scale(0.5); }
    15%  { opacity: 0.6; }
    100% { opacity: 0; transform: translateY(-60vh) scale(1.2); }
  }

  .scanline {
    position: absolute; inset: 0; z-index: 2; pointer-events: none;
    background: repeating-linear-gradient(0deg, transparent, transparent 3px, rgba(0,0,0,0.03) 4px);
  }

  /* Hero content */
  .hero-content {
    grid-column: 1; position: relative; z-index: 5;
    display: flex; flex-direction: column; justify-content: center;
    padding: 130px 64px 80px;
  }

  .hero-tag {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 11px; letter-spacing: 0.2em; text-transform: uppercase;
    color: var(--spore); margin-bottom: 32px;
    animation: fadeSlideUp 0.8s 0.2s both;
  }
  .hero-tag::before { content: ''; display: inline-block; width: 28px; height: 1px; background: var(--spore); }

  .hero-title {
    font-family: 'DM Serif Display', serif;
    font-size: clamp(52px, 5.5vw, 80px);
    line-height: 1.05; font-weight: 400;
    margin-bottom: 28px;
    animation: fadeSlideUp 0.8s 0.35s both;
  }
  .hero-title em { font-style: italic; color: var(--sage); display: block; }
  .hero-title .accent-word { position: relative; display: inline-block; }
  .hero-title .accent-word::after {
    content: ''; position: absolute;
    bottom: 6px; left: 0; right: 0; height: 3px;
    background: var(--spore);
    transform: scaleX(0); transform-origin: left;
    animation: underlineReveal 0.6s 1.2s forwards;
  }
  @keyframes underlineReveal { to { transform: scaleX(1); } }

  .hero-desc {
    font-size: 15px; line-height: 1.85;
    max-width: 460px; color: rgba(245,240,232,0.65);
    margin-bottom: 52px;
    animation: fadeSlideUp 0.8s 0.5s both;
  }

  .hero-cta {
    display: flex; align-items: center; gap: 20px;
    animation: fadeSlideUp 0.8s 0.65s both;
  }

  .cta-primary {
    display: inline-flex; align-items: center; gap: 12px;
    font-family: 'Syne', sans-serif;
    font-size: 14px; font-weight: 600;
    letter-spacing: 0.08em; text-transform: uppercase;
    padding: 16px 36px;
    background: var(--moss); border: 1px solid var(--sage);
    color: var(--cream); border-radius: 4px;
    cursor: pointer; transition: all 0.3s ease;
    position: relative; overflow: hidden; text-decoration: none;
  }
  .cta-primary::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(107,143,94,0.3), transparent);
    transform: translateX(-100%); transition: transform 0.4s ease;
  }
  .cta-primary:hover::before { transform: translateX(0); }
  .cta-primary:hover { box-shadow: 0 0 40px rgba(46,74,46,0.5); transform: translateY(-2px); }
  .cta-arrow { display: inline-block; font-size: 18px; transition: transform 0.3s ease; }
  .cta-primary:hover .cta-arrow { transform: translateX(4px); }

  .cta-secondary {
    font-size: 13px; letter-spacing: 0.1em; text-transform: uppercase;
    color: rgba(245,240,232,0.5); background: none; border: none;
    cursor: pointer; padding: 0;
    text-decoration: underline; text-underline-offset: 4px;
    text-decoration-color: transparent; transition: all 0.2s;
  }
  .cta-secondary:hover { color: var(--spore); text-decoration-color: var(--spore); }



  /* Hero visual */
  .hero-visual {
    grid-column: 2; position: relative; z-index: 3;
    display: flex; align-items: center; justify-content: center;
    padding: 130px 48px 80px;
  }

  .env-card {
    background: rgba(245,240,232,0.04);
    border: 1px solid rgba(245,240,232,0.1);
    border-radius: 16px; backdrop-filter: blur(20px);
    padding: 32px; width: 100%; max-width: 380px;
    animation: fadeSlideUp 0.8s 0.7s both, cardFloat 6s ease-in-out infinite;
    box-shadow: 0 40px 80px rgba(0,0,0,0.4), inset 0 1px 0 rgba(245,240,232,0.08);
  }
  @keyframes cardFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }

  .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
  .card-title { font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; color: rgba(245,240,232,0.45); }
  .status-dot { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--sage); letter-spacing: 0.1em; }
  .status-dot::before {
    content: ''; width: 7px; height: 7px; border-radius: 50%;
    background: var(--sage); box-shadow: 0 0 8px var(--sage);
    animation: pulse 2s infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.5;transform:scale(1.3)} }

  .gauge-row { display: flex; gap: 20px; margin-bottom: 28px; }
  .gauge {
    flex: 1; background: rgba(245,240,232,0.04);
    border: 1px solid rgba(245,240,232,0.08);
    border-radius: 12px; padding: 18px;
    position: relative; overflow: hidden;
  }
  .gauge::before { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; }
  .gauge.temp::before { background: linear-gradient(90deg, var(--moss), var(--sage)); }
  .gauge.humid::before { background: linear-gradient(90deg, #2a5f7a, #4a9bbf); }
  .gauge-label { font-size: 10px; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(245,240,232,0.4); margin-bottom: 8px; }
  .gauge-value { font-family: 'DM Serif Display', serif; font-size: 32px; line-height: 1; color: var(--cream); }
  .gauge-unit { font-size: 14px; opacity: 0.5; }
  .gauge-range { font-size: 10px; color: rgba(245,240,232,0.35); margin-top: 6px; }

  .device-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .device-item {
    display: flex; align-items: center; gap: 10px;
    background: rgba(245,240,232,0.03); border: 1px solid rgba(245,240,232,0.07);
    border-radius: 10px; padding: 12px;
  }
  .device-icon { font-size: 18px; }
  .device-info { flex: 1; min-width: 0; }
  .device-name { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(245,240,232,0.5); margin-bottom: 2px; }
  .device-status { font-size: 12px; font-weight: 500; }
  .device-status.on { color: var(--sage); }
  .device-status.off { color: rgba(245,240,232,0.3); }
  .toggle { width: 32px; height: 18px; background: rgba(245,240,232,0.1); border-radius: 100px; position: relative; flex-shrink: 0; }
  .toggle.active { background: var(--moss); }
  .toggle::after {
    content: ''; position: absolute; top: 3px; left: 3px;
    width: 12px; height: 12px; border-radius: 50%;
    background: rgba(245,240,232,0.5); transition: transform 0.2s;
  }
  .toggle.active::after { transform: translateX(14px); background: var(--sage); }

  /* ── FOOTER ── */
  footer {
    position: relative; z-index: 5;
    border-top: 1px solid rgba(245,240,232,0.07);
    padding: 24px 48px;
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(0,0,0,0.3);
  }
  .footer-copy { font-size: 12px; letter-spacing: 0.06em; color: rgba(245,240,232,0.3); }
  .footer-brand { font-family: 'Syne', sans-serif; font-size: 12px; font-weight: 600; color: rgba(245,240,232,0.2); letter-spacing: 0.1em; text-transform: uppercase; }

  @keyframes fadeSlideUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* ── RESPONSIVE ── */
  @media (max-width: 1100px) {
    nav { padding: 16px 32px; }
    .ph-time-chip { display: none; }
  }
  @media (max-width: 1024px) {
    .hero { grid-template-columns: 1fr; }
    .hero-visual { display: none; }
    .hero-content { padding: 130px 40px 120px; }
    .hero-stats { left: 40px; }
    footer { padding: 20px 40px; }
  }
  @media (max-width: 640px) {
    nav { padding: 14px 20px; gap: 12px; }
    .nav-brand img { width: 34px; height: 34px; }
    .nav-brand-text { font-size: 13px; }
    .pill-btn { padding: 7px 14px; font-size: 10px; }
    .hero-content { padding: 110px 24px 100px; }
    .hero-stats { left: 24px; bottom: 32px; gap: 28px; }
    .hero-stats .stat-value { font-size: 22px; }
    footer { padding: 18px 24px; flex-direction: column; gap: 6px; text-align: center; }
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
    <a href="docs.php" class="pill-btn">How It Works</a>
    <a href="index.php" class="pill-btn filled">Log In</a>
  </div>
</nav>

<!-- ── HERO ── -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-blob"></div>
  <div class="scanline"></div>
  <div class="spores" id="spores"></div>

  <div class="hero-content">
    <div class="hero-tag">Smart Cultivation System</div>

    <h1 class="hero-title">
      <span class="accent-word">Precision</span>
      <em>incubation</em>
      for reliable<br>yields
    </h1>

    <p class="hero-desc">
      A modern monitoring system designed to optimize mushroom
      growth conditions with automated tracking, real-time environment
      control, and data-driven insights for consistently healthy yields.
    </p>

    <div class="hero-cta">
      <a href="index.php" class="cta-primary">
        Access Dashboard
        <span class="cta-arrow">→</span>
      </a>
      <a href="docs.php" class="cta-secondary">Learn how it works</a>
    </div>
  </div>



  <!-- Live env preview card -->
  <div class="hero-visual">
    <div class="env-card">
      <div class="card-header">
        <span class="card-title">Live Environment</span>
        <span class="status-dot">Online</span>
      </div>
      <div class="gauge-row">
        <div class="gauge temp">
          <div class="gauge-label">Temperature</div>
          <div class="gauge-value">25.4<span class="gauge-unit">°C</span></div>
          <div class="gauge-range">Ideal: 22–28°C</div>
        </div>
        <div class="gauge humid">
          <div class="gauge-label">Humidity</div>
          <div class="gauge-value">91<span class="gauge-unit">%</span></div>
          <div class="gauge-range">Ideal: 85–95%</div>
        </div>
      </div>
      <div class="device-grid">
        <div class="device-item">
          <span class="device-icon">💧</span>
          <div class="device-info"><div class="device-name">Mist</div><div class="device-status on">Active</div></div>
          <div class="toggle active"></div>
        </div>
        <div class="device-item">
          <span class="device-icon">🌀</span>
          <div class="device-info"><div class="device-name">Fan</div><div class="device-status off">Idle</div></div>
          <div class="toggle"></div>
        </div>
        <div class="device-item">
          <span class="device-icon">🔥</span>
          <div class="device-info"><div class="device-name">Heater</div><div class="device-status off">Idle</div></div>
          <div class="toggle"></div>
        </div>
        <div class="device-item">
          <span class="device-icon">🌿</span>
          <div class="device-info"><div class="device-name">Sprayer</div><div class="device-status off">Idle</div></div>
          <div class="toggle"></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── FOOTER ── -->
<footer>
  <span class="footer-copy">© 2025 J WHO? Mushroom Incubation System — All Rights Reserved</span>
  <span class="footer-brand">J WHO? MIS</span>
</footer>

<script>
  // PH Time clock (UTC+8)
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

  // Spore particles
  const sporesEl = document.getElementById('spores');
  for (let i = 0; i < 22; i++) {
    const s = document.createElement('div');
    s.className = 'spore';
    const size = Math.random() * 4 + 2;
    s.style.cssText = `width:${size}px;height:${size}px;left:${Math.random()*100}%;bottom:${Math.random()*30}%;--dur:${6+Math.random()*14}s;--delay:${Math.random()*12}s;`;
    sporesEl.appendChild(s);
  }
</script>
</body>
</html>