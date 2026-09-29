<?php

namespace AltDesign\Altimiser;

use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

/**
 * The collections whose entries have pages, and whether structured data can
 * loop over them.
 *
 * A page listing a collection's entries wants its structured data to loop over
 * the collection, or the list goes stale the day somebody joins. Altimiser only
 * ever sees URLs, so the handles have to come from here.
 *
 * Whether a loop renders is a property of the site rather than of the markup.
 * Alt SEO runs the schema field through Antlers as untrusted content, and
 * untrusted Antlers may only call the tags a site allows. The collection tag is
 * not on Statamic's default list, and a refused loop renders nothing, so the
 * list comes out empty while the markup around it still validates.
 */
class Collections
{
    /** @return array<int, array{handle: string, title: string, route: string, loopable: bool}> */
    public function all(): array
    {
        $site = Site::default()->handle();

        return Collection::all()
            ->map(fn ($collection): array => [
                'handle' => $collection->handle(),
                'title' => (string) $collection->title(),
                'route' => (string) $collection->route($site),
                'loopable' => $this->loopable($collection->handle()),
            ])
            ->filter(fn (array $collection): bool => $collection['route'] !== '')
            ->values()
            ->all();
    }

    /**
     * Statamic's own test, repeated: {{ collection:partners }} is checked as
     * collection:partners:partners against the allow list, then the guard list.
     * Neither default names the collection tag, so only the site's own
     * configuration can allow it.
     */
    public function loopable(string $handle): bool
    {
        $tag = "collection:{$handle}:{$handle}";

        return Str::is((array) config('statamic.antlers.allowedContentTags', []), $tag)
            && ! Str::is((array) config('statamic.antlers.guardedContentTags', []), $tag);
    }
}
