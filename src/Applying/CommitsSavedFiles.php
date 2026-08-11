<?php

namespace AltDesign\Altimiser\Applying;

/**
 * Git handling for the appliers that save through Statamic rather than writing
 * files themselves.
 *
 * Saving an entry or an asset dispatches Statamic's own events, and if its git
 * integration is switched on it will commit the result too. That is not
 * something to assume, though: it commits from a queued job, so a site without
 * a worker, or with a dispatch delay, saves the file and commits it never.
 *
 * So this does not decide up front who is responsible. It saves, then looks at
 * what actually happened, and commits whatever is still sitting there. Either
 * way the change ends up in a commit, which is the only outcome that matters:
 * an uncommitted edit on a site that deploys from a repository is a change
 * waiting to be silently wiped.
 */
trait CommitsSavedFiles
{
    private function shouldCommit(?string $file): bool
    {
        return $file !== null && $this->git->enabled();
    }

    private function gitBlocker(?string $file): ?ChangeResult
    {
        if (! $this->shouldCommit($file)) {
            return null;
        }

        if (! $this->git->isAvailable()) {
            return ChangeResult::skipped('', 'git_unavailable', 'This site is not a git working tree.');
        }

        if (! $this->git->isClean($file)) {
            return ChangeResult::skipped(
                '',
                'git_dirty',
                "{$file} has uncommitted changes. Commit or discard them and run this again.",
            );
        }

        return null;
    }

    /** The commit to record before saving, so a commit made during the save is visible. */
    private function headBefore(?string $file): ?string
    {
        return $this->shouldCommit($file) ? $this->git->head() : null;
    }

    /**
     * What happened to the file, as location detail to record.
     *
     * Returns null only when the change genuinely could not be committed, which
     * is the caller's cue to put the value back.
     *
     * @return ?array<string, mixed>
     */
    private function commitSaved(?string $file, ?string $headBefore, ChangeRequest $change): ?array
    {
        if (! $this->shouldCommit($file)) {
            return [];
        }

        $committedDuringSave = $this->committedDuringSave($file, $headBefore);

        if ($committedDuringSave !== null) {
            return ['file' => $file, 'commit' => $committedDuringSave, 'committed_by' => 'statamic'];
        }

        // Clean, and nobody committed: the save wrote no file at all. Normal on a
        // site holding its content in the database.
        if ($this->git->isClean($file)) {
            return [];
        }

        $commit = $this->git->commit($file, strtr(config('altimiser.git.message'), [
            ':check' => $change->check,
            ':url' => $change->path(),
        ]));

        if ($commit !== null) {
            return ['file' => $file, 'commit' => $commit, 'pushed' => $this->git->push()];
        }

        // A commit can fail because Statamic's queued job got there first, or is
        // holding the index right now. Losing a race is not a reason to undo work
        // that is already committed, so the file is asked again before giving up.
        return $this->git->isClean($file)
            ? ['file' => $file, 'commit' => $this->git->head(), 'committed_by' => 'statamic']
            : null;
    }

    /** Statamic commits synchronously when its queue connection is sync. */
    private function committedDuringSave(string $file, ?string $headBefore): ?string
    {
        if ($headBefore === null) {
            return null;
        }

        $head = $this->git->head();

        return $head !== null && $head !== $headBefore && $this->git->isClean($file) ? $head : null;
    }
}
