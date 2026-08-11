<?php

use AltDesign\Altimiser\Applying\AiTemplateEditor;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\ImageDimensions;
use AltDesign\Altimiser\Applying\TemplateApplier;
use AltDesign\Altimiser\Applying\TemplateLocator;
use AltDesign\Altimiser\Applying\TemplatePatcher;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-git-views';
    $this->views = base_path($this->relative);
    $this->file = $this->views.'/page.antlers.html';
    $this->source = '<section><img src="/hero.jpg" alt="Hero"><p>Body</p></section>';

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);
    File::put($this->file, $this->source);

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded']);
    config()->set('altimiser.ai.enabled', false);
    config()->set('altimiser.git.enabled', true);
    config()->set('altimiser.git.message', '[BOT] Altimiser: :check on :url');
});

afterEach(function () {
    File::deleteDirectory($this->views);
});

function applierWithGit(GitRepository $git): TemplateApplier
{
    return new TemplateApplier(
        new TemplatePatcher,
        app(TemplateLocator::class),
        app(AiTemplateEditor::class),
        $git,
        app(ImageDimensions::class),
    );
}

function heroChange(): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: ['selector' => 'img[src="/hero.jpg"]', 'attribute' => 'loading', 'match' => '/hero.jpg'],
        currentValue: null,
        suggestedValue: 'lazy',
    );
}

function stubGit(array $behaviour = []): GitRepository
{
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn($behaviour['available'] ?? true);
    $git->shouldReceive('isClean')->andReturn($behaviour['clean'] ?? true);
    $git->shouldReceive('commit')->andReturn(array_key_exists('commit', $behaviour) ? $behaviour['commit'] : 'abc1234');
    $git->shouldReceive('push')->andReturn($behaviour['pushed'] ?? true);

    return $git;
}

it('will not touch a template on a site that is not a git repository', function () {
    $result = applierWithGit(stubGit(['available' => false]))->apply(heroChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('git_unavailable')
        ->and($result['id'])->toBe('abc')
        ->and(File::get($this->file))->toBe($this->source);
});

it('will not edit a template that has uncommitted changes', function () {
    $result = applierWithGit(stubGit(['clean' => false]))->apply(heroChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('git_dirty')
        ->and($result['message'])->toContain('uncommitted changes')
        ->and(File::get($this->file))->toBe($this->source);
});

it('surfaces a dirty template during a dry run rather than at apply time', function () {
    $result = applierWithGit(stubGit(['clean' => false]))->apply(heroChange(), dryRun: true)->toArray();

    expect($result['reason'])->toBe('git_dirty');
});

it('records the commit and whether it was pushed', function () {
    $result = applierWithGit(stubGit())->apply(heroChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['commit'])->toBe('abc1234')
        ->and($result['location']['pushed'])->toBeTrue()
        ->and(File::get($this->file))->toContain('loading="lazy"');
});

it('reports an applied but unpushed change honestly', function () {
    $result = applierWithGit(stubGit(['pushed' => false]))->apply(heroChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['pushed'])->toBeFalse();
});

it('rolls the file back when the commit fails', function () {
    $result = applierWithGit(stubGit(['commit' => null]))->apply(heroChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toContain('rolled back')
        ->and(File::get($this->file))->toBe($this->source);
});

it('never commits on a dry run', function () {
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(true);
    $git->shouldReceive('isAvailable')->andReturn(true);
    $git->shouldReceive('isClean')->andReturn(true);
    $git->shouldNotReceive('commit');
    $git->shouldNotReceive('push');

    $result = applierWithGit($git)->apply(heroChange(), dryRun: true)->toArray();

    expect($result['status'])->toBe('applied')
        ->and(File::get($this->file))->toBe($this->source);
});

it('writes without git when git integration is switched off', function () {
    config()->set('altimiser.git.enabled', false);

    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn(false);
    $git->shouldNotReceive('commit');

    $result = applierWithGit($git)->apply(heroChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location'])->not->toHaveKey('commit')
        ->and(File::get($this->file))->toContain('loading="lazy"');
});
