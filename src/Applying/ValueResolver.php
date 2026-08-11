<?php

namespace AltDesign\Altimiser\Applying;

class ValueResolver
{
    /**
     * Alt SEO stores values like "{title} | {site_name}" and substitutes them at
     * render time, so a stored value rarely equals what Altimiser scanned. This
     * runs the same substitution so the two can be compared.
     *
     * Kept free of Statamic types so it can be tested without a CMS.
     *
     * @param  array<string, string>  $variables
     */
    public function render(string $stored, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return strtr($stored, $replacements);
    }

    public function containsPlaceholders(string $value): bool
    {
        return preg_match('/\{\s*[a-z_][a-z0-9_]*\s*\}/i', $value) === 1;
    }

    public function equivalent(string $actual, string $expected): bool
    {
        return $this->collapse($actual) === $this->collapse($expected);
    }

    public function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
