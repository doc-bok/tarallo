<?php

declare(strict_types=1);

class Session
{
    /**
     * Ensures a PHP session is started.
     * Call this at the start of any function that uses $_SESSION.
     */
    public static function EnsureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Logs a user out of the current session.
     */
    public static function LogoutInternal(): void
    {
        self::EnsureSession();

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
}