<?php

namespace AltDesign\Altimiser\Applying;

use Throwable;

class TemplateApplier implements Applier
{
    public function __construct(
        private TemplatePatcher $patcher,
        private TemplateLocator $locator,
        private GitRepository $git,
        private ImageDimensions $dimensions,
    ) {}

    public function supports(string $check): bool
    {
        if (! config('altimiser.patch_templates')) {
            return false;
        }

        return in_array($check, config('altimiser.template_checks', []), true);
    }

    /**
     * Templates are only ever patched where the element is written out as a
     * literal and lands in exactly one file.
     *
     * An element built from variables is not this receiver's to edit. Working
     * out which tag in which partial produces it needs the whole repository in
     * front of you, and that job belongs to the agent Altimiser runs against
     * the client's own GitHub checkout, which sends back a reviewed patch.
     */
    public function apply(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        $change = $this->supplyMissingValue($change);

        if (blank($change->suggestedValue) && $change->check !== 'image.dimensions_missing') {
            return ChangeResult::skipped(
                $change->id,
                'no_suggestion',
                'There is no replacement value for this check, and this receiver cannot work one out for itself.',
            );
        }

        $located = $this->locate($change);

        if ($located !== null) {
            return $this->write(
                $change,
                $located['file'],
                $located['contents'],
                ['type' => 'template', 'file' => $located['file'], 'resolved_by' => $located['by']],
                $dryRun,
            );
        }

        $satisfied = $this->alreadySatisfied($change);

        if ($satisfied !== null) {
            return $satisfied;
        }

        // Two templates writing out the same URL is a real ambiguity, and worth
        // naming both rather than reporting a flat no_match: it is usually a
        // partial that got copied instead of included.
        $ambiguous = filled($change->matchLiteral())
            ? $this->literalPatches($change, $change->matchLiteral())
            : [];

        if (count($ambiguous) > 1) {
            return ChangeResult::skipped(
                $change->id,
                'ambiguous',
                count($ambiguous).' templates contain this element: '.implode(', ', array_keys($ambiguous)),
            );
        }

        return ChangeResult::skipped(
            $change->id,
            'no_match',
            'No template writes this element out as a literal, so it cannot be placed by matching. '
            .'Findings like this belong to the agent, which works against the repository itself.',
        );
    }

    /**
     * A value we can work out for ourselves. Dimensions read off a real file
     * are exact, and the change arrives without one precisely because only this
     * side of the connection can open the asset.
     */
    private function supplyMissingValue(ChangeRequest $change): ChangeRequest
    {
        if ($change->check !== 'image.dimensions_missing' || filled($change->suggestedValue)) {
            return $change;
        }

        $literal = $change->matchLiteral();

        if ($literal === null) {
            return $change;
        }

        $change->suggestedValue = $this->dimensions->for($literal);

        return $change;
    }

    /**
     * Finds the one template this change belongs to.
     *
     * Each literal the element offers is tried in turn and the first that lands
     * in exactly one file wins. Anything landing in several is left alone: two
     * templates writing the same element is a real ambiguity, and the next, more
     * specific literal is a better answer than a guess.
     *
     * @return ?array{file: string, contents: string, by: string}
     */
    private function locate(ChangeRequest $change): ?array
    {
        foreach ($change->locatorLiterals() as $kind => $literal) {
            $patches = $this->literalPatches($change, $literal);

            if (count($patches) === 1) {
                $file = array_key_first($patches);

                return ['file' => $file, 'contents' => $patches[$file], 'by' => $kind];
            }
        }

        return null;
    }

    /**
     * The fix is already in the template, because another change in this run made
     * the same edit or a previous run did. Reporting no_match here would call a
     * finished job a failure.
     */
    private function alreadySatisfied(ChangeRequest $change): ?ChangeResult
    {
        foreach ($change->locatorLiterals() as $kind => $literal) {
            foreach ($this->locator->containing($literal) as $path => $contents) {
                if ($this->patcher->isSatisfied($contents, $change, $literal)) {
                    return ChangeResult::alreadyApplied(
                        $change->id,
                        ['type' => 'template', 'file' => $path, 'resolved_by' => $kind],
                        "{$path} already has this fix, most likely from another change to the same template.",
                    );
                }
            }
        }

        return null;
    }

    /** @return array<string, string> path to patched contents */
    private function literalPatches(ChangeRequest $change, string $literal): array
    {
        $patches = [];

        foreach ($this->locator->containing($literal) as $path => $contents) {
            $patched = $this->patcher->patch($contents, $change, $literal);

            if ($patched !== null) {
                $patches[$path] = $patched;
            }
        }

        return $patches;
    }

    /** @param array<string, mixed> $location */
    private function write(ChangeRequest $change, string $file, string $contents, array $location, bool $dryRun): ChangeResult
    {
        $blocked = $this->gitBlocker($file);

        if ($blocked !== null) {
            return $blocked->withId($change->id);
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $change->suggestedValue);
        }

        $original = file_get_contents($this->locator->absolute($file));

        try {
            file_put_contents($this->locator->absolute($file), $contents);
        } catch (Throwable $exception) {
            return ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
        }

        return $this->record($change, $file, $original, $location);
    }

    /**
     * Checked before writing and on a dry run too, so a preview surfaces a dirty
     * template or a missing repo rather than discovering it at apply time.
     */
    private function gitBlocker(string $file): ?ChangeResult
    {
        if (! $this->git->enabled()) {
            return null;
        }

        if (! $this->git->isAvailable()) {
            return ChangeResult::skipped(
                '',
                'git_unavailable',
                'This site is not a git working tree, so a template edit could not be committed or undone.',
            );
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

    /** @param array<string, mixed> $location */
    private function record(ChangeRequest $change, string $file, string $original, array $location): ChangeResult
    {
        if (! $this->git->enabled()) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $change->suggestedValue);
        }

        $commit = $this->git->commit($file, $this->message($change));

        // An edit we could not commit is an untracked change nobody asked for,
        // so it is put back rather than left behind for someone to find.
        if ($commit === null) {
            file_put_contents($this->locator->absolute($file), $original);

            return ChangeResult::failed(
                $change->id,
                'write_failed',
                "The edit to {$file} could not be committed, so it was rolled back.",
            );
        }

        $location['commit'] = $commit;
        $location['pushed'] = $this->git->push();

        return ChangeResult::applied($change->id, $location, $change->currentValue, $change->suggestedValue);
    }

    private function message(ChangeRequest $change): string
    {
        return strtr(config('altimiser.git.message'), [
            ':check' => $change->check,
            ':url' => $change->path(),
        ]);
    }
}
