<?php

namespace AltDesign\Altimiser\Applying;

use Statamic\Facades\YAML;
use Throwable;

/**
 * Structured data written once for a collection instead of once per entry.
 *
 * The markup a model writes for a collection is a template: the type and the
 * shape are fixed, the values are Antlers. Writing it into thirteen news
 * entries stores the same template thirteen times, and the fourteenth entry,
 * published next week, gets nothing at all.
 *
 * Statamic augments an empty field to its blueprint default, and Alt SEO's tag
 * reads the augmented value, so a default on the collection's own blueprint
 * renders on every entry that has not overridden it. That is the right home for
 * a value the whole collection shares.
 *
 * Only ever a default. An entry that has its own value keeps winning, which is
 * how a default is supposed to behave and how a page that genuinely differs
 * stays different.
 */
class CollectionSchema
{
    private string $field = 'alt_seo_schema';

    /**
     * The blueprint file this change belongs in, or null to write the entry
     * instead.
     *
     * Null covers every reason not to do this: the feature is off, the check is
     * not one whose value a collection can share, the collection is one whose
     * entries genuinely differ, the record is a taxonomy term, or the blueprint
     * does not carry the field. That last one is deliberate: a site that has not
     * put the field in its collection blueprint has not agreed to this, and
     * adding it here would take Alt SEO's injected tab away from that
     * collection along with every other field on it.
     */
    public function file(ChangeRequest $change, object $entry): ?string
    {
        if (! config('altimiser.collection_defaults.enabled', true)) {
            return null;
        }

        if ($change->check !== 'structured_data.missing') {
            return null;
        }

        $collection = method_exists($entry, 'collectionHandle') ? $entry->collectionHandle() : null;

        if (! is_string($collection)) {
            return null;
        }

        if (in_array($collection, config('altimiser.collection_defaults.except', ['pages']), true)) {
            return null;
        }

        $path = $this->path($entry);

        if ($path === null) {
            return null;
        }

        return $this->holdsField($path) ? $path : null;
    }

    /** What the collection already falls back to, if anything. */
    public function current(string $file): ?string
    {
        $default = $this->find($this->read($file));

        return is_string($default) ? $default : null;
    }

    public function write(string $file, string $block): void
    {
        $contents = $this->read($file);

        $this->set($contents, $block);

        file_put_contents(base_path($file), YAML::dump($contents));
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        try {
            return YAML::parse(file_get_contents(base_path($file))) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function holdsField(string $file): bool
    {
        return $this->locate($this->read($file)) !== null;
    }

    /**
     * The field's own definition, wherever the blueprint keeps it.
     *
     * Walked rather than addressed by path because a blueprint written for
     * Statamic 5 calls its top level sections and one written for 6 calls them
     * tabs, and either may nest the field inside a set or a grid.
     *
     * @param  array<mixed>  $contents
     * @return ?array<string, mixed>
     */
    private function locate(array $contents): ?array
    {
        if (($contents['handle'] ?? null) === $this->field && is_array($contents['field'] ?? null)) {
            return $contents['field'];
        }

        foreach ($contents as $value) {
            if (! is_array($value)) {
                continue;
            }

            $found = $this->locate($value);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<mixed> $contents */
    private function find(array $contents): ?string
    {
        $field = $this->locate($contents);

        return is_string($field['default'] ?? null) ? $field['default'] : null;
    }

    /** @param array<mixed> $contents */
    private function set(array &$contents, string $block): bool
    {
        if (($contents['handle'] ?? null) === $this->field && is_array($contents['field'] ?? null)) {
            $contents['field']['default'] = $block;

            return true;
        }

        foreach ($contents as &$value) {
            if (! is_array($value)) {
                continue;
            }

            if ($this->set($value, $block)) {
                return true;
            }
        }

        return false;
    }

    /** The blueprint's path relative to the project, or null if it is not in it. */
    private function path(object $entry): ?string
    {
        $blueprint = method_exists($entry, 'blueprint') ? $entry->blueprint() : null;
        $path = $blueprint === null ? null : $blueprint->path();

        if (! is_string($path) || ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR)) {
            return null;
        }

        return substr($path, strlen(base_path()) + 1);
    }
}
