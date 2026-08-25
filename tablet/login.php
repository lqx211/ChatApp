<?php
/**
 * ChatApp - IE8 Login & Register Page (MySQL)
 * Server-rendered, no JS required for form submission
 */
require_once __DIR__ . '/../api/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['username'])) {
    header('Location: chat.php');
    exit;
}

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['ie8_action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($action === 'login') {
        if (empty($username) || empty($password)) {
            $error = 'Something went wrong.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT username, password, enabled FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                if (!$user['enabled']) {
                    $error = 'Your account has been disabled.';
                } elseif (password_verify($password, $user['password'])) {
                    $_SESSION['username'] = $user['username'];
                    header('Location: chat.php');
                    exit;
                } else {
                    $error = 'Something went wrong.';
                }
            } else {
                $error = 'Something went wrong.';
            }
        }
    } elseif ($action === 'register') {
        $password2 = $_POST['password2'] ?? '';

        if (strlen($username) < 3 || strlen($username) > 20) {
            $error = 'Something went wrong.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = 'Something went wrong.';
        } elseif (strlen($password) < 4) {
            $error = 'Something went wrong.';
        } elseif ($password !== $password2) {
            $error = 'Something went wrong.';
        } else {
            $pdo = db();

            // Check if exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Something went wrong.';
            } else {
                $lang = trim($_POST['language'] ?? 'en');
                if (!in_array($lang, ['en', 'zh'])) $lang = 'en';
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare('INSERT INTO users (username, password, preferred_language, created_at) VALUES (?, ?, ?, NOW())')->execute([$username, $hash, $lang]);
                $_SESSION['username'] = $username;
                $_SESSION['preferred_language'] = $lang;
                header('Location: chat.php');
                exit;
            }
        }
    }
}

$showTab = $_GET['tab'] ?? 'login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=8">
    <title>Legacy Login</title>
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            background-color: #1a1a1a;
            color: #e0e0e0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
        }
        #wrapper {
            text-align: center;
            padding-top: 80px;
        }
        #auth-box {
            width: 380px;
            margin: 0 auto;
            background-color: #2a2a2a;
            border: 1px solid #3a3a3a;
            padding: 30px 28px;
            text-align: left;
        }
        #auth-box h1 {
            text-align: center;
            color: #c0c0c0;
            font-size: 22px;
            margin: 0 0 4px 0;
            padding: 0;
        }
        #auth-box p.subtitle {
            text-align: center;
            color: #777;
            font-size: 12px;
            margin: 0 0 24px 0;
        }
        .tabs {
            margin-bottom: 20px;
            border-bottom: 2px solid #3a3a3a;
            padding: 0;
        }
        .tabs a {
            display: inline-block;
            padding: 8px 20px;
            color: #777;
            text-decoration: none;
            font-weight: bold;
            font-size: 13px;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        .tabs a.active {
            color: #c0c0c0;
            border-bottom: 2px solid #888;
        }
        .error-msg {
            background-color: #3d2020;
            border: 1px solid #5c2a2a;
            color: #e06060;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: 12px;
        }
        .form-row {
            margin-bottom: 12px;
        }
        .form-row label {
            display: block;
            color: #aaa;
            font-size: 12px;
            margin-bottom: 4px;
        }
        .form-row input, .form-row select {
            width: 100%;
            padding: 8px 6px;
            background-color: #1e1e1e;
            border: 1px solid #444;
            color: #e0e0e0;
            font-size: 13px;
            font-family: Arial, sans-serif;
            box-sizing: border-box;
        }
        .form-row input:focus {
            border-color: #888;
            outline: none;
        }
        .btn-primary {
            width: 100%;
            padding: 10px;
            background-color: #4a4a4a;
            border: 1px solid #555;
            color: #e0e0e0;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            font-family: Arial, sans-serif;
        }
        .btn-primary:hover {
            background-color: #5a5a5a;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #777;
            font-size: 12px;
            text-decoration: none;
        }
        .back-link:hover {
            color: #aaa;
        }
        <?php if ($showTab === 'register'): ?>
        #login-form { display: none; }
        #register-form { display: block; }
        <?php else: ?>
        #login-form { display: block; }
        #register-form { display: none; }
        <?php endif; ?>
    </style>
</head>
<body>
    <div id="wrapper">
        <div id="auth-box">
            <h1>Login</h1>
            <p class="subtitle">may support IE8</p>

            <div class="tabs">
                <a href="?tab=login" class="<?php echo $showTab === 'login' ? 'active' : ''; ?>">Login</a>
                <a href="?tab=register" class="<?php echo $showTab === 'register' ? 'active' : ''; ?>">Register</a>
            </div>

            <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Login Form -->
            <form id="login-form" method="post" action="login.php?tab=login">
                <input type="hidden" name="ie8_action" value="login">
                <div class="form-row">
                    <label for="loginUsername">Username</label>
                    <input type="text" id="loginUsername" name="username" maxlength="20" value="<?php echo htmlspecialchars($username ?? ''); ?>">
                </div>
                <div class="form-row">
                    <label for="loginPassword">Password</label>
                    <input type="password" id="loginPassword" name="password">
                </div>
                <div class="form-row">
                    <input type="submit" class="btn-primary" value="Log In">
                </div>
            </form>

            <!-- Register Form -->
            <form id="register-form" method="post" action="login.php?tab=register">
                <input type="hidden" name="ie8_action" value="register">
                <div class="form-row">
                    <label for="regUsername">Username</label>
                    <input type="text" id="regUsername" name="username" maxlength="20" value="<?php echo htmlspecialchars($username ?? ''); ?>">
                </div>
                <div class="form-row">
                    <label for="regPassword">Password</label>
                    <input type="password" id="regPassword" name="password">
                </div>
                <div class="form-row">
                    <label for="regPassword2">Confirm Password</label>
                    <input type="password" id="regPassword2" name="password2">
                </div>
                <div class="form-row">
                    <label for="regLanguage">Preferred Language</label>
                    <select id="regLanguage" name="language" style="width:100%; padding:8px 6px; background-color:#1e1e1e; border:1px solid #444; color:#e0e0e0; font-size:13px; font-family:Arial,sans-serif; box-sizing:border-box">
                        <option value="en">English (US)</option>
                        <option value="zh">Chinese Simplified (简体中文)</option>
                    </select>
                </div>
                <div class="form-row">
                    <input type="submit" class="btn-primary" value="Register">
                </div>
            </form>

            <a href="../index.php" class="back-link">Back to entry page</a>
        </div>
    </div>
</body>
</html>