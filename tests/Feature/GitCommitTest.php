<?php

use AltDesign\Altimiser\Applying\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * These run against a real repository in a temp directory rather than a mock.
 * The whole point of the git integration is how the git binary actually behaves,
 * which a mock would assert nothing about.
 */

function git(array $arguments, ?string $directory = null): void
{
    Process::path($directory ?? test()->repo)->run(['git', ...$arguments])->throw();
}

beforeEach(function () {
    $this->repo = sys_get_temp_dir().'/altimiser-git-'.bin2hex(random_bytes(6));

    File::makeDirectory($this->repo.'/resources/views', recursive: true);
    File::put($this->repo.'/resources/views/page.antlers.html', '<img src="{{ hero:url }}">');

    git(['init', '--initial-branch=main']);
    git(['config', 'user.email', 'test@example.com']);
    git(['config', 'user.name', 'Test']);
    git(['add', '.']);
    git(['commit', '-m', 'Initial']);

    config()->set('altimiser.git.enabled', true);
    config()->set('altimiser.git.binary', 'git');
    config()->set('altimiser.git.push', false);
    config()->set('altimiser.git.message', '[BOT] Altimiser: :check on :url');
    config()->set('altimiser.git.author', 'Altimiser <altimiser@alt-design.net>');

    $this->git = app(GitRepository::class)->in($this->repo);
});

afterEach(function () {
    File::deleteDirectory($this->repo);
});

function lastCommitMessage(string $repo): string
{
    return trim(Process::path($repo)->run(['git', 'log', '-1', '--pretty=%s'])->output());
}

it('recognises a git working tree', function () {
    expect($this->git->isAvailable())->toBeTrue();
});

it('knows a directory that is not a repository', function () {
    $plain = sys_get_temp_dir().'/altimiser-plain-'.bin2hex(random_bytes(6));
    File::makeDirectory($plain, recursive: true);

    expect(app(GitRepository::class)->in($plain)->isAvailable())->toBeFalse();

    File::deleteDirectory($plain);
});

it('sees a clean file as clean', function () {
    expect($this->git->isClean('resources/views/page.antlers.html'))->toBeTrue();
});

it('sees an edited file as dirty', function () {
    File::put($this->repo.'/resources/views/page.antlers.html', '<img src="{{ hero:url }}" class="wip">');

    expect($this->git->isClean('resources/views/page.antlers.html'))->toBeFalse();
});

it('does not call a file dirty because something else is', function () {
    File::put($this->repo.'/unrelated.txt', 'work in progress');

    expect($this->git->isClean('resources/views/page.antlers.html'))->toBeTrue();
});

it('commits an edit with the bot prefix', function () {
    File::put($this->repo.'/resources/views/page.antlers.html', '<img src="{{ hero:url }}" loading="lazy">');

    $sha = $this->git->commit('resources/views/page.antlers.html', '[BOT] Altimiser: image.not_lazy_loaded on /about');

    expect($sha)->not->toBeNull()
        ->and(lastCommitMessage($this->repo))->toStartWith('[BOT]')
        ->and(lastCommitMessage($this->repo))->toBe('[BOT] Altimiser: image.not_lazy_loaded on /about')
        ->and($this->git->isClean('resources/views/page.antlers.html'))->toBeTrue();
});

it('commits without any global git identity configured', function () {
    git(['config', '--unset', 'user.email']);
    git(['config', '--unset', 'user.name']);

    File::put($this->repo.'/resources/views/page.antlers.html', '<img src="{{ hero:url }}" loading="lazy">');

    expect($this->git->commit('resources/views/page.antlers.html', '[BOT] Altimiser: test'))->not->toBeNull();

    $author = trim(Process::path($this->repo)->run(['git', 'log', '-1', '--pretty=%an <%ae>'])->output());

    expect($author)->toBe('Altimiser <altimiser@alt-design.net>');
});

it('commits only the file it was given', function () {
    File::put($this->repo.'/resources/views/page.antlers.html', '<img src="{{ hero:url }}" loading="lazy">');
    File::put($this->repo.'/unrelated.txt', 'someone else work in progress');
    git(['add', 'unrelated.txt']);

    $this->git->commit('resources/views/page.antlers.html', '[BOT] Altimiser: test');

    $files = trim(Process::path($this->repo)->run(['git', 'show', '--name-only', '--pretty=', 'HEAD'])->output());

    expect($files)->toBe('resources/views/page.antlers.html');
});

it('leaves one revertable commit per edit', function () {
    $path = $this->repo.'/resources/views/page.antlers.html';

    File::put($path, '<img src="{{ hero:url }}" loading="lazy">');
    $sha = $this->git->commit('resources/views/page.antlers.html', '[BOT] Altimiser: test');

    Process::path($this->repo)->run(['git', 'revert', '--no-edit', $sha])->throw();

    expect(File::get($path))->toBe('<img src="{{ hero:url }}">');
});

it('reports the branch it is on', function () {
    expect($this->git->currentBranch())->toBe('main');
});

it('does not push when pushing is turned off', function () {
    expect($this->git->push())->toBeFalse();
});
