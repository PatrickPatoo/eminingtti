<?php
session_start();
require_once "connect.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

if (empty($_SESSION["notification_csrf"])) {
    $_SESSION["notification_csrf"] = bin2hex(random_bytes(32));
}

function notificationEscape($value): string {
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    $savedToken = $_SESSION["notification_csrf"] ?? "";

    if (!is_string($submittedToken) || !is_string($savedToken) || !hash_equals($savedToken, $submittedToken)) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "mark_all") {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                if (!$stmt->execute()) { $error = "Could not update notifications."; }
                $stmt->close();
            } else {
                $error = "Could not update notifications.";
            }
        } elseif ($action === "mark_one") {
            $notificationId = filter_input(INPUT_POST, "notification_id", FILTER_VALIDATE_INT);
            if (!$notificationId || $notificationId < 1) {
                $error = "Invalid notification.";
            } else {
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                if ($stmt) {
                    $stmt->bind_param("ii", $notificationId, $userId);
                    if (!$stmt->execute()) { $error = "Could not update this notification."; }
                    $stmt->close();
                } else {
                    $error = "Could not update this notification.";
                }
            }
        }

        if ($error === "") {
            header("Location: notifications.php");
            exit;
        }
    }
}

$unreadCount = 0;
$countStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
if ($countStmt) {
    $countStmt->bind_param("i", $userId);
    $countStmt->execute();
    $countStmt->bind_result($unreadCount);
    $countStmt->fetch();
    $countStmt->close();
}

$notifications = [];
$listStmt = $conn->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC");
if ($listStmt) {
    $listStmt->bind_param("i", $userId);
    $listStmt->execute();
    $listStmt->bind_result($notificationId, $title, $message, $type, $isRead, $createdAt);
    while ($listStmt->fetch()) {
        $notifications[] = [
            "id" => $notificationId, "title" => $title, "message" => $message,
            "type" => $type, "is_read" => $isRead, "created_at" => $createdAt
        ];
    }
    $listStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Emining TTI Portal</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        :root { color-scheme: light; --page-bg: #f4f6fb; --card-bg: #fff; --text: #202938; --muted: #687386; --border: #e5e7eb; --accent: #d62828; --sidebar-width: 250px; }
        body.dark { color-scheme: dark; --page-bg: #111827; --card-bg: #1f2937; --text: #f3f4f6; --muted: #c0c8d4; --border: #374151; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--page-bg); color: var(--text); transition: background .3s ease, color .3s ease; }

        .overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1005; opacity: 0; transition: opacity 0.3s ease; }
        .overlay.active { display: block; opacity: 1; }

        .sidebar { position: fixed; top: 0; bottom: 0; left: 0; width: var(--sidebar-width); padding: 24px 17px; background: #fff2b8; color: #272727; overflow-y: auto; z-index: 1010; transition: transform 0.3s ease; }
        .brand { text-align: center; padding-bottom: 22px; border-bottom: 1px solid rgba(0,0,0,.13); }
        .brand img { display: block; width: 100%; max-width: 130px; height: auto; max-height: 90px; object-fit: contain; margin: 0 auto 15px; background-color: #ffffff; padding: 8px; border-radius: 10px; box-shadow: 0 3px 8px rgba(0,0,0,0.1); }
        .brand p { margin: 0; color: #5c5540; font-size: 13px; font-weight: bold; }
        .menu-label { margin: 25px 12px 10px; color: #766a43; font-size: 11px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .nav-link { display: flex; align-items: center; gap: 11px; padding: 13px 14px; margin: 5px 0; border-radius: 9px; color: #272727; text-decoration: none; font-size: 14px; font-weight: 600; transition: background .2s, color .2s; }
        .nav-link:hover, .nav-link.active { background: #fff; color: #b91c1c; }
        .nav-link .material-icons { font-size: 20px; color: #766a43; transition: color 0.2s; }
        .nav-link:hover .material-icons, .nav-link.active .material-icons { color: #b91c1c; }
        .sidebar-bottom { margin-top: 25px; padding-top: 15px; border-top: 1px solid rgba(0,0,0,.13); }

        .main { display: flex; flex-direction: column; margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }
        .content { flex: 1; padding: 28px; }
        .topbar { display: flex; flex-direction: row; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 27px; flex-wrap: nowrap; }
        .topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .topbar h1 { margin: 0; font-size: 22px; white-space: nowrap; }
        .muted { color: var(--muted); font-size: 13px; line-height: 1.2; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .top-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .mobile-menu-btn { display: none; background: transparent; border: none; color: var(--text); cursor: pointer; padding: 0; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 15px; background: var(--accent); border: 0; border-radius: 8px; color: white; text-decoration: none; font-size: 12px; font-weight: bold; cursor: pointer; white-space: nowrap; }

        /* Notifications Specific */
        .summary, .notification { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 14px; box-shadow: 0 5px 18px rgba(0, 0, 0, .04); }
        .summary { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .count { display: inline-block; padding: 5px 10px; border-radius: 20px; background: #fee2e2; color: #991b1b; font-size: 13px; font-weight: bold; }
        .notification { border-left: 5px solid #d1d5db; }
        .notification.unread { border-left-color: #2563eb; background: #eff6ff; }
        body.dark .notification.unread { background: #1e293b; }
        .notification h2 { margin: 0 0 9px; font-size: 18px; }
        .notification p { margin: 0 0 12px; line-height: 1.6; overflow-wrap: anywhere; }
        .notification small { display: block; color: var(--muted); margin-bottom: 13px; }
        .empty, .error { background: var(--card-bg); padding: 25px; border-radius: 12px; text-align: center; border: 1px solid var(--border); }
        .error { color: #991b1b; background: #fef2f2; margin-bottom: 15px; }
        .inline-form { display: inline-block; margin: 0; }

        .footer-quick-links { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 20px; padding: 22px 18px; background: var(--card-bg); border-top: 1px solid var(--border); }
        .footer-quick-links strong { color: var(--text); font-size: 14px; }
        .footer-quick-links a { color: #dc2626; text-decoration: none; font-size: 14px; font-weight: 600; }
        .footer-quick-links a:hover { text-decoration: underline; }
        .footer { flex-shrink: 0; padding: 22px 28px; background: #343a40 !important; border-top: 1px solid #4b5157; text-align: center; color: #fff !important; font-size: 13px; line-height: 1.8; }
        .footer p { margin: 4px 0; color: #fff !important; }

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
        }
        @media (max-width: 600px) {
            .footer-quick-links { gap: 14px; padding: 18px 10px; flex-direction: column; text-align: center; }
            .footer-quick-links strong { width: 100%; text-align: center; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
<div class="overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/logo.png" alt="Emining TTI Logo">
        <p>Account Management Portal</p>
    </div>
    <div class="menu-label">Main Menu</div>
    <a class="nav-link" href="dashboard.php"><span class="material-icons">dashboard</span> Dashboard</a>
    <a class="nav-link" href="profile.php"><span class="material-icons">person</span> My Profile</a>
    <a class="nav-link" href="courses.php"><span class="material-icons">library_books</span> Available Courses</a>
    <a class="nav-link" href="my_applications.php"><span class="material-icons">assignment</span> My Applications</a>
    <?php if (isset($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
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
    <header class="topbar">
        <div class="topbar-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <span class="material-icons">menu</span>
            </button>
            <div>
                <h1>Notifications</h1>
                <div class="muted">Updates about your account</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="btn" href="dashboard.php">Dashboard</a>
        </div>
    </header>

    <?php if ($error !== ""): ?><div class="error" role="alert"><?= notificationEscape($error) ?></div><?php endif; ?>

    <section class="summary">
        <div>
            <strong>Your notifications</strong>
            <div class="muted"><?= count($notifications) ?> notification(s) saved</div>
        </div>
        <div class="summary-actions">
            <span class="count"><?= (int) $unreadCount ?> unread</span>
            <?php if ((int) $unreadCount > 0): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= notificationEscape($_SESSION["notification_csrf"]) ?>">
                    <input type="hidden" name="action" value="mark_all">
                    <button class="btn" type="submit">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if (empty($notifications)): ?>
        <div class="empty"><h2>You're all caught up!</h2><p class="muted">New notifications will appear here.</p></div>
    <?php else: ?>
        <?php foreach ($notifications as $notification): ?>
            <article class="notification <?= (int) $notification["is_read"] === 0 ? "unread" : "" ?>">
                <h2><?= notificationEscape($notification["title"]) ?><?php if ((int) $notification["is_read"] === 0): ?><span class="count" style="margin-left:10px; font-size:10px;">New</span><?php endif; ?></h2>
                <p><?= nl2br(notificationEscape($notification["message"])) ?></p>
                <small><?= notificationEscape($notification["created_at"]) ?></small>
                <?php if ((int) $notification["is_read"] === 0): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= notificationEscape($_SESSION["notification_csrf"]) ?>">
                        <input type="hidden" name="action" value="mark_one">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification["id"] ?>">
                        <button class="btn" type="submit" style="padding: 6px 12px; font-size: 11px;">Mark as read</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<nav class="footer-quick-links" aria-label="Quick Links">
    <strong>Quick Links:</strong>
    <a href="dashboard.php">Dashboard</a>
    <a href="profile.php">My Profile</a>
    <a href="courses.php">Available Courses</a>
    <a href="my_applications.php">My Applications</a>
    <?php if (isset($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
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
    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("mobileOverlay");

    if (mobileMenuBtn) mobileMenuBtn.addEventListener("click", toggleMenu);
    if (overlay) overlay.addEventListener("click", toggleMenu);

    function toggleMenu() {
        sidebar.classList.toggle("active");
        overlay.classList.toggle("active");
    }
})();
</script>
</body>
</html>