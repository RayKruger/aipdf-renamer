<?php
// show PHP errors (for debugging... remove later)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

// create users table if it doesn't exist
$createUsersSql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
";
$mysqli->query($createUsersSql);

// add user_id column to pdf_files if it doesn't exist
$checkUserIdCol = $mysqli->query("SHOW COLUMNS FROM pdf_files LIKE 'user_id'");
if ($checkUserIdCol && $checkUserIdCol->num_rows == 0) {
    $mysqli->query("ALTER TABLE pdf_files ADD COLUMN user_id INT NULL AFTER id");
}
$message = "";

// handle form submissions (whether it's login or signup)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {

    $action = $_POST["action"];

    // sign up
    if ($action === "signup") {
        // Get values from the sign-up form
        $username = isset($_POST["signup_username"]) ? trim($_POST["signup_username"]) : "";
        $password = isset($_POST["signup_password"]) ? $_POST["signup_password"] : "";

        // validation
        if ($username === "" || $password === "") {
            $message = "Please enter both a username and password to sign up.";
        } else {
            // only allow one username
            $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
            if (!$stmt) {
                $message = "Database error.";
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $message = "That username is already taken.";
                    $stmt->close();
                } else {
                    $stmt->close();

                    // insert new user
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $mysqli->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
                    if (!$stmt) {
                        $message = "Database error.";
                    } else {
                        $stmt->bind_param("ss", $username, $passwordHash);
                        if ($stmt->execute()) {
                            $message = "Sign up successful! You can now log in.";
                        } else {
                            $message = "Error creating user.";
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }

    // login
    if ($action === "login") {
        // get values
        $username = isset($_POST["login_username"]) ? trim($_POST["login_username"]) : "";
        $password = isset($_POST["login_password"]) ? $_POST["login_password"] : "";

        // validate
        if ($username === "" || $password === "") {
            $message = "Please enter both a username and password to log in.";
        } else {
            // look up the user by username
            $stmt = $mysqli->prepare("SELECT id, password_hash FROM users WHERE username = ?");
            if (!$stmt) {
                $message = "Database error.";
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->bind_result($userId, $passwordHash);

                if ($stmt->fetch()) {
                    // check if password is correct
                    if (password_verify($password, $passwordHash)) {
                        // store info in this session
                        $_SESSION["user_id"]  = $userId;
                        $_SESSION["username"] = $username;

                        $stmt->close();
                        header("Location: AiPDFsearchPage.php");
                        exit;
                    } else {
                        $message = "Invalid password.";
                    }
                } else {
                    $message = "User not found.";
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
    <title>Login / Sign Up</title>

    <!-- Tailwind for css -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(to bottom, #0f172a, #1e293b, #000);
        }
    </style>
</head>
<body class="min-h-screen text-slate-100">
<div class="max-w-lg mx-auto px-4 py-10">

    <header class="mb-8">
    <div class="mb-4">

    </div>
    <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">
        <span class="text-lime-400">AiPDF</span> Library&Search Login
    </h1>
        <p class="text-slate-300 mt-2">
            Sign in to manage your PDFs or create a new account.
        </p>
    </header>

    <div class="bg-slate-900/80 border border-slate-700 rounded-2xl shadow-xl p-8">

        <?php if ($message !== ""): ?>
            <div class="mb-6 rounded-lg border border-amber-500/60 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div id="loginBox">
            <h2 class="text-xl font-semibold text-slate-100 mb-4">Login</h2>
            <form method="post" action="login.php" class="space-y-4">
                <input type="hidden" name="action" value="login">

                <div>
                    <label class="block text-sm text-slate-300 mb-1">Username</label>
                    <input type="text" name="login_username"
                           class="w-full rounded-lg bg-slate-800/70 border border-slate-600 px-3 py-2 text-sm
                                  focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>

                <div>
                    <label class="block text-sm text-slate-300 mb-1">Password</label>
                    <input type="password" name="login_password"
                           class="w-full rounded-lg bg-slate-800/70 border border-slate-600 px-3 py-2 text-sm
                                  focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>

                <button type="submit"
                        class="w-full mt-2 inline-flex justify-center px-4 py-2.5 rounded-lg bg-cyan-500 text-slate-900
                               font-semibold shadow hover:bg-cyan-400 transition">
                    Log In
                </button>
            </form>

            <p class="mt-6 text-sm text-slate-300 text-center">
                No account?
                <button type="button" onclick="showSignup()"
                        class="text-cyan-400 hover:text-cyan-300 font-semibold">
                    Sign up here
                </button>
            </p>
        </div>

        <div id="signupBox" class="hidden">
            <h2 class="text-xl font-semibold text-slate-100 mb-4">Create Account</h2>
            <form method="post" action="login.php" class="space-y-4">
                <input type="hidden" name="action" value="signup">

                <div>
                    <label class="block text-sm text-slate-300 mb-1">Username</label>
                    <input type="text" name="signup_username"
                           class="w-full rounded-lg bg-slate-800/70 border border-slate-600 px-3 py-2 text-sm
                                  focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>

                <div>
                    <label class="block text-sm text-slate-300 mb-1">Password</label>
                    <input type="password" name="signup_password"
                           class="w-full rounded-lg bg-slate-800/70 border border-slate-600 px-3 py-2 text-sm
                                  focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>

                <button type="submit"
                        class="w-full mt-2 inline-flex justify-center px-4 py-2.5 rounded-lg bg-lime-400 text-slate-900
                               font-semibold shadow hover:bg-lime-300 transition">
                    Create Account
                </button>
            </form>

            <p class="mt-6 text-sm text-slate-300 text-center">
                Already have an account?
                <button type="button" onclick="showLogin()"
                        class="text-cyan-400 hover:text-cyan-300 font-semibold">
                    Log in here
                </button>
            </p>
        </div>
    </div>

    <p class="mt-6 text-sm text-slate-400">
        <a href="index.php" class="text-cyan-400 hover:text-cyan-300">
            Back to AiPDF Renamer
        </a>
    </p>
</div>

<script>
    // show the sign-up form and hide the login form
    function showSignup() {
        document.getElementById("loginBox").classList.add("hidden");
        document.getElementById("signupBox").classList.remove("hidden");
    }

    // show the login form and hide the sign-up form
    function showLogin() {
        document.getElementById("signupBox").classList.add("hidden");
        document.getElementById("loginBox").classList.remove("hidden");
    }
</script>

</body>
</html>
