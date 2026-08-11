<?php

namespace AltDesign\Altimiser\Applying;

use Illuminate\Support\Facades\Process;

class GitRepository
{
    private ?string $workingDirectory = null;

    public function in(string $directory): self
    {
        $clone = clone $this;
        $clone->workingDirectory = $directory;

        return $clone;
    }

    public function enabled(): bool
    {
        return (bool) config('altimiser.git.enabled');
    }

    public function isAvailable(): bool
    {
        return $this->run(['rev-parse', '--is-inside-work-tree'])->successful();
    }

    /**
     * A file with uncommitted changes is somebody's work in progress. Editing it
     * would mix our change into theirs, and committing it would sweep their work
     * into our commit, so we refuse rather than untangle it.
     */
    public function isClean(string $relativePath): bool
    {
        $status = $this->run(['status', '--porcelain', '--', $relativePath]);

        return $status->successful() && trim($status->output()) === '';
    }

    /**
     * Identity is passed per-command because the web user on a deployed site
     * usually has no ~/.gitconfig, which would otherwise make the commit fail.
     */
    public function commit(string $relativePath, string $message): ?string
    {
        if (! $this->run(['add', '--', $relativePath])->successful()) {
            return null;
        }

        [$name, $email] = $this->author();

        $committed = $this->run([
            '-c', "user.name={$name}",
            '-c', "user.email={$email}",
            'commit',
            '--message', $message,
            '--only', '--', $relativePath,
        ]);

        if (! $committed->successful()) {
            return null;
        }

        $sha = $this->run(['rev-parse', 'HEAD']);

        return $sha->successful() ? trim($sha->output()) : null;
    }

    public function push(): bool
    {
        if (! config('altimiser.git.push')) {
            return false;
        }

        $remote = config('altimiser.git.remote', 'origin');
        $branch = config('altimiser.git.branch') ?: $this->currentBranch();

        if ($branch === null) {
            return false;
        }

        return $this->run(['push', $remote, "HEAD:{$branch}"])->successful();
    }

    /** Used to tell "somebody else committed this" from "nothing was written". */
    public function head(): ?string
    {
        $sha = $this->run(['rev-parse', 'HEAD']);

        return $sha->successful() ? trim($sha->output()) : null;
    }

    public function currentBranch(): ?string
    {
        $branch = $this->run(['rev-parse', '--abbrev-ref', 'HEAD']);

        if (! $branch->successful()) {
            return null;
        }

        $name = trim($branch->output());

        return $name === 'HEAD' ? null : $name;
    }

    public function resetFile(string $relativePath): void
    {
        $this->run(['checkout', '--', $relativePath]);
    }

    /** @return array{0: string, 1: string} */
    private function author(): array
    {
        $author = config('altimiser.git.author', 'Altimiser <altimiser@example.com>');

        if (preg_match('/^(.*?)\s*<(.+)>$/', $author, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [$author, 'altimiser@localhost'];
    }

    /** @param array<int, string> $arguments */
    private function run(array $arguments)
    {
        return Process::path($this->workingDirectory ?? base_path())
            ->timeout(60)
            ->run([config('altimiser.git.binary', 'git'), ...$arguments]);
    }
}
