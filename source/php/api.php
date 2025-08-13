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

	public static function DeleteCardList(array $request): array
	{
		return CardList::deleteCardList($request);
	}

	public static function UpdateBoardTitle(array $request): array
	{
		return Board::updateBoardTitle($request);
	}

	public static function CreateNewBoard(array $request): array
	{
        return Board::createNewBoard($request);
	}

	public static function CloseBoard(array $request): array
	{
		return Board::closeBoard($request);
	}

	public static function ReopenBoard(array $request): array
	{
		return Board::reopenBoard($request);
	}

	public static function DeleteBoard(array $request): array
	{
		return Board::deleteBoard($request);
	}

	public static function ImportBoard(): array
	{
		return Board::importBoard();
	}

	public static function ImportFromTrello(array $request): array
	{
		return Board::importFromTrello($request);
	}

	public static function CreateBoardLabel(array $request): array
	{
        return Label::createBoardLabel($request);
	}

	public static function UpdateBoardLabel(array $request): array
	{
		return Label::updateBoardLabel($request);
	}

	public static function DeleteBoardLabel(array $request): array
	{
		return Label::deleteBoardLabel($request);
	}

	public static function SetCardLabel(array $request): array
	{
		return Label::setCardLabel($request);
	}

	public static function GetBoardPermissions(array $request): array
    {
		return Permission::getBoardPermissions($request);
	}

	public static function SetUserPermission(array $request): array
    {
        return Permission::setUserPermission($request);
	}

	public static function RequestBoardAccess(array $request): array
	{
		return Permission::requestBoardAccess($request);
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
		$exportPath = Board::TEMP_EXPORT_PATH;
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
				$destFilePath = Board::TEMP_EXPORT_PATH;
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
}

?>