<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Permission
{
    /**
     * Checks if a user's role meets the required role.
     * @param int  $userType           The user's role constant.
     * @param int  $requiredUserType   The minimum required role constant.
     * @param bool $throwIfFailed      Whether to throw on failure (default: true).
     * @return bool                    True if allowed, false if not (when not throwing).
     * @throws ApiException        If $throwIfFailed is true and permission denied.
     */
    public static function CheckPermissions(UserType $userType, UserType $requiredUserType, bool $throwIfFailed = true): bool
    {
        // Lower numeric value means higher privilege in your enum, so invert sense if needed
        $hasPermission = ($userType->value <= $requiredUserType->value);

        if (!$hasPermission) {
            if ($throwIfFailed) {
                throw new ApiException("Missing permissions to perform the requested operation.", 403);
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
     * @throws ApiException On DB error.
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
        $boardData = Board::GetBoardData($boardID, UserType::Moderator);

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
            $permissions = DB::getInstance()->fetchTable($sql, ['board_id' => $boardID]);
        } catch (Throwable $e) {
            Logger::error("getBoardPermissions: DB error for board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to fetch board permissions");
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
     * @throws ApiException On permission denial or DB error
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
        $boardData = Board::GetBoardData($boardID, UserType::Moderator);

        $isSpecialPermission = ($targetUserID < 0);

        if ($isSpecialPermission) {
            if (empty($_SESSION['is_admin'])) {
                throw new ApiException("Special permissions are only available to site admins", 403);
            }
            if ($targetUserID < Account::USER_ID_MIN) {
                throw new InvalidArgumentException("Invalid special permission user ID");
            }
        }

        if ($targetUserID === (int)($_SESSION['user_id'] ?? 0)) {
            throw new ApiException("Cannot edit your own permissions", 400);
        }

        // Prevent assigning equal/higher permissions than your own
        if ($userType <= (int)$boardData['user_type']) {
            throw new ApiException("Cannot assign this level of permission", 403);
        }

        // Fetch current permission if exists
        $permission = DB::getInstance()->fetchRow(
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
                throw new ApiException("No permission record found for the specified user", 404);
            }
            if ((int)$permission['user_type'] <= (int)$boardData['user_type']) {
                throw new ApiException("Cannot edit permissions for this user", 403);
            }
        }

        try {
            if ($permission) {
                // Update existing
                DB::getInstance()->query(
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
                DB::getInstance()->query(
                    "INSERT INTO tarallo_permissions (user_id, board_id, user_type)
                 VALUES (:user_id, :board_id, :user_type)",
                    [
                        'user_id'   => $targetUserID,
                        'board_id'  => $boardID,
                        'user_type' => $userType
                    ]
                );
            }

            Board::updateBoardModifiedTime($boardID);
        } catch (Throwable $e) {
            Logger::error("SetUserPermission: Failed updating $targetUserID on board $boardID - " . $e->getMessage());
            throw new ApiException("Database error while updating user permission");
        }

        // Return updated permission row
        $updated = DB::getInstance()->fetchRow(
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
     * @throws ApiException If request is invalid or DB error.
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
            throw new ApiException("Must be logged in to request access", 403);
        }

        // Check board access
        $sql = "
        SELECT user_type
        FROM tarallo_permissions
        WHERE board_id = :board_id AND user_id = :user_id
        LIMIT 1
    ";
        $userId = (int) $_SESSION['user_id'];

        $permissionRecord = DB::getInstance()->fetchRow($sql, [
            'board_id' => $boardID,
            'user_id'  => $userId
        ]);

        $userType = $permissionRecord === null ? UserType::None : $permissionRecord['user_type'];

        // Already has Guest or higher → can't request again
        if ($userType != UserType::None) {
            throw new ApiException("User already has blocked or higher access to this board", 400);
        }

        // Decide insert vs update
        $params = [
            'user_id'   => $userID,
            'board_id'  => $boardID,
            'user_type' => UserType::Guest
        ];

        $sql = $userType === UserType::None
            ? "INSERT INTO tarallo_permissions (user_id, board_id, user_type) VALUES (:user_id, :board_id, :user_type)"
            : "UPDATE tarallo_permissions SET user_type = :user_type WHERE user_id = :user_id AND board_id = :board_id";

        try {
            DB::getInstance()->query($sql, $params);
            Logger::info("Board $boardID: user $userID granted guest access");
        } catch (Throwable $e) {
            Logger::error("RequestBoardAccess: Failed for user $userID on board $boardID - " . $e->getMessage());
            throw new ApiException("Failed to request board access");
        }

        return ['access_requested' => true];
    }

}