<?php
session_start();
require_once "connect.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function validDateOfBirth($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $year = (int) substr($date, 0, 4);
    $month = (int) substr($date, 5, 2);
    $day = (int) substr($date, 8, 2);
    return checkdate($month, $day, $year) && $date <= date("Y-m-d");
}

$educationLevels = [
    "Primary School", "Secondary School", "Certificate", "Diploma",
    "Higher Diploma", "Bachelor's Degree", "Postgraduate Diploma",
    "Master's Degree", "PhD", "Other"
];

$kenyanCounties = [
    "Baringo", "Bomet", "Bungoma", "Busia", "Elgeyo-Marakwet", "Embu", "Garissa", "Homa Bay", "Isiolo", "Kajiado",
    "Kakamega", "Kericho", "Kiambu", "Kilifi", "Kirinyaga", "Kisii", "Kisumu", "Kitui", "Kwale", "Laikipia",
    "Lamu", "Machakos", "Makueni", "Mandera", "Marsabit", "Meru", "Migori", "Mombasa", "Murang'a", "Nairobi",
    "Nakuru", "Nandi", "Narok", "Nyamira", "Nyandarua", "Nyeri", "Samburu", "Siaya", "Taita-Taveta", "Tana River",
    "Tharaka-Nithi", "Trans Nzoia", "Turkana", "Uasin Gishu", "Vihiga", "Wajir", "West Pokot"
];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

$returnCourseId = filter_input(INPUT_GET, "return_course_id", FILTER_VALIDATE_INT);
if (!$returnCourseId || $returnCourseId < 1) {
    $returnCourseId = 0;
}

$stmt = $conn->prepare(
    "SELECT id, full_name, email, role, created_at, phone, date_of_birth, gender, national_id, address, county, profile_photo, education_level, kcse_year, kcse_certificate, national_id_document
     FROM users WHERE id = ? LIMIT 1"
);

if (!$stmt) {
    error_log("Profile SELECT preparation failed: " . $conn->error);
    exit("Unable to load your profile. Please check your database setup.");
}

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

$isAdmin = ($user["role"] ?? "") === "admin";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!is_string($postedToken) || !hash_equals($_SESSION["csrf_token"], $postedToken)) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $fullName = trim($_POST["full_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $dob = trim($_POST["date_of_birth"] ?? "");
        $gender = trim($_POST["gender"] ?? "");
        $nationalId = trim($_POST["national_id"] ?? "");
        $education = trim($_POST["education_level"] ?? "");
        $kcseYear = trim($_POST["kcse_year"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $county = trim($_POST["county"] ?? "");

        $returnCourseId = filter_input(INPUT_POST, "return_course_id", FILTER_VALIDATE_INT);
        if (!$returnCourseId || $returnCourseId < 1) {
            $returnCourseId = 0;
        }

        $photoPath = $user["profile_photo"] ?? "";
        $certificatePath = $user["kcse_certificate"] ?? "";
        $nationalIdDocPath = $user["national_id_document"] ?? "";

        if ($fullName === "" || strlen($fullName) > 150) {
            $error = "Enter your full name (up to 150 characters).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $error = "Enter a valid email address.";
        } elseif ($phone === "" || strlen($phone) > 30) {
            $error = "Enter your phone number (up to 30 characters).";
        } elseif ($dob === "" || !validDateOfBirth($dob)) {
            $error = "Enter a valid date of birth that is not in the future.";
        } elseif ($nationalId === "" || strlen($nationalId) > 50) {
            $error = "Enter your national ID number (up to 50 characters).";
        } elseif (!in_array($education, $educationLevels, true)) {
            $error = "Select a valid education level.";
        } elseif ($kcseYear === "" || !is_numeric($kcseYear) || $kcseYear < 1980 || $kcseYear > date("Y")) {
            $error = "Enter a valid KCSE year (e.g., 2023).";
        } elseif ($county === "" || !in_array($county, $kenyanCounties, true)) {
            $error = "Please select a valid county.";
        } elseif (strlen($gender) > 30 || strlen($address) > 255) {
            $error = "One or more optional profile fields are too long.";
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            if (!$check) {
                $error = "Unable to validate the email address.";
            } else {
                $check->bind_param("si", $email, $userId);
                $check->execute();
                $emailExists = $check->get_result()->num_rows > 0;
                $check->close();
                if ($emailExists) {
                    $error = "That email address is already registered.";
                }
            }
        }

        if ($error === "" && isset($_FILES["profile_photo"]) && $_FILES["profile_photo"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES["profile_photo"];
            if ($file["error"] !== UPLOAD_ERR_OK) {
                $error = "The photo upload failed. Please try again.";
            } elseif ($file["size"] > 2 * 1024 * 1024) {
                $error = "Your profile photo must be 2 MB or smaller.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file["tmp_name"]);
                $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

                if (!isset($allowed[$mime]) || @getimagesize($file["tmp_name"]) === false) {
                    $error = "Use a valid JPG, PNG, or WEBP image for your photo.";
                } else {
                    $uploadDir = __DIR__ . "/../assets/uploads/profiles/";
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        $error = "Unable to create the profile photo folder.";
                    } else {
                        $newFilename = "profile_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
                        if (!move_uploaded_file($file["tmp_name"], $uploadDir . $newFilename)) {
                            $error = "Unable to save the profile photo.";
                        } else {
                            $photoPath = "assets/uploads/profiles/" . $newFilename;
                        }
                    }
                }
            }
        }

        $hasCertificate = !empty($user["kcse_certificate"]);
        if ($error === "" && isset($_FILES["kcse_certificate"]) && $_FILES["kcse_certificate"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES["kcse_certificate"];
            if ($file["error"] !== UPLOAD_ERR_OK) {
                $error = "The certificate upload failed. Please try again.";
            } elseif ($file["size"] > 5 * 1024 * 1024) {
                $error = "Your certificate must be 5 MB or smaller.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file["tmp_name"]);
                $allowed = [
                    "image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp", "application/pdf" => "pdf"
                ];

                if (!isset($allowed[$mime])) {
                    $error = "Use a valid JPG, PNG, WEBP, or PDF for your certificate.";
                } else {
                    $uploadDir = __DIR__ . "/../assets/uploads/certificates/";
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        $error = "Unable to create the certificate folder.";
                    } else {
                        $newFilename = "cert_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
                        if (!move_uploaded_file($file["tmp_name"], $uploadDir . $newFilename)) {
                            $error = "Unable to save the certificate.";
                        } else {
                            $certificatePath = "assets/uploads/certificates/" . $newFilename;
                            $hasCertificate = true;
                        }
                    }
                }
            }
        }

        $hasNationalIdDoc = !empty($user["national_id_document"]);
        if ($error === "" && isset($_FILES["national_id_document"]) && $_FILES["national_id_document"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES["national_id_document"];
            if ($file["error"] !== UPLOAD_ERR_OK) {
                $error = "The National ID upload failed. Please try again.";
            } elseif ($file["size"] > 5 * 1024 * 1024) {
                $error = "Your National ID document must be 5 MB or smaller.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file["tmp_name"]);
                $allowed = [
                    "image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp", "application/pdf" => "pdf"
                ];

                if (!isset($allowed[$mime])) {
                    $error = "Use a valid JPG, PNG, WEBP, or PDF for your National ID.";
                } else {
                    $uploadDir = __DIR__ . "/../assets/uploads/national_ids/";
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        $error = "Unable to create the National ID folder.";
                    } else {
                        $newFilename = "nid_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
                        if (!move_uploaded_file($file["tmp_name"], $uploadDir . $newFilename)) {
                            $error = "Unable to save the National ID document.";
                        } else {
                            $nationalIdDocPath = "assets/uploads/national_ids/" . $newFilename;
                            $hasNationalIdDoc = true;
                        }
                    }
                }
            }
        }

        if ($error === "" && !$hasCertificate) {
            $error = "Please upload your KCSE certificate.";
        }
        if ($error === "" && !$hasNationalIdDoc) {
            $error = "Please upload a copy of your National ID.";
        }

        if ($error === "") {
            $update = $conn->prepare(
                "UPDATE users SET full_name = ?, email = ?, phone = ?, date_of_birth = ?, gender = ?, national_id = ?, address = ?, county = ?, education_level = ?, kcse_year = ?, profile_photo = ?, kcse_certificate = ?, national_id_document = ? WHERE id = ?"
            );

            if (!$update) {
                error_log("Profile UPDATE preparation failed: " . $conn->error);
                $error = "Unable to prepare your profile update.";
            } else {
                $update->bind_param("sssssssssssssi", $fullName, $email, $phone, $dob, $gender, $nationalId, $address, $county, $education, $kcseYear, $photoPath, $certificatePath, $nationalIdDocPath, $userId);

                if ($update->execute()) {
                    $_SESSION["full_name"] = $fullName;
                    $_SESSION["email"] = $email;
                    $message = "Your profile has been updated successfully.";

                    $stmt = $conn->prepare("SELECT id, full_name, email, role, created_at, phone, date_of_birth, gender, national_id, address, county, profile_photo, education_level, kcse_year, kcse_certificate, national_id_document FROM users WHERE id = ? LIMIT 1");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $user = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $isAdmin = ($user["role"] ?? "") === "admin";

                    if ($returnCourseId > 0) {
                        header("Location: apply_course.php?course_id=" . $returnCourseId . "&profile_updated=1");
                        exit;
                    }
                } else {
                    error_log("Profile UPDATE failed: " . $update->error);
                    $error = "Unable to save your profile changes.";
                }
                $update->close();
            }
        }
    }
}

$initial = strtoupper(substr(trim($user["full_name"]), 0, 1));
$photo = $user["profile_photo"] ?? "";

$requiredFields = [
    "full_name" => "Full name", "email" => "Email address", "phone" => "Phone number",
    "date_of_birth" => "Date of birth", "national_id" => "National ID Number", 
    "education_level" => "Education level", "kcse_year" => "KCSE Year", 
    "county" => "County", "kcse_certificate" => "KCSE Certificate",
    "national_id_document" => "National ID Document"
];

$missingFields = [];
foreach ($requiredFields as $key => $label) {
    if (trim((string)($user[$key] ?? "")) === "") {
        $missingFields[] = $label;
    }
}
if (!empty($user["date_of_birth"]) && !validDateOfBirth($user["date_of_birth"])) {
    $missingFields[] = "Valid date of birth";
}
$profileComplete = empty($missingFields);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile | Emining TTI Portal</title>
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

.btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 15px; background: var(--accent); border: 0; border-radius: 8px; color: white; text-decoration: none; font-size: 12px; font-weight: bold; cursor: pointer; white-space: nowrap; }
.panel { padding: 24px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 13px; box-shadow: 0 5px 22px rgba(0,0,0,.04); margin-bottom: 22px; }
.panel h2 { font-size: 18px; margin: 0 0 7px; }
.profile-heading { display: flex; align-items: center; gap: 18px; margin-bottom: 23px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
.avatar { width: 90px; height: 90px; flex-shrink: 0; display: grid; place-items: center; overflow: hidden; border-radius: 50%; background: #fee2e2; color: #991b1b; font-size: 30px; font-weight: bold; }
.avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-heading h2 { margin: 0 0 7px; font-size: 20px; }
.profile-heading p { margin: 0 0 4px; color: var(--muted); font-size: 13px; }
.form-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 17px; }
.field label { display: block; margin-bottom: 7px; font-size: 13px; font-weight: bold; }
.field input, .field select, .field textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 7px; outline: none; background: var(--card-bg); color: var(--text); font: inherit; font-size: 13px; }
.field input:focus, .field select:focus, .field textarea:focus { border-color: #d62828; box-shadow: 0 0 0 3px rgba(214,40,40,.09); }
.field textarea { min-height: 80px; resize: vertical; }
.full-width { grid-column: 1 / -1; }
.help { display: block; margin-top: 6px; color: var(--muted); font-size: 11px; }
.notice { padding: 13px 15px; border-radius: 7px; margin-bottom: 17px; font-size: 13px; line-height: 1.6; }
.success { background: #dcfce7; color: #166534; }
.error { background: #fee2e2; color: #991b1b; }
.warning { background: #fff7d6; color: #854d0e; border: 1px solid #fde68a; }
.account-info { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 15px; }
.info-item { padding: 15px; background: var(--page-bg); border-radius: 8px; overflow-wrap: anywhere; }
.info-item span { display: block; color: var(--muted); font-size: 11px; margin-bottom: 7px; }
.info-item strong { font-size: 13px; }
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
    .account-info { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .full-width { grid-column: auto; }
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
    <a class="nav-link active" href="profile.php"><span class="material-icons">person</span> My Profile</a>
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
    <header class="topbar">
        <div class="topbar-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <span class="material-icons">menu</span>
            </button>
            <div>
                <h1>My Profile</h1>
                <div class="muted">Manage your personal details</div>
            </div>
        </div>
        <div class="top-actions">
            <a class="btn" href="dashboard.php">Dashboard</a>
        </div>
    </header>

    <?php if (isset($_GET["complete_profile"])): ?>
        <div class="notice warning">
            <strong>Complete your profile before applying.</strong><br>
            Fill in all required fields below and save your changes.
            <?php if (!$profileComplete): ?>
                <br>Still required: <?= h(implode(", ", $missingFields)) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($message !== ""): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <section class="panel">
        <div class="profile-heading">
            <div class="avatar">
                <?php if ($photo !== ""): ?>
                    <img src="../<?= h($photo) ?>" alt="Profile photo">
                <?php else: ?>
                    <?= h($initial) ?>
                <?php endif; ?>
            </div>
            <div>
                <h2><?= h($user["full_name"]) ?></h2>
                <p><?= h($user["email"]) ?></p>
                <p><?= $isAdmin ? "Administrator account" : "Registered user account" ?></p>
            </div>
        </div>

        <h2>Personal Information</h2>
        <p class="muted">Fields marked with * are required.</p>

        <form method="POST" action="profile.php<?= $returnCourseId > 0 ? '?return_course_id=' . (int)$returnCourseId : '' ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="return_course_id" value="<?= (int)$returnCourseId ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="full_name">Full Name *</label>
                    <input id="full_name" type="text" name="full_name" maxlength="150" required value="<?= h($user["full_name"]) ?>">
                </div>
                <div class="field">
                    <label for="email">Email Address *</label>
                    <input id="email" type="email" name="email" maxlength="190" required value="<?= h($user["email"]) ?>">
                </div>
                <div class="field">
                    <label for="phone">Phone Number *</label>
                    <input id="phone" type="tel" name="phone" maxlength="30" autocomplete="tel" required value="<?= h($user["phone"]) ?>" placeholder="e.g. 0712345678">
                </div>
                <div class="field">
                    <label for="date_of_birth">Date of Birth *</label>
                    <input id="date_of_birth" type="date" name="date_of_birth" max="<?= date("Y-m-d") ?>" required value="<?= h($user["date_of_birth"]) ?>">
                </div>
                <div class="field">
                    <label for="national_id">National ID Number *</label>
                    <input id="national_id" type="text" name="national_id" maxlength="50" required value="<?= h($user["national_id"]) ?>">
                </div>
                <div class="field">
                    <label for="education_level">Education Level *</label>
                    <select id="education_level" name="education_level" required>
                        <option value="">Select your education level</option>
                        <?php foreach ($educationLevels as $level): ?>
                            <option value="<?= h($level) ?>" <?= ($user["education_level"] ?? "") === $level ? "selected" : "" ?>><?= h($level) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="kcse_year">KCSE Year *</label>
                    <input id="kcse_year" type="number" name="kcse_year" min="1980" max="<?= date('Y') ?>" required value="<?= h($user["kcse_year"]) ?>" placeholder="e.g. 2023">
                </div>
                <div class="field">
                    <label for="county">County *</label>
                    <select id="county" name="county" required>
                        <option value="">Select your county</option>
                        <?php foreach ($kenyanCounties as $c): ?>
                            <option value="<?= h($c) ?>" <?= ($user["county"] ?? "") === $c ? "selected" : "" ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="gender">Gender (Optional)</label>
                    <select id="gender" name="gender">
                        <option value="">Select gender</option>
                        <?php foreach (["Male", "Female", "Prefer not to say"] as $option): ?>
                            <option value="<?= h($option) ?>" <?= ($user["gender"] ?? "") === $option ? "selected" : "" ?>><?= h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field full-width">
                    <label for="address">Address (Optional)</label>
                    <textarea id="address" name="address" maxlength="255"><?= h($user["address"]) ?></textarea>
                </div>
                <div class="field full-width">
                    <label for="profile_photo">Profile Photo (Optional)</label>
                    <input id="profile_photo" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <span class="help">JPG, PNG, or WEBP. Maximum size: 2 MB.</span>
                </div>
                <div class="field full-width">
                    <label for="kcse_certificate">KCSE Certificate Upload *</label>
                    <input id="kcse_certificate" type="file" name="kcse_certificate" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" <?= empty($user["kcse_certificate"]) ? 'required' : '' ?>>
                    <span class="help">JPG, PNG, WEBP, or PDF. Maximum size: 5 MB. <?= !empty($user["kcse_certificate"]) ? ' (Already uploaded, upload a new one to replace)' : '' ?></span>
                </div>
                <div class="field full-width">
                    <label for="national_id_document">National ID Upload *</label>
                    <input id="national_id_document" type="file" name="national_id_document" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" <?= empty($user["national_id_document"]) ? 'required' : '' ?>>
                    <span class="help">JPG, PNG, WEBP, or PDF. Maximum size: 5 MB. <?= !empty($user["national_id_document"]) ? ' (Already uploaded, upload a new one to replace)' : '' ?></span>
                </div>
            </div>

            <div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn">UPDATE</button>
                <a href="dashboard.php" class="btn" style="background:var(--card-bg);color:var(--text);border:1px solid var(--border);">Cancel</a>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Account Information</h2>
        <p class="muted">Your system account details.</p>
        <div class="account-info">
            <div class="info-item"><span>Account ID</span><strong><?= (int)$user["id"] ?></strong></div>
            <div class="info-item"><span>Account Role</span><strong><?= $isAdmin ? "Administrator" : "Registered User" ?></strong></div>
            <div class="info-item"><span>Registration Date</span><strong><?= h($user["created_at"]) ?></strong></div>
        </div>
    </section>
</main>

<nav class="footer-quick-links" aria-label="Quick Links">
    <strong>Quick Links:</strong>
    <a href="dashboard.php">Dashboard</a>
    <a href="profile.php">My Profile</a>
    <a href="courses.php">Available Courses</a>
    <a href="my_applications.php">My Applications</a>
    <?php if ($isAdmin): ?><a href="users.php">Registered Users</a><?php endif; ?>
</nav>

<footer class="footer">
    <p>&copy; 2026 Emining TTI. All rights reserved.</p>
    <p>Secure account access and personal profile management.</p>
</footer>
</div>

<script>
(function () {
    const themeToggle = document.getElementById("themeToggle");
    if (themeToggle) {
        function applyTheme(isDark) {
            document.body.classList.toggle("dark", isDark);
            themeToggle.setAttribute("aria-checked", isDark ? "true" : "false");
        }
        applyTheme(false);
        themeToggle.addEventListener("click", function () {
            applyTheme(!document.body.classList.contains("dark"));
        });
    }

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