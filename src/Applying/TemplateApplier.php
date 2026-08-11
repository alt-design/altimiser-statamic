<?php

namespace AltDesign\Altimiser\Applying;

use Throwable;

class TemplateApplier implements Applier, PreparesBatch
{
    /** @var array<string, TemplateEdit> resolved ahead of time, keyed by change id */
    private array $resolved = [];

    public function __construct(
        private TemplatePatcher $patcher,
        private TemplateLocator $locator,
        private AiTemplateEditor $editor,
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
     * Works out which changes need the model, then resolves them all at once.
     * Doing it here rather than inside apply() is what turns a batch of ten from
     * three minutes of sequential calls into about twenty seconds.
     *
     * @param  array<int, ChangeRequest>  $changes
     */
    public function prepare(array $changes, bool $dryRun): void
    {
        if (! $this->editor->isConfigured()) {
            return;
        }

        $requests = [];
        $sharing = [];

        foreach ($changes as $change) {
            if (! $this->needsModel($change)) {
                continue;
            }

            $candidates = $this->candidatesFor($change);

            if ($candidates === []) {
                continue;
            }

            // One question per distinct question. An image in a loop appears on
            // forty pages and arrives as forty changes, every one of them asking
            // the same thing of the same templates and getting the same answer,
            // of which thirty-nine are then discarded as already applied. On a
            // real queue that is 781 changes over 218 actual questions.
            $question = $this->fingerprint($change, $candidates);

            $sharing[$question][] = $change->id;
            $requests[$question] ??= ['change' => $change, 'candidates' => $candidates];
        }

        if ($requests === []) {
            return;
        }

        foreach ($this->editor->editMany($requests) as $question => $edit) {
            foreach ($sharing[$question] as $id) {
                $this->resolved[$id] = $edit;
            }
        }
    }

    /**
     * What makes two changes the same question.
     *
     * Everything the prompt is built from, so two changes share an answer only
     * when they would have produced identical requests. The value is in here
     * because dimensions differ per asset, and the file list is because the same
     * element on two URLs can be rendered by different templates.
     *
     * @param  array<string, string>  $candidates
     */
    private function fingerprint(ChangeRequest $change, array $candidates): string
    {
        return md5(serialize([
            $change->check,
            $change->target['selector'] ?? null,
            $change->target['attribute'] ?? null,
            $change->target['context'] ?? null,
            $change->matchLiteral(),
            $change->suggestedValue,
            array_keys($candidates),
        ]));
    }

    /**
     * The deterministic patcher gets first refusal, so only a change it cannot
     * place is worth spending a model call on.
     */
    private function needsModel(ChangeRequest $change): bool
    {
        $change = $this->supplyMissingValue($change);

        if ($this->locate($change) !== null) {
            return false;
        }

        return $this->alreadySatisfied($change) === null;
    }

    /**
     * The deterministic patcher runs first because when it can act it is exact,
     * free and repeatable. It only sees elements written out as literals, which
     * on a content-driven site is the minority, so the model handles the rest.
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

        // Two templates writing out the same URL is a real ambiguity rather than
        // something a model should resolve on our behalf. Only the URL counts
        // here: a class shared by several templates is ordinary, and that is
        // exactly the case the model is good at.
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

        return $this->patchByModel($change, $dryRun);
    }

    /**
     * A value we can work out for ourselves. Dimensions read off a real file are
     * exact, so they never need a model, and the change arrives without one
     * precisely because only this side can supply it.
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
     * Finds the one template this change belongs to, without asking a model.
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

    /**
     * What the model gets to read. Where a literal narrowed it to a handful of
     * files, those are the ones: sending the whole render path of a page when we
     * already know which partial writes the element is both slower and a worse
     * prompt, because the answer is buried in four files it does not need.
     *
     * @return array<string, string>
     */
    private function candidatesFor(ChangeRequest $change): array
    {
        $narrowed = [];

        foreach ($change->locatorLiterals() as $literal) {
            $narrowed = [...$narrowed, ...$this->locator->containing($literal)];
        }

        return $narrowed !== [] ? $narrowed : $this->locator->forUrl($change->path(), $this->tagHint($change));
    }

    private function patchByModel(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        if (! $this->editor->isConfigured()) {
            return ChangeResult::skipped(
                $change->id,
                'no_match',
                'No template contains this element as a literal, and AI editing is not enabled on this site.',
            );
        }

        $candidates = $this->candidatesFor($change);
        $edit = $this->resolved[$change->id] ?? $this->editor->edit($change, $candidates);

        if (! $edit->usable()) {
            return ChangeResult::skipped($change->id, 'no_match', $edit->rejection);
        }

        if (! array_key_exists($edit->file, $candidates)) {
            return ChangeResult::skipped($change->id, 'no_match', 'The template the edit refers to could not be re-read.');
        }

        $location = [
            'type' => 'template',
            'file' => $edit->file,
            'resolved_by' => 'model',
            'reasoning' => $edit->reasoning,
            'edit' => ['lines' => "{$edit->startLine}-{$edit->endLine}", 'from' => $edit->replaced, 'to' => $edit->replacement],
        ];

        return $this->write($change, $edit->file, $edit->applyTo($candidates[$edit->file]), $location, $dryRun);
    }

    /** The element type we are hunting, used to narrow a directory read. */
    private function tagHint(ChangeRequest $change): ?string
    {
        $selector = $change->target['selector'] ?? '';

        return preg_match('/^([a-z]+)/i', $selector, $matches) === 1 ? "<{$matches[1]}" : null;
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
