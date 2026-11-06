<?php
session_start();
require_once "connect.php";

// 1. Check if user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// 2. Check if user is an Admin
$userId = (int) $_SESSION["user_id"];
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    exit("Access Denied. Only administrators can view these documents.");
}

// 3. Get the application ID from the URL
$applicationId = filter_input(INPUT_GET, "application_id", FILTER_VALIDATE_INT);
if (!$applicationId) {
    exit("Invalid application ID.");
}

// 4. Fetch ALL student details and file paths
$sql = "SELECT 
            u.*, 
            ca.course_id, 
            c.title AS course_title,
            ca.motivation,
            ca.applied_at
        FROM course_applications ca 
        INNER JOIN users u ON u.id = ca.user_id 
        INNER JOIN courses c ON c.id = ca.course_id
        WHERE ca.id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $applicationId);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

if (!$data) {
    exit("Application not found.");
}

function e($val) { return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Student Info | Admin Portal</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6fb; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #202938; }
        
        /* Profile Info Layout */
        .profile-top { display: flex; gap: 30px; align-items: flex-start; margin-bottom: 30px; flex-wrap: wrap; }
        .profile-photo { width: 150px; height: 150px; border-radius: 12px; object-fit: cover; border: 2px solid #cbd5e1; background: #f8fafc; display: flex; align-items: center; justify-content: center; font-size: 50px; color: #94a3b8; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; flex: 1; }
        .info-row { margin-bottom: 10px; font-size: 15px; }
        .info-row strong { display: inline-block; width: 140px; color: #64748b; }
        
        /* Documents Layout */
        h2 { color: #1e293b; font-size: 18px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px; }
        .document-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .doc-box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; text-align: center; background: #f8fafc; }
        .doc-box h3 { margin-top: 0; font-size: 15px; color: #1e293b; margin-bottom: 15px; }
        .doc-box img { max-width: 100%; height: auto; max-height: 400px; border-radius: 4px; border: 1px solid #cbd5e1; }
        .doc-box iframe { width: 100%; height: 400px; border: 1px solid #cbd5e1; border-radius: 4px; }
        
        .no-doc { color: #991b1b; font-weight: bold; padding: 20px; background: #fee2e2; border-radius: 4px; display: inline-block; }
        .btn-back { display: inline-block; padding: 10px 15px; background: #d62828; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; }
        
        @media (max-width: 768px) {
            .info-grid, .document-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1>Student Information</h1>
            <a href="manage_courses.php" class="btn-back">&larr; Back to Manage Courses</a>
        </div>

        <!-- Personal Information -->
        <div class="profile-top">
            <?php if (!empty($data['profile_photo'])): ?>
                <!-- Use secure gateway for profile photo -->
                <img src="view_file.php?file=<?= urlencode($data['profile_photo']) ?>" class="profile-photo" alt="Profile Photo">
            <?php else: ?>
                <div class="profile-photo">
                    <?= strtoupper(substr($data['full_name'], 0, 1)) ?>
                </div>
            <?php endif; ?>

            <div class="info-grid">
                <div class="info-row"><strong>Full Name:</strong> <?= e($data['full_name']) ?></div>
                <div class="info-row"><strong>Email:</strong> <?= e($data['email']) ?></div>
                <div class="info-row"><strong>Phone:</strong> <?= e($data['phone']) ?></div>
                <div class="info-row"><strong>National ID:</strong> <?= e($data['national_id']) ?></div>
                <div class="info-row"><strong>Date of Birth:</strong> <?= e($data['date_of_birth']) ?></div>
                <div class="info-row"><strong>Gender:</strong> <?= e($data['gender'] ?: 'N/A') ?></div>
                <div class="info-row"><strong>County:</strong> <?= e($data['county']) ?></div>
                <div class="info-row"><strong>Education Level:</strong> <?= e($data['education_level']) ?></div>
                <div class="info-row"><strong>KCSE Year:</strong> <?= e($data['kcse_year']) ?></div>
                <div class="info-row"><strong>Address:</strong> <?= e($data['address'] ?: 'N/A') ?></div>
            </div>
        </div>

        <!-- Application Details -->
        <h2>Application Details</h2>
        <div class="info-grid" style="margin-bottom: 30px;">
            <div class="info-row"><strong>Course Applied:</strong> <?= e($data['course_title']) ?></div>
            <div class="info-row"><strong>Date Applied:</strong> <?= e(date("d M Y, H:i", strtotime($data['applied_at']))) ?></div>
            <div class="info-row" style="grid-column: 1 / -1;"><strong>Motivation:</strong> <br><br><?= nl2br(e($data['motivation'] ?: 'No statement provided.')) ?></div>
        </div>

        <!-- Documents -->
        <h2>Uploaded Documents</h2>
        <div class="document-grid">
            <!-- KCSE Certificate -->
            <div class="doc-box">
                <h3>KCSE Certificate</h3>
                <?php if (!empty($data['kcse_certificate'])): ?>
                    <?php if (pathinfo($data['kcse_certificate'], PATHINFO_EXTENSION) === 'pdf'): ?>
                        <!-- Use secure gateway for PDF -->
                        <iframe src="view_file.php?file=<?= urlencode($data['kcse_certificate']) ?>"></iframe>
                    <?php else: ?>
                        <!-- Use secure gateway for Images -->
                        <img src="view_file.php?file=<?= urlencode($data['kcse_certificate']) ?>" alt="KCSE Certificate">
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-doc">No certificate uploaded.</div>
                <?php endif; ?>
            </div>

            <!-- National ID -->
            <div class="doc-box">
                <h3>National ID Document</h3>
                <?php if (!empty($data['national_id_document'])): ?>
                    <?php if (pathinfo($data['national_id_document'], PATHINFO_EXTENSION) === 'pdf'): ?>
                        <!-- Use secure gateway for PDF -->
                        <iframe src="view_file.php?file=<?= urlencode($data['national_id_document']) ?>"></iframe>
                    <?php else: ?>
                        <!-- Use secure gateway for Images -->
                        <img src="view_file.php?file=<?= urlencode($data['national_id_document']) ?>" alt="National ID">
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-doc">No ID document uploaded.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</body>
</html>