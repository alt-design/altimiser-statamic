<?php

namespace AltDesign\Altimiser\Applying;

use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\AssetContainer;
use Throwable;

class AssetApplier implements Applier
{
    use CommitsSavedFiles;

    public function __construct(private GitRepository $git) {}

    public function supports(string $check): bool
    {
        return in_array($check, config('altimiser.asset_checks', []), true);
    }

    /**
     * Alt text belongs to the image, not to the page it happens to appear on. An
     * asset used on nine pages has one description, and writing it nine times
     * into nine entries would be nine chances to disagree with itself.
     */
    public function apply(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        if (blank($change->suggestedValue)) {
            return ChangeResult::skipped(
                $change->id,
                'no_suggestion',
                'There is no replacement value for this change.',
            );
        }

        $source = $change->target['match'] ?? '';
        $asset = $this->locate($source);

        if ($asset === null) {
            return ChangeResult::skipped($change->id, 'no_match', "No asset in this site matches {$source}.");
        }

        $field = $this->field($asset);

        if ($field === null) {
            $candidates = implode(' or ', config('altimiser.fields.image', []));

            return ChangeResult::skipped(
                $change->id,
                'no_field',
                "The asset blueprint has no {$candidates} field, so there is nowhere to put this that the "
                .'site would render.',
            );
        }

        $current = (string) $asset->get($field);
        $location = ['type' => 'asset', 'asset' => $asset->id(), 'field' => $field];

        if ($current === $change->suggestedValue) {
            return ChangeResult::alreadyApplied($change->id, $location, "{$field} already says this.");
        }

        // Alt text is stored in the asset's meta file, which is a tracked file
        // like any other, so it needs committing for the same reason a template
        // edit does.
        $file = $this->metaFile($asset);
        $blocked = $this->gitBlocker($file);

        if ($blocked !== null) {
            return $blocked->withId($change->id);
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $current, $change->suggestedValue);
        }

        $headBefore = $this->headBefore($file);

        $asset->set($field, $change->suggestedValue)->save();

        $committed = $this->commitSaved($file, $headBefore, $change);

        if ($committed === null) {
            $asset->set($field, $current)->save();

            return ChangeResult::failed(
                $change->id,
                'write_failed',
                "The edit to {$file} could not be committed, so it was put back.",
            );
        }

        return ChangeResult::applied($change->id, [...$location, ...$committed], $current, $change->suggestedValue);
    }

    /** Null where the container is on a remote disk, which git cannot speak for. */
    private function metaFile(Asset $asset): ?string
    {
        try {
            $path = $asset->container()->disk()->filesystem()->path($asset->metaPath());
        } catch (Throwable) {
            return null;
        }

        if (! is_string($path) || ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR)) {
            return null;
        }

        return substr($path, strlen(base_path()) + 1);
    }

    /**
     * The URL in the markup is usually a Glide transform rather than the asset
     * itself, so the original path is read back out of it before asking Statamic.
     */
    private function locate(string $source): ?Asset
    {
        if (preg_match('#/cached-img/containers/([^/]+)/(.+)/[0-9a-f]{32}/[^/]+$#', $source, $matches) === 1) {
            return AssetContainer::find($matches[1])?->asset($matches[2]);
        }

        return Assets::findByUrl(parse_url($source, PHP_URL_PATH) ?: $source);
    }

    /**
     * Writing to a field the blueprint does not have would save without error and
     * render nowhere, which is the worst of both: reported as done, invisible on
     * the page.
     */
    private function field(Asset $asset): ?string
    {
        $fields = $asset->blueprint()?->fields()->all()->keys()->all() ?? [];

        foreach (config('altimiser.fields.image', []) as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
