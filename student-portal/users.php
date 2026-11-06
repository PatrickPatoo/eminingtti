<?php
session_start();
require_once "connect.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentUser || $currentUser["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied. Administrator access is required.");
}

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$message = "";
$error = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $error = "Security validation failed. Refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";
        $targetId = filter_input(INPUT_POST, "target_id", FILTER_VALIDATE_INT);

        if (!$targetId || $targetId < 1) {
            $error = "Invalid user account.";
        } elseif ($targetId === $userId && in_array($action, ["delete", "deactivate"], true)) {
            $error = "You cannot delete or deactivate your own administrator account.";
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $check->bind_param("i", $targetId);
            $check->execute();
            $targetExists = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$targetExists) {
                $error = "The selected user was not found.";
            } elseif ($action === "deactivate" || $action === "activate") {
                $active = ($action === "activate") ? 1 : 0;
                $update = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                if (!$update) {
                    $error = "Unable to update account status.";
                } else {
                    $update->bind_param("ii", $active, $targetId);
                    if ($update->execute()) {
                        $message = $active ? "User account activated successfully." : "User account deactivated successfully.";
                    } else {
                        $error = "Unable to update account status.";
                    }
                    $update->close();
                }
            } elseif ($action === "delete") {
                $delete = $conn->prepare("DELETE FROM users WHERE id = ?");
                $delete->bind_param("i", $targetId);
                if ($delete->execute()) {
                    $message = "User account deleted successfully.";
                } else {
                    $error = "Unable to delete this account.";
                }
                $delete->close();
            } elseif ($action === "edit") {
                $name = trim($_POST["full_name"] ?? "");
                $email = trim($_POST["email"] ?? "");
                $role = $_POST["role"] ?? "user";

                if ($name === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Enter a valid full name and email address.";
                } elseif (!in_array($role, ["user", "admin"], true)) {
                    $error = "Invalid account role.";
                } else {
                    $duplicate = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
                    $duplicate->bind_param("si", $email, $targetId);
                    $duplicate->execute();
                    $emailExists = $duplicate->get_result()->num_rows > 0;
                    $duplicate->close();

                    if ($emailExists) {
                        $error = "That email address is already in use.";
                    } else {
                        $update = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?");
                        $update->bind_param("sssi", $name, $email, $role, $targetId);
                        if ($update->execute()) {
                            $message = "User details updated successfully.";
                        } else {
                            $error = "Unable to update user details.";
                        }
                        $update->close();
                    }
                }
            }
        }
    }
}

$search = trim($_GET["search"] ?? "");
$like = "%" . $search . "%";

$sql = "SELECT id, full_name, email, role, created_at, is_active FROM users WHERE full_name LIKE ? OR email LIKE ? ORDER BY id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$users = $stmt->get_result();

$totalUsers = 0;
$totalAdmins = 0;
$inactiveUsers = 0;

$stats = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(role = 'admin'), 0) AS admins, COALESCE(SUM(is_active = 0), 0) AS inactive FROM users");
if ($stats) {
    $statRow = $stats->fetch_assoc();
    $totalUsers = (int)$statRow["total"];
    $totalAdmins = (int)$statRow["admins"];
    $inactiveUsers = (int)$statRow["inactive"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management | Emining TTI Portal</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
:root { color-scheme: light; --page-bg: #f4f6fb; --card-bg: #fff; --text: #202938; --muted: #687386; --border: #e5e7eb; --accent: #d62828; --sidebar-width: 250px; }
body.dark { color-scheme: dark; --page-bg: #111827; --card-bg: #1f2937; --text: #f3f4f6; --muted: #c0c8d4; --border: #374151; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, sans-serif; background: var(--page-bg); color: var(--text); transition: background .3s ease, color .3s ease; }

.overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1005; opacity: 0; transition: opacity 0.3s ease; }
.overlay.active { display: block; opacity: 1; }

.sidebar { position: fixed; top: 0; bottom: 0; left: 0; width: var(--sidebar-width); padding: 24px 17px; background: #fbe891; color: #272727; overflow-y: auto; z-index: 1010; transition: transform 0.3s ease; }
.brand { text-align: center; padding-bottom: 22px; border-bottom: 1px solid rgba(0,0,0,.13); }
.brand img { display: block; width: 100%; max-width: 130px; height: auto; max-height: 90px; object-fit: contain; margin: 0 auto 15px; background-color: #ffffff; padding: 8px; border-radius: 10px; box-shadow: 0 3px 8px rgba(0,0,0,0.1); }
.brand p { margin: 0; color: #5c5540; font-size: 13px; font-weight: bold; }
.menu-label { margin: 25px 12px 10px; color: #766a43; font-size: 11px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
.nav-link { display: flex; align-items: center; gap: 11px; padding: 13px 14px; margin: 5px 0; border-radius: 9px; color: #272727; text-decoration: none; font-size: 14px; font-weight: 600; transition: background .2s, color .2s; }
.nav-link:hover, .nav-link.active { background: #fff; color: #b91c1c; }
.nav-link .material-icons { font-size: 20px; color: #766a43; transition: color 0.2s; }
.nav-link:hover .material-icons, .nav-link.active .material-icons { color: #b91c1c; }
.sidebar-bottom { margin-top: 25px; padding-top: 15px; border-top: 1px solid rgba(0,0,0,.13); }

.main { display: flex; flex-direction: column; margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }
.content { flex: 1; padding: 28px; }
.topbar { display: flex; flex-direction: row; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 27px; flex-wrap: nowrap; }
.topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
.topbar h1 { margin: 0; font-size: 22px; white-space: nowrap; }
.muted { color: var(--muted); font-size: 13px; line-height: 1.2; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.top-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.mobile-menu-btn { display: none; background: transparent; border: none; color: var(--text); cursor: pointer; padding: 0; }
.btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 15px; background: var(--accent); border: 0; border-radius: 8px; color: white; text-decoration: none; font-size: 12px; font-weight: bold; cursor: pointer; white-space: nowrap; }

/* Users Page Specific */
.card, .table-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 5px 18px rgba(0,0,0,.04); }
.card { padding: 20px; }
.stats { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 15px; margin-bottom: 21px; }
.stat-label { color: var(--muted); font-size: 12px; }
.stat-value { margin-top: 10px; font-size: 26px; font-weight: bold; }
.table-card { padding: 20px; overflow: hidden; }
.table-heading { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 16px; }
.table-heading h2 { font-size: 17px; margin: 0 0 6px; }
.search-form { display: flex; gap: 8px; }
.search-form input { width: 205px; max-width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 7px; background: var(--card-bg); color: var(--text); }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th { background: #f7f9fc; color: #53627a; text-align: left; text-transform: uppercase; font-size: 10px; letter-spacing: .5px; }
body.dark th { background: #273449; color: #d4dbea; }
th, td { padding: 13px 10px; border-bottom: 1px solid var(--border); }
td { white-space: nowrap; }
.badge { display: inline-block; border-radius: 20px; padding: 5px 9px; font-size: 10px; font-weight: bold; }
.admin { background: #fee2e2; color: #b91c1c; }
.user { background: #dbeafe; color: #1d4ed8; }
.active { background: #dcfce7; color: #166534; }
.inactive { background: #fee2e2; color: #991b1b; }
.row-actions { display: flex; align-items: center; gap: 5px; }
.inline-form { display: inline; }
.notice { padding: 12px 15px; margin-bottom: 17px; border-radius: 7px; font-size: 13px; }
.success { background: #dcfce7; color: #166534; }
.error { background: #fee2e2; color: #991b1b; }
.modal { display: none; position: fixed; inset: 0; z-index: 100; background: rgba(0,0,0,.55); align-items: center; justify-content: center; padding: 20px; }
.modal.open { display: flex; }
.modal-box { width: 100%; max-width: 430px; padding: 24px; border-radius: 12px; background: var(--card-bg); color: var(--text); }
.modal-box h2 { margin-top: 0; font-size: 20px; }
.modal-box label { display: block; margin: 13px 0 6px; font-size: 13px; }
.modal-box input, .modal-box select { width: 100%; padding: 11px; border: 1px solid var(--border); border-radius: 7px; background: var(--card-bg); color: var(--text); }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }

.footer-quick-links { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 20px; padding: 22px 18px; background: var(--card-bg); border-top: 1px solid var(--border); }
.footer-quick-links strong { color: var(--text); font-size: 14px; }
.footer-quick-links a { color: #dc2626; text-decoration: none; font-size: 14px; font-weight: 600; }
.footer-quick-links a:hover { text-decoration: underline; }
.footer { flex-shrink: 0; padding: 22px 28px; background: #343a40 !important; border-top: 1px solid #4b5157; text-align: center; color: #fff !important; font-size: 13px; line-height: 1.8; }
.footer p { margin: 4px 0; color: #fff !important; }

@media (max-width: 800px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .main { margin-left: 0; }
    .content { padding: 15px; }
    .mobile-menu-btn { display: block; }
    .topbar { flex-wrap: nowrap; gap: 8px; margin-bottom: 15px; }
    .topbar .muted { display: none; }
    .topbar h1 { font-size: 18px; }
    .top-actions { gap: 6px; }
    .stats { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .footer-quick-links { gap: 14px; padding: 18px 10px; flex-direction: column; text-align: center; }
    .footer-quick-links strong { width: 100%; text-align: center; margin-bottom: 5px; }
}
</style>
</head>
<body>

<div class="overlay" id="mobileOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/logo.png" alt="Emining TTI Logo">
        <p>Account Management Portal</p>
    </div>
    <div class="menu-label">Main Menu</div>
    <a class="nav-link" href="dashboard.php"><span class="material-icons">dashboard</span> Dashboard</a>
    <a class="nav-link" href="profile.php"><span class="material-icons">person</span> My Profile</a>
    <a class="nav-link" href="courses.php"><span class="material-icons">library_books</span> Available Courses</a>
    <a class="nav-link" href="my_applications.php"><span class="material-icons">assignment</span> My Applications</a>
    <?php if (isset($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
        <a class="nav-link active" href="users.php"><span class="material-icons">group</span> Registered Users</a>
        <a class="nav-link" href="manage_courses.php"><span class="material-icons">settings</span> Manage Courses</a>
    <?php endif; ?>
    <div class="sidebar-bottom">
        <a class="nav-link" href="../index.html"><span class="material-icons">language</span> Main Website</a>
        <a class="nav-link" href="logout.php"><span class="material-icons">logout</span> Logout</a>
    </div>
</aside>

<div class="main">
    <main class="content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <span class="material-icons">menu</span>
                </button>
                <div>
                    <h1>User Management</h1>
                    <div class="muted">Manage registered accounts</div>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn" href="dashboard.php">Dashboard</a>
            </div>
        </header>

        <?php if ($message !== ""): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <section class="stats">
            <div class="card"><div class="stat-label">Total registered users</div><div class="stat-value"><?= $totalUsers ?></div></div>
            <div class="card"><div class="stat-label">Administrators</div><div class="stat-value"><?= $totalAdmins ?></div></div>
            <div class="card"><div class="stat-label">Deactivated accounts</div><div class="stat-value"><?= $inactiveUsers ?></div></div>
        </section>

        <section class="table-card">
            <div class="table-heading">
                <div>
                    <h2>All Registered Users</h2>
                    <div class="muted">Search accounts and manage their access.</div>
                </div>
                <form class="search-form" method="GET" action="users.php">
                    <input type="search" name="search" placeholder="Search name or email..." value="<?= h($search) ?>">
                    <button class="btn" type="submit">Search</button>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Registered</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($users->num_rows === 0): ?>
                        <tr><td colspan="7">No users found.</td></tr>
                    <?php else: ?>
                        <?php while ($row = $users->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int)$row["id"] ?></td>
                                <td><?= h($row["full_name"]) ?></td>
                                <td><?= h($row["email"]) ?></td>
                                <td><span class="badge <?= $row["role"] === "admin" ? "admin" : "user" ?>"><?= h(ucfirst($row["role"])) ?></span></td>
                                <td><span class="badge <?= (int)$row["is_active"] === 1 ? "active" : "inactive" ?>"><?= (int)$row["is_active"] === 1 ? "Active" : "Inactive" ?></span></td>
                                <td><?= h($row["created_at"]) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button type="button" class="btn edit-button" style="padding:5px 8px; font-size:10px;" data-id="<?= (int)$row["id"] ?>" data-name="<?= h($row["full_name"]) ?>" data-email="<?= h($row["email"]) ?>" data-role="<?= h($row["role"]) ?>">Edit</button>
                                        <?php if ((int)$row["id"] !== $userId): ?>
                                            <form class="inline-form" method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="target_id" value="<?= (int)$row["id"] ?>">
                                                <input type="hidden" name="action" value="<?= (int)$row["is_active"] === 1 ? "deactivate" : "activate" ?>">
                                                <button class="btn" style="padding:5px 8px; font-size:10px; background:#64748b;" type="submit"><?= (int)$row["is_active"] === 1 ? "Off" : "On" ?></button>
                                            </form>
                                            <form class="inline-form" method="POST" onsubmit="return confirm('Permanently delete this user account?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="target_id" value="<?= (int)$row["id"] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button class="btn" style="padding:5px 8px; font-size:10px; background:#fee2e2; color:#b91c1c;" type="submit">Del</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <nav class="footer-quick-links" aria-label="Quick Links">
        <strong>Quick Links:</strong>
        <a href="dashboard.php">Dashboard</a>
        <a href="profile.php">My Profile</a>
        <a href="courses.php">Available Courses</a>
        <a href="my_applications.php">My Applications</a>
        <?php if (isset($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>
            <a href="users.php">Registered Users</a>
            <a href="manage_courses.php">Manage Courses</a>
        <?php endif; ?>
    </nav>

    <footer class="footer">
        <p>&copy; 2026 Emining TTI. All rights reserved.</p>
        <p>Secure account access and personal profile management.</p>
    </footer>
</div>

<div class="modal" id="editModal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <h2>Edit User</h2>
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="target_id" id="editId">
            <label>Full name</label>
            <input type="text" name="full_name" id="editName" required>
            <label>Email address</label>
            <input type="email" name="email" id="editEmail" required>
            <label>Role</label>
            <select name="role" id="editRole" required>
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
            <div class="modal-actions">
                <button class="btn" type="submit">Save Changes</button>
                <button class="btn" type="button" id="closeModal" style="background:#64748b;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("mobileOverlay");

    if (mobileMenuBtn) mobileMenuBtn.addEventListener("click", toggleMenu);
    if (overlay) overlay.addEventListener("click", toggleMenu);

    function toggleMenu() {
        sidebar.classList.toggle("active");
        overlay.classList.toggle("active");
    }

    const modal = document.getElementById("editModal");
    const editId = document.getElementById("editId");
    const editName = document.getElementById("editName");
    const editEmail = document.getElementById("editEmail");
    const editRole = document.getElementById("editRole");
    const closeModal = document.getElementById("closeModal");

    document.querySelectorAll(".edit-button").forEach(function (button) {
        button.addEventListener("click", function () {
            editId.value = button.dataset.id;
            editName.value = button.dataset.name;
            editEmail.value = button.dataset.email;
            editRole.value = button.dataset.role;
            modal.classList.add("open");
        });
    });

    if (closeModal) {
        closeModal.addEventListener("click", function () {
            modal.classList.remove("open");
        });
    }
})();
</script>
</body>
</html>