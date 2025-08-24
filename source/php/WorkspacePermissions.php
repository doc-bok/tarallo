<?php

/**
 * Class to handle workspace permissions options.
 */
class WorkspacePermissions
{
    private const TABLE_NAME = 'tarallo_workspace_permissions';
    private DB $db;

    public function __construct(DB $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new workspace permissions entry. Note this method doesn't catch
     * exceptions or handle transactions.
     * @param int $userId The ID of the user.
     * @param int $workspaceId The ID of the workspace.
     * @param UserType $role The role of the user.
     * @return int The last insert ID.
     * @throws Exception if the database fails to update.
     */
    public function create(int $userId, int $workspaceId, UserType $role) : int {

        // Set up the parameters.
        $params = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'user_type' => $role->value
        ];

        // Add the workspace to the database.
        return $this->db->insertV2(self::TABLE_NAME, $params);
    }

    /**
     * Get the permissions the specified user has for a particular workspace.
     * @param int $userId The ID of the user.
     * @param int $workspaceId The ID of the workspace.
     * @return UserType The user type of the user for the specified workspace.
     */
    public function read(int $userId, int $workspaceId) : UserType {
        $query = "
            SELECT * FROM tarallo_workspace_permissions
            WHERE workspace_id = :workspace_id AND user_id = :user_id
            LIMIT 1";

        $params = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId
        ];

        $row =  $this->db->fetchRowV2($query, $params);
        if ($row === null) {
            Logger::warning("Could not find permissions for user [$userId] on workspace [$workspaceId].");
            return UserType::None;
        }

        return UserType::from((int)$row['user_type']);
    }

    /**
     * Delete permissions for the specified workspace.
     * @param int $workspaceId The ID of the workspace being deleted.
     * @return void
     */
    public function delete(int $workspaceId) : void
    {
        $where = "workspace_id = :workspace_id";
        $params = [
            'workspace_id' => $workspaceId
        ];

        $rowsAffected = $this->db->delete(self::TABLE_NAME, $where, $params);
        Logger::info("Deleted [$rowsAffected] permission entries for workspace [$workspaceId].");
    }
}