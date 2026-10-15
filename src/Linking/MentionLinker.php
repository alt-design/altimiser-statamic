<?php

namespace AltDesign\Altimiser\Linking;

/**
 * Turns the first mention of a phrase in an entry's content into a link, or
 * takes that link off again.
 *
 * Only words already on the page are linked. Nothing is added or reworded, so
 * the edit needs nobody to rewrite the sentence around it, and taking the link
 * off restores the content as it was.
 *
 * The first mention is the one a reader meets first, in the order the entry
 * stores its fields. Where that mention is already a link to somewhere else it
 * is left alone rather than passed over: somebody chose that link, and linking
 * a later mention instead would put two destinations on one name.
 *
 * Kept free of Statamic so it can be tested with plain arrays. It is told each
 * field's type, because a string in a Markdown field is prose and the same
 * string in a text field is a label.
 */
class MentionLinker
{
    /** @var array<int, string> */
    private array $prose = ['markdown', 'textarea', 'bard'];

    /** @var array<string, string> */
    private array $types = [];

    private string $mention = '';

    private string $href = '';

    private bool $removing = false;

    /**
     * @param  array<string, mixed>  $data  the entry's own data
     * @param  array<string, string>  $types  field handle to fieldtype, the fields inside sets and grids included
     */
    public function link(array $data, array $types, string $anchor, string $href): LinkEdit
    {
        return $this->edit($data, $types, $anchor, $href, removing: false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $types
     */
    public function unlink(array $data, array $types, string $anchor, string $href): LinkEdit
    {
        return $this->edit($data, $types, $anchor, $href, removing: true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $types
     */
    private function edit(array $data, array $types, string $anchor, string $href, bool $removing): LinkEdit
    {
        $this->types = $types;
        $this->mention = $this->expression($anchor);
        $this->href = $href;
        $this->removing = $removing;

        foreach ($data as $handle => $value) {
            $handle = (string) $handle;

            if ($this->isReserved($handle)) {
                continue;
            }

            $found = $this->visit($value, $handle, $handle);

            if ($found !== null) {
                return new LinkEdit($found[0], $handle, $value, $found[1]);
            }
        }

        return new LinkEdit('not_found');
    }

    /**
     * Whole words only, ignoring case and how the spaces between them were
     * typed, so "loft conversion" never links half of "loft conversions".
     */
    private function expression(string $anchor): string
    {
        $words = preg_split('/\s+/u', trim($anchor)) ?: [];
        $quoted = implode('[\s\x{00A0}]+', array_map(fn (string $word): string => preg_quote($word, '/'), $words));

        return '(?<![\p{L}\p{N}])'.$quoted.'(?![\p{L}\p{N}])';
    }

    /** @return ?array{0: string, 1: string} the outcome and where, once the first mention is found */
    private function visit(mixed &$value, string $key, string $path): ?array
    {
        $type = $this->types[$key] ?? null;

        if ($type === 'bard' && is_array($value)) {
            return $this->inNodes($value, $path);
        }

        if (is_string($value)) {
            $found = in_array($type, $this->prose, true) ? $this->inString($value, markdown: $type === 'markdown') : null;

            return $found === null ? null : [$found, $path];
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (array_keys($value) as $childKey) {
            if ($this->isReserved((string) $childKey)) {
                continue;
            }

            $found = $this->visit($value[$childKey], (string) $childKey, "{$path}.{$childKey}");

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Bard's nodes, depth first, which is reading order.
     *
     * @param  array<int, mixed>  $nodes
     * @return ?array{0: string, 1: string}
     */
    private function inNodes(array &$nodes, string $path): ?array
    {
        for ($index = 0; $index < count($nodes); $index++) {
            $node = $nodes[$index] ?? null;

            if (! is_array($node)) {
                continue;
            }

            $found = match (true) {
                ($node['type'] ?? null) === 'text' => $this->inTextNode($nodes, $index),
                ($node['type'] ?? null) === 'set' && is_array($node['attrs']['values'] ?? null) => $this->visit($nodes[$index]['attrs']['values'], '', "{$path}.{$index}")[0] ?? null,
                is_array($node['content'] ?? null) => $this->inNodes($nodes[$index]['content'], $path)[0] ?? null,
                default => null,
            };

            if ($found !== null) {
                return [$found, $path];
            }
        }

        return null;
    }

    /** @param array<int, mixed> $nodes */
    private function inTextNode(array &$nodes, int $index): ?string
    {
        $node = $nodes[$index];
        $text = (string) ($node['text'] ?? '');
        $link = $this->linkMark($node);

        if ($this->removing) {
            if ($link === null || ! $this->samePlace((string) ($link['attrs']['href'] ?? '')) || ! $this->isWholly($text)) {
                return null;
            }

            $nodes[$index] = $this->withMarks($node, $text, $this->withoutLinks($node['marks'] ?? []));
            $this->mergeAround($nodes, $index);

            return 'changed';
        }

        if (preg_match("/{$this->mention}/iu", $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        if ($link !== null) {
            return $this->samePlace((string) ($link['attrs']['href'] ?? '')) ? 'already' : 'inside_link';
        }

        [$mention, $offset] = $match[0];
        $marks = $node['marks'] ?? [];
        $before = substr($text, 0, $offset);
        $after = substr($text, $offset + strlen($mention));

        array_splice($nodes, $index, 1, array_values(array_filter([
            $before === '' ? null : $this->withMarks($node, $before, $marks),
            $this->withMarks($node, $mention, [...$marks, ['type' => 'link', 'attrs' => ['href' => $this->href, 'rel' => null, 'target' => null, 'title' => null]]]),
            $after === '' ? null : $this->withMarks($node, $after, $marks),
        ])));

        return 'changed';
    }

    /**
     * Markdown, a textarea, or Bard saved as HTML.
     *
     * A mention inside a tag, an image or the address of a link is not prose and
     * is passed over. A mention inside a link's text is the first mention, and
     * is answered for rather than passed over.
     */
    private function inString(string &$value, bool $markdown): ?string
    {
        $links = $this->links($value);

        if ($this->removing) {
            foreach ($links as $link) {
                if ($this->samePlace($link['href']) && $this->isWholly($link['text'])) {
                    $value = substr_replace($value, $link['text'], $link['start'], $link['end'] - $link['start']);

                    return 'changed';
                }
            }

            return null;
        }

        preg_match_all("/{$this->mention}/iu", $value, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as [$mention, $offset]) {
            $end = $offset + strlen($mention);

            foreach ($links as $link) {
                if ($offset >= $link['start'] && $end <= $link['end']) {
                    return $this->samePlace($link['href']) ? 'already' : 'inside_link';
                }
            }

            if ($this->insideMarkup($value, $offset, $end)) {
                continue;
            }

            $value = substr_replace($value, $markdown ? "[{$mention}]({$this->href})" : "<a href=\"{$this->href}\">{$mention}</a>", $offset, strlen($mention));

            return 'changed';
        }

        return null;
    }

    /**
     * Every link in a string, Markdown or HTML, with where it starts and ends.
     *
     * @return array<int, array{start: int, end: int, href: string, text: string}>
     */
    private function links(string $value): array
    {
        $links = [];

        preg_match_all('/(?<!!)\[([^\]]+)\]\(\s*([^)\s]*)[^)]*\)/u', $value, $markdown, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/isu', $value, $html, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($markdown as $match) {
            $links[] = ['start' => $match[0][1], 'end' => $match[0][1] + strlen($match[0][0]), 'href' => $match[2][0], 'text' => $match[1][0]];
        }

        foreach ($html as $match) {
            preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $match[1][0], $href);

            $links[] = ['start' => $match[0][1], 'end' => $match[0][1] + strlen($match[0][0]), 'href' => $href[2] ?? '', 'text' => $match[2][0]];
        }

        usort($links, fn (array $one, array $other): int => $one['start'] <=> $other['start']);

        return $links;
    }

    private function insideMarkup(string $value, int $start, int $end): bool
    {
        preg_match_all('/<[^>]*>|!\[[^\]]*\]\([^)]*\)/u', $value, $spans, PREG_OFFSET_CAPTURE);

        foreach ($spans[0] as [$span, $offset]) {
            if ($start < $offset + strlen($span) && $end > $offset) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return ?array<string, mixed>
     */
    private function linkMark(array $node): ?array
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (($mark['type'] ?? null) === 'link') {
                return $mark;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<int, array<string, mixed>>
     */
    private function withoutLinks(array $marks): array
    {
        return array_values(array_filter($marks, fn (array $mark): bool => ($mark['type'] ?? null) !== 'link'));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private function withMarks(array $node, string $text, array $marks): array
    {
        $node['text'] = $text;
        $node['marks'] = $marks;

        if ($marks === []) {
            unset($node['marks']);
        }

        return $node;
    }

    /**
     * Joins the text either side of a removed link back onto it, so the
     * paragraph is the one it was before the link went in.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function mergeAround(array &$nodes, int $index): void
    {
        foreach ([$index, $index - 1] as $left) {
            if ($this->mergeable($nodes[$left] ?? null, $nodes[$left + 1] ?? null)) {
                $nodes[$left]['text'] .= $nodes[$left + 1]['text'];
                array_splice($nodes, $left + 1, 1);
            }
        }
    }

    private function mergeable(mixed $one, mixed $other): bool
    {
        return is_array($one) && is_array($other)
            && ($one['type'] ?? null) === 'text' && ($other['type'] ?? null) === 'text'
            && ($one['marks'] ?? []) == ($other['marks'] ?? []);
    }

    private function isWholly(string $text): bool
    {
        return preg_match("/^\\s*{$this->mention}\\s*\$/iu", $text) === 1;
    }

    /**
     * Whether a link already goes where this one would. Compared as paths, so a
     * full address on this site and a root relative one are the same place. A
     * Statamic reference to an entry is never the same place, since it cannot
     * be resolved here.
     */
    private function samePlace(string $href): bool
    {
        return $this->pathOf($href) === $this->pathOf($this->href);
    }

    private function pathOf(string $href): string
    {
        if (str_contains($href, '://') && preg_match('#^https?://#i', $href) !== 1) {
            return $href;
        }

        return '/'.trim((string) parse_url($href, PHP_URL_PATH), '/');
    }

    /**
     * Never the entry's name, its address or its SEO fields, which are labels
     * shown elsewhere rather than prose a reader follows.
     */
    private function isReserved(string $handle): bool
    {
        return in_array($handle, ['title', 'slug'], true) || str_starts_with($handle, 'alt_seo_');
    }
}
