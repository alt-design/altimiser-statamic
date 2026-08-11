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
        ], true);
    }

    private function transform(string $tag, ChangeRequest $change): ?string
    {
        return match ($change->check) {
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

    private function setAttribute(string $tag, string $attribute, string $value): ?string
    {
        $quoted = preg_quote($attribute, '/');

        if (preg_match("/\\s{$quoted}\\s*=\\s*([\"'])(.*?)\\1/i", $tag) === 1) {
            return preg_replace(
                "/(\\s{$quoted}\\s*=\\s*)([\"'])(.*?)\\2/i",
                "\${1}\${2}{$value}\${2}",
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
