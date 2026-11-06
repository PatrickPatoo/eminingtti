<?php
session_start();
require_once "connect.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    exit("Access Denied. Only administrators can update the fee structure.");
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_FILES["fee_pdf"]) && $_FILES["fee_pdf"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES["fee_pdf"];
        
        if ($file["error"] !== UPLOAD_ERR_OK) {
            $error = "Upload failed. Please try again.";
        } elseif ($file["size"] > 5 * 1024 * 1024) {
            $error = "The file must be 5MB or smaller.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file["tmp_name"]);
            
            if ($mime !== "application/pdf") {
                $error = "Only PDF files are allowed.";
            } else {
                // We save it exactly as fee_structure.pdf to overwrite the old one
                $targetPath = __DIR__ . "/../assets/fee_structure.pdf";
                
                if (move_uploaded_file($file["tmp_name"], $targetPath)) {
                    $message = "Fee structure updated successfully!";
                } else {
                    $error = "Failed to save the file. Check folder permissions.";
                }
            }
        }
    } else {
        $error = "Please select a PDF file to upload.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Fee Structure | Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .upload-box { max-width: 600px; margin: 50px auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; color: #202938; font-size: 24px; }
        label { display: block; margin-bottom: 10px; font-weight: bold; color: #334155; }
        input[type="file"] { display: block; width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 7px; margin-bottom: 20px; }
        button { padding: 12px 20px; background: #14532d; color: white; border: none; border-radius: 7px; font-weight: bold; cursor: pointer; }
        button:hover { background: #2563eb; }
        .back-btn { display: inline-block; margin-bottom: 20px; color: #64748b; text-decoration: none; font-weight: bold; }
        .back-btn:hover { text-decoration: underline; }
    </style>
</head>
<body style="background: #f4f6fb;">
    <div class="upload-box">
        <a href="dashboard.php" class="back-btn">&larr; Back to Dashboard</a>
        <h1>Upload Fee Structure</h1>
        <p style="color: #64748b; margin-bottom: 20px;">Upload a new PDF file to replace the current fee structure. This will immediately update the file available to all students.</p>

        <?php if ($message !== ""): ?>
            <div class="message success" style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 7px; margin-bottom: 20px;"><?= $message ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="message error" style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 7px; margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <label for="fee_pdf">Select PDF File (Max 5MB)</label>
            <input type="file" id="fee_pdf" name="fee_pdf" accept="application/pdf" required>

            <button type="submit">Upload & Replace</button>
        </form>
    </div>
</body>
</html>