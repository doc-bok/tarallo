<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Page
{
    const DEFAULT_BG = "images/tarallo-bg.jpg";

    private API $api;
    private DB $db;

    /**
     * Construction - Register our operations.
     * @param Api $api The main API class.
     * @param DB $db The database wrapper.
     */
    public function __construct(Api $api, DB $db)
    {
        $this->api = $api;
        $this->db = $db;

        $this->api->registerOperation('GET', 'GetCurrentPage', [$this, 'getCurrentPage']);
    }

    /**
     * Request the page that should be displayed for the current state.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    public function getCurrentPage(array $request): array
    {
        try {
            // Ensure DB exists or initialise
            $this->db->initDatabaseIfNeeded();

            // Logged in?
            if (isset($_SESSION['logged_in'])) {
                return self::getLoggedInPage($request);
            }

            // Logged out flow
            return self::getLoggedOutPage();

        } catch (Throwable $e) {
            Logger::error("GetCurrentPage: Unhandled exception - " . $e->getMessage());
            http_response_code($e->getCode());
            return [
                'page_name' => 'Error',
                'page_content' => ['message' => $e->getMessage()]
            ];
        }
    }

    /**
     * Get the page for a logged-in user based on the request.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    private function getLoggedInPage(array $request): array {
        if (isset($request['board_id'])) {
            return self::getBoardPage($request);
        }

        if (isset($request['board_list_id'])) {
            return self::getBoardListPage();
        }

        if (isset($request['workspace_id'])) {
            return self::getWorkspacePage($request);
        }

        return self::getHomePage($request);
    }

    /**
     * Get the page for a logged-out user based on the request.
     * @return array Data for the page to display.
     */
    private function getLoggedOutPage(): array
    {
        $settings = $this->db->getDBSettings();

        // Apply DB updates if needed
        if ($this->db->applyDBUpdates($settings['db_version'])) {
            Logger::info("Database updates applied, refreshing settings cache");
            $settings = $this->db->getDBSettings();
        }

        if (!empty($settings['perform_first_startup'])) {
            Logger::info("First startup detected — creating admin account");
            $adminAccount = Account::createNewAdminAccount();
            $this->db->setDBSetting('perform_first_startup', 0);

            return [
                'page_name' => 'FirstStartup',
                'page_content' => array_merge($settings, [
                    'admin_user' => $adminAccount['username'],
                    'admin_pass' => $adminAccount['password']
                ])
            ];
        }

        return [
            'page_name' => 'Login',
            'page_content' => array_merge($settings, [
                'background_img_url' => Board::DEFAULT_BG
            ])
        ];
    }

    /**
     * Gets the homepage. Provides a list of available workspaces.
     * @param array $request The request parameters.
     * @return array The data for the page.
     */
    private function getHomePage(array $request): array {
        $workspacePermissions = new WorkspacePermissions($this->db);
        $workspaces = new Workspace($this->api, $this->db, $workspacePermissions);
        $data = $workspaces->readAll($request);

        // Create a workspace if one doesn't exist yet.
        if (empty($data)) {
            Logger::info("No workspaces found. A new one will be created.");

            // Use the display name, if available.
            if ($_SESSION['display_name']) {
                $request['name'] = $_SESSION['display_name'] . "'s Workspace";
            }

            $data = [$workspaces->create($request)];
        }

        $displayName = $_SESSION['display_name'] ?? 'Unknown';
        $settings = $this->db->getDBSettings();

        return [
            'page_name'    => 'Home',
            'page_content' => array_merge($settings, [
                'workspace_list'         => $data,
                'background_url'    => self::DEFAULT_BG,
                'background_tiled'  => true,
                'display_name'      => $displayName
            ])
        ];
    }

    /**
     * Gets the data for a workspace page.
     * @param array $request The request parameters.
     * @return array The data for the page.
     */
    private function getWorkspacePage(array $request): array {
        $workspaces = new Workspace($this->db, new WorkspacePermissions($this->db));
        $workspace = $workspaces->read($request);

        // Create a workspace if one doesn't exist yet.
        if (empty($workspace)) {
            Logger::info("Workspace not found. A new one will be created.");
            $workspace = $workspaces->create($request);
        }

        $displayName = $_SESSION['display_name'] ?? 'Unknown';
        $settings = $this->db->getDBSettings();

        return [
            'page_name'    => 'Workspace',
            'page_content' => array_merge($settings, [
                'workspace'         => $workspace,
                'background_url'    => self::DEFAULT_BG,
                'background_tiled'  => true,
                'display_name'      => $displayName
            ])
        ];
    }

    /**
     * Get the board list page from the database.
     * @return array
     */
    private function getBoardListPage(): array
    {
        Session::ensureSession();

        if (empty($_SESSION['user_id'])) {
            Logger::error("GetBoardListPage: No user_id in session");
            throw new ApiException("Not logged in", 403);
        }

        $userId = (int) $_SESSION['user_id'];
        $displayName = $_SESSION['display_name'] ?? 'Unknown';

        $sql = "
        SELECT b.*, p.user_type
        FROM tarallo_boards b
        INNER JOIN tarallo_permissions p ON b.id = p.board_id
        WHERE p.user_id = :user_id
        ORDER BY b.last_modified_time DESC
    ";
        $results = $this->db->fetchColumn($sql, 'id', ['user_id' => $userId]);

        $boardList = [];
        foreach ($results as $boardId) {
            try {
                // Use GetBoardData to enforce permissions and formatting
                $board = Board::getBoardData((int)$boardId, UserType::Observer);
                $boardList[] = $board;
            } catch (ApiException $e) {
                Logger::debug("GetBoardListPage: Skipping board $boardId - " . $e->getMessage());
            }
        }

        $settings = $this->db->getDBSettings();

        return [
            'page_name'    => 'BoardList',
            'page_content' => array_merge($settings, [
                'boards'            => $boardList,
                'background_url'    => self::DEFAULT_BG,
                'background_tiled'  => true,
                'display_name'      => $displayName
            ])
        ];
    }

    /**
     * Get the page for a single board.
     * @param array $request The request parameters.
     * @return array The page content.
     */
    private function getBoardPage(array $request): array
    {
        if (empty($request['board_id'])) {
            Logger::error("GetBoardPage: Missing 'board_id'");
            http_response_code(400);
            return [
                'page_name'    => 'Error',
                'page_content' => ['message' => 'board_id parameter is missing']
            ];
        }

        $boardId     = (int) $request['board_id'];
        $displayName = $_SESSION['display_name'] ?? 'Unknown';

        try {
            $boardData = Board::getBoardData($boardId, UserType::None, true, true);
        } catch (ApiException) {
            return [
                'page_name'    => 'UnaccessibleBoard',
                'page_content' => [
                    'id'               => $boardId,
                    'display_name'     => $displayName,
                    'access_requested' => false
                ]
            ];
        }

        $boardData['display_name'] = $displayName;

        // Add the database version
        $boardData['db_version'] = $this->db->getDBSetting('db_version');

        if (!empty($boardData['closed'])) {
            return [
                'page_name'    => 'ClosedBoard',
                'page_content' => $boardData
            ];
        }

        return [
            'page_name'    => 'Board',
            'page_content' => $boardData
        ];
    }
}