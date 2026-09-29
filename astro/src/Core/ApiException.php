<?php
namespace App\Core;

class ApiException extends \RuntimeException {
    public function __construct(public string $errCode, string $message, public int $status = 400, public array $details = []) {
        parent::__construct($message);
    }
}
