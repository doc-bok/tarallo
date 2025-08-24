<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Log Helper
 *  Provides methods that help with clean logging.
 */
class Logger
{
    /**
     * Get logging level based on environment variable.
     * Levels: 'off', 'error', 'warning', 'info', 'debug'
     */
    private static function logLevel(): LogLevel
    {
        $level = strtolower((string)(Config::getInstance()->get('DB_LOG_LEVEL') ?: 'error'));
        return LogLevel::tryFrom($level) ?? LogLevel::ERROR; // fallback
    }

    /**
     * Conditional logger that respects log level.
     */
    private static function log(LogLevel $level, string $message): void
    {
        $threshold = self::logLevel()->severity();
        if ($level->severity() <= $threshold && $threshold !== 0) {
            error_log("[$level->value] $message");
        }
    }

    /**
     * Helper for debug level logs.
     */
    public static function debug(string $message): void
    {
        self::log(LogLevel::DEBUG, $message);
    }

    /**
     * Helper for info level logs.
     */
    public static function info(string $message): void
    {
        self::log(LogLevel::INFO, $message);
    }

    /**
     * Helper for warning level logs.
     */
    public static function warning(string $message): void
    {
        self::log(LogLevel::WARNING, $message);
    }

    /**
     * Helper for error level logs.
     */
    public static function error(string $message): void
    {
        self::log(LogLevel::ERROR, $message);
    }
}