<?php

namespace AltDesign\Altimiser;

use Statamic\Facades\GlobalSet;

/**
 * The site's single line global values: the place a firm keeps its telephone
 * number, email and address once for every page.
 *
 * Reported so structured data can read them from where they live, as
 * {{ contact:telephone }}, rather than carry a copy that goes stale when the
 * office moves. Only single line text: a longer field can hold a line break,
 * and a line break inside a JSON string breaks the whole block.
 *
 * The values travel so Altimiser can tell which of them a page actually shows.
 * It passes a model only the ones already visible on the public page, so a
 * global holding something private goes no further than Altimiser.
 */
class Globals
{
    /** @return array<int, array{handle: string, title: string, fields: array<int, array{handle: string, display: string, value: string}>}> */
    public function all(): array
    {
        return GlobalSet::all()
            ->map(fn ($set): array => [
                'handle' => $set->handle(),
                'title' => (string) $set->title(),
                'fields' => $this->fields(
                    $set->blueprint()?->fields()->all()->all() ?? [],
                    $set->inDefaultSite()?->data()->all() ?? [],
                ),
            ])
            ->filter(fn (array $set): bool => $set['fields'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, \Statamic\Fields\Field>  $fields
     * @param  array<string, mixed>  $values
     * @return array<int, array{handle: string, display: string, value: string}>
     */
    public function fields(array $fields, array $values): array
    {
        return collect($fields)
            ->filter(fn ($field): bool => $field->type() === 'text'
                && is_string($values[$field->handle()] ?? null)
                && trim($values[$field->handle()]) !== '')
            ->map(fn ($field): array => [
                'handle' => $field->handle(),
                'display' => (string) $field->display(),
                'value' => trim($values[$field->handle()]),
            ])
            ->values()
            ->all();
    }
}
