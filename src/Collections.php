<?php

namespace AltDesign\Altimiser;

use AltDesign\Altimiser\Applying\CollectionSchema;
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
    /** @return array<int, array{handle: string, title: string, route: string, loopable: bool, sections: array<int, string>, shares_schema: bool}> */
    public function all(): array
    {
        $site = Site::default()->handle();
        $collections = Collection::all()->filter(fn ($collection): bool => (string) $collection->route($site) !== '');
        $sections = $collections
            ->mapWithKeys(fn ($collection): array => [
                $collection->handle() => $this->sections(
                    $collection->queryEntries()->where('site', $site)->where('published', true)->pluck('uri'),
                ),
            ])
            ->all();

        return $collections
            ->map(fn ($collection): array => [
                'handle' => $collection->handle(),
                'title' => (string) $collection->title(),
                'route' => (string) $collection->route($site),
                'loopable' => $this->loopable($collection->handle()),
                'sections' => $this->ownSections($sections, $collection->handle(), (string) $collection->route($site)),
                'shares_schema' => app(CollectionSchema::class)->sharesSchema($collection->handle()),
            ])
            ->values()
            ->all();
    }

    /**
     * The sections only this collection's entries sit under.
     *
     * A section another collection also uses would put that collection's pages
     * on this one's card and give them its markup: a page in the tree at
     * /machine/compare is not a machine. A page tree reports none of its own,
     * because its addresses follow the tree rather than the collection.
     *
     * @param  array<string, array<int, string>>  $sections
     * @return array<int, string>
     */
    public function ownSections(array $sections, string $handle, string $route): array
    {
        if (str_contains($route, 'parent_uri')) {
            return [];
        }

        $elsewhere = collect($sections)->except($handle)->flatten()->all();

        return array_values(array_diff($sections[$handle] ?? [], $elsewhere));
    }

    /**
     * The first part of the path its entries sit under, so machine and
     * material-lift for a collection routed /{product_category}/{slug}. A route
     * starting with a variable says nothing about where its pages are, and the
     * entries' own addresses do. An entry at the top level is under none.
     *
     * @param  iterable<int, ?string>  $uris
     * @return array<int, string>
     */
    public function sections(iterable $uris): array
    {
        return collect($uris)
            ->map(fn (?string $uri): array => explode('/', trim((string) $uri, '/')))
            ->filter(fn (array $segments): bool => count($segments) > 1)
            ->map(fn (array $segments): string => $segments[0])
            ->unique()
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
