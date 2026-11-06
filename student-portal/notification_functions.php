<?php
// notification_functions.php

function createNotification(
    mysqli $conn,
    int $userId,
    string $title,
    string $message,
    string $type = "general",
    ?int $applicationId = null
): bool {
    $stmt = $conn->prepare(
        "INSERT INTO notifications
            (user_id, title, message, type, related_application_id)
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log("Notification prepare failed: " . $conn->error);
        return false;
    }

    $stmt->bind_param(
        "isssi",
        $userId,
        $title,
        $message,
        $type,
        $applicationId
    );

    $saved = $stmt->execute();

    if (!$saved) {
        error_log("Notification save failed: " . $stmt->error);
    }

    $stmt->close();

    return $saved;
}

function notifyAdministrators(
    mysqli $conn,
    string $applicantName,
    string $courseTitle,
    int $applicationId
): void {
    $admins = $conn->query(
        "SELECT id FROM users WHERE role = 'admin'"
    );

    if (!$admins) {
        error_log("Could not retrieve administrators: " . $conn->error);
        return;
    }

    while ($admin = $admins->fetch_assoc()) {
        createNotification(
            $conn,
            (int) $admin["id"],
            "New course application",
            $applicantName . " has applied for " . $courseTitle . ".",
            "application",
            $applicationId
        );
    }

    $admins->free();
}
?>