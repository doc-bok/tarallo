<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Permission
{
    // user types for the permission table
    const USERTYPE_Owner = 0; // full-control of the board
    const USERTYPE_Moderator = 2; // full-control of the board, except a few functionalities like board permanent deletion
    const USERTYPE_Member = 6; // full-control of the cards but no access to board layout and options
    const USERTYPE_Observer = 8; // read-only access to the board
    const USERTYPE_Guest = 9; // no access, but user requested to join the board
    const USERTYPE_Blocked = 10; // no access (blocked by a board moderator)
    const USERTYPE_None = 11; // no access (no record on db)

    /**
     * Checks if a user's role meets the required role.
     * @param int  $userType           The user's role constant.
     * @param int  $requiredUserType   The minimum required role constant.
     * @param bool $throwIfFailed      Whether to throw on failure (default: true).
     * @return bool                    True if allowed, false if not (when not throwing).
     * @throws RuntimeException        If $throwIfFailed is true and permission denied.
     */
    public static function CheckPermissions(int $userType, int $requiredUserType, bool $throwIfFailed = true): bool
    {
        // Lower numeric value means higher privilege in your enum, so invert sense if needed
        $hasPermission = ($userType <= $requiredUserType);

        if (!$hasPermission) {
            if ($throwIfFailed) {
                throw new RuntimeException("Missing permissions to perform the requested operation.", 403);
            }
            return false;
        }

        return true;
    }

    /**
     * Retrieve a board's permission list for moderators/admins.
     * @param array $request Must contain 'board_id' (int).
     * @return array Board data with 'permissions' and 'is_admin' fields added.
     * @throws InvalidArgumentException On invalid board ID.
     * @throws RuntimeException On DB error.
     */
    public static function getBoardPermissions(array $request): array
    {
        if (!isset($request['board_id']) || !is_numeric($request['board_id'])) {
            throw new InvalidArgumentException("Missing or invalid board_id");
        }
        $boardID = (int) $request['board_id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID: $boardID");
        }

        // Permission check — must be at least Moderator
        $boardData = Board::GetBoardData($boardID, Permission::USERTYPE_Moderator);

        $sql = <<<SQL
        SELECT p.user_id,
               u.display_name,
               p.user_type
        FROM tarallo_permissions p
        LEFT JOIN tarallo_users u
               ON p.user_id = u.id
        WHERE p.board_id = :board_id
    SQL;

        try {
            $permissions = DB::fetchTable($sql, ['board_id' => $boardID]);
        } catch (Throwable $e) {
            Logger::error("getBoardPermissions: DB error for board $boardID - " . $e->getMessage());
            throw new RuntimeException("Failed to fetch board permissions");
        }

        $boardData['permissions'] = $permissions ?: [];
        $boardData['is_admin']    = !empty($_SESSION['is_admin']);

        return $boardData;
    }

    /**
     * Set or update a user's permission for a board.
     * @param array $request Must contain 'board_id', 'user_id', 'user_type'
     * @return array Updated permission row
     * @throws InvalidArgumentException On invalid parameters
     * @throws RuntimeException On permission denial or DB error
     */
    public static function setUserPermission(array $request): array
    {
        foreach (['board_id', 'user_id', 'user_type'] as $key) {
            if (!isset($request[$key])) {
                throw new InvalidArgumentException("Missing parameter: $key");
            }
        }

        $boardID   = (int)$request['board_id'];
        $targetUserID = (int)$request['user_id'];
        $userType  = (int)$request['user_type'];

        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID");
        }

        // Ensure acting user is at least Moderator on this board
        $boardData = Board::GetBoardData($boardID, Permission::USERTYPE_Moderator);

        $isSpecialPermission = ($targetUserID < 0);

        if ($isSpecialPermission) {
            if (empty($_SESSION['is_admin'])) {
                throw new RuntimeException("Special permissions are only available to site admins", 403);
            }
            if ($targetUserID < Account::USER_ID_MIN) {
                throw new InvalidArgumentException("Invalid special permission user ID");
            }
        }

        if ($targetUserID === (int)($_SESSION['user_id'] ?? 0)) {
            throw new RuntimeException("Cannot edit your own permissions", 400);
        }

        // Prevent assigning equal/higher permissions than your own
        if ($userType <= (int)$boardData['user_type']) {
            throw new RuntimeException("Cannot assign this level of permission", 403);
        }

        // Fetch current permission if exists
        $permission = DB::fetchRow(
            "SELECT user_id, user_type 
           FROM tarallo_permissions 
          WHERE board_id = :board_id AND user_id = :user_id",
            [
                'board_id' => $boardID,
                'user_id'  => $targetUserID
            ]
        );

        if (!$isSpecialPermission) {
            if (!$permission) {
                throw new RuntimeException("No permission record found for the specified user", 404);
            }
            if ((int)$permission['user_type'] <= (int)$boardData['user_type']) {
                throw new RuntimeException("Cannot edit permissions for this user", 403);
            }
        }

        try {
            if ($permission) {
                // Update existing
                DB::query(
                    "UPDATE tarallo_permissions
                    SET user_type = :user_type
                  WHERE board_id = :board_id AND user_id = :user_id",
                    [
                        'user_type' => $userType,
                        'board_id'  => $boardID,
                        'user_id'   => $targetUserID
                    ]
                );
            } else {
                // Insert new (only allowed for special permissions at this point)
                DB::query(
                    "INSERT INTO tarallo_permissions (user_id, board_id, user_type)
                 VALUES (:user_id, :board_id, :user_type)",
                    [
                        'user_id'   => $targetUserID,
                        'board_id'  => $boardID,
                        'user_type' => $userType
                    ]
                );
            }

            DB::UpdateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            Logger::error("SetUserPermission: Failed updating $targetUserID on board $boardID - " . $e->getMessage());
            throw new RuntimeException("Database error while updating user permission");
        }

        // Return updated permission row
        $updated = DB::fetchRow(
            "SELECT user_id, user_type 
           FROM tarallo_permissions 
          WHERE board_id = :board_id AND user_id = :user_id",
            [
                'board_id' => $boardID,
                'user_id'  => $targetUserID
            ]
        );

        return $updated ?: [];
    }

    /**
     * Requests guest-level access to a board for the current user.
     * @param array $request Must contain 'board_id' (int).
     * @return array ['access_requested' => bool]
     * @throws InvalidArgumentException On invalid input.
     * @throws RuntimeException If request is invalid or DB error.
     */
    public static function requestBoardAccess(array $request): array
    {
        // Validate parameters
        if (!isset($request['board_id']) || !is_numeric($request['board_id'])) {
            throw new InvalidArgumentException("Missing or invalid board_id");
        }
        $boardID = (int)$request['board_id'];
        if ($boardID <= 0) {
            throw new InvalidArgumentException("Invalid board ID");
        }

        // Require logged-in user
        $userID = $_SESSION['user_id'] ?? null;
        if (!$userID) {
            throw new RuntimeException("Must be logged in to request access", 403);
        }

        // Check board access
        $sql = "
        SELECT user_type
        FROM tarallo_permissions
        WHERE board_id = :board_id AND user_id = :user_id
        LIMIT 1
    ";
        $userId = (int) $_SESSION['user_id'];

        $permissionRecord = DB::fetchRow($sql, [
            'board_id' => $boardID,
            'user_id'  => $userId
        ]);

        $userType = $permissionRecord === null ? self::USERTYPE_None : $permissionRecord['user_type'];

        // Already has Guest or higher → can't request again
        if ($userType != self::USERTYPE_None) {
            throw new RuntimeException("User already has blocked or higher access to this board", 400);
        }

        // Decide insert vs update
        $params = [
            'user_id'   => $userID,
            'board_id'  => $boardID,
            'user_type' => self::USERTYPE_Guest
        ];

        $sql = $userType === self::USERTYPE_None
            ? "INSERT INTO tarallo_permissions (user_id, board_id, user_type) VALUES (:user_id, :board_id, :user_type)"
            : "UPDATE tarallo_permissions SET user_type = :user_type WHERE user_id = :user_id AND board_id = :board_id";

        try {
            DB::query($sql, $params);
            Logger::info("Board $boardID: user $userID granted guest access");
        } catch (Throwable $e) {
            Logger::error("RequestBoardAccess: Failed for user $userID on board $boardID - " . $e->getMessage());
            throw new RuntimeException("Failed to request board access");
        }

        return ['access_requested' => true];
    }

}