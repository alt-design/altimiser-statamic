<?php

namespace AltDesign\Altimiser\Applying;

class ChangeRequest
{
    public function __construct(
        public string $id,
        public string $check,
        public string $url,
        public array $target,
        public ?string $currentValue,
        public ?string $suggestedValue,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            check: $data['check'],
            url: $data['url'],
            target: $data['target'] ?? [],
            currentValue: $data['current_value'] ?? null,
            suggestedValue: $data['suggested_value'] ?? null,
        );
    }

    /** The literal string a template patcher can search source files for. */
    public function matchLiteral(): ?string
    {
        return $this->target['match'] ?? null;
    }

    /**
     * Strings from the reported element that a template might contain verbatim,
     * most reliable first.
     *
     * The obvious one is the src or href, and on a site that writes its URLs out
     * by hand it is enough. On a content-driven site it is worse than useless: an
     * image URL is a Glide transform assembled at render time, so the one string
     * we are searching for is the one string guaranteed not to be in the source.
     * The class attribute usually is, character for character, because that is
     * how people write CSS.
     *
     * @return array<string, string> kind to literal
     */
    public function locatorLiterals(): array
    {
        $literals = ['src' => $this->matchLiteral()];
        $context = (string) ($this->target['context'] ?? '');

        foreach (['class', 'id', 'alt'] as $attribute) {
            if (preg_match('/\s'.$attribute.'="([^"]+)"/i', $context, $matches) === 1) {
                $literals[$attribute] = trim($matches[1]);
            }
        }

        return array_filter($literals, fn (?string $literal): bool => filled($literal) && mb_strlen($literal) > 3);
    }

    public function checkGroup(): string
    {
        return str_contains($this->check, '.')
            ? strstr($this->check, '.', true)
            : $this->check;
    }

    public function path(): string
    {
        return '/'.trim(parse_url($this->url, PHP_URL_PATH) ?? '/', '/');
    }
}
