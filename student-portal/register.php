<?php
session_start();
require_once "connect.php";

$error = "";
$full_name = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "" || $email === "" || $password === "" || $confirm_password === "") {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must contain at least 8 characters.";
    } elseif (strlen($password) > 72) {
        $error = "Password must not exceed 72 characters.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter (A-Z).";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter (a-z).";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number (0-9).";
    } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $error = "Password must contain at least one special character (@, #, $ or !).";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    }

    if ($error === "") {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if (!$check) {
            $error = "Something went wrong. Please try again.";
        } else {
            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $error = "This email is already registered.";
            }
            $check->close();
        }
    }

    if ($error === "") {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)");
        if (!$stmt) {
            $error = "Something went wrong. Please try again.";
        } else {
            $stmt->bind_param("sss", $full_name, $email, $hashed_password);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: login.php?registered=1");
                exit;
            } else {
                if ($stmt->errno === 1062) {
                    $error = "This email is already registered.";
                } else {
                    $error = "Registration failed. Please try again.";
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Emining TTI Portal</title>
    <link rel="stylesheet" href="../assets/style.css"/>
    
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
<div class="auth-container">
    <form class="auth-form" method="POST" action="register.php" id="register-form">
        <h2>Create Account</h2>
        <p class="subtitle">Register to get started</p>

        <?php if ($error !== ""): ?>
            <div class="message error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($full_name, ENT_QUOTES, "UTF-8") ?>" autocomplete="name" required>

        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, "UTF-8") ?>" autocomplete="email" required>

        <label for="password">Password</label>
        <div class="password-container">
            <input type="password" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="password-feedback" required>
            <span class="toggle-password" onclick="togglePassword('password', this)">
                <span class="material-icons" style="font-size: 20px;">visibility_off</span>
            </span>
        </div>
        <div id="password-feedback" class="password-feedback" role="status" aria-live="polite" hidden></div>

        <label for="confirm_password">Confirm Password</label>
        <div class="password-container">
            <input type="password" id="confirm_password" name="confirm_password" maxlength="72" autocomplete="new-password" aria-describedby="confirm-error" required>
            <span class="toggle-password" onclick="togglePassword('confirm_password', this)">
                <span class="material-icons" style="font-size: 20px;">visibility_off</span>
            </span>
        </div>
        <div id="confirm-error" class="field-error" role="status"></div>

        <button type="submit" id="register-button">Register</button>

        <p class="form-footer">Already have an account? <a href="login.php">Login here</a></p>
        <p class="form-footer" style="margin-top: 15px;"><a href="../index.html" style="color: #64748b; font-weight: normal;">&larr; Back to Emining TTI Website</a></p>
    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("register-form");
    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirm_password");
    const feedback = document.getElementById("password-feedback");
    const confirmError = document.getElementById("confirm-error");

    function getFirstPasswordProblem(value) {
        if (value.length < 8) return "At least 8 characters.";
        if (!/[A-Z]/.test(value)) return "One uppercase letter (A-Z).";
        if (!/[a-z]/.test(value)) return "One lowercase letter (a-z).";
        if (!/[0-9]/.test(value)) return "One number (0-9).";
        if (!/[^a-zA-Z0-9]/.test(value)) return "One special character (@, #, $ or !).";
        if (value.length > 72) return "No more than 72 characters.";
        return "";
    }

    function updatePasswordFeedback() {
        const value = password.value;
        const problem = getFirstPasswordProblem(value);
        if (value === "" && document.activeElement !== password) {
            feedback.hidden = true; feedback.textContent = ""; feedback.className = "password-feedback";
        } else if (problem !== "") {
            feedback.hidden = false; feedback.className = "password-feedback feedback-error"; feedback.textContent = "Password needs: " + problem;
        } else {
            feedback.hidden = false; feedback.className = "password-feedback feedback-success"; feedback.textContent = "✓ Your password meets all requirements.";
        }
        updateConfirmFeedback();
    }

    function updateConfirmFeedback() {
        if (confirmPassword.value === "") {
            confirmError.textContent = ""; confirmError.className = "field-error";
        } else if (password.value !== confirmPassword.value) {
            confirmError.textContent = "Passwords do not match."; confirmError.className = "field-error visible";
        } else {
            confirmError.textContent = "✓ Passwords match."; confirmError.className = "field-error field-success";
        }
    }

    password.addEventListener("focus", updatePasswordFeedback);
    password.addEventListener("input", updatePasswordFeedback);
    password.addEventListener("blur", updatePasswordFeedback);
    confirmPassword.addEventListener("input", updateConfirmFeedback);
    confirmPassword.addEventListener("blur", updateConfirmFeedback);

    form.addEventListener("submit", function (event) {
        const problem = getFirstPasswordProblem(password.value);
        if (problem !== "") {
            event.preventDefault(); feedback.hidden = false; feedback.className = "password-feedback feedback-error"; feedback.textContent = "Password needs: " + problem; password.focus(); return;
        }
        if (password.value !== confirmPassword.value) {
            event.preventDefault(); confirmError.textContent = "Passwords do not match."; confirmError.className = "field-error visible"; confirmPassword.focus();
        }
    });
});

// Function to toggle password visibility
function togglePassword(inputId, iconSpan) {
    const input = document.getElementById(inputId);
    const icon = iconSpan.querySelector('.material-icons');
    
    if (input.type === "password") {
        input.type = "text";
        icon.textContent = "visibility"; // Open eye
    } else {
        input.type = "password";
        icon.textContent = "visibility_off"; // Closed eye
    }
}
</script>
</body>
</html>