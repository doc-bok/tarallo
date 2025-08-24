<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Session
{
    /**
     * Ensures a PHP session is started.
     * Call this at the start of any function that uses $_SESSION.
     */
    public static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Logs a user out of the current session.
     */
    public static function logoutInternal(): void
    {
        self::ensureSession();

        // Clear all session variables
        $_SESSION = [];

        // Delete the session cookie (if any)
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destroy the session file
        session_destroy();

        // Regenerate session ID after logout (good practice)
        session_start();
        session_regenerate_id(true);
    }

    /**
     * Logs a user into the application.
     * @param array $request The request parameters.
     * @return string[]|true[] The result of the login attempt.
     */
    public static function login(array $request): array
    {
        Session::ensureSession();

        // Force logout if already logged in
        if (self::isUserLoggedIn()) {
            Logger::info("Login: Existing session detected, logging out first");
            Session::logoutInternal();
        }

        $username = trim($request['username'] ?? '');
        $password = $request['password'] ?? '';

        if ($username === '' || $password === '') {
            Logger::warning("Login: Missing username or password");
            http_response_code(400);
            return ['error' => 'Missing username or password'];
        }

        // Look up user
        $userRecord = DB::getInstance()->fetchRow(
            "SELECT * FROM tarallo_users WHERE username = :username",
            ['username' => $username]
        );

        if (!$userRecord) {
            Logger::warning("Login failed: Unknown username '$username'");
            http_response_code(401);
            return ['error' => 'Invalid username or password'];
        }

        // First login: password is empty
        if (strlen($userRecord['password']) === 0) {
            Logger::info("Login: First login for '$username', setting password");
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $ok = DB::getInstance()->query(
                "UPDATE tarallo_users SET password = :passwordHash WHERE username = :username",
                ['passwordHash' => $passwordHash, 'username' => $username]
            );

            if (!$ok) {
                Logger::error("Login: Failed to set initial password for '$username'");
                http_response_code(500);
                return ['error' => 'Internal error setting first password'];
            }

            // Refresh record
            $userRecord['password'] = $passwordHash;
        }

        if (!password_verify($password, $userRecord['password'])) {
            Logger::warning("Login failed: Wrong password for '$username'");
            http_response_code(401);
            return ['error' => 'Invalid username or password'];
        }

        // Successful login → update session
        $_SESSION['logged_in']    = true;
        $_SESSION['user_id']      = $userRecord['id'];
        $_SESSION['username']     = $userRecord['username'];
        $_SESSION['display_name'] = $userRecord['display_name'];
        $_SESSION['is_admin']     = (bool) $userRecord['is_admin'];

        // Update last access time
        DB::getInstance()->query(
            "UPDATE tarallo_users SET last_access_time = :t WHERE id = :id",
            ['t' => time(), 'id' => $userRecord['id']]
        );

        Logger::info("Login successful for '$username' (user_id {$userRecord['id']})");

        return ['success' => true];
    }

    /**
     * Checks to see if a user is logged in.
     * @return bool TRUE if a user is logged into the session.
     */
    public static function isUserLoggedIn(): bool
    {
        Session::ensureSession();
        return !empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
    }

    /**
     * Logs a user out of the session.
     * @param array $request The request parameters.
     * @return true[] The result of the logout attempt.
     */
    public static function logout(array $request): array
    {
        self::ensureSession();

        if (self::isUserLoggedIn()) {
            Logger::info("Logout: User {$_SESSION['username']} (ID {$_SESSION['user_id']}) logging out");
        } else {
            Logger::debug("Logout: No user currently logged in");
        }

        self::logoutInternal();
        return ['success' => true];
    }
}