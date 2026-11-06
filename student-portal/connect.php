<?php
// Local XAMPP Database Configuration
$host = "localhost";
$username = "root";
$password = ""; // Default XAMPP password is empty
$database = "auth_system"; // The name of the database you created

// Disable detailed mysqli errors from being displayed
mysqli_report(MYSQLI_REPORT_OFF);

// Connect to the database
$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

// Check the connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection failed. Please check your local XAMPP settings.");
}

// Set character encoding
if (!$conn->set_charset("utf8mb4")) {
    error_log("Failed to set database character encoding.");
    die("Database configuration error.");
}
?>