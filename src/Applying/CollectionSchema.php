<?php

namespace AltDesign\Altimiser\Applying;

use RuntimeException;

/**
 * Structured data written once for a collection instead of once per entry.
 *
 * The markup a model writes for a collection is a template: the type and the
 * shape are fixed, the values are Antlers. Writing it into thirteen news
 * entries stores the same template thirteen times, and the fourteenth entry,
 * published next week, gets nothing at all.
 *
 * It goes in the collection's inject, the values Statamic gives every entry in
 * a collection that has none of its own. Alt SEO's tag reads the field from
 * the page, where the injected value is, so nothing about the blueprint has to
 * change and Alt SEO keeps adding its own tab to it.
 *
 * Only ever a default. An entry that has its own value keeps winning, which is
 * how a default is supposed to behave and how a page that genuinely differs
 * stays different.
 */
class CollectionSchema
{
    private string $field = 'alt_seo_schema';

    /**
     * The collection this change belongs in, or null to write the entry
     * instead.
     *
     * Null covers every reason not to do this: the feature is off, the check is
     * not one whose value a collection can share, the collection is one whose
     * entries genuinely differ, or the record is a taxonomy term.
     */
    public function collection(ChangeRequest $change, object $entry): ?object
    {
        if ($change->check !== 'structured_data.missing') {
            return null;
        }

        if (! method_exists($entry, 'collectionHandle') || ! $this->sharesSchema((string) $entry->collectionHandle())) {
            return null;
        }

        $collection = method_exists($entry, 'collection') ? $entry->collection() : null;

        return is_object($collection) ? $collection : null;
    }

    /** Whether structured data for this collection is written as its default. */
    public function sharesSchema(string $handle): bool
    {
        return (bool) config('altimiser.collection_defaults.enabled', true)
            && ! in_array($handle, config('altimiser.collection_defaults.except', ['pages']), true);
    }

    /**
     * What the collection's entries already fall back to, if anything.
     *
     * A default in the blueprint counts as much as one injected by the
     * collection. A site that put one there chose it, and an injected value
     * would quietly take its place on every entry.
     */
    public function current(object $collection, object $entry): ?string
    {
        $injected = $collection->cascade($this->field);

        if (is_string($injected) && filled($injected)) {
            return $injected;
        }

        $blueprint = method_exists($entry, 'blueprint') ? $entry->blueprint() : null;
        $default = $blueprint?->field($this->field)?->defaultValue();

        return is_string($default) && filled($default) ? $default : null;
    }

    /** @return array<string, mixed> */
    public function injected(object $collection): array
    {
        return $collection->cascade()->all();
    }

    /**
     * Whether saving the collection would change nothing in its file but the
     * inject.
     *
     * Statamic writes a collection's whole file out again from what it read,
     * which drops comments and any key it does not know and puts the rest in
     * its own order. A file somebody has edited by hand would come back as a
     * commit rewriting it, so it is left for a developer instead.
     */
    public function canonical(object $collection): bool
    {
        $file = $this->path($collection);

        if ($file === null || ! method_exists($collection, 'fileContents')) {
            return true;
        }

        return $collection->fileContents() === file_get_contents(base_path($file));
    }

    /** @param array<string, mixed> $cascade */
    public function restore(object $collection, array $cascade): void
    {
        $this->save($collection->cascade($cascade));
    }

    public function write(object $collection, string $block): void
    {
        $this->save($collection->cascade([...$this->injected($collection), $this->field => $block]));
    }

    /**
     * The collection's file relative to the project, or null where it has none,
     * which is the case on a database-driven site.
     */
    public function path(object $collection): ?string
    {
        $path = method_exists($collection, 'path') ? $collection->path() : null;

        if (! is_string($path) || ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        return substr($path, strlen(base_path()) + 1);
    }

    /**
     * A listener on CollectionSaving can stop the save, and then nothing is
     * written while everything after it would report the change as made.
     */
    private function save(object $collection): void
    {
        if ($collection->save() === false) {
            throw new RuntimeException("Statamic did not save the {$collection->handle()} collection.");
        }
    }
}
