import { useState, useEffect, useRef } from "react";

// ─── DESIGN TOKENS ──────────────────────────────────────────────────────────
const css = `
  @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&family=DM+Mono:wght@300;400;500&family=Outfit:wght@300;400;500;600&display=swap');

  :root {
    --bg:       #07090f;
    --bg2:      #0b0f1a;
    --surface:  #0f1525;
    --border:   #1c2540;
    --border2:  #243060;
    --gold:     #c8a84b;
    --gold-dim: #8a6f2e;
    --cyan:     #00d4ff;
    --cyan-dim: #005a70;
    --text:     #dde3f0;
    --muted:    #4a5a7a;
    --muted2:   #8090b0;
    --danger:   #ff4d6d;
    --green:    #00e5a0;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  html { scroll-behavior: smooth; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Outfit', sans-serif;
    font-weight: 400;
    line-height: 1.6;
    overflow-x: hidden;
  }

  /* Scrollbar */
  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }

  /* Grid overlay */
  .grid-bg {
    position: absolute; inset: 0; pointer-events: none;
    background-image:
      linear-gradient(rgba(200,168,75,0.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(200,168,75,0.03) 1px, transparent 1px);
    background-size: 60px 60px;
  }

  /* Noise texture */
  .noise::after {
    content: '';
    position: absolute; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    pointer-events: none;
    opacity: 0.4;
  }

  /* Fade-in animation */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  @keyframes fadeIn {
    from { opacity: 0; } to { opacity: 1; }
  }
  @keyframes pulse-gold {
    0%, 100% { box-shadow: 0 0 0 0 rgba(200,168,75,0.4); }
    50%       { box-shadow: 0 0 0 8px rgba(200,168,75,0); }
  }
  @keyframes scanline {
    0%   { transform: translateY(-100%); }
    100% { transform: translateY(100vh); }
  }
  @keyframes ticker {
    0%   { transform: translateX(0); }
    100% { transform: translateX(-50%); }
  }
  @keyframes glow-cyan {
    0%, 100% { opacity: 0.5; }
    50%       { opacity: 1; }
  }
  @keyframes float {
    0%, 100% { transform: translateY(0px); }
    50%       { transform: translateY(-8px); }
  }
  @keyframes counter-up {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .animate-fade-up { animation: fadeUp 0.7s ease both; }
  .animate-fade-in { animation: fadeIn 0.5s ease both; }

  /* Section spacing */
  section { position: relative; overflow: hidden; }

  /* Gold line accent */
  .gold-line {
    display: inline-block;
    width: 40px; height: 2px;
    background: var(--gold);
    margin-bottom: 16px;
    vertical-align: middle;
  }

  /* Label tag */
  .label-tag {
    display: inline-flex; align-items: center; gap: 8px;
    font-family: 'DM Mono', monospace;
    font-size: 11px; letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--gold);
    background: rgba(200,168,75,0.08);
    border: 1px solid rgba(200,168,75,0.2);
    padding: 6px 14px; border-radius: 2px;
    margin-bottom: 24px;
  }

  /* Button styles */
  .btn-primary {
    display: inline-flex; align-items: center; gap: 10px;
    background: var(--gold);
    color: #07090f;
    font-family: 'Outfit', sans-serif;
    font-weight: 600; font-size: 14px;
    letter-spacing: 0.05em;
    padding: 14px 28px; border-radius: 3px;
    border: none; cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
  }
  .btn-primary:hover {
    background: #ddc05e;
    transform: translateY(-1px);
    box-shadow: 0 8px 32px rgba(200,168,75,0.3);
  }
  .btn-outline {
    display: inline-flex; align-items: center; gap: 10px;
    background: transparent;
    color: var(--text);
    font-family: 'Outfit', sans-serif;
    font-weight: 500; font-size: 14px;
    padding: 13px 28px; border-radius: 3px;
    border: 1px solid var(--border2); cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
  }
  .btn-outline:hover {
    border-color: var(--gold);
    color: var(--gold);
    background: rgba(200,168,75,0.05);
  }

  /* Card */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 28px;
    transition: all 0.3s ease;
  }
  .card:hover {
    border-color: var(--border2);
    box-shadow: 0 16px 48px rgba(0,0,0,0.4);
    transform: translateY(-2px);
  }
  .card-gold:hover {
    border-color: rgba(200,168,75,0.3);
    box-shadow: 0 16px 48px rgba(200,168,75,0.08);
  }

  /* Section title */
  .section-title {
    font-family: 'Playfair Display', serif;
    font-size: clamp(32px, 4vw, 52px);
    font-weight: 700;
    line-height: 1.15;
    letter-spacing: -0.02em;
    margin-bottom: 20px;
  }
  .section-sub {
    font-size: 17px;
    color: var(--muted2);
    line-height: 1.7;
    max-width: 560px;
  }

  /* Divider */
  .divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border2), transparent);
    margin: 0;
  }

  /* Metric chip */
  .metric-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(0,212,255,0.08);
    border: 1px solid rgba(0,212,255,0.15);
    color: var(--cyan);
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    padding: 4px 10px; border-radius: 3px;
  }

  /* Feature icon */
  .feat-icon {
    width: 44px; height: 44px;
    background: rgba(200,168,75,0.08);
    border: 1px solid rgba(200,168,75,0.15);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    margin-bottom: 16px;
    flex-shrink: 0;
  }

  /* Container */
  .container {
    max-width: 1160px;
    margin: 0 auto;
    padding: 0 24px;
  }

  /* Nav */
  nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    transition: all 0.3s ease;
  }
  nav.scrolled {
    background: rgba(7,9,15,0.92);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
  }
  .nav-inner {
    display: flex; align-items: center; justify-content: space-between;
    height: 64px;
  }
  .nav-logo {
    font-family: 'Playfair Display', serif;
    font-size: 22px; font-weight: 700;
    color: var(--text);
    letter-spacing: -0.02em;
  }
  .nav-logo span { color: var(--gold); }
  .nav-links {
    display: flex; align-items: center; gap: 32px;
    list-style: none;
  }
  .nav-links a {
    font-size: 13px; font-weight: 500;
    color: var(--muted2);
    text-decoration: none;
    transition: color 0.2s;
    letter-spacing: 0.02em;
  }
  .nav-links a:hover { color: var(--text); }

  /* Ticker bar */
  .ticker-bar {
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    height: 32px; overflow: hidden;
    display: flex; align-items: center;
  }
  .ticker-track {
    display: flex; gap: 0;
    animation: ticker 30s linear infinite;
    white-space: nowrap;
  }
  .ticker-item {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 0 32px;
    font-family: 'DM Mono', monospace;
    font-size: 11px;
    color: var(--muted);
    border-right: 1px solid var(--border);
  }
  .ticker-item .up { color: var(--green); }
  .ticker-item .down { color: var(--danger); }

  /* Hero */
  .hero {
    min-height: 100vh;
    display: flex; flex-direction: column;
    justify-content: center;
    padding: 120px 0 80px;
    position: relative;
  }
  .hero-eyebrow {
    font-family: 'DM Mono', monospace;
    font-size: 11px; letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: 28px;
    display: flex; align-items: center; gap: 12px;
  }
  .hero-eyebrow::before {
    content: '';
    display: block; width: 32px; height: 1px;
    background: var(--gold);
  }
  .hero-title {
    font-family: 'Playfair Display', serif;
    font-size: clamp(48px, 6.5vw, 88px);
    font-weight: 900;
    line-height: 1.02;
    letter-spacing: -0.03em;
    margin-bottom: 28px;
    max-width: 780px;
  }
  .hero-title em {
    font-style: italic;
    color: var(--gold);
  }
  .hero-sub {
    font-size: 18px;
    color: var(--muted2);
    line-height: 1.75;
    max-width: 520px;
    margin-bottom: 44px;
  }
  .hero-cta-row {
    display: flex; align-items: center; gap: 16px;
    flex-wrap: wrap;
  }
  .hero-trust {
    margin-top: 64px;
    display: flex; align-items: center; gap: 32px;
    flex-wrap: wrap;
  }
  .hero-trust-item {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; color: var(--muted2);
  }
  .hero-trust-item .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--green);
    animation: pulse-gold 2s infinite;
  }

  /* Dashboard mockup */
  .dashboard-mockup {
    position: relative;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow:
      0 0 0 1px var(--border),
      0 32px 80px rgba(0,0,0,0.6),
      0 0 80px rgba(200,168,75,0.06);
    animation: float 6s ease-in-out infinite;
  }
  .mockup-bar {
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    height: 36px;
    display: flex; align-items: center;
    padding: 0 16px; gap: 8px;
  }
  .mockup-dot {
    width: 10px; height: 10px; border-radius: 50%;
  }
  .mockup-content {
    padding: 20px;
  }
  .mock-stat-row {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
    margin-bottom: 16px;
  }
  .mock-stat {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 12px;
  }
  .mock-stat-label {
    font-family: 'DM Mono', monospace;
    font-size: 10px; color: var(--muted);
    margin-bottom: 4px;
  }
  .mock-stat-val {
    font-family: 'DM Mono', monospace;
    font-size: 20px; font-weight: 500;
    color: var(--text);
  }
  .mock-stat-delta {
    font-family: 'DM Mono', monospace;
    font-size: 10px;
  }
  .mock-bar-chart {
    display: flex; align-items: flex-end; gap: 6px;
    height: 80px;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 12px;
  }
  .mock-bar {
    flex: 1; border-radius: 3px 3px 0 0;
    background: linear-gradient(180deg, var(--gold-dim), var(--gold));
    opacity: 0.7;
    transition: opacity 0.2s;
  }
  .mock-bar.active { opacity: 1; }
  .mock-bar.cyan {
    background: linear-gradient(180deg, var(--cyan-dim), var(--cyan));
  }
  .mock-list {
    display: flex; flex-direction: column; gap: 6px;
  }
  .mock-list-item {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 8px 12px;
    font-family: 'DM Mono', monospace;
    font-size: 11px;
  }
  .mock-list-label { color: var(--muted2); }
  .mock-list-score {
    color: var(--gold);
    background: rgba(200,168,75,0.1);
    padding: 2px 8px; border-radius: 2px;
  }

  /* Problem section */
  .problem-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
  }
  .problem-cell {
    background: var(--surface);
    padding: 32px 24px;
    transition: background 0.3s;
  }
  .problem-cell:hover { background: var(--bg2); }
  .problem-number {
    font-family: 'Playfair Display', serif;
    font-size: 48px; font-weight: 900;
    color: var(--border2);
    line-height: 1;
    margin-bottom: 12px;
  }
  .problem-text {
    font-size: 15px; color: var(--muted2);
    line-height: 1.6;
  }
  .problem-text strong { color: var(--text); display: block; margin-bottom: 4px; }

  /* Intelligence sections */
  .intel-section {
    padding: 100px 0;
  }
  .intel-layout {
    display: grid; grid-template-columns: 1fr 1fr; gap: 80px;
    align-items: center;
  }
  .intel-layout.reverse { direction: rtl; }
  .intel-layout.reverse > * { direction: ltr; }

  /* Feature list */
  .feature-list {
    display: flex; flex-direction: column; gap: 16px;
    margin-top: 32px;
  }
  .feature-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    transition: border-color 0.2s;
  }
  .feature-item:hover { border-color: var(--border2); }
  .feature-item-icon {
    font-size: 18px; flex-shrink: 0;
    width: 36px; height: 36px;
    background: rgba(200,168,75,0.08);
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
  }
  .feature-item-text { font-size: 14px; color: var(--muted2); line-height: 1.6; }
  .feature-item-text strong { color: var(--text); display: block; margin-bottom: 2px; font-size: 15px; }

  /* Data card mockup */
  .data-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0,0,0,0.5);
  }
  .data-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--bg2);
  }
  .data-card-title {
    font-family: 'DM Mono', monospace;
    font-size: 12px; color: var(--muted2);
    letter-spacing: 0.08em;
  }
  .live-dot {
    display: flex; align-items: center; gap: 6px;
    font-family: 'DM Mono', monospace;
    font-size: 10px; color: var(--green);
  }
  .live-dot::before {
    content: '';
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--green);
    animation: pulse-gold 1.5s infinite;
  }
  .data-card-body { padding: 20px; }

  /* Heatmap */
  .heatmap-grid {
    display: grid; grid-template-columns: repeat(12, 1fr); gap: 3px;
  }
  .heatmap-cell {
    aspect-ratio: 1;
    border-radius: 2px;
    background: var(--border);
    transition: background 0.3s;
  }

  /* Lead card */
  .lead-card {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 6px;
    margin-bottom: 8px;
    transition: border-color 0.2s;
  }
  .lead-card:hover { border-color: var(--gold-dim); }
  .lead-score {
    min-width: 40px; text-align: center;
    font-family: 'DM Mono', monospace;
    font-size: 18px; font-weight: 500;
  }
  .lead-info { flex: 1; }
  .lead-name { font-size: 14px; font-weight: 600; }
  .lead-meta {
    font-family: 'DM Mono', monospace;
    font-size: 11px; color: var(--muted);
  }
  .lead-channel {
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    padding: 3px 8px; border-radius: 2px;
  }

  /* Process steps */
  .process-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 0;
    position: relative;
  }
  .process-step {
    padding: 40px 28px;
    position: relative;
  }
  .process-step:not(:last-child)::after {
    content: '';
    position: absolute; right: 0; top: 52px;
    width: 1px; height: 60px;
    background: linear-gradient(180deg, var(--border2), transparent);
  }
  .step-num {
    font-family: 'Playfair Display', serif;
    font-size: 64px; font-weight: 900;
    color: var(--border);
    line-height: 1;
    margin-bottom: 16px;
    background: linear-gradient(180deg, var(--border2), var(--border));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .step-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
  .step-text { font-size: 14px; color: var(--muted2); line-height: 1.6; }

  /* Plans */
  .plans-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-top: 56px;
  }
  .plan-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 36px 28px;
    position: relative;
    transition: all 0.3s ease;
  }
  .plan-card:hover {
    border-color: var(--border2);
    transform: translateY(-4px);
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
  }
  .plan-card.featured {
    border-color: var(--gold);
    background: linear-gradient(135deg, var(--surface), rgba(200,168,75,0.04));
    box-shadow: 0 0 40px rgba(200,168,75,0.12);
  }
  .plan-badge {
    position: absolute; top: -12px; left: 28px;
    background: var(--gold);
    color: #07090f;
    font-family: 'DM Mono', monospace;
    font-size: 10px; font-weight: 500;
    letter-spacing: 0.1em;
    padding: 4px 12px; border-radius: 2px;
    text-transform: uppercase;
  }
  .plan-name {
    font-family: 'DM Mono', monospace;
    font-size: 12px; letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted2);
    margin-bottom: 12px;
  }
  .plan-price {
    font-family: 'Playfair Display', serif;
    font-size: 48px; font-weight: 700;
    line-height: 1;
    margin-bottom: 4px;
  }
  .plan-price span { font-size: 16px; font-weight: 400; color: var(--muted2); }
  .plan-desc { font-size: 14px; color: var(--muted2); margin-bottom: 28px; margin-top: 8px; }
  .plan-features { list-style: none; display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px; }
  .plan-features li {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 14px; color: var(--muted2);
  }
  .plan-features li::before {
    content: '✓';
    color: var(--gold);
    font-weight: 700;
    flex-shrink: 0;
    font-size: 12px;
    margin-top: 2px;
  }

  /* Counters */
  .counters-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
  }
  .counter-cell {
    background: var(--surface);
    padding: 40px 28px;
    text-align: center;
  }
  .counter-val {
    font-family: 'Playfair Display', serif;
    font-size: 52px; font-weight: 900;
    background: linear-gradient(135deg, var(--gold), #e8c96a);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    line-height: 1;
    margin-bottom: 8px;
  }
  .counter-label { font-size: 14px; color: var(--muted2); }

  /* FAQ */
  .faq-item {
    border-bottom: 1px solid var(--border);
    padding: 24px 0;
  }
  .faq-question {
    display: flex; justify-content: space-between; align-items: center;
    cursor: pointer;
    font-size: 16px; font-weight: 500;
    color: var(--text);
    gap: 16px;
  }
  .faq-question:hover { color: var(--gold); }
  .faq-icon {
    width: 24px; height: 24px;
    border: 1px solid var(--border2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 14px;
    color: var(--gold);
    transition: all 0.2s;
  }
  .faq-answer {
    font-size: 15px; color: var(--muted2);
    line-height: 1.7;
    overflow: hidden;
    transition: max-height 0.3s ease, padding 0.3s ease;
  }

  /* CTA section */
  .cta-section {
    padding: 120px 0;
    position: relative;
  }
  .cta-box {
    background: linear-gradient(135deg, var(--surface) 0%, rgba(200,168,75,0.06) 100%);
    border: 1px solid rgba(200,168,75,0.25);
    border-radius: 16px;
    padding: 80px 60px;
    text-align: center;
    position: relative;
    overflow: hidden;
  }
  .cta-box::before {
    content: '';
    position: absolute;
    top: -120px; left: 50%; transform: translateX(-50%);
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(200,168,75,0.08) 0%, transparent 70%);
    pointer-events: none;
  }

  /* Footer */
  footer {
    background: var(--bg2);
    border-top: 1px solid var(--border);
    padding: 60px 0 32px;
  }
  .footer-grid {
    display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 48px;
    margin-bottom: 48px;
  }
  .footer-links { list-style: none; display: flex; flex-direction: column; gap: 10px; }
  .footer-links a {
    font-size: 14px; color: var(--muted2);
    text-decoration: none;
    transition: color 0.2s;
  }
  .footer-links a:hover { color: var(--gold); }
  .footer-bottom {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 24px;
    border-top: 1px solid var(--border);
    font-family: 'DM Mono', monospace;
    font-size: 11px; color: var(--muted);
  }
  .footer-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 16px; }

  /* Responsive */
  @media (max-width: 768px) {
    .intel-layout { grid-template-columns: 1fr; gap: 48px; }
    .intel-layout.reverse { direction: ltr; }
    .footer-grid { grid-template-columns: 1fr 1fr; }
    .hero-title { font-size: 40px; }
    .cta-box { padding: 48px 28px; }
    .nav-links { display: none; }
    .mock-stat-row { grid-template-columns: repeat(2, 1fr); }
  }
`;

// ─── DATA ──────────────────────────────────────────────────────────────────
const TICKER_ITEMS = [
    { label: "BMW X5 · 2021 · Lisboa", score: "+94", up: true },
    { label: "Mercedes C220 · 2020 · Porto", score: "+87", up: true },
    { label: "Volkswagen Golf · 2019 · Braga", score: "-12", up: false },
    { label: "Audi A4 · 2022 · Setúbal", score: "+76", up: true },
    { label: "Toyota RAV4 · 2021 · Faro", score: "+91", up: true },
    { label: "Ford Focus · 2018 · Coimbra", score: "-8", up: false },
    { label: "Renault Megane · 2020 · Aveiro", score: "+62", up: true },
    { label: "Hyundai Tucson · 2022 · Lisboa", score: "+83", up: true },
];

const FAQS = [
    {
        q: "O Xplendor substitui o meu site atual?",
        a: "Não. O Xplendor integra-se com o site que já tens ou com os canais onde publicas as tuas viaturas — Standvirtual, OLX, site próprio ou WhatsApp. Não precisas de mudar nada no teu fluxo atual para começar a receber inteligência.",
    },
    {
        q: "Como é que o Xplendor sabe a origem real das minhas leads?",
        a: "Através do Xplendor Pixel e de links de rastreamento por viatura. Cada clique, cada formulário e cada interação fica registada com a viatura associada, o canal de origem e o timestamp — sem depender de análise manual.",
    },
    {
        q: "Preciso de um técnico para instalar o Xplendor?",
        a: "Para a maioria dos casos, não. Se tiveres site próprio, basta colar um snippet de duas linhas. Se usares os links de rastreamento gerados automaticamente, não precisas de tocar no site.",
    },
    {
        q: "Os dados financeiros do meu stand ficam privados?",
        a: "Sim. Os dados financeiros do teu stand — custo de aquisição, margens, lucro por viatura — são privados e nunca partilhados com outros stands. Usamos dados anonimizados e agregados para calcular benchmarks de mercado.",
    },
    {
        q: "O Xplendor funciona com stands de qualquer dimensão?",
        a: "Sim. Temos planos para stands com stock reduzido (10-30 viaturas) até grandes grupos com múltiplas unidades. O produto escala com o teu volume e as funcionalidades de Machine Learning ativam-se progressivamente com o crescimento do dataset.",
    },
];

const PLANS = [
    {
        name: "Start",
        price: "149",
        desc: "Para stands que querem começar a medir o que realmente funciona.",
        features: [
            "Até 30 viaturas ativas",
            "Tracking de leads por viatura",
            "Dashboard de performance básico",
            "Links de rastreamento ilimitados",
            "Relatório mensal automático",
            "Suporte por email",
        ],
    },
    {
        name: "Growth",
        price: "299",
        desc: "Para stands que querem crescer com dados e campanhas inteligentes.",
        features: [
            "Até 100 viaturas ativas",
            "Smart Budget — recomendação de investimento",
            "Demand Radar — procura por segmento",
            "Score de rentabilidade por viatura",
            "Integração Google Ads + Meta Ads",
            "Benchmark de mercado (anonimizado)",
            "Suporte prioritário",
        ],
        featured: true,
    },
    {
        name: "Pro",
        price: "599",
        desc: "Para grupos e stands com volume que exigem inteligência preditiva.",
        features: [
            "Viaturas ilimitadas",
            "Modelo preditivo de probabilidade de venda",
            "Market Insights — tendências e sazonalidade",
            "Priorização automática de leads",
            "Budget allocation automático",
            "API de integração (EasyData, CRM próprio)",
            "Gestor de conta dedicado",
        ],
    },
];

// ─── SUBCOMPONENTS ──────────────────────────────────────────────────────────
function Navbar() {
    const [scrolled, setScrolled] = useState(false);
    useEffect(() => {
        const fn = () => setScrolled(window.scrollY > 40);
        window.addEventListener("scroll", fn);
        return () => window.removeEventListener("scroll", fn);
    }, []);
    return (
        <nav className={scrolled ? "scrolled" : ""}>
            <div className="container">
                <div className="nav-inner">
                    <div className="nav-logo">Xplend<span>or</span></div>
                    <ul className="nav-links">
                        {["Produto", "Funcionalidades", "Planos", "FAQ"].map((l) => (
                            <li key={l}><a href={`#${l.toLowerCase()}`}>{l}</a></li>
                        ))}
                    </ul>
                    <a href="#contacto" className="btn-primary" style={{ padding: "10px 20px", fontSize: "13px" }}>
                        Pedir Demo
                    </a>
                </div>
            </div>
        </nav>
    );
}

function TickerBar() {
    const items = [...TICKER_ITEMS, ...TICKER_ITEMS];
    return (
        <div className="ticker-bar" style={{ marginTop: 64 }}>
            <div className="ticker-track">
                {items.map((item, i) => (
                    <div key={i} className="ticker-item">
                        <span style={{ color: "var(--muted2)" }}>●</span>
                        {item.label}
                        <span className={item.up ? "up" : "down"}>{item.score}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DashboardMockup() {
    const bars = [40, 65, 50, 80, 45, 92, 70, 55, 88, 62, 75, 95];
    return (
        <div className="dashboard-mockup">
            <div className="mockup-bar">
                <div className="mockup-dot" style={{ background: "#ff5f57" }} />
                <div className="mockup-dot" style={{ background: "#febc2e" }} />
                <div className="mockup-dot" style={{ background: "#28c840" }} />
                <span style={{ fontFamily: "'DM Mono',monospace", fontSize: 11, color: "var(--muted)", marginLeft: 8 }}>
                    xplendor.io/dashboard
                </span>
            </div>
            <div className="mockup-content">
                <div className="mock-stat-row">
                    {[
                        { label: "LEADS HOJE", val: "24", delta: "+8", up: true },
                        { label: "VIEWS/VIATURA", val: "3.2k", delta: "+14%", up: true },
                        { label: "SCORE MÉDIO", val: "78", delta: "+5", up: true },
                    ].map((s) => (
                        <div key={s.label} className="mock-stat">
                            <div className="mock-stat-label">{s.label}</div>
                            <div className="mock-stat-val">{s.val}</div>
                            <div className="mock-stat-delta" style={{ color: s.up ? "var(--green)" : "var(--danger)" }}>
                                {s.delta}
                            </div>
                        </div>
                    ))}
                </div>
                <div className="mock-bar-chart">
                    {bars.map((h, i) => (
                        <div
                            key={i}
                            className={`mock-bar ${i === 11 ? "active cyan" : i > 8 ? "active" : ""}`}
                            style={{ height: `${h}%` }}
                        />
                    ))}
                </div>
                <div className="mock-list">
                    {[
                        { label: "BMW X5 2021 · Diesel", score: 94 },
                        { label: "Mercedes C220 2020", score: 87 },
                        { label: "Audi A4 2022 · Híbrido", score: 81 },
                    ].map((item) => (
                        <div key={item.label} className="mock-list-item">
                            <span className="mock-list-label">{item.label}</span>
                            <span className="mock-list-score">{item.score}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function ProblemSection() {
    const problems = [
        { n: "01", title: "Pagas por cada viatura publicada", text: "Marketplaces cobram mensalidade fixa por listagem. Quanto maior o stock, maior o custo — independentemente do resultado." },
        { n: "02", title: "Não sabes o que converte", text: "Recebes leads mas não sabes se vieram do Google, do Standvirtual, do WhatsApp ou de uma campanha que pagaste." },
        { n: "03", title: "Não sabes que carros promover", text: "Investes em campanhas genéricas sem saber quais as viaturas com maior procura real ou maior probabilidade de venda." },
        { n: "04", title: "Decisions baseadas em intuição", text: "Sem dados de margem, tempo em stock e ROI por canal, as decisões de compra e preço são feitas no escuro." },
    ];
    return (
        <section style={{ padding: "100px 0" }} id="produto">
            <div className="container">
                <div style={{ marginBottom: 56 }}>
                    <div className="label-tag">O problema real</div>
                    <h2 className="section-title">
                        Os stands mais bem-sucedidos<br />
                        <em style={{ fontStyle: "italic", color: "var(--gold)" }}>já não dependem de marketplaces.</em>
                    </h2>
                    <p className="section-sub">
                        Dependência de plataformas externas significa margens comprimidas,
                        dados que não são teus e decisões comerciais cegas.
                    </p>
                </div>
                <div className="problem-grid">
                    {problems.map((p) => (
                        <div key={p.n} className="problem-cell">
                            <div className="problem-number">{p.n}</div>
                            <div className="problem-text">
                                <strong>{p.title}</strong>
                                {p.text}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function LeadIntelligence() {
    const leads = [
        { name: "Paulo Ferreira", model: "BMW X5 2021", channel: "Google Ads", score: 94, color: "var(--green)" },
        { name: "Ana Costa", model: "Mercedes C220", channel: "Meta Ads", score: 87, color: "var(--cyan)" },
        { name: "Rui Mendes", model: "Audi A4 2022", channel: "WhatsApp", score: 72, color: "var(--gold)" },
        { name: "Sofia Lima", model: "Toyota RAV4", channel: "Orgânico", score: 61, color: "var(--muted2)" },
    ];
    return (
        <section className="intel-section" id="funcionalidades" style={{ background: "var(--bg2)" }}>
            <div className="container">
                <div className="intel-layout">
                    <div>
                        <div className="label-tag">Lead Intelligence</div>
                        <h2 className="section-title">Sabe exatamente de onde vem cada lead</h2>
                        <p className="section-sub">
                            Cada contacto — formulário, WhatsApp, telefone, click em campanha —
                            fica registado com a viatura, o canal e o momento exacto.
                            Acabou-se o "veio do site" como única resposta.
                        </p>
                        <div className="feature-list">
                            {[
                                { icon: "📍", title: "Origem real de cada lead", text: "Google, Meta, Standvirtual, orgânico, WhatsApp ou referência direta — disambiguado automaticamente." },
                                { icon: "🎯", title: "Score de intenção", text: "Cada lead recebe uma pontuação baseada no comportamento: número de visitas, páginas vistas, tempo em viatura." },
                                { icon: "⚡", title: "Priorização automática", text: "A equipa comercial vê as leads por ordem de probabilidade de conversão, não por ordem de chegada." },
                            ].map((f) => (
                                <div key={f.title} className="feature-item">
                                    <div className="feature-item-icon">{f.icon}</div>
                                    <div className="feature-item-text">
                                        <strong>{f.title}</strong>{f.text}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="data-card">
                        <div className="data-card-header">
                            <span className="data-card-title">LEAD INTELLIGENCE · HOJE</span>
                            <span className="live-dot">LIVE</span>
                        </div>
                        <div className="data-card-body">
                            {leads.map((l) => (
                                <div key={l.name} className="lead-card">
                                    <div className="lead-score" style={{ color: l.color }}>{l.score}</div>
                                    <div className="lead-info">
                                        <div className="lead-name">{l.name}</div>
                                        <div className="lead-meta">{l.model}</div>
                                    </div>
                                    <div className="lead-channel" style={{
                                        background: `${l.color}15`,
                                        color: l.color,
                                        border: `1px solid ${l.color}30`,
                                    }}>
                                        {l.channel}
                                    </div>
                                </div>
                            ))}
                            <div style={{ marginTop: 16, padding: "12px 16px", background: "var(--bg)", borderRadius: 6, border: "1px solid var(--border)" }}>
                                <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 11, color: "var(--muted)", marginBottom: 8 }}>ORIGEM DE LEADS · 7 DIAS</div>
                                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                                    {[
                                        { label: "Google", pct: 42, color: "var(--cyan)" },
                                        { label: "Meta", pct: 28, color: "var(--gold)" },
                                        { label: "Orgânico", pct: 18, color: "var(--green)" },
                                        { label: "Outros", pct: 12, color: "var(--muted)" },
                                    ].map((o) => (
                                        <div key={o.label} style={{ flex: 1, minWidth: 60 }}>
                                            <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 10, color: "var(--muted)", marginBottom: 4 }}>{o.label}</div>
                                            <div style={{ height: 4, background: "var(--border)", borderRadius: 2, overflow: "hidden" }}>
                                                <div style={{ width: `${o.pct}%`, height: "100%", background: o.color, borderRadius: 2 }} />
                                            </div>
                                            <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 10, color: o.color, marginTop: 2 }}>{o.pct}%</div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function DemandRadar() {
    const segments = [
        { name: "SUV · Lisboa", demand: 94, trend: "+18%" },
        { name: "Berlina · Porto", demand: 87, trend: "+12%" },
        { name: "Elétrico · Nacional", demand: 91, trend: "+34%" },
        { name: "Familiar · Braga", demand: 73, trend: "+6%" },
        { name: "Desportivo · Lisboa", demand: 61, trend: "-4%" },
        { name: "Comercial · Setúbal", demand: 55, trend: "+2%" },
    ];
    return (
        <section className="intel-section">
            <div className="container">
                <div className="intel-layout reverse">
                    <div>
                        <div className="label-tag">Demand Radar</div>
                        <h2 className="section-title">Compra o que o mercado quer antes da concorrência</h2>
                        <p className="section-sub">
                            Sabes quais os segmentos com maior procura ativa na tua região,
                            quais os modelos com menor tempo de stock e onde existe
                            desequilíbrio entre oferta e procura.
                        </p>
                        <div className="feature-list">
                            {[
                                { icon: "📡", title: "Procura em tempo real por segmento", text: "Volume de pesquisa e intenção de compra por marca, modelo, região e combustível." },
                                { icon: "📈", title: "Tendências de mercado", text: "Sazonalidade, variações de CPL e desvios de preço face à mediana — atualizados semanalmente." },
                                { icon: "🔍", title: "Análise de gap", text: "Identifica segmentos com alta procura e baixa oferta no teu stock — oportunidades de compra." },
                            ].map((f) => (
                                <div key={f.title} className="feature-item">
                                    <div className="feature-item-icon">{f.icon}</div>
                                    <div className="feature-item-text">
                                        <strong>{f.title}</strong>{f.text}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="data-card">
                        <div className="data-card-header">
                            <span className="data-card-title">DEMAND RADAR · SEMANA</span>
                            <span className="metric-chip">PT · Todos os canais</span>
                        </div>
                        <div className="data-card-body">
                            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                                {segments.map((s) => (
                                    <div key={s.name} style={{ display: "flex", alignItems: "center", gap: 12 }}>
                                        <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 12, color: "var(--muted2)", minWidth: 160 }}>{s.name}</div>
                                        <div style={{ flex: 1, height: 6, background: "var(--border)", borderRadius: 3, overflow: "hidden" }}>
                                            <div style={{
                                                width: `${s.demand}%`, height: "100%", borderRadius: 3,
                                                background: s.demand > 85 ? "var(--green)" : s.demand > 70 ? "var(--gold)" : "var(--cyan-dim)",
                                                transition: "width 1s ease"
                                            }} />
                                        </div>
                                        <div style={{
                                            fontFamily: "'DM Mono',monospace", fontSize: 11,
                                            color: s.trend.startsWith("+") ? "var(--green)" : "var(--danger)",
                                            minWidth: 40, textAlign: "right"
                                        }}>{s.trend}</div>
                                    </div>
                                ))}
                            </div>
                            <div style={{ marginTop: 20, padding: 14, background: "rgba(0,229,160,0.05)", border: "1px solid rgba(0,229,160,0.15)", borderRadius: 6 }}>
                                <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 10, color: "var(--green)", marginBottom: 4 }}>▲ OPORTUNIDADE DETETADA</div>
                                <div style={{ fontSize: 13, color: "var(--text)" }}>Alta procura de SUV eléctrico em Lisboa sem oferta equivalente no teu stock.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function PerformanceSection() {
    const cars = [
        { name: "BMW X5 · 2021 · Diesel", price: "42.500€", views: 1840, leads: 12, score: 94, days: 8 },
        { name: "Mercedes C220 · 2020", price: "28.900€", views: 1230, leads: 8, score: 87, days: 14 },
        { name: "Audi A4 · 2022 · Híbrido", price: "34.200€", views: 980, leads: 6, score: 81, days: 19 },
        { name: "Toyota RAV4 · 2021", price: "31.500€", views: 740, leads: 4, score: 68, days: 27 },
    ];
    return (
        <section className="intel-section" style={{ background: "var(--bg2)" }}>
            <div className="container">
                <div style={{ textAlign: "center", marginBottom: 56, maxWidth: 640, margin: "0 auto 56px" }}>
                    <div className="label-tag">Performance por Viatura</div>
                    <h2 className="section-title">Cada viatura tem o seu próprio P&L</h2>
                    <p className="section-sub" style={{ margin: "0 auto" }}>
                        Views, leads, custo por lead, investimento em campanha e margem estimada
                        — por viatura, não por stand. Sabe qual carro merece mais atenção hoje.
                    </p>
                </div>
                <div className="data-card" style={{ maxWidth: 800, margin: "0 auto" }}>
                    <div className="data-card-header">
                        <span className="data-card-title">PERFORMANCE · STOCK ATIVO</span>
                        <span className="live-dot">ATUALIZADO HOJE</span>
                    </div>
                    <div className="data-card-body" style={{ padding: 0 }}>
                        <div style={{ overflowX: "auto" }}>
                            <table style={{ width: "100%", borderCollapse: "collapse" }}>
                                <thead>
                                    <tr style={{ borderBottom: "1px solid var(--border)" }}>
                                        {["Viatura", "Preço", "Views", "Leads", "Dias stock", "Score"].map((h) => (
                                            <th key={h} style={{ padding: "12px 16px", fontFamily: "'DM Mono',monospace", fontSize: 10, color: "var(--muted)", letterSpacing: "0.08em", textAlign: "left", whiteSpace: "nowrap" }}>{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {cars.map((c, i) => (
                                        <tr key={c.name} style={{ borderBottom: "1px solid var(--border)", transition: "background 0.2s" }}
                                            onMouseEnter={e => e.currentTarget.style.background = "var(--bg)"}
                                            onMouseLeave={e => e.currentTarget.style.background = "transparent"}>
                                            <td style={{ padding: "14px 16px", fontSize: 13, fontWeight: 500 }}>{c.name}</td>
                                            <td style={{ padding: "14px 16px", fontFamily: "'DM Mono',monospace", fontSize: 12, color: "var(--muted2)" }}>{c.price}</td>
                                            <td style={{ padding: "14px 16px", fontFamily: "'DM Mono',monospace", fontSize: 12 }}>{c.views.toLocaleString()}</td>
                                            <td style={{ padding: "14px 16px", fontFamily: "'DM Mono',monospace", fontSize: 12, color: "var(--cyan)" }}>{c.leads}</td>
                                            <td style={{ padding: "14px 16px", fontFamily: "'DM Mono',monospace", fontSize: 12, color: c.days > 20 ? "var(--danger)" : "var(--muted2)" }}>{c.days}d</td>
                                            <td style={{ padding: "14px 16px" }}>
                                                <div style={{
                                                    display: "inline-block",
                                                    background: c.score > 85 ? "rgba(0,229,160,0.1)" : c.score > 70 ? "rgba(200,168,75,0.1)" : "rgba(255,77,109,0.1)",
                                                    color: c.score > 85 ? "var(--green)" : c.score > 70 ? "var(--gold)" : "var(--danger)",
                                                    fontFamily: "'DM Mono',monospace", fontSize: 13, fontWeight: 500,
                                                    padding: "4px 10px", borderRadius: 3,
                                                    border: `1px solid ${c.score > 85 ? "rgba(0,229,160,0.2)" : c.score > 70 ? "rgba(200,168,75,0.2)" : "rgba(255,77,109,0.2)"}`,
                                                }}>{c.score}</div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function SmartBudget() {
    return (
        <section className="intel-section">
            <div className="container">
                <div className="intel-layout">
                    <div>
                        <div className="label-tag">Smart Budget</div>
                        <h2 className="section-title">O orçamento certo,<br />para a viatura certa</h2>
                        <p className="section-sub">
                            Não precisas de ser especialista em Google Ads ou Meta Ads.
                            O Smart Budget analisa o teu stock, o mercado e o histórico
                            de performance e diz-te onde investir cada euro.
                        </p>
                        <div className="feature-list">
                            {[
                                { icon: "💡", title: "Recomendação por viatura", text: "Quanto investir, em que canal e com que criativos para maximizar o ROI de cada viatura em stock." },
                                { icon: "⚙️", title: "Otimização automática", text: "Integração direta com Google Ads e Meta Ads. Ajuste de lances e orçamentos sem intervenção manual." },
                                { icon: "📊", title: "ROI real por campanha", text: "Correlação entre investimento em campanha, leads gerados e margens realizadas. Não ROAS genérico — lucro real." },
                            ].map((f) => (
                                <div key={f.title} className="feature-item">
                                    <div className="feature-item-icon">{f.icon}</div>
                                    <div className="feature-item-text">
                                        <strong>{f.title}</strong>{f.text}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div style={{ marginTop: 32, display: "flex", gap: 16, flexWrap: "wrap" }}>
                            <a href="#contacto" className="btn-primary">Pedir demonstração →</a>
                        </div>
                    </div>
                    <div className="data-card">
                        <div className="data-card-header">
                            <span className="data-card-title">SMART BUDGET · RECOMENDAÇÃO</span>
                            <span style={{ fontFamily: "'DM Mono',monospace", fontSize: 10, color: "var(--gold)", background: "rgba(200,168,75,0.1)", padding: "3px 8px", borderRadius: 2 }}>IA</span>
                        </div>
                        <div className="data-card-body">
                            <div style={{ marginBottom: 16, padding: "14px 16px", background: "rgba(200,168,75,0.05)", border: "1px solid rgba(200,168,75,0.15)", borderRadius: 6 }}>
                                <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 10, color: "var(--gold)", marginBottom: 4 }}>ORÇAMENTO TOTAL DO MÊS</div>
                                <div style={{ fontFamily: "'Playfair Display',serif", fontSize: 32, fontWeight: 700 }}>800€</div>
                                <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 11, color: "var(--muted2)" }}>distribuído por 6 viaturas prioritárias</div>
                            </div>
                            {[
                                { name: "BMW X5 2021", budget: 280, channel: "Google", roi: "4.2×" },
                                { name: "Mercedes C220", budget: 200, channel: "Meta", roi: "3.8×" },
                                { name: "Audi A4 2022", budget: 160, channel: "Google", roi: "3.1×" },
                                { name: "Toyota RAV4", budget: 160, channel: "Meta", roi: "2.9×" },
                            ].map((r) => (
                                <div key={r.name} style={{ display: "flex", alignItems: "center", gap: 12, padding: "10px 0", borderBottom: "1px solid var(--border)" }}>
                                    <div style={{ flex: 1 }}>
                                        <div style={{ fontSize: 13, fontWeight: 500 }}>{r.name}</div>
                                        <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 10, color: "var(--muted)" }}>{r.channel}</div>
                                    </div>
                                    <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 14, color: "var(--text)" }}>{r.budget}€</div>
                                    <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 12, color: "var(--green)", background: "rgba(0,229,160,0.1)", padding: "2px 8px", borderRadius: 2 }}>ROI {r.roi}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function WorkProcess() {
    const steps = [
        { n: "01", title: "Liga o teu stock", text: "Importa as tuas viaturas via integração EasyData, feed XML, ou adiciona manualmente. Em menos de 10 minutos está tudo visível." },
        { n: "02", title: "Ativa o tracking", text: "Instala o Xplendor Pixel no teu site ou usa os links de rastreamento gerados automaticamente por viatura. Zero dependência técnica." },
        { n: "03", title: "Regista as vendas", text: "Quando fechas uma venda, registas em 30 segundos: preço e custo. O Xplendor calcula o lucro e alimenta o modelo." },
        { n: "04", title: "Toma decisões com dados", text: "Dashboard diário com performance por viatura, origem de leads, oportunidades de mercado e recomendações de investimento." },
    ];
    return (
        <section style={{ padding: "100px 0", background: "var(--bg2)" }}>
            <div className="container">
                <div style={{ textAlign: "center", marginBottom: 56, maxWidth: 560, margin: "0 auto 56px" }}>
                    <div className="label-tag">Como funciona</div>
                    <h2 className="section-title">Operacional em menos de um dia</h2>
                </div>
                <div className="process-grid">
                    {steps.map((s) => (
                        <div key={s.n} className="process-step">
                            <div className="step-num">{s.n}</div>
                            <div className="step-title">{s.title}</div>
                            <div className="step-text">{s.text}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Counters() {
    const stats = [
        { val: "2×", label: "Mais leads qualificadas em 90 dias" },
        { val: "40%", label: "Redução do custo por lead" },
        { val: "6×", label: "Retorno médio sobre investimento em ads" },
        { val: "18d", label: "Tempo médio de stock com Smart Budget" },
    ];
    return (
        <section style={{ padding: "80px 0" }}>
            <div className="container">
                <div className="counters-row">
                    {stats.map((s) => (
                        <div key={s.val} className="counter-cell">
                            <div className="counter-val">{s.val}</div>
                            <div className="counter-label">{s.label}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Plans() {
    return (
        <section style={{ padding: "100px 0" }} id="planos">
            <div className="container">
                <div style={{ textAlign: "center", marginBottom: 0, maxWidth: 560, margin: "0 auto" }}>
                    <div className="label-tag">Planos</div>
                    <h2 className="section-title">Preço que acompanha o teu crescimento</h2>
                    <p className="section-sub" style={{ margin: "0 auto" }}>
                        Sem comissões por viatura. Sem surpresas.
                        Uma mensalidade fixa enquanto cresces.
                    </p>
                </div>
                <div className="plans-grid">
                    {PLANS.map((p) => (
                        <div key={p.name} className={`plan-card ${p.featured ? "featured" : ""}`}>
                            {p.featured && <div className="plan-badge">Mais popular</div>}
                            <div className="plan-name">{p.name}</div>
                            <div className="plan-price">{p.price}<span>€/mês</span></div>
                            <div className="plan-desc">{p.desc}</div>
                            <ul className="plan-features">
                                {p.features.map((f) => <li key={f}>{f}</li>)}
                            </ul>
                            <a href="#contacto" className={p.featured ? "btn-primary" : "btn-outline"} style={{ width: "100%", justifyContent: "center" }}>
                                Começar agora
                            </a>
                        </div>
                    ))}
                </div>
                <div style={{ textAlign: "center", marginTop: 32, fontFamily: "'DM Mono',monospace", fontSize: 12, color: "var(--muted)" }}>
                    Todos os planos incluem 14 dias de trial sem cartão de crédito.
                </div>
            </div>
        </section>
    );
}

function FAQSection() {
    const [open, setOpen] = useState(null);
    return (
        <section style={{ padding: "100px 0", background: "var(--bg2)" }} id="faq">
            <div className="container">
                <div style={{ display: "grid", gridTemplateColumns: "1fr 2fr", gap: 80, alignItems: "start" }}>
                    <div>
                        <div className="label-tag">FAQ</div>
                        <h2 className="section-title" style={{ fontSize: "clamp(28px,3vw,42px)" }}>Perguntas frequentes</h2>
                        <p style={{ fontSize: 15, color: "var(--muted2)", lineHeight: 1.7, marginTop: 16 }}>
                            Não encontras o que precisas? Fala diretamente connosco.
                        </p>
                        <div style={{ marginTop: 24 }}>
                            <a href="#contacto" className="btn-outline" style={{ fontSize: 13 }}>Contactar →</a>
                        </div>
                    </div>
                    <div>
                        {FAQS.map((f, i) => (
                            <div key={i} className="faq-item">
                                <div className="faq-question" onClick={() => setOpen(open === i ? null : i)}>
                                    {f.q}
                                    <div className="faq-icon">{open === i ? "−" : "+"}</div>
                                </div>
                                <div className="faq-answer" style={{ maxHeight: open === i ? 200 : 0, paddingTop: open === i ? 16 : 0, overflow: "hidden", transition: "all 0.3s ease" }}>
                                    {f.a}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function CTASection() {
    return (
        <section className="cta-section" id="contacto">
            <div className="container">
                <div className="cta-box">
                    <div className="label-tag" style={{ margin: "0 auto 24px" }}>Começa hoje</div>
                    <h2 style={{ fontFamily: "'Playfair Display',serif", fontSize: "clamp(36px,5vw,64px)", fontWeight: 900, lineHeight: 1.1, letterSpacing: "-0.02em", marginBottom: 20 }}>
                        O teu stand merece<br />
                        <em style={{ color: "var(--gold)" }}>inteligência real.</em>
                    </h2>
                    <p style={{ fontSize: 17, color: "var(--muted2)", maxWidth: 480, margin: "0 auto 40px", lineHeight: 1.7 }}>
                        14 dias de trial gratuito. Sem cartão de crédito.
                        Operacional em menos de um dia.
                    </p>
                    <div style={{ display: "flex", gap: 16, justifyContent: "center", flexWrap: "wrap" }}>
                        <a href="mailto:hello@xplendor.io" className="btn-primary" style={{ fontSize: 15, padding: "16px 36px" }}>
                            Pedir demonstração →
                        </a>
                        <a href="tel:+351900000000" className="btn-outline" style={{ fontSize: 15, padding: "15px 36px" }}>
                            Falar com a equipa
                        </a>
                    </div>
                    <div style={{ marginTop: 40, display: "flex", justifyContent: "center", gap: 32, flexWrap: "wrap" }}>
                        {["Sem contrato de permanência", "Setup em menos de 1 dia", "Suporte em português"].map((t) => (
                            <div key={t} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, color: "var(--muted2)" }}>
                                <span style={{ color: "var(--green)" }}>✓</span> {t}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function Footer() {
    return (
        <footer>
            <div className="container">
                <div className="footer-grid">
                    <div>
                        <div className="nav-logo" style={{ marginBottom: 16, fontSize: 24 }}>Xplend<span>or</span></div>
                        <p style={{ fontSize: 14, color: "var(--muted2)", lineHeight: 1.7, maxWidth: 280, marginBottom: 24 }}>
                            Plataforma de inteligência comercial para stands automóveis. Mais leads, melhores decisões, maior margem.
                        </p>
                        <div style={{ fontFamily: "'DM Mono',monospace", fontSize: 11, color: "var(--muted)" }}>
                            Feito em Portugal 🇵🇹
                        </div>
                    </div>
                    {[
                        { title: "Produto", links: ["Lead Intelligence", "Demand Radar", "Smart Budget", "Performance", "Market Insights"] },
                        { title: "Empresa", links: ["Sobre nós", "Blog", "Carreiras", "Contacto"] },
                        { title: "Legal", links: ["Termos de Uso", "Privacidade", "Cookies"] },
                    ].map((col) => (
                        <div key={col.title}>
                            <div className="footer-title">{col.title}</div>
                            <ul className="footer-links">
                                {col.links.map((l) => <li key={l}><a href="#">{l}</a></li>)}
                            </ul>
                        </div>
                    ))}
                </div>
                <div className="footer-bottom">
                    <span>© 2025 Xplendor. Todos os direitos reservados.</span>
                    <span>hello@xplendor.io</span>
                </div>
            </div>
        </footer>
    );
}

// ─── HERO ──────────────────────────────────────────────────────────────────
function Hero() {
    return (
        <section className="hero noise">
            <div className="grid-bg" />
            <div style={{ position: "absolute", top: "20%", right: "8%", width: 500, height: 500, background: "radial-gradient(circle, rgba(200,168,75,0.06) 0%, transparent 65%)", pointerEvents: "none" }} />
            <div className="container">
                <div style={{ display: "grid", gridTemplateColumns: "1.1fr 0.9fr", gap: 80, alignItems: "center" }}>
                    <div style={{ animation: "fadeUp 0.8s ease both" }}>
                        <div className="hero-eyebrow">Plataforma de Inteligência Automóvel</div>
                        <h1 className="hero-title">
                            Vende mais.<br />
                            Decide com<br />
                            <em>dados reais.</em>
                        </h1>
                        <p className="hero-sub">
                            O Xplendor transforma o teu stand num negócio orientado
                            por dados — leads com origem identificada, viaturas com
                            score de rentabilidade e investimento em publicidade
                            optimizado por inteligência artificial.
                        </p>
                        <div className="hero-cta-row">
                            <a href="#contacto" className="btn-primary" style={{ fontSize: 15, padding: "16px 32px" }}>
                                Pedir demonstração →
                            </a>
                            <a href="#produto" className="btn-outline" style={{ fontSize: 15, padding: "15px 32px" }}>
                                Ver como funciona
                            </a>
                        </div>
                        <div className="hero-trust">
                            <div className="hero-trust-item">
                                <div className="dot" />
                                <span>14 dias grátis</span>
                            </div>
                            <div className="hero-trust-item">
                                <div className="dot" />
                                <span>Sem cartão de crédito</span>
                            </div>
                            <div className="hero-trust-item">
                                <div className="dot" />
                                <span>Setup em 1 dia</span>
                            </div>
                        </div>
                    </div>
                    <div style={{ animation: "fadeUp 0.8s 0.2s ease both" }}>
                        <DashboardMockup />
                    </div>
                </div>
            </div>
        </section>
    );
}

// ─── MAIN APP ───────────────────────────────────────────────────────────────
export default function XplendorLanding() {
    return (
        <>
            <style>{css}</style>
            <Navbar />
            <TickerBar />
            <Hero />
            <div className="divider" />
            <ProblemSection />
            <div className="divider" />
            <LeadIntelligence />
            <div className="divider" />
            <DemandRadar />
            <div className="divider" />
            <PerformanceSection />
            <div className="divider" />
            <SmartBudget />
            <div className="divider" />
            <WorkProcess />
            <Counters />
            <div className="divider" />
            <Plans />
            <div className="divider" />
            <FAQSection />
            <CTASection />
            <Footer />
        </>
    );
}