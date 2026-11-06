<?php
session_start();
require_once "connect.php";
require_once "notification_functions.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

$accountStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$accountStmt->bind_param("i", $userId);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

if (!$account || $account["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrator access is required.");
}

if (empty($_SESSION["manage_courses_csrf"])) {
    $_SESSION["manage_courses_csrf"] = bin2hex(random_bytes(32));
}

function mcEscape($value) {
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["manage_courses_csrf"], $token)) {
        $error = "Your session could not be verified. Refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        /* =========================================
           ADD COURSE
           ========================================= */
        if ($action === "add_course") {
            $title = trim($_POST["title"] ?? "");
            $category = trim($_POST["category"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $duration = trim($_POST["duration"] ?? "");
            $requirements = trim($_POST["requirements"] ?? "");
            $deadlineInput = trim($_POST["application_deadline"] ?? "");
            $status = $_POST["status"] ?? "draft";

            if ($title === "" || $category === "" || $description === "" || $duration === "") {
                $error = "Please complete the title, category, description, and duration.";
            } elseif (strlen($title) > 200 || strlen($category) > 100 || strlen($duration) > 100) {
                $error = "One or more course fields are too long.";
            } elseif (!in_array($status, ["draft", "published", "closed"], true)) {
                $error = "Invalid course status.";
            } else {
                $deadline = null;
                if ($deadlineInput !== "") {
                    $date = DateTime::createFromFormat("Y-m-d", $deadlineInput);
                    if (!$date || $date->format("Y-m-d") !== $deadlineInput) {
                        $error = "Please enter a valid application deadline.";
                    } else {
                        $deadline = $deadlineInput;
                    }
                }

                if ($error === "") {
                    $stmt = $conn->prepare("INSERT INTO courses (title, category, description, duration, requirements, application_deadline, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssss", $title, $category, $description, $duration, $requirements, $deadline, $status);

                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: manage_courses.php?course_added=1");
                        exit;
                    }
                    error_log("Add course error: " . $stmt->error);
                    $stmt->close();
                    $error = "The course could not be saved. Check your database structure.";
                }
            }
        } 
        /* =========================================
           EDIT COURSE
           ========================================= */
        elseif ($action === "edit_course") {
            $courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
            $title = trim($_POST["title"] ?? "");
            $category = trim($_POST["category"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $duration = trim($_POST["duration"] ?? "");
            $requirements = trim($_POST["requirements"] ?? "");
            $deadlineInput = trim($_POST["application_deadline"] ?? "");
            
            if (!$courseId || $title === "" || $category === "" || $description === "" || $duration === "") {
                $error = "Please complete all required fields.";
            } else {
                $deadline = null;
                if ($deadlineInput !== "") {
                    $date = DateTime::createFromFormat("Y-m-d", $deadlineInput);
                    if ($date && $date->format("Y-m-d") === $deadlineInput) {
                        $deadline = $deadlineInput;
                    }
                }

                $stmt = $conn->prepare("UPDATE courses SET title = ?, category = ?, description = ?, duration = ?, requirements = ?, application_deadline = ? WHERE id = ?");
                $stmt->bind_param("ssssssi", $title, $category, $description, $duration, $requirements, $deadline, $courseId);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: manage_courses.php?course_edited=1#courses");
                    exit;
                }
                error_log("Edit course error: " . $stmt->error);
                $stmt->close();
                $error = "The course could not be updated.";
            }
        }
        /* =========================================
           UPDATE COURSE STATUS
           ========================================= */
        elseif ($action === "course_status") {
            $courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
            $newStatus = $_POST["status"] ?? "";

            if (!$courseId || !in_array($newStatus, ["draft", "published", "closed"], true)) {
                $error = "Invalid course or status.";
            } else {
                $stmt = $conn->prepare("UPDATE courses SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $newStatus, $courseId);
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: manage_courses.php?course_updated=1#courses");
                    exit;
                }
                error_log("Update course error: " . $stmt->error);
                $stmt->close();
                $error = "The course status could not be updated.";
            }
        } 
        /* =========================================
           REVIEW APPLICATION
           ========================================= */
        elseif ($action === "review_application") {
            $applicationId = filter_input(INPUT_POST, "application_id", FILTER_VALIDATE_INT);
            $newStatus = $_POST["status"] ?? "";

            if (!$applicationId || !in_array($newStatus, ["pending", "approved", "rejected"], true)) {
                $error = "Invalid application or application status.";
            } else {
                $lookup = $conn->prepare("SELECT ca.user_id, ca.status AS old_status, c.title AS course_title FROM course_applications ca INNER JOIN courses c ON c.id = ca.course_id WHERE ca.id = ? LIMIT 1");
                $lookup->bind_param("i", $applicationId);
                $lookup->execute();
                $application = $lookup->get_result()->fetch_assoc();
                $lookup->close();

                if (!$application) {
                    $error = "The application could not be found.";
                } else {
                    if ($newStatus === "pending") {
                        $update = $conn->prepare("UPDATE course_applications SET status = 'pending', reviewed_at = NULL WHERE id = ?");
                        $update->bind_param("i", $applicationId);
                    } else {
                        $update = $conn->prepare("UPDATE course_applications SET status = ?, reviewed_at = NOW() WHERE id = ?");
                        $update->bind_param("si", $newStatus, $applicationId);
                    }

                    if ($update->execute()) {
                        $update->close();
                        if (in_array($newStatus, ["approved", "rejected"], true) && $newStatus !== $application["old_status"]) {
                            $applicantId = (int) $application["user_id"];
                            $courseTitle = $application["course_title"];
                            $notificationTitle = $newStatus === "approved" ? "Course application approved" : "Course application rejected";
                            $notificationMessage = $newStatus === "approved" ? "Your application for " . $courseTitle . " has been approved." : "Your application for " . $courseTitle . " has been rejected. Please contact the administrator if you need more information.";
                            try {
                                createNotification($conn, $applicantId, $notificationTitle, $notificationMessage, "application", (int) $applicationId);
                            } catch (Throwable $notificationError) {
                                error_log("Applicant notification error: " . $notificationError->getMessage());
                            }
                        }
                        header("Location: manage_courses.php?reviewed=" . urlencode($newStatus) . "#applications");
                        exit;
                    }
                    error_log("Review application error: " . $update->error);
                    $update->close();
                    $error = "The application status could not be updated.";
                }
            }
        } else {
            $error = "Unknown request.";
        }
    }
}

if (isset($_GET["course_added"])) {
    $message = "Course added successfully.";
} elseif (isset($_GET["course_edited"])) {
    $message = "Course details updated successfully.";
} elseif (isset($_GET["course_updated"])) {
    $message = "Course status updated successfully.";
} elseif (isset($_GET["reviewed"])) {
    $reviewed = $_GET["reviewed"];
    if ($reviewed === "approved") { $message = "Application approved. The applicant has been notified."; }
    elseif ($reviewed === "rejected") { $message = "Application rejected. The applicant has been notified."; }
    else { $message = "Application returned to pending status."; }
}

$courses = [];
$coursesResult = $conn->query("SELECT id, title, category, description, duration, requirements, application_deadline, status FROM courses ORDER BY id DESC");
if ($coursesResult) {
    while ($row = $coursesResult->fetch_assoc()) { $courses[] = $row; }
    $coursesResult->free();
}

$applications = [];
$applicationsResult = $conn->query("
    SELECT 
        ca.id, ca.user_id, ca.course_id, ca.phone, ca.education_level, ca.motivation, 
        ca.status, ca.applied_at, ca.reviewed_at, 
        u.full_name, u.email, 
        c.title AS course_title 
    FROM course_applications ca 
    LEFT JOIN users u ON u.id = ca.user_id 
    LEFT JOIN courses c ON c.id = ca.course_id 
    ORDER BY ca.applied_at DESC
");

if ($applicationsResult) {
    while ($row = $applicationsResult->fetch_assoc()) { $applications[] = $row; }
    $applicationsResult->free();
} else {
    $error = "Error loading applications: " . $conn->error;
}

$totalCourses = count($courses);
$totalApplications = count($applications);
$pendingApplications = 0;
$approvedApplications = 0;
$rejectedApplications = 0;

foreach ($applications as $application) {
    if ($application["status"] === "pending") { $pendingApplications++; }
    elseif ($application["status"] === "approved") { $approvedApplications++; }
    elseif ($application["status"] === "rejected") { $rejectedApplications++; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses | Emining TTI Portal</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        :root { color-scheme: light; --page-bg: #f4f6fb; --card-bg: #fff; --text: #202938; --muted: #687386; --border: #e5e7eb; --accent: #d62828; --sidebar-width: 250px; }
        body.dark { color-scheme: dark; --page-bg: #111827; --card-bg: #1f2937; --text: #f3f4f6; --muted: #c0c8d4; --border: #374151; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--page-bg); color: var(--text); transition: background .3s ease, color .3s ease; }

        .overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1005; opacity: 0; transition: opacity 0.3s ease; }
        .overlay.active { display: block; opacity: 1; }

        .sidebar { position: fixed; top: 0; bottom: 0; left: 0; width: var(--sidebar-width); padding: 24px 17px; background:#fbe891; color: #272727; overflow-y: auto; z-index: 1010; transition: transform 0.3s ease; }
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

        /* Manage Courses Specific */
        .panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 22px; margin-bottom: 22px; box-shadow: 0 5px 18px rgba(15, 23, 42, .05); }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat { background: var(--card-bg); padding: 18px; border-radius: 12px; border: 1px solid var(--border); }
        .stat strong { display: block; font-size: 26px; margin-top: 8px; }
        label { display: block; margin: 13px 0 6px; font-weight: 600; }
        input, textarea, select { width: 100%; padding: 11px; border: 1px solid var(--border); border-radius: 7px; font: inherit; background: var(--card-bg); color: var(--text); }
        textarea { min-height: 90px; resize: vertical; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .field-full { grid-column: 1 / -1; }
        .success { padding: 12px; margin-bottom: 18px; background: #dcfce7; color: #166534; border-radius: 8px; }
        .error { padding: 12px; margin-bottom: 18px; background: #fee2e2; color: #991b1b; border-radius: 8px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 780px; }
        th, td { padding: 12px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; line-height: 1.5; }
        th { background: #f8fafc; }
        body.dark th { background: #1e293b; }
        .status { display: inline-block; padding: 4px 8px; border-radius: 20px; background: #e2e8f0; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .inline-form { min-width: 150px; }
        .inline-form select { margin-bottom: 7px; }
        .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        /* Modal Styles */
        .modal { display: none; position: fixed; inset: 0; z-index: 1100; background: rgba(0,0,0,.6); align-items: center; justify-content: center; padding: 20px; }
        .modal.open { display: flex; }
        .modal-box { width: 100%; max-width: 600px; padding: 24px; border-radius: 12px; background: var(--card-bg); color: var(--text); max-height: 90vh; overflow-y: auto; }
        .modal-box h2 { margin-top: 0; font-size: 20px; margin-bottom: 20px; }
        .modal-actions { display: flex; gap: 10px; margin-top: 20px; }

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
            .form-grid { grid-template-columns: 1fr; }
            .field-full { grid-column: auto; }
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
        <a class="nav-link active" href="manage_courses.php"><span class="material-icons">settings</span> Manage Courses</a>
    <?php endif; ?>
    <div class="sidebar-bottom">
        <a class="nav-link" href="../index.html"><span class="material-icons">language</span> Main Website</a>
        <a class="nav-link" href="logout.php"><span class="material-icons">logout</span> Logout</a>
    </div>
</aside>

<div class="main">
<div class="container" style="padding: 28px;">
    <header class="topbar">
        <div class="topbar-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <span class="material-icons">menu</span>
            </button>
            <div>
                <h1>Manage Courses</h1>
                <div class="muted">Review applications</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="btn" href="dashboard.php">Dashboard</a>
        </div>
    </header>

    <?php if ($message !== ""): ?><div class="success"><?= mcEscape($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="error"><?= mcEscape($error) ?></div><?php endif; ?>

    <section class="stats">
        <div class="stat">Total Courses<strong><?= $totalCourses ?></strong></div>
        <div class="stat">Applications<strong><?= $totalApplications ?></strong></div>
        <div class="stat">Pending<strong><?= $pendingApplications ?></strong></div>
        <div class="stat">Approved<strong><?= $approvedApplications ?></strong></div>
        <div class="stat">Rejected<strong><?= $rejectedApplications ?></strong></div>
    </section>

    <section class="panel">
        <h2>Add a Course</h2>
        <form method="post" action="manage_courses.php">
            <input type="hidden" name="csrf_token" value="<?= mcEscape($_SESSION["manage_courses_csrf"]) ?>">
            <input type="hidden" name="action" value="add_course">

            <div class="form-grid">
                <div><label for="title">Course Title *</label><input id="title" name="title" maxlength="200" required></div>
                <div><label for="category">Category *</label><input id="category" name="category" maxlength="100" required></div>
                <div><label for="duration">Duration *</label><input id="duration" name="duration" maxlength="100" placeholder="e.g. 3 months" required></div>
                <div><label for="application_deadline">Application Deadline</label><input id="application_deadline" type="date" name="application_deadline"></div>
                <div class="field-full"><label for="description">Description *</label><textarea id="description" name="description" required></textarea></div>
                <div class="field-full"><label for="requirements">Requirements</label><textarea id="requirements" name="requirements"></textarea></div>
                <div>
                    <label for="status">Initial Status</label>
                    <select id="status" name="status">
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
            </div>
            <p><button class="btn" type="submit">Save Course</button></p>
        </form>
    </section>

    <section class="panel" id="courses">
        <h2>Existing Courses</h2>
        <?php if (!$courses): ?>
            <p class="muted">No courses have been added yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Course</th><th>Category</th><th>Duration</th><th>Deadline</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><strong><?= mcEscape($course["title"]) ?></strong><br><span class="muted"><?= mcEscape($course["description"]) ?></span></td>
                            <td><?= mcEscape($course["category"]) ?></td>
                            <td><?= mcEscape($course["duration"]) ?></td>
                            <td><?= mcEscape($course["application_deadline"] ?: "Not set") ?></td>
                            <td><span class="status"><?= mcEscape($course["status"]) ?></span></td>
                            <td>
                                <div class="actions">
                                    <!-- Edit Button -->
                                    <button type="button" class="btn edit-course-btn" style="background:#2563eb; padding:5px 10px; font-size:11px;"
                                        data-id="<?= (int)$course["id"] ?>"
                                        data-title="<?= mcEscape($course["title"]) ?>"
                                        data-category="<?= mcEscape($course["category"]) ?>"
                                        data-description="<?= mcEscape($course["description"]) ?>"
                                        data-duration="<?= mcEscape($course["duration"]) ?>"
                                        data-requirements="<?= mcEscape($course["requirements"]) ?>"
                                        data-deadline="<?= mcEscape($course["application_deadline"]) ?>"
                                    >Edit</button>

                                    <!-- Status Form -->
                                    <form method="post" class="inline-form" style="display:flex; gap:5px;">
                                        <input type="hidden" name="csrf_token" value="<?= mcEscape($_SESSION["manage_courses_csrf"]) ?>">
                                        <input type="hidden" name="action" value="course_status">
                                        <input type="hidden" name="course_id" value="<?= (int) $course["id"] ?>">
                                        <select name="status" aria-label="Course status" style="padding:5px; font-size:11px;">
                                            <?php foreach (["draft", "published", "closed"] as $status): ?>
                                                <option value="<?= $status ?>" <?= $course["status"] === $status ? "selected" : "" ?>><?= ucfirst($status) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:11px; background:#64748b;">Update</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel" id="applications">
        <h2>Course Applications</h2>
        
        <?php if ($error !== ""): ?>
            <!-- Debugging error is shown at the top -->
        <?php endif; ?>

        <?php if (!$applications): ?>
            <div class="error" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba;">
                <strong>No applications found in the database.</strong><br>
                Please log in as a student, go to "Available Courses", and click "Apply for This Course". Once you submit the form, the application will appear here for you to approve.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Applicant</th><th>Course</th><th>Contact & Education</th><th>Motivation</th><th>Status</th><th>Review Application</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($applications as $application): ?>
                        <tr>
                            <td>
                                <strong><?= mcEscape($application["full_name"] ?? "Deleted User") ?></strong><br>
                                <?= mcEscape($application["email"] ?? "N/A") ?><br>
                                <span class="muted">Applied: <?= mcEscape(date("d M Y, H:i", strtotime($application["applied_at"]))) ?></span>
                            </td>
                            <td><?= mcEscape($application["course_title"] ?? "Deleted Course") ?></td>
                            <td><?= mcEscape($application["phone"]) ?><br><?= mcEscape($application["education_level"]) ?></td>
                            <td><?= nl2br(mcEscape($application["motivation"] ?: "No statement provided.")) ?></td>
                            <td><span class="status status-<?= mcEscape($application["status"]) ?>"><?= mcEscape(ucfirst($application["status"])) ?></span></td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <!-- View Documents Button -->
                                    <a href="view_documents.php?application_id=<?= (int) $application["id"] ?>" 
                                       target="_blank" 
                                       class="btn" 
                                       style="background: #1e73be; padding: 5px 10px; font-size: 11px; text-align: center; display: block;">
                                       View Student ID & Docs
                                    </a>

                                    <!-- Status Form -->
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= mcEscape($_SESSION["manage_courses_csrf"]) ?>">
                                        <input type="hidden" name="action" value="review_application">
                                        <input type="hidden" name="application_id" value="<?= (int) $application["id"] ?>">
                                        <select name="status" aria-label="Application decision">
                                            <?php foreach (["pending", "approved", "rejected"] as $status): ?>
                                                <option value="<?= $status ?>" <?= $application["status"] === $status ? "selected" : "" ?>><?= ucfirst($status) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:11px; margin-top: 5px;">Save Status</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

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

<!-- EDIT COURSE MODAL -->
<div class="modal" id="editCourseModal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <h2>Edit Course</h2>
        <form method="POST" action="manage_courses.php">
            <input type="hidden" name="csrf_token" value="<?= mcEscape($_SESSION["manage_courses_csrf"]) ?>">
            <input type="hidden" name="action" value="edit_course">
            <input type="hidden" name="course_id" id="editCourseId">

            <div class="form-grid">
                <div class="field-full">
                    <label>Course Title *</label>
                    <input type="text" name="title" id="editTitle" required>
                </div>
                <div>
                    <label>Category *</label>
                    <input type="text" name="category" id="editCategory" required>
                </div>
                <div>
                    <label>Duration *</label>
                    <input type="text" name="duration" id="editDuration" required>
                </div>
                <div class="field-full">
                    <label>Application Deadline</label>
                    <input type="date" name="application_deadline" id="editDeadline">
                </div>
                <div class="field-full">
                    <label>Description *</label>
                    <textarea name="description" id="editDescription" required></textarea>
                </div>
                <div class="field-full">
                    <label>Requirements</label>
                    <textarea name="requirements" id="editRequirements"></textarea>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn" type="submit">Save Changes</button>
                <button class="btn" type="button" id="closeEditModal" style="background:#64748b;">Cancel</button>
            </div>
        </form>
    </div>
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

    // Modal Logic for Editing Courses
    const modal = document.getElementById("editCourseModal");
    const editId = document.getElementById("editCourseId");
    const editTitle = document.getElementById("editTitle");
    const editCategory = document.getElementById("editCategory");
    const editDuration = document.getElementById("editDuration");
    const editDeadline = document.getElementById("editDeadline");
    const editDescription = document.getElementById("editDescription");
    const editRequirements = document.getElementById("editRequirements");
    const closeModal = document.getElementById("closeEditModal");

    document.querySelectorAll(".edit-course-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            editId.value = button.dataset.id;
            editTitle.value = button.dataset.title;
            editCategory.value = button.dataset.category;
            editDuration.value = button.dataset.duration;
            editDeadline.value = button.dataset.deadline;
            editDescription.value = button.dataset.description;
            editRequirements.value = button.dataset.requirements;
            
            modal.classList.add("open");
        });
    });

    if (closeModal) {
        closeModal.addEventListener("click", function () {
            modal.classList.remove("open");
        });
    }
})();
</script>
</body>
</html>