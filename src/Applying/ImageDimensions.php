<?php

namespace AltDesign\Altimiser\Applying;

class ImageDimensions
{
    /**
     * Reads real dimensions off disk for an image whose URL points at an actual
     * file. Deterministic and free, which is why it runs before any model does.
     *
     * A Glide URL is deliberately not resolved here even though the cached file
     * exists: its dimensions are the transform's, not the source asset's, and
     * baking those into a template would be wrong for every other asset the same
     * template renders. Those go to the model to be bound dynamically instead.
     */
    public function for(string $src): ?string
    {
        if (str_contains($src, '/cached-img/') || str_contains($src, '/img/asset/')) {
            return null;
        }

        $path = $this->pathFor($src);

        if ($path === null) {
            return null;
        }

        $size = @getimagesize($path);

        if ($size === false || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            return null;
        }

        return "{$size[0]}x{$size[1]}";
    }

    private function pathFor(string $src): ?string
    {
        if (str_contains($src, '://')) {
            return null;
        }

        $relative = ltrim(strtok($src, '?') ?: $src, '/');

        if (str_contains($relative, '..')) {
            return null;
        }

        $path = public_path($relative);

        return is_file($path) ? $path : null;
    }
}
