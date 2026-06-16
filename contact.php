<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$_cfg = require __DIR__ . '/mailer.config.php';

// === Helper: 建立已設定好的 PHPMailer 實例 ===
function makeMailer(array $cfg): PHPMailer
{
  $m = new PHPMailer(true);
  $m->isSMTP();
  $m->Host = $cfg['smtp_host'];
  $m->SMTPAuth = true;
  $m->Username = $cfg['smtp_user'];
  $m->Password = $cfg['smtp_pass'];
  $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $m->Port = $cfg['smtp_port'];
  $m->CharSet = 'UTF-8';
  $m->setFrom($cfg['mail_from'], $cfg['site_name']);
  $m->SMTPDebug = 0;
  $m->Debugoutput = function ($str, $level) {
    error_log("SMTP: $str"); };
  $m->Timeout = 10;
  $m->SMTPKeepAlive = true;
  return $m;
}

// === Captcha init JSON endpoint (for contact2.html) ===
if (isset($_GET['captcha_json']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  if (empty($_SESSION['captcha_answer'])) {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha_answer'] = $a + $b;
    $_SESSION['captcha_question'] = "{$a} + {$b}";
  }
  $parts = explode(' + ', $_SESSION['captcha_question'] ?? '1 + 1');
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['capA' => (int) ($parts[0] ?? 1), 'capB' => (int) ($parts[1] ?? 1)]);
  exit;
}


// === CSRF Token Refresh endpoint ===
if (isset($_GET['csrf_refresh']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['csrf_token' => $_SESSION['csrf_token']]);
  exit;
}
// ===== 設定區 =====
$to = $_cfg['mail_to'];
$from = $_cfg['mail_from'];
$subject_prefix = $_cfg['subject_prefix'];
$site_name = $_cfg['site_name'];

// ===== CSRF Token =====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== 產生驗證碼（Session 儲存）=====
if (empty($_SESSION['captcha_answer'])) {
  $a = random_int(1, 9);
  $b = random_int(1, 9);
  $_SESSION['captcha_answer'] = $a + $b;
  $_SESSION['captcha_question'] = "{$a} + {$b}";
}

// ===== 處理表單送出 =====
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim(strip_tags($_POST['name'] ?? ''));
  $email = trim(strip_tags($_POST['email'] ?? ''));
  $phone = trim(strip_tags($_POST['phone'] ?? ''));
  $company = trim(strip_tags($_POST['company'] ?? ''));
  $message = trim(strip_tags($_POST['message'] ?? ''));
  $budget = trim(strip_tags($_POST['budget'] ?? ''));
  $price = trim(strip_tags($_POST['price'] ?? ''));
  $captcha = trim($_POST['captcha'] ?? '');

  $captcha_valid = is_numeric($captcha) &&
    (int) $captcha === (int) ($_SESSION['captcha_answer'] ?? -1);

  // 立即刷新驗證碼（防重放）
  $a = random_int(1, 9);
  $b = random_int(1, 9);
  $_SESSION['captcha_answer'] = $a + $b;
  $_SESSION['captcha_question'] = "{$a} + {$b}";

  // CSRF 驗證
  $csrf_ok = isset($_POST['csrf_token']) &&
    hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);

  // Honeypot（人類不填此欄，機器人會填）
  $honeypot_ok = trim($_POST['_hp'] ?? '') === '';

  // Rate Limit：每 Session 60 秒內最多 5 次
  $now = time();
  $attempts = array_filter(
    $_SESSION['form_attempts'] ?? [],
    function ($t) use ($now) {
      return $now - $t < 60; }
  );
  $rate_ok = count($attempts) < 5;
  if ($rate_ok) {
    $attempts[] = $now;
  }
  $_SESSION['form_attempts'] = array_values($attempts);

  if (!$csrf_ok || !$honeypot_ok) {
    $error = '請求驗證失敗，請重新整理頁面後再試。';
  } elseif (!$rate_ok) {
    $error = '送出次數過多，請稍候再試。';
  } elseif (empty($name) || empty($email) || empty($phone)) {
    $error = '請填寫姓名、Email 及電話。';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Email 格式不正確。';
  } elseif (!$captcha_valid) {
    $error = '驗證碼錯誤，請重新計算後填入。';
  } else {
    $mail_subject = $subject_prefix . '來自網站的詢問';

    $admin_body = "姓名：{$name}\n";
    $admin_body .= "Email：{$email}\n";
    $admin_body .= "電話：" . ($phone ?: '(未填)') . "\n";
    $admin_body .= "公司：" . ($company ?: '(未填)') . "\n";
    $admin_body .= "預算/服務：" . ($budget ?: '(未選擇)') . ($price ? " ／ 預算：{$price}" : '') . "\n";
    $admin_body .= "─────────────────────\n";
    $admin_body .= $message . "\n";

    try {
      $mailer = makeMailer($_cfg);
      $mailer->addAddress($to);
      $mailer->addReplyTo($email, $name);
      $mailer->Subject = $mail_subject;
      $mailer->Body = $admin_body;
      $mailer->send();
      $sent = true;
    } catch (Exception $e) {
      $sent = false;
    }

    if ($sent) {
      $reply_subject = "[{$site_name}] 感謝您的訊息，我們已收到您的詢問";
      $reply_body = "親愛的 {$name} 您好，\n\n";
      $reply_body .= "感謝您聯繫 {$site_name}，\n";
      $reply_body .= "我們已收到您的訊息，將盡快回覆您，請耐心等候。\n\n";
      $reply_body .= "── 您的訊息摘要 ──────────────────\n";
      $reply_body .= $message . "\n";
      $reply_body .= "──────────────────────────────────\n\n";
      $reply_body .= "此信為系統自動發送，請勿直接回覆。\n";
      $reply_body .= "{$site_name} 敬上\n";

      try {
        $mailer->clearAddresses();
        $mailer->clearReplyTos();
        $mailer->addAddress($email, $name);
        $mailer->Subject = $reply_subject;
        $mailer->Body = $reply_body;
        $mailer->send();
      } catch (Exception $e) { /* silently ignore */
      }
      $success = true;
    } else {
      $error = '郵件發送失敗，請稍後再試或直接聯絡我們。';
    }
  }
}

// 準備驗證碼顯示數字（POST 後已刷新 Session，此處取最新值）
$parts = explode(' + ', $_SESSION['captcha_question'] ?? '1 + 1');
$capA = (int) ($parts[0] ?? 1);
$capB = (int) ($parts[1] ?? 1);

// ===== AJAX JSON 回應 =====
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($is_ajax) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($success
    ? ['ok' => true, 'capA' => $capA, 'capB' => $capB]
    : ['ok' => false, 'error' => $error]);
  exit;
}

// 已選預算
$selBudget = $_POST['budget'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-TW" class="lenis">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>聯絡我們 Contact｜核點 Nudot Studio・台中網頁設計與商業視覺合作洽詢</title>

  <!-- SEO -->
  <meta name="description" content="與 核點 Nudot Studio聯繫 — 台中網頁設計、高階商業視覺與 AI 動態影像團隊。歡迎洽詢品牌官網、互動體驗設計與 Gen-AI 視覺合作專案。">
  <meta name="keywords"
    content="NUDOT 聯絡, 核點創意聯繫, 台中網頁設計洽詢, 網站設計合作, 高階商業視覺, AI 動態影像, 品牌識別設計, Gen-AI Visual, 接案洽詢, 商業攝影視覺">
  <meta name="robots" content="index, follow">
  <meta name="author" content="核點 Nudot Studio">
  <meta name="theme-color" content="#030303">
  <meta name="format-detection" content="telephone=no">
  <link rel="canonical" href="https://nudot.com.tw/contact">

  <meta property="og:type" content="website">
  <meta property="og:url" content="https://nudot.com.tw/contact">
  <meta property="og:title" content="聯絡我們 Contact｜核點 Nudot Studio・台中網頁設計與商業視覺合作洽詢">
  <meta property="og:description"
    content="與 核點 Nudot Studio聯繫 — 台中網頁設計、高階商業視覺與 AI 動態影像團隊。歡迎洽詢品牌官網、互動體驗設計與 Gen-AI 視覺合作專案。">
  <meta property="og:image" content="https://nudot.com.tw/images/og.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:locale" content="zh_TW">
  <meta property="og:site_name" content="核點 Nudot Studio">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="聯絡我們 Contact｜核點 Nudot Studio・台中網頁設計與商業視覺合作洽詢">
  <meta name="twitter:description" content="與 核點 Nudot Studio聯繫 — 台中網頁設計、高階商業視覺與 AI 動態影像團隊，洽詢品牌官網與 Gen-AI 視覺合作。">
  <meta name="twitter:image" content="https://nudot.com.tw/images/og.jpg">

  <script>
    (function () {
      try {
        var raw = sessionStorage.getItem('nudot:page-transition');
        if (!raw) return;
        var payload = JSON.parse(raw);
        if (payload && payload.at && Date.now() - payload.at < 12000) {
          document.documentElement.classList.add('has-pending-page-transition');
        }
      } catch (e) { }
    })();
  </script>
  <!-- Google Analytics 4 (GA4) -->
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-N53QVZL8TL"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', 'G-N53QVZL8TL');
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://use.typekit.net/upd0woi.css">
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Bitcount+Grid+Single:wght@100..900&family=Bitcount+Prop+Single:wght@100..900&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Doto:wght@100..900&family=Zalando+Sans+SemiExpanded:ital,wght@0,200..900;1,200..900&display=swap">
  <link rel="stylesheet" href="cursor-shared.css?v=1">
  <link rel="stylesheet" href="nav-menu-shared.css?v=1">
  <link rel="stylesheet" href="page-transitions.css?v=1">
  <link rel="stylesheet" href="project-creative-process.css?v=1">
  <link rel="icon" href="images/fav.png" type="image/png">

  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.2/dist/CustomEase.min.js"></script>
  <script src="https://cdn.jsdelivr.net/gh/studio-freight/lenis@1.0.29/bundled/lenis.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@barba/core@2.10.3/dist/barba.umd.min.js" defer></script>
  <script src="./transitions.js?v=1" defer></script>
  <script src="project-creative-process.js?v=1" defer></script>
  <script src="cursor-shared.js?v=1" defer></script>

  <style>
    :root {
      --font-family-body: "DM Sans", "Helvetica Neue", Arial, sans-serif;
      --font-family-display: "Zalando Sans SemiExpanded", "DM Sans", "Helvetica Neue", Arial, sans-serif;
      --text-2xs: clamp(0.6875rem, 0.66rem + 0.14vw, 0.75rem);
      --text-xs: clamp(0.75rem, 0.72rem + 0.22vw, 0.85rem);
      --text-sm: clamp(0.8125rem, 0.77rem + 0.28vw, 0.95rem);
      --leading-body: 1.7;
      --tracking-meta: 0.12em;
      --tracking-caps: 0.18em;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html.lenis {
      height: auto;
      scroll-behavior: auto !important;
    }

    html,
    body {
      background: #000;
      color: #fff;
      font-family: var(--font-family-body);
    }

    body {
      overflow: clip;
    }

    /* ── Contact Banner ── */
    .contact-banner {
      position: relative;
      overflow: visible;

    }

    .cb-headline-wrap {
      position: relative;
      max-width: 1400px;
      margin: 0 auto;
    }

    .cb-headline {
      display: flex;
      flex-direction: column;
      gap: 0;
      pointer-events: none;
      user-select: none;
    }

    .cb-line {
      display: block;
      font-family: var(--font-family-display);
      font-size: clamp(3rem, 10vw, 10rem);
      font-weight: 700;
      line-height: 0.92;
      letter-spacing: -0.03em;
      text-transform: uppercase;
      color: #fff;
    }

    .cb-float {
      position: absolute;
      overflow: hidden;
      pointer-events: none;
      z-index: 2;
    }

    .cb-float img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .cb-float--1 {
      width: clamp(75px, 9vw, 155px);
      height: clamp(52px, 6vw, 110px);
      top: 4%;
      left: 49%;
    }

    .cb-float--2 {
      width: clamp(60px, 7vw, 125px);
      height: clamp(80px, 10vw, 175px);
      top: 38%;
      left: 29%;
    }

    .cb-float--3 {
      width: clamp(75px, 8.5vw, 148px);
      height: clamp(75px, 8.5vw, 148px);
      top: 22%;
      right: 5%;
    }

    @media (max-width: 768px) {



      .cb-float {
        display: none;
      }

      .cb-line {
        font-size: clamp(2.6rem, 10vw, 4.5rem);
      }
    }

    /* ── Page layout ── */
    .page {
      padding: 0 var(--work-side-pad) 0 var(--work-side-pad);
      /*max-width: 1400px;*/
      margin: 0 auto;
    }

    .top-desc {
      display: flex;
      justify-content: space-between;
      font-size: var(--text-xs);
      letter-spacing: var(--tracking-caps);
      text-transform: uppercase;
      color: #959595;
      margin-bottom: 24px;
    }

    .line {
      border-top: 1px solid #222;
      margin: 40px 0;
    }

    /* ── Info Grid ── */
    .info-grid {
      display: grid;
      /*grid-template-columns: 240px 1fr 1fr;*/
      gap: 40px;
      margin-bottom: 60px;
    }

    .info-label {
      font-size: 11px;
      letter-spacing: 0.2em;
      text-transform: uppercase;
      color: #959595;
      padding-top: 4px;
    }

    .info-title {
      font-size: 11px;
      letter-spacing: 0.2em;
      text-transform: uppercase;
      color: #959595;
      margin-bottom: 16px;
      font-weight: 400;
    }

    .info-text {
      font-size: 14px;
      line-height: 1.7;
      color: #959595;
      margin-bottom: 24px;
    }

    .huge-link {
      display: block;
      font-size: clamp(18px, 3vw, 36px);
      color: #fff;
      text-decoration: none;
      font-weight: 300;
      letter-spacing: -0.01em;
      margin-bottom: 8px;
      border-bottom: 1px solid #222;
      padding-bottom: 16px;
      transition: color 0.2s;
    }

    .huge-link:hover {
      color: #959595;
    }

    /* ── Form Area ── */
    .form-area {
      display: block;
      margin-bottom: 80px;
    }

    .form-msg {
      font-size: 13px;
      line-height: 1.7;
      color: #666;
      margin-bottom: 40px;
      padding-bottom: 24px;
      border-bottom: 1px solid #222;
    }

    .form-fields {
      display: flex;
      flex-direction: column;
      gap: 0;
    }

    .field-group {
      display: grid;
      grid-template-columns: 220px 1fr;
      align-items: start;
      gap: 0 28px;
      padding: 22px 0;
      border-bottom: 1px solid #222;
    }

    .field-group:focus-within {
      border-bottom-color: #555;
    }

    .field-group label {
      font-size: 14px;
      letter-spacing: 0.01em;
      text-transform: none;
      color: #d5d5d5;
      font-weight: 400;
      padding-top: 4px;
      align-self: start;
    }

    .field-group label .req {
      color: #fff;
      margin-left: 1px;
    }

    .field-group input:-webkit-autofill,
    .field-group input:-webkit-autofill:hover,
    .field-group input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px #0d0d0d00 inset !important;
      -webkit-text-fill-color: #fff !important;
      caret-color: #fff;
      transition: background-color 9999s ease-in-out 0s;
    }

    .field-group input,
    .field-group textarea {
      background: transparent;
      border: none;
      color: #fff;
      font-size: clamp(1.3rem, 2.8vw, 2rem);
      font-weight: 300;
      font-family: var(--font-family-body);
      padding: 0 0 4px;
      outline: none;
      width: 100%;
      letter-spacing: -0.01em;
    }

    .field-group input::placeholder,
    .field-group textarea::placeholder {
      color: #363636;
      font-weight: 300;
      font-style: italic;
    }

    .field-group textarea {
      resize: none;
      min-height: 70px;
    }

    .field-group input.invalid,
    .field-group textarea.invalid {
      color: #fff;
    }

    .field-group input.valid,
    .field-group textarea.valid {
      color: #fff;
    }

    .field-error {
      font-size: 11px;
      color: #fff;
      display: none;
      letter-spacing: 0.05em;
      grid-column: 2;
      padding-top: 4px;
    }

    .field-error.visible {
      display: block;
    }

    /* server error banner */
    .server-error {
      font-size: 12px;
      letter-spacing: 0.1em;
      color: #e55;
      border: 1px solid #e55;
      padding: 12px 16px;
      margin-bottom: 24px;
    }

    /* budget rows */
    .budget-section {
      display: grid;
      grid-template-columns: 220px 1fr;
      align-items: start;
      gap: 0 28px;
      padding: 22px 0;
      border-bottom: 1px solid #222;
    }

    .budget-section-label {
      font-size: 13px;
      letter-spacing: 0.01em;
      text-transform: none;
      color: #666;
      font-weight: 400;
      padding-top: 4px;
    }

    .budget-offers {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .budget-offer-btn {
      appearance: none;
      background: transparent;
      border: none;
      border-bottom: 2px solid transparent;
      border-radius: 0;
      padding: 9px 20px 11px;
      cursor: pointer;
      color: #999999;
      font-size: 14px;
      font-family: var(--font-family-body);
      letter-spacing: 0.03em;
      transition: color 0.2s, border-color 0.2s;
      white-space: nowrap;
      outline: none;
      line-height: 1.4;
    }

    .budget-offer-btn:hover {
      color: #aaa;
      border-bottom-color: rgba(255, 255, 255, 0.25);
    }

    .budget-offer-btn.is-active {
      color: #fff;
      border-bottom-color: #fff;
    }

    /* budget selected row */
    .budget-selected-row {
      display: grid;
      grid-template-columns: 220px 1fr;
      align-items: center;
      gap: 0 28px;
      padding: 0;
      border-bottom: 1px solid transparent;
      max-height: 0;
      overflow: hidden;
      opacity: 0;
      pointer-events: none;
      transition: max-height 0.35s cubic-bezier(0.16, 1, 0.3, 1),
        opacity 0.28s ease,
        padding 0.32s cubic-bezier(0.16, 1, 0.3, 1),
        border-color 0.3s ease;
    }

    .budget-selected-row.is-visible {
      max-height: 100px;
      opacity: 1;
      padding: 14px 0;
      pointer-events: auto;
      border-color: #1c1c1c;
    }

    .budget-selected-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .budget-selected-tag {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 14px;
      color: #000000;
      letter-spacing: 0.04em;
      padding: 5px 12px 5px 9px;
      /* border: 1px solid #333; */
      border-radius: 1px;
      background-color: #fff;
    }

    .budget-selected-tag::before {
      content: '✓';
      font-size: 9px;
      color: #aaa;
    }

    .budget-tag-remove {
      appearance: none;
      background: transparent;
      border: none;
      color: #555;
      font-size: 15px;
      line-height: 1;
      padding: 0 0 0 6px;
      cursor: pointer;
      font-family: inherit;
      transition: color 0.15s;
      vertical-align: middle;
    }

    .budget-tag-remove:hover {
      color: #fff;
    }

    .budget-selected-price {
      border-color: #444;
      color: #fff;
    }

    .budget-selected-price::before {
      content: '▸';
      color: #888;
    }

    /* budget price row */
    .budget-price-section {
      display: grid;
      grid-template-columns: 220px 1fr;
      align-items: start;
      gap: 0 28px;
      padding: 0;
      border-bottom: 1px solid transparent;
      max-height: 0;
      overflow: hidden;
      opacity: 0;
      pointer-events: none;
      transition: max-height 0.38s cubic-bezier(0.16, 1, 0.3, 1),
        opacity 0.28s ease,
        padding 0.35s cubic-bezier(0.16, 1, 0.3, 1),
        border-color 0.3s ease;
    }

    .budget-price-section.is-visible {
      max-height: 160px;
      opacity: 1;
      padding: 22px 0;
      pointer-events: auto;
      border-color: #222;
    }

    .budget-price-offers {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .budget-price-btn {
      appearance: none;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 3px;
      padding: 9px 22px;
      cursor: pointer;
      color: #777;
      font-size: 14px;
      font-family: var(--font-family-body);
      letter-spacing: 0.03em;
      transition: background 0.2s, border-color 0.2s, color 0.2s;
      white-space: nowrap;
      outline: none;
      line-height: 1.4;
    }

    .budget-price-btn:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.28);
      color: #bbb;
    }

    .budget-price-btn.is-active {
      background: #fff;
      border-color: #fff;
      color: #000;
    }

    /* captcha */
    .captcha-block {
      border-bottom: 1px solid #222;
    }

    .captcha-eq-row {
      display: grid;
      grid-template-columns: 220px 1fr;
      align-items: center;
      gap: 0 28px;
      padding: 22px 0;
    }

    .captcha-meta-label {
      font-size: 13px;
      letter-spacing: 0.01em;
      text-transform: none;
      color: #666;
      font-weight: 400;
    }

    .captcha-right {
      display: flex;
      align-items: center;
      gap: 24px;
    }

    .captcha-eq-display {
      display: flex;
      align-items: baseline;
      gap: 8px;
      line-height: 1;
    }

    .cap-digit,
    .cap-op,
    .cap-q {
      font-family: "Bitcount Prop Single", "Bitcount Grid Single", monospace;
      line-height: 1;
    }

    .cap-digit {
      font-size: clamp(1.8rem, 4vw, 3rem);
      font-weight: 400;
      color: #fff;
    }

    .cap-op {
      font-size: clamp(1.2rem, 2.8vw, 2rem);
      color: #666;
    }

    .cap-q {
      font-size: clamp(1.8rem, 4vw, 3rem);
      color: #666;
    }

    .captcha-input-wrap {
      display: flex;
      align-items: center;
    }

    .captcha-input-wrap input {
      background: transparent;
      border: none;
      border-bottom: 1px solid #333;
      color: #fff;
      font-size: clamp(1.8rem, 4vw, 3rem);
      font-weight: 300;
      font-family: "Bitcount Prop Single", "Bitcount Grid Single", monospace;
      padding: 4px 0;
      outline: none;
      width: 70px;
      text-align: center;
      transition: border-color 0.2s;
      -moz-appearance: textfield;
    }

    .captcha-input-wrap input::-webkit-outer-spin-button,
    .captcha-input-wrap input::-webkit-inner-spin-button {
      -webkit-appearance: none;
    }

    .captcha-input-wrap input:focus {
      border-bottom-color: #fff;
    }

    .captcha-block .field-error {
      grid-column: unset;
      padding: 0 0 12px 248px;
    }

    /* submit */
    .submit-wrap {
      margin-top: 32px;
    }

    .btn-submit {
      background: transparent;
      border: 1px solid #959595;
      color: #fff;
      font-size: 12px;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      padding: 15px 100px;
      cursor: pointer;
      font-family: var(--font-family-body);
      transition: background 0.2s, border-color 0.2s;
    }

    .btn-submit:hover {
      background: #fff;
      color: #000;
      border-color: #fff;
    }

    .btn-submit:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    /* ── Footer ── */
    .site-footer {
      position: relative;
      overflow: visible;
      background: #000;
      color: #fff;
      padding: 0;
      font-family: var(--font-family-body);
    }

    .footer-parallax-section {
      height: 50vh;
      width: 100%;
      overflow: hidden;
      position: relative;
      z-index: 1;
    }

    .footer-parallax-copy {
      position: absolute;
      top: 30px;
      left: 40px;
      z-index: 3;
      color: rgba(255, 255, 255, 0.65);
      font-family: 'DM Sans', sans-serif;
      font-size: 12px;
      letter-spacing: 0.06em;
      line-height: 1.7;
      pointer-events: none;
    }

    @media (max-width: 1440px) {
      .footer-parallax-section {
        height: 300px;

      }
    }


    .footer-parallax-bg {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-image: var(--footer-bg-image, none);
      background-attachment: fixed;
      background-size: contain;
      background-position: bottom;
      opacity: 1;
      mix-blend-mode: luminosity;
    }

    /* ── Footer Top ── */
    .footer-top {
      padding: 3vw 2.5vw;
      position: relative;
      z-index: 5;
      background-color: #000;
    }

    .contact-hero.work-hero {
      position: relative;
      top: auto;
      z-index: 5;
      background: #000;
    }

    /* Mobile-only title — hidden on desktop */
    .contact-hero .mobile_work-hero__title {
      display: none;
      font-size: 65px;
    }

    .footer-brand-title {
      font-family: 'Zalando Sans SemiExpanded', 'DM Sans', sans-serif;
      font-size: clamp(64px, 9.5vw, 130px);
      font-weight: 600;
      text-transform: uppercase;
      line-height: 0.88;
      letter-spacing: -0.02em;
      margin-bottom: 40px;
      color: #fff;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
    }

    .footer-brand-title sup {
      font-size: 0.13em;
      vertical-align: top;
      letter-spacing: 0;
    }

    .footer-info-bar {
      display: flex;
      justify-content: space-between;
      border-top: 1px solid #222;
      padding-top: 18px;
      margin-bottom: 40px;
      font-size: 14px;
      text-transform: uppercase;
      color: #fff;
      letter-spacing: 0.06em;
    }

    .footer-main-content {
      display: grid;
      grid-template-columns: 1.4fr 1fr;
      /*gap: 40px;*/
    }

    .footer-description {
      font-size: 14px;
      line-height: 1.65;
      color: #666;
      max-width: 450px;
      margin-bottom: 32px;
    }

    .footer-contact-info a {
      display: block;
      color: #fff;
      text-decoration: none;
      font-size: clamp(20px, 3.5vw, 50px);
      font-weight: 400;
      margin-bottom: 4px;
      transition: opacity 0.2s;
    }

    .footer-contact-info a:hover {
      opacity: 0.6;
    }

    .footer-contact-info span {
      color: #ccc;
      font-size: clamp(18px, 2.5vw, 30px);
    }

    .footer-nav-links {
      display: flex;
      gap: 28px;
      margin-top: 38px;
    }

    .footer-nav-links a {
      color: #fff;
      text-decoration: none;
      font-size: var(--text-xs);
      text-transform: uppercase;
      letter-spacing: 1.5px;
      transition: opacity 0.2s;
    }

    .footer-nav-links a:hover {
      opacity: 0.5;
    }

    .footer-right-panel {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      justify-content: flex-start;
    }

    .footer-video-thumb {
      width: 100%;
      max-width: 340px;
      aspect-ratio: 16 / 9;
      overflow: hidden;
      position: relative;
    }

    @media (max-width: 768px) {
      .footer-video-thumb {
        max-width: 100%;
      }
    }

    .footer-video-thumb img,
    .footer-video-thumb video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .footer-copyright-bridge {
      position: absolute;
      bottom: 0;
      right: 6%;
      width: clamp(90px, 10vw, 148px);
      height: clamp(90px, 10vw, 148px);
      display: flex;
      align-items: center;
      justify-content: center;
      transform: translateY(50%);
      z-index: 20;
    }

    .footer-copyright-bridge span {
      font-family: 'Zalando Sans SemiExpanded', 'DM Sans', sans-serif;
      font-size: 180px;
      font-weight: 600;
      color: #fff;
      line-height: 1;
    }


    @media (max-width: 768px) {
      .footer-top {
        padding: 1.5rem;
        line-height: normal;
      }

      .footer-nav-links {
        gap: 16px;
        margin-top: 24px;
        flex-wrap: wrap;

      }
    }

    /* ── Sending Overlay ── */
    #sending-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.75);
      display: none;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 28px;
      z-index: 999999;
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    #sending-overlay.active {
      display: flex;
      opacity: 1;
    }

    #sending-overlay.fade-in {
      opacity: 1;
    }

    #sending-hourglass {
      width: 52px;
      height: auto;
      animation: hg-spin 1.4s linear infinite;
      transform-origin: center center;
    }

    @keyframes hg-spin {
      0%   { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    #sending-label {
      font-size: 11px;
      letter-spacing: 0.22em;
      text-transform: uppercase;
      color: #aaa;
      font-family: var(--font-family-body);
    }

    /* ── Lightbox ── */
    #lb-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.2);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 99999;
      backdrop-filter: blur(10px);
    }

    #lb-overlay.active {
      display: flex;
    }

    #lb-box {
      padding: 60px 40px;
      text-align: center;
      max-width: 420px;

    }



    #lb-mark {
      font-size: 48px;
      color: #fff;
      /*margin-bottom: 20px;*/
      line-height: 1;
      mix-blend-mode: exclusion;
    }

    #lb-mark img {
      width: 50px;
    }

    #lb-title {
      font-size: 18px;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      color: #fff;
      margin-bottom: 16px;
      font-family: 'Bitcount Grid Single';
      text-transform: uppercase;
    }



    #lb-title div {
      font-size: 60px;

    }

    #lb-sub {
      font-size: 14px;
      line-height: 1.7;
      color: #959595;
      margin-bottom: 32px;
    }

    #lb-close {
      background: transparent;
      border: 1px solid #959595;
      color: #fff;
      font-size: 11px;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      padding: 10px 28px;
      cursor: pointer;
      font-family: var(--font-family-body);
      transition: background 0.2s;
    }

    #lb-close:hover {
      background: #fff;
      color: #000;
    }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
      .footer-copyright-bridge {
        display: none !important;
      }
    }

    @media (max-width: 768px) {

      /* Hero: show CONTACT div, hide LET'S / center / TALK */
      .contact-hero .mobile_work-hero__title {
        display: block;
      }

      .contact-hero h1.work-hero__title {
        display: none;
      }

      .contact-hero .work-hero__center {
        display: block;
      }

      .contact-hero .work-hero__code {
        display: none;
      }

      .info-grid {
        grid-template-columns: 1fr;
      }

      .field-group {
        grid-template-columns: 1fr;
        gap: 6px 0;
      }

      .field-group label {
        padding-top: 0;
      }

      .field-group .field-error {
        grid-column: 1;
      }

      .budget-section {
        grid-template-columns: 1fr;
        gap: 12px 0;
      }

      .budget-price-section {
        grid-template-columns: 1fr;
        gap: 10px 0;
      }

      .budget-price-section.is-visible {
        max-height: 260px;
      }

      .budget-selected-row {
        grid-template-columns: 1fr;
        gap: 10px 0;
      }

      .budget-selected-row.is-visible {
        max-height: 200px;
      }

      .captcha-eq-row {
        grid-template-columns: 1fr;
        gap: 12px 0;
      }

      .captcha-block .field-error {
        padding-left: 0;
      }

      .submit-wrap {
        padding-left: 0;
      }

      .footer-parallax-section {
        display: none !important;
      }

      .footer-main-content {
        grid-template-columns: 1fr;
      }

      .footer-right-panel {
        align-items: flex-start;
        margin-top: 40px;
      }

      .footer-info-bar {
        flex-direction: column;
        gap: 8px;
      }
    }

    @media (max-width: 1024px) {

      .footer-copyright-bridge,
      .footer-parallax-section {
        display: none !important;
      }

      .work-hero__title {
        display: none;
      }

      .contact-hero .mobile_work-hero__title {
        display: block;
      }
    }

    /* ── Contact Entry Animation ── */
    .contact-content {
      will-change: transform, opacity;
      opacity: 0;
      transform: translateY(40px) translateZ(0);
      transition:
        opacity 0.75s cubic-bezier(0.16, 1, 0.3, 1) 0.08s,
        transform 0.95s cubic-bezier(0.16, 1, 0.3, 1) 0.08s;
    }

    .top-desc {
      opacity: 0;
      transition: opacity 0.6s ease 0.38s;
    }

    .line {
      transform-origin: center center;
      transform: scaleX(0);
      will-change: transform;
      transition: transform 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.55s;
    }

    .info-grid>*:nth-child(1) {
      opacity: 0;
      transform: translateY(20px) translateZ(0);
      transition: opacity 0.6s ease 0.68s, transform 0.85s cubic-bezier(0.16, 1, 0.3, 1) 0.68s;
    }

    .info-grid>*:nth-child(2) {
      opacity: 0;
      transform: translateY(20px) translateZ(0);
      transition: opacity 0.6s ease 0.78s, transform 0.85s cubic-bezier(0.16, 1, 0.3, 1) 0.78s;
    }

    .info-grid>*:nth-child(3) {
      opacity: 0;
      transform: translateY(20px) translateZ(0);
      transition: opacity 0.6s ease 0.88s, transform 0.85s cubic-bezier(0.16, 1, 0.3, 1) 0.88s;
    }

    .form-area {
      opacity: 0;
      transition: opacity 0.6s ease 0.95s;
    }

    /*.contact-entered{min-height:2300px;}*/
    .contact-entered .contact-content {
      opacity: 1;
      transform: translateY(0) translateZ(0);
    }

    .contact-entered .top-desc {
      opacity: 1;
    }

    .contact-entered .line {
      transform: scaleX(1);
    }

    .contact-entered .info-grid>* {
      opacity: 1;
      transform: translateY(0) translateZ(0);
    }

    .contact-entered .form-area {
      opacity: 1;
    }

    /* ── Contact Marquee (matches lab.html style) ── */
    .contact-marquee {

      width: 100%;
      overflow: hidden;
      background: #000;
      border-top: 1px solid #313131;
      border-bottom: 1px solid #313131;
      clip-path: inset(100% 0 0 0);
      padding: 10px;
    }

    .contact-marquee__track {
      display: flex;
      align-items: center;
      gap: 0;
      white-space: nowrap;
      will-change: transform;
    }

    .contact-marquee__item {
      font-family: 'Bitcount Prop Single', 'Bitcount Grid Single', monospace;
      font-size: clamp(1.4rem, 4vw, 4.5rem);
      font-weight: 400;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #fff;
      padding: 0 clamp(20px, 3vw, 60px);
    }

    .contact-marquee__sep {
      display: inline-flex;
      align-items: center;
      flex-shrink: 0;
      width: clamp(20px, 2.8vw, 40px);
    }

    .contact-marquee__sep img {
      width: 100%;
      height: auto;
      display: block;
      animation: contact-sep-spin 3s ease-in-out infinite;
      /*filter: invert(1);*/
    }

    @keyframes contact-sep-spin {
      0% {
        transform: rotate(0deg);
      }

      40% {
        transform: rotate(180deg);
      }

      60% {
        transform: rotate(180deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .contact-marquee__track {
        transform: none !important;
      }
    }
  </style>
</head>

<body class="shared-nav-page page-work-pattern">

  <div class="page-transition-shell js-page-transition-shell" aria-hidden="true">
    <div class="page-transition-curtain js-page-transition-curtain"></div>
    <div class="page-transition-frame js-page-transition-frame">
      <div class="page-transition-loader js-page-transition-loader"></div>
      <div class="page-transition-counter js-page-transition-counter">[ <span
          class="js-page-transition-counter-num">00</span> ]</div>
      <div class="page-transition-panel js-page-transition-panel">
        <div class="page-transition-meta is-top-left">NUDOT</div>
        <div class="page-transition-meta is-top-right js-page-transition-meta-to">contact.php</div>
        <div class="page-transition-copy">
          <div class="page-transition-eyebrow js-page-transition-eyebrow">NUDOT STUDIO</div>
          <div class="page-transition-title js-page-transition-title">CONTACT</div>
          <div class="page-transition-subtitle js-page-transition-subtitle">ABOUT YOUR NEXT DIGITAL TRANSFORMATION</div>
        </div>
        <div class="page-transition-meta is-bottom-left">Digital / Motion / Interface</div>
        <div class="page-transition-meta is-bottom-right">2026</div>
      </div>
    </div>
  </div>

  <div data-barba="wrapper">
    <div data-barba="container" data-barba-namespace="contact">

      <div id="nav_scroll_container" aria-label="Primary navigation">
        <nav id="nav_scroll">
          <a class="ns-icon" href="contact" data-cursor="CONTACT" aria-label="Contact">
            <svg viewBox="0 0 24 24">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
              <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
          </a>
          <a class="ns-logo" href="index" data-cursor="HOME" aria-label="NUDOT home">
            <img src="images/pc_logo.svg" alt="NUDOT 核點創意" width="45" height="35" loading="lazy" decoding="async">
          </a>
          <button class="ns-icon ns-hamburger" id="nav-scroll-menu-btn" data-cursor="OPEN" aria-label="Open menu"
            aria-expanded="false" aria-controls="nav-scroll-dropdown">
            <svg class="ns-ham-svg" viewBox="0 0 24 24">
              <line class="ns-ham-l1" x1="5" y1="9" x2="19" y2="9"></line>
              <line class="ns-ham-l2" x1="5" y1="15" x2="19" y2="15"></line>
            </svg>
          </button>
        </nav>
        <div class="ns-dropdown" id="nav-scroll-dropdown" aria-hidden="true">
          <div class="ns-dropdown__inner" id="nav-scroll-dropdown-content">
            <div class="ns-menu-stage">
              <!-- <div class="ns-menu-topline ns-dropdown__item">
            <span class="ns-menu-topline__brand">NUDOT CREATIVE STUDIO</span>
            <a class="ns-menu-topline__contact" href="#site-footer">CONTACT</a>
          </div> -->

              <div class="ns-menu-rows">
                <a class="ns-showcase-row ns-dropdown__item" href="index" data-transition-label="Home">
                  <span class="ns-showcase-row__index">( 首頁 )</span>
                  <span class="ns-showcase-row__thumb is-left" aria-hidden="true">
                    <img src="images/nav/1.webp" data-nav-src="images/nav/1.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                  <span class="ns-showcase-row__title">
                    <span class="ns-showcase-row__title-track">
                      <span class="ns-showcase-row__title-layer is-primary">HOME</span>
                      <span class="ns-showcase-row__title-layer is-accent" aria-hidden="true">HOME</span>
                    </span>
                  </span>
                  <span class="ns-showcase-row__thumb is-right" aria-hidden="true">
                    <img src="images/nav/2.webp" data-nav-src="images/nav/2.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                </a>

                <a class="ns-showcase-row ns-dropdown__item" href="about" data-transition-label="About">
                  <span class="ns-showcase-row__index">( 核點創意 )</span>
                  <span class="ns-showcase-row__thumb is-left" aria-hidden="true">
                    <img src="images/nav/3.webp" data-nav-src="images/nav/3.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                  <span class="ns-showcase-row__title">
                    <span class="ns-showcase-row__title-track">
                      <span class="ns-showcase-row__title-layer is-primary">ABOUT</span>
                      <span class="ns-showcase-row__title-layer is-accent" aria-hidden="true">ABOUT</span>
                    </span>
                  </span>
                  <span class="ns-showcase-row__thumb is-right" aria-hidden="true">
                    <img src="images/nav/4.webp" data-nav-src="images/nav/4.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                </a>

                <a class="ns-showcase-row ns-dropdown__item" href="work" data-transition-label="Work">
                  <span class="ns-showcase-row__index">( 設計案例 )</span>
                  <span class="ns-showcase-row__thumb is-left" aria-hidden="true">
                    <img src="images/nav/5.webp" data-nav-src="images/nav/5.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                  <span class="ns-showcase-row__title">
                    <span class="ns-showcase-row__title-track">
                      <span class="ns-showcase-row__title-layer is-primary">WORK</span>
                      <span class="ns-showcase-row__title-layer is-accent" aria-hidden="true">WORK</span>
                    </span>
                  </span>
                  <span class="ns-showcase-row__thumb is-right" aria-hidden="true">
                    <img src="images/nav/6.webp" data-nav-src="images/nav/6.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                </a>

                <a class="ns-showcase-row ns-dropdown__item" href="lab" data-transition-label="Labs">
                  <span class="ns-showcase-row__index">( 核點實驗室 )</span>
                  <span class="ns-showcase-row__thumb is-left" aria-hidden="true">
                    <img src="images/nav/7.webp" data-nav-src="images/nav/7.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                  <span class="ns-showcase-row__title">
                    <span class="ns-showcase-row__title-track">
                      <span class="ns-showcase-row__title-layer is-primary">LABS</span>
                      <span class="ns-showcase-row__title-layer is-accent" aria-hidden="true">LABS</span>
                    </span>
                  </span>
                  <span class="ns-showcase-row__thumb is-right" aria-hidden="true">
                    <img src="images/nav/8.webp" data-nav-src="images/nav/8.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                </a>

                <a class="ns-showcase-row ns-dropdown__item" href="contact" data-transition-label="Contact">
                  <span class="ns-showcase-row__index">( 聯繫我們 )</span>
                  <span class="ns-showcase-row__thumb is-left" aria-hidden="true">
                    <img src="images/nav/9.webp" data-nav-src="images/nav/9.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                  <span class="ns-showcase-row__title">
                    <span class="ns-showcase-row__title-track">
                      <span class="ns-showcase-row__title-layer is-primary">CONTACT</span>
                      <span class="ns-showcase-row__title-layer is-accent" aria-hidden="true">CONTACT</span>
                    </span>
                  </span>
                  <span class="ns-showcase-row__thumb is-right" aria-hidden="true">
                    <img src="images/nav/10.webp" data-nav-src="images/nav/10.webp" alt="" width="300" height="200"
                      loading="lazy" decoding="async">
                  </span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Contact Banner -->
      <section class="contact-banner" aria-label="Contact banner">
        <header class="work-hero work-reveal contact-hero">
          <div class="work-hero__top">
            <h1 class="work-hero__title">LET'S</h1>
            <div class="work-hero__title mobile_work-hero__title">CONTACT</div>
            <div class="work-hero__center">
              <p class="work-hero__desc">ABOUT YOUR NEXT BIG<br>DIGITAL TRANSFORMATION</p>
            </div>
            <p class="work-hero__code">TALK</p>
          </div>
          <div class="work-hero__line"></div>
          <div class="work-hero__bottom">
            <p class="work-hero__year">START CONVERSATION</p>
            <p class="work-hero__kicker">( 聯繫我們 )</p>
            <p class="work-hero__tags">VISION | EXPERIENCE | EXECUTION</p>
          </div>
        </header>




      </section>

      <main class="page-container">
        <div class="page">




          <div class="form-area">
            <!--<div class="form-msg">We try our best to respond within 24–48 hours.<br>如未收到回覆，請確認垃圾郵件資料夾。</div>-->

            <form id="contactForm" method="POST" action="contact" novalidate>

              <?php if ($error): ?>
                <div class="server-error"><?= htmlspecialchars($error) ?></div>
              <?php endif; ?>

              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" id="f_budget" name="budget" value="<?= htmlspecialchars($selBudget) ?>">
              <input type="hidden" id="f_price" name="price" value="">
              <!-- honeypot: 人類不可見，機器人會自動填入 -->
              <input type="text" name="_hp" value="" aria-hidden="true" tabindex="-1" autocomplete="off"
                style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">

              <div class="form-fields">
                <div class="field-group">
                  <label for="f_name">Name <span class="req">*</span> ／ 姓名</label>
                  <input type="text" id="f_name" name="name" required placeholder="John Doe"
                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                  <div class="field-error" id="name-error">Please fill in your name. ／ 請填寫姓名</div>
                </div>
                <div class="field-group">
                  <label for="f_email">Email <span class="req">*</span> ／ 電子郵件</label>
                  <input type="email" id="f_email" name="email" required placeholder="john@example.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                  <div class="field-error" id="email-error">Please fill in a valid email. ／ 請填寫有效的電子郵件</div>
                </div>
                <div class="field-group">
                  <label for="f_phone">Phone <span class="req">*</span> ／ 電話</label>
                  <input type="tel" id="f_phone" name="phone" required placeholder="+886 ..."
                    value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                  <div class="field-error" id="phone-error">Please fill in your phone number. ／ 請填寫電話號碼</div>
                </div>
                <div class="field-group">
                  <label for="f_company">Company ／ 公司名稱 <span
                      style="font-size:10px;opacity:0.45;letter-spacing:0.05em;">(optional ／ 非必填)</span></label>
                  <input type="text" id="f_company" name="company" placeholder="Company name"
                    value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
                </div>
                <div class="field-group field-full">
                  <label for="f_msg">Project Details ／ 專案說明</label>
                  <textarea id="f_msg" name="message"
                    placeholder="Tell us about your project... ／ 請描述您的專案需求"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                  <div class="field-error" id="message-error">Please provide some details. ／ 請填寫專案說明</div>
                </div>
                <div class="budget-section">
                  <div class="budget-section-label">您需要的服務項目</div>
                  <div class="budget-offers" aria-label="Budget scale">
                    <button type="button" class="budget-offer-btn<?= $selBudget === '網站設計' ? ' is-active' : '' ?>"
                      data-budget="網站設計">網站設計</button>
                    <button type="button" class="budget-offer-btn<?= $selBudget === '品牌識別' ? ' is-active' : '' ?>"
                      data-budget="品牌識別">品牌識別</button>

                    <button type="button" class="budget-offer-btn<?= $selBudget === '高階商業視覺圖像生成' ? ' is-active' : '' ?>"
                      data-budget="高階商業視覺圖像生成">高階商業視覺圖像生成</button>
                    <button type="button" class="budget-offer-btn<?= $selBudget === 'AI動態影像' ? ' is-active' : '' ?>"
                      data-budget="AI動態影像">AI 動態影像</button>
                  </div>
                </div>
                <div class="budget-price-section" id="budgetPriceSection">
                  <div class="budget-section-label">預算範圍</div>
                  <div class="budget-price-offers" id="budgetPriceOffers"></div>
                </div>
                <div class="budget-selected-row" id="budgetSelectedRow">
                  <div class="budget-section-label">已選擇</div>
                  <div class="budget-selected-tags" id="budgetSelectedTags"></div>
                </div>
                <div class="captcha-block">
                  <div class="captcha-eq-row">
                    <div class="captcha-meta-label">Captcha ／ 驗證碼</div>
                    <div class="captcha-right">
                      <div class="captcha-eq-display">
                        <span class="cap-digit"><?= $capA ?></span>
                        <span class="cap-op">+</span>
                        <span class="cap-digit"><?= $capB ?></span>
                        <span class="cap-op">=</span>
                        <!--<span class="cap-q">?</span>-->
                      </div>
                      <div class="captcha-input-wrap">
                        <input type="number" id="f_captcha" name="captcha" required placeholder="?">
                      </div>
                    </div>
                  </div>
                  <div class="field-error" id="captcha-error">Incorrect answer. ／ 驗證碼錯誤，請重新輸入</div>
                </div>
                <div class="submit-wrap">
                  <button type="submit" class="btn-submit" id="btnSubmit">Submit ／ 送出</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </main>


      <!-- Scroll-driven marquee -->
      <div class="contact-marquee" aria-hidden="true">
        <div class="contact-marquee__track" id="contact-marquee-track">
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <!-- duplicate for seamless loop -->
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
          <span class="contact-marquee__item">LET'S TALK</span>
          <span class="contact-marquee__sep"><img src="images/contact.svg?v=2" alt=""></span>
        </div>
      </div>

      <footer class="site-footer" id="site-footer">
        <div class="footer-top">
          <div class="footer-main-content">
            <div>
              <div class="footer-contact-info">
                <a href="mailto:hello@nudot.com.tw">hello@nudot.com.tw</a>
                <span>+8869 83 750 522</span>
              </div>
              <div style="color:#444;font-size:14px;margin-top:8px;">臺中市北屯區文心路三段447號</div>
              <nav class="footer-nav-links">
                <a href="https://www.instagram.com/nudotlabs" target="_blank" rel="noopener noreferrer"
                  data-transition-label="Instagram">Instagram</a>
                <a href="https://www.threads.com/@nudotlabs" target="_blank" rel="noopener noreferrer"
                  data-transition-label="Threads">Threads</a>
                <a href="https://www.facebook.com/profile.php?id=61588727983387&locale=zh_TW" target="_blank"
                  rel="noopener noreferrer" data-transition-label="Facebook">Facebook</a>
              </nav>
            </div>
            <div class="footer-right-panel">
              <div class="footer-video-thumb">
                <video autoplay muted loop playsinline preload="none" disableremoteplayback>
                  <source src="images/footer.mp4" type="video/mp4">
                </video>
              </div>
            </div>
          </div>

        </div><!-- /footer-top -->

        <div class="footer-parallax-section" id="footer-parallax-section">
          <div class="footer-parallax-bg" id="footer-parallax-bg" data-bg="images/footer.webp"></div>
          <div class="footer-parallax-copy">
            <div>核點創意有限公司</div>
            <div>&#169; 2026 NUDOT STUDIO. ALL RIGHTS RESERVED.</div>
          </div>
        </div>

      </footer>

    </div><!-- /data-barba container -->
  </div><!-- /data-barba wrapper -->

  <!-- Sending overlay -->
  <div id="sending-overlay" role="status" aria-live="polite" aria-label="Sending your message">
    <img id="sending-hourglass" src="images/lab/hourglass.svg" alt="" aria-hidden="true">
    <div id="sending-label">Sending&ensp;／&ensp;傳送中</div>
  </div>

  <!-- Lightbox -->
  <div id="lb-overlay">
    <div id="lb-box">
      <div id="lb-mark">

        <img src="images/contact.svg?v=2">

      </div>
      <div id="lb-title">
        <div>RECEIVED</div> 已收到
      </div>
      <div id="lb-sub">Thank you. We will respond shortly.<br>感謝您的訊息，我們將盡快回覆。</div>
      <button id="lb-close">Close ／ 關閉</button>
    </div>
  </div>

  <!-- Noise canvas -->
  <canvas id="film-grain-canvas" class="work-noise-canvas" aria-hidden="true"></canvas>
  <script src="noise.js?v=4"></script>

  <!-- Custom cursor -->
  <div id="cursor-dot" aria-hidden="true"></div>
  <div id="cursor-ring" aria-hidden="true" data-cursor-label="EXPLORE" data-cursor-side="right"
    style="position: fixed;"></div>

  <!-- Lenis smooth scroll (contact page) -->
  <script>
    (function () {
      'use strict';
      if (window._lenis) return;
      if (!window.Lenis || !window.gsap || !window.ScrollTrigger) return;
      gsap.registerPlugin(ScrollTrigger);
      var lenis = new Lenis({
        duration: 1.2,
        easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
        smoothTouch: false,
      });
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add(function (time) { lenis.raf(time * 1000); });
      gsap.ticker.lagSmoothing(0);
      window._lenis = lenis;
    }());
  </script>

  <!-- Contact Entry Animation -->
  <script>
    (function () {
      'use strict';
      if (window.__nudotContactEnterInit) return;
      window.__nudotContactEnterInit = true;

      function triggerEntry() {
        var container = document.querySelector('[data-barba-namespace="contact"]');
        if (!container) return;
        window.requestAnimationFrame(function () {
          window.requestAnimationFrame(function () {
            container.classList.add('contact-entered');
          });
        });
      }

      function ready(fn) {
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else { fn(); }
      }

      if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }

      ready(function () {
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(triggerEntry).catch(triggerEntry);
        } else {
          window.setTimeout(triggerEntry, 80);
        }
      });

    }());
  </script>

  <!-- Form JS -->
  <script>
    (function () {
      'use strict';
      var form = document.getElementById('contactForm');
      if (!form) return;
      var nameEl = document.getElementById('f_name');
      var emailEl = document.getElementById('f_email');
      var phoneEl = document.getElementById('f_phone');
      var msgEl = document.getElementById('f_msg');
      var captchaEl = document.getElementById('f_captcha');
      var budgetEl = document.getElementById('f_budget');
      var priceEl = document.getElementById('f_price');

      function showError(field, id, show) {
        var el = document.getElementById(id);
        if (field) { field.classList.toggle('invalid', show); field.classList.toggle('valid', !show && field.value.trim() !== ''); }
        if (el) el.classList.toggle('visible', show);
      }

      function isEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

      if (nameEl) nameEl.addEventListener('blur', function () { showError(nameEl, 'name-error', nameEl.value.trim() === ''); });
      if (emailEl) emailEl.addEventListener('blur', function () { var v = emailEl.value.trim(); showError(emailEl, 'email-error', !v || !isEmail(v)); });
      if (phoneEl) phoneEl.addEventListener('blur', function () { showError(phoneEl, 'phone-error', phoneEl.value.trim() === ''); });
      if (captchaEl) captchaEl.addEventListener('blur', function () { showError(captchaEl, 'captcha-error', captchaEl.value.trim() === ''); });

      // Budget price options
      var priceMap = {
        '網站設計': ['NT$ 50,000 — 100,000', 'NT$ 100,000 — 150,000', 'NT$ 150,000 UP'],
        '品牌識別': ['NT$ 15,000 — 30,000', 'NT$ 30,000 — 60,000', 'NT$ 60,000 UP'],
        '高階商業視覺圖像生成': ['NT$ 8,000 / 10張', 'NT$ 20,000 / 30張', 'NT$ 30,000 / 50張'],
        'AI動態影像': ['NT$ 5,000 / 10秒', 'NT$ 20,000 / 30秒', 'NT$ 50,000 / 60秒']
      };
      var servicePriceMap = {}; // tracks selected price per service

      function updatePriceEl() {
        if (!priceEl) return;
        var parts = [];
        document.querySelectorAll('.budget-offer-btn.is-active').forEach(function (b) {
          var svc = b.dataset.budget;
          if (servicePriceMap[svc]) parts.push(svc + ': ' + servicePriceMap[svc]);
        });
        priceEl.value = parts.join('、');
      }

      function updateSelectedDisplay() {
        updatePriceEl();
        var row = document.getElementById('budgetSelectedRow');
        var tags = document.getElementById('budgetSelectedTags');
        if (!row || !tags) return;
        var active = [];
        document.querySelectorAll('.budget-offer-btn.is-active').forEach(function (b) {
          active.push(b.dataset.budget);
        });
        if (active.length === 0) { row.classList.remove('is-visible'); tags.innerHTML = ''; return; }
        tags.innerHTML = '';
        active.forEach(function (name) {
          var tag = document.createElement('span');
          tag.className = 'budget-selected-tag';
          var price = servicePriceMap[name];
          tag.appendChild(document.createTextNode(price ? (name + ' / ' + price) : name));
          var removeBtn = document.createElement('button');
          removeBtn.type = 'button';
          removeBtn.className = 'budget-tag-remove';
          removeBtn.innerHTML = '&times;';
          removeBtn.setAttribute('aria-label', '移除 ' + name);
          removeBtn.addEventListener('click', (function (svcName) {
            return function (e) {
              e.stopPropagation();
              var svcBtn = document.querySelector('.budget-offer-btn[data-budget="' + svcName + '"]');
              if (svcBtn) svcBtn.classList.remove('is-active');
              delete servicePriceMap[svcName];
              var sel = [];
              document.querySelectorAll('.budget-offer-btn.is-active').forEach(function (b) { sel.push(b.dataset.budget); });
              if (budgetEl) budgetEl.value = sel.join('、');
              if (sel.length > 0) {
                lastPriceBudget = sel[sel.length - 1];
                showPriceOptions(lastPriceBudget);
              } else {
                lastPriceBudget = '';
                showPriceOptions('');
              }
              updateSelectedDisplay();
            };
          })(name));
          tag.appendChild(removeBtn);
          tags.appendChild(tag);
        });
        row.classList.add('is-visible');
      }

      function showPriceOptions(service) {
        var ps = document.getElementById('budgetPriceSection');
        var po = document.getElementById('budgetPriceOffers');
        if (!ps || !po) return;
        var prices = priceMap[service];
        po.innerHTML = '';
        if (!prices) { ps.classList.remove('is-visible'); updateSelectedDisplay(); return; }
        var existingPrice = servicePriceMap[service] || '';
        prices.forEach(function (p) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'budget-price-btn' + (p === existingPrice ? ' is-active' : '');
          btn.textContent = p;
          btn.dataset.price = p;
          btn.addEventListener('click', function () {
            po.querySelectorAll('.budget-price-btn').forEach(function (b) { b.classList.remove('is-active'); });
            this.classList.add('is-active');
            servicePriceMap[lastPriceBudget] = this.dataset.price || '';
            updateSelectedDisplay();
          });
          po.appendChild(btn);
        });
        ps.classList.add('is-visible');
      }

      // Budget button — multi select
      var lastPriceBudget = '';
      document.querySelectorAll('.budget-offer-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var wasActive = this.classList.contains('is-active');

          if (wasActive) {
            // 已選中：切換到此服務的價格面板（不取消選擇）
            lastPriceBudget = this.dataset.budget || '';
            showPriceOptions(lastPriceBudget);
            return;
          }

          // 啟用此服務
          this.classList.add('is-active');
          var selected = [];
          document.querySelectorAll('.budget-offer-btn.is-active').forEach(function (b) {
            selected.push(b.dataset.budget);
          });
          if (budgetEl) budgetEl.value = selected.join('、');
          lastPriceBudget = this.dataset.budget || '';
          showPriceOptions(lastPriceBudget);
          updateSelectedDisplay();
        });
      });

      // AJAX submit
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        var ok = true;
        if (!nameEl || nameEl.value.trim() === '') { showError(nameEl, 'name-error', true); ok = false; }
        var ev = emailEl ? emailEl.value.trim() : '';
        if (!ev || !isEmail(ev)) { showError(emailEl, 'email-error', true); ok = false; }
        if (!phoneEl || phoneEl.value.trim() === '') { showError(phoneEl, 'phone-error', true); ok = false; }
        if (!captchaEl || captchaEl.value.trim() === '') { showError(captchaEl, 'captcha-error', true); ok = false; }
        if (!ok) { var first = form.querySelector('.invalid'); if (first) first.focus({ preventScroll: true }); return; }

        var btn = document.getElementById('btnSubmit');
        if (btn) { btn.disabled = true; btn.textContent = 'Sending... ／ 傳送中'; }

        // Show sending overlay
        var sendOv = document.getElementById('sending-overlay');
        if (sendOv) {
          sendOv.style.display = 'flex';
          // 強制 reflow 後加 fade-in class
          void sendOv.offsetWidth;
          sendOv.classList.add('active', 'fade-in');
        }

        fetch('contact?csrf_refresh', { method: 'GET' })
          .then(function (r) { return r.json(); })
          .then(function (csrf) {
            var csrfInput = form.querySelector('input[name="csrf_token"]');
            if (csrfInput && csrf.csrf_token) { csrfInput.value = csrf.csrf_token; }
            return fetch('contact', {
              method: 'POST',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              body: new FormData(form)
            });
          })
          .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
          })
          .then(function (data) {
            if (data.ok) {
              form.reset();
              document.querySelectorAll('.budget-offer-btn').forEach(function (b) { b.classList.remove('is-active'); });
              form.querySelectorAll('.valid').forEach(function (f) { f.classList.remove('valid'); });
              servicePriceMap = {};
              var ps = document.getElementById('budgetPriceSection');
              if (ps) { ps.classList.remove('is-visible'); }
              if (priceEl) priceEl.value = '';
              updateSelectedDisplay();
              // 更新驗證碼顯示為後端產生的新一組
              if (data.capA && data.capB) {
                var digits = document.querySelectorAll('.captcha-eq-display .cap-digit');
                if (digits[0]) digits[0].textContent = data.capA;
                if (digits[1]) digits[1].textContent = data.capB;
              }
              openLightbox();
            } else {
              var errEl = form.querySelector('.server-error');
              if (!errEl) {
                errEl = document.createElement('div');
                errEl.className = 'server-error';
                form.insertBefore(errEl, form.firstChild);
              }
              errEl.textContent = data.error || 'An error occurred. ／ 發生錯誤，請稍後再試';
              if (window._lenis && window._lenis.scrollTo) {
                window._lenis.scrollTo(errEl, { offset: -80 });
              } else {
                errEl.scrollIntoView({ behavior: 'auto', block: 'nearest' });
              }
            }
          })
          .catch(function () { alert('網路錯誤，請稍後再試。'); })
          .finally(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Submit ／ 送出'; }
            // Hide sending overlay
            var sendOv = document.getElementById('sending-overlay');
            if (sendOv) {
              sendOv.classList.remove('active', 'fade-in');
              sendOv.addEventListener('transitionend', function hideSendOv() {
                sendOv.style.display = 'none';
                sendOv.removeEventListener('transitionend', hideSendOv);
              });
            }
          });
      });

      function openLightbox() {
        var ov = document.getElementById('lb-overlay');
        if (ov) { ov.classList.add('active'); var cl = document.getElementById('lb-close'); if (cl) cl.focus(); document.addEventListener('keydown', lbKey); }
      }
      function closeLightbox() {
        var ov = document.getElementById('lb-overlay');
        if (ov) ov.classList.remove('active');
        document.removeEventListener('keydown', lbKey);
      }
      function lbKey(e) { if (e.key === 'Escape') closeLightbox(); }

      var ov = document.getElementById('lb-overlay');
      var cl = document.getElementById('lb-close');
      if (ov) ov.addEventListener('click', function (e) { if (e.target === this) closeLightbox(); });
      if (cl) cl.addEventListener('click', closeLightbox);

      /* ── Contact marquee: scroll-driven + reveal ── */
      (function () {
        var track = document.getElementById('contact-marquee-track');
        var marqueeEl = track ? track.closest('.contact-marquee') : null;
        if (!track || !marqueeEl || typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;

        // Scroll-driven scroll (moves left as page scrolls)
        gsap.to(track, {
          x: '-50%',
          ease: 'none',
          scrollTrigger: {
            trigger: document.body,
            start: 'top top',
            end: 'bottom bottom',
            scrub: 1
          }
        });

        // Slide up reveal when entering viewport
        gsap.to(marqueeEl, {
          clipPath: 'inset(0% 0 0 0)',
          duration: 1.0,
          ease: 'power3.inOut',
          scrollTrigger: {
            trigger: marqueeEl,
            start: 'top 92%'
          }
        });

        // Stagger hourglass spins
        var sepImgs = marqueeEl.querySelectorAll('.contact-marquee__sep img');
        var half = Math.round(sepImgs.length / 2);
        var period = 3;
        sepImgs.forEach(function (img, i) {
          img.style.animationDelay = '-' + ((i % half) * (period / half)).toFixed(3) + 's';
        });
      }());

    }());
  </script>

  <!-- Footer Reveal -->
  <style>
    .frev-wrap {
      overflow: hidden;
      display: inline-block;
      vertical-align: bottom;
    }

    .footer-video-cover {
      position: absolute;
      inset: 0;
      background: #0a0a0a;
      z-index: 2;
      pointer-events: none;
      transform-origin: right center;
      will-change: transform;
    }
  </style>
  <script>
    (function () {
      // Set footer parallax background image
      const parallaxBg = document.getElementById('footer-parallax-bg');
      if (parallaxBg && parallaxBg.dataset.bg) {
        parallaxBg.style.setProperty('--footer-bg-image', 'url("' + parallaxBg.dataset.bg + '")');
      }

      const footer = document.getElementById('site-footer');
      if (!footer || !window.gsap || !window.ScrollTrigger) return;
      gsap.registerPlugin(ScrollTrigger);

      const contactInfo = footer.querySelector('.footer-contact-info');
      const addrDiv = footer.querySelector('.footer-main-content > div > div[style]');
      const navLinks = footer.querySelector('.footer-nav-links');
      const thumb = footer.querySelector('.footer-video-thumb');

      const contactEmail = contactInfo ? contactInfo.querySelector('a') : null;
      const contactPhone = contactInfo ? contactInfo.querySelector('span') : null;

      if (contactEmail) {
        const ew = document.createElement('div');
        ew.style.overflow = 'hidden';
        contactEmail.parentNode.insertBefore(ew, contactEmail);
        ew.appendChild(contactEmail);
        gsap.set(contactEmail, { y: '105%' });
      }
      if (contactPhone) {
        const pw = document.createElement('div');
        pw.style.cssText = 'overflow:hidden;display:block;';
        contactPhone.parentNode.insertBefore(pw, contactPhone);
        pw.appendChild(contactPhone);
        gsap.set(contactPhone, { display: 'inline-block', y: '105%' });
      }

      const navAnchors = navLinks ? [...navLinks.querySelectorAll('a')] : [];
      navAnchors.forEach(a => {
        const wrap = document.createElement('span');
        wrap.className = 'frev-wrap';
        a.parentNode.insertBefore(wrap, a);
        wrap.appendChild(a);
      });
      gsap.set(navAnchors, { y: '120%' });

      let videoCover = null;
      if (thumb) {
        videoCover = document.createElement('div');
        videoCover.className = 'footer-video-cover';
        thumb.appendChild(videoCover);
      }

      const tl = gsap.timeline({
        scrollTrigger: { trigger: footer, start: 'top 82%' }
      });

      if (contactEmail) tl.to(contactEmail, { y: '0%', duration: 1.2, ease: 'power4.out' }, 0);
      if (contactPhone) tl.to(contactPhone, { y: '0%', duration: 0.9, ease: 'power3.out' }, 0.2);
      if (addrDiv) tl.from(addrDiv, { y: 14, opacity: 0, duration: 0.8, ease: 'power3.out' }, 0.35);
      if (navAnchors.length) tl.to(navAnchors, { y: '0%', duration: 0.85, stagger: 0.07, ease: 'power4.out' }, 0.48);
      if (videoCover) {
        tl.fromTo(videoCover,
          { scaleX: 1 },
          { scaleX: 0, duration: 0.85, ease: 'power4.inOut', transformOrigin: 'right center' },
          0.1
        );
      }
    })();
  </script>
</body>

</html>