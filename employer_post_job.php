<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once '../config.php';

if (empty($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }
if ($_SESSION['account_type'] !== 'employer') { header('Location: ../employer_dashboard.php'); exit; }

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) { header('Location: ../logout.php'); exit; }

$firstName = $user['first_name'];
$lastName  = $user['last_name'];
$fullName  = $firstName . ' ' . $lastName;
$initials  = strtoupper(substr($firstName,0,1) . substr($lastName,0,1));
$location  = $user['location']  ?? '';
$landmark  = $user['landmark']  ?? '';

// ── FIX: Correct profile photo path resolution ────────────────────────────────
// profile_photo is stored as e.g. "uploads/profiles/profile_xxx.jpg"
// This file is in /employer/ subfolder, so we need to go up one level: ../
$rawPhoto  = $user['profile_photo'] ?? '';
$photo     = '';
if ($rawPhoto) {
    // Always build the path relative to the root for <img src>
    // Since employer/ is one level deep, prepend ../
    $photo = '../' . ltrim($rawPhoto, '/');
}

// ── Recent posts for sidebar ──────────────────────────────────────────────────
$recentStmt = $pdo->prepare("
    SELECT j.*,
           (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = j.id) AS applicant_count
    FROM jobs j
    WHERE j.employer_id = ?
    ORDER BY j.created_at DESC
    LIMIT 8
");
$recentStmt->execute([$userId]);
$recentJobs = $recentStmt->fetchAll();

// ── Skill options ─────────────────────────────────────────────────────────────
$skillOptions = [
    'Carpenter','Plumber','Electrician','Painter','Welder',
    'Mason / Bricklayer','House Cleaner','Gardener / Landscaper',
    'Driver','Cook / Catering','General Laborer','Roofing Specialist',
    'Tile Setter','Other'
];

// ── Handle POST ───────────────────────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';
$fd         = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_job'])) {
    $fd = [
        'title'       => trim($_POST['title']        ?? ''),
        'description' => trim($_POST['description']  ?? ''),
        'skill'       => trim($_POST['skill_needed'] ?? ''),
        'location'    => trim($_POST['location']     ?? ''),
        'landmark'    => trim($_POST['landmark']     ?? ''),
        'daily_rate'  => trim($_POST['daily_rate']   ?? ''),
        'slots'       => max(1, min(100, (int)($_POST['slots'] ?? 1))),
    ];

    $errors = [];
    if (!$fd['title'])       $errors[] = 'Job title is required.';
    if (!$fd['description']) $errors[] = 'Job description is required.';
    if (!$fd['skill'])       $errors[] = 'Required skill is required.';
    if (!$fd['location'])    $errors[] = 'Location / city is required.';
    if (!is_numeric($fd['daily_rate']) || (float)$fd['daily_rate'] < 0)
                             $errors[] = 'Please enter a valid daily rate.';

    if (empty($errors)) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO jobs
                    (employer_id, title, description, skill_needed, location, landmark, daily_rate, slots, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())
            ");
            $ins->execute([
                $userId,
                $fd['title'],
                $fd['description'],
                $fd['skill'],
                $fd['location'],
                $fd['landmark'],
                (float)$fd['daily_rate'],
                $fd['slots'],
            ]);
            $successMsg = 'Job posted successfully!';
            $fd = [];
            $recentStmt->execute([$userId]);
            $recentJobs = $recentStmt->fetchAll();
        } catch (PDOException $e) {
            $errorMsg = 'Database error. Please try again.';
        }
    } else {
        $errorMsg = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Post a Job — iTRABAHO</title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --primary:#E8630A; --primary-dark:#C0510A; --secondary:#1A3A5C; --accent:#F5A623; --bg:#F2EDE6; --text:#1C2B3A; --muted:#6B7C8D; --success:#2D9E6B; --success-bg:#E8F5EE; --danger:#DC3545; --danger-bg:#FDECEA; --border:#E2D9CE; --employer:#1A6BAF; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Barlow',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; }

    .topbar { background:var(--secondary); height:60px; padding:0 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; position:sticky; top:0; z-index:100; }
    .logo { font-family:'Nunito',sans-serif; font-weight:900; font-size:22px; color:white; text-decoration:none; }
    .logo span { color:var(--accent); }
    .topbar-right { display:flex; align-items:center; gap:16px; }
    .topbar-avatar { width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,0.3); }
    .topbar-avatar-ph { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#1A6BAF,#2A8AD4); display:flex; align-items:center; justify-content:center; font-family:'Nunito',sans-serif; font-weight:800; font-size:13px; color:white; border:2px solid rgba(255,255,255,0.3); }
    .topbar-name { font-family:'Nunito',sans-serif; font-weight:700; font-size:14px; color:white; }
    .logout-link { color:rgba(255,255,255,0.5); font-size:13px; font-weight:600; font-family:'Nunito',sans-serif; text-decoration:none; display:flex; align-items:center; gap:5px; }
    .logout-link:hover { color:white; }

    .layout { display:flex; flex:1; }
    .sidebar { width:230px; background:white; border-right:1px solid var(--border); flex-shrink:0; position:sticky; top:60px; height:calc(100vh - 60px); overflow-y:auto; }
    .sidebar-profile { padding:20px 16px 16px; border-bottom:1px solid var(--border); text-align:center; }
    .profile-avatar { width:68px; height:68px; border-radius:50%; object-fit:cover; border:3px solid var(--employer); display:block; margin:0 auto 10px; }
    .profile-avatar-ph { width:68px; height:68px; border-radius:50%; background:linear-gradient(135deg,#1A3A5C,#2A5A8C); display:flex; align-items:center; justify-content:center; font-family:'Nunito',sans-serif; font-weight:800; font-size:24px; color:white; border:3px solid var(--employer); margin:0 auto 10px; }
    .profile-name { font-family:'Nunito',sans-serif; font-weight:800; font-size:15px; color:var(--text); }
    .profile-role { font-size:12px; color:var(--muted); margin-top:2px; }
    .employer-badge { display:inline-flex; align-items:center; gap:6px; background:#EBF5FF; color:var(--employer); font-family:'Nunito',sans-serif; font-weight:700; font-size:11px; padding:3px 10px; border-radius:20px; margin-top:8px; }
    .nav-section { padding:14px 16px 6px; font-family:'Nunito',sans-serif; font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; }
    .nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; color:var(--muted); cursor:pointer; border-radius:10px; margin:2px 8px; text-decoration:none; transition:all 0.15s; }
    .nav-item:hover { background:var(--bg); color:var(--text); }
    .nav-item.active { background:#EBF5FF; color:var(--employer); }
    .nav-item svg { flex-shrink:0; }

    .main { flex:1; padding:24px; overflow-y:auto; }
    .page-header { margin-bottom:22px; }
    .breadcrumb { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); font-family:'Nunito',sans-serif; font-weight:600; margin-bottom:6px; }
    .breadcrumb a { color:var(--muted); text-decoration:none; }
    .breadcrumb a:hover { color:var(--employer); }
    .page-title { font-family:'Nunito',sans-serif; font-weight:900; font-size:22px; color:var(--text); margin-bottom:2px; }
    .page-sub { font-size:13px; color:var(--muted); }

    .alert { border-radius:12px; padding:13px 16px; font-size:13px; font-weight:600; font-family:'Nunito',sans-serif; display:flex; align-items:flex-start; gap:10px; margin-bottom:20px; }
    .alert-success { background:var(--success-bg); border:1.5px solid #A8D8BB; color:#1a6b40; }
    .alert-danger  { background:var(--danger-bg);  border:1.5px solid #F5C6C0; color:#c62828; }
    .alert svg { flex-shrink:0; margin-top:1px; }

    .content-grid { display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start; }
    .card { background:white; border-radius:16px; border:1.5px solid var(--border); margin-bottom:20px; overflow:hidden; }
    .card:last-child { margin-bottom:0; }
    .card-header { display:flex; align-items:center; gap:12px; padding:16px 20px 0; }
    .card-header-icon { width:36px; height:36px; border-radius:10px; background:#EBF5FF; color:var(--employer); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .card-header-icon.orange { background:#FFF1E8; color:var(--primary); }
    .card-header-title { font-family:'Nunito',sans-serif; font-weight:900; font-size:15px; color:var(--text); }
    .card-header-sub { font-size:12px; color:var(--muted); margin-top:1px; }
    .card-body { padding:16px 20px 20px; }

    .form-group { margin-bottom:18px; }
    .form-label { font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; color:var(--text); display:flex; align-items:center; gap:6px; margin-bottom:7px; }
    .form-label .opt { font-size:11px; color:var(--muted); font-weight:500; }
    .input-wrap { position:relative; display:flex; align-items:center; }
    .input-icon { position:absolute; left:13px; color:var(--muted); display:flex; pointer-events:none; }
    .form-control { width:100%; padding:11px 13px 11px 40px; border:1.5px solid var(--border); border-radius:10px; font-size:14px; font-family:'Barlow',sans-serif; color:var(--text); background:var(--bg); outline:none; transition:border-color 0.15s, box-shadow 0.15s, background 0.15s; }
    .form-control.no-icon { padding-left:13px; }
    .form-control:focus { border-color:var(--employer); box-shadow:0 0 0 3px rgba(26,107,175,0.1); background:white; }
    .form-control::placeholder { color:#aab4bf; }
    select.form-control { -webkit-appearance:none; cursor:pointer; }
    textarea.form-control { resize:vertical; min-height:110px; line-height:1.6; padding-top:11px; }
    .form-hint { font-size:11px; color:var(--muted); margin-top:5px; line-height:1.4; }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

    .peso-pfx { position:absolute; left:13px; font-size:14px; font-weight:700; color:var(--muted); font-family:'Nunito',sans-serif; pointer-events:none; }
    .peso-input { padding-left:26px !important; }

    .slots-wrap { display:flex; align-items:center; }
    .slots-btn { width:38px; height:44px; background:var(--bg); border:1.5px solid var(--border); color:var(--text); font-size:20px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; flex-shrink:0; }
    .slots-btn:first-child { border-radius:10px 0 0 10px; border-right:none; }
    .slots-btn:last-child  { border-radius:0 10px 10px 0; border-left:none; }
    .slots-btn:hover { background:#EBF5FF; color:var(--employer); }
    .slots-num { flex:1; text-align:center; border:1.5px solid var(--border); border-left:none; border-right:none; height:44px; font-size:15px; font-weight:800; font-family:'Nunito',sans-serif; color:var(--text); background:white; outline:none; }
    .slots-num:focus { border-color:var(--employer); }

    .btn-submit { width:100%; padding:13px; background:var(--primary); color:white; border:none; border-radius:12px; font-family:'Nunito',sans-serif; font-weight:800; font-size:15px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:9px; box-shadow:0 4px 14px rgba(232,99,10,0.26); transition:background 0.15s, transform 0.1s; }
    .btn-submit:hover { background:var(--primary-dark); }
    .btn-submit:active { transform:scale(0.98); }
    .btn-submit:disabled { opacity:0.6; cursor:not-allowed; }
    .btn-secondary { width:100%; padding:11px; background:white; color:var(--text); border:1.5px solid var(--border); border-radius:12px; font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:border-color 0.15s, color 0.15s; text-decoration:none; margin-top:10px; }
    .btn-secondary:hover { border-color:var(--employer); color:var(--employer); }

    .preview-box { background:var(--bg); border-radius:12px; border:1.5px dashed var(--border); padding:16px; min-height:78px; transition:all 0.2s; margin-bottom:0; }
    .preview-box.live { border-style:solid; background:white; }
    .preview-title { font-family:'Nunito',sans-serif; font-weight:800; font-size:14px; color:var(--text); margin-bottom:6px; }
    .preview-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
    .chip { display:inline-flex; align-items:center; gap:4px; background:var(--bg); border:1px solid var(--border); font-size:11px; font-weight:600; font-family:'Nunito',sans-serif; color:var(--muted); padding:2px 8px; border-radius:20px; }
    .chip.green { background:var(--success-bg); border-color:#A8D8BB; color:#1a6b40; }
    .preview-desc { font-size:12px; color:var(--muted); line-height:1.6; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
    .preview-empty { text-align:center; font-size:12px; color:var(--border); font-style:italic; padding:8px 0; }

    .tip-item { display:flex; align-items:flex-start; gap:10px; font-size:12px; color:var(--muted); line-height:1.5; margin-bottom:12px; }
    .tip-item:last-child { margin-bottom:0; }
    .tip-num { width:20px; height:20px; border-radius:50%; background:#EBF5FF; color:var(--employer); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; font-family:'Nunito',sans-serif; flex-shrink:0; margin-top:1px; }

    .job-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; padding:11px 0; border-bottom:1px solid var(--border); }
    .job-row:first-child { padding-top:0; }
    .job-row:last-child { border-bottom:none; padding-bottom:0; }
    .job-row-title { font-family:'Nunito',sans-serif; font-weight:700; font-size:13px; color:var(--text); margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px; }
    .job-row-meta { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
    .status-badge { display:inline-flex; align-items:center; font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; font-family:'Nunito',sans-serif; }
    .status-open   { background:#E8F5EE; color:#1a6b40; }
    .status-closed { background:#F5F5F5; color:#666; }
    .status-in_progress { background:#FFF8E1; color:#856404; }
    .status-completed { background:#EEF2FF; color:#3730A3; }

    .empty-state { text-align:center; padding:24px 12px; color:var(--muted); font-family:'Nunito',sans-serif; font-size:13px; font-weight:600; line-height:1.7; }
    .empty-state svg { display:block; margin:0 auto 10px; color:var(--border); }

    @keyframes spin { to { transform:rotate(360deg); } }
    @media (max-width:1050px) { .content-grid { grid-template-columns:1fr; } }
    @media (max-width:768px)  { .sidebar { display:none; } .main { padding:16px; } .form-row { grid-template-columns:1fr; } }
  </style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <a href="../employer_dashboard.php" class="logo">i<span>TRABAHO</span></a>
  <div class="topbar-right">
    <div style="display:flex;align-items:center;gap:10px;">
      <?php if ($photo): ?>
        <img src="<?= htmlspecialchars($photo) ?>" class="topbar-avatar" alt="">
      <?php else: ?>
        <div class="topbar-avatar-ph"><?= $initials ?></div>
      <?php endif; ?>
      <span class="topbar-name"><?= htmlspecialchars($firstName) ?></span>
    </div>
    <a href="../logout.php" class="logout-link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</div>

<div class="layout">

  <!-- SIDEBAR -->
  <div class="sidebar">
    <div class="sidebar-profile">
      <?php if ($photo): ?>
        <img src="<?= htmlspecialchars($photo) ?>" class="profile-avatar" alt="">
      <?php else: ?>
        <div class="profile-avatar-ph"><?= $initials ?></div>
      <?php endif; ?>
      <div class="profile-name"><?= htmlspecialchars($fullName) ?></div>
      <div class="profile-role"><?= $location ? htmlspecialchars($location) : 'Employer' ?></div>
      <div><div class="employer-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Employer
      </div></div>
    </div>

    <div class="nav-section" style="margin-top:8px;">Main Menu</div>
    <a href="../employer_dashboard.php" class="nav-item">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a href="employer_find_workers.php" class="nav-item">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Find Workers
    </a>
    <!-- POST A JOB replaces My Job Posts in sidebar -->
    <a href="employer_post_job.php" class="nav-item active">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
      Post a Job
    </a>
    <a href="employer_hired.php" class="nav-item">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Hired Workers
    </a>

    <div class="nav-section">Account</div>
    <a href="../edit_profile.php" class="nav-item">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Edit Profile
    </a>
    <a href="../logout.php" class="nav-item">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>

  <!-- MAIN -->
  <div class="main">

    <div class="page-header">
      <div class="breadcrumb">
        <a href="../employer_dashboard.php">Dashboard</a>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        Post a Job
      </div>
      <div class="page-title">Post a New Job</div>
      <div class="page-sub">Fill in the details below to find the right worker for your project</div>
    </div>

    <?php if ($successMsg): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <div><?= htmlspecialchars($successMsg) ?> &nbsp;<a href="employer_jobs.php" style="color:var(--success);font-weight:700;text-decoration:none;">Manage your job posts →</a></div>
    </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
    <div class="alert alert-danger">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div><?= $errorMsg ?></div>
    </div>
    <?php endif; ?>

    <div class="content-grid">

      <!-- LEFT: Form -->
      <div>
        <form method="POST" id="jobForm" novalidate>
          <input type="hidden" name="post_job" value="1">

          <!-- Job Details -->
          <div class="card">
            <div class="card-header">
              <div class="card-header-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              </div>
              <div>
                <div class="card-header-title">Job Details</div>
                <div class="card-header-sub">Describe the work you need done</div>
              </div>
            </div>
            <div class="card-body">
              <div class="form-group">
                <label class="form-label" for="title">Job Title</label>
                <div class="input-wrap">
                  <div class="input-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
                  <input type="text" id="title" name="title" class="form-control" placeholder="e.g. Need a Plumber for pipe repair" value="<?= htmlspecialchars($fd['title'] ?? '') ?>" maxlength="120" required oninput="livePreview()">
                </div>
                <div class="form-hint">Be specific — a clear title gets more applicants.</div>
              </div>
              <div class="form-group">
                <label class="form-label" for="skill_needed">Skill Required</label>
                <div class="input-wrap">
                  <div class="input-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
                  <select id="skill_needed" name="skill_needed" class="form-control" required onchange="livePreview()">
                    <option value="">Select required skill...</option>
                    <?php foreach ($skillOptions as $s): ?>
                      <option value="<?= htmlspecialchars($s) ?>" <?= ($fd['skill'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label" for="description">Job Description</label>
                <textarea id="description" name="description" class="form-control no-icon" placeholder="Describe the work in detail — what needs to be done, working hours, materials provided..." required oninput="livePreview()"><?= htmlspecialchars($fd['description'] ?? '') ?></textarea>
                <div class="form-hint">Mention scope of work, expected duration, and any special requirements.</div>
              </div>
            </div>
          </div>

          <!-- Location & Pay -->
          <div class="card">
            <div class="card-header">
              <div class="card-header-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              </div>
              <div>
                <div class="card-header-title">Location &amp; Pay</div>
                <div class="card-header-sub">Where is the job and how much will you pay?</div>
              </div>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="location">City / Municipality</label>
                  <div class="input-wrap">
                    <div class="input-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                    <input type="text" id="location" name="location" class="form-control" placeholder="e.g. Kabankalan City" value="<?= htmlspecialchars($fd['location'] ?? $location) ?>" required oninput="livePreview()">
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label" for="landmark">Landmark / Area <span class="opt">(optional)</span></label>
                  <div class="input-wrap">
                    <div class="input-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></div>
                    <input type="text" id="landmark" name="landmark" class="form-control" placeholder="e.g. Near SM, Brgy. 5" value="<?= htmlspecialchars($fd['landmark'] ?? $landmark) ?>" oninput="livePreview()">
                  </div>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label" for="daily_rate">Daily Rate (₱)</label>
                  <div class="input-wrap">
                    <span class="peso-pfx">₱</span>
                    <input type="number" id="daily_rate" name="daily_rate" class="form-control peso-input" placeholder="e.g. 800" value="<?= htmlspecialchars($fd['daily_rate'] ?? '') ?>" min="0" max="99999" step="50" required oninput="livePreview()">
                  </div>
                  <div class="form-hint">Typical range: ₱500–₱1,200 / day</div>
                </div>
                <div class="form-group">
                  <label class="form-label" for="slotsNum">Workers Needed</label>
                  <div class="slots-wrap">
                    <button type="button" class="slots-btn" onclick="changeSlots(-1)">−</button>
                    <input type="number" id="slotsNum" name="slots" class="slots-num" value="<?= (int)($fd['slots'] ?? 1) ?>" min="1" max="100" oninput="livePreview()">
                    <button type="button" class="slots-btn" onclick="changeSlots(1)">+</button>
                  </div>
                  <div class="form-hint" style="margin-top:5px;">How many workers do you need?</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div class="card">
            <div class="card-body">
              <button type="submit" class="btn-submit" id="submitBtn">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Post Job Now
              </button>
              <a href="employer_jobs.php" class="btn-secondary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Manage My Job Posts
              </a>
            </div>
          </div>

        </form>
      </div>

      <!-- RIGHT: Sidebar panels -->
      <div>

        <!-- Live Preview -->
        <div class="card">
          <div class="card-header">
            <div class="card-header-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </div>
            <div>
              <div class="card-header-title">Live Preview</div>
              <div class="card-header-sub">How workers will see your post</div>
            </div>
          </div>
          <div class="card-body">
            <div class="preview-box" id="previewBox">
              <div class="preview-empty" id="pvEmpty">Start filling the form to preview your post</div>
              <div id="pvContent" style="display:none;">
                <div class="preview-title" id="pvTitle"></div>
                <div class="preview-chips" id="pvChips"></div>
                <div class="preview-desc" id="pvDesc"></div>
              </div>
            </div>
            <!-- Posted-by with fixed photo path -->
            <div style="margin-top:14px;background:var(--bg);border-radius:10px;padding:12px;border:1px solid var(--border);display:flex;align-items:center;gap:10px;">
              <?php if ($photo): ?>
                <img src="<?= htmlspecialchars($photo) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid var(--border);" alt="">
              <?php else: ?>
                <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1A3A5C,#2A5A8C);display:flex;align-items:center;justify-content:center;font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;color:white;flex-shrink:0;"><?= $initials ?></div>
              <?php endif; ?>
              <div>
                <div style="font-family:'Nunito',sans-serif;font-weight:700;font-size:12px;color:var(--text);"><?= htmlspecialchars($fullName) ?></div>
                <div style="font-size:11px;color:var(--muted);"><?= $location ? htmlspecialchars($location) : 'No location set' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Tips -->
        <div class="card">
          <div class="card-header">
            <div class="card-header-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
              <div class="card-header-title">Tips for a Great Post</div>
              <div class="card-header-sub">Get better responses faster</div>
            </div>
          </div>
          <div class="card-body">
            <div class="tip-item"><div class="tip-num">1</div><div>Be specific — mention dimensions, quantities, or the exact problem.</div></div>
            <div class="tip-item"><div class="tip-num">2</div><div>State whether you provide tools and materials, or if the worker brings their own.</div></div>
            <div class="tip-item"><div class="tip-num">3</div><div>Set a fair rate — underpaying leads to fewer and lower-quality applicants.</div></div>
            <div class="tip-item"><div class="tip-num">4</div><div>Add a landmark so workers know if they can reach your location.</div></div>
          </div>
        </div>

        <!-- Recent Posts (quick links to manage) -->
        <div class="card">
          <div class="card-header">
            <div class="card-header-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
              <div class="card-header-title">Your Recent Posts</div>
              <div class="card-header-sub">Last 8 job postings</div>
            </div>
          </div>
          <div class="card-body">
            <?php if (empty($recentJobs)): ?>
              <div class="empty-state">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                No job posts yet.<br>This will be your first one!
              </div>
            <?php else: ?>
              <?php foreach ($recentJobs as $j): ?>
                <div class="job-row">
                  <div style="flex:1;min-width:0;">
                    <div class="job-row-title"><?= htmlspecialchars($j['title']) ?></div>
                    <div class="job-row-meta">
                      <span class="status-badge status-<?= htmlspecialchars($j['status']) ?>"><?= ucfirst(str_replace('_',' ', $j['status'])) ?></span>
                      <span><?= (int)$j['applicant_count'] ?> applicant<?= $j['applicant_count'] != 1 ? 's' : '' ?></span>
                      <span><?= date('M j', strtotime($j['created_at'])) ?></span>
                    </div>
                  </div>
                  <a href="employer_jobs.php?edit=<?= (int)$j['id'] ?>" style="color:var(--employer);display:flex;align-items:center;flex-shrink:0;padding:4px;" title="Edit">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                  </a>
                </div>
              <?php endforeach; ?>
              <a href="employer_jobs.php" class="btn-secondary" style="margin-top:14px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Manage All Job Posts
              </a>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div><!-- /content-grid -->

  </div><!-- /main -->
</div><!-- /layout -->

<script>
  function changeSlots(d) {
    const el = document.getElementById('slotsNum');
    el.value = Math.min(100, Math.max(1, (parseInt(el.value) || 1) + d));
    livePreview();
  }

  function livePreview() {
    const title = document.getElementById('title').value.trim();
    const skill = document.getElementById('skill_needed').value;
    const desc  = document.getElementById('description').value.trim();
    const loc   = document.getElementById('location').value.trim();
    const lm    = document.getElementById('landmark').value.trim();
    const rate  = document.getElementById('daily_rate').value.trim();
    const slots = document.getElementById('slotsNum').value.trim();

    const hasAny = title || skill || desc;
    const box    = document.getElementById('previewBox');
    const empty  = document.getElementById('pvEmpty');
    const cont   = document.getElementById('pvContent');

    if (!hasAny) { box.classList.remove('live'); empty.style.display='block'; cont.style.display='none'; return; }
    box.classList.add('live'); empty.style.display='none'; cont.style.display='block';

    document.getElementById('pvTitle').textContent = title || '(untitled)';
    const chips = document.getElementById('pvChips');
    chips.innerHTML = '';
    if (skill) chips.innerHTML += `<span class="chip">${skill}</span>`;
    if (loc)   chips.innerHTML += `<span class="chip">${lm ? loc+' · '+lm : loc}</span>`;
    if (rate)  chips.innerHTML += `<span class="chip green">₱${Number(rate).toLocaleString()}/day</span>`;
    if (slots) chips.innerHTML += `<span class="chip">${slots} slot${slots!=1?'s':''}</span>`;
    document.getElementById('pvDesc').textContent = desc;
  }

  document.getElementById('jobForm').addEventListener('submit', function(e) {
    const fields = ['title','skill_needed','description','location','daily_rate'];
    let ok = true;
    fields.forEach(function(name) {
      const el = document.querySelector(`[name="${name}"]`);
      if (el && !el.value.trim()) { el.style.borderColor='var(--danger)'; ok=false; }
      else if (el) { el.style.borderColor=''; }
    });
    if (!ok) {
      e.preventDefault();
      window.scrollTo({top:0,behavior:'smooth'});
      if (!document.querySelector('.alert-danger')) {
        const a = document.createElement('div');
        a.className = 'alert alert-danger';
        a.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><div>Please fill in all required fields.</div>`;
        document.querySelector('.content-grid').insertAdjacentElement('beforebegin', a);
      }
      return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 0.7s linear infinite;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.18-3.57"/></svg> Posting...`;
  });

  document.querySelectorAll('.form-control,.slots-num').forEach(function(el) {
    el.addEventListener('focus', function() { this.style.borderColor=''; });
  });

  livePreview();
</script>
</body>
</html>
