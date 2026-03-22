<?php

declare(strict_types=1);

class AppException extends Exception
{
    private int $errorCode;

    public function __construct(string $message, int $errorCode)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}

try {
    throw new AppException('not found', 404);
} catch (AppException $e) {
    echo "AppException: " . $e->getMessage() . " (code " . strval($e->getErrorCode()) . ")\n";
}

echo "done\n";
