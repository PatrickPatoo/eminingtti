<?php
session_start();
require_once "connect.php";

// Removed redirect block so the login page always shows.

$email = "";
$error = "";
$success = "";

if (isset($_GET["registered"]) && $_GET["registered"] === "1") {
    $success = "Registration successful! You can now log in.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please enter your email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, email, password, role FROM users WHERE email = ? LIMIT 1");

        if (!$stmt) {
            error_log("Login query preparation failed: " . $conn->error);
            $error = "Something went wrong. Please try again.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user["password"])) {
                session_regenerate_id(true);
                
                // Clear old session data before setting new user
                $_SESSION = [];

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["role"] = $user["role"] ?? "user";
                
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Invalid email or password.";
            }
        }
    }
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Emining TTI Portal</title>
    <link rel="stylesheet" href="../assets/style.css">
    
    <!-- Google Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    
    <style>
        /* CSS for the password container and eye icon */
        .password-container {
            position: relative;
            margin-bottom: 20px;
        }
        .password-container input {
            margin-bottom: 0 !important;
            padding-right: 45px !important; /* Make room for the eye icon */
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
            user-select: none;
            display: flex;
            align-items: center;
        }
    </style>
</head>
<body>
    <main class="auth-container">
        <section class="auth-card login-card">
            <div class="login-logo">
                <img src="../assets/logo.png" alt="Emining TTI Logo" onerror="this.style.display='none';">
            </div>

            <h1>Student Portal</h1>
            <p class="subtitle">Log in to access your account.</p>

            <?php if ($success !== ""): ?>
                <div class="message success" role="status" id="success-message"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="message error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>

                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                    <span class="toggle-password" onclick="togglePassword('password', this)">
                        <span class="material-icons" style="font-size: 20px;">visibility_off</span>
                    </span>
                </div>

                <div class="forgot-password-row">
    <a href="forgot_password.php">Forgot password?</a>
</div>

                <button type="submit" id="loginButton">Login</button>
            </form>

            <p class="auth-link">
                Don't have an account? <a href="register.php">Register here</a>
            </p>

            <p class="auth-link" style="margin-top: 15px;">
                <a href="../index.html" style="color: #64748b; font-weight: normal;">&larr; Back to Emining TTI Website</a>
            </p>

            <div class="login-footer" style="margin-top: 22px; padding-top: 16px; border-top: 1px solid #cbd5e1; color: #64748b; text-align: center; font-size: 12px;">
                Secure account access
            </div>
        </section>
    </main>

    <script>
        function togglePassword(inputId, iconSpan) {
            const input = document.getElementById(inputId);
            const icon = iconSpan.querySelector('.material-icons');
            
            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "visibility"; // Show open eye
            } else {
                input.type = "password";
                icon.textContent = "visibility_off"; // Show closed eye
            }
        }
    </script>
</body>
</html>