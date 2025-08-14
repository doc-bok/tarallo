<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

class Account
{
    // special user IDs
    const USER_ID_ONREGISTER = -1; // if a permission record on the permission table has this user_id, the permission will be copied to any new registered user
    const USER_ID_MIN = self::USER_ID_ONREGISTER; // this should be the minimun special user ID

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

    /**
     * Register a new user.
     * @param array $request The request parameters.
     * @return array|string[] The result of the register attempt.
     */
    public static function register(array $request): array
    {
        Session::ensureSession();

        $settings = DB::getDBSettings();
        if (empty($settings['registration_enabled'])) {
            Logger::warning("Register: Registration disabled");
            http_response_code(403);
            return ['error' => 'Account creation disabled on this server'];
        }

        if (Session::isUserLoggedIn()) {
            Logger::info("Register: Existing session detected, logging out first");
            Session::logoutInternal();
        }

        $username     = trim($request['username'] ?? '');
        $displayName  = trim($request['display_name'] ?? '');
        $password     = $request['password'] ?? '';

        // Username validation
        if (strlen($username) < 5) {
            http_response_code(400);
            return ['error' => 'Username is too short'];
        }
        if (!preg_match('/^[A-Za-z0-9]+$/', $username)) {
            http_response_code(400);
            return ['error' => 'Username must be alphanumeric and contain no spaces'];
        }
        // Display name validation
        if (strlen($displayName) < 3) {
            http_response_code(400);
            return ['error' => 'Display name is too short'];
        }
        if (!preg_match('/^[A-Za-z0-9\s]+$/', $displayName)) {
            http_response_code(400);
            return ['error' => 'Display name must be alphanumeric'];
        }
        // Password validation
        if (strlen($password) < 5) {
            http_response_code(400);
            return ['error' => 'Password must be at least 5 characters'];
        }

        // Prevent duplicate username
        if (self::UsernameExists($username)) {
            http_response_code(400);
            return ['error' => 'Username already in use'];
        }

        // Create user
        $userId = Account::addUserInternal($username, $password, $displayName);
        if (!$userId) {
            Logger::error("Register: Failed to create user '$username'");
            http_response_code(500);
            return ['error' => 'Internal server error while creating user'];
        }

        Logger::info("Register: Created new user '$username' with ID $userId");

        // Apply initial permissions
        $initialPerms = DB::fetchTable(
            "SELECT * FROM tarallo_permissions WHERE user_id = :id",
            ['id' => self::USER_ID_ONREGISTER]
        );
        foreach ($initialPerms as $perm) {
            if ($perm['user_type'] == Permission::USERTYPE_Blocked) continue;
            DB::query(
                "INSERT INTO tarallo_permissions (user_id, board_id, user_type) VALUES (:uid, :bid, :ut)",
                ['uid' => $userId, 'bid' => $perm['board_id'], 'ut' => $perm['user_type']]
            );
        }

        return [
            'success'  => true,
            'username' => $username
        ];
    }

    /**
     * Check whether a username already exists in the database.
     *
     * @param string $username Username to check
     * @return bool            True if the username exists, false otherwise
     * @throws RuntimeException On invalid input or DB error
     */
    public static function UsernameExists(string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException("Username cannot be empty.");
        }

        try {
            $count = DB::fetchOne(
                "SELECT COUNT(*) FROM tarallo_users WHERE username = :username",
                ['username' => $username]
            );
        } catch (Throwable $e) {
            Logger::error("UsernameExists: DB error while checking '$username' - " . $e->getMessage());
            throw new RuntimeException("Database error while checking username availability");
        }

        return ((int) $count) > 0;
    }
}