<?php
namespace Mikrodius;

/** One monotonic budget shared by every nested operation. */
final class Deadline
{
    private int $end;
    private const RESERVE_MS = 1200;
    public function __construct(float $seconds = 8) { $this->end = hrtime(true) + (int) (max(0, $seconds) * 1e9); }
    public function remainingMs(bool $reserve = true): int { return max(0, (int) (($this->end - hrtime(true)) / 1e6) - ($reserve ? self::RESERVE_MS : 0)); }
    public function available(): bool { return $this->remainingMs() >= 50; }
    public function check(): void { if (!$this->available()) { throw new Problem(503, 'sync_deadline'); } }
    public function waitSeconds(int $requested): float { return min($requested, max(0, ($this->remainingMs() - 50) / 1000)); }
}
