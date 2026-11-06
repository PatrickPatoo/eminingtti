<?php
session_start();
require_once "connect.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$sql = "SELECT c.*, (SELECT COUNT(*) FROM course_applications a WHERE a.course_id = c.id AND a.user_id = ?) AS already_applied FROM courses c WHERE c.status = 'published' ORDER BY c.created_at DESC";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to load courses. Please try again later.");
}

$userId = (int) $_SESSION["user_id"];
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Courses | Emining TTI Portal</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        :root { color-scheme: light; --page-bg: #f4f6fb; --card-bg: #fff; --text: #202938; --muted: #687386; --border: #e5e7eb; --accent: #d62828; --sidebar-width: 250px; }
        body.dark { color-scheme: dark; --page-bg: #111827; --card-bg: #1f2937; --text: #f3f4f6; --muted: #c0c8d4; --border: #374151; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--page-bg); color: var(--text); transition: background .3s ease, color .3s ease; }

        .overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1005; opacity: 0; transition: opacity 0.3s ease; }
        .overlay.active { display: block; opacity: 1; }

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

        .main { display: flex; flex-direction: column; margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }
        .content { flex: 1; padding: 28px; }
        .topbar { display: flex; flex-direction: row; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 27px; flex-wrap: nowrap; }
        .topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .topbar h1 { margin: 0; font-size: 22px; white-space: nowrap; }
        .muted { color: var(--muted); font-size: 13px; line-height: 1.2; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .top-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .mobile-menu-btn { display: none; background: transparent; border: none; color: var(--text); cursor: pointer; padding: 0; }

        /* Courses Page Specific Styles */
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 25px; }
        .filter-btn { padding: 8px 16px; background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 20px; font-size: 14px; font-weight: bold; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .filter-btn:hover, .filter-btn.active { background: #14532d; color: #ffffff; border-color: #14532d; }

        .course-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 22px; }
        .course-card { background: #ffffff; border-radius: 14px; padding: 24px; box-shadow: 0 8px 25px rgba(15, 23, 42, 0.15); display: flex; flex-direction: column; gap: 13px; }
        
        .course-category { color: #166534; background: #dcfce7; padding: 6px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; align-self: flex-start; text-transform: uppercase; }
        .course-card h2 { font-size: 21px; color: #0f172a; margin-top: 5px; }
        .course-card p { color: #475569; font-size: 14px; line-height: 1.7; overflow-wrap: anywhere; }
        .course-duration { font-weight: bold; color: #334155 !important; }
        .course-requirements { border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 13px; color: #475569; }
        .course-requirements strong { color: #1e293b; }
        
        .course-action { display: inline-block; text-align: center; padding: 12px 16px; background: #14532d; color: #ffffff; border-radius: 7px; text-decoration: none; font-weight: bold; margin-top: auto; transition: background 0.2s ease; }
        .course-action:hover { background: #2563eb; }
        .course-action.applied { background: #64748b; }
        
        .empty-courses { background: #ffffff; border-radius: 12px; padding: 30px; text-align: center; color: #475569; }

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
            .course-grid { grid-template-columns: 1fr; }
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
    <a class="nav-link active" href="courses.php"><span class="material-icons">library_books</span> Available Courses</a>
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
                    <h1>Available Courses</h1>
                    <div class="muted">Explore and apply</div>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn" href="dashboard.php">Dashboard</a>
            </div>
        </header>

        <div class="filter-bar">
            <a href="courses.php" class="filter-btn active">All Courses</a>
            <a href="courses.php?filter=Higher Diploma" class="filter-btn">Higher Diploma</a>
            <a href="courses.php?filter=Diploma" class="filter-btn">Diploma</a>
            <a href="courses.php?filter=Certificate" class="filter-btn">Certificate</a>
            <a href="courses.php?filter=Artisan" class="filter-btn">Artisan</a>
            <a href="courses.php?filter=Short Courses" class="filter-btn">Short Courses</a>
        </div>

        <?php 
        $filter = isset($_GET['filter']) ? $_GET['filter'] : '';
        $hasCourses = false;
        ?>

        <?php if ($result->num_rows > 0): ?>
            <section class="course-grid">
                <?php while ($course = $result->fetch_assoc()): ?>
                    <?php 
                    if (!empty($filter) && strtolower($course['category']) !== strtolower($filter)) {
                        continue;
                    }
                    $hasCourses = true;
                    ?>
                    <article class="course-card">
                        <span class="course-category"><?= e($course["category"]) ?></span>
                        <h2><?= e($course["title"]) ?></h2>
                        <p><?= nl2br(e($course["description"])) ?></p>
                        <p class="course-duration">Duration: <?= e($course["duration"]) ?></p>
                        <?php if (!empty($course["requirements"])): ?>
                            <p class="course-requirements"><strong>Requirements:</strong><br><?= nl2br(e($course["requirements"])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($course["application_deadline"])): ?>
                            <p><strong>Deadline:</strong> <?= e($course["application_deadline"]) ?></p>
                        <?php endif; ?>

                        <?php if ((int) $course["already_applied"] > 0): ?>
                            <a class="course-action applied" href="my_applications.php">Already Applied — View Status</a>
                        <?php else: ?>
                            <a class="course-action" href="apply_course.php?course_id=<?= (int) $course["id"] ?>">Apply for This Course</a>
                        <?php endif; ?>
                    </article>
                <?php endwhile; ?>
            </section>
            
            <?php if (!$hasCourses): ?>
                <div class="empty-courses"><h2>No courses found for this category</h2><p>Try checking "All Courses" or check back later.</p></div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-courses"><h2>No courses available yet</h2><p>Please check again later.</p></div>
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
<?php $stmt->close(); ?>