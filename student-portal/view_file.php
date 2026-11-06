<?php
session_start();
require_once "connect.php";

// 1. Check if user is logged in & is an admin
if (!isset($_SESSION["user_id"])) {
    http_response_code(403);
    exit("Access denied.");
}

$userId = (int) $_SESSION["user_id"];
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied. Admins only.");
}

// 2. Get the file path from the URL
$file = $_GET['file'] ?? '';

// 3. Security check: Ensure the file path is safe (prevents directory traversal)
if (strpos($file, '..') !== false || strpos($file, 'assets/uploads/') === false) {
    http_response_code(400);
    exit("Invalid file request.");
}

// 4. Define the absolute path to the file
$filePath = __DIR__ . '/../' . $file;

if (!file_exists($filePath)) {
    http_response_code(404);
    exit("File not found.");
}

// 5. Serve the file with the correct MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

header("Content-Type: " . $mimeType);
header("Content-Length: " . filesize($filePath));
readfile($filePath);
exit;
?>