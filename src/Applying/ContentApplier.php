<?php

namespace AltDesign\Altimiser\Applying;

use Throwable;

class ContentApplier implements Applier
{
    use CommitsSavedFiles;

    public function __construct(
        private FieldResolver $resolver,
        private ValueResolver $values,
        private ContentResolver $content,
        private GitRepository $git,
    ) {}

    public function supports(string $check): bool
    {
        return in_array($check, config('altimiser.content_checks', []), true);
    }

    public function apply(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        if (blank($change->suggestedValue)) {
            return ChangeResult::skipped(
                $change->id,
                'no_suggestion',
                'There is no replacement value for this check, and this receiver cannot work one out for itself.',
            );
        }

        $entry = $this->content->find($change->path()) ?? $this->content->findTerm($change->path());

        if ($entry === null) {
            return ChangeResult::skipped(
                $change->id,
                'not_found',
                "Nothing editable answers to {$change->path()}. It may be a global, a form page, or a hard-coded route.",
            );
        }

        $data = $entry->data()->all();
        $match = $this->resolver->resolve($change, $data, $this->blueprintFields($entry), $this->variables($entry));

        if (! $match->matched()) {
            return ChangeResult::skipped($change->id, $match->reason, $match->message);
        }

        $current = $entry->get($match->field);

        if (! $this->safeToWrite($match, $current, $change->currentValue)) {
            return ChangeResult::skipped(
                $change->id,
                'value_changed',
                "The {$match->field} field has changed since the scan, so it was left alone.",
            );
        }

        $location = [
            ...$this->describe($entry),
            'field' => $match->field,
            'inherited' => $match->inherited,
            'flattened_template' => $match->flattensTemplate,
        ];

        $file = $this->relativePath($entry);
        $blocked = $this->gitBlocker($file);

        if ($blocked !== null) {
            return $blocked->withId($change->id);
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $this->asString($current), $change->suggestedValue);
        }

        $headBefore = $this->headBefore($file);

        try {
            $entry->set($match->field, $change->suggestedValue)->save();
        } catch (Throwable $exception) {
            return ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
        }

        $committed = $this->commitSaved($file, $headBefore, $change);

        // A content file written but not committed is undone by the next deploy,
        // silently, which is worse than not having written it.
        if ($committed === null) {
            $this->restore($entry, $match, $current);

            return ChangeResult::failed(
                $change->id,
                'write_failed',
                "The edit to {$file} could not be committed, so it was put back.",
            );
        }

        return ChangeResult::applied(
            $change->id,
            [...$location, ...$committed],
            $this->asString($current),
            $change->suggestedValue,
        );
    }

    /**
     * The path git needs, or null where the entry does not live in a file at all,
     * which is the case on a database-driven site.
     */
    private function relativePath(object $record): ?string
    {
        $path = method_exists($record, 'path') ? $record->path() : null;

        if (! is_string($path) || ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR)) {
            return null;
        }

        return substr($path, strlen(base_path()) + 1);
    }

    private function restore(object $entry, FieldMatch $match, mixed $current): void
    {
        // An inherited value had no field of its own before we wrote one, so
        // putting an empty string back would leave a per-page override behind.
        $match->inherited || blank($current)
            ? $entry->remove($match->field)->save()
            : $entry->set($match->field, $current)->save();
    }

    /**
     * A scan is a snapshot and an editor may have changed the value since, so we
     * only write when what is there is what we saw.
     *
     * The exception is an inherited value: the field is legitimately empty
     * because the rendered text came from a site-wide default, and writing
     * creates the per-page override. Treating that as a conflict would block the
     * single most common Alt SEO case.
     */
    private function safeToWrite(FieldMatch $match, mixed $current, ?string $expected): bool
    {
        if ($match->inherited) {
            return blank($current);
        }

        if (blank($expected)) {
            return blank($current);
        }

        if (! is_scalar($current)) {
            return false;
        }

        return $match->flattensTemplate || $this->values->equivalent((string) $current, $expected);
    }

    /** @return array<string, mixed> */
    private function describe(object $record): array
    {
        if (method_exists($record, 'taxonomyHandle')) {
            return ['type' => 'term', 'id' => $record->id(), 'taxonomy' => $record->taxonomyHandle()];
        }

        return ['type' => 'entry', 'id' => $record->id(), 'collection' => $record->collectionHandle()];
    }

    /**
     * Alt SEO substitutes these into stored values at render time.
     *
     * Every one of these is a field handle a site is free to use for something
     * else. Adams and Moore's description is a set of blocks rather than a line
     * of text, and casting that to a string failed the change with an array
     * conversion error that had nothing to do with the change, so anything that
     * is not already a scalar substitutes as nothing.
     *
     * @return array<string, string>
     */
    private function variables(object $entry): array
    {
        return [
            'title' => $this->asString($entry->get('title')) ?? '',
            'site_name' => $this->asString(config('app.name')) ?? '',
            'description' => $this->asString($entry->get('description')) ?? '',
        ];
    }

    /** @return array<int, string> */
    private function blueprintFields(object $entry): array
    {
        return $entry->blueprint()?->fields()->all()->keys()->all() ?? [];
    }

    private function asString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
