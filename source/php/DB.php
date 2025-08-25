<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use Random\RandomException;

class DatabaseConnectionException extends ApiException {}

/**
 * DB Helper — supports both new and legacy usage.
 *
 * Modern usage (preferred):
 *   $this->fetchRow("SELECT * FROM users WHERE id = :id", [':id' => 123]);
 *   $this->insert("INSERT INTO users (name) VALUES (:name)", [':name' => 'Alice']);
 *   $this->run("DELETE FROM logs WHERE created < NOW() - INTERVAL 30 DAY");
 *
 * Transactions:
 *   $this->beginTransaction();
 *   ...
 *   $this->commit();
 *
 * Supports nested transactions using MySQL save points.
 */

class DB extends Singleton {
    private const INIT_DB_PATCH = "dbpatch/init_db.sql";

    private ?PDO $pdo = NULL;
    private int $transactionNesting = 0;
    private bool $transactionFailed = false;

    /**
     * Check if the database exists and initialise it if it isn't.
     */
    public function initDatabaseIfNeeded(): void
    {
        $dbExists = ((int) $this->fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tarallo_settings'"
            )) > 0;

        if (!$dbExists) {
            Logger::warning("Database not initialised — attempting init");
            Session::logoutInternal();

            if (!$this->tryApplyingDBPatch(self::INIT_DB_PATCH)) {
                Logger::error("DB init failed - corrupted or missing patch");
                throw new ApiException("Database initialisation failed or DB is corrupted");
            }
        }
    }

    /**
     * Apply sequential DB update patches starting from a given version.
     * @param int|string $dbVersion Current DB schema version (e.g. "1-45" or 45).
     * @return bool                 True if one or more updates were applied, false otherwise.
     */
    public function applyDBUpdates(int|string $dbVersion): bool
    {
        $anyUpdateApplied = false;

        // Sanitize and normalize version (handles "1-123" legacy format)
        $cleanVersion = (int) str_replace('1-', '', (string) $dbVersion);
        if ($cleanVersion < 0) {
            Logger::error("ApplyDBUpdates: Invalid starting version '$dbVersion'");
            return false;
        }

        // Define the patch directory (prefer from config)
        $patchDir = defined('DB_PATCH_DIR') ? DB_PATCH_DIR : __DIR__ . '/../dbpatch';

        Logger::info("ApplyDBUpdates: Starting from version $cleanVersion");

        while (true) {
            $nextVersion = $cleanVersion + 1;
            $patchFile   = "$patchDir/update_{$cleanVersion}_to_$nextVersion.sql";

            if (!File::fileExists($patchFile)) {
                Logger::info("ApplyDBUpdates: No patch file for $cleanVersion → $nextVersion, DB is up-to-date.");
                break;
            }

            try {
                Logger::info("ApplyDBUpdates: Applying patch $patchFile");
                $result = $this->tryApplyingDBPatch($patchFile);

                if (!$result['success'] ?? !$result) {
                    Logger::warning("ApplyDBUpdates: Failed to apply $patchFile - " . ($result['message'] ?? 'Unknown error'));
                    break; // stop upgrading on first failure
                }

                $cleanVersion     = $nextVersion;
                $anyUpdateApplied = true;

            } catch (Throwable $e) {
                Logger::error("ApplyDBUpdates: Exception applying $patchFile - " . $e->getMessage());
                break; // stop updates; leave DB in known last applied state
            }
        }

        return $anyUpdateApplied;
    }

    /**
     * Given an array of database row records, this function creates a mapping
     * from the old ID (specified by $idFieldName) to a new sequential ID,
     * starting from $nextFreeID.
     * @param array  $dbRows     Array of associative arrays representing DB rows.
     * @param string $idFieldName Name of the ID field to remap.
     * @param int    $nextFreeID Starting ID to assign in the new index.
     * @return array Mapping from old IDs to new IDs.
     */
    public function rebuildDBIndex(array $dbRows, string $idFieldName, int $nextFreeID): array
    {
        $newIndex = [];

        foreach ($dbRows as $row) {
            if (!isset($row[$idFieldName])) {
                throw new InvalidArgumentException("ID field '$idFieldName' missing in DB row.");
            }

            $oldID = $row[$idFieldName];
            $newIndex[$oldID] = $nextFreeID++;
        }

        return $newIndex;
    }

    /**
     * Fetch all settings from the database as an associative array.
     * @return array<string,string>  Key/value pairs of settings.
     */
    public function getDBSettings(): array
    {
        static $settingsCache = null;

        if ($settingsCache !== null) {
            return $settingsCache;
        }

        try {
            $rows = $this->fetchAssoc(
                "SELECT name, value FROM tarallo_settings",
                'name',
                'value'
            );
        } catch (Throwable $e) {
            Logger::error("GetDBSettings: DB error - " . $e->getMessage());
            return [];
        }

        $settingsCache = $rows;

        return $settingsCache;
    }

    /**
     * Retrieve a single setting value from the database.
     * @param string $name Setting name to retrieve.
     * @return string|null The setting value, or null if not found.
     * @throws ApiException On invalid name or DB error.
     */
    public function getDBSetting(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException("Setting name cannot be empty.");
        }

        try {
            $value = $this->fetchOne(
                "SELECT value FROM tarallo_settings WHERE name = :name",
                ['name' => $name]
            );
        } catch (Throwable $e) {
            Logger::error("GetDBSetting: DB error fetching '$name' - " . $e->getMessage());
            throw new ApiException("Database error retrieving setting '$name'");
        }

        if ($value === false || $value === null) {
            Logger::warning("GetDBSetting: '$name' not found in tarallo_settings");
            return null;
        }

        return (string) $value;
    }

    /**
     * Update a named DB setting's value.
     * @param string $name   Setting name (must exist in tarallo_settings)
     * @param mixed  $value  New value (will be cast to string in DB)
     * @return bool          True if a row was updated, false if setting not found or unchanged.
     * @throws ApiException On invalid input or DB error.
     */
    public function setDBSetting(string $name, mixed $value): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException("Setting name cannot be empty.");
        }

        try {
            $rowsAffected = $this->query(
                "UPDATE tarallo_settings 
             SET value = :value
             WHERE name = :name",
                [
                    'name'  => $name,
                    'value' => (string)$value
                ]
            );
        } catch (Throwable $e) {
            Logger::error("SetDBSetting: Failed to set '$name' - " . $e->getMessage());
            throw new ApiException("Database error updating setting '$name'");
        }

        $updated = ($rowsAffected > 0);

        Logger::info(
            "SetDBSetting: Setting '$name' " . ($updated ? "updated to '$value'" : "not changed/found")
        );

        return $updated;
    }

    /**
     * Begins a database transaction.
     * Supports true nested transactions using MySQL SAVEPOINT.
     * The outermost call starts the actual transaction.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function beginTransaction(): void
    {
        try {
            $db = $this->open();
            if ($this->transactionNesting === 0) {
                $db->beginTransaction();
                $this->transactionFailed = false;
            } else {
                // Create a savepoint for nested transaction
                $db->exec('SAVEPOINT ' . $this->savepointName());
            }

            $this->transactionNesting++;
            Logger::debug("Transaction nesting now: " . $this->transactionNesting);
        } catch (PDOException $e) {
            Logger::error("beginTransaction failed: " . $e->getMessage());
            $this->transactionFailed = true;
            throw new DatabaseConnectionException("Could not start database transaction.", 0, $e);
        }
    }

    /**
     * Executes a non-SELECT SQL query and returns the number of affected rows.
     * Supports multiple statements separated by semicolons.
     * Does not return result sets (use query() for SELECT queries).
     * @param string $sql The query to execute.
     * @return int The number of affected rows.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function run(string $sql): int
    {
        $db = $this->open();

        try {
            $affected = $db->exec($sql);

            if ($affected === false) {
                // Defensive: This should be caught by PDOException in ERRMODE_EXCEPTION mode
                throw new DatabaseConnectionException("Exec failed with unknown error.");
            }

            return $affected;
        } catch (PDOException $e) {
            Logger::error("Exec failed: {$e->getMessage()} | SQL: $sql");
            throw new DatabaseConnectionException("Database exec failed.", 0, $e);
        }
    }

    /**
     * Execute a query and return the result.
     * @param string $sql The query to execute.
     * @return PDOStatement The data returned by the query.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $db = $this->open();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            Logger::error("{$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));
            throw new DatabaseConnectionException("Database query failed.", 0, $e);
        }
    }

    /**
     * Execute an insert query and return the index.
     * @param string $sql The query to execute.
     * @return string The key of the last row inserted.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }

    /**
     * Execute a query and return only one column from the first matching row.
     * @param string $sql The query to execute.
     * @return mixed The row if the query is successful, otherwise null.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function fetchOne(string $sql, array $params = []): mixed
    {
        try {
            $stmt = $this->query($sql, $params);
            $row  = $stmt->fetch(PDO::FETCH_NUM);
            return $row[0] ?? null; // null if no row or first col is null
        } catch (PDOException $e) {
            Logger::error("fetchOne failed: {$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));
            throw new DatabaseConnectionException("Database fetchOne() failed.", 0, $e);
        }
    }

    /**
     * Execute a query and return results as an associative array using one column as the key
     * and another column as the value.
     * @param string $sql The query to execute.
     * @param string $keyName The column to use for the keys.
     * @param string $valueName The column to use for the values.
     * @return array The associative array.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function fetchAssoc(string $sql, string $keyName, string $valueName, array $params = []): array
    {
        $dictionary = [];
        try {
            $stmt = $this->query($sql, $params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (array_key_exists($keyName, $row) && array_key_exists($valueName, $row)) {
                    $dictionary[$row[$keyName]] = $row[$valueName];
                }
            }
            return $dictionary;
        } catch (PDOException $e) {
            Logger::error("fetchAssoc failed: {$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));
            throw new DatabaseConnectionException("Database fetchAssoc() failed.", 0, $e);
        }
    }

    /**
     * Execute a query and return the result as an array of rows.
     * @param string $sql The query to execute.
     * @return array The associative array.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function fetchTable(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            Logger::error("fetchTable failed: {$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));
            throw new DatabaseConnectionException("Database fetchTable() failed.", 0, $e);
        }
    }

    /**
     * Execute a query and return the first row as an associative array.
     * @param string $sql The query to execute.
     * @return ?array The associative array, or false if the query fails.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->query($sql, $params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            Logger::error("fetchRow failed: {$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));
            throw new DatabaseConnectionException("Database fetchRow() failed.", 0, $e);
        }
    }

    /**
     * Execute a query and return a simple array of values from a specified column.
     * @param string $sql The query to execute.
     * @param string $fieldName The column to look for.
     * @return array The array of values.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function fetchColumn(string $sql, string $fieldName, array $params = []): array
    {
        $values = [];
        try {
            $stmt = $this->query($sql, $params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (array_key_exists($fieldName, $row)) {
                    $values[] = $row[$fieldName];
                } else {
                    Logger::error("fetchArray: field '$fieldName' not found in row.");
                }
            }

            return $values;
        } catch (PDOException $e) {
            Logger::error("fetchArray failed: {$e->getMessage()} | SQL: $sql | Params: " . json_encode($params));
            throw new DatabaseConnectionException("Database fetchArray() failed.", 0, $e);
        }
    }

    /**
     * Commits the transaction or releases the last savepoint if nested.
     * The actual commit occurs only when outermost transaction commits.
     * If a rollback was triggered in any nested transaction, performs overall rollback.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function commit(): void
    {
        if ($this->transactionNesting <= 0) {
            Logger::warning('commit() called with no active transaction.');
            return;
        }
        $this->transactionNesting--;
        $db = $this->open();
        try {
            if ($this->transactionNesting === 0) {
                if ($this->transactionFailed) {
                    $db->rollBack();
                    Logger::info("Outer transaction rolled back due to inner error.");
                } else {
                    $db->commit();
                    Logger::info("Outer transaction committed successfully.");
                }
                $this->transactionFailed = false;
            } else {
                // Release savepoint for nested commit
                $db->exec('RELEASE SAVEPOINT ' . $this->savepointName());
                Logger::debug("Released savepoint sp_" . $this->transactionNesting);
            }
        } catch (PDOException $e) {
            Logger::error("Commit/Rollback failed: " . $e->getMessage());
            throw new DatabaseConnectionException("Transaction finalisation failed.", 0, $e);
        }
    }

    /**
     * Rolls back the transaction or rolls back to the last savepoint if nested.
     * Marks the transaction as failed, so outermost commit becomes rollback.
     * @throws DatabaseConnectionException if the connection fails.
     */
    public function rollBack(): void
    {
        if ($this->transactionNesting <= 0) {
            Logger::warning('rollBack() called with no active transaction.');
            return;
        }
        $db = $this->open();
        $this->transactionNesting--;
        $this->transactionFailed = true;

        try {
            if ($this->transactionNesting === 0) {
                $db->rollBack();
                Logger::info("Outer transaction rolled back.");
            } else {
                // Rollback to savepoint for nested rollback
                $db->exec('ROLLBACK TO SAVEPOINT ' . $this->savepointName());
                Logger::debug("Rolled back to savepoint sp_" . $this->transactionNesting);
            }
        } catch (PDOException $e) {
            Logger::error("rollBack() failed: " . $e->getMessage());
            throw new DatabaseConnectionException("Transaction rollback failed.", 0, $e);
        }
    }

    /**
     * Attempt to apply a DB patch from an SQL file.
     * @param string $sqlFilePath
     * @return array { success: bool, message: string }
     */
    private function tryApplyingDBPatch(string $sqlFilePath): array
    {
        // Check that the patch file exists and is readable
        if (!File::fileExists($sqlFilePath)) {
            Logger::warning("DB patch file not found: $sqlFilePath");
            return ['success' => false, 'message' => 'Patch file not found'];
        }

        // Read patch content
        $sql = File::readFileAsString($sqlFilePath);
        if ($sql === '') {
            Logger::warning("DB patch file is empty or unreadable: $sqlFilePath");
            return ['success' => false, 'message' => 'Empty or unreadable patch file'];
        }

        // Sanity check that it contains something looking like SQL
        if (stripos($sql, 'INSERT') === false &&
            stripos($sql, 'UPDATE') === false &&
            stripos($sql, 'CREATE') === false &&
            stripos($sql, 'ALTER') === false) {
            Logger::warning("DB patch file $sqlFilePath does not appear to contain SQL DML/DDL");
            // Still continue with warning
        }

        try {
            //$this->beginTransaction();
            $this->run($sql); // assumes $this->run can handle multi‑statement SQL
            //$this->commit();

            Logger::info("Successfully applied DB patch from $sqlFilePath");
            return ['success' => true, 'message' => 'Patch applied successfully'];

        } catch (Throwable $e) {
            $this->rollBack();
            Logger::error("Failed applying DB patch from $sqlFilePath: " . $e->getMessage());
            return ['success' => false, 'message' => 'DB patch failed: ' . $e->getMessage()];
        }
    }

    /**
     * Open a new connection to a database.
     * @return PDO The newly created database connection.
     * @throws DatabaseConnectionException if the connection fails.
     */
    private function open() : PDO
    {
        if (is_null($this->pdo)) {
            $this->validateConfig();

            $opt = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES'"
            );

            $maxRetries = Config::getInstance()->get('DB_MAX_RETRIES');
            $initialDelay = Config::getInstance()->get('DB_RETRY_DELAY_MS');

            $attempt = 0;
            $delay = $initialDelay;

            do {
                try {
                    $this->pdo = new PDO(
                        Config::getInstance()->get('DB_DSN'),
                        Config::getInstance()->get('DB_USERNAME'),
                        Config::getInstance()->get('DB_PASSWORD'),
                        $opt
                    );
                    break; // success
                } catch (PDOException $e) {
                    $this->logConnectionError($e);
                    $attempt++;
                    if ($attempt > $maxRetries) {
                        throw new DatabaseConnectionException($this->formatErrorMessage($e), 0, $e);
                    }

                    // Sleep with exponential backoff and jitter before retrying
                    try {
                        usleep(($delay + random_int(0, 250)) * 1000);
                        $delay *= 2; // double the delay for next attempt
                    } catch (RandomException $randomException) {
                        Logger::error($randomException->getMessage());
                        break;
                    }
                }
            } while($attempt <= $maxRetries);
        }

        return $this->pdo;
    }

    /**
     * Returns a unique savepoint name per connection and nesting level.
     */
    private function savepointName(): string
    {
        // Process ID + nesting level ensures uniqueness within persistent connections
        return 'sp_' . getmypid() . '_' . $this->transactionNesting;
    }

    /**
     * Check that the config is valid.
     */
    private function validateConfig(): void
    {
        if (!Config::getInstance()->has('DB_DSN')) {
            throw new DatabaseConnectionException("DB_DSN is missing.");
        }
    }

    /**
     * Log a connection error.
     * @param PDOException $e The exception thrown by the PDO.
     */
    private function logConnectionError(PDOException $e): void
    {
        Logger::error("Connection failed: " . $e->getMessage());
        $dsnSafe = preg_replace('/password=[^;]*/i', 'password=hunter2', Config::getInstance()->get('DB_DSN'));
        Logger::debug("DSN used: " . $dsnSafe);
    }

    /**
     * Format an error message based on the environment.
     * @param PDOException $e The exception thrown by the PDO.
     * @return string The formatted error message.
     */
    private function formatErrorMessage(PDOException $e): string {
        return Config::getInstance()->get('APP_ENV') === 'development'
            ? "Connection failed: {$e->getMessage()}"
            : "Connection error. Please try again later.";
    }

    // ==== New Database Interface ====

    /**
     * Executes a prepared query and returns the PDOStatement.
     * @param string $sql The SQL query to execute.
     * @param array $params The parameters used with the query.
     * @return PDOStatement The statement that was just executed.
     */
    public function queryV2(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row.
     * @param string $sql The SQL query to execute.
     * @param array $params The parameters used with the query.
     * @return array|null The row fetched, or null if no row was found.
     */
    public function fetchRowV2(string $sql, array $params = []): ?array {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows.
     * @param string $sql The SQL query to execute.
     * @param array $params The parameters used with the query.
     * @return array An array of the rows found.
     */
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch a single column from the first row.
     * @param string $sql The SQL query to execute.
     * @param array $params The parameters used with the query.
     * @return mixed The values in the column.
     */
    public function fetchColumnV2(string $sql, array $params = []): mixed {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * Insert a row and return last insert ID.
     * @param string $table The table to add a row to.
     * @param array $data The data for the row.
     * @return string The last insert ID.
     */
    public function insertV2(string $table, array $data): string {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return $this->pdo->lastInsertId();
    }

    //

    /**
     * Update rows, returns number of affected rows.
     * @param string $table The table to add a row to.
     * @param array $data The data for the row.
     * @param string $where The WHERE conditions for the query.
     * @param array $whereParams The parameters for the WHERE condition.
     * @return int The number of rows affected.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setParts = [];
        foreach ($data as $column => $value) {
            $setParts[] = "$column = :set_$column";
        }
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE $table SET $setClause WHERE $where";
        $stmt = $this->pdo->prepare($sql);

        // Bind values for SET
        foreach ($data as $column => $value) {
            $stmt->bindValue(":set_$column", $value);
        }
        // Bind values for WHERE
        foreach ($whereParams as $param => $value) {
            if (is_int($param)) {
                $stmt->bindValue($param + 1, $value); // positional params
            } else {
                $stmt->bindValue(is_string($param) && $param[0] === ':' ? $param : ':' . $param, $value);
            }
        }

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Delete rows, returns number of affected rows.
     * @param string $table The table to delete a row from.
     * @param string $where The WHERE conditions for the query.
     * @param array $whereParams The parameters for the WHERE condition.
     * @return int The number of rows affected.
     */
    public function delete(string $table, string $where, array $whereParams = []): int {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount();
    }

    /**
     * Returns last inserted ID.
     * @return string The last inserted ID.
     */
    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    /**
     * Returns row count of last executed statement.
     * @param PDOStatement $stmt The PDO statement.
     * @return int The row count affected.
     */
    public function rowCount(PDOStatement $stmt): int {
        return $stmt->rowCount();
    }
}
