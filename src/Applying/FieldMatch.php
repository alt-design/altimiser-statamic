<?php

namespace AltDesign\Altimiser\Applying;

class FieldMatch
{
    private function __construct(
        public ?string $field,
        public ?string $reason,
        public ?string $message,
        public bool $inherited = false,
        public bool $flattensTemplate = false,
    ) {}

    public static function found(string $field): self
    {
        return new self($field, null, null);
    }

    /**
     * The rendered value came from somewhere other than this entry, most often
     * an Alt SEO site-wide default. Writing creates a per-page override, so the
     * value-changed guard must not treat the empty field as a conflict.
     */
    public static function inherited(string $field): self
    {
        return new self($field, null, null, inherited: true);
    }

    /** The field holds placeholders that writing a literal value will discard. */
    public static function flattening(string $field): self
    {
        return new self($field, null, null, flattensTemplate: true);
    }

    public static function failed(string $reason, string $message): self
    {
        return new self(null, $reason, $message);
    }

    public function matched(): bool
    {
        return $this->field !== null;
    }
}
