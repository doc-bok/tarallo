<?php

/**
 * Class to handle workspace permissions options.
 */
class WorkspacePermissions
{
    /**
     * Create a new workspace permissions entry. Note this method doesn't catch
     * exceptions or handle transactions.
     * @param int $userId The ID of the user.
     * @param int $workspaceId The ID of the workspace.
     * @param UserType $role The role of the user.
     * @return void
     * @throws Exception if the database fails to update.
     */
    public function createWorkspacePermissions(int $userId, int $workspaceId, UserType $role) : void {
        $query = "
            INSERT INTO tarallo_workspace_permissions(workspace_id, user_id, user_type)
            VALUES (:workspace_id, :user_id, :user_type)";

        $params = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'user_type' => $role->value
        ];

        // Add the workspace to the database.
        DB::getInstance()->insert($query, $params);
    }
}