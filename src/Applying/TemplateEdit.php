<?php

namespace AltDesign\Altimiser\Applying;

class TemplateEdit
{
    private function __construct(
        public ?string $file,
        public ?int $startLine,
        public ?int $endLine,
        public ?string $replaced,
        public ?string $replacement,
        public ?string $reasoning,
        public ?string $rejection,
    ) {}

    public static function accepted(
        string $file,
        int $startLine,
        int $endLine,
        string $replaced,
        string $replacement,
        ?string $reasoning,
    ): self {
        return new self($file, $startLine, $endLine, $replaced, $replacement, $reasoning, null);
    }

    public static function rejected(string $rejection): self
    {
        return new self(null, null, null, null, null, null, $rejection);
    }

    public function usable(): bool
    {
        return $this->rejection === null;
    }

    /**
     * Splices the replacement over the named line range. Line numbers are
     * unambiguous in a way a byte-exact search string is not: a model that
     * mangles one space in its copy of the markup produces an edit that cannot
     * be applied at all, and it does so silently.
     */
    public function applyTo(string $contents): string
    {
        $lines = preg_split('/\R/', $contents);
        $ending = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        array_splice(
            $lines,
            $this->startLine - 1,
            $this->endLine - $this->startLine + 1,
            preg_split('/\R/', $this->replacement),
        );

        return implode($ending, $lines);
    }
}
