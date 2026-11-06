<?php
session_start();
require_once "connect.php";

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Generate a 6-digit code
            $code = sprintf("%06d", mt_rand(100000, 999999));
            
            // Set expiration to 1 hour from now to avoid timezone issues
            $expires = date("Y-m-d H:i:s", strtotime("+1 hour")); 

            $update = $conn->prepare("UPDATE users SET reset_code = ?, reset_expires = ? WHERE email = ?");
            $update->bind_param("sss", $code, $expires, $email);
            
            if ($update->execute()) {
                // Display the code on the screen for testing
                $message = "A reset code has been generated for <strong>" . htmlspecialchars($email) . "</strong>.<br><br><strong>Your Code: <span style='color:red; font-size:24px; letter-spacing:2px;'>" . $code . "</span></strong><br><br><a href='reset_password.php' style='color:#2563eb; font-weight:bold;'>Click here to reset your password</a>";
            } else {
                $error = "Something went wrong. Please try again.";
            }
            $update->close();
        } else {
            $error = "This email address is not registered in our system.";
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
    <title>Forgot Password | Emining TTI Portal</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body>
    <main class="auth-container">
        <section class="auth-card login-card">
            <div class="login-logo">
                <img src="../assets/logo.png" alt="Logo" onerror="this.style.display='none';">
            </div>

            <h1>Reset Password</h1>
            <p class="subtitle">Enter your email address and we'll generate a reset code for you.</p>

            <?php if ($message !== ""): ?>
                <div class="message success" style="text-align:center;" role="status"><?= $message ?></div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="message error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($message === ""): ?>
                <form method="POST" action="forgot_password.php">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your registered email">

                    <button type="submit">Get Reset Code</button>
                </form>
            <?php endif; ?>

            <p class="auth-link" style="margin-top: 20px;">
                Remembered your password? <a href="login.php">Back to Login</a>
            </p>
        </section>
    </main>
</body>
</html>