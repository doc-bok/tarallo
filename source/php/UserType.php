<?php

/**
 * Enum representing user roles and their capabilities in boards and workspaces.
 */
enum UserType: int
{
    /**
     * Full-control.
     */
    case Owner = 0;

    /**
     * Full-control except board deletion.
     */
    case Moderator = 2;

    /**
     * Full-control except layout, permissions and options.
     */
    case Member = 6;

    /**
     * Read-only access.
     */
    case Observer = 8;

    /**
     * No access, requested to join.
     */
    case Guest = 9;

    /**
     * No access, blocked by moderator.
     */
    case Blocked = 10;

    /**
     * No access, no record in DB.
     */
    case None = 11;
}
