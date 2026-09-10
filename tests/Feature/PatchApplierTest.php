<?php

use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\PatchApplier;

function patchGit(array $behaviour = []): GitRepository
{
    $git = Mockery::mock(GitRepository::class)->makePartial();
    $git->shouldReceive('enabled')->andReturn($behaviour['enabled'] ?? true);
    $git->shouldReceive('isAvailable')->andReturn($behaviour['available'] ?? true);
    $git->shouldReceive('isClean')->andReturn($behaviour['clean'] ?? true);
    $git->shouldReceive('commit')->andReturn(array_key_exists('commit', $behaviour) ? $behaviour['commit'] : 'abc1234');
    $git->shouldReceive('push')->andReturn($behaviour['pushed'] ?? true);

    return $git;
}

function templatePatch(string $file = 'resources/views/page.antlers.html'): string
{
    return implode("\n", [
        "diff --git a/{$file} b/{$file}",
        'index 1111111..2222222 100644',
        "--- a/{$file}",
        "+++ b/{$file}",
        '@@ -1,1 +1,1 @@',
        '-<h2>{{ title }}</h2>',
        '+<h1>{{ title }}</h1>',
    ])."\n";
}

/** A real working tree, because git apply is the thing under test. */
function repositoryWith(string $contents): string
{
    $repository = sys_get_temp_dir().'/altimiser-patch-'.bin2hex(random_bytes(4));

    mkdir($repository.'/resources/views', 0777, true);
    file_put_contents($repository.'/resources/views/page.antlers.html', $contents);

    app()->setBasePath($repository);

    return $repository;
}

beforeEach(function () {
    config()->set('altimiser.template_paths', ['resources/views']);
});

it('refuses a patch that reaches outside the templates', function () {
    $result = (new PatchApplier(patchGit()))->apply(templatePatch('content/collections/pages/team.md'), 'Fixes');

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('outside_templates')
        ->and($result['message'])->toContain('not a template on this site');
});

it('refuses to write over a file somebody else has edited', function () {
    $result = (new PatchApplier(patchGit(['clean' => false])))->apply(templatePatch(), 'Fixes');

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('git_dirty');
});

it('refuses when the site is not a git working tree', function () {
    $result = (new PatchApplier(patchGit(['available' => false])))->apply(templatePatch(), 'Fixes');

    expect($result['reason'])->toBe('git_unavailable');
});

it('refuses an empty patch rather than committing nothing', function () {
    expect((new PatchApplier(patchGit()))->apply('   ', 'Fixes')['reason'])->toBe('empty');
});

it('applies a patch and commits the lot as one change', function () {
    $repository = repositoryWith("<h2>{{ title }}</h2>\n");

    $result = (new PatchApplier(patchGit()))->apply(templatePatch(), 'Heading levels');

    expect($result['status'])->toBe('applied')
        ->and($result['commit'])->toBe('abc1234')
        ->and($result['files'])->toBe(['resources/views/page.antlers.html'])
        ->and(file_get_contents($repository.'/resources/views/page.antlers.html'))
        ->toBe("<h1>{{ title }}</h1>\n");
});

it('refuses a patch that no longer applies to the file it was written against', function () {
    // The templates have moved on since the agent read them. Git knows that and
    // we do not, so it is asked before anything is written.
    repositoryWith("<h3>{{ title }}</h3>\n");

    $result = (new PatchApplier(patchGit()))->apply(templatePatch(), 'Heading levels');

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('does_not_apply');
});

it('leaves the file alone when it cannot be committed', function () {
    $repository = repositoryWith("<h2>{{ title }}</h2>\n");

    $result = (new PatchApplier(patchGit(['commit' => null])))->apply(templatePatch(), 'Heading levels');

    // Applied but not committed. Reverting it on a site somebody may already be
    // looking at is worse than an uncommitted change they can see in git status.
    expect($result['status'])->toBe('failed')
        ->and($result['reason'])->toBe('commit_failed')
        ->and(file_get_contents($repository.'/resources/views/page.antlers.html'))
        ->toBe("<h1>{{ title }}</h1>\n");
});

it('applies a patch whose trailing newline was trimmed in transit', function () {
    $repository = repositoryWith("<h2>{{ title }}</h2>\n");

    // What arrives after Laravel's TrimStrings has been over the request body.
    $trimmed = rtrim(templatePatch());

    $result = (new PatchApplier(patchGit()))->apply($trimmed, 'Heading levels');

    expect($result['status'])->toBe('applied')
        ->and(file_get_contents($repository.'/resources/views/page.antlers.html'))
        ->toBe("<h1>{{ title }}</h1>\n");
});
