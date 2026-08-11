<?php

namespace AltDesign\Altimiser\Applying;

use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Term as Terms;
use Statamic\Structures\Page;

class ContentResolver
{
    /**
     * Turns a URL into something writable.
     *
     * Entry::findByUri returns a Statamic\Structures\Page for any collection
     * with a page tree, not an Entry. Page proxies get() and collectionHandle()
     * so it looks like an entry until you ask it for its blueprint, which comes
     * back null and quietly leaves you with no fields to write to. Unwrapping it
     * is the difference between resolving a page and silently failing on one.
     *
     * @return ?Entry
     */
    public function find(string $path): ?object
    {
        $found = Entries::findByUri($path);

        if ($found instanceof Page) {
            return $found->entry();
        }

        return $found;
    }

    /**
     * Taxonomy terms have their own URLs, blueprints and SEO fields, and a site
     * with a hub or category structure will have plenty of them.
     */
    public function findTerm(string $path): ?object
    {
        foreach (Terms::query()->get() as $term) {
            if ($this->matches($term->uri(), $path)) {
                return $term;
            }
        }

        return null;
    }

    private function matches(?string $uri, string $path): bool
    {
        if ($uri === null) {
            return false;
        }

        return rtrim($uri, '/') === rtrim($path, '/');
    }
}
