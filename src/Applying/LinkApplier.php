<?php

namespace AltDesign\Altimiser\Applying;

use AltDesign\Altimiser\Linking\LinkEdit;
use AltDesign\Altimiser\Linking\MentionLinker;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Throwable;

/**
 * Links a page that already names another page to it, using the words that are
 * already there.
 *
 * The change carries the words in target.anchor and the destination in
 * target.href. A revert arrives as the same change with its values swapped, so
 * a suggested value that is the anchor itself means take the link off again.
 */
class LinkApplier implements Applier
{
    use CommitsSavedFiles;

    public function __construct(
        private ContentResolver $content,
        private MentionLinker $linker,
        private GitRepository $git,
    ) {}

    public function supports(string $check): bool
    {
        return in_array($check, config('altimiser.link_checks', []), true);
    }

    public function apply(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        $anchor = trim((string) ($change->target['anchor'] ?? ''));
        $href = trim((string) ($change->target['href'] ?? ''));

        if ($anchor === '' || $href === '') {
            return ChangeResult::skipped(
                $change->id,
                'invalid_change',
                'A link change needs the words to link and the page they link to. Scan the site again and send it once more.',
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

        $removing = $change->suggestedValue === $anchor;
        $data = $entry->data()->all();
        $types = $this->types($entry->blueprint()?->fields()->all() ?? []);

        $edit = $removing
            ? $this->linker->unlink($data, $types, $anchor, $href)
            : $this->linker->link($data, $types, $anchor, $href);

        $location = [...$this->describe($entry), 'field' => $edit->path];

        if (! $edit->changed()) {
            return $this->unchanged($edit, $change, $location, $anchor, $href, $removing);
        }

        $file = $this->relativePath($entry);
        $blocked = $this->gitBlocker($file);

        if ($blocked !== null) {
            return $blocked->withId($change->id);
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $change->suggestedValue);
        }

        $headBefore = $this->headBefore($file);

        try {
            $entry->set($edit->field, $edit->value)->save();
        } catch (Throwable $exception) {
            return ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
        }

        $committed = $this->commitSaved($file, $headBefore, $change);

        if ($committed === null) {
            $entry->set($edit->field, $data[$edit->field])->save();

            return ChangeResult::failed(
                $change->id,
                'write_failed',
                "The edit to {$file} could not be committed, so it was put back.",
            );
        }

        return ChangeResult::applied($change->id, [...$location, ...$committed], $change->currentValue, $change->suggestedValue);
    }

    /** @param array<string, mixed> $location */
    private function unchanged(LinkEdit $edit, ChangeRequest $change, array $location, string $anchor, string $href, bool $removing): ChangeResult
    {
        if ($removing) {
            return ChangeResult::alreadyApplied($change->id, $location, "There is no link to {$href} on \"{$anchor}\" in this entry, so there was nothing to take off.");
        }

        return match ($edit->outcome) {
            'already' => ChangeResult::alreadyApplied($change->id, $location, "The first mention of \"{$anchor}\" already links to {$href}."),
            'inside_link' => ChangeResult::skipped(
                $change->id,
                'already_linked',
                "The first mention of \"{$anchor}\" already links somewhere else. Somebody chose that link, so it was left alone.",
            ),
            default => ChangeResult::skipped(
                $change->id,
                'mention_not_found',
                "\"{$anchor}\" is not in this entry's content as written, so there is nothing to link. It may come from a template, a global or another entry shown on the page.",
            ),
        };
    }

    /**
     * Each field's type by handle, the fields inside page builder sets, Bard
     * sets and grids included, so the linker can tell prose from a label at any
     * depth.
     *
     * @param  iterable<string, Field>  $fields
     * @return array<string, string>
     */
    private function types(iterable $fields): array
    {
        $types = [];

        foreach ($fields as $handle => $field) {
            $types[$handle] ??= $field->type();
            $types += $this->types($this->nested($field));
        }

        return $types;
    }

    /** @return iterable<string, Field> */
    private function nested(Field $field): iterable
    {
        return match ($field->type()) {
            'replicator', 'bard' => collect($field->fieldtype()->flattenedSetsConfig())
                ->flatMap(fn (array $set) => (new Fields($set['fields'] ?? []))->all()),
            'grid' => (new Fields($field->config()['fields'] ?? []))->all(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function describe(object $record): array
    {
        if (method_exists($record, 'taxonomyHandle')) {
            return ['type' => 'term', 'id' => $record->id(), 'taxonomy' => $record->taxonomyHandle()];
        }

        return ['type' => 'entry', 'id' => $record->id(), 'collection' => $record->collectionHandle()];
    }

    private function relativePath(object $record): ?string
    {
        $path = method_exists($record, 'path') ? $record->path() : null;

        if (! is_string($path) || ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR)) {
            return null;
        }

        return substr($path, strlen(base_path()) + 1);
    }
}
