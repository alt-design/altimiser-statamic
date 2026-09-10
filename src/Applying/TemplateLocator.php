<?php

namespace AltDesign\Altimiser\Applying;

use Statamic\Contracts\Entries\Entry;
use Symfony\Component\Finder\Finder;

class TemplateLocator
{
    private array $extensions = ['.antlers.html', '.blade.php', '.antlers.php', '.html'];

    /** @var array<string, array<int, string>> */
    private array $searched = [];

    public function __construct(private ContentResolver $content) {}

    /**
     * Files containing a literal string. Used by the deterministic patcher,
     * which can only act when the thing it is looking for is written out.
     *
     * @return array<string, string> relative path to contents
     */
    public function containing(string $literal): array
    {
        $contents = [];

        // Which files, not what is in them. The sweep is memoised because a batch
        // asks about the same class attribute dozens of times over, but the
        // contents are read fresh every call: an earlier change in the same batch
        // may have just edited one of these files, and patching a stale copy
        // would write the same fix in twice.
        foreach ($this->searchFor($literal) as $path) {
            $absolute = $this->absolute($path);

            if (is_file($absolute)) {
                $contents[$path] = file_get_contents($absolute);
            }
        }

        return $contents;
    }

    /** @return array<int, string> relative paths */
    private function searchFor(string $literal): array
    {
        if (array_key_exists($literal, $this->searched)) {
            return $this->searched[$literal];
        }

        $roots = $this->roots();

        if ($roots === []) {
            return $this->searched[$literal] = [];
        }

        $found = Finder::create()->files()->in($roots)->name($this->patterns())->contains($literal);

        return $this->searched[$literal] = array_keys($this->readAll($found));
    }

    /**
     * The templates that actually render a URL. Statamic already knows this, so
     * there is no need to search or to have a model guess: the entry names its
     * template, the template names its layout, and the source names its partials.
     *
     * @return array<string, string> relative path to contents
     */
    public function forUrl(string $path, ?string $hint = null): array
    {
        $entry = $this->content->find($path);

        if ($entry === null) {
            return [];
        }

        $files = [];

        foreach ($this->viewNames($entry) as $view) {
            $resolved = $this->resolve($view);

            if ($resolved !== null) {
                $files[$resolved] = file_get_contents($this->absolute($resolved));
            }
        }

        $used = $this->setsUsedBy($entry);

        foreach ($files as $contents) {
            foreach ($this->partialsIn($contents) as $partial) {
                foreach ($this->filesFor($partial, $hint, $used) as $resolved => $body) {
                    $files[$resolved] ??= $body;
                }
            }
        }

        return $this->withinBudget($files);
    }

    /**
     * The sets this entry actually renders.
     *
     * A page builder writes its partial name from a variable, so the directory
     * read below cannot tell which of forty sets are on this page. The entry
     * can: every item in a page builder field carries the handle of the set it
     * is. Without this, a fix for one page is offered partials belonging to
     * other pages entirely, and the model edits a shared file that had nothing
     * to do with the page it was asked about.
     *
     * Bard writes its nodes with a type as well, so this collects names like
     * "paragraph" too. Harmless: they are only ever used to resolve a filename
     * inside the partial's own directory, and there is no sets/paragraph.
     *
     * @return array<int, string>
     */
    private function setsUsedBy(Entry $entry): array
    {
        $types = [];

        $this->typesIn($entry->data()->all(), $types);

        return array_values(array_unique($types));
    }

    /** @param array<int, string> $types */
    private function typesIn(mixed $value, array &$types): void
    {
        if (! is_array($value)) {
            return;
        }

        if (is_string($value['type'] ?? null)) {
            $types[] = $value['type'];
        }

        foreach ($value as $item) {
            $this->typesIn($item, $types);
        }
    }

    /**
     * A partial referenced by a variable, such as {{ partial:sets/{type} }} in a
     * page builder, has no single filename to resolve. That is the normal way a
     * Statamic site renders its content, so refusing to look further would put
     * most of a site's markup out of reach. The directory is read instead, and
     * narrowed to files that mention the kind of tag we are looking for so the
     * context budget is not spent on partials that cannot contain it.
     *
     * @param  array<int, string>  $used  set handles this entry actually renders
     * @return array<string, string>
     */
    private function filesFor(string $partial, ?string $hint, array $used = []): array
    {
        if (! str_contains($partial, '{')) {
            $resolved = $this->resolve($partial) ?? $this->resolve("partials/{$partial}");

            return $resolved === null ? [] : [$resolved => file_get_contents($this->absolute($resolved))];
        }

        $directory = trim(strstr($partial, '{', true) ?: '', '/');

        if ($directory === '') {
            return [];
        }

        $named = $this->namedIn($directory, $used);

        return $named !== [] ? $named : $this->readDirectory($directory, $hint);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    private function namedIn(string $directory, array $names): array
    {
        $files = [];

        foreach ($names as $name) {
            $resolved = $this->resolve("{$directory}/{$name}");

            if ($resolved !== null) {
                $files[$resolved] = file_get_contents($this->absolute($resolved));
            }
        }

        return $files;
    }

    /** @return array<string, string> */
    private function readDirectory(string $directory, ?string $hint): array
    {
        $roots = array_filter(
            array_map(fn (string $root): string => $this->absolute("{$root}/{$directory}"), $this->paths()),
            'is_dir',
        );

        if ($roots === []) {
            return [];
        }

        $found = Finder::create()->files()->in($roots)->name($this->patterns());

        if ($hint !== null) {
            $found->contains($hint);
        }

        return $this->readAll($found);
    }

    /** @return array<int, string> */
    private function viewNames(Entry $entry): array
    {
        return array_values(array_filter([
            $entry->template(),
            $entry->layout(),
        ]));
    }

    /**
     * ponytail: one level of partial nesting. A partial that includes another
     * partial holding the element will be missed, and the edit reports no_match.
     *
     * @return array<int, string>
     */
    private function partialsIn(string $contents): array
    {
        preg_match_all('/\{\{\s*partial:([a-z0-9_\-\/{}]+)/i', $contents, $colonStyle);
        preg_match_all('/\{\{\s*partial\s+src=["\']([^"\']+)["\']/i', $contents, $srcStyle);
        preg_match_all('/@include\(["\']([^"\']+)["\']/', $contents, $bladeStyle);

        $blade = array_map(fn (string $view): string => str_replace('.', '/', $view), $bladeStyle[1]);

        return array_values(array_unique([...$colonStyle[1], ...$srcStyle[1], ...$blade]));
    }

    private function resolve(string $view): ?string
    {
        $view = str_replace('.', '/', trim($view, '/'));

        foreach ($this->paths() as $root) {
            foreach ($this->extensions as $extension) {
                $relative = "{$root}/{$view}{$extension}";

                if (is_file($this->absolute($relative))) {
                    return $relative;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function withinBudget(array $files): array
    {
        $maximumFiles = config('altimiser.ai.max_files', 6);
        $maximumBytes = config('altimiser.ai.max_bytes', 60000);

        $kept = [];
        $bytes = 0;

        foreach (array_slice($files, 0, $maximumFiles, preserve_keys: true) as $path => $contents) {
            $bytes += strlen($contents);

            if ($bytes > $maximumBytes) {
                break;
            }

            $kept[$path] = $contents;
        }

        return $kept;
    }

    /** @return array<string, string> */
    private function readAll(Finder $files): array
    {
        $contents = [];

        foreach ($files as $file) {
            $contents[$this->relative($file->getPathname())] = $file->getContents();
        }

        return $contents;
    }

    /** @return array<int, string> */
    private function paths(): array
    {
        return config('altimiser.template_paths', []);
    }

    /** @return array<int, string> */
    private function roots(): array
    {
        return array_values(array_filter(array_map($this->absolute(...), $this->paths()), 'is_dir'));
    }

    /** @return array<int, string> */
    private function patterns(): array
    {
        return array_map(fn (string $extension): string => "*{$extension}", $this->extensions);
    }

    public function absolute(string $relative): string
    {
        return base_path($relative);
    }

    private function relative(string $absolute): string
    {
        return str_replace(base_path().'/', '', $absolute);
    }
}
