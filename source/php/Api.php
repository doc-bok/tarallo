<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

// Load the environment variables from the .env file.
use Dotenv\Dotenv;

/**
 * Contains all the Tarallo API calls.
 */
class Api
{
    // Allowed operations by old API.
    private const ALLOWED_OPERATIONS = [
        'GET' => [
            'OpenCard',
            'GetBoardPermissions',
            'ExportBoard'
        ],
        'POST' => [
            'Login',
            'Logout',
            'Register',
            'AddNewCard',
            'UploadAttachment',
            'UploadBackground',
            'AddCardList',
            'CreateNewBoard',
            'ImportBoard',
            'ImportFromTrello',
            'CreateBoardLabel',
            'RequestBoardAccess',
            'UploadChunk'
        ],
        'PUT' => [
            'MoveCard',
            'MoveCardList',
            'UpdateCardTitle',
            'UpdateCardContent',
            'UpdateCardFlags',
            'UpdateAttachmentName',
            'UpdateCardListName',
            'UpdateBoardTitle',
            'CloseBoard',
            'ReopenBoard',
            'UpdateBoardLabel',
            'SetCardLabel',
            'SetUserPermission'
        ],
        'DELETE' => [
            'DeleteCard',
            'DeleteAttachment',
            'DeleteCardList',
            'DeleteBoard',
            'DeleteBoardLabel'
        ],
    ];

    // Registered operations via new API.
    private array $registeredOperations = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => [],
    ];

    // The various components that can make up a page.
    private Page $page;

    /**
     * Construction.
     */
    public function __construct()
    {
        $this->page = new Page($this, DB::getInstance());
    }

    /**
     * Register a new method with the API
     * @param string $httpMethod The HTTP Method that's allowed.
     * @param string $opName The operation name.
     * @param callable $callback The method to call when this operation is requested.
     * @return void
     */
    public function registerOperation(string $httpMethod, string $opName, callable $callback): void
    {
        $this->registeredOperations[$httpMethod][$opName] = $callback;
    }

    /**
     * Run the API and generate a response.
     * @return void
     */
    public function run(): void
    {
        $this->loadEnvironment();
        $this->initializePage();
        $parameters = $this->decodeParameters();

        // call the requested API and echo the result as JSON
        try {
            $operation = $parameters['OP'];
            $httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            $response =$this->dispatch($operation, $httpMethod, $parameters);
            echo json_encode($response);
        } catch (ApiException $e) {
            $code = $e->getCode();

            // Log the error.
            Logger::error($code . ": " . $e->getMessage());

            http_response_code($code);
            echo json_encode([
                'status' => 'error',
                'error' => [
                    'code' => $code,
                    'message' => $code < 500 ? $e->getMessage() : 'Internal server error'
                ]
            ]);
        } catch (Exception $e) {
            // Log the error.
            Logger::error($e->getCode() . ": " . $e->getMessage());

            http_response_code(500);
            error_log($e->getMessage());
            echo json_encode([
                'status' => 'error',
                'error' => [
                    'code' => 500,
                    'message' => 'Internal server error'
                ]
            ]);
        }
    }

    /**
     * Loads the environment for the current installation. Supports loading
     * extra env files (like .env.production).
     * @return void
     */
    private function loadEnvironment(): void {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../', [
            '.env',
            '.env.' . ($_ENV['APP_ENV'] ?? 'production')
        ]);

        $dotenv->safeLoad();
    }

    /**
     * Initialize the response.
     * @return void
     */
    private function initializePage(): void {
        header('Content-Type: application/json; charset=utf-8');
        session_start([
            'cookie_samesite' => 'Strict',  // or 'Lax' depending on your CSRF protection strategy
            'cookie_secure' => true,         // ensures cookie sent only over HTTPS
            'cookie_httponly' => true        // prevents JavaScript access to the cookie
        ]);
    }

    /**
     * Gets parameters from both the URL string and POST JSON.
     * @return array
     */
    private function decodeParameters(): array {
        return array_merge(Json::decodePostJSON(), $_GET ?? []);
    }

    /**
     * Check the api call name has been specified and it's a valid API call
     * @param string $operation The operation the client is requesting.
     * @return void
     */
    private function validateAPIMethod(string $operation, $httpMethod): void {

        // Check we actually have an OP parameter before we do anything else.
        if (!$operation) {
            throw new ApiException("Invalid or missing OP parameter", 400);
        }

        // Check the HTTP method is supported
        if (
            !array_key_exists($httpMethod, self::ALLOWED_OPERATIONS)
            && !array_key_exists($httpMethod, $this->registeredOperations)
        ) {
            throw new ApiException("HTTP method $httpMethod is not supported", 405);
        }

        // Check the OP is supported with the HTTP method being used.
        if (
            !in_array($operation, self::ALLOWED_OPERATIONS[$httpMethod], true)
            && !array_key_exists($operation, $this->registeredOperations[$httpMethod])
        ) {
            header('Allow: ' . $this->getAllowedMethodsForOperation($operation));
            throw new ApiException("HTTP method $httpMethod not allowed for operation $operation", 405);
        }
    }

    /**
     * Get a comma-separated string of allowed HTTP methods for a given operation.
     * @param string $operation The operation requested.
     * @return string The list of supported HTTP methods.
     */
    private function getAllowedMethodsForOperation(string $operation): string {
        $methods = [];
        foreach (self::ALLOWED_OPERATIONS as $method => $operations) {
            if (in_array($operation, $operations, true)) {
                $methods[] = $method;
            }
        }
        return implode(', ', $methods);
    }

    /**
     * Dispatches an operation.
     * @param string $operation The operation to execute.
     * @param string $httpMethod The method used to execute.
     * @param array $parameters The parameters for the operation.
     * @return mixed The response from the operation.
     */
    private function dispatch(string $operation, string $httpMethod, array $parameters): mixed {
        $this->validateAPIMethod($operation, $httpMethod);

        if (
            array_key_exists($httpMethod, self::ALLOWED_OPERATIONS)
            && in_array($operation, self::ALLOWED_OPERATIONS[$httpMethod], true)
        ) {
            return $this->$operation($parameters);
        } else {
            return $this->registeredOperations[$httpMethod][$operation]($parameters);
        }
    }

    // ===== API Calls =====

    private function Login(array $request): array
    {
        return Session::login($request);
    }

    private function Register(array $request): array
    {
        return Account::register($request);
    }

    private function Logout(array $request): array
    {
        return Session::logout($request);
    }

    private function OpenCard(array $request): array
    {
        return Card::openCard($request);
    }

    private function AddNewCard(array $request): array
    {
        return Card::addNewCard($request);
    }

    private function DeleteCard(array $request): array
    {
        return Card::deleteCard($request);
    }

    private function MoveCard(array $request): array
    {
        return Card::moveCard($request);
    }

    private function MoveCardList(array $request): array
    {
        return CardList::moveCardList($request);
    }

    private function UpdateCardTitle(array $request): array
    {
        return Card::updateCardTitle($request);
    }

    private function UpdateCardContent(array $request): array
    {
        return Card::updateCardContent($request);
    }

    private function UpdateCardFlags(array $request): array
    {
        return Card::updateCardFlags($request);
    }

    private function UploadAttachment(array $request): array
    {
        return Attachment::uploadAttachment($request);
    }

    private function UploadBackground(array $request): array
    {
        return Board::uploadBackground($request);
    }

	private function DeleteAttachment(array $request): array
    {
		return Attachment::deleteAttachment($request);
	}

	private function UpdateAttachmentName(array $request): array
	{
		return Attachment::updateAttachmentName($request);
	}

	private function UpdateCardListName(array $request): array
	{
		return CardList::updateCardListName($request);
	}

	private function AddCardList(array $request): array
	{
		return CardList::addCardList($request);
	}

	private function DeleteCardList(array $request): array
	{
		return CardList::deleteCardList($request);
	}

	private function UpdateBoardTitle(array $request): array
	{
		return Board::updateBoardTitle($request);
	}

	private function CreateNewBoard(array $request): array
	{
        return Board::createNewBoard($request);
	}

	private function CloseBoard(array $request): array
	{
		return Board::closeBoard($request);
	}

	private function ReopenBoard(array $request): array
	{
		return Board::reopenBoard($request);
	}

	private function DeleteBoard(array $request): array
	{
		return Board::deleteBoard($request);
	}

	private function ImportBoard(): array
	{
		return Board::importBoard();
	}

	private function ImportFromTrello(array $request): array
	{
		return Board::importFromTrello($request);
	}

	private function CreateBoardLabel(array $request): array
	{
        return Label::createBoardLabel($request);
	}

	private function UpdateBoardLabel(array $request): array
	{
		return Label::updateBoardLabel($request);
	}

	private function DeleteBoardLabel(array $request): array
	{
		return Label::deleteBoardLabel($request);
	}

	private function SetCardLabel(array $request): array
	{
		return Label::setCardLabel($request);
	}

	private function GetBoardPermissions(array $request): array
    {
		return Permission::getBoardPermissions($request);
	}

	private function SetUserPermission(array $request): array
    {
        return Permission::setUserPermission($request);
	}

	private function RequestBoardAccess(array $request): array
	{
		return Permission::requestBoardAccess($request);
	}

	private function ExportBoard(array $request): void
	{
		Board::exportBoard($request);
	}

	private function UploadChunk(array $request): array
	{
		return File::uploadChunk($request);
	}
}

$api = new Api();
$api->run();