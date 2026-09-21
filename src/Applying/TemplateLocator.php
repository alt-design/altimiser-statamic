<?php

namespace AltDesign\Altimiser\Applying;

use Symfony\Component\Finder\Finder;

class TemplateLocator
{
    private array $extensions = ['.antlers.html', '.blade.php', '.antlers.php', '.html'];

    /** @var array<string, array<int, string>> */
    private array $searched = [];

    /**
     * Files containing a literal string, which is the only question this asks.
     *
     * The patcher can only act on an element written out in a template, so
     * finding it is a text search. Working out which partial renders an element
     * built from variables is the agent's job, against the whole repository.
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
