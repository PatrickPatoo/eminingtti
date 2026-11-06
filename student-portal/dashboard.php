<?php
session_start();
require_once "connect.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("SELECT id, full_name, email, role, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$_SESSION["full_name"] = $user["full_name"];
$_SESSION["email"] = $user["email"];
$_SESSION["role"] = $user["role"];

$isAdmin = $user["role"] === "admin";

function h($value) {
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}

$firstName = explode(" ", trim($user["full_name"]))[0] ?? "User";

// Check if fee structure exists
$feeStructurePath = "../assets/fee_structure.pdf";
$feeStructureExists = file_exists(__DIR__ . "/" . $feeStructurePath);

$message = "";
$error = "";

// Handle URL messages
if (isset($_GET["fee_deleted"])) {
    $message = "Fee structure deleted successfully.";
} elseif (isset($_GET["fee_delete_error"])) {
    $error = "Failed to delete the fee structure. Check file permissions.";
}

$totalUsers = 0;
$totalAdmins = 0;
if ($isAdmin) {
    $stats = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(role = 'admin'), 0) AS admins FROM users");
    if ($stats) {
        $statsRow = $stats->fetch_assoc();
        $totalUsers = (int) $statsRow["total"];
        $totalAdmins = (int) $statsRow["admins"];
        $stats->free();
    }
}

$unreadNotifications = 0;
$notificationStmt = $conn->prepare("SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notificationStmt) {
    $notificationStmt->bind_param("i", $userId);
    $notificationStmt->execute();
    $notificationResult = $notificationStmt->get_result()->fetch_assoc();
    $unreadNotifications = (int) ($notificationResult["unread_count"] ?? 0);
    $notificationStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Emining TTI Portal</title>
    
    <!-- Google Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <style>
        :root { color-scheme: light; --page-bg: #f4f6fb; --card-bg: #fff; --text: #202938; --muted: #687386; --border: #e5e7eb; --accent: #d62828; --welcome-start: #30343b; --welcome-end: #515762; --shadow: 0 5px 22px rgba(0,0,0,.05); --sidebar-width: 250px; }
        body.dark { color-scheme: dark; --page-bg: #111827; --card-bg: #1f2937; --text: #f3f4f6; --muted: #c0c8d4; --border: #374151; --welcome-start: #171827; --welcome-end: #25253b; --shadow: 0 5px 22px rgba(0,0,0,.16); }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--page-bg); color: var(--text); transition: background .3s ease, color .3s ease; }
        a, button { -webkit-tap-highlight-color: transparent; }
        
        /* OVERLAY */
        .overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1005; opacity: 0; transition: opacity 0.3s ease; }
        .overlay.active { display: block; opacity: 1; }

        /* SIDEBAR */
        .sidebar { position: fixed; top: 0; bottom: 0; left: 0; width: var(--sidebar-width); padding: 24px 17px; background: #fbe891; color: #272727; overflow-y: auto; z-index: 1010; transition: transform 0.3s ease; }
        .brand { text-align: center; padding-bottom: 22px; border-bottom: 1px solid rgba(0,0,0,.13); }
        .brand img { display: block; width: 100%; max-width: 130px; height: auto; max-height: 90px; object-fit: contain; margin: 0 auto 15px; background-color: #ffffff; padding: 8px; border-radius: 10px; box-shadow: 0 3px 8px rgba(0,0,0,0.1); }
        .brand p { margin: 0; color: #5c5540; font-size: 13px; font-weight: bold; }
        .menu-label { margin: 25px 12px 10px; color: #766a43; font-size: 11px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .nav-link { display: flex; align-items: center; gap: 11px; padding: 13px 14px; margin: 5px 0; border-radius: 9px; color: #272727; text-decoration: none; font-size: 14px; font-weight: 600; transition: background .2s, color .2s; }
        .nav-link:hover, .nav-link.active { background: #fff; color: #b91c1c; }
        .nav-link .material-icons { font-size: 20px; color: #766a43; transition: color 0.2s; }
        .nav-link:hover .material-icons, .nav-link.active .material-icons { color: #b91c1c; }
        .sidebar-bottom { margin-top: 25px; padding-top: 15px; border-top: 1px solid rgba(0,0,0,.13); }
        
        /* MAIN CONTENT */
        .main { display: flex; flex-direction: column; margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }
        .content { flex: 1; padding: 28px; }
        
        /* TOPBAR LAYOUT (FIXED TO SAME LINE) */
        .topbar { display: flex; flex-direction: row; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 27px; flex-wrap: nowrap; }
        .topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .topbar h1 { margin: 0; font-size: 22px; white-space: nowrap; }
        .muted { color: var(--muted); font-size: 13px; line-height: 1.2; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .top-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .mobile-menu-btn { display: none; background: transparent; border: none; color: var(--text); cursor: pointer; padding: 0; }

        /* Buttons */
        .button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 14px; border-radius: 8px; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; background: var(--accent); color: #fff; border: none; white-space: nowrap; }
        .button:hover { background: #b91c1c; }
        .notification-button { position: relative; min-width: 40px; min-height: 38px; padding: 7px 10px; background: var(--card-bg); color: var(--text); border: 1px solid var(--border); font-size: 18px; }
        .notification-button:hover { background: var(--card-bg); border-color: var(--accent); }
        .notification-number { position: absolute; top: -6px; right: -6px; display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 20px; background: #dc2626; color: #fff; font-size: 10px; font-weight: bold; border: 2px solid var(--card-bg); }
        
        /* Theme Toggle */
        .theme-control { display: flex; align-items: center; gap: 10px; }
        .theme-switch { position: relative; width: 60px; height: 32px; flex-shrink: 0; border: 1px solid rgba(120,140,190,.28); border-radius: 50px; padding: 0; cursor: pointer; background: linear-gradient(135deg, #292941, #414166); box-shadow: inset 0 2px 6px rgba(0,0,0,.25), 0 2px 8px rgba(50,50,90,.12); transition: background .35s ease, box-shadow .35s ease; outline-offset: 4px; }
        .theme-switch:hover { box-shadow: 0 0 0 3px rgba(110,130,220,.12), 0 2px 10px rgba(50,50,90,.2); }
        .switch-knob { position: absolute; width: 24px; height: 24px; left: 3px; top: 3px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: radial-gradient(circle at 35% 30%, #fff, #a5eaff 70%); color: #fff; font-size: 14px; line-height: 1; box-shadow: 0 0 6px rgba(91,221,255,.9), 0 0 12px rgba(91,221,255,.45), 0 1px 3px rgba(0,0,0,.3); transform: translateX(0); transition: transform .38s cubic-bezier(.68,-.3,.27,1.3), background .35s ease, box-shadow .35s ease; }
        .switch-knob::before { content: "☀"; }
        body.dark .theme-switch { background: linear-gradient(135deg, #111827, #17182c); box-shadow: inset 0 2px 6px rgba(0,0,0,.45), 0 0 10px rgba(97,91,255,.12); }
        body.dark .switch-knob { transform: translateX(28px); background: radial-gradient(circle at 35% 30%, #fff, #80edff 72%); box-shadow: 0 0 8px rgba(88,233,255,.95), 0 0 16px rgba(88,233,255,.5), 0 1px 3px rgba(0,0,0,.35); }
        body.dark .switch-knob::before { content: "☾"; font-size: 16px; }
        
        /* Cards and Panels */
        .welcome { padding: 28px; margin-bottom: 24px; border-radius: 15px; color: #fff; background: linear-gradient(120deg, var(--welcome-start), var(--welcome-end)); box-shadow: var(--shadow); transition: background .3s ease; }
        .welcome h2 { margin: 0 0 10px; font-size: 24px; }
        .welcome p { margin: 0; line-height: 1.7; color: #fff1f1; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 18px; margin-bottom: 24px; }
        .stat-card, .panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: 13px; padding: 22px; box-shadow: var(--shadow); transition: background .3s ease, border-color .3s ease; }
        .stat-title { color: var(--muted); font-size: 14px; }
        .stat-number { margin: 12px 0 7px; font-size: 29px; font-weight: bold; }
        .panel h3 { margin: 0 0 10px; }
        .quick-links { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 15px; margin-top: 18px; }
        .quick-link { display: block; padding: 18px; background: var(--card-bg); color: var(--text); border: 1px solid var(--border); border-radius: 10px; text-decoration: none; transition: border-color .2s, transform .2s, background .3s ease; }
        .quick-link:hover { border-color: var(--accent); transform: translateY(-2px); }
        .quick-link strong { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 15px; }
        .quick-link strong .material-icons { font-size: 20px; color: var(--accent); }
        .quick-link span { color: var(--muted); font-size: 13px; line-height: 1.6; }
        .account-row { display: flex; align-items: center; gap: 12px; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--border); }
        .avatar { width: 45px; height: 45px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: #fee2e2; color: #991b1b; font-weight: bold; font-size: 18px; }
        .footer-quick-links { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 20px; padding: 22px 18px; background: var(--card-bg); border-top: 1px solid var(--border); }
        .footer-quick-links strong { color: var(--text); font-size: 14px; }
        .footer-quick-links a { color: #dc2626; text-decoration: none; font-size: 14px; font-weight: 600; }
        .footer-quick-links a:hover { text-decoration: underline; }
        .footer { flex-shrink: 0; padding: 22px 28px; background: #343a40 !important; border-top: 1px solid #4b5157; text-align: center; color: #fff !important; font-size: 13px; line-height: 1.8; }
        .footer p { margin: 4px 0; color: #fff !important; }

        /* Fee Structure Card Specific */
        .fee-card { position: relative; }
        .fee-card-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 15px; }
        .fee-btn-download { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 8px 12px; background: #1e73be; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; }
        .fee-btn-download:hover { background: #155a96; }
        .fee-btn-update { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 8px 12px; background: #d62828; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; }
        .fee-btn-update:hover { background: #b91c1c; }
        .fee-btn-delete { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 8px 12px; background: #991b1b; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; }
        .fee-btn-delete:hover { background: #7f1d1d; }
        
        /* Notifications for Fee actions */
        .success { padding: 12px; margin-bottom: 18px; background: #dcfce7; color: #166534; border-radius: 8px; border: 1px solid #86efac; }
        .error { padding: 12px; margin-bottom: 18px; background: #fee2e2; color: #991b1b; border-radius: 8px; border: 1px solid #fecdd3; }

        /* =========================================
           MOBILE RESPONSIVE LAYOUT
           ========================================= */
        @media (max-width: 800px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main { margin-left: 0; }
            .content { padding: 15px; }
            
            .mobile-menu-btn { display: block; }
            
            .topbar { flex-wrap: nowrap; gap: 8px; margin-bottom: 15px; }
            .topbar .muted { display: none; }
            .topbar h1 { font-size: 18px; }
            
            .top-actions { gap: 6px; }
            .button { padding: 7px 10px; font-size: 11px; }
            .theme-switch { width: 50px; height: 28px; }
            .switch-knob { width: 22px; height: 22px; top: 2px; left: 2px; font-size: 12px; }
            body.dark .switch-knob { transform: translateX(22px); }

            .footer { padding: 20px 15px; }
            .welcome { padding: 20px; }
            .quick-links { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 600px) {
            .footer-quick-links { gap: 14px; padding: 18px 10px; flex-direction: column; text-align: center; }
            .footer-quick-links strong { width: 100%; text-align: center; margin-bottom: 5px; }
            .stats { grid-template-columns: 1fr; }
            .account-row { flex-direction: column; text-align: center; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition-duration: .01ms !important; } }
    </style>
</head>
<body>

<!-- Mobile Overlay -->
<div class="overlay" id="mobileOverlay"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/logo.png" alt="Emining TTI Logo">
        <p>Account Management Portal</p>
    </div>

    <div class="menu-label">Main Menu</div>
    <a class="nav-link active" href="dashboard.php"><span class="material-icons">dashboard</span> Dashboard</a>
    <a class="nav-link" href="profile.php"><span class="material-icons">person</span> My Profile</a>
    <a class="nav-link" href="courses.php"><span class="material-icons">library_books</span> Available Courses</a>
    <a class="nav-link" href="my_applications.php"><span class="material-icons">assignment</span> My Applications</a>

    <?php if ($isAdmin): ?>
        <a class="nav-link" href="users.php"><span class="material-icons">group</span> Registered Users</a>
        <a class="nav-link" href="manage_courses.php"><span class="material-icons">settings</span> Manage Courses</a>
    <?php endif; ?>

    <div class="sidebar-bottom">
        <a class="nav-link" href="../index.html"><span class="material-icons">language</span> Main Website</a>
        <a class="nav-link" href="logout.php"><span class="material-icons">logout</span> Logout</a>
    </div>
</aside>

<div class="main">
    <main class="content">
        
        <!-- TOPBAR FIXED TO SINGLE LINE -->
        <header class="topbar">
            
            <!-- Left Side: Menu Button & Title -->
            <div class="topbar-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <span class="material-icons">menu</span>
                </button>
                <div style="display: flex; flex-direction: column;">
                    <h1>Dashboard</h1>
                    <span class="muted">Welcome back, <?= h($firstName) ?>.</span>
                </div>
            </div>

            <!-- Right Side: Notifications, Theme Toggle, Edit Profile -->
            <div class="top-actions">
                <a class="button notification-button" href="notifications.php" title="Notifications">
                    <span class="material-icons">notifications</span>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-number"><?= $unreadNotifications > 99 ? "99+" : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>
                <div class="theme-control">
                    <button type="button" class="theme-switch" id="themeToggle" role="switch" aria-checked="false">
                        <span class="switch-knob"></span>
                    </button>
                </div>
                <a class="button" href="profile.php">Edit Profile</a>
            </div>

        </header>

        <!-- Success / Error Messages for Fee Deletion -->
        <?php if ($message !== ""): ?><div class="success"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

        <section class="welcome">
            <h2>Hello, <?= h($user["full_name"]) ?>!</h2>
            <p>Welcome to your account dashboard. Manage your personal information, explore available courses, apply online and track your applications.</p>
        </section>

        <?php if ($isAdmin): ?>
            <section class="stats">
                <div class="stat-card"><div class="stat-title">Registered Users</div><div class="stat-number"><?= $totalUsers ?></div><div class="muted">Accounts in the system</div></div>
                <div class="stat-card"><div class="stat-title">Administrators</div><div class="stat-number"><?= $totalAdmins ?></div><div class="muted">Accounts with admin access</div></div>
                <div class="stat-card"><div class="stat-title">Your Access</div><div class="stat-number">Admin</div><div class="muted">Administrator privileges</div></div>
            </section>
        <?php else: ?>
            <section class="stats">
                <div class="stat-card"><div class="stat-title">Account Status</div><div class="stat-number">Active</div><div class="muted">You are signed in</div></div>
                <div class="stat-card"><div class="stat-title">Your Access</div><div class="stat-number">User</div><div class="muted">Standard account access</div></div>
                <div class="stat-card"><div class="stat-title">Unread Notifications</div><div class="stat-number"><?= $unreadNotifications ?></div><div class="muted">Updates waiting for you</div></div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h3>Quick Actions</h3>
            <p class="muted">Choose what you would like to do next.</p>
            <div class="quick-links">
                <a class="quick-link" href="profile.php">
                    <strong><span class="material-icons">person</span> Update My Profile</strong>
                    <span>Update your personal details and profile information.</span>
                </a>
                <a class="quick-link" href="courses.php">
                    <strong><span class="material-icons">library_books</span> Available Courses</strong>
                    <span>Browse published courses, view requirements, and apply for a course online.</span>
                </a>
                <a class="quick-link" href="my_applications.php">
                    <strong><span class="material-icons">assignment</span> My Applications</strong>
                    <span>View your applications and check whether they are pending, approved, or rejected.</span>
                </a>

                <!-- FEE STRUCTURE CARD -->
                <div class="quick-link fee-card" style="cursor: default;">
                    <strong><span class="material-icons">attach_money</span> Fee Structure</strong>
                    
                    <?php if ($feeStructureExists): ?>
                        <span>Download the current fee structure or view details.</span>
                    <?php else: ?>
                        <span style="color: #b91c1c; font-weight: bold;">Fee structure not yet uploaded.</span>
                    <?php endif; ?>

                    <div class="fee-card-actions">
                        <?php if ($feeStructureExists): ?>
                            <a href="<?= $feeStructurePath ?>" download class="fee-btn-download">
                                <span class="material-icons" style="font-size: 16px;">download</span> Download PDF
                            </a>
                        <?php endif; ?>

                        <?php if ($isAdmin): ?>
                            <a href="upload_fee_structure.php" class="fee-btn-update">
                                <span class="material-icons" style="font-size: 16px;">upload_file</span> Upload / Update Fee
                            </a>
                            
                            <!-- Delete Button (Only shows if a file exists) -->
                            <?php if ($feeStructureExists): ?>
                                <a href="delete_fee.php" onclick="return confirm('Are you sure you want to delete the fee structure? Students will no longer be able to download it.');" class="fee-btn-delete">
                                    <span class="material-icons" style="font-size: 16px;">delete</span> Delete Fee Structure
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- END FEE STRUCTURE CARD -->

                <?php if ($isAdmin): ?>
                    <a class="quick-link" href="users.php">
                        <strong><span class="material-icons">group</span> Registered Users</strong>
                        <span>Manage and review registered user accounts.</span>
                    </a>
                    <a class="quick-link" href="manage_courses.php">
                        <strong><span class="material-icons">settings</span> Manage Courses</strong>
                        <span>Manage course listings and review applications.</span>
                    </a>
                <?php endif; ?>
                <a class="quick-link" href="logout.php">
                    <strong><span class="material-icons">logout</span> Sign Out</strong>
                    <span>Securely end your current session.</span>
                </a>
            </div>
            <div class="account-row">
                <div class="avatar"><?= h(strtoupper(substr(trim($user["full_name"]), 0, 1))) ?></div>
                <div>
                    <strong><?= h($user["full_name"]) ?></strong>
                    <div class="muted"><?= h($user["email"]) ?></div>
                    <div class="muted">Account type: <?= $isAdmin ? "Administrator" : "Student" ?></div>
                </div>
            </div>
        </section>
    </main>

    <nav class="footer-quick-links" aria-label="Quick Links">
        <strong>Quick Links:</strong>
        <a href="dashboard.php">Dashboard</a>
        <a href="profile.php">My Profile</a>
        <a href="courses.php">Available Courses</a>
        <a href="my_applications.php">My Applications</a>
        <?php if ($isAdmin): ?>
            <a href="users.php">Registered Users</a>
            <a href="manage_courses.php">Manage Courses</a>
        <?php endif; ?>
    </nav>

    <footer class="footer">
        <p>&copy; 2026 Emining TTI. All rights reserved.</p>
        <p>Secure account access and personal profile management.</p>
    </footer>
</div>

<script>
(function () {
    // 1. Theme Toggle Logic
    const themeToggle = document.getElementById("themeToggle");
    if (themeToggle) {
        function applyTheme(isDark) {
            document.body.classList.toggle("dark", isDark);
            themeToggle.setAttribute("aria-checked", isDark ? "true" : "false");
            themeToggle.setAttribute("aria-label", isDark ? "Switch to light mode" : "Switch to dark mode");
        }
        applyTheme(false);
        themeToggle.addEventListener("click", function () {
            applyTheme(!document.body.classList.contains("dark"));
        });
    }

    // 2. Mobile Sidebar Slide-out Logic
    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("mobileOverlay");

    function toggleMenu() {
        sidebar.classList.toggle("active");
        overlay.classList.toggle("active");
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener("click", toggleMenu);
    }
    
    if (overlay) {
        overlay.addEventListener("click", toggleMenu);
    }
})();
</script>
</body>
</html>