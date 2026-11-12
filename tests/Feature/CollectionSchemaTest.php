<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\CollectionSchema;
use AltDesign\Altimiser\Applying\ContentApplier;
use AltDesign\Altimiser\Applying\ContentResolver;
use AltDesign\Altimiser\Applying\FieldResolver;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\ValueResolver;
use AltDesign\Altimiser\Collections;
use Illuminate\Support\Collection as Values;

/** Statamic's collection as far as this touches it: its handle, its file and its inject. */
function collectionNamed(string $handle, array $inject = []): object
{
    return new class($handle, $inject)
    {
        public int $saves = 0;

        public bool $refuses = false;

        private Values $cascade;

        public function __construct(private string $handle, array $inject)
        {
            $this->cascade = collect($inject);
        }

        public function handle(): string
        {
            return $this->handle;
        }

        public function path(): string
        {
            return base_path("content/collections/{$this->handle}.yaml");
        }

        public function fileContents(): string
        {
            return "title: {$this->handle}\n";
        }

        public function cascade($key = null, $default = null)
        {
            if ($key === null) {
                return $this->cascade;
            }

            if (is_array($key)) {
                $this->cascade = collect($key);

                return $this;
            }

            return $this->cascade->get($key, $default);
        }

        public function save(): bool
        {
            if ($this->refuses) {
                return false;
            }

            $this->saves++;

            return true;
        }
    };
}

function onDisk(object $collection, ?string $contents = null): object
{
    @mkdir(dirname($collection->path()), 0777, true);
    file_put_contents($collection->path(), $contents ?? $collection->fileContents());

    return $collection;
}

function entryOf(object $collection, ?string $blueprintDefault = null): object
{
    return new class($collection, $blueprintDefault)
    {
        public function __construct(private object $collection, private ?string $blueprintDefault) {}

        public function collectionHandle(): string
        {
            return $this->collection->handle();
        }

        public function collection(): object
        {
            return $this->collection;
        }

        public function blueprint(): object
        {
            return new class($this->blueprintDefault)
            {
                public function __construct(private ?string $default) {}

                public function field(string $handle): ?object
                {
                    return $handle !== 'alt_seo_schema' ? null : new class($this->default)
                    {
                        public function __construct(private ?string $default) {}

                        public function defaultValue(): ?string
                        {
                            return $this->default;
                        }
                    };
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

function collectionApplier(object $entry, array $git = []): ContentApplier
{
    config()->set('altimiser.git.enabled', true);
    config()->set('altimiser.git.message', '[BOT] Altimiser: :check on :url');
    config()->set('statamic.git.enabled', false);

    $repository = Mockery::mock(GitRepository::class)->makePartial();
    $repository->shouldReceive('enabled')->andReturn(true);
    $repository->shouldReceive('isAvailable')->andReturn(true);
    $repository->shouldReceive('isClean')->andReturn(true, false);
    $repository->shouldReceive('head')->andReturn('sha-before', 'sha-before');
    $repository->shouldReceive('commit')->andReturn(array_key_exists('commit', $git) ? $git['commit'] : 'abc1234');
    $repository->shouldReceive('push')->andReturn(true);

    $content = Mockery::mock(ContentResolver::class);
    $content->shouldReceive('find')->andReturn($entry);

    return new ContentApplier(new FieldResolver(new ValueResolver), new ValueResolver, $content, $repository, new CollectionSchema);
}

beforeEach(function () {
    $this->schema = new CollectionSchema;
});

afterEach(function () {
    @unlink(base_path('content/collections/news.yaml'));
    @rmdir(base_path('content/collections'));
    @rmdir(base_path('content'));
});

it('applies structured data to the collection and commits the collection file', function () {
    $news = onDisk(collectionNamed('news', ['template' => 'news/show']));

    $result = collectionApplier(entryOf($news))->apply(schemaChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['type'])->toBe('collection')
        ->and($result['location']['collection'])->toBe('news')
        ->and($result['location']['file'])->toBe('content/collections/news.yaml')
        ->and($result['location']['commit'])->toBe('abc1234')
        ->and($news->cascade()->all())->toBe(['template' => 'news/show', 'alt_seo_schema' => schemaChange()->suggestedValue]);
});

it('calls the next entry in the collection already done', function () {
    $news = collectionNamed('news', ['alt_seo_schema' => schemaChange()->suggestedValue]);

    $result = collectionApplier(entryOf($news))->apply(schemaChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('already_applied')
        ->and($news->saves)->toBe(0);
});

it('leaves a default somebody else chose in place', function () {
    $news = collectionNamed('news');

    $result = collectionApplier(entryOf($news, '{"@type":"BlogPosting"}'))->apply(schemaChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('default_present')
        ->and($news->saves)->toBe(0);
});

it('leaves a collection file edited by hand to a developer, rather than rewrite it', function () {
    $news = onDisk(collectionNamed('news'), "# Kept by hand.\ntitle: news\n");

    $result = collectionApplier(entryOf($news))->apply(schemaChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('collection_file_edited')
        ->and($news->saves)->toBe(0)
        ->and(file_get_contents($news->path()))->toBe("# Kept by hand.\ntitle: news\n");
});

it('fails rather than report a save Statamic refused', function () {
    $news = onDisk(collectionNamed('news'));
    $news->refuses = true;

    $result = collectionApplier(entryOf($news))->apply(schemaChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('failed')
        ->and($result['reason'])->toBe('write_failed');
});

it('puts the collection back when the commit fails', function () {
    $news = onDisk(collectionNamed('news', ['template' => 'news/show']));

    $result = collectionApplier(entryOf($news), ['commit' => null])->apply(schemaChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toContain('put back')
        ->and($news->cascade()->all())->toBe(['template' => 'news/show']);
});

it('writes a collection its own default rather than every entry', function () {
    $news = collectionNamed('news');

    expect($this->schema->collection(schemaChange(), entryOf($news)))->toBe($news);

    $this->schema->write($news, '{"@type":"NewsArticle","headline":"{{ title }}"}');

    expect($news->cascade('alt_seo_schema'))->toBe('{"@type":"NewsArticle","headline":"{{ title }}"}')
        ->and($news->saves)->toBe(1)
        ->and($this->schema->current($news, entryOf($news)))->toBe('{"@type":"NewsArticle","headline":"{{ title }}"}');
});

it('keeps whatever else the collection injects', function () {
    $news = collectionNamed('news', ['template' => 'news/show']);

    $this->schema->write($news, '{"@type":"NewsArticle"}');

    expect($news->cascade()->all())->toBe(['template' => 'news/show', 'alt_seo_schema' => '{"@type":"NewsArticle"}']);
});

it('puts the inject back as it was', function () {
    $news = collectionNamed('news', ['template' => 'news/show']);
    $before = $this->schema->injected($news);

    $this->schema->write($news, '{"@type":"NewsArticle"}');
    $this->schema->restore($news, $before);

    expect($news->cascade()->all())->toBe(['template' => 'news/show']);
});

it('counts a default somebody has already chosen, injected or in the blueprint', function () {
    $injected = collectionNamed('news', ['alt_seo_schema' => '{"@type":"Article"}']);
    $inBlueprint = collectionNamed('news');

    expect($this->schema->current($injected, entryOf($injected)))->toBe('{"@type":"Article"}')
        ->and($this->schema->current($inBlueprint, entryOf($inBlueprint, '{"@type":"BlogPosting"}')))->toBe('{"@type":"BlogPosting"}')
        ->and($this->schema->current($inBlueprint, entryOf($inBlueprint)))->toBeNull();
});

it('leaves pages alone, where entries share a template and nothing else', function () {
    expect($this->schema->collection(schemaChange(), entryOf(collectionNamed('pages'))))->toBeNull()
        ->and($this->schema->sharesSchema('pages'))->toBeFalse()
        ->and($this->schema->sharesSchema('news'))->toBeTrue();
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

    expect($this->schema->collection($change, entryOf(collectionNamed('news'))))->toBeNull();
});

it('can be switched off entirely', function () {
    config()->set('altimiser.collection_defaults.enabled', false);

    expect($this->schema->collection(schemaChange(), entryOf(collectionNamed('news'))))->toBeNull()
        ->and($this->schema->sharesSchema('news'))->toBeFalse();
});

it('gives the collection file relative to the project, and none where it is not on disk', function () {
    expect($this->schema->path(collectionNamed('news')))->toBeNull()
        ->and($this->schema->path(onDisk(collectionNamed('news'))))->toBe('content/collections/news.yaml');
});

it('reports the sections a collection\'s entries sit under, whatever its route says', function () {
    $sections = (new Collections)->sections([
        '/machine/genie-gs-1932',
        '/machine/genie-gs-2632',
        '/material-lift/genie-slc-24',
        '/about',
        null,
    ]);

    expect($sections)->toBe(['machine', 'material-lift']);
});

it('reports only the sections no other collection also uses, and none for a page tree', function () {
    $sections = [
        'machines' => ['machine', 'material-lift'],
        'pages' => ['about', 'machine'],
        'news' => ['latest-news'],
    ];
    $collections = new Collections;

    expect($collections->ownSections($sections, 'machines', '/{product_category}/{slug}'))->toBe(['material-lift'])
        ->and($collections->ownSections($sections, 'news', '/latest-news/{slug}'))->toBe(['latest-news'])
        ->and($collections->ownSections($sections, 'pages', '{parent_uri}/{slug}'))->toBe([]);
});
