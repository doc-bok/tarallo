<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Class that handles server configuration.
 */
class Config extends Singleton {
    private array $settings = [];

    /**
     * Get a configuration value.
     */
    public function get(string $key): mixed {
        return $this->settings[$key] ?? null;
    }

    /**
     * Check if a value exists in the config settings.
     */
    public function has(string $key): bool {
        return array_key_exists($key, $this->settings);
    }

    /**
     * Construction: Loads and validates the configuration.
     */
    protected function __construct()
    {
        parent::__construct();
        $this->load();
        $this->validate();
    }

    /**
     * Load the environment variables into the configuration.
     */
    private function load(): void {
        $this->settings = [
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
     * Validate that all required settings are present.
     * @throws RuntimeException if any required setting is missing.
     */
    private function validate(): void {
        $required = ['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'];
        foreach ($required as $key) {
            if (empty($this->settings[$key])) {
                throw new RuntimeException("Missing required config key: $key");
            }
        }
    }
}

