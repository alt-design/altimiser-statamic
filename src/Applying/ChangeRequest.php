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
        /**
         * Whether the value describes this page rather than every page.
         *
         * A lazy loading attribute is the same string on every image on the
         * internet. A heading is this page's own words. The difference decides
         * whether a template may contain the value literally, and only the
         * service that raised the finding knows which it is.
         */
        public bool $pageSpecific = false,
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
            pageSpecific: (bool) ($data['page_specific'] ?? false),
        );
    }

    /**
     * Whether the finding is that an element is absent.
     *
     * Worth knowing because everything else here is "this element exists and
     * wants an attribute", and telling a model an element exists when the whole
     * finding is that it does not gets it inventing one to satisfy the brief.
     */
    public function reportsAMissingElement(): bool
    {
        return str_ends_with($this->check, '.missing');
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
