<?php

declare(strict_types=1);

class Account
{
    private const ROLE_ADMIN = 'admin';

    /**
     * Create a new admin account with a unique "admin" username and random password.
     * @return array{id:int,username:string,password:string} Account info (plaintext password only returned here once).
     * @throws RuntimeException if DB insert fails.
     */
    public static function createNewAdminAccount(): array
    {
        $account = [
            'username' => self::ROLE_ADMIN,
            'password' => '',
            'id'       => 0
        ];

        try {
            // Get existing admin usernames
            $userQuery = "SELECT username FROM tarallo_users WHERE username LIKE :pattern";
            $usedAdminNames = DB::fetchColumn($userQuery, 'username', [
                'pattern' => 'admin%'
            ]);
        } catch (Throwable $e) {
            Logger::error("CreateNewAdminAccount: Failed to fetch existing admin usernames - " . $e->getMessage());
            throw new RuntimeException("Failed to query existing admin usernames");
        }

        // Find the next available admin username
        $i = 0;
        while (in_array($account['username'], $usedAdminNames, true)) {
            $account['username'] = self::ROLE_ADMIN . ++$i;
        }

        // Generate a secure random password
        try {
            $passBytes = random_bytes(24);
        } catch (Exception $e) {
            Logger::error("CreateNewAdminAccount: Random password generation failed - " . $e->getMessage());
            throw new RuntimeException("Failed to generate admin password");
        }
        $account['password'] = rtrim(strtr(base64_encode($passBytes), '+/', '-_'), '=');

        // Create the new user in DB
        try {
            $account['id'] = self::addUserInternal(
                $account['username'],
                $account['password'],
                self::ROLE_ADMIN, // use a defined constant, not magic string
                true              // active
            );
        } catch (Throwable $e) {
            Logger::error("CreateNewAdminAccount: AddUserInternal failed for {$account['username']} - " . $e->getMessage());
            throw new RuntimeException("Failed to create admin account in DB");
        }

        if (!$account['id']) {
            Logger::error("CreateNewAdminAccount: DB returned no ID for {$account['username']}");
            throw new RuntimeException("Failed to create admin account");
        }

        Logger::info("CreateNewAdminAccount: Created {$account['username']} (ID {$account['id']})");

        return $account;
    }

    /**
     * Add a user record to the database.
     * @param string $username     User's login username (must be unique)
     * @param string $password     Plain-text password (will be hashed before saving)
     * @param string $displayName  User's display name
     * @param bool   $isAdmin      Whether the user has admin privileges
     * @return int                 The new user ID
     * @throws RuntimeException    On validation failure or DB error
     */
    public static function addUserInternal(string $username, string $password, string $displayName, bool $isAdmin = false): int
    {
        // ==== Validate input ====
        $username   = trim($username);
        $displayName = trim($displayName);

        if ($username === '' || $password === '' || $displayName === '') {
            throw new RuntimeException("Username, password, and display name are required.");
        }

        // Basic password policy (optional — can be expanded)
        if (strlen($password) < 8) {
            throw new RuntimeException("Password must be at least 8 characters long.");
        }

        // ==== Check for existing username ====
        try {
            $existing = DB::fetchOne(
                "SELECT id FROM tarallo_users WHERE username = :username",
                ['username' => $username]
            );
            if ($existing) {
                throw new RuntimeException("Username already exists.");
            }
        } catch (Throwable $e) {
            Logger::error("AddUserInternal: Failed checking for existing user '$username' - " . $e->getMessage());
            throw new RuntimeException("Internal error while checking username availability.");
        }

        // ==== Hash password ====
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // ==== Insert user ====
        $now = time();
        try {
            $userId = DB::insert(
                "INSERT INTO tarallo_users 
                 (username, password, display_name, register_time, last_access_time, is_admin)
             VALUES 
                 (:username, :password, :display_name, :register_time, 0, :is_admin)",
                [
                    'username'      => $username,
                    'password'      => $passwordHash,
                    'display_name'  => $displayName,
                    'register_time' => $now,
                    'is_admin'      => $isAdmin ? 1 : 0
                ]
            );
        } catch (Throwable $e) {
            Logger::error("AddUserInternal: DB insert failed for '$username' - " . $e->getMessage());
            throw new RuntimeException("Failed to create user.");
        }

        if (!$userId) {
            Logger::error("AddUserInternal: Insert returned no ID for '$username'");
            throw new RuntimeException("Database did not return new user ID.");
        }

        Logger::info("AddUserInternal: Created user '$username' (ID $userId)" . ($isAdmin ? ' [ADMIN]' : ''));

        return (int)$userId;
    }
}