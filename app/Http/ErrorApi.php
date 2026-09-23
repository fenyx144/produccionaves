<?php

declare(strict_types=1);

namespace App\Http;

/** Error de dominio con contrato HTTP de respuesta (status, errorCode, data). */
final class ErrorApi extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var string|null */
    public $errorCode;
    /** @var mixed */
    public $data;

    public function __construct(int $status, string $message, ?string $errorCode = null, $data = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->data = $data;
    }
}
