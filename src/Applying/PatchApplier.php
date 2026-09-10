<?php

namespace AltDesign\Altimiser\Applying;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Applies a patch an agent wrote against a copy of this repository.
 *
 * The rest of this addon writes one value into one field. This writes a diff
 * across several template files, which is a bigger thing to accept, so it is
 * checked harder before anything lands: every file has to be a template, the
 * working tree has to be clean where it is about to write, and git itself has
 * to agree the patch applies before it is allowed to.
 *
 * One commit, like every other change here, so git revert undoes the lot.
 */
class PatchApplier
{
    use CommitsSavedFiles;

    public function __construct(private GitRepository $git) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(string $patch, string $message, bool $dryRun = false): array
    {
        if (blank(trim($patch))) {
            return $this->refused('empty', 'There was no patch to apply.');
        }

        /*
         * A patch has to end with a newline or git calls it corrupt, naming the
         * last line as the fault. Laravel trims every string on the way in, so
         * by the time a perfectly good patch reaches here its final newline has
         * been eaten by middleware and the error points at the diff rather than
         * at the transport that damaged it.
         */
        $patch = rtrim($patch, "\r\n")."\n";

        $files = $this->filesIn($patch);

        if ($files === []) {
            return $this->refused('empty', 'The patch names no files.');
        }

        $outside = $this->outsideTemplates($files);

        if ($outside !== null) {
            return $this->refused('outside_templates', $outside);
        }

        if (! $this->git->enabled() || ! $this->git->isAvailable()) {
            return $this->refused('git_unavailable', 'This site is not a git working tree, so a patch cannot be applied safely.');
        }

        foreach ($files as $file) {
            if (! $this->git->isClean($file)) {
                return $this->refused('git_dirty', "{$file} has uncommitted changes. Commit or discard them and try again.");
            }
        }

        $checked = $this->run(['git', 'apply', '--check', '--whitespace=nowarn', '-'], $patch);

        if (! $checked->successful()) {
            return $this->refused('does_not_apply', 'The patch no longer applies to these files: '.trim($checked->errorOutput()));
        }

        if ($dryRun) {
            return ['status' => 'applied', 'files' => $files, 'dry_run' => true];
        }

        $applied = $this->run(['git', 'apply', '--whitespace=nowarn', '-'], $patch);

        if (! $applied->successful()) {
            return $this->refused('write_failed', trim($applied->errorOutput()));
        }

        return $this->commit($files, $message);
    }

    /**
     * @param  array<int, string>  $files
     * @return array<string, mixed>
     */
    private function commit(array $files, string $message): array
    {
        $commit = $this->git->commit($files, $message);

        if ($commit === null) {
            // Left on disk rather than reverted: undoing an applied patch on a
            // site somebody may already be looking at is a worse outcome than
            // an uncommitted change somebody can see in git status.
            return [
                'status' => 'failed',
                'reason' => 'commit_failed',
                'message' => 'The patch was applied but could not be committed. It is on disk and needs committing by hand.',
                'files' => $files,
            ];
        }

        return [
            'status' => 'applied',
            'files' => $files,
            'commit' => $commit,
            'pushed' => $this->git->push(),
        ];
    }

    private function run(array $command, string $input)
    {
        return Process::path(base_path())->timeout(120)->input($input)->run($command);
    }

    /** @param array<int, string> $files */
    private function outsideTemplates(array $files): ?string
    {
        foreach ($files as $file) {
            foreach (config('altimiser.template_paths', []) as $allowed) {
                if (str_starts_with($file, rtrim($allowed, '/').'/')) {
                    continue 2;
                }
            }

            return "The patch changes {$file}, which is not a template on this site.";
        }

        return null;
    }

    /** @return array<int, string> */
    private function filesIn(string $patch): array
    {
        try {
            preg_match_all('/^diff --git a\/(\S+) b\/(\S+)$/m', $patch, $matches);

            return array_values(array_unique([...$matches[1], ...$matches[2]]));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function refused(string $reason, string $message): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'message' => $message];
    }
}
