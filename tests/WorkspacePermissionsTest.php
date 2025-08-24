<?php

declare(strict_types=1);
require_once __DIR__ . '/../source/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Contains tests for the utility class.
 */
#[CoversClass(WorkspacePermissions::class)]
class WorkspacePermissionsTest extends TestCase
{
    // Setup for each test
    protected function setUp(): void
    {
        // Load test environment file.
        $dotenv = Dotenv::createImmutable(
            __DIR__ . '/../source',
            [
                '.env.test'
            ]);

        $dotenv->safeLoad();

        // Ensure clean table
        DB::getInstance()->beginTransaction();
        DB::getInstance()->run('DELETE FROM tarallo_workspace_permissions');
        DB::getInstance()->commit();

        // Reset auto_increment
        DB::getInstance()->run('ALTER TABLE tarallo_workspace_permissions AUTO_INCREMENT = 1');
    }

    /**
     * Test that create inserts a record properly.
     */
    public function testCreateInsertsPermissionRecord()
    {
        $workspacePermissions = new WorkspacePermissions();

        $workspaceId = 1;
        $userId = 2;
        $role = UserType::Moderator;

        $workspacePermissions->create($workspaceId, $userId, $role);

        DB::getInstance()->beginTransaction();
        $record = DB::getInstance()->fetchRow("SELECT * FROM tarallo_workspace_permissions WHERE workspace_id = :workspace_id AND user_id = :user_id", [
            'workspace_id' => $workspaceId,
            'user_id' => $userId
        ]);
        DB::getInstance()->commit();

        $this->assertNotNull($record);
        $this->assertEquals($role->value, $record['user_type']);
    }
}
