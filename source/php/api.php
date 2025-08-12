<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'db.php';
require_once 'file.php';
require_once 'json.php';
require_once 'utils.php';

// page initialization
header('Content-Type: application/json; charset=utf-8');
session_start(['cookie_samesite' => 'Strict',]);

// initialize parameters
$request = Json::decodePostJSON(); // params posted as json
$request = array_merge($request == null ? array() : $request, $_GET); // params added to the url

// check the the api call name has been specified
// and it's a valid API call
if (!isset($request['OP']) || !method_exists("API", $request['OP'])) {
	http_response_code(400);
	exit("Invalid 'OP' code: " . $request['OP']);
}

// call the requested API and echo the result as JSON
$methodName = "API::" . $request['OP'];
$response = $methodName($request);
echo json_encode($response);

// contains all the tarallo api calls
class API
{
	const DEFAULT_BG = "images/tarallo-bg.jpg";
	const DEFAULT_BOARDTILE_BG = "images/boardtile-bg.jpg";
	const DEFAULT_LABEL_COLORS = array("red", "orange", "yellow", "green", "cyan", "azure", "blue", "purple", "pink", "grey");
	const MAX_LABEL_COUNT = 24;
	const MAX_LABEL_FIELD_LEN = 400;
	const TEMP_EXPORT_PATH = "temp/export.zip";

	// user types for the permission table
	const USERTYPE_Owner = 0; // full-control of the board
	const USERTYPE_Moderator = 2; // full-control of the board, except a few functionalities like board permanent deletion
	const USERTYPE_Member = 6; // full-control of the cards but no access to board layout and options
	const USERTYPE_Observer = 8; // read-only access to the board
	const USERTYPE_Guest = 9; // no access, but user requested to join the board
	const USERTYPE_Blocked = 10; // no access (blocked by a board moderator)
	const USERTYPE_None = 11; // no access (no record on db)

	// special user IDs
	const userId_ONREGISTER = -1; // if a permission record on the permission table has this user_id, the permission will be copied to any new registered user
	const userId_MIN = self::userId_ONREGISTER; // this should be the minimun special user ID

    const INIT_DB_PATCH = "dbpatch/init_db.sql";

    /**
     * Check if the database exists and initialise it if it isn't.
     */
    private static function initDatabaseIfNeeded(): void
    {
        $dbExists = ((int) DB::fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tarallo_settings'"
            )) > 0;

        if (!$dbExists) {
            Logger::warning("Database not initialised — attempting init");
            self::LogoutInternal();

            if (!self::TryApplyingDBPatch(self::INIT_DB_PATCH)) {
                Logger::error("DB init failed - corrupted or missing patch");
                throw new RuntimeException("Database initialisation failed or DB is corrupted");
            }
        }
    }

    /**
     * Get the page for a logged-in user based on the request.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    private static function getLoggedInPage(array $request): array
    {
        if (isset($request['board_id'])) {
            return self::GetBoardPage($request);
        }

        return self::getBoardListPage();
    }

    /**
     * Get the page for a logged-out user based on the request.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    private static function getLoggedOutPage(array $request): array
    {
        $settings = self::GetDBSettings();

        // Apply DB updates if needed
        if (self::ApplyDBUpdates($settings['db_version'])) {
            Logger::info("Database updates applied, refreshing settings cache");
            $settings = self::GetDBSettings();
        }

        if (!empty($settings['perform_first_startup'])) {
            Logger::info("First startup detected — creating admin account");
            $adminAccount = self::CreateNewAdminAccount();
            self::SetDBSetting('perform_first_startup', 0);

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
                'background_img_url' => self::DEFAULT_BG
            ])
        ];
    }

    /**
     * Request the page that should be displayed for the current state.
     * @param array $request The request parameters.
     * @return array Data for the page to display.
     */
    public static function GetCurrentPage(array $request): array
    {
        try {
            // Ensure DB exists or initialise
            self::initDatabaseIfNeeded();

            // Logged in?
            if (isset($_SESSION['logged_in'])) {
                return self::getLoggedInPage($request);
            }

            // Logged out flow
            return self::getLoggedOutPage($request);

        } catch (Throwable $e) {
            Logger::error("GetCurrentPage: Unhandled exception - " . $e->getMessage());
            http_response_code(500);
            return [
                'page_name' => 'Error',
                'page_content' => ['message' => 'Internal Server Error']
            ];
        }
    }

    /**
     * Get the board list page from the database.
     * @return array
     */
    public static function GetBoardListPage(): array
    {
        self::EnsureSession();

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
                $board = self::GetBoardData((int)$boardId, self::USERTYPE_Observer);
                $boardList[] = $board;
            } catch (RuntimeException $e) {
                Logger::debug("GetBoardListPage: Skipping board $boardId - " . $e->getMessage());
            }
        }

        $settings = self::GetDBSettings();

        return [
            'page_name'    => 'BoardList',
            'page_content' => array_merge($settings, [
                'boards'           => $boardList,
                'background_url'   => self::DEFAULT_BG,
                'background_tiled' => true,
                'display_name'     => $displayName
            ])
        ];
    }

    /**
     * Get the page for a single board.
     * @param array $request The request parameters.
     * @return array The page content.
     */
    public static function GetBoardPage(array $request): array
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
            $boardData = self::GetBoardData($boardId, self::USERTYPE_None, true, true);
        } catch (\RuntimeException $e) {
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
     * Logs a user into the application.
     * @param array $request The request parameters.
     * @return string[]|true[] The result of the login attempt.
     */
    public static function Login(array $request): array
    {
        self::EnsureSession();

        // Force logout if already logged in
        if (self::IsUserLoggedIn()) {
            Logger::info("Login: Existing session detected, logging out first");
            self::LogoutInternal();
        }

        $username = trim($request['username'] ?? '');
        $password = $request['password'] ?? '';

        if ($username === '' || $password === '') {
            Logger::warning("Login: Missing username or password");
            http_response_code(400);
            return ['error' => 'Missing username or password'];
        }

        // Look up user
        $userRecord = DB::fetchRow(
            "SELECT * FROM tarallo_users WHERE username = :username",
            ['username' => $username]
        );

        if (!$userRecord) {
            Logger::warning("Login failed: Unknown username '$username'");
            http_response_code(401);
            return ['error' => 'Invalid username or password'];
        }

        // First login: password is empty
        if (strlen($userRecord['password']) === 0) {
            Logger::info("Login: First login for '$username', setting password");
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $ok = DB::query(
                "UPDATE tarallo_users SET password = :passwordHash WHERE username = :username",
                ['passwordHash' => $passwordHash, 'username' => $username]
            );
            if (!$ok) {
                Logger::error("Login: Failed to set initial password for '$username'");
                http_response_code(500);
                return ['error' => 'Internal error setting first password'];
            }

            // Refresh record
            $userRecord['password'] = $passwordHash;
        }

        if (!password_verify($password, $userRecord['password'])) {
            Logger::warning("Login failed: Wrong password for '$username'");
            http_response_code(401);
            return ['error' => 'Invalid username or password'];
        }

        // Successful login → update session
        $_SESSION['logged_in']    = true;
        $_SESSION['user_id']      = $userRecord['id'];
        $_SESSION['username']     = $userRecord['username'];
        $_SESSION['display_name'] = $userRecord['display_name'];
        $_SESSION['is_admin']     = (bool) $userRecord['is_admin'];

        // Update last access time
        DB::query(
            "UPDATE tarallo_users SET last_access_time = :t WHERE id = :id",
            ['t' => time(), 'id' => $userRecord['id']]
        );

        Logger::info("Login successful for '$username' (user_id {$userRecord['id']})");

        return ['success' => true];
    }

    /**
     * Register a new user.
     * @param array $request The request parameters.
     * @return array|string[] The result of the register attempt.
     */
    public static function Register(array $request): array
    {
        self::EnsureSession();

        $settings = self::GetDBSettings();
        if (empty($settings['registration_enabled'])) {
            Logger::warning("Register: Registration disabled");
            http_response_code(403);
            return ['error' => 'Account creation disabled on this server'];
        }

        if (self::IsUserLoggedIn()) {
            Logger::info("Register: Existing session detected, logging out first");
            self::LogoutInternal();
        }

        $username     = trim($request['username'] ?? '');
        $displayName  = trim($request['display_name'] ?? '');
        $password     = $request['password'] ?? '';

        // Username validation
        if (strlen($username) < 5) {
            http_response_code(400);
            return ['error' => 'Username is too short'];
        }
        if (!preg_match('/^[A-Za-z0-9]+$/', $username)) {
            http_response_code(400);
            return ['error' => 'Username must be alphanumeric and contain no spaces'];
        }
        // Display name validation
        if (strlen($displayName) < 3) {
            http_response_code(400);
            return ['error' => 'Display name is too short'];
        }
        if (!preg_match('/^[A-Za-z0-9\s]+$/', $displayName)) {
            http_response_code(400);
            return ['error' => 'Display name must be alphanumeric'];
        }
        // Password validation
        if (strlen($password) < 5) {
            http_response_code(400);
            return ['error' => 'Password must be at least 5 characters'];
        }

        // Prevent duplicate username
        if (self::UsernameExists($username)) {
            http_response_code(400);
            return ['error' => 'Username already in use'];
        }

        // Create user
        $userId = self::AddUserInternal($username, $password, $displayName);
        if (!$userId) {
            Logger::error("Register: Failed to create user '$username'");
            http_response_code(500);
            return ['error' => 'Internal server error while creating user'];
        }

        Logger::info("Register: Created new user '$username' with ID $userId");

        // Apply initial permissions
        $initialPerms = DB::fetchTable(
            "SELECT * FROM tarallo_permissions WHERE user_id = :id",
            ['id' => self::userId_ONREGISTER]
        );
        foreach ($initialPerms as $perm) {
            if ($perm['user_type'] == self::USERTYPE_Blocked) continue;
            DB::query(
                "INSERT INTO tarallo_permissions (user_id, board_id, user_type) VALUES (:uid, :bid, :ut)",
                ['uid' => $userId, 'bid' => $perm['board_id'], 'ut' => $perm['user_type']]
            );
        }

        return [
            'success'  => true,
            'username' => $username
        ];
    }

    /**
     * Logs a user out of the session.
     * @param array $request The request parameters.
     * @return true[] The result of the logout attempt.
     */
    public static function Logout(array $request): array
    {
        self::EnsureSession();

        if (self::IsUserLoggedIn()) {
            Logger::info("Logout: User {$_SESSION['username']} (ID {$_SESSION['user_id']}) logging out");
        } else {
            Logger::debug("Logout: No user currently logged in");
        }

        self::LogoutInternal();
        return ['success' => true];
    }

    /**
     * Open a card.
     * @param array $request The request parameters.
     * @return string[] The card data, if successful.
     */
    public static function OpenCard(array $request): array
    {
        self::EnsureSession();

        $userId = $_SESSION['user_id'] ?? null;
        $cardId = isset($request['id']) ? (int) $request['id'] : 0;

        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }
        if ($cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Invalid card ID'];
        }

        // Get board_id for this card
        $boardRow = DB::fetchRow(
            "SELECT board_id FROM tarallo_cards WHERE id = :id LIMIT 1",
            ['id' => $cardId]
        );
        if (!$boardRow) {
            http_response_code(404);
            return ['error' => 'Card not found'];
        }

        try {
            $boardData = self::GetBoardData(
                (int) $boardRow['board_id'],
                self::USERTYPE_Observer,
                false,  // no lists
                true,   // include cards
                true,   // include card content
                true    // include attachments
            );
        } catch (RuntimeException $e) {
            http_response_code(403);
            return ['error' => 'Unable to get card data'];
        }

        // Find the card by ID and return it
        foreach ($boardData['cards'] as $card) {
            if ((int)$card['id'] === $cardId) {
                return $card;
            }
        }

        http_response_code(404);
        return ['error' => 'Card not accessible'];
    }

    /**
     * Add a new card to a list
     * @param array $request The request parameters.
     * @return string[] The card data, if successful.
     */
    public static function AddNewCard(array $request): array
    {
        self::EnsureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        $boardId    = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardlistId = isset($request['cardlist_id']) ? (int)$request['cardlist_id'] : 0;
        $title      = trim($request['title'] ?? '');

        if ($boardId <= 0 || $cardlistId <= 0 || $title === '') {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Check board permission
        try {
            self::GetBoardData($boardId, self::USERTYPE_Member);
        } catch (RuntimeException $e) {
            Logger::warning("AddNewCard: Board $boardId not accessible to $userId");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Check cardlist belongs to board
        try {
            self::GetCardlistData($boardId, $cardlistId);
        } catch (RuntimeException $e) {
            Logger::warning("AddNewCard: Cardlist {$cardlistId} not found in board $boardId for $userId");
            http_response_code(400);
            return ['error' => 'Invalid cardlist'];
        }

        $content      = "Insert the card description here."; // default
        $coverAttach  = 0;
        $lastMoved    = time();
        $labelMask    = 0;
        $flagMask     = 0;

        try {
            $newCardRecord = self::AddNewCardInternal(
                $boardId,
                $cardlistId,
                0, // prev_card_id means add at top
                $title,
                $content,
                $coverAttach,
                $lastMoved,
                $labelMask,
                $flagMask
            );
        } catch (Throwable $e) {
            Logger::error("AddNewCard: Failed adding card to board $boardId list $cardlistId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Error adding card'];
        }

        self::UpdateBoardModifiedTime($boardId);

        return self::CardRecordToData($newCardRecord);
    }

    /**
     * Deletes a card from a list.
     * @param array $request The request parameters.
     * @return array|string[] The result of the operation.
     */
    public static function DeleteCard(array $request): array
    {
        self::EnsureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        $boardId = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardId  = isset($request['deleted_card_id']) ? (int)$request['deleted_card_id'] : 0;

        if ($boardId <= 0 || $cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Invalid or missing board_id / deleted_card_id'];
        }

        // Permission check
        try {
            self::GetBoardData($boardId, self::USERTYPE_Member);
        } catch (RuntimeException $e) {
            Logger::warning("DeleteCard: User $userId tried to delete card $cardId in board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        try {
            $deletedCard = self::DeleteCardInternal($boardId, $cardId, true);
            self::UpdateBoardModifiedTime($boardId);
        } catch (RuntimeException $e) {
            http_response_code(400);
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            Logger::error("DeleteCard: Unexpected failure deleting card $cardId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Error deleting card'];
        }

        Logger::info("DeleteCard: User $userId deleted card $cardId from board $boardId");
        return ['success' => true, 'deleted_card' => self::CardRecordToData($deletedCard)];
    }


	public static function MoveCard($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);

		//query and validate cardlist id
		$cardlistData = self::GetCardlistData($request["board_id"], $request["dest_cardlist_id"]);

		// move the card
		try
		{
			DB::beginTransaction();

			// delete the original card
			$cardRecord = self::DeleteCardInternal($request["board_id"], $request["moved_card_id"], false);

			// update last_move_time only if the card list is changing
			$lastMovedTime = $cardRecord["cardlist_id"] != $request["dest_cardlist_id"] ? time() : $cardRecord["last_moved_time"];

			// add the card at the new location
			$newCardRecord = self::AddNewCardInternal(
				$request["board_id"],
				$request["dest_cardlist_id"],
				$request["new_prev_card_id"],
				$cardRecord["title"],
				$cardRecord["content"],
				$cardRecord["cover_attachment_id"],
				$lastMovedTime,
				$cardRecord["label_mask"],
				$cardRecord["flags"]
			);

			// update attachments card_id
			$updateAttachmentsQuery = "UPDATE tarallo_attachments SET card_id = :new_card_id WHERE card_id = :old_card_id";
			DB::setParam("new_card_id", $newCardRecord["id"]);
			DB::setParam("old_card_id", $request["moved_card_id"]);
			DB::queryWithStoredParams($updateAttachmentsQuery);

			self::UpdateBoardModifiedTime($request["board_id"]);

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		// prepare the response
		$response = self::CardRecordToData($newCardRecord);
		return $response;
	}

	public static function MoveCardList($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Moderator);

		//query and validate cardlist id
		$cardListData = self::GetCardlistData($request["board_id"], $request["moved_cardlist_id"]);

		// query and validate the prev cardlist if any, and get the next list ID
		$nextCardListID = 0;
		if ($request["new_prev_cardlist_id"] > 0) 
		{
			$cardListPrevData = self::GetCardlistData($request["board_id"], $request["new_prev_cardlist_id"]);
			$nextCardListID = $cardListPrevData["next_list_id"];
		}
		else
		{
			// query the first cardlist, that will be the next after the moved one
			$nextCardListQuery = "SELECT * FROM tarallo_cardlists WHERE board_id = :board_id AND prev_list_id = 0";
			DB::setParam("board_id", $boardData['id']);
			$nextCardListRecord = DB::fetchRowWithStoredParams($nextCardListQuery);
			$nextCardListID = $nextCardListRecord['id'];
		}

		// move the card list
		try
		{
			DB::beginTransaction();

			// update cardlist linked list
			self::RemoveCardListFromLL($cardListData);
			DB::setParam("prev_list_id", $request["new_prev_cardlist_id"]);
			DB::setParam("next_list_id", $nextCardListID);
			DB::setParam("id", $request["moved_cardlist_id"]);
			DB::queryWithStoredParams("UPDATE tarallo_cardlists SET prev_list_id = :prev_list_id, next_list_id = :next_list_id WHERE id = :id");
			self::AddCardListToLL($request["moved_cardlist_id"], $request["new_prev_cardlist_id"], $nextCardListID);

			self::UpdateBoardModifiedTime($request["board_id"]);

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		// requery the list prepare the response
		$response = self::GetCardlistData($request["board_id"], $request["moved_cardlist_id"]);
		return $response;
	}

	public static function UpdateCardTitle($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);

		// query and validate card id
		$cardRecord = self::GetCardData($request["board_id"], $request["id"]);

		// update the card
		$titleUpdateQuery = "UPDATE tarallo_cards SET title = :title WHERE id = :id";
		DB::setParam("title", $request["title"]);
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams($titleUpdateQuery);

		self::UpdateBoardModifiedTime($request["board_id"]);

		$cardRecord["title"] = $request["title"];
		return self::CardRecordToData($cardRecord);
	}

	public static function UpdateCardContent($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);

		// query and validate card id
		$cardRecord = self::GetCardData($request["board_id"], $request["id"]);

		// update the content
		$titleUpdateQuery = "UPDATE tarallo_cards SET content = :content WHERE id = :id";
		DB::setParam("content", $request["content"]);
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams($titleUpdateQuery);

		self::UpdateBoardModifiedTime($request["board_id"]);

		$cardRecord["content"] = $request["content"];
		return self::CardRecordToData($cardRecord);
	}

	public static function UpdateCardFlags($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);

		// query and validate card id
		$cardRecord = self::GetCardData($request["board_id"], $request["id"]);

		// calculate the flag mask
		$cardFlagList = self::CardFlagMaskToList($cardRecord["flags"]);
		if (isset($request["locked"]))
			$cardFlagList["locked"] = $request["locked"];
		$cardRecord["flags"] = self::CardFlagListToMask($cardFlagList);

		// update the flags in the db
		$flagsUpdateQuery = "UPDATE tarallo_cards SET flags = :flags WHERE id = :id";
		DB::setParam("flags", $cardRecord["flags"]);
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams($flagsUpdateQuery);

		self::UpdateBoardModifiedTime($request["board_id"]);

		return self::CardRecordToData($cardRecord);
	}

	public static function UploadAttachment($request)
	{
		// check attachment size
		$maxAttachmentSize = self::GetDBSetting("attachment_max_size_kb");
		if ($maxAttachmentSize && (strlen($request["attachment"]) * 0.75 / 1024) > self::GetDBSetting("attachment_max_size_kb"))
		{
			http_response_code(400);
			exit("Attachment is too big! Max size is $maxAttachmentSize kb");
		}

		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);
		// query and validate card id
		$cardRecord = self::GetCardData($request["board_id"], $request["card_id"]);

		// add attachment to the card in db
		$addAttachmentQuery = "INSERT INTO tarallo_attachments (name, guid, extension, card_id, board_id)";
		$addAttachmentQuery .= " VALUES (:name, :guid, :extension, :card_id, :board_id)";
		$fileInfo = pathinfo($request["filename"]);
		$extension = isset($fileInfo["extension"]) ? strtolower($fileInfo["extension"]) : "bin";
		$guid = uniqid("", true);
		DB::setParam("name", self::CleanAttachmentName($fileInfo["filename"]));
		DB::setParam("guid", $guid);
		DB::setParam("extension", $extension);
		DB::setParam("card_id", $request["card_id"]);
		DB::setParam("board_id", $request["board_id"]);
		$attachmentID = DB::insertWithStoredParams($addAttachmentQuery);
		
		if (!$attachmentID) 
		{
			http_response_code(500);
			exit("Failed to save the new attachment.");
		}

		// save attachment to file
		$filePath = self::GetAttachmentFilePath($request["board_id"], $guid, $extension);
		$fileContent = base64_decode($request["attachment"]);
		File::writeToFile($filePath, $fileContent);

		// create a thumbnail
		$thumbFilePath = self::GetThumbnailFilePath($request["board_id"], $guid);
		Utils::createImageThumbnail($filePath, $thumbFilePath);
		if (File::fileExists($thumbFilePath)) 
		{
			// a thumbnail has been created, set it at the card cover image
			DB::setParam("attachment_id", $attachmentID);
			DB::setParam("card_id", $cardRecord["id"]);
			DB::queryWithStoredParams("UPDATE tarallo_cards SET cover_attachment_id = :attachment_id WHERE id = :card_id");
		}

		self::UpdateBoardModifiedTime($request["board_id"]);

		// re-query added attachment and card and return their data
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $attachmentID);
		$response = self::AttachmentRecordToData($attachmentRecord);
		$cardRecord = self::GetCardData($request["board_id"], $request["card_id"]);
		$response["card"] = self::CardRecordToData($cardRecord);
		return $response;
	}

	public static function UploadBackground($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		// validate filename
		$fileInfo = pathinfo($request["filename"]);
		if (!isset($fileInfo["extension"]))
		{
			http_response_code(400);
			exit("Invalid image file!");
		}

		// save new background to file
		$extension = $fileInfo["extension"];
		$guid = uniqid("", true). "#" . $extension;
		$newBackgroundPath = self::GetBackgroundUrl($request["board_id"], $guid);
		$fileContent = base64_decode($request["background"]);
		File::writeToFile($newBackgroundPath, $fileContent);

		// save a thumbnail copy of it for board tiles
		$newBackgroundThumbPath = self::GetBackgroundUrl($request["board_id"], $guid, true);
		Utils::createImageThumbnail($newBackgroundPath, $newBackgroundThumbPath);

		// delete old background files
		if (stripos($boardData["background_url"], self::DEFAULT_BG) === false) 
		{
			File::deleteFile($boardData["background_url"]);
			File::deleteFile($boardData["background_thumb_url"]);
		}

		// update background in DB
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("background_guid", $guid);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET background_guid = :background_guid WHERE id = :board_id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		$boardData["background_url"] = $newBackgroundPath;
		$boardData["background_tiled"] = false;
		$boardData["background_thumb_url"] = $newBackgroundThumbPath;
		return $boardData;
	}

	public static function DeleteAttachment($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);

		// query attachment
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $request["id"]);

		// delete attachments files
		self::DeleteAttachmentFiles($attachmentRecord);

		// delete attachment from db
		$deletionQuery = "DELETE FROM tarallo_attachments WHERE id = :id";
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams($deletionQuery);

		// delete from cover image if any
		DB::setParam("attachment_id", $attachmentRecord["id"]);
		DB::setParam("card_id", $attachmentRecord["card_id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cards SET cover_attachment_id = 0 WHERE cover_attachment_id = :attachment_id AND id = :card_id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		// re-query added attachment and card and return their data
		$response = self::AttachmentRecordToData($attachmentRecord);
		$cardRecord = self::GetCardData($attachmentRecord["board_id"], $attachmentRecord["card_id"]);
		$response["card"] = self::CardRecordToData($cardRecord);
		return $response;
	}

	public static function UpdateAttachmentName($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);

		// query attachment
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $request["id"]);

		// update attachment name
		$filteredName = self::CleanAttachmentName($request["name"]);

		DB::setParam("id", $attachmentRecord["id"]);
		DB::setParam("name", $filteredName);
		DB::queryWithStoredParams("UPDATE tarallo_attachments SET name = :name WHERE id = :id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		// return the updated attachment data
		$attachmentRecord["name"] = $filteredName;
		$response = self::AttachmentRecordToData($attachmentRecord);
		return $response;
	}

	public static function ProxyAttachment($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Observer);

		// query attachment
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $request["id"]);

		// output just the file (or its thumbnail)
		if (isset($request["thumbnail"]))
		{
			$attachmentPath = self::GetThumbnailFilePathFromRecord($attachmentRecord);
		}
		if (!isset($request["thumbnail"]) || !File::fileExists($attachmentPath))
		{
			$attachmentPath = self::GetAttachmentFilePathFromRecord($attachmentRecord);
		}

		$mimeType = File::getMimeType($attachmentRecord["extension"]);
		$downloadName = $attachmentRecord["name"] . "." . $attachmentRecord["extension"];
		$isImage = stripos($mimeType, "image") === 0;

		File::outputFile($attachmentPath, $mimeType, $downloadName, !$isImage);
	}

	public static function UpdateCardListName($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		//query and validate cardlist id
		$cardlistData = self::GetCardlistData($request["board_id"], $request["id"]);

		// update the cardlist name
		DB::setParam("name", $request["name"]);
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cardlists SET name = :name WHERE id = :id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		// return the cardlist data
		$cardlistData["name"] = $request["name"];
		return $cardlistData;
	}

	public static function AddCardList($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		// insert the new cardlist
		$newCardListData = self::AddNewCardListInternal($boardData["id"], $request["prev_list_id"], $request["name"]);

		self::UpdateBoardModifiedTime($request["board_id"]);

		return $newCardListData;
	}

	public static function DeleteCardList($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		//query and validate cardlist id
		$cardListData = self::GetCardlistData($request["board_id"], $request["id"]);

		// check the number of cards in the list (deletion of lists is only allowed when empty)
		DB::setParam("id", $request["id"]);
		$cardCount = DB::fetchOneWithStoredParams("SELECT COUNT(*) FROM tarallo_cards WHERE cardlist_id = :id");

		if ($cardCount > 0)
		{
			http_response_code(400);
			exit("The specified list still contains cards and cannot be deleted!");
		}

		// delete the list
		self::DeleteCardListInternal($cardListData);

		self::UpdateBoardModifiedTime($request["board_id"]);

		return $cardListData;
	}

	public static function UpdateBoardTitle($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		// update the board title
		DB::setParam("title", self::CleanBoardTitle($request["title"]));
		DB::setParam("id", $request["board_id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET title = :title WHERE id = :id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		// requery and return the board data
		return self::GetBoardData($request["board_id"]);
	}

	public static function CreateNewBoard($request)
	{
		if (!self::IsUserLoggedIn())
		{
			http_response_code(403);
			exit("Cannot create a new board without being logged in.");
		}

		// create the new board
		$newBoardID = self::CreateNewBoardInternal($request["title"]);

		// re-query and return the new board data
		return self::GetBoardData($newBoardID);
	}

	public static function CloseBoard($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["id"]);

		// mark the board as closed
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET closed = 1 WHERE id = :id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		$boardData["closed"] = 1;
		return $boardData;
	}

	public static function ReopenBoard($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["id"]);

		// mark the board as closed
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET closed = 0 WHERE id = :id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		$boardData["closed"] = 0;
		return $boardData;
	}

	public static function DeleteBoard($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["id"]);

		// make sure the board is closed before deleting
		if (!$boardData["closed"])
		{
			http_response_code(400);
			exit("Cannot delete an open board.");
		}

		$boardID = $request["id"];

		// save attachment records before deleting them
		DB::setParam("board_id", $boardID);
		$attachments = DB::fetchTableWithStoredParams("SELECT * FROM tarallo_attachments WHERE board_id = :board_id");

		// delete all the records from the board
		try
		{
			DB::beginTransaction();

			// delete board record
			DB::setParam("board_id", $boardID);
			DB::queryWithStoredParams("DELETE FROM tarallo_boards WHERE id = :board_id");

			// delete cardlists
			DB::setParam("board_id", $boardID);
			DB::queryWithStoredParams("DELETE FROM tarallo_cardlists WHERE board_id = :board_id");

			// delete cards
			DB::setParam("board_id", $boardID);
			DB::queryWithStoredParams("DELETE FROM tarallo_cards WHERE board_id = :board_id");

			// delete attachments
			DB::setParam("board_id", $boardID);
			DB::queryWithStoredParams("DELETE FROM tarallo_attachments WHERE board_id = :board_id");

			// delete permissions
			DB::setParam("board_id", $boardID);
			DB::queryWithStoredParams("DELETE FROM tarallo_permissions WHERE board_id = :board_id");

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		// delete all board files
		$boardDir = self::GetBoardContentDir($boardID);
		File::deleteDir($boardDir);

		return $boardData;
	}

	public static function ImportBoard($request)
	{
		if (!$_SESSION["is_admin"] && !self::GetDBSetting("board_import_enabled"))
		{
			http_response_code(403);
			exit("Board import is disabled on this server!");
		}

		if (!self::IsUserLoggedIn())
		{
			http_response_code(403);
			exit("Cannot create a new board without being logged in.");
		}

		// open the zip archive from the export
		$exportPath = self::TEMP_EXPORT_PATH;
		$exportZip = new ZipArchive();		
		if (!$exportZip->open(File::ftpDir($exportPath)))
		{
			http_response_code(500);
			exit("Import Failed: export zip not found.");
		}

		// unzip db content
		$boardExportData = array();
		{
			$dbExportJson = $exportZip->getFromName("db.json");
			if (!$dbExportJson)
			{
				http_response_code(400);
				exit("Import Failed: invalid export file.");
			}
			$boardExportData = json_decode($dbExportJson, true);
		}

		// build new db indices
		$nextCardlistID = DB::fetchOneWithStoredParams("SELECT MAX(id) FROM tarallo_cardlists") + 1;
		$cardlistIndex = DB::rebuildDBIndex($boardExportData["cardlists"], "id", $nextCardlistID);
		$cardlistIndex[0] = 0; // unlinked cardlist entry
		$nextCardID = DB::fetchOneWithStoredParams("SELECT MAX(id) FROM tarallo_cards") + 1;
		$cardIndex = DB::rebuildDBIndex($boardExportData["cards"], "id", $nextCardID);
		$cardIndex[0] = 0; // no prev card id entry
		$nextAttachmentID = DB::fetchOneWithStoredParams("SELECT MAX(id) FROM tarallo_attachments") + 1;
		$attachIndex = DB::rebuildDBIndex($boardExportData["attachments"], "id", $nextAttachmentID);
		$attachIndex[0] = 0; // card without cover attachment

		try
		{
			DB::beginTransaction();

			// create a new board with the exported data
			$newBoardID = self::CreateNewBoardInternal($boardExportData["title"], $boardExportData["label_names"], $boardExportData["label_colors"], $boardExportData["background_guid"]);

			// add cardlists to db
			{
				// prepare a query to add all the cardlists
				$addCardlistsQuery = "INSERT INTO tarallo_cardlists (id, board_id, name, prev_list_id, next_list_id) VALUES ";
				$cardlistPlaceholders = "(?, ?, ?, ?, ?)";
				// foreach cardlist
				for ($i = 0; $i < count($boardExportData["cardlists"]); $i++) 
				{
					$curList = $boardExportData["cardlists"][$i];

					// add query parameters
					DB::$QUERY_PARAMS[] = $cardlistIndex[$curList["id"]]; // id
					DB::$QUERY_PARAMS[] = $newBoardID;// board_id
					DB::$QUERY_PARAMS[] = $curList["name"]; // name
					DB::$QUERY_PARAMS[] = $cardlistIndex[$curList["prev_list_id"]]; // prev_list_id
					DB::$QUERY_PARAMS[] = $cardlistIndex[$curList["next_list_id"]]; // next_list_id

					// add query format
					$addCardlistsQuery .= ($i > 0 ? ", " : "") . $cardlistPlaceholders;
				}
				// add all the cards for this list to the DB
				DB::queryWithStoredParams($addCardlistsQuery);
			}

			// add cards to db
			{
				// prepare a query to add all the cards
				$addCardsQuery = "INSERT INTO tarallo_cards (id, title, content, prev_card_id, next_card_id, cardlist_id, board_id, cover_attachment_id, last_moved_time, label_mask, flags) VALUES ";
				$cardPlaceholders = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
				// foreach card
				for ($i = 0; $i < count($boardExportData["cards"]); $i++) 
				{
					$curCard = $boardExportData["cards"][$i];

					// add query parameters
					DB::$QUERY_PARAMS[] = $cardIndex[$curCard["id"]]; // id
					DB::$QUERY_PARAMS[] = $curCard["title"]; // title
					DB::$QUERY_PARAMS[] = $curCard["content"]; // content
					DB::$QUERY_PARAMS[] = $cardIndex[$curCard["prev_card_id"]]; // prev_card_id
					DB::$QUERY_PARAMS[] = $cardIndex[$curCard["next_card_id"]];// next_card_id
					DB::$QUERY_PARAMS[] = $cardlistIndex[$curCard["cardlist_id"]];// cardlist_id
					DB::$QUERY_PARAMS[] = $newBoardID;// board_id
					DB::$QUERY_PARAMS[] = $attachIndex[$curCard["cover_attachment_id"]];// cover_attachment_id
					DB::$QUERY_PARAMS[] = $curCard["last_moved_time"]; // last_moved_time
					DB::$QUERY_PARAMS[] = $curCard["label_mask"];// label_mask
					DB::$QUERY_PARAMS[] = $curCard["flags"];// flags

					// add query format
					$addCardsQuery .= ($i > 0 ? ", " : "") . $cardPlaceholders;
				}
				// add all the cards for this list to the DB
				DB::queryWithStoredParams($addCardsQuery);
			}

			// add attachments
			if (count($boardExportData["attachments"]) > 0)
			{
				// prepare a query to add all the attachments
				$addAttachmentsQuery = "INSERT INTO tarallo_attachments (id, name, guid, extension, card_id, board_id) VALUES ";
				$attachmentsPlaceholders = "(?, ?, ?, ?, ?, ?)";
				// foreach cardlist
				for ($i = 0; $i < count($boardExportData["attachments"]); $i++) 
				{
					$curAttachment = $boardExportData["attachments"][$i];

					// add query parameters
					DB::$QUERY_PARAMS[] = $attachIndex[$curAttachment["id"]]; // id
					DB::$QUERY_PARAMS[] = $curAttachment["name"]; // name
					DB::$QUERY_PARAMS[] = $curAttachment["guid"]; // guid
					DB::$QUERY_PARAMS[] = $curAttachment["extension"]; // extension
					DB::$QUERY_PARAMS[] = $cardIndex[$curAttachment["card_id"]]; // card_id
					DB::$QUERY_PARAMS[] = $newBoardID;// board_id

					// add query format
					$addAttachmentsQuery .= ($i > 0 ? ", " : "") . $attachmentsPlaceholders;
				}
				// add all the cards for this list to the DB
				DB::queryWithStoredParams($addAttachmentsQuery);
			}

			// unzip board content to board/ folder
			$boardFolder = self::GetBoardContentDir($newBoardID);
			if (!$exportZip->extractTo(File::ftpDir($boardFolder)))
			{
				DB::rollBack();
				http_response_code(500);
				exit("Import Failed: extraction failed.");
			}
			if (!$exportZip->close())
			{
				DB::rollBack();
				http_response_code(500);
				exit("Import failed: cannot close zip file.");
			}

			// clean temp files
			File::deleteFile($boardFolder . "db.json");
			File::deleteFile($exportPath);

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		// re-query and return the new board data
		return self::GetBoardData($newBoardID);
	}

	public static function ImportFromTrello($request) 
	{
		if (!$_SESSION["is_admin"] && !self::GetDBSetting("trello_import_enabled"))
		{
			http_response_code(403);
			exit("Importing boards from Trello is disabled on this server!");
		}

		if (!self::IsUserLoggedIn())
		{
			http_response_code(403);
			exit("Cannot create a new board without being logged in.");
		}

		// check the next available card id
		$nextCardID = DB::fetchOneWithStoredParams("SELECT MAX(id) FROM tarallo_cards") + 1;

		$trello = $request["trello_export"];

		// create the new board
		$newBoardID = self::CreateNewBoardInternal($trello["name"]);

		// add labels to the board
		$labelNames = array();
		$labelColors = array();
		foreach ($trello["labelNames"] as $key => $value) 
		{
			if (strlen($value) > 0)
			{
				$labelNames[] = self::CleanLabelName($value);
				$labelColors[] = self::DEFAULT_LABEL_COLORS[count($labelColors) % count(self::DEFAULT_LABEL_COLORS)];
			}
		}
		if (count($labelNames) > 0)
		{
			self::UpdateBoardLabelsInternal($newBoardID, $labelNames, $labelColors);
		}

		// prerare cards and lists data
		$trelloLists = $trello["lists"];
		$cardlistCount = count($trelloLists);
		$trelloCards = $trello["cards"];
		$cardCount = count($trelloCards);
		$prevCardlistID = 0;
		$clistCount = count($trello["checklists"]);

		// foreach list...
		for ($iList = 0; $iList < $cardlistCount; $iList++) 
		{
			$curTrelloList = $trelloLists[$iList];

			if ($curTrelloList["closed"])
				continue; // skip archived trello lists

			// create the list
			$newCardlistData = self::AddNewCardListInternal($newBoardID, $prevCardlistID, $curTrelloList["name"]);
			$newCardlistID = $newCardlistData["id"];


			// collect the trello cards for this list
			$curTrelloCards = array();
			$curTrelloListID = $curTrelloList["id"];
			for ($iCard = 0; $iCard < $cardCount; $iCard++) 
			{
				if ($trelloCards[$iCard]["closed"] || // card is archived (not supported, just discard)
					($trelloCards[$iCard]["idList"] !== $curTrelloListID)) // the card is from another list
				{
					continue; 
				}

				$curTrelloCards[] = $trelloCards[$iCard];
			}

			$listCardCount = count($curTrelloCards);
			
			if ($listCardCount > 0)
			{
				// sort cards in this list
				usort($curTrelloCards, [API::class, "CompareTrelloSortedItems"]);

				// prepare a query to add all the cards for this list
				$addCardsQuery = "INSERT INTO tarallo_cards (id, title, content, prev_card_id, next_card_id, cardlist_id, board_id, cover_attachment_id, last_moved_time, label_mask) VALUES ";
				$recordPlaceholders = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
				// foreach card...
				for ($iCard = 0; $iCard < $listCardCount; $iCard++) 
				{
					$curTrelloCard = $curTrelloCards[$iCard];

					// convert due date to last moved time
					$lastMovedTime = 0;
					if (strlen($curTrelloCard["due"]) > 0)
					{
						$trelloDueDate = DateTime::createFromFormat("Y-m-d*H:i:s.v+", $curTrelloCard["due"]);
						if ($trelloDueDate)
							$lastMovedTime = $trelloDueDate->getTimestamp();
					}

					// convert card labels into a mask
					$labelMask = 0;
					foreach($curTrelloCard["labels"] as $trelloCardLabel)
					{
						$labelIndex = array_search($trelloCardLabel["name"], $labelNames);
						if ($labelIndex !== false)
							$labelMask += 1 << $labelIndex;
					}

					//convert all checklists to markup
					$clistContent = "";
					$clistCardCount = count($curTrelloCard["idChecklists"]);
					for ($iCardChk = 0; $iCardChk < $clistCardCount; $iCardChk++)
					{
						$chkGUID = $curTrelloCard["idChecklists"][$iCardChk];

						$cardChk = false;
						for($iChk = 0; $iChk < $clistCount; $iChk++)
						{
							if ($trello["checklists"][$iChk]["id"] === $chkGUID)
							{
								$cardChk = $trello["checklists"][$iChk];
								break;
							}
						}

						if (!$cardChk)
							continue; // checklist reference not found in the trello export?

						// sort checklist items
						usort($cardChk["checkItems"], [API::class, "CompareTrelloSortedItems"]);

						// convert checklist to markup
						$clistContent .= "\n## " . $cardChk["name"]; // title
						$chkItemsCount = count($cardChk["checkItems"]);
						for ($iItem = 0; $iItem < $chkItemsCount; $iItem++)
						{
							$chkItem = $cardChk["checkItems"][$iItem];
							$checkedStr = $chkItem["state"] == "complete" ? "x" : " ";
							$clistContent .= "\n- [$checkedStr] " . $chkItem["name"]; // item
						}

						// checklist termination
						$clistContent .= "\n";
					}

					// add query parameters
					DB::$QUERY_PARAMS[] = $nextCardID; // id
					DB::$QUERY_PARAMS[] = $curTrelloCard["name"]; // title
					DB::$QUERY_PARAMS[] = $curTrelloCard["desc"] . $clistContent; // content
					DB::$QUERY_PARAMS[] = $iCard == 0 ? 0 : ($nextCardID - 1); // prev_card_id
					DB::$QUERY_PARAMS[] = $iCard == ($listCardCount - 1) ? 0 : ($nextCardID + 1);// next_card_id
					DB::$QUERY_PARAMS[] = $newCardlistID;// cardlist_id
					DB::$QUERY_PARAMS[] = $newBoardID;// board_id
					DB::$QUERY_PARAMS[] = 0;// cover_attachment_id
					DB::$QUERY_PARAMS[] = $lastMovedTime; // last_moved_time
					DB::$QUERY_PARAMS[] = $labelMask;// label_mask

					// add query format
					$addCardsQuery .= ($iCard > 0 ? ", " : "") . $recordPlaceholders;

					$nextCardID++;

				} // end foreach card

				// add all the cards for this list to the DB
				DB::queryWithStoredParams($addCardsQuery);
			}

			$prevCardlistID = $newCardlistID;

		} // end foreach list

		// re-query and return the new board data
		return self::GetBoardData($newBoardID);
	}

	public static function CreateBoardLabel($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		// explode board label list
		$boardLabelNames = array();
		$boardLabelColors = array();
		if (strlen($boardData["label_names"]) > 0) 
		{
			$boardLabelNames = explode(",", $boardData["label_names"]);
			$boardLabelColors = explode(",", $boardData["label_colors"]);
		}
		$labelCount = count($boardLabelNames);

		// search for the first empty slot in the label mask if any
		$labelIndex = array_search("", $boardLabelNames);
		if ($labelIndex === false)
		{
			// check that the number of label is not exceeded
			if ($labelCount >= self::MAX_LABEL_COUNT)
			{
				http_response_code(400);
				exit("Cannot create any more labels!");
			}

			// no empty slot, add one
			$labelIndex = $labelCount;
			$boardLabelNames[] = "";
			$boardLabelColors[] = "";
		}

		// add a new label
		$newLabelColor = self::DEFAULT_LABEL_COLORS[$labelIndex % count(self::DEFAULT_LABEL_COLORS)];
		$boardLabelNames[$labelIndex] = $newLabelColor;
		$boardLabelColors[$labelIndex] = $newLabelColor;

		// update the board
		self::UpdateBoardLabelsInternal($request["board_id"], $boardLabelNames, $boardLabelColors);
		self::UpdateBoardModifiedTime($request["board_id"]);

		// return the updated labels
		$response = array();
		$response["label_names"] = implode(",", $boardLabelNames);
		$response["label_colors"] = implode(",", $boardLabelColors);
		$response["index"] = $labelIndex;
		return $response;
	}

	public static function UpdateBoardLabel($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		// explode board label list
		$boardLabelNames = explode(",", $boardData["label_names"]);
		$boardLabelColors = explode(",", $boardData["label_colors"]);
		$labelCount = count($boardLabelNames);
		 
		if (!isset($request["index"]) || $request["index"] >= $labelCount || $request["index"] < 0)
		{
			http_response_code(400);
			exit("Invalid parameters: the label <index> is required, and must be a smaller than the label count.");
		}

		// update the label name and color
		$labelIndex = $request["index"];
		$boardLabelNames[$labelIndex] = self::CleanLabelName($request["name"]);
		$boardLabelColors[$labelIndex] = $request["color"];

		// update the board
		self::UpdateBoardLabelsInternal($request["board_id"], $boardLabelNames, $boardLabelColors);
		self::UpdateBoardModifiedTime($request["board_id"]);

		// return the updated label
		$response = array();
		$response["index"] = $labelIndex;
		$response["name"] = $boardLabelNames[$labelIndex];
		$response["color"] = $boardLabelColors[$labelIndex];
		return $response;
	}

	public static function DeleteBoardLabel($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"]);

		// explode board label list
		$boardLabelNames = explode(",", $boardData["label_names"]);
		$boardLabelColors = explode(",", $boardData["label_colors"]);
		$labelCount = count($boardLabelNames);
		 
		if (!isset($request["index"]) || $request["index"] >= $labelCount || $request["index"] < 0)
		{
			http_response_code(400);
			exit("Invalid parameters: the label <index> is required, and must be a smaller than the label count.");
		}

		// remove the label name and color
		$labelIndex = $request["index"];
		$boardLabelNames[$labelIndex] = "";
		$boardLabelColors[$labelIndex] = "";

		// remove unused trailing elements
		while (strlen($boardLabelNames[$labelCount - 1]) == 0)
		{
			array_pop($boardLabelNames);
			array_pop($boardLabelColors);
			$labelCount--;
		}

		// update the board
		self::UpdateBoardLabelsInternal($request["board_id"], $boardLabelNames, $boardLabelColors);
		self::UpdateBoardModifiedTime($request["board_id"]);

		// remove the label flag from all the cards of this board
		DB::setParam("removed_label_mask", ~(1 << $labelIndex));
		DB::setParam("board_id", $request["board_id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cards SET label_mask = label_mask & :removed_label_mask WHERE board_id = :board_id");

		// return the removed label index
		$response = array();
		$response["index"] = $labelIndex;
		return $response;
	}

	public static function SetCardLabel($request)
	{
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Member);
		

		if (!isset($request["index"]) || !isset($request["active"]))
		{
			http_response_code(400);
			exit("Missing parameters: both the label <index> and <active> are required.");
		}

		// explode board label list
		$boardLabelNames = explode(",", $boardData["label_names"]);
		$boardLabelColors = explode(",", $boardData["label_colors"]);
		$labelCount = count($boardLabelNames);
		$labelIndex = intval($request["index"]);
		$labelActive = $request["active"] ? 1 : 0;

		if ($labelIndex >= $labelCount || $labelIndex < 0) 
		{
			http_response_code(400);
			exit("The label index was out of bounds!");
		}

		// query and validate card id
		$cardData = self::GetCardData($request["board_id"], $request["card_id"]);

		// create the new mask
		$labelMask = $cardData["label_mask"];
		$selectMask = 1 << $labelIndex;
		$labelMask = ($labelMask & ~$selectMask) + $labelActive * $selectMask;

		// update the card
		DB::setParam("label_mask", $labelMask);
		DB::setParam("card_id", $cardData["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cards SET label_mask = :label_mask WHERE id = :card_id");

		self::UpdateBoardModifiedTime($request["board_id"]);

		// return info about the updated label
		$response = array();
		$response["card_id"] = $cardData["id"];
		$response["index"] = $labelIndex;
		$response["name"] = $boardLabelNames[$labelIndex];
		$response["color"] = $boardLabelColors[$labelIndex];
		$response["active"] = ($labelActive !== 0);

		return $response;
	}

	public static function GetBoardPermissions($request) {
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Moderator);

		// query permissions for this board
		$boardPermissionsQuery = "SELECT tarallo_permissions.user_id, tarallo_users.display_name, tarallo_permissions.user_type";
		$boardPermissionsQuery .= " FROM tarallo_permissions LEFT JOIN tarallo_users ON tarallo_permissions.user_id = tarallo_users.id";
		$boardPermissionsQuery .= " WHERE board_id = :board_id";
		DB::setParam("board_id", $request["id"]);
		$boardData["permissions"] = DB::fetchTableWithStoredParams($boardPermissionsQuery);
		$boardData["is_admin"] = $_SESSION["is_admin"];

		return $boardData;
	}

	public static function SetUserPermission($request) {
		// query and validate board id
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Moderator);
		$isSpecialPermission = $request["user_id"] < 0;

		if ($isSpecialPermission)
		{
			if (!$_SESSION["is_admin"]) 
			{
				http_response_code(403);
				exit("Special permissions are only available to site admins.");
			}

			if ($request["user_id"] < self::userId_MIN)
			{
				http_response_code(400);
				exit("Invalid special permission.");
			}
		}

		if ($request["user_id"] == $_SESSION["user_id"]) 
		{
			http_response_code(400);
			exit("Cannot edit your own permissions!");
		}

		if ($request["user_type"] <= $boardData["user_type"]) 
		{
			http_response_code(403);
			exit("Cannot assign this level of permission.");
		}

		// query current user type
		$boardPermissionsQuery = "SELECT user_id, user_type FROM tarallo_permissions";
		$boardPermissionsQuery .= " WHERE board_id = :board_id AND user_id = :user_id";
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("user_id", $request["user_id"]);
		$permission = DB::fetchRowWithStoredParams($boardPermissionsQuery);

		if (!$isSpecialPermission)
		{
			if (!$permission) 
			{
				http_response_code(404);
				exit("No permission for the specified user was found!");
			}

			if ($permission["user_type"] <= $boardData["user_type"])
			{
				http_response_code(403);
				exit("Cannot edit permissions for this user.");
			}
		}

		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("user_id", $request["user_id"]);
		DB::setParam("user_type", $request["user_type"]);
		if ($permission) 
		{
			// update permission
			DB::queryWithStoredParams("UPDATE tarallo_permissions SET user_type = :user_type WHERE board_id = :board_id AND user_id = :user_id");
		} 
		else // if ($isSpecialPermission)
		{
			// add permission (only special permissions can be added here if not present)
			DB::queryWithStoredParams("INSERT INTO tarallo_permissions (user_id, board_id, user_type) VALUES (:user_id, :board_id, :user_type)");
		}

		self::UpdateBoardModifiedTime($request["board_id"]);

		// query back for the updated permission
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("user_id", $request["user_id"]);
		$permission = DB::fetchRowWithStoredParams($boardPermissionsQuery);

		return $permission;
	}

	public static function RequestBoardAccess($request)
	{
		// query and validate board id and access level
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_None);

		if ($boardData["user_type"] < self::USERTYPE_Guest) 
		{
			http_response_code(400);
			exit("The user is already allowed to view this board!");
		}

		DB::setParam("user_id", $_SESSION["user_id"]);
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("user_type", self::USERTYPE_Guest);

		// add new permission or update existing one
		if ($boardData["user_type"] == self::USERTYPE_None)
			$guestPermissionQuery = "INSERT INTO tarallo_permissions (user_id, board_id, user_type) VALUES (:user_id, :board_id, :user_type)";
		else
			$guestPermissionQuery = "UPDATE tarallo_permissions SET user_type = :user_type WHERE user_id = :user_id AND board_id = :board_id";

		DB::queryWithStoredParams($guestPermissionQuery);

		// prepare the response
		$response = array();
		$response["access_requested"] = true;
		return $response;
	}

	public static function ExportBoard($request)
	{
		if (!$_SESSION["is_admin"] && !self::GetDBSetting("board_export_enabled"))
		{
			http_response_code(403);
			exit("Board export is disabled on this server!");
		}

		// query and validate board id and access level
		$boardData = self::GetBoardData($request["board_id"], self::USERTYPE_Moderator);
		$boardID = $boardData["id"];

		// create a zip for the export
		$exportPath = self::TEMP_EXPORT_PATH;
		File::prepareDir($exportPath);
		$exportZip = new ZipArchive();

		if (!$exportZip->open(File::ftpDir($exportPath), ZipArchive::CREATE | ZipArchive::OVERWRITE))
		{
			http_response_code(500);
			exit("Export failed: zip creation error.");
		}

		// create a board data struct
		DB::setParam("id", $boardID);
		$boardExportData = DB::fetchRowWithStoredParams("SELECT * FROM tarallo_boards WHERE id = :id");
		DB::setParam("board_id", $boardID);
		$boardExportData["cardlists"] = DB::fetchTableWithStoredParams("SELECT * FROM tarallo_cardlists WHERE board_id = :board_id");
		DB::setParam("board_id", $boardID);
		$boardExportData["cards"] = DB::fetchTableWithStoredParams("SELECT * FROM tarallo_cards WHERE board_id = :board_id");
		DB::setParam("board_id", $boardID);
		$boardExportData["attachments"] = DB::fetchTableWithStoredParams("SELECT * FROM tarallo_attachments WHERE board_id = :board_id");
		$boardExportData["db_version"] = self::GetDBSetting("db_version");

		// add the data struct to the zip as json
		$exportDataJsonPath = "temp/export.json";
		File::writeToFile($exportDataJsonPath, json_encode($boardExportData));
		if (!$exportZip->addFile(File::ftpDir($exportDataJsonPath), "db.json"))
		{
			http_response_code(500);
			exit("Export failed: failed to add db data.");
		}

		// add the whole board folder (attachments + background)
		$boardBaseDir = File::ftpDir("boards/$boardId/");
		$dirIterator = new RecursiveDirectoryIterator($boardBaseDir);
		$fileIterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

		foreach ($fileIterator as $file) {
			if ($file->isDir())
				continue;

			$absFilePath = $file->getRealPath();
			$zippedFilePath = str_replace($boardBaseDir, '', $absFilePath);
			if (!$exportZip->addFile($absFilePath, $zippedFilePath))
			{
				http_response_code(500);
				exit("Export failed: failed to add attachments.");
			}
		}

		// finalize the zip file
		if ($exportZip->status != ZIPARCHIVE::ER_OK)
		{
			http_response_code(500);
			exit("Export failed: zip status error.");
		}
		if (!$exportZip->close())
		{
			http_response_code(500);
			exit("Export failed: cannot close zip file.");
		}

		//output zip file as download
		$downloadName =  "export - " . strtolower($boardData["title"]) . " " . date("Y-m-d H-i-s")  . ".zip";
		File::outputFile($exportPath, File::getMimeType("zip"), $downloadName, true);
	}

	public static function UploadChunk($request)
	{
		if (!self::IsUserLoggedIn())
		{
			http_response_code(403);
			exit("Cannot upload data without being logged in.");
		}

		// validate context
		switch ($request["context"])
		{
			case "ImportBoard":
				if (!$_SESSION["is_admin"] && !self::GetDBSetting("board_import_enabled"))
				{
					http_response_code(403);
					exit("Board import is disabled on this server! (upload)");
				}
				$destFilePath = self::TEMP_EXPORT_PATH;
				break;
			default:
				http_response_code(400);
				exit("Invalid context.");
		}

		// decode content and write to file
		$writeFlags = $request["chunkIndex"] === 0 ? 0 : FILE_APPEND; // append if its not the first chunk
		$chunkContent = base64_decode($request["data"]);
		File::writeToFile($destFilePath, $chunkContent, $writeFlags);

		// prepare the response
		$response = array();
		$response["size"] = filesize(File::ftpDir($destFilePath));
		return $response;
	}

	private static function UpdateBoardLabelsInternal($boardID, $labelNames, $labelColors)
	{
		$labelsString = implode(",", $labelNames);
		$labelColorsString = implode(",", $labelColors);

		if (strlen($labelsString) >= self::MAX_LABEL_FIELD_LEN || strlen($labelColorsString) >= self::MAX_LABEL_FIELD_LEN)
		{
			http_response_code(400);
			exit("The label configuration cannot be saved.");
		}

		DB::setParam("label_names", $labelsString);
		DB::setParam("label_colors", $labelColorsString);
		DB::setParam("board_id", $boardID);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET label_names = :label_names, label_colors = :label_colors WHERE id = :board_id");
	}

	private static function TryApplyingDBPatch($sqlFilePath) 
	{
		if (!File::fileExists($sqlFilePath))
			return false;

		// load sql patch file and execute it
		$sql = File::readFileAsString($sqlFilePath);
		DB::run($sql);
		return true;
	}

	private static function ApplyDBUpdates($dbVersion)
	{
		$anyUpdateApplied = false;
		$cleanVersion = (int)str_replace("1-", "", $dbVersion); // clean old version format

		// check if new updates are available and apply them
		do
		{
			// check if the next version patch file exists
			$nextVersion = $cleanVersion + 1;
			$dbUpdatePatch = "dbpatch/update_{$cleanVersion}_to_{$nextVersion}.sql";
			
			// try applying this patch
			if (!self::TryApplyingDBPatch($dbUpdatePatch))
				break; // not available, db is up to date!

			// check the next version
			$cleanVersion = $nextVersion;
			$anyUpdateApplied = true;

		} while(true);

		return $anyUpdateApplied;
	}

	private static function DeleteAttachmentFiles($attachmentRecord)
	{
		$attachmentPath = self::GetAttachmentFilePathFromRecord($attachmentRecord);
		File::deleteFile($attachmentPath);
		$thumbnailPath = self::GetThumbnailFilePathFromRecord($attachmentRecord);
		File::deleteFile($thumbnailPath);
	}

    /**
     * Internal helper to delete a card.
     * @param int $boardID The ID of the board.
     * @param int $cardID The ID of the card.
     * @param bool $deleteAttachments If TRUE will delete attachments as well.
     * @return array The result of the operation.
     * @throws Throwable if database update fails.
     */
    private static function DeleteCardInternal(int $boardID, int $cardID, bool $deleteAttachments = true): array
    {
        // Fetch card
        $cardRecord = DB::fetchRow(
            "SELECT * FROM tarallo_cards WHERE id = :id",
            ['id' => $cardID]
        );

        if (!$cardRecord) {
            throw new RuntimeException("Card not found");
        }
        if ((int)$cardRecord['board_id'] !== $boardID) {
            throw new RuntimeException("Card is not in the specified board");
        }

        DB::beginTransaction();
        try {
            // Link previous card to next
            if (!empty($cardRecord['prev_card_id'])) {
                DB::query(
                    "UPDATE tarallo_cards
                 SET next_card_id = :next
                 WHERE id = :prev",
                    [
                        'next' => $cardRecord['next_card_id'] ?: 0,
                        'prev' => $cardRecord['prev_card_id']
                    ]
                );
            }

            // Link next card to previous
            if (!empty($cardRecord['next_card_id'])) {
                DB::query(
                    "UPDATE tarallo_cards
                 SET prev_card_id = :prev
                 WHERE id = :next",
                    [
                        'prev' => $cardRecord['prev_card_id'] ?: 0,
                        'next' => $cardRecord['next_card_id']
                    ]
                );
            }

            // Delete the card
            DB::query(
                "DELETE FROM tarallo_cards WHERE id = :id",
                ['id' => $cardID]
            );

            // Delete attachments
            if ($deleteAttachments) {
                $attachments = DB::fetchTable(
                    "SELECT * FROM tarallo_attachments WHERE card_id = :id",
                    ['id' => $cardID]
                );
                foreach ($attachments as $att) {
                    self::DeleteAttachmentFiles($att);
                }
                DB::query(
                    "DELETE FROM tarallo_attachments WHERE card_id = :id",
                    ['id' => $cardID]
                );
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $cardRecord;
    }

    /**
     * Internal helper to add a new card.
     * @param int $boardID The ID of the board
     * @param int $cardlistID The ID of the card list
     * @param int $prevCardID The previous card ID
     * @param string $title The title of the card
     * @param string $content The content of the card
     * @param int $coverAttachmentID The ID of the cover attachment
     * @param int $lastMovedTime The last time the card was moved
     * @param int $labelMask The labels that are set
     * @param int $flagMask Any flags that are set
     * @return array The new card data if successful
     * @throws RuntimeException if the database fails to update.
     */
    private static function AddNewCardInternal(
        int $boardID,
        int $cardlistID,
        int $prevCardID,
        string $title,
        string $content,
        int $coverAttachmentID,
        int $lastMovedTime,
        int $labelMask,
        int $flagMask
    ): array {
        // Count cards in destination list
        $cardCount = (int) DB::fetchOne(
            "SELECT COUNT(*) FROM tarallo_cards WHERE cardlist_id = :cid",
            ['cid' => $cardlistID]
        );

        if ($cardCount === 0 && $prevCardID > 0) {
            throw new RuntimeException("Previous card ID not in empty list");
        }

        $nextCardID  = 0;
        $prevCardRec = null;
        $nextCardRec = null;

        if ($cardCount > 0) {
            // Find next card
            $nextCardRec = DB::fetchRow(
                "SELECT * FROM tarallo_cards WHERE cardlist_id = :cid AND prev_card_id = :pid",
                ['cid' => $cardlistID, 'pid' => $prevCardID]
            );

            // Validate prev card
            if ($prevCardID > 0) {
                $prevCardRec = DB::fetchRow(
                    "SELECT * FROM tarallo_cards WHERE cardlist_id = :cid AND id = :pid",
                    ['cid' => $cardlistID, 'pid' => $prevCardID]
                );
                if (!$prevCardRec) {
                    throw new \RuntimeException("Previous card ID {$prevCardID} invalid");
                }
            }

            if ($nextCardRec) {
                $nextCardID = (int) $nextCardRec['id'];
            }
        }

        // Transaction to insert/update links
        DB::beginTransaction();
        try {
            $newCardID = DB::insert(
                "INSERT INTO tarallo_cards 
                (title, content, prev_card_id, next_card_id, cardlist_id, board_id, cover_attachment_id, last_moved_time, label_mask, flags)
             VALUES 
                (:title, :content, :prev_id, :next_id, :cid, :bid, :cover, :last_moved, :label, :flags)",
                [
                    'title'       => $title,
                    'content'     => $content,
                    'prev_id'     => $prevCardID,
                    'next_id'     => $nextCardID,
                    'cid'         => $cardlistID,
                    'bid'         => $boardID,
                    'cover'       => $coverAttachmentID,
                    'last_moved'  => $lastMovedTime,
                    'label'       => $labelMask,
                    'flags'       => $flagMask
                ]
            );

            if ($nextCardID > 0) {
                DB::query(
                    "UPDATE tarallo_cards SET prev_card_id = :new_id WHERE id = :nid",
                    ['new_id' => $newCardID, 'nid' => $nextCardID]
                );
            }
            if ($prevCardID > 0) {
                DB::query(
                    "UPDATE tarallo_cards SET next_card_id = :new_id WHERE id = :pid",
                    ['new_id' => $newCardID, 'pid' => $prevCardID]
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return DB::fetchRow(
            "SELECT * FROM tarallo_cards WHERE id = :id",
            ['id' => $newCardID]
        );
    }


	private static function RemoveCardListFromLL($cardListData)
	{
		// re-link previous list
		if ($cardListData["prev_list_id"] > 0)
		{
			$prevCardLinkQuery = "UPDATE tarallo_cardlists SET next_list_id = :next_list_id WHERE id = :prev_list_id";
			DB::setParam("prev_list_id", $cardListData["prev_list_id"]);
			DB::setParam("next_list_id", $cardListData["next_list_id"]);
			DB::queryWithStoredParams($prevCardLinkQuery);
		}

		// re-link the next list
		if ($cardListData["next_list_id"] > 0)
		{
			$nextCardLinkQuery = "UPDATE tarallo_cardlists SET prev_list_id = :prev_list_id WHERE id = :next_list_id";
			DB::setParam("prev_list_id", $cardListData["prev_list_id"]);
			DB::setParam("next_list_id", $cardListData["next_list_id"]);
			DB::queryWithStoredParams($nextCardLinkQuery);
		}
	}

	private static function DeleteCardListInternal($cardListData)
	{
		// delete the list
		try
		{
			DB::beginTransaction();

			self::RemoveCardListFromLL($cardListData);

			// delete the list
			$deletionQuery = "DELETE FROM tarallo_cardlists WHERE id = :id";
			DB::setParam("id", $cardListData["id"]);
			DB::queryWithStoredParams($deletionQuery);

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		return $cardListData;
	}

	private static function AddCardListToLL($newListID, $prevListID, $nextListID)
	{
		if ($nextListID > 0)
		{
			// update the next card list by linking it to the new one
			DB::setParam("new_id", $newListID);
			DB::setParam("next_list_id", $nextListID);
			DB::queryWithStoredParams("UPDATE tarallo_cardlists SET prev_list_id = :new_id WHERE id = :next_list_id");
		}

		if ($prevListID > 0)
		{
			// update the prev card by linking it to the new one
			DB::setParam("new_id", $newListID);
			DB::setParam("prev_list_id", $prevListID);
			DB::queryWithStoredParams("UPDATE tarallo_cardlists SET next_list_id = :new_id WHERE id = :prev_list_id");
		}
	}

	private static function AddNewCardListInternal($boardID, $prevListID, $name) 
	{
		// count the cardlists in the destination board
		$cardListCountQuery = "SELECT COUNT(*) FROM tarallo_cardlists WHERE board_id = :board_id";
		DB::setParam("board_id", $boardID);
		$cardListCount = DB::fetchOneWithStoredParams($cardListCountQuery);

		if ($cardListCount == 0 && $prevListID > 0)
		{
			http_response_code(400);
			exit("The specified previous card list is not in the destination board.");
		}

		$nextListID = 0;
		if ($cardListCount > 0)
		{
			// board is not empty

    		// query the cardlist that will be the next after the new one
			$nextCardListQuery = "SELECT * FROM tarallo_cardlists WHERE board_id = :board_id AND prev_list_id = :prev_list_id";
			DB::setParam("board_id", $boardID);
			DB::setParam("prev_list_id", $prevListID);
			$nextCardListRecord = DB::fetchRowWithStoredParams($nextCardListQuery);

			// query prev card list data
			$preCardListRecord = false;
			if ($prevListID > 0)
			{
				$prevCardListQuery = "SELECT * FROM tarallo_cardlists WHERE board_id = :board_id AND id = :prev_list_id";
				DB::setParam("board_id", $boardID);
				DB::setParam("prev_list_id", $prevListID);
				$prevCardListRecord = DB::fetchRowWithStoredParams($prevCardListQuery);

				if (!$prevCardListRecord)
				{
					http_response_code(400);
					exit("The specified previous card list id is invalid.");
				}
			}

			if ($nextCardListRecord)
			{
				// found a list that will be next to the one that will be added
				$nextListID = $nextCardListRecord["id"];
			}
		}

		// perform queries to add the new list and update the others
		try
		{
			DB::beginTransaction();

			// add the new list with the specified name
			$addCardListQuery = "INSERT INTO tarallo_cardlists (board_id, name, prev_list_id, next_list_id)";
			$addCardListQuery .= " VALUES (:board_id, :name, :prev_list_id, :next_list_id)";
			DB::setParam("board_id", $boardID);
			DB::setParam("name", $name);
			DB::setParam("prev_list_id", $prevListID);
			DB::setParam("next_list_id", $nextListID);
			$newListID = DB::insertWithStoredParams($addCardListQuery);

			self::AddCardListToLL($newListID, $prevListID, $nextListID);

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		// re-query the added list and return its data
		return self::GetCardlistData($boardID, $newListID);
	}

	private static function CreateNewBoardInternal($title, $labelNames = "", $labelColors = "", $backgroundGUID = null)
	{
		try
		{
			DB::beginTransaction();
		
			// create a new board record
			$createBoardQuery = "INSERT INTO tarallo_boards (title, label_names, label_colors, last_modified_time, background_guid)";
			$createBoardQuery .= " VALUES (:title, :label_names, :label_colors, :last_modified_time, :background_guid)";
			DB::setParam("title", self::CleanBoardTitle($title));
			DB::setParam("label_names", $labelNames);
			DB::setParam("label_colors", $labelColors);
			DB::setParam("last_modified_time", time());
			DB::setParam("background_guid", $backgroundGUID);
			$newBoardID = DB::insertWithStoredParams($createBoardQuery);

			// create the owner permission record
			$createBoardQuery = "INSERT INTO tarallo_permissions (user_id, board_id, user_type)";
			$createBoardQuery .= " VALUES (:user_id, :board_id, :user_type)";
			DB::setParam("user_id", $_SESSION["user_id"]);
			DB::setParam("board_id", $newBoardID);
			DB::setParam("user_type", self::USERTYPE_Owner);
			DB::queryWithStoredParams($createBoardQuery);
		
			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		return $newBoardID;
	}

	private static function CardRecordToData($cardRecord)
	{
		$card = array();
		$card["title"] = $cardRecord["title"];
		$card["cardlist_id"] = $cardRecord["cardlist_id"];
		$card["id"] = $cardRecord["id"];
		$card["prev_card_id"] = $cardRecord["prev_card_id"];
		$card["next_card_id"] = $cardRecord["next_card_id"];
		$card["cover_img_url"] = "";
		if ($cardRecord["cover_attachment_id"] > 0)
		{
			$card["cover_img_url"] = self::GetThumbnailProxyUrl($cardRecord["board_id"], $cardRecord["cover_attachment_id"]);
		}
		$card["label_mask"] = $cardRecord["label_mask"];
		if ($cardRecord["last_moved_time"] != 0) 
		{
			$card["last_moved_date"] = date("l, d M Y", $cardRecord["last_moved_time"]);
		}
		$card = array_merge($card, self::CardFlagMaskToList($cardRecord["flags"]));
		return $card;
	}

	private static function CardFlagMaskToList($flagMask)
	{
		$flagList = array();
		$flagList["locked"] = $flagMask & 0x001;
		return $flagList;
	}

	private static function CardFlagListToMask($flagList)
	{
		$flagMask = 0;
		$flagMask += $flagList["locked"] ? 1 : 0;
		return $flagMask;
	}

	private static function AttachmentRecordToData($attachmentRecord)
	{
		$attachmentData = array();
		$attachmentData["id"] = $attachmentRecord["id"];
		$attachmentData["name"] = $attachmentRecord["name"];
		$attachmentData["extension"] = $attachmentRecord["extension"];
		$attachmentData["card_id"] = $attachmentRecord["card_id"];
		$attachmentData["board_id"] = $attachmentRecord["board_id"];
		$attachmentData["url"] = self::GetAttachmentProxyUrlFromRecord($attachmentRecord);
		$attachmentData["thumbnail"] = self::GetThumbnailProxyUrlFromRecord($attachmentRecord);
		return $attachmentData;
	}

	private static function BoardRecordToData($boardRecord)
	{
		$boardData = array();
		$boardData["id"] = $boardRecord["id"];
		$boardData["user_type"] = $boardRecord["user_type"];
		$boardData["title"] = $boardRecord["title"];
		$boardData["closed"] = $boardRecord["closed"];
		$boardData["background_url"] = self::GetBackgroundUrl($boardRecord["id"], $boardRecord["background_guid"]);
		$boardData["background_thumb_url"] = self::GetBackgroundUrl($boardRecord["id"], $boardRecord["background_guid"], true);
		$boardData["background_tiled"] = $boardRecord["background_guid"] ? false : true; // only the default bg is tiled for now
		$boardData["label_names"] = $boardRecord["label_names"];
		$boardData["label_colors"] = $boardRecord["label_colors"];
		$boardData["all_color_names"] = self::DEFAULT_LABEL_COLORS;
		$boardData["last_modified_date"] = date("d M Y", $boardRecord["last_modified_time"]);
		return $boardData;
	}

    /**
     * Retrieves board data for the given board ID and user,
     * including the user's permission level for that board.
     * Optionally includes card lists and cards.
     * @param int  $boardId
     * @param int  $minRole          Minimum role constant to require.
     * @param bool $includeCardLists Whether to include card lists.
     * @param bool $includeCards     Whether to include cards.
     * @return array
     * @throws RuntimeException
     */
    private static function GetBoardData(
        int $boardId,
        int $minRole = self::USERTYPE_None,
        bool $includeCardLists = false,
        bool $includeCards = false,
        bool $includeCardContent = false,
        bool $includeAttachments = false
    ): array {
        self::EnsureSession();
        
        if (empty($_SESSION['user_id'])) {
            Logger::error("GetBoardData: No user_id in session");
            throw new RuntimeException("Not logged in");
        }

        $userId = (int) $_SESSION['user_id'];

        // Base board data with user permissions
        $sql = "
        SELECT b.*, p.user_type
        FROM tarallo_boards b
        INNER JOIN tarallo_permissions p ON b.id = p.board_id
        WHERE b.id = :board_id AND p.user_id = :user_id
        LIMIT 1
    ";
        $boardRecord = DB::fetchRow($sql, [
            'board_id' => $boardId,
            'user_id'  => $userId
        ]);

        if (!$boardRecord) {
            Logger::warning("GetBoardData: Board $boardId not found or no permissions for user $userId");
            throw new RuntimeException("Board not found or access denied");
        }

        // Check minimum required role
        if (!self::CheckPermissions($boardRecord['user_type'], $minRole, false)) {
            Logger::warning("GetBoardData: User $userId has insufficient permissions for board $boardId");
            throw new RuntimeException("Permission denied");
        }

        // Convert DB record to API-friendly structure
        $boardData = self::BoardRecordToData($boardRecord);
        $boardData['user_type'] = (int) $boardRecord['user_type'];

        // Optionally pull card lists
        if ($includeCardLists) {
            $listSQL = "
            SELECT id, name, prev_list_id, next_list_id
            FROM tarallo_cardlists
            WHERE board_id = :board_id
            ORDER BY id ASC
        ";
            $boardData['cardlists'] = DB::fetchTable($listSQL, ['board_id' => $boardId], 'id');
        }

        // Optionally pull cards
        if ($includeCards) {
            $sql = "SELECT * FROM tarallo_cards WHERE board_id = :board_id ORDER BY id ASC";

            $cardsRaw = DB::fetchTable($sql, ['board_id' => $boardId]);
            $cards = array_map([self::class, 'CardRecordToData'], $cardsRaw);

            // If content not included in CardRecordToData, append from raw
            if ($includeCardContent && isset($cardsRaw[0]['content'])) {
                foreach ($cards as $i => &$c) {
                    $c['content'] = $cardsRaw[$i]['content'] ?? '';
                }
            }

            // Optionally add attachments per card
            if ($includeAttachments) {
                foreach ($cards as &$card) {
                    $attachments = DB::fetchTable(
                        "SELECT * FROM tarallo_attachments WHERE card_id = :cid",
                        ['cid' => $card['id']]
                    );
                    $card['attachmentList'] = array_map([self::class, 'AttachmentRecordToData'], $attachments);
                }
            }

            $boardData['cards'] = $cards;
        }

        Logger::debug(
            "GetBoardData: User $userId fetched board $boardId" .
            ($includeCardLists ? ' + lists' : '') .
            ($includeCards ? ' + cards' : '') .
            ($includeCardContent ? ' + content' : '') .
            ($includeAttachments ? ' + attachments' : '')
        );

        return $boardData;
    }


	private static function UpdateBoardModifiedTime($boardID)
	{
		DB::setParam("last_modified_time", time());
		DB::setParam("board_id", $boardID);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET last_modified_time = :last_modified_time WHERE id = :board_id");
	}

	private static function CheckPermissions($userType, $requestedUserType, $exitIfFailed = true)
	{
		if ($userType > $requestedUserType)
		{
			if ($exitIfFailed)
			{
				http_response_code(403);
				exit("Missing permissions to perform the requested operation.");
			}
			return false;
		}
		return true;
	}

	private static function GetCardlistData($boardID, $cardlistID)
	{
		// query and validate cardlist
		$cardlistQuery = "SELECT * FROM tarallo_cardlists WHERE id = :cardlist_id";
		DB::setParam("cardlist_id", $cardlistID);
		$cardlistData = DB::fetchRowWithStoredParams($cardlistQuery);

		if (!$cardlistData)
		{
			http_response_code(404);
			exit("The specified list does not exists.");
		}

		if ($cardlistData["board_id"] != $boardID)
		{
			http_response_code(400);
			exit("The specified list is not part of the specified board.");
		}

		return $cardlistData;
	}

	private static function GetCardData($boardID, $cardID)
	{
		// query and validate cardlist
		$cardQuery = "SELECT * FROM tarallo_cards WHERE id = :card_id";
		DB::setParam("card_id", $cardID);
		$cardData = DB::fetchRowWithStoredParams($cardQuery);

		if (!$cardData)
		{
			http_response_code(404);
			exit("The specified card does not exists.");
		}

		if ($cardData["board_id"] != $boardID)
		{
			http_response_code(400);
			exit("The card is not part of the specified board.");
		}

		return $cardData;
	}

	private static function GetAttachmentRecord($boardID, $attachmentID)
	{
		// query attachment
		DB::setParam("id", $attachmentID);
		$attachmentRecord = DB::fetchRowWithStoredParams("SELECT * FROM tarallo_attachments WHERE id = :id");

		if (!$attachmentRecord)
		{
			http_response_code(404);
			exit("Attachment not found.");
		}

		if ($attachmentRecord["board_id"] != $boardID)
		{
			http_response_code(403);
			exit("Cannot modify attachments from other boards.");
		}

		return $attachmentRecord;
	}

	private static function GetBoardContentDir($boardID)
	{
		return "boards/$boardID/";
	}

	private static function GetAttachmentDir($boardID)
	{
		return self::GetBoardContentDir($boardID) . "a/";
	}

	private static function GetAttachmentFilePath($boardID, $guid, $extension)
	{
		return self::GetAttachmentDir($boardID) . $guid . "." . $extension;
	}

	private static function GetAttachmentFilePathFromRecord($record)
	{
		return self::GetAttachmentFilePath($record["board_id"], $record["guid"], $record["extension"]);
	}

	private static function GetAttachmentProxyUrl($boardID, $attachmentID)
	{
		return "php/api.php?OP=ProxyAttachment&board_id=$boardID&id=$attachmentID";
	}

	private static function GetAttachmentProxyUrlFromRecord($record)
	{
		return self::GetAttachmentProxyUrl($record["board_id"], $record["id"]);
	}

	private static function GetBackgroundUrl($boardID, $guid, $thumbnail = false)
	{
		if ($guid) 
		{
			$guidElems = explode("#", $guid);
			return self::GetBoardContentDir($boardID) . $guidElems[0] . ($thumbnail ? "-thumb." : ".") . $guidElems[1];
		}
		else
		{
			return $thumbnail ? self::DEFAULT_BOARDTILE_BG : self::DEFAULT_BG;
		}
	}

	private static function GetThumbnailDir($boardID)
	{
		return self::GetAttachmentDir($boardID) . "t/";
	}

	private static function GetThumbnailFilePath($boardID, $guid)
	{
		return self::GetThumbnailDir($boardID) . $guid . ".jpg";
	}

	private static function GetThumbnailFilePathFromRecord($record)
	{
		return self::GetThumbnailFilePath($record["board_id"], $record["guid"]);
	}

	private static function GetThumbnailProxyUrl($boardID, $attachmentID)
	{
		return self::GetAttachmentProxyUrl($boardID, $attachmentID) . "&thumbnail=true";
	}

	private static function GetThumbnailProxyUrlFromRecord($record)
	{
		switch ($record["extension"])
		{
			case "jpg":
			case "jpeg":
			case "png":
			case "gif":
				return self::GetThumbnailProxyUrl($record["board_id"], $record["id"]);
		}	
		return false;
	}

    /**
     * Ensures a PHP session is started.
     * Call this at the start of any function that uses $_SESSION.
     */
    private static function EnsureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Checks to see if a user is logged in.
     * @return bool TRUE if a user is logged into the session.
     */
    private static function IsUserLoggedIn(): bool
    {
        self::EnsureSession();
        return !empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
    }

    /**
     * Logs a user out of the current session.
     */
    private static function LogoutInternal(): void
    {
        self::EnsureSession();

        // Clear all session variables
        $_SESSION = [];

        // Delete the session cookie (if any)
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destroy the session file
        session_destroy();

        // Regenerate session ID after logout (good practice)
        session_start();
        session_regenerate_id(true);
    }

	private static function CleanBoardTitle($title)
	{
		return substr($title, 0, 64);
	}

	private static function CleanAttachmentName($name)
	{
		return substr($name, 0, 100);
	}

	private static function CleanLabelName($name)
	{
		$name = str_replace(',', ' ', $name);
		return substr($name, 0, 32);
	}

	private static function CompareTrelloSortedItems($a, $b)
	{
		if ($a["pos"] == $b["pos"])
			return 0;

		return ($a["pos"] < $b["pos"]) ? -1 : 1;
	}

	private static function GetDBSetting($name)
	{
		DB::setParam("name", $name);
		return DB::fetchOneWithStoredParams("SELECT value FROM tarallo_settings WHERE name = :name");
	}

	private static function SetDBSetting($name, $value)
	{
		DB::setParam("name", $name);
		DB::setParam("value", $value);
		return DB::queryWithStoredParams("UPDATE tarallo_settings SET value = :value WHERE name = :name");
	}

	private static function GetDBSettings()
	{
		return DB::fetchAssocWithStoredParams("SELECT name, value FROM tarallo_settings", "name", "value");
	}

	private static function UsernameExists($username) 
	{
		// check if the specified username already exists
		$userQuery = "SELECT COUNT(*) FROM tarallo_users where username = :username";
		DB::setParam("username", $username);

		return DB::fetchOneWithStoredParams($userQuery);
	}

	private static function AddUserInternal($username, $password, $displayName, $isAdmin = false)
	{
		// add the new user record to the DB
		$passwordHash = password_hash($password, PASSWORD_DEFAULT);
		$addUserQuery = "INSERT INTO tarallo_users (username, password, display_name, register_time, last_access_time, is_admin)";
		$addUserQuery .= " VALUES(:username, :password, :display_name, :register_time, 0, :is_admin)";
		DB::setParam("username", $username);
		DB::setParam("password", $passwordHash);
		DB::setParam("display_name", $displayName);
		DB::setParam("register_time", time());
		DB::setParam("is_admin", $isAdmin ? 1 : 0);
		$userId = DB::insertWithStoredParams($addUserQuery);
		return $userId;
	}

	private static function CreateNewAdminAccount()
	{
		$account = array();
		$account["username"] = "admin";

		// find the first available admin* account name
		{
			$userQuery = "SELECT username FROM tarallo_users where username LIKE 'admin%'";
			$usedAdminNames = DB::fetchArrayWithStoredParams($userQuery, "username");
			for ($i = 0; in_array($account["username"], $usedAdminNames); $account["username"] = "admin" . ++$i) ;
		}

		// generate a random password for it
		{
			$passBytes = openssl_random_pseudo_bytes(24);
			$account["password"] = rtrim(strtr(base64_encode($passBytes), '+/', '-_'), '=');
		}
	
		// add the new user record to the DB
		$account["id"] = self::AddUserInternal($account["username"], $account["password"], "Admin", true);
		if (!$account["id"])
		{
			http_response_code(500);
			exit("Failed to create admin account (DB error).");
		}

		return $account;
	}
}

?>