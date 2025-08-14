<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

enum LogLevel: string
{
    case DEBUG   = 'debug';
    case INFO    = 'info';
    case WARNING = 'warning';
    case ERROR   = 'error';
    case OFF     = 'off';

    public function severity(): int
    {
        return match($this) {
            self::OFF     => 0,
            self::ERROR   => 1,
            self::WARNING => 2,
            self::INFO    => 3,
            self::DEBUG   => 4,
        };
    }
}