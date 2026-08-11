<?php

namespace AltDesign\Altimiser\Applying;

class RenderedConsistency
{
    /**
     * Checks a proposed edit against the element as the browser actually received
     * it, and rejects the ones that cannot be the same element.
     *
     * A model will confidently pick a plausible tag over the correct one. Given a
     * page with fifty images it chose a header image whose template writes
     * class="self-end w-full lg:w-1/2 mt-auto" as a literal, for a reported
     * element that rendered with class="". Those cannot be the same tag, and no
     * structural validation catches it because the edit is perfectly well formed.
     *
     * Only literal attribute values are compared. A class built from a variable
     * tells us nothing, so it is left alone rather than guessed at.
     */
    public function contradicts(string $templateTag, ?string $renderedTag): bool
    {
        if (blank($renderedTag)) {
            return false;
        }

        foreach (['class', 'alt'] as $attribute) {
            $inTemplate = $this->literalAttribute($templateTag, $attribute);

            if ($inTemplate === null) {
                continue;
            }

            $rendered = $this->attribute($renderedTag, $attribute) ?? '';

            if ($this->inconsistent($attribute, $inTemplate, $rendered)) {
                return true;
            }
        }

        return false;
    }

    private function inconsistent(string $attribute, string $inTemplate, string $rendered): bool
    {
        if ($attribute === 'class') {
            $wanted = preg_split('/\s+/', trim($inTemplate), flags: PREG_SPLIT_NO_EMPTY) ?: [];
            $has = preg_split('/\s+/', trim($rendered), flags: PREG_SPLIT_NO_EMPTY) ?: [];

            return array_diff($wanted, $has) !== [];
        }

        return trim($inTemplate) !== trim($rendered);
    }

    /**
     * The attribute's value only when the template writes it out in full. Any
     * template syntax in there means the rendered value is unknowable from here.
     */
    private function literalAttribute(string $tag, string $attribute): ?string
    {
        $value = $this->attribute($tag, $attribute);

        if ($value === null) {
            return null;
        }

        $hasTemplateSyntax = str_contains($value, '{{')
            || str_contains($value, '{')
            || str_contains($value, '<?')
            || str_contains($value, '@');

        return $hasTemplateSyntax ? null : $value;
    }

    private function attribute(string $tag, string $attribute): ?string
    {
        $quoted = preg_quote($attribute, '/');

        if (preg_match("/\\s{$quoted}\\s*=\\s*([\"'])(.*?)\\1/is", $tag, $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }
}
