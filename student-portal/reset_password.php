<?php
session_start();
require_once "connect.php";

$message = "";
$error = "";
$email_input = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email_input = trim($_POST["email"] ?? "");
    
    // Strip any hidden spaces from the code
    $code = trim($_POST["code"] ?? "");
    $code = preg_replace('/\s+/', '', $code); 
    
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($email_input === "" || $code === "" || $new_password === "" || $confirm_password === "") {
        $error = "Please fill in all fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Check if code is valid
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND reset_code = ? LIMIT 1");
        $stmt->bind_param("ss", $email_input, $code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update password and clear reset code
            $update = $conn->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_expires = NULL WHERE id = ?");
            $update->bind_param("si", $hashed_password, $user["id"]);
            
            if ($update->execute()) {
                $message = "Your password has been reset successfully! <br><br><a href='login.php' style='color:#2563eb; font-weight:bold;'>Click here to login</a>";
            } else {
                $error = "Failed to update password. Please try again.";
            }
            $update->close();
        } else {
            $error = "Invalid reset code. Please make sure you typed the exact 6 digits shown on the previous page.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | Emining TTI Portal</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .password-container { position: relative; margin-bottom: 20px; }
        .password-container input { margin-bottom: 0 !important; padding-right: 45px !important; }
        .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; display: flex; align-items: center; }
    </style>
</head>
<body>
    <main class="auth-container">
        <section class="auth-card login-card">
            <div class="login-logo">
                <img src="../assets/logo.png" alt="Logo" onerror="this.style.display='none';">
            </div>

            <h1>Set New Password</h1>
            <p class="subtitle">Enter the code generated for you and your new password.</p>

            <?php if ($message !== ""): ?>
                <div class="message success" style="text-align:center;" role="status"><?= $message ?></div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($message === ""): ?>
                <form method="POST" action="reset_password.php">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($email_input) ?>" required placeholder="Your registered email">

                    <label for="code">Reset Code (6-digit number)</label>
                    <input type="text" id="code" name="code" required placeholder="e.g. 123456" autocomplete="off">

                    <label for="new_password">New Password</label>
                    <div class="password-container">
                        <input type="password" id="new_password" name="new_password" minlength="8" required>
                        <span class="toggle-password" onclick="togglePassword('new_password', this)">
                            <span class="material-icons" style="font-size: 20px;">visibility_off</span>
                        </span>
                    </div>

                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-container">
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                        <span class="toggle-password" onclick="togglePassword('confirm_password', this)">
                            <span class="material-icons" style="font-size: 20px;">visibility_off</span>
                        </span>
                    </div>

                    <button type="submit">Reset Password</button>
                </form>
            <?php endif; ?>

            <p class="auth-link" style="margin-top: 20px;">
                <a href="login.php">Back to Login</a>
            </p>
        </section>
    </main>

    <script>
        function togglePassword(inputId, iconSpan) {
            const input = document.getElementById(inputId);
            const icon = iconSpan.querySelector('.material-icons');
            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "visibility"; 
            } else {
                input.type = "password";
                icon.textContent = "visibility_off"; 
            }
        }
    </script>
</body>
</html>