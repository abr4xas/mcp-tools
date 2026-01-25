<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Exceptions;

use Exception;

abstract class AnalysisException extends Exception
{
    protected string $errorCode;

    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, string $errorCode, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => true,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
            'suggestion' => $this->getSuggestion(),
        ];
    }

    abstract protected function getSuggestion(): string;
}
