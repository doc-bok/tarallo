<?php

declare(strict_types=1);

require_once __DIR__ . '/account.php';
require_once __DIR__ . '/attachment.php';
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/card.php';
require_once __DIR__ . '/cardlist.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/file.php';
require_once __DIR__ . '/json.php';
require_once __DIR__ . '/label.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/permission.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/utils.php';

// page initialization
header('Content-Type: application/json; charset=utf-8');
session_start(['cookie_samesite' => 'Strict',]);

// initialize parameters
$request = Json::decodePostJSON(); // params posted as json
$request = array_merge($request == null ? array() : $request, $_GET); // params added to the url

// check the api call name has been specified
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
	const MAX_LABEL_COUNT = 24;
	const MAX_LABEL_FIELD_LEN = 400;
	const TEMP_EXPORT_PATH = "temp/export.zip";

    public static function GetCurrentPage(array $request): array
    {
        return Page::getCurrentPage($request);
    }

    public static function GetBoardListPage(): array
    {
        return Page::getBoardListPage();
    }
    
    public static function GetBoardPage(array $request): array
    {
        return Page::getBoardPage($request);
    }

    public static function Login(array $request): array
    {
        return Session::login($request);
    }

    public static function Register(array $request): array
    {
        return Account::register($request);
    }

    public static function Logout(array $request): array
    {
        return Session::logout($request);
    }

    public static function OpenCard(array $request): array
    {
        return Card::openCard($request);
    }

    public static function AddNewCard(array $request): array
    {
        return Card::addNewCard($request);
    }

    public static function DeleteCard(array $request): array
    {
        return Card::deleteCard($request);
    }

    public static function MoveCard(array $request): array
    {
        return Card::moveCard($request);
    }

    public static function MoveCardList(array $request): array
    {
        return CardList::moveCardList($request);
    }

    public static function UpdateCardTitle(array $request): array
    {
        return Card::updateCardTitle($request);
    }

    /**
     * Updates a card's content.
     * @param array $request The request parameters.
     * @return array The updated card data.
     */
    public static function UpdateCardContent(array $request): array
    {
        Session::ensureSession();

        $userId    = $_SESSION['user_id'] ?? null;
        $boardId   = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardId    = isset($request['id']) ? (int)$request['id'] : 0;
        $newContent = $request['content'] ?? ''; // allow empty string but still check type

        // === Basic validation ===
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        if ($boardId <= 0 || $cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // === Permission check ===
        try {
            Board::GetBoardData($boardId, Permission::USERTYPE_Member);
        } catch (\RuntimeException $e) {
            Logger::warning("UpdateCardContent: User $userId tried to edit card $cardId on board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // === Card ownership check ===
        try {
            $cardRecord = Card::getCardData($boardId, $cardId);
        } catch (RuntimeException $e) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // === Perform update ===
        try {
            DB::query(
                "UPDATE tarallo_cards SET content = :content WHERE id = :id",
                ['content' => $newContent, 'id' => $cardId]
            );
            DB::UpdateBoardModifiedTime($boardId);
        } catch (Throwable $e) {
            Logger::error("UpdateCardContent: DB error on card $cardId (board $boardId) - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Failed to update card content'];
        }

        // === Update local record to reflect change ===
        $cardRecord['content'] = $newContent;

        Logger::info("UpdateCardContent: User $userId updated content of card $cardId in board $boardId");

        return Card::cardRecordToData($cardRecord);
    }

    /**
     * Updates a card's flags.
     * @param array $request The request parameters.
     * @return array The updated card data.
     */
    public static function UpdateCardFlags(array $request): array
    {
        Session::ensureSession();

        $userId  = $_SESSION['user_id'] ?? null;
        $boardId = isset($request['board_id']) ? (int)$request['board_id'] : 0;
        $cardId  = isset($request['id']) ? (int)$request['id'] : 0;

        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        if ($boardId <= 0 || $cardId <= 0) {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Permission check
        try {
            Board::GetBoardData($boardId, Permission::USERTYPE_Member);
        } catch (RuntimeException $e) {
            Logger::warning("UpdateCardFlags: User $userId tried to update flags on card $cardId in board $boardId without permission");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Card existence/ownership check
        try {
            $cardRecord = Card::getCardData($boardId, $cardId);
        } catch (RuntimeException $e) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // Calculate new flag mask
        $flagList = Card::cardFlagMaskToList($cardRecord['flags']);
        if (array_key_exists('locked', $request)) {
            $flagList['locked'] = (bool)$request['locked'];
        }
        $cardRecord['flags'] = self::CardFlagListToMask($flagList);

        // Update DB
        try {
            DB::query(
                "UPDATE tarallo_cards SET flags = :flags WHERE id = :id",
                ['flags' => $cardRecord['flags'], 'id' => $cardId]
            );
            DB::UpdateBoardModifiedTime($boardId);
        } catch (Throwable $e) {
            Logger::error("UpdateCardFlags: DB error on card $cardId in board $boardId - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Failed to update card flags'];
        }

        Logger::info("UpdateCardFlags: User $userId updated flags for card $cardId in board $boardId (new flags: {$cardRecord['flags']})");

        return Card::cardRecordToData($cardRecord);
    }

    /**
     * Upload an attachment
     * @param array $request The request parameters.
     * @return array|string[]
     */
    public static function UploadAttachment(array $request): array
    {
        Session::ensureSession();

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            return ['error' => 'Not logged in'];
        }

        // Validate required inputs
        $boardId    = isset($request['board_id']) ? (int) $request['board_id'] : 0;
        $cardId     = isset($request['card_id']) ? (int) $request['card_id'] : 0;
        $filename   = trim($request['filename'] ?? '');
        $attachment = $request['attachment'] ?? '';

        if ($boardId <= 0 || $cardId <= 0 || $filename === '' || $attachment === '') {
            http_response_code(400);
            return ['error' => 'Missing or invalid parameters'];
        }

        // Check attachment size limit
        $maxAttachmentSizeKB = (int) self::GetDBSetting('attachment_max_size_kb');
        
        // Base64 encoding inflates size ~33% (hence * 0.75 to reverse)
        $attachmentSizeKB = (strlen($attachment) * 0.75) / 1024;
        if ($maxAttachmentSizeKB > 0 && $attachmentSizeKB > $maxAttachmentSizeKB) {
            http_response_code(400);
            return ['error' => "Attachment is too big! Max size is {$maxAttachmentSizeKB} KB"];
        }

        // Permission check: require Member role on board
        try {
            Board::GetBoardData($boardId, Permission::USERTYPE_Member);
        } catch (RuntimeException) {
            Logger::warning("UploadAttachment: User {$userId} no permission on board {$boardId}");
            http_response_code(403);
            return ['error' => 'Access denied'];
        }

        // Validate card belongs to board
        try {
            Card::getCardData($boardId, $cardId);
        } catch (\RuntimeException) {
            http_response_code(404);
            return ['error' => 'Card not found in this board'];
        }

        // Prepare attachment metadata
        $fileInfo = pathinfo($filename);
        $name      = self::CleanAttachmentName($fileInfo['filename'] ?? '');
        $extension = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : 'bin';
        $guid      = uniqid('', true);

        // Insert attachment record in DB
        $insertSql = "INSERT INTO tarallo_attachments (name, guid, extension, card_id, board_id)
                  VALUES (:name, :guid, :extension, :card_id, :board_id)";
        try {
            $attachmentID = DB::insert($insertSql, [
                'name'      => $name,
                'guid'      => $guid,
                'extension' => $extension,
                'card_id'   => $cardId,
                'board_id'  => $boardId
            ]);
            
            if (!$attachmentID) {
                Logger::error("UploadAttachment: Failed to insert attachment record for board $boardId");
                http_response_code(500);
                return ['error' => 'Failed to save new attachment'];
            }
        } catch (Throwable $e) {
            Logger::error("UploadAttachment: Database error - " . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Database error saving attachment'];
        }

        // Decode base64 content and save file
        $filePath = Attachment::getAttachmentFilePath($boardId, $guid, $extension);
        $fileContent = base64_decode($attachment);

        if ($fileContent === false) {
            http_response_code(400);
            return ['error' => 'Invalid attachment base64 data'];
        }

        if (!File::writeToFile($filePath, $fileContent)) {
            Logger::error("UploadAttachment: Failed to write file to {$filePath}");
            http_response_code(500);
            return ['error' => 'Failed to save attachment file'];
        }

        // Create thumbnail if possible
        $thumbFilePath = Attachment::getThumbnailFilePath($boardId, $guid);
        Utils::createImageThumbnail($filePath, $thumbFilePath);
        if (File::fileExists($thumbFilePath)) {
            try {
                DB::query(
                    "UPDATE tarallo_cards SET cover_attachment_id = :attachment_id WHERE id = :card_id",
                    ['attachment_id' => $attachmentID, 'card_id' => $cardId]
                );
            } catch (\Throwable $e) {
                Logger::error("UploadAttachment: Failed to set cover attachment - " . $e->getMessage());
                // Not fatal; continue without failing the upload
            }
        }

        DB::UpdateBoardModifiedTime($boardId);

        // Re-fetch attachment record and card data for response
        $attachmentRecord = self::GetAttachmentRecord($boardId, $attachmentID);
        $cardRecord = Card::getCardData($boardId, $cardId);

        $response = Attachment::attachmentRecordToData($attachmentRecord);
        $response['card'] = Card::cardRecordToData($cardRecord);

        Logger::info("UploadAttachment: User $userId uploaded attachment $attachmentID to card $cardId in board $boardId");

        return $response;
    }


	public static function UploadBackground($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"]);

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
		$newBackgroundPath = Board::getBackgroundUrl($request["board_id"], $guid);
		$fileContent = base64_decode($request["background"]);
		File::writeToFile($newBackgroundPath, $fileContent);

		// save a thumbnail copy of it for board tiles
		$newBackgroundThumbPath = Board::getBackgroundUrl($request["board_id"], $guid, true);
		Utils::createImageThumbnail($newBackgroundPath, $newBackgroundThumbPath);

		// delete old background files
		if (stripos($boardData["background_url"], Board::DEFAULT_BG) === false) 
		{
			File::deleteFile($boardData["background_url"]);
			File::deleteFile($boardData["background_thumb_url"]);
		}

		// update background in DB
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("background_guid", $guid);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET background_guid = :background_guid WHERE id = :board_id");

		DB::UpdateBoardModifiedTime($request["board_id"]);

		$boardData["background_url"] = $newBackgroundPath;
		$boardData["background_tiled"] = false;
		$boardData["background_thumb_url"] = $newBackgroundThumbPath;
		return $boardData;
	}

	public static function DeleteAttachment($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Member);

		// query attachment
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $request["id"]);

		// delete attachments files
		Attachment::deleteAttachmentFiles($attachmentRecord);

		// delete attachment from db
		$deletionQuery = "DELETE FROM tarallo_attachments WHERE id = :id";
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams($deletionQuery);

		// delete from cover image if any
		DB::setParam("attachment_id", $attachmentRecord["id"]);
		DB::setParam("card_id", $attachmentRecord["card_id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cards SET cover_attachment_id = 0 WHERE cover_attachment_id = :attachment_id AND id = :card_id");

		DB::UpdateBoardModifiedTime($request["board_id"]);

		// re-query added attachment and card and return their data
		$response = Attachment::attachmentRecordToData($attachmentRecord);
		$cardRecord = Card::getCardData($attachmentRecord["board_id"], $attachmentRecord["card_id"]);
		$response["card"] = Card::cardRecordToData($cardRecord);
		return $response;
	}

	public static function UpdateAttachmentName($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Member);

		// query attachment
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $request["id"]);

		// update attachment name
		$filteredName = self::CleanAttachmentName($request["name"]);

		DB::setParam("id", $attachmentRecord["id"]);
		DB::setParam("name", $filteredName);
		DB::queryWithStoredParams("UPDATE tarallo_attachments SET name = :name WHERE id = :id");

		DB::UpdateBoardModifiedTime($request["board_id"]);

		// return the updated attachment data
		$attachmentRecord["name"] = $filteredName;
		$response = Attachment::attachmentRecordToData($attachmentRecord);
		return $response;
	}

	public static function ProxyAttachment($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Observer);

		// query attachment
		$attachmentRecord = self::GetAttachmentRecord($request["board_id"], $request["id"]);

		// output just the file (or its thumbnail)
		if (isset($request["thumbnail"]))
		{
			$attachmentPath = Attachment::GetThumbnailFilePathFromRecord($attachmentRecord);
		}
		if (!isset($request["thumbnail"]) || !File::fileExists($attachmentPath))
		{
			$attachmentPath = Attachment::getAttachmentFilePathFromRecord($attachmentRecord);
		}

		$mimeType = File::getMimeType($attachmentRecord["extension"]);
		$downloadName = $attachmentRecord["name"] . "." . $attachmentRecord["extension"];
		$isImage = stripos($mimeType, "image") === 0;

		File::outputFile($attachmentPath, $mimeType, $downloadName, !$isImage);
	}

	public static function UpdateCardListName($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData((int)$request["board_id"]);

		//query and validate cardlist id
		$cardlistData = Card::GetCardlistData((int)$request["board_id"], $request["id"]);

		// update the cardlist name
		DB::setParam("name", $request["name"]);
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cardlists SET name = :name WHERE id = :id");

		DB::UpdateBoardModifiedTime((int)$request["board_id"]);

		// return the cardlist data
		$cardlistData["name"] = $request["name"];
		return $cardlistData;
	}

	public static function AddCardList($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData((int)$request["board_id"]);

		// insert the new cardlist
		$newCardListData = self::AddNewCardListInternal($boardData["id"], $request["prev_list_id"], $request["name"]);

		DB::UpdateBoardModifiedTime((int)$request["board_id"]);

		return $newCardListData;
	}

	public static function DeleteCardList($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"]);

		//query and validate cardlist id
		$cardListData = Card::GetCardlistData($request["board_id"], $request["id"]);

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

		DB::UpdateBoardModifiedTime($request["board_id"]);

		return $cardListData;
	}

	public static function UpdateBoardTitle($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"]);

		// update the board title
		DB::setParam("title", self::CleanBoardTitle($request["title"]));
		DB::setParam("id", $request["board_id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET title = :title WHERE id = :id");

		DB::UpdateBoardModifiedTime($request["board_id"]);

		// requery and return the board data
		return Board::GetBoardData($request["board_id"]);
	}

	public static function CreateNewBoard($request)
	{
		if (!Session::isUserLoggedIn())
		{
			http_response_code(403);
			exit("Cannot create a new board without being logged in.");
		}

		// create the new board
		$newBoardID = self::CreateNewBoardInternal($request["title"]);

		// re-query and return the new board data
		return Board::GetBoardData((int)$newBoardID);
	}

	public static function CloseBoard($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["id"]);

		// mark the board as closed
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET closed = 1 WHERE id = :id");

		DB::UpdateBoardModifiedTime($request["id"]);

		$boardData["closed"] = 1;
		return $boardData;
	}

	public static function ReopenBoard($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["id"]);

		// mark the board as closed
		DB::setParam("id", $request["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET closed = 0 WHERE id = :id");

		DB::UpdateBoardModifiedTime($request["board_id"]);

		$boardData["closed"] = 0;
		return $boardData;
	}

	public static function DeleteBoard($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["id"]);

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
		$boardDir = Board::getBoardContentDir($boardID);
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

		if (!Session::isUserLoggedIn())
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
			$boardFolder = Board::getBoardContentDir($newBoardID);
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
		return Board::GetBoardData((int)$newBoardID);
	}

	public static function ImportFromTrello($request) 
	{
		if (!$_SESSION["is_admin"] && !self::GetDBSetting("trello_import_enabled"))
		{
			http_response_code(403);
			exit("Importing boards from Trello is disabled on this server!");
		}

		if (!Session::isUserLoggedIn())
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
				$labelColors[] = Label::DEFAULT_LABEL_COLORS[count($labelColors) % count(Label::DEFAULT_LABEL_COLORS)];
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
		return Board::GetBoardData((int)$newBoardID);
	}

	public static function CreateBoardLabel($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"]);

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
		$newLabelColor = Label::DEFAULT_LABEL_COLORS[$labelIndex % count(Label::DEFAULT_LABEL_COLORS)];
		$boardLabelNames[$labelIndex] = $newLabelColor;
		$boardLabelColors[$labelIndex] = $newLabelColor;

		// update the board
		self::UpdateBoardLabelsInternal($request["board_id"], $boardLabelNames, $boardLabelColors);
		DB::UpdateBoardModifiedTime($request["board_id"]);

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
		$boardData = Board::GetBoardData($request["board_id"]);

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
		DB::UpdateBoardModifiedTime($request["board_id"]);

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
		$boardData = Board::GetBoardData($request["board_id"]);

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
		DB::UpdateBoardModifiedTime($request["board_id"]);

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
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Member);
		

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
		$cardData = Card::getCardData($request["board_id"], $request["card_id"]);

		// create the new mask
		$labelMask = $cardData["label_mask"];
		$selectMask = 1 << $labelIndex;
		$labelMask = ($labelMask & ~$selectMask) + $labelActive * $selectMask;

		// update the card
		DB::setParam("label_mask", $labelMask);
		DB::setParam("card_id", $cardData["id"]);
		DB::queryWithStoredParams("UPDATE tarallo_cards SET label_mask = :label_mask WHERE id = :card_id");

		DB::UpdateBoardModifiedTime($request["board_id"]);

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
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Moderator);

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
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Moderator);
		$isSpecialPermission = $request["user_id"] < 0;

		if ($isSpecialPermission)
		{
			if (!$_SESSION["is_admin"]) 
			{
				http_response_code(403);
				exit("Special permissions are only available to site admins.");
			}

			if ($request["user_id"] < Account::userId_MIN)
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

		DB::UpdateBoardModifiedTime($request["board_id"]);

		// query back for the updated permission
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("user_id", $request["user_id"]);
		$permission = DB::fetchRowWithStoredParams($boardPermissionsQuery);

		return $permission;
	}

	public static function RequestBoardAccess($request)
	{
		// query and validate board id and access level
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_None);

		if ($boardData["user_type"] < Permission::USERTYPE_Guest) 
		{
			http_response_code(400);
			exit("The user is already allowed to view this board!");
		}

		DB::setParam("user_id", $_SESSION["user_id"]);
		DB::setParam("board_id", $request["board_id"]);
		DB::setParam("user_type", Permission::USERTYPE_Guest);

		// add new permission or update existing one
		if ($boardData["user_type"] == Permission::USERTYPE_None)
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
		$boardData = Board::GetBoardData($request["board_id"], Permission::USERTYPE_Moderator);
		$boardId = $boardData["id"];

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
		DB::setParam("id", $boardId);
		$boardExportData = DB::fetchRowWithStoredParams("SELECT * FROM tarallo_boards WHERE id = :id");
		DB::setParam("board_id", $boardId);
		$boardExportData["cardlists"] = DB::fetchTableWithStoredParams("SELECT * FROM tarallo_cardlists WHERE board_id = :board_id");
		DB::setParam("board_id", $boardId);
		$boardExportData["cards"] = DB::fetchTableWithStoredParams("SELECT * FROM tarallo_cards WHERE board_id = :board_id");
		DB::setParam("board_id", $boardId);
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
		if (!Session::isUserLoggedIn())
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

	private static function DeleteCardListInternal($cardListData)
	{
		// delete the list
		try
		{
			DB::beginTransaction();

			CardList::removeCardListFromLL($cardListData);

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

			CardList::addCardListToLL((int)$newListID, (int)$prevListID, $nextListID);

			DB::commit();
		}
		catch(Exception $e)
		{
			DB::rollBack();
			throw $e;
		}

		// re-query the added list and return its data
		return Card::GetCardlistData($boardID, (int)$newListID);
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
			DB::setParam("user_type", Permission::USERTYPE_Owner);
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

	private static function CardFlagListToMask($flagList)
	{
		$flagMask = 0;
		$flagMask += $flagList["locked"] ? 1 : 0;
		return $flagMask;
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
}

?>