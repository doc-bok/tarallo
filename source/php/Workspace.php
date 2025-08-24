<?php

/**
 * Handles workspace-based operations.
 */
class Workspace
{
    private const TABLE_NAME = 'tarallo_workspaces';
    private DB $db;
    private WorkspacePermissions $workspacePermissions;

    /**
     * Ensure we have workspace permissions set up.
     * TODO: Switch to a DI model.
     */
    public function __construct()
    {
        $this->db = DB::getInstance();
        $this->workspacePermissions = new WorkspacePermissions($this->db);
    }

    /**
     * Create a new workspace.
     * @param array $request The request parameters.
     * @return array The new workspace just created.
     */
    public function create(array $request): array
    {        
        try {
            // Check the user is logged in.
            self::checkLoggedIn();
            $userId = (int) $_SESSION['user_id'];
            
            // Check our arguments are valid.
            $this->checkValueType($request, 'name', 'string');
            $this->checkValueType($request, 'logo_id', 'int');
            $this->checkValueType($request, 'is_public', 'bool');

            // Begin a transaction.
            $this->db->beginTransaction();

            // Create the new workspace.
            $workspaceId = $this->createWorkspace(
                $request['name'],
                $request['logo_id'],
                $request['is_public']);

            // Assign ownership permission to current user.
            $this->workspacePermissions->create(
                $userId,
                $workspaceId,
                UserType::Owner);

            // Add workspace-less boards. Backwards compatability so that boards
            // created before workspaces were implemented don't get lost.
            $this->addOrphanedBoards($userId, $workspaceId);

            // Commit the changes.
            $this->db->commit();

            // Return new workspace.
            return $this->readWorkspace($workspaceId);
        } catch (Throwable $e) {

            // Roll back changes on an error.
            $this->db->rollBack();

            // Log and throw for debugging.
            Logger::error("Failed to create workspace: " . $e->getMessage());
            throw new RuntimeException("Failed to create workspace.", 500);
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
        try {
            // Check the user is logged in.
            self::checkLoggedIn();
            $userId = (int) $_SESSION['user_id'];
            
            // Check our arguments are valid.
            $this->checkValueType($request, 'workspace_id', 'int');
            $workspaceId = (int) $request['workspace_id'];

            // Check user isn't blocked.
            $userType = $this->workspacePermissions->read($userId, $workspaceId);
            if ($userType === UserType::Blocked) {
                Logger::error("User [$userId] is blocked from workspace [$workspaceId].");
                throw new RuntimeException("User [$userId] is blocked from workspace [$workspaceId].", 403);
            }

            // Read the workspace.
            $result = $this->readWorkspace($workspaceId);

            // Check the user has read permissions.
            if (!$result['is_public'] && $userType > UserType::Observer) {
                Logger::error("User [$userId] doesn't have permission to read workspace [$workspaceId].");
                throw new RuntimeException("User [$userId] doesn't have permission to read workspace [$workspaceId].", 403);
            }

            // Get the workspace.
            return $result;
        } catch (Throwable $e) {

            // Log and throw for debugging.
            Logger::error("Failed to read workspace: " . $e->getMessage());
            throw new RuntimeException("Failed to read workspace.", 500);
        }
    }

    /**
     * Updates a workspace's title.
     * @param array $request The request parameters.
     * @return void
     */
    public function updateTitle(array $request): void
    {
        try {
            // Check session exists.
            self::checkLoggedIn();
            $userId = (int) $_SESSION['user_id'];

            // Check parameters.
            $this->checkValueType($request, 'workspace_id', 'int');
            $this->checkValueType($request, 'title', 'string');

            $workspaceId = (int) $request['workspace_id'];
            $title = (string) $request['title'];

            // Check permissions.
            $userType = $this->workspacePermissions->read($userId, $workspaceId);
            if ($userType > UserType::Member) {
                throw new RuntimeException("User [$userId] does not have permission to update workspace [$workspaceId].", 403);
            }

            // Begin a transaction.
            $this->db->beginTransaction();

            // Update the title.
            $this->updateWorkspaceTitle($workspaceId, $title);

            // Commit the changes.
            $this->db->commit();

        } catch (Throwable $e) {

            // Roll back changes on an error.
            $this->db->rollBack();

            // Log and throw for debugging.
            Logger::error("Failed to update workspace title: " . $e->getMessage());
            throw new RuntimeException("Failed to update workspace title.", 500);
        }
    }

    /**
     * Updates a workspace's logo.
     * @param array $request The request parameters.
     * @return void
     */
    public function updateLogo(array $request): void
    {
        try {
            // Check session exists.
            self::checkLoggedIn();
            $userId = (int) $_SESSION['user_id'];

            // Check parameters.
            $this->checkValueType($request, 'workspace_id', 'int');
            $this->checkValueType($request, 'logo_id', 'int');

            $workspaceId = (int) $request['workspace_id'];
            $logoId = (int) $request['logo_id'];

            // Check permissions.
            $userType = $this->workspacePermissions->read($userId, $workspaceId);
            if ($userType > UserType::Member) {
                throw new RuntimeException("User [$userId] does not have permission to update workspace [$workspaceId].", 403);
            }

            // Begin a transaction.
            $this->db->beginTransaction();

            // Update the title.
            $this->updateWorkspaceLogo($workspaceId, $logoId);

            // Commit the changes.
            $this->db->commit();

        } catch (Throwable $e) {

            // Roll back changes on an error.
            $this->db->rollBack();

            // Log and throw for debugging.
            Logger::error("Failed to update workspace logo: " . $e->getMessage());
            throw new RuntimeException("Failed to update workspace logo.", 500);
        }
    }

    /**
     * Updates a workspace's publicity.
     * @param array $request The request parameters.
     * @return void
     */
    public function updateIsPublic(array $request): void
    {
        try {
            // Check session exists.
            self::checkLoggedIn();
            $userId = (int) $_SESSION['user_id'];

            // Check parameters.
            $this->checkValueType($request, 'workspace_id', 'int');
            $this->checkValueType($request, 'is_public', 'bool');

            $workspaceId = (int) $request['workspace_id'];
            $isPublic = (bool) $request['is_public'];

            // Check permissions.
            $userType = $this->workspacePermissions->read($userId, $workspaceId);
            if ($userType > UserType::Member) {
                throw new RuntimeException("User [$userId] does not have permission to update workspace [$workspaceId].", 403);
            }

            // Begin a transaction.
            $this->db->beginTransaction();

            // Update the title.
            $this->updateWorkspaceIsPublic($workspaceId, $isPublic);

            // Commit the changes.
            $this->db->commit();

        } catch (Throwable $e) {

            // Roll back changes on an error.
            $this->db->rollBack();

            // Log and throw for debugging.
            Logger::error("Failed to update workspace publicity: " . $e->getMessage());
            throw new RuntimeException("Failed to update workspace publicity.", 500);
        }
    }

    /**
     * Delete the specified workspace.
     * @param array $request The request parameters.
     * @return void
     */
    public function delete(array $request): void
    {
        try {
            self::checkLoggedIn();
            $userId = (int) $_SESSION['user_id'];
            
            // Check our arguments are valid.
            $this->checkValueType($request, 'workspace_id', 'int');
            $workspaceId = (int) $request['workspace_id'];

            // Check ownership.
            $userType = $this->workspacePermissions->read($userId, $workspaceId);
            if ($userType !== UserType::Owner) {
                throw new RuntimeException("User [$userId] does not have permission to delete workspace [$workspaceId].", 403);
            }

            // Begin a transaction.
            $this->db->beginTransaction();

            // Delete the workspace.
            $this->deleteWorkspace($workspaceId);

            // Delete the permissions.
            $this->workspacePermissions->delete($workspaceId);

            // Commit the changes.
            $this->db->commit();
        } catch (Throwable $e) {

            // Roll back changes on an error.
            $this->db->rollBack();

            // Log and throw for debugging.
            Logger::error("Failed to delete workspace: " . $e->getMessage());
            throw new RuntimeException("Failed to delete workspace.", 500);
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
            throw new InvalidArgumentException("Workspace name cannot be empty after cleaning.", 400);
        }

        // Generate slug.
        $originalSlug = Utils::generateSlug($cleanName);
        $slug = $originalSlug;

        // Ensure Logo ID is valid.
        $logoId = self::sanitizeLogoId($logoId); // TODO: This isn't fully implemented yet.

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
                return $this->db->insertV2(self::TABLE_NAME, $params);
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
    }

    /**
     * Read a single workspace from the database.
     * @param int $workspaceId The ID of the workspace to retrieve.
     * @return ?array The workspace as an associative array, or NULL if the
     *                workspace doesn't exist.
     */
    private function readWorkspace(int $workspaceId): ?array {
        $query = "
            SELECT *
            FROM tarallo_workspaces
            WHERE id = :workspace_id
            LIMIT 1";

        $params = [
            'workspace_id' => $workspaceId
        ];

        // Get the workspace.
        $result = $this->db->fetchRowV2($query, $params);

        // Check we found a workspace.
        if (!$result) {
            Logger::error("Workspace [$workspaceId] not found.");
            throw new RuntimeException("Workspace [$workspaceId] not found.", 404);
        }

        // Success! Return the result.
        return $result;
    }

    /**
     * Update the workspace's title. This does not update the slug so that
     * links don't break.
     * @param int $workspaceId The ID of the workspace to update.
     * @param string $title The new title.
     * @return void
     */
    private function updateWorkspaceTitle(int $workspaceId, string $title): void {
        $cleanTitle = Utils::sanitizeString($title, 64);

        $params = ['title' => $cleanTitle];
        $where = "id = :workspace_id";
        $whereParams = ['workspace_id' => $workspaceId];

        $numRowsAffected = $this->db->update(self::TABLE_NAME, $params, $where, $whereParams);
        Logger::info("Update title on [$numRowsAffected] to [$title] workspaces with ID [$workspaceId].");
    }

    /**
     * Update the logo on a board, ensuring the Logo ID is valid.
     * @param int $workspaceId The ID of the workspace.
     * @param int $logoId The ID of the logo.
     * @return void
     */
    private function updateWorkspaceLogo(int $workspaceId, int $logoId): void {
        $cleanLogoId = $this->sanitizeLogoId($logoId);

        $params = ['logo_id' => $cleanLogoId];
        $where = "id = :workspace_id";
        $whereParams = ['workspace_id' => $workspaceId];

        $numRowsAffected = $this->db->update(self::TABLE_NAME, $params, $where, $whereParams);
        Logger::info("Updated logo on [$numRowsAffected] workspaces with ID [$workspaceId].");
    }

    /**
     * Update a board's privacy.
     * @param int $workspaceId The ID of the workspace.
     * @param bool $isPublic True if the board is to be made public, otherwise
     *                       false.
     * @return void
     */
    private function updateWorkspaceIsPublic(int $workspaceId, bool $isPublic): void {

        $params = ['is_public' => $isPublic];
        $where = "id = :workspace_id";
        $whereParams = ['workspace_id' => $workspaceId];

        $numRowsAffected = $this->db->update(self::TABLE_NAME, $params, $where, $whereParams);
        Logger::info("Updated public to [$isPublic] on [$numRowsAffected] workspaces with ID [$workspaceId].");
    }

    /**
     * Deletes the specified workspace. This operation is non-reversible.
     * @param int $workspaceId The ID of the workspace to delete.
     * @return void
     */
    private function deleteWorkspace(int $workspaceId): void {
        $where = "id = :workspace_id";
        $params = ['workspace_id' => $workspaceId];

        // Delete the workspace.
        $numRowsAffected = $this->db->delete(self::TABLE_NAME, $where, $params);
        Logger::info("Deleted [$numRowsAffected] workspaces with ID [$workspaceId].");
    }

    /**
     * Adds any orphaned boards belonging to the user to the new workspace.
     * This is so that people who created boards before workspaces existed can
     * still access their old boards.
     * @param int $userId The ID of the user the boards should belong to.
     * @param int $workspaceId The ID of the workspace to add them to.
     * @return void
     * @throws RuntimeException if we fail to update the boards' owner.
     */
    private function addOrphanedBoards(int $userId, int $workspaceId): void
    {
        $sql = "
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

        $result = $this->db->queryV2($sql, $params);
        $rowsAffected = $this->db->rowCount($result);
        Logger::info("Added [$rowsAffected] orphan boards to workspace [$workspaceId].");
    }

    /**
     * Ensure the Logo ID references an actual logo.
     * TODO: This is placeholder to be updated when logos are implemented.
     * TODO: This might be moved to a Logo class.
     * @param int $id The Logo ID.
     * @return int The Logo ID if valid, otherwise zero.
     */
    private function sanitizeLogoId(int $id): int
    {
        // Zero means use the default logo, so is always valid.
        if ($id < 1) {
            return 0;
        }

        // TODO: Check there is an entry for the specified logo ID. For now
        //       force all IDs to zero.
        return 0;
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
            throw new InvalidArgumentException("Workspace key [$key] not found.", 400);
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
            (class_exists($type) || interface_exists($type))
            && $value instanceof $type
        ) {
            return;
        }

        Logger::error("Value [$value] is not of type [$type].");
        throw new InvalidArgumentException("Value [$value] is not of type [$type].", 400);
    }
}