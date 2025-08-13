<?php

declare(strict_types=1);

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
}