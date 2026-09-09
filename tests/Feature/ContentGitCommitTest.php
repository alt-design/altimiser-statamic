<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\ContentApplier;
use AltDesign\Altimiser\Applying\ContentResolver;
use AltDesign\Altimiser\Applying\FieldResolver;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\ValueResolver;

beforeEach(function () {
    config()->set('altimiser.fields', ['meta_description' => ['seo_description']]);
    config()->set('altimiser.git.enabled', true);
    config()->set('altimiser.git.message', '[BOT] Altimiser: :check on :url');
    config()->set('statamic.git.enabled', false);
});

function stubbedEntry(array $data = ['seo_description' => 'About us.']): object
{
    return new class($data)
    {
        public array $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function data()
        {
            return collect($this->data);
        }

        public function blueprint()
        {
            return null;
        }

        public function get(string $key)
        {
            return $this->data[$key] ?? null;
        }

        public function set(string $key, $value): self
        {
            $this->data[$key] = $value;

            return $this;
        }

        public function remove(string $key): self
        {
            unset($this->data[$key]);

            return $this;
        }

        public function save(): bool
        {
            return true;
        }

        public function path(): string
        {
            return base_path('content/collections/pages/about.md');
        }

        public function id(): string
        {
            return 'entry-1';
        }

        public function collectionHandle(): string
        {
            return 'pages';
        }
    };
}

function contentGit(array $behaviour = []): GitRepository
{
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn($behaviour['available'] ?? true);
    // Clean before the write, changed after it, which is what a real save looks like.
    $git->shouldReceive('isClean')->andReturn($behaviour['clean'] ?? true, false);
    // Unchanged HEAD means nothing committed the file while we were saving it.
    $git->shouldReceive('head')->andReturn(...($behaviour['head'] ?? ['sha-before', 'sha-before']));
    $git->shouldReceive('commit')->andReturn(array_key_exists('commit', $behaviour) ? $behaviour['commit'] : 'abc1234');
    $git->shouldReceive('push')->andReturn($behaviour['pushed'] ?? true);

    return $git;
}

function contentApplierWith(GitRepository $git, object $entry): ContentApplier
{
    $content = Mockery::mock(ContentResolver::class);
    $content->shouldReceive('find')->andReturn($entry);

    return new ContentApplier(new FieldResolver(new ValueResolver), new ValueResolver, $content, $git);
}

function descriptionChange(): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: 'meta_description.too_short',
        url: 'https://example.com/about',
        target: [],
        currentValue: 'About us.',
        suggestedValue: 'About us, what we do and who we do it for.',
    );
}

it('commits a content change when nothing else is watching the repository', function () {
    $result = contentApplierWith(contentGit(), stubbedEntry())->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['file'])->toBe('content/collections/pages/about.md')
        ->and($result['location']['commit'])->toBe('abc1234');
});

it('records the commit Statamic made during the save rather than making a second one', function () {
    config()->set('statamic.git.enabled', true);

    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn(true);
    $git->shouldReceive('isClean')->andReturn(true);
    // HEAD moved while we were saving, which is Statamic's subscriber committing
    // synchronously off the EntrySaved event.
    $git->shouldReceive('head')->andReturn('sha-before', 'sha-after');
    $git->shouldNotReceive('commit');

    $result = contentApplierWith($git, stubbedEntry())->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['commit'])->toBe('sha-after')
        ->and($result['location']['committed_by'])->toBe('statamic');
});

it('commits it itself when Statamic has been left to it and has not done it', function () {
    // Statamic commits from a queued job, so a site without a worker saves the
    // file and commits it never. The change still has to end up in a commit.
    config()->set('statamic.git.enabled', true);

    $result = contentApplierWith(contentGit(), stubbedEntry())->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['commit'])->toBe('abc1234')
        ->and($result['location'])->not->toHaveKey('committed_by');
});

it('will not write over an entry file that has uncommitted changes', function () {
    $result = contentApplierWith(contentGit(['clean' => false]), stubbedEntry())->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('git_dirty')
        ->and($result['id'])->toBe('abc');
});

it('puts the value back when the commit fails', function () {
    $entry = stubbedEntry();

    $result = contentApplierWith(contentGit(['commit' => null]), $entry)->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toContain('put back')
        ->and($entry->get('seo_description'))->toBe('About us.');
});

it('never commits on a dry run', function () {
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn(true);
    $git->shouldReceive('isClean')->andReturn(true);
    $git->shouldNotReceive('commit');

    $result = contentApplierWith($git, stubbedEntry())->apply(descriptionChange(), dryRun: true)->toArray();

    expect($result['status'])->toBe('applied');
});

it('does not commit when the save wrote nothing to disk', function () {
    // What a database-backed site looks like from here: the entry knows a path,
    // because the Eloquent driver's Entry extends the file one, but saving it
    // leaves that path exactly as it was.
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn(true);
    $git->shouldReceive('isClean')->andReturn(true);
    $git->shouldNotReceive('commit');

    $entry = stubbedEntry();
    $result = contentApplierWith($git, $entry)->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location'])->not->toHaveKey('commit')
        // The write itself still happened, so it must not be rolled back.
        ->and($entry->get('seo_description'))->toBe('About us, what we do and who we do it for.');
});

it('keeps the change when a commit race is lost rather than undoing it', function () {
    // Statamic's queued job can commit the file between our save and our commit.
    // Our git add then has nothing to do and fails, but the work is committed, so
    // putting the value back would be wrong.
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn(true);
    $git->shouldReceive('head')->andReturn('sha-before', 'sha-before', 'sha-after');
    $git->shouldReceive('isClean')->andReturn(true, false, true);
    $git->shouldReceive('commit')->andReturn(null);

    $entry = stubbedEntry();
    $result = contentApplierWith($git, $entry)->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['committed_by'])->toBe('statamic')
        ->and($entry->get('seo_description'))->toBe('About us, what we do and who we do it for.');
});

it('puts the value back when nothing committed it and our own commit failed', function () {
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn(true);
    $git->shouldReceive('head')->andReturn('sha-before');
    $git->shouldReceive('isClean')->andReturn(true, false, false);
    $git->shouldReceive('commit')->andReturn(null);

    $entry = stubbedEntry();
    $result = contentApplierWith($git, $entry)->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('failed')
        ->and($entry->get('seo_description'))->toBe('About us.');
});

it('applies to an entry whose description is blocks rather than text', function () {
    // A real Adams and Moore entry: description is a set field, and casting it
    // to a string to substitute {description} was a fatal error that failed the
    // whole apply request rather than the one change.
    $entry = stubbedEntry([
        'seo_description' => 'About us.',
        'title' => 'About',
        'description' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Blocks, not a line of text.']]],
        ],
    ]);

    $result = contentApplierWith(contentGit(), $entry)->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied');
});

it('substitutes nothing for a title that is not text either', function () {
    $entry = stubbedEntry([
        'seo_description' => 'About us.',
        'title' => ['nested' => 'somehow'],
    ]);

    $result = contentApplierWith(contentGit(), $entry)->apply(descriptionChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied');
});
