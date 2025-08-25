<?php

/**
 * Exception class for API-specific exceptions.
 */
class ApiException extends RuntimeException {

    /**
     * Construction - Default to 500: Internal Server Error
     * @param string $message A description of the error.
     * @param int $code The error code.
     * @param Throwable|null $previous The previous exception, if we want a
     *                                 chain.
     */
    public function __construct(string $message = "", int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}