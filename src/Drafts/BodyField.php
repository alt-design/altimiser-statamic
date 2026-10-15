<?php

namespace AltDesign\Altimiser\Drafts;

use Illuminate\Support\Str;

/**
 * Where a draft's body goes in a blueprint, and in what shape.
 *
 * Blueprints differ from site to site, so this looks for the field an editor
 * would write the page in: a Bard field, then a Markdown field, then a text set
 * in a page builder, then a plain textarea that is plainly the body. It never
 * writes to the title, the slug or the SEO fields, and where nothing fits it
 * says so rather than inventing a field the blueprint does not have.
 *
 * Kept free of Statamic so the choice can be tested with plain arrays. Bard's
 * own format comes from a converter passed in, because only Statamic knows how
 * a given Bard field is configured.
 */
class BodyField
{
    /** @var array<int, string> */
    private array $textual = ['bard', 'markdown'];

    /** @var array<int, string> */
    private array $likelyNames = ['content', 'body', 'text', 'copy', 'article', 'rich_text', 'wysiwyg', 'article_body'];

    /**
     * @param  array<string, array{type: string, sets?: array<string, array<string, string>>}>  $fields  handle to type, with each replicator set's fields
     * @param  callable(string $html, string $field, ?string $set): array<int, mixed>  $bard  HTML to Bard nodes
     * @param  string  $rowId  the key a page builder row is identified by, which a site can change
     * @return ?array<string, mixed> the body as entry data, or null where nothing fits
     */
    public function place(array $fields, string $markdown, callable $bard, string $rowId = 'id'): ?array
    {
        $fields = array_filter($fields, fn (array $field, string $handle): bool => ! $this->reserved($handle), ARRAY_FILTER_USE_BOTH);
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        foreach ($this->textual as $type) {
            $handle = $this->preferred(array_keys(array_filter($fields, fn (array $field): bool => $field['type'] === $type)));

            if ($handle !== null) {
                return [$handle => $this->value($type, $markdown, $html, fn (string $html): array => $bard($html, $handle, null))];
            }
        }

        foreach ($fields as $handle => $field) {
            if ($field['type'] !== 'replicator') {
                continue;
            }

            $set = $this->preferred(array_keys(array_filter($field['sets'] ?? [], fn (array $setFields): bool => array_intersect($setFields, $this->textual) !== [])));

            if ($set === null) {
                continue;
            }

            $setFields = $field['sets'][$set];
            $type = in_array('bard', $setFields, true) ? 'bard' : 'markdown';
            $inner = $this->preferred(array_keys(array_filter($setFields, fn (string $setType): bool => $setType === $type)));

            return [$handle => [[
                $rowId => Str::random(8),
                'type' => $set,
                'enabled' => true,
                $inner => $this->value($type, $markdown, $html, fn (string $html): array => $bard($html, $inner, $set)),
            ]]];
        }

        $textarea = $this->preferred(array_keys(array_filter(
            $fields,
            fn (array $field, string $handle): bool => $field['type'] === 'textarea' && in_array($handle, $this->likelyNames, true),
            ARRAY_FILTER_USE_BOTH,
        )));

        return $textarea === null ? null : [$textarea => $markdown];
    }

    private function value(string $type, string $markdown, string $html, callable $bard): mixed
    {
        return $type === 'bard' ? $bard($html) : $markdown;
    }

    /**
     * The handle most likely to be the body: one with a body-like name, else
     * the first.
     *
     * @param  array<int, string>  $handles
     */
    private function preferred(array $handles): ?string
    {
        foreach ($this->likelyNames as $name) {
            if (in_array($name, $handles, true)) {
                return $name;
            }
        }

        return $handles[0] ?? null;
    }

    private function reserved(string $handle): bool
    {
        return in_array($handle, ['title', 'slug'], true) || str_starts_with($handle, 'alt_seo_');
    }
}
