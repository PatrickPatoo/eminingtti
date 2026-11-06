<?php
session_start();
require_once "connect.php";

// 1. Check if user is logged in and is an admin
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
    exit("Access Denied. Only administrators can delete the fee structure.");
}

// 2. Define file path
$filePath = __DIR__ . "/../assets/fee_structure.pdf";

// 3. Delete the file if it exists
if (file_exists($filePath)) {
    if (unlink($filePath)) {
        // Successfully deleted, redirect back to dashboard
        header("Location: dashboard.php?fee_deleted=1");
        exit;
    } else {
        // Failed to delete (permissions issue)
        header("Location: dashboard.php?fee_delete_error=1");
        exit;
    }
} else {
    // File doesn't exist, just redirect back
    header("Location: dashboard.php");
    exit;
}
?>