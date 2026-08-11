<?php

namespace AltDesign\Altimiser\Applying;

class ChangeResult
{
    private function __construct(
        public string $id,
        public string $status,
        public ?string $reason = null,
        public ?string $message = null,
        public ?array $location = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public static function applied(string $id, array $location, ?string $from, ?string $to): self
    {
        return new self($id, 'applied', location: $location, from: $from, to: $to);
    }

    /**
     * The fix is present because another change in this run, or an earlier run,
     * made the same edit. Counted as success: the issue really is fixed.
     */
    public static function alreadyApplied(string $id, array $location, string $message): self
    {
        return new self($id, 'already_applied', message: $message, location: $location);
    }

    /**
     * Not attempted in this request because the batch ran to its size limit.
     * Not a failure and not terminal: Altimiser sends it again.
     */
    public static function deferred(string $id): self
    {
        return new self($id, 'deferred', message: 'Not attempted in this request. Send it again.');
    }

    public static function skipped(string $id, string $reason, string $message): self
    {
        return new self($id, 'skipped', reason: $reason, message: $message);
    }

    public static function failed(string $id, string $reason, string $message): self
    {
        return new self($id, 'failed', reason: $reason, message: $message);
    }

    /** Lets a guard be described before the change it belongs to is known. */
    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'message' => $this->message,
            'location' => $this->location,
            'from' => $this->from,
            'to' => $this->to,
        ], fn ($value): bool => $value !== null);
    }
}
