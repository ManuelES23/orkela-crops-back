<?php
// sentinel-back/app/Exceptions/FaceRecognitionException.php
namespace App\Exceptions;

use Exception;

class FaceRecognitionException extends Exception
{
    public function __construct(
        private readonly string $reason,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : "Face recognition failed: {$reason}");
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
