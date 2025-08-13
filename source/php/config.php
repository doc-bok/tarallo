<?php

declare(strict_types=1);

require_once __DIR__ . '/loglevel.php';

// Load the environment variables from the .env file.
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

// Support loading extra env files (like .env.production)
$dotenv = Dotenv::createImmutable(__DIR__, [
    '.env',
    '.env.' . ($_ENV['APP_ENV'] ?? 'production')
]);
$dotenv->safeLoad();

// ==================== SERVER CONFIGURATION ====================

class Config {
    private static array $settings = [];

    /**
     * Load the environment variables into the configuration.
     */
    public static function load(): void {
        self::$settings = [
            'APP_ENV'     => $_ENV['APP_ENV'] ?? 'production',
            'FTP_ROOT'    => $_ENV['TARALLO_FTP_ROOT'] ?? dirname(__DIR__),
            'DB_DSN'      => $_ENV['TARALLO_DB_DSN'] ?? 'mysql:host=mysql;port=3306;dbname=tarallo;charset=utf8',
            'DB_USERNAME' => $_ENV['TARALLO_DB_USERNAME'] ?? '',
            'DB_PASSWORD' => $_ENV['TARALLO_DB_PASSWORD'] ?? '',
            'DB_LOG_LEVEL' => $_ENV['TARALLO_DB_LOG_LEVEL'] ?? LogLevel::ERROR,
            'DB_MAX_RETRIES'  => isset($_ENV['TARALLO_DB_MAX_RETRIES']) ? (int)$_ENV['TARALLO_DB_MAX_RETRIES'] : 3,
            'DB_RETRY_DELAY_MS' => isset($_ENV['TARALLO_DB_RETRY_DELAY_MS']) ? (int)$_ENV['TARALLO_DB_RETRY_DELAY_MS'] : 500,
        ];
    }

    /**
     * Get a configuration value.
     */
    public static function get(string $key): mixed {
        return self::$settings[$key] ?? null;
    }

    /**
     * Check if a value exists in the config settings.
     */
    public static function has(string $key): bool {
        return array_key_exists($key, self::$settings);
    }

    /**
     * Validate that all required settings are present.
     * @throws RuntimeException if any required setting is missing.
     */
    public static function validate(): void {
        $required = ['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'];
        foreach ($required as $key) {
            if (empty(self::$settings[$key])) {
                throw new RuntimeException("Missing required config key: $key");
            }
        }
    }
}
Config::load();
Config::validate();

