<?php

namespace AltDesign\Altimiser\Applying;

use Throwable;

class RobotsApplier implements Applier
{
    public function __construct(private GitRepository $git) {}

    public function supports(string $check): bool
    {
        return in_array($check, ['site.robots_missing', 'sitemap.not_declared_in_robots'], true);
    }

    /**
     * robots.txt is a plain file with a well understood shape, so there is no
     * reason to hand either of these to a model or to a developer. Writing the
     * file is deterministic and the result is trivially checkable.
     */
    public function apply(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        $relative = 'public/robots.txt';
        $path = base_path($relative);
        $existing = is_file($path) ? file_get_contents($path) : null;

        $contents = $change->check === 'site.robots_missing'
            ? $this->create($change, $existing)
            : $this->declareSitemap($change, $existing);

        if ($contents === null) {
            return ChangeResult::alreadyApplied(
                $change->id,
                ['type' => 'file', 'file' => $relative],
                'robots.txt already says this.',
            );
        }

        $blocked = $this->gitBlocker($relative);

        if ($blocked !== null) {
            return $blocked->withId($change->id);
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, ['type' => 'file', 'file' => $relative], $existing, $contents);
        }

        try {
            file_put_contents($path, $contents);
        } catch (Throwable $exception) {
            return ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
        }

        $location = ['type' => 'file', 'file' => $relative];

        if ($this->git->enabled()) {
            $commit = $this->git->commit($relative, strtr(config('altimiser.git.message'), [
                ':check' => $change->check,
                ':url' => $change->path(),
            ]));

            if ($commit === null) {
                $existing === null ? @unlink($path) : file_put_contents($path, $existing);

                return ChangeResult::failed($change->id, 'write_failed', 'Could not commit robots.txt, so it was rolled back.');
            }

            $location['commit'] = $commit;
            $location['pushed'] = $this->git->push();
        }

        return ChangeResult::applied($change->id, $location, $existing, $contents);
    }

    /**
     * A permissive robots.txt with the sitemap declared. Deliberately does not
     * invent Disallow rules: guessing what a site wants hidden is how a tool
     * accidentally deindexes something.
     */
    private function create(ChangeRequest $change, ?string $existing): ?string
    {
        if ($existing !== null) {
            return null;
        }

        $sitemap = $change->suggestedValue ?: rtrim($change->url, '/').'/sitemap.xml';

        return "User-agent: *\nDisallow:\n\nSitemap: ".$this->sitemapUrl($sitemap)."\n";
    }

    private function declareSitemap(ChangeRequest $change, ?string $existing): ?string
    {
        $line = 'Sitemap: '.$this->sitemapUrl($change->suggestedValue ?? '');

        if ($existing === null) {
            return "User-agent: *\nDisallow:\n\n{$line}\n";
        }

        if (preg_match('/^\s*sitemap:/im', $existing) === 1) {
            return null;
        }

        return rtrim($existing, "\n")."\n\n{$line}\n";
    }

    /** The suggestion arrives as either a bare URL or a whole Sitemap: line. */
    private function sitemapUrl(string $value): string
    {
        return trim(preg_replace('/^\s*sitemap:\s*/i', '', $value) ?: $value);
    }

    private function gitBlocker(string $file): ?ChangeResult
    {
        if (! $this->git->enabled()) {
            return null;
        }

        if (! $this->git->isAvailable()) {
            return ChangeResult::skipped('', 'git_unavailable', 'This site is not a git working tree.');
        }

        if (! $this->git->isClean($file)) {
            return ChangeResult::skipped('', 'git_dirty', "{$file} has uncommitted changes.");
        }

        return null;
    }
}
