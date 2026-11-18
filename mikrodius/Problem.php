<?php
namespace Mikrodius;

final class Problem extends \RuntimeException
{
    public int $status;
    public string $problemCode;
    public ?string $requestId;
    public int $retryAfter;
    /** Operator-facing detail. Callers pass only sanitized, secret-redacted text. */
    public ?string $detail;

    public function __construct(int $status, string $code, ?string $requestId = null, int $retryAfter = 0, ?string $detail = null)
    {
        // Never include response details: upstream errors may echo credentials.
        parent::__construct($code);
        $this->status = $status;
        $this->problemCode = $code;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
        $this->detail = $detail;
    }

    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status === 424 ||
            $this->status >= 500 || $this->problemCode === 'move_in_progress';
    }
}
