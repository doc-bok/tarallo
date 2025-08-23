<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

class Page
{
    const DEFAULT_BG = "images/tarallo-bg.jpg";

    /**
     * Get the page for a logged-in user based on the request.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    public static function getLoggedInPage(array $request): array
    {
        if (isset($request['board_id'])) {
            return self::getBoardPage($request);
        }

        return self::getBoardListPage();
    }

    /**
     * Get the page for a single board.
     * @param array $request The request parameters.
     * @return array The page content.
     */
    public static function getBoardPage(array $request): array
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
        } catch (RuntimeException) {
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
        $boardData['db_version'] = DB::getDBSetting('db_version');

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

    /**
     * Get the board list page from the database.
     * @return array
     */
    public static function getBoardListPage(): array
    {
        Session::ensureSession();

        if (empty($_SESSION['user_id'])) {
            Logger::error("GetBoardListPage: No user_id in session");
            throw new RuntimeException("Not logged in");
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
        $results = DB::fetchColumn($sql, 'id', ['user_id' => $userId]);

        $boardList = [];
        foreach ($results as $boardId) {
            try {
                // Use GetBoardData to enforce permissions and formatting
                $board = Board::getBoardData((int)$boardId, UserType::Observer);
                $boardList[] = $board;
            } catch (RuntimeException $e) {
                Logger::debug("GetBoardListPage: Skipping board $boardId - " . $e->getMessage());
            }
        }

        $settings = DB::getDBSettings();

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
     * Get the page for a logged-out user based on the request.
     * @return array Data for the page to display.
     */
    public static function getLoggedOutPage(): array
    {
        $settings = DB::getDBSettings();

        // Apply DB updates if needed
        if (DB::applyDBUpdates($settings['db_version'])) {
            Logger::info("Database updates applied, refreshing settings cache");
            $settings = DB::getDBSettings();
        }

        if (!empty($settings['perform_first_startup'])) {
            Logger::info("First startup detected — creating admin account");
            $adminAccount = Account::createNewAdminAccount();
            DB::setDBSetting('perform_first_startup', 0);

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
     * Request the page that should be displayed for the current state.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    public static function getCurrentPage(array $request): array
    {
        try {
            // Ensure DB exists or initialise
            DB::initDatabaseIfNeeded();

            // Logged in?
            if (isset($_SESSION['logged_in'])) {
                return self::getLoggedInPage($request);
            }

            // Logged out flow
            return self::getLoggedOutPage();

        } catch (Throwable $e) {
            Logger::error("GetCurrentPage: Unhandled exception - " . $e->getMessage());
            http_response_code(500);
            return [
                'page_name' => 'Error',
                'page_content' => ['message' => 'Internal Server Error']
            ];
        }
    }
}