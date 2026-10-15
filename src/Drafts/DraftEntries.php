<?php

namespace AltDesign\Altimiser\Drafts;

use AltDesign\Altimiser\Applying\ContentResolver;
use AltDesign\Altimiser\Applying\GitRepository;
use Illuminate\Support\Str;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Fieldtypes\RowId;

/**
 * A content draft from Altimiser, saved as an unpublished entry for an editor
 * to finish.
 *
 * Unpublished is the whole point. A draft is written by a model from the
 * business's answers and carries its gaps marked in the text, so it is only
 * ever somewhere for an editor to start, and nothing reaches the site until
 * somebody presses publish.
 *
 * The idea it came from is recorded on the entry, so sending the same idea
 * again rewrites its draft rather than adding a second one, but only while the
 * draft is still unpublished. Once somebody has published it, it is theirs.
 */
class DraftEntries
{
    public function __construct(
        private ContentResolver $resolver,
        private GitRepository $git,
        private BodyField $body,
    ) {}

    /**
     * @param  array{reference: string, title: string, meta_description?: ?string, body: string, collection?: ?string, expands?: ?string}  $draft
     * @return array<string, mixed>
     */
    public function store(array $draft): array
    {
        $beside = filled($draft['expands'] ?? null) ? $this->resolver->find($this->path((string) $draft['expands'])) : null;

        if (filled($draft['expands'] ?? null) && $beside === null) {
            return $this->refused('page_not_found', "No entry lives at {$this->path((string) $draft['expands'])}, so there is nothing to put the draft beside.");
        }

        $collection = $beside?->collection() ?? Collections::findByHandle((string) ($draft['collection'] ?? ''));

        if ($collection === null) {
            return $this->refused('collection_not_found', 'There is no collection called "'.($draft['collection'] ?? '').'" on this site.');
        }

        $blueprint = $beside?->blueprint() ?? $collection->entryBlueprint();
        $entry = Entries::query()->where('collection', $collection->handle())->where('altimiser_idea', $draft['reference'])->first();

        if ($entry?->published()) {
            return $this->refused('already_published', "The draft for this idea was published as \"{$entry->get('title')}\", so it is left alone.");
        }

        $file = $entry === null ? null : $this->relativePath($entry);

        if ($file !== null && $this->git->enabled() && ! $this->git->isClean($file)) {
            return $this->refused('git_dirty', "{$file} has changes nobody has committed, which are somebody's work, so the draft was not rewritten over them.");
        }

        $body = $this->body->place(
            $this->describe($blueprint),
            $draft['body'],
            fn (string $html, string $field, ?string $set): array => $this->bard($blueprint, $field, $set, $html),
            app(RowId::class)->handle(),
        );

        if ($body === null) {
            return $this->refused('no_body_field', "The {$blueprint->title()} blueprint has no Bard, Markdown or page builder text field to write the draft into.");
        }

        $created = $entry === null;
        $entry ??= Entries::make()
            ->collection($collection->handle())
            ->blueprint($blueprint->handle())
            ->locale(Site::default()->handle())
            ->slug($this->slug($collection->handle(), $beside ? $beside->slug().'-draft' : Str::slug($draft['title'])));

        if ($created && $collection->dated()) {
            $entry->date(now()->startOfDay());
        }

        $entry->published(false)->set('title', $draft['title'])->set('altimiser_idea', $draft['reference']);

        foreach ($body as $field => $value) {
            $entry->set($field, $value);
        }

        $description = $this->descriptionField($blueprint);

        if ($description !== null && filled($draft['meta_description'] ?? null)) {
            $entry->set($description, $draft['meta_description']);
        }

        $entry->save();

        return [
            'status' => $created ? 'created' : 'updated',
            'id' => $entry->id(),
            'collection' => $collection->handle(),
            'edit_url' => $entry->editUrl(),
            ...$this->commit($entry, $draft['title']),
        ];
    }

    /**
     * Each field's type, and for a page builder the fields of every set, in the
     * plain shape BodyField chooses from.
     *
     * @return array<string, array{type: string, sets?: array<string, array<string, string>>}>
     */
    private function describe(Blueprint $blueprint): array
    {
        return $blueprint->fields()->all()
            ->map(fn (Field $field): array => array_filter([
                'type' => $field->type(),
                'sets' => $field->type() === 'replicator' ? collect($field->fieldtype()->flattenedSetsConfig())
                    ->map(fn (array $set): array => (new Fields($set['fields'] ?? []))->all()->map(fn (Field $inner): string => $inner->type())->all())
                    ->all() : null,
            ]))
            ->all();
    }

    /**
     * HTML as the nodes a Bard field stores, converted by that field's own
     * configuration so the result is what an editor typing it would produce.
     *
     * @return array<int, mixed>
     */
    private function bard(Blueprint $blueprint, string $handle, ?string $set, string $html): array
    {
        $field = $set === null ? $blueprint->field($handle) : $this->fieldInSet($blueprint, $set, $handle);

        return (new Augmentor($field->fieldtype()))->renderHtmlToProsemirror($html)['content'] ?? [];
    }

    private function fieldInSet(Blueprint $blueprint, string $set, string $handle): ?Field
    {
        foreach ($blueprint->fields()->all() as $field) {
            $sets = $field->type() === 'replicator' ? $field->fieldtype()->flattenedSetsConfig() : [];

            if (isset($sets[$set])) {
                return (new Fields($sets[$set]['fields'] ?? []))->get($handle);
            }
        }

        return null;
    }

    /** The first of the configured meta description fields that this blueprint has. */
    private function descriptionField(Blueprint $blueprint): ?string
    {
        foreach ((array) config('altimiser.fields.meta_description', []) as $handle) {
            if ($blueprint->hasField($handle)) {
                return $handle;
            }
        }

        return null;
    }

    private function slug(string $collection, string $wanted): string
    {
        $slug = $wanted;

        for ($suffix = 2; Entries::query()->where('collection', $collection)->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$wanted}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Commits the entry's file where nothing else has, so a site that deploys
     * from its repository keeps the draft. A database-driven site has no file,
     * and a site that is not a git checkout has nothing to commit it to; an
     * unpublished draft is harmless on either.
     *
     * @return array<string, mixed>
     */
    private function commit(object $entry, string $title): array
    {
        $file = $this->relativePath($entry);

        if ($file === null || ! $this->git->enabled() || ! $this->git->isAvailable() || $this->git->isClean($file)) {
            return [];
        }

        $commit = $this->git->commit($file, '[BOT] Altimiser: draft of '.Str::limit($title, 80));

        return $commit === null
            ? ['commit' => null, 'message' => "{$file} was saved but could not be committed. It needs committing by hand."]
            : ['file' => $file, 'commit' => $commit, 'pushed' => $this->git->push()];
    }

    private function relativePath(object $entry): ?string
    {
        $path = method_exists($entry, 'path') ? $entry->path() : null;

        if (! is_string($path) || ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR)) {
            return null;
        }

        return substr($path, strlen(base_path()) + 1);
    }

    private function path(string $url): string
    {
        return '/'.trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
    }

    /** @return array{status: string, reason: string, message: string} */
    private function refused(string $reason, string $message): array
    {
        return ['status' => 'refused', 'reason' => $reason, 'message' => $message];
    }
}
