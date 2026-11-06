<?php
session_start();
require_once "connect.php";
require_once "notification_functions.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function validApplicationDateOfBirth($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $year = (int)substr($date, 0, 4);
    $month = (int)substr($date, 5, 2);
    $day = (int)substr($date, 8, 2);
    return checkdate($month, $day, $year) && $date <= date("Y-m-d");
}

$userId = (int)$_SESSION["user_id"];
$courseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
}

if (!$courseId || $courseId < 1) {
    http_response_code(400);
    exit("Invalid course selected. Please return to the courses page.");
}

$profileStmt = $conn->prepare("SELECT full_name, email, phone, date_of_birth, national_id, education_level FROM users WHERE id = ? LIMIT 1");
$profileStmt->bind_param("i", $userId);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

if (!$profile) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$requiredProfileFields = [
    "full_name" => "Full name", "email" => "Email address", "phone" => "Phone number",
    "date_of_birth" => "Date of birth", "national_id" => "National ID / identification number", "education_level" => "Education level"
];

$missingProfileFields = [];
foreach ($requiredProfileFields as $field => $label) {
    if (trim((string)($profile[$field] ?? "")) === "") {
        $missingProfileFields[] = $label;
    }
}

if (!filter_var($profile["email"] ?? "", FILTER_VALIDATE_EMAIL)) {
    $missingProfileFields[] = "Valid email address";
}
if (!empty($profile["date_of_birth"]) && !validApplicationDateOfBirth($profile["date_of_birth"])) {
    $missingProfileFields[] = "Valid date of birth";
}

$allowedEducationLevels = ["Primary School", "Secondary School", "Certificate", "Diploma", "Higher Diploma", "Bachelor's Degree", "Postgraduate Diploma", "Master's Degree", "PhD", "Other"];

if (!empty($profile["education_level"]) && !in_array($profile["education_level"], $allowedEducationLevels, true)) {
    $missingProfileFields[] = "Valid education level";
}

if (!empty($missingProfileFields)) {
    header("Location: profile.php?complete_profile=1&return_course_id=" . $courseId);
    exit;
}

$phone = $profile["phone"];
$education = $profile["education_level"];
$motivation = "";
$error = "";

if (empty($_SESSION["course_application_csrf"])) {
    $_SESSION["course_application_csrf"] = bin2hex(random_bytes(32));
}

$stmt = $conn->prepare("SELECT id, title, category, description, duration, requirements, application_deadline FROM courses WHERE id = ? AND status = 'published' AND (application_deadline IS NULL OR application_deadline >= CURDATE()) LIMIT 1");
$stmt->bind_param("i", $courseId);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$course) {
    http_response_code(404);
    exit("This course is unavailable or its application deadline has passed.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $phone = trim($_POST["phone"] ?? "");
    $education = trim($_POST["education_level"] ?? "");
    $motivation = trim($_POST["motivation"] ?? "");
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["course_application_csrf"], $csrfToken)) {
        $error = "Your session could not be verified. Please refresh and try again.";
    } elseif ($phone === "" || $education === "") {
        $error = "Please enter your phone number and education level.";
    } elseif (strlen($phone) > 30) {
        $error = "Your phone number is too long.";
    } elseif (strlen($education) > 150 || !in_array($education, $allowedEducationLevels, true)) {
        $error = "Please select a valid education level.";
    } elseif (strlen($motivation) > 5000) {
        $error = "Your motivation statement is too long.";
    } else {
        $check = $conn->prepare("SELECT id FROM courses WHERE id = ? AND status = 'published' AND (application_deadline IS NULL OR application_deadline >= CURDATE()) LIMIT 1");
        $check->bind_param("i", $courseId);
        $check->execute();
        $stillOpen = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$stillOpen) {
            $error = "Applications for this course are now closed.";
        } else {
            try {
                $insert = $conn->prepare("INSERT INTO course_applications (user_id, course_id, phone, education_level, motivation) VALUES (?, ?, ?, ?, ?)");
                $insert->bind_param("iisss", $userId, $courseId, $phone, $education, $motivation);
                $insert->execute();
                $applicationId = (int)$conn->insert_id;
                $insert->close();

                $nameStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
                $applicantName = $profile["full_name"];
                if ($nameStmt) {
                    $nameStmt->bind_param("i", $userId);
                    $nameStmt->execute();
                    $applicant = $nameStmt->get_result()->fetch_assoc();
                    $nameStmt->close();
                    if (!empty($applicant["full_name"])) {
                        $applicantName = $applicant["full_name"];
                    }
                }

                try {
                    notifyAdministrators($conn, $applicantName, $course["title"], $applicationId);
                } catch (Throwable $notificationError) {
                    error_log("Admin notification error: " . $notificationError->getMessage());
                }

                unset($_SESSION["course_application_csrf"]);
                header("Location: my_applications.php?applied=1");
                exit;

            } catch (mysqli_sql_exception $exception) {
                if ((int)$exception->getCode() === 1062) {
                    $error = "You have already applied for this course.";
                } else {
                    error_log("Course application error: " . $exception->getMessage());
                    $error = "We could not submit your application. Please try again.";
                }
            } catch (Throwable $exception) {
                error_log("Course application error: " . $exception->getMessage());
                $error = "We could not submit your application. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Course | Emining TTI Portal</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .application-page { min-height: 100vh; padding: 35px 15px; }
        .application-card { max-width: 700px; margin: 0 auto; padding: 30px; background: #fff; border-radius: 14px; box-shadow: 0 10px 35px rgba(15, 23, 42, .15); }
        .application-card h1 { color: #0f172a; font-size: 27px; margin-bottom: 10px; }
        .course-summary { background: #f1f5f9; padding: 16px; border-radius: 8px; margin: 20px 0; line-height: 1.7; color: #334155; }
        .application-card label { display: block; margin: 18px 0 8px; font-weight: 600; color: #334155; }
        .application-card input, .application-card select, .application-card textarea { display: block; width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 7px; font: inherit; background: #fff; color: #0f172a; }
        .application-card textarea { min-height: 120px; resize: vertical; }
        .application-card button { margin-top: 22px; background: #14532d; color: #fff; border: 0; border-radius: 7px; padding: 12px 18px; cursor: pointer; font-weight: 700; }
        .application-card button:hover { background: #2563eb; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #fff; text-decoration: none; font-weight: 600; }
        .application-error { background: #fff1f2; border: 1px solid #fecdd3; color: #b91c1c; padding: 12px; border-radius: 7px; margin: 15px 0; line-height: 1.5; }
        .application-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px; border-radius: 7px; margin: 15px 0; line-height: 1.5; }
        .help-text { margin-top: 7px; color: #64748b; font-size: 13px; line-height: 1.5; }
        @media (max-width: 480px) { .application-card { padding: 22px 18px; } }
    </style>
</head>
<body>
<main class="application-page">
    <div style="max-width:700px;margin:0 auto;">
        <a class="back-link" href="courses.php">&larr; Back to Courses</a>
    </div>

    <section class="application-card">
        <h1>Course Application</h1>
        <p class="subtitle">Complete the form below to apply.</p>

        <?php if (isset($_GET["profile_updated"])): ?>
            <div class="application-success" role="status">Your profile has been saved. You can now continue with your course application.</div>
        <?php endif; ?>

        <div class="course-summary">
            <strong><?= e($course["title"]) ?></strong><br>
            Category: <?= e($course["category"]) ?><br>
            Duration: <?= e($course["duration"]) ?>
            <?php if (!empty($course["application_deadline"])): ?>
                <br>Application deadline: <?= e($course["application_deadline"]) ?>
            <?php endif; ?>
            <p style="margin-top:10px;"><?= nl2br(e($course["description"])) ?></p>
            <?php if (!empty($course["requirements"])): ?>
                <p><strong>Course requirements:</strong><br><?= nl2br(e($course["requirements"])) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($error !== ""): ?>
            <div class="application-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="apply_course.php?course_id=<?= (int)$courseId ?>">
            <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION["course_application_csrf"]) ?>">

            <label for="phone">Phone Number *</label>
            <input type="tel" id="phone" name="phone" maxlength="30" autocomplete="tel" value="<?= e($phone) ?>" placeholder="e.g. 0712345678" required>

            <label for="education_level">Highest Education Level *</label>
            <select id="education_level" name="education_level" required>
                <option value="">Select your education level</option>
                <?php foreach ($allowedEducationLevels as $level): ?>
                    <option value="<?= e($level) ?>" <?= $education === $level ? "selected" : "" ?>><?= e($level) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="motivation">Why do you want to take this course?</label>
            <textarea id="motivation" name="motivation" maxlength="5000" placeholder="Tell us about your interests and goals..."><?= e($motivation) ?></textarea>

            <p class="help-text">Your phone number and education level are pre-filled from your profile. You can adjust them for this application. Your profile must remain complete to submit.</p>

            <button type="submit">Submit Application</button>
        </form>
    </section>
</main>
</body>
</html>