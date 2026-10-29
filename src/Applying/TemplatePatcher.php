<?php

namespace AltDesign\Altimiser\Applying;

class TemplatePatcher
{
    /**
     * Rewrites the HTML tag surrounding a literal string. Pure string work with
     * no filesystem or Statamic dependency, because this is where the bugs live.
     *
     * Returns null when the transform does not apply, which the caller reports
     * rather than treating as a failure.
     */
    public function patch(string $contents, ChangeRequest $change, ?string $literal = null): ?string
    {
        $literal ??= $change->matchLiteral();

        if (blank($literal)) {
            return null;
        }

        $position = strpos($contents, $literal);

        if ($position === false) {
            return null;
        }

        // A second occurrence means we cannot tell which element was reported.
        if (strpos($contents, $literal, $position + 1) !== false) {
            return null;
        }

        $tag = $this->tagAround($contents, $position);

        if ($tag === null) {
            return null;
        }

        $patched = $this->transform($tag['html'], $change);

        if ($patched === null || $patched === $tag['html']) {
            return null;
        }

        return substr_replace($contents, $patched, $tag['start'], $tag['end'] - $tag['start']);
    }

    /**
     * Whether the fix is already present. Many issues collapse onto one template
     * edit: forty pages rendering the same partial produce forty issues and one
     * change. Without this the first applies and the rest report no_match, which
     * reads as failure when the work is done.
     */
    public function isSatisfied(string $contents, ChangeRequest $change, ?string $literal = null): bool
    {
        $literal ??= $change->matchLiteral();

        if (blank($literal) || ! str_contains($contents, $literal)) {
            return false;
        }

        $tag = $this->tagAround($contents, strpos($contents, $literal));

        if ($tag === null) {
            return false;
        }

        if (! $this->handles($change->check)) {
            return false;
        }

        // Without a value there is no end state to compare against, so a
        // transform declining tells us nothing about whether the fix is present.
        if (blank($change->suggestedValue)) {
            return false;
        }

        // Declining is not the same as done here: alt text is refused on a tag it
        // cannot safely go on, and that tag has no description at all.
        if ($change->isAltText()) {
            return $this->isReportedImage($tag['html'], $change)
                && $this->attributeOf($tag['html'], 'alt') === $change->suggestedValue;
        }

        $transformed = $this->transform($tag['html'], $change);

        // A supported transform that produces no change means the attribute is
        // already what we would have set it to.
        return $transformed === null || $transformed === $tag['html'];
    }

    public function handles(string $check): bool
    {
        return in_array($check, [
            'image.not_lazy_loaded',
            'iframe.not_lazy_loaded',
            'image.lazy_above_fold',
            'image.dimensions_missing',
            'font.no_display_swap',
            'performance.lcp_not_prioritised',
            'image.alt_missing',
            'image.alt_empty',
            'image.alt_too_long',
        ], true);
    }

    /**
     * The alt text a template gives the reported image, or null where the tag
     * around the literal is some other element or has no alt.
     */
    public function currentAltText(string $contents, ChangeRequest $change): ?string
    {
        $position = strpos($contents, (string) $change->matchLiteral());
        $tag = $position === false ? null : $this->tagAround($contents, $position);

        return $tag !== null && $this->isReportedImage($tag['html'], $change)
            ? $this->attributeOf($tag['html'], 'alt')
            : null;
    }

    private function transform(string $tag, ChangeRequest $change): ?string
    {
        return match ($change->check) {
            'image.alt_missing', 'image.alt_empty', 'image.alt_too_long' => $this->setAltText($tag, $change),
            'image.not_lazy_loaded', 'iframe.not_lazy_loaded' => $this->setAttribute($tag, 'loading', 'lazy'),
            'image.lazy_above_fold' => $this->setAttribute($tag, 'loading', 'eager'),
            'image.dimensions_missing' => $this->setDimensions($tag, $change->suggestedValue),
            'performance.lcp_not_prioritised' => $this->setAttribute($tag, 'fetchpriority', 'high'),
            'font.no_display_swap' => $this->replaceLiteral($tag, $change),
            default => null,
        };
    }

    /**
     * Width and height arrive as a single "800x600" value because they are one
     * fix: setting only one of them does not prevent layout shift.
     */
    private function setDimensions(string $tag, ?string $dimensions): ?string
    {
        if ($dimensions === null || preg_match('/^(\d+)x(\d+)$/', $dimensions, $matches) !== 1) {
            return null;
        }

        $withWidth = $this->setAttribute($tag, 'width', $matches[1]);

        return $withWidth === null ? null : $this->setAttribute($withWidth, 'height', $matches[2]);
    }

    private function replaceLiteral(string $tag, ChangeRequest $change): ?string
    {
        $literal = $change->matchLiteral();

        if ($literal === null || ! str_contains($tag, $literal)) {
            return null;
        }

        return str_replace($literal, $change->suggestedValue, $tag);
    }

    /**
     * Never over an alt the template works out for itself: a literal there would
     * describe one image wherever the expression renders another.
     */
    private function setAltText(string $tag, ChangeRequest $change): ?string
    {
        if (blank($change->suggestedValue) || ! $this->isReportedImage($tag, $change)) {
            return null;
        }

        if (str_contains((string) $this->attributeOf($tag, 'alt'), '{{')) {
            return null;
        }

        return $this->setAttribute($tag, 'alt', htmlspecialchars($change->suggestedValue, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * An img tag that is the element the scan reported, by its class as well as
     * its src. One file can be a decorative copy in a loading overlay in one
     * template and a logo linking home in another, and alt text right for one is
     * wrong for the other.
     */
    private function isReportedImage(string $tag, ChangeRequest $change): bool
    {
        if (preg_match('/^<img\b[^<>]*>$/i', $tag) !== 1) {
            return false;
        }

        $reported = $this->attributeOf((string) ($change->target['context'] ?? ''), 'class');
        $squash = fn (?string $value): string => trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $reported === null || $squash($this->attributeOf($tag, 'class')) === $squash($reported);
    }

    private function attributeOf(string $tag, string $attribute): ?string
    {
        $quoted = preg_quote($attribute, '/');

        return preg_match("/\\s{$quoted}\\s*=\\s*([\"'])(.*?)\\1/is", $tag, $matches) === 1
            ? html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5)
            : null;
    }

    private function setAttribute(string $tag, string $attribute, string $value): ?string
    {
        $quoted = preg_quote($attribute, '/');

        if (preg_match("/\\s{$quoted}\\s*=\\s*([\"'])(.*?)\\1/i", $tag) === 1) {
            return preg_replace_callback(
                "/(\\s{$quoted}\\s*=\\s*)([\"'])(.*?)\\2/i",
                fn (array $matches): string => $matches[1].$matches[2].$value.$matches[2],
                $tag,
                1,
            );
        }

        $selfClosing = str_ends_with(rtrim($tag), '/>');
        $trimmed = rtrim(substr($tag, 0, -1));

        if ($selfClosing) {
            $trimmed = rtrim(substr($trimmed, 0, -1));
        }

        return $trimmed." {$attribute}=\"{$value}\"".($selfClosing ? ' />' : '>');
    }

    /**
     * Walks outward from the literal to the enclosing tag.
     *
     * ponytail: naive scan to the nearest angle brackets. An attribute value
     * containing a literal < or > would confuse it, which no real template does.
     *
     * @return ?array{start: int, end: int, html: string}
     */
    private function tagAround(string $contents, int $position): ?array
    {
        $start = strrpos(substr($contents, 0, $position), '<');
        $end = strpos($contents, '>', $position);

        if ($start === false || $end === false) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end + 1,
            'html' => substr($contents, $start, $end + 1 - $start),
        ];
    }
}
