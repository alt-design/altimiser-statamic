<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\CollectionSchema;

/** Adams and Moore's shape: sections rather than tabs, with the field nested. */
function blueprintYaml(?string $default = null, bool $withField = true): string
{
    $field = $withField ? implode("\n", array_filter([
        '      -',
        '        handle: alt_seo_schema',
        '        field:',
        '          type: code',
        '          mode: json',
        $default === null ? null : "          default: '{$default}'",
    ])) : '';

    return implode("\n", array_filter([
        'sections:',
        '  main:',
        '    display: Main',
        '    fields:',
        '      -',
        '        handle: title',
        '        field:',
        '          type: text',
        '  alt_seo:',
        '    display: SEO',
        '    fields:',
        '      -',
        '        handle: alt_seo_meta_title',
        '        field:',
        '          type: text',
        $field ?: null,
    ]))."\n";
}

function blueprintAt(string $relative, string $contents): string
{
    $absolute = base_path($relative);

    @mkdir(dirname($absolute), 0777, true);
    file_put_contents($absolute, $contents);

    return $relative;
}

function entryIn(string $collection, string $blueprintPath): object
{
    return new class($collection, base_path($blueprintPath))
    {
        public function __construct(private string $collection, private string $path) {}

        public function collectionHandle(): string
        {
            return $this->collection;
        }

        public function blueprint(): object
        {
            return new class($this->path)
            {
                public function __construct(private string $path) {}

                public function path(): string
                {
                    return $this->path;
                }
            };
        }
    };
}

function schemaChange(string $block = '{"@type":"NewsArticle","headline":"{{ title }}"}'): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: 'structured_data.missing',
        url: 'https://adamsmoorelaw.com/news/gross-negligence',
        target: ['selector' => 'script[type="application/ld+json"]', 'attribute' => null],
        currentValue: null,
        suggestedValue: $block,
    );
}

beforeEach(function () {
    $this->schema = new CollectionSchema;
    $this->file = blueprintAt('resources/blueprints/collections/news/news.yaml', blueprintYaml());
});

afterEach(function () {
    @unlink(base_path('resources/blueprints/collections/news/news.yaml'));
    @unlink(base_path('resources/blueprints/collections/pages/page.yaml'));
});

it('writes a collection its own default rather than every entry', function () {
    expect($this->schema->file(schemaChange(), entryIn('news', $this->file)))->toBe($this->file);

    $this->schema->write($this->file, '{"@type":"NewsArticle","headline":"{{ title }}"}');

    expect($this->schema->current($this->file))->toBe('{"@type":"NewsArticle","headline":"{{ title }}"}');
});

it('keeps the rest of the blueprint intact', function () {
    $this->schema->write($this->file, '{"@type":"NewsArticle"}');

    $contents = file_get_contents(base_path($this->file));

    expect($contents)->toContain('alt_seo_meta_title')
        ->toContain('handle: title')
        ->toContain('display: Main');
});

it('leaves pages alone, where entries share a template and nothing else', function () {
    $file = blueprintAt('resources/blueprints/collections/pages/page.yaml', blueprintYaml());

    expect($this->schema->file(schemaChange(), entryIn('pages', $file)))->toBeNull();
});

it('writes the entry instead when the blueprint does not carry the field', function () {
    // Not an oversight to work around: adding the field here would take Alt
    // SEO's injected tab away from the collection along with everything on it.
    $file = blueprintAt('resources/blueprints/collections/news/news.yaml', blueprintYaml(withField: false));

    expect($this->schema->file(schemaChange(), entryIn('news', $file)))->toBeNull();
});

it('reports a default somebody has already chosen', function () {
    $file = blueprintAt('resources/blueprints/collections/news/news.yaml', blueprintYaml('{"@type":"Article"}'));

    expect($this->schema->current($file))->toBe('{"@type":"Article"}');
});

it('does nothing for a check whose value belongs to one page', function () {
    $change = new ChangeRequest(
        id: 'abc',
        check: 'meta_description.too_short',
        url: 'https://adamsmoorelaw.com/news/gross-negligence',
        target: [],
        currentValue: 'Short.',
        suggestedValue: 'A longer description of this particular article.',
    );

    expect($this->schema->file($change, entryIn('news', $this->file)))->toBeNull();
});

it('can be switched off entirely', function () {
    config()->set('altimiser.collection_defaults.enabled', false);

    expect($this->schema->file(schemaChange(), entryIn('news', $this->file)))->toBeNull();
});
