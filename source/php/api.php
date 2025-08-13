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

    public static function UpdateCardContent(array $request): array
    {
        return Card::updateCardContent($request);
    }

    public static function UpdateCardFlags(array $request): array
    {
        return Card::updateCardFlags($request);
    }

    public static function UploadAttachment(array $request): array
    {
        return Attachment::uploadAttachment($request);
    }

    public static function UploadBackground(array $request): array
    {
        return Board::uploadBackground($request);
    }

	public static function DeleteAttachment(array $request): array
    {
		return Attachment::deleteAttachment($request);
	}

	public static function UpdateAttachmentName(array $request): array
	{
		return Attachment::updateAttachmentName($request);
	}

	public static function ProxyAttachment(array $request): void
	{
		Attachment::proxyAttachment($request);
	}

	public static function UpdateCardListName(array $request): array
	{
		return CardList::updateCardListName($request);
	}

	public static function AddCardList(array $request): array
	{
		return CardList::addCardList($request);
	}

	public static function DeleteCardList($request)
	{
		return CardList::deleteCardList($request);
	}

	public static function UpdateBoardTitle($request)
	{
		// query and validate board id
		$boardData = Board::GetBoardData($request["board_id"]);

		// update the board title
		DB::setParam("title", self::CleanBoardTitle($request["title"]));
		DB::setParam("id", $request["board_id"]);
		DB::queryWithStoredParams("UPDATE tarallo_boards SET title = :title WHERE id = :id");

		DB::updateBoardModifiedTime($request["board_id"]);

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

		DB::updateBoardModifiedTime($request["id"]);

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

		DB::updateBoardModifiedTime($request["board_id"]);

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
		if (!$_SESSION["is_admin"] && !DB::getDBSetting("board_import_enabled"))
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
		if (!$_SESSION["is_admin"] && !DB::getDBSetting("trello_import_enabled"))
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
			$newCardlistData = CardList::addNewCardListInternal($newBoardID, $prevCardlistID, $curTrelloList["name"]);
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
		DB::updateBoardModifiedTime($request["board_id"]);

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
		DB::updateBoardModifiedTime($request["board_id"]);

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
		DB::updateBoardModifiedTime($request["board_id"]);

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

		DB::updateBoardModifiedTime($request["board_id"]);

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

		DB::updateBoardModifiedTime($request["board_id"]);

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
		if (!$_SESSION["is_admin"] && !DB::getDBSetting("board_export_enabled"))
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
		$boardExportData["db_version"] = DB::getDBSetting("db_version");

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
				if (!$_SESSION["is_admin"] && !DB::getDBSetting("board_import_enabled"))
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

	private static function CleanBoardTitle($title)
	{
		return substr($title, 0, 64);
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
}

?>