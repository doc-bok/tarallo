<?php

/**
 * Handles workspace-based operations.
 */
class Workspace
{
    private WorkspacePermissions $workspacePermissions;

    /**
     * Ensure we have workspace permissions set up.
     * TODO: We may end up passing permissions as an argument.
     */
    public function __construct()
    {
        $this->workspacePermissions = new WorkspacePermissions();
    }

    /**
     * Create a new workspace.
     * @param array $request The request parameters.
     * @return array The new workspace just created.
     */
    public function create(array $request): array
    {
        // Check the user is logged in.
        self::checkLoggedIn();
        $userId = (int) $_SESSION['user_id'];

        $db = DB::getInstance();
        try {
            // Check our arguments are valid.
            $this->checkValueType($request, 'name', 'string');
            $this->checkValueType($request, 'logo_id', 'int');
            $this->checkValueType($request, 'is_public', 'bool');

            // Begin a transaction.
            $db->beginTransaction();

            // Create the new workspace.
            $workspaceId = $this->createWorkspace(
                $request['name'],
                $request['logo_id'],
                $request['is_public']);

            // Assign ownership permission to current user.
            $this->workspacePermissions->createWorkspacePermissions(
                $userId,
                $workspaceId,
                UserType::Owner);

            // Add workspace-less boards. Backwards compatability so that boards
            // created before workspaces were implemented don't get lost.
            $this->addOrphanedBoards($userId, $workspaceId);

            // Commit the changes.
            $db->commit();

            // Return new workspace.
            return $this->readWorkspace($userId, $workspaceId);
        } catch (Throwable $e) {

            // Roll back changes on an error.
            $db->rollBack();

            // Log and throw for debugging.
            Logger::error("Failed to create workspace: " . $e->getMessage());
            throw new RuntimeException("Failed to create workspace.");
        }
    }

    /**
     * Read a workspace from the database.
     * @param array $request The request parameters.
     * @return array The workspace requested by the client.
     * @throws RuntimeException if the database read fails, the workspace
     *                          doesn't exist, or the user doesn't have read
     *                          permissions.
     */
    public function read(array $request): array
    {
        // Check the user is logged in.
        self::checkLoggedIn();
        $userId = (int) $_SESSION['user_id'];

        try {
            // Check our arguments are valid.
            $this->checkValueType($request, 'workspace_id', 'int');

            // Get the workspace.
            return $this->readWorkspace($userId, $request['workspace_id']);
        } catch (Throwable $e) {

            // Log and throw for debugging.
            Logger::error("Failed to read workspace: " . $e->getMessage());
            throw new RuntimeException("Failed to read workspace.");
        }
    }

    /**
     * Checks if a user is logged in.
     * TODO: This probably wants to be in a generic utility class, or checked
     *       earlier in the flow.
     * @return void
     * @throws RuntimeException if the user is not logged in.
     */
    private function checkLoggedIn(): void
    {
        // Only logged-in users can create workspaces.
        if (!Session::isUserLoggedIn()) {
            throw new RuntimeException("User is not logged in.", 403);
        }
    }

    /**
     * Create a new workspace.
     * @param string $name The name of the workspace to create.
     * @param int $logoId The ID of the logo to use for the namespace.
     * @param bool $isPublic Whether the workspace is public or not.
     * @return int The ID of the newly added workspace.
     * @throws Exception If the workspace could not be added to the database.
     * @throws InvalidArgumentException If the title is not valid.
     */
    private function createWorkspace(
        string $name,
        int    $logoId,
        bool   $isPublic): int
    {
        // Cleanup name.
        $cleanName = Utils::sanitizeString($name, 64);
        if ($cleanName === '') {
            throw new InvalidArgumentException("Workspace name cannot be empty after cleaning.");
        }

        // Generate slug.
        $originalSlug = Utils::generateSlug($cleanName);
        $slug = $originalSlug;

        // Ensure Logo ID is valid.
        $logoId = self::sanitiseLogoId($logoId); // TODO: This isn't fully implemented yet.

        // The query used to insert.
        $query = "
            INSERT INTO tarallo_workspaces(name, slug, slug_hash, logo_id, is_public)
            VALUES (:name, :slug, :slug_hash, :logo_id, :is_public)";

        $i = 1; // 1-indexed value used for slug collisions.
        while (true) {

            // Generate slug hash
            $slugHash = md5($slug);

            $params = [
                'name' => $cleanName,
                'slug' => $slug,
                'slug_hash' => $slugHash,
                'logo_id' => $logoId,
                'is_public' => $isPublic
            ];

            // Add the workspace to the database.
            try {
                $result = DB::getInstance()->insert($query, $params);

                // Success! Break out of the while loop.
                break;
            } catch (PDOException  $exception) {

                // If we have a hash collision with the slug, generate a new
                // one and try again. Otherwise, throw an exception as usual.
                if ($exception->getCode() == '23000'
                    && str_contains($exception->getMessage(), 'unique_slug_hash')) {
                    $slug = $originalSlug . '-' . $i++;
                } else {
                    throw;
                }
            }
        }

        return $result;
    }

    /**
     * Read a single workspace from the database.
     * @param int $workspaceId The ID of the workspace to retrieve.
     * @return ?array The workspace as an associative array, or NULL if the
     *                workspace doesn't exist.
     */
    private function readWorkspace(int $userId, int $workspaceId): ?array {
        $query = "
            SELECT b.*, p.user_type
            FROM tarallo_workspaces b
            INNER JOIN tarallo_workspace_permissions p ON b.id = p.workspace_id
            WHERE b.id = :workspace_id AND p.user_id = :user_id
            LIMIT 1";

        $params = [
            'user_id' => $userId,
            'workspace_id' => $workspaceId
        ];

        // Get the workspace.
        $result = DB::getInstance()->fetchRow($query, $params);

        // Check we found a workspace.
        if (!$result) {
            Logger::error("Workspace [$workspaceId] not found.");
            throw new RuntimeException("Workspace [$workspaceId] not found.");
        }

        // Check the user isn't blocked.
        $userType = UserType::from($result['user_type']);
        if ($userType === UserType::Blocked) {
            Logger::error("User [$userId] is blocked from workspace [$workspaceId].");
            throw new RuntimeException("User [$userId] is blocked from workspace [$workspaceId].");
        }

        // Check the user has read permissions.
        if (!$result['is_public'] && $userType > UserType::Observer) {
            Logger::error("User [$userId] doesn't have permission to read workspace [$workspaceId].");
            throw new RuntimeException("User [$userId] doesn't have permission to read workspace [$workspaceId].");
        }

        // Success! Return the result.
        return $result;
    }

    /**
     * Ensure the Logo ID references an actual logo.
     * TODO: This is placeholder to be updated when logos are implemented.
     * TODO: This might be moved to a Logo class.
     * @param int $id The Logo ID.
     * @return int The Logo ID if valid, otherwise zero.
     */
    private function sanitiseLogoId(int $id): int
    {

        // Zero means use the default logo.
        if ($id < 1) {
            return 0;
        }

        // TODO: Check there is an entry for the specified logo ID

        return $id;
    }

    /**
     * Adds any orphaned boards belonging to the user to the new workspace.
     * This is so that people who created boards before workspaces existed can
     * still use their old boards.
     * @param int $userId The ID of the user the boards should belong to.
     * @param int $workspaceId The ID of the workspace to add them to.
     * @return void
     * @throws RuntimeException if we fail to update the boards' owner.
     */
    private function addOrphanedBoards(int $userId, int $workspaceId): void
    {
        $query = "
            UPDATE tarallo_boards b
            INNER JOIN tarallo_permissions p ON b.id = p.board_id
            SET b.workspace_id = :workspace_id
            WHERE b.workspace_id = 0
            AND p.user_id = :user_id
            AND p.user_type = :user_type";

        $params = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'user_type' => UserType::Owner->value
        ];

        $numAffectedRows = DB::getInstance()->run($query, $params);
        Logger::info("addOrphanedBoards: Updated [$numAffectedRows] orphaned boards for user [$userId] into workspace [$workspaceId].");
    }

    /**
     * Checks if a given key exists in an array and the value is of the expected type.
     * TODO: This would be better in a utility class of some kind.
     * @param array $input The input array to check.
     * @param string $key The key to look for.
     * @param string $type The expected type, e.g. 'string', 'int', 'bool', 'array', 'object', or a class/interface name.
     * @throws InvalidArgumentException if the value is not the correct type.
     */
    private function checkValueType(array $input, string $key, string $type): void {
        if (!array_key_exists($key, $input)) {
            Logger::error("Workspace key [$key] not found.");
            throw new InvalidArgumentException("Workspace key [$key] not found.");
        }

        $value = $input[$key];

        if ($type === 'int' && is_int($value)) {
            return;
        }

        if ($type === 'float' && is_float($value)) {
            return;
        }

        if ($type === 'string' && is_string($value)) {
            return;
        }

        if ($type === 'bool' && is_bool($value)) {
            return;
        }

        if ($type === 'array' && is_array($value)) {
            return;
        }

        if ($type === 'object' && is_object($value)) {
            return;
        }

        // Check if type is a class or interface and value is instance of it
        if (
            class_exists($type)
            || interface_exists($type)
            && $value instanceof $type) {
            return;
        }

        Logger::error("Value [$value] is not of type [$type].");
        throw new InvalidArgumentException("Value [$value] is not of type [$type].");
    }
}