<?php

use AltDesign\Altimiser\Linking\MentionLinker;

function text(string $text, array $marks = []): array
{
    return array_filter(['type' => 'text', 'text' => $text, 'marks' => $marks ?: null]);
}

function paragraph(array ...$nodes): array
{
    return ['type' => 'paragraph', 'content' => $nodes];
}

function linkMark(string $href): array
{
    return ['type' => 'link', 'attrs' => ['href' => $href, 'rel' => null, 'target' => null, 'title' => null]];
}

function linker(): MentionLinker
{
    return new MentionLinker;
}

it('links the first mention in a Bard field, splitting the text around it', function () {
    $data = ['title' => 'Rachel Adams', 'content' => [paragraph(text('Rachel writes about the Restrictive Covenant Clause often.'))]];

    $edit = linker()->link($data, ['content' => 'bard'], 'restrictive covenant clause', '/insights/clause');

    expect($edit->changed())->toBeTrue()
        ->and($edit->field)->toBe('content')
        ->and($edit->value[0]['content'])->toBe([
            text('Rachel writes about the '),
            text('Restrictive Covenant Clause', [linkMark('/insights/clause')]),
            text(' often.'),
        ]);
});

it('keeps the marks a mention already had, and adds no words', function () {
    $data = ['content' => [paragraph(text('See the restrictive covenant clause', [['type' => 'bold']]))]];

    $edit = linker()->link($data, ['content' => 'bard'], 'restrictive covenant clause', '/clause');

    expect($edit->value[0]['content'])->toBe([
        text('See the ', [['type' => 'bold']]),
        text('restrictive covenant clause', [['type' => 'bold'], linkMark('/clause')]),
    ]);
});

it('takes a Bard link off again and leaves the paragraph as it was', function () {
    $original = ['content' => [paragraph(text('Rachel writes about the restrictive covenant clause often.'))]];
    $types = ['content' => 'bard'];

    $linked = linker()->link($original, $types, 'restrictive covenant clause', '/clause');
    $unlinked = linker()->unlink(['content' => $linked->value], $types, 'restrictive covenant clause', '/clause');

    expect($unlinked->changed())->toBeTrue()
        ->and($unlinked->value)->toBe($original['content']);
});

it('says a mention already linked to the page is done, and leaves one linked elsewhere alone', function () {
    $types = ['content' => 'bard'];
    $linkedHere = ['content' => [paragraph(text('restrictive covenant clause', [linkMark('https://example.com/clause/')]))]];
    $linkedElsewhere = ['content' => [paragraph(
        text('restrictive covenant clause', [linkMark('/elsewhere')]),
        text(' and the restrictive covenant clause again.'),
    )]];

    expect(linker()->link($linkedHere, $types, 'restrictive covenant clause', '/clause')->outcome)->toBe('already')
        ->and(linker()->link($linkedElsewhere, $types, 'restrictive covenant clause', '/clause')->outcome)->toBe('inside_link');
});

it('matches whole words only', function () {
    $data = ['content' => [paragraph(text('All about loft conversions.'))]];

    expect(linker()->link($data, ['content' => 'bard'], 'loft conversion', '/lofts')->outcome)->toBe('not_found');
});

it('finds the mention inside a page builder set, and a Bard set', function () {
    $types = ['page_builder' => 'replicator', 'heading' => 'text', 'body' => 'markdown', 'content' => 'bard', 'quote' => 'textarea'];
    $data = [
        'page_builder' => [
            ['id' => 'a', 'type' => 'hero', 'heading' => 'The restrictive covenant clause'],
            ['id' => 'b', 'type' => 'text', 'body' => 'Read about the restrictive covenant clause here.'],
        ],
    ];

    $edit = linker()->link($data, $types, 'restrictive covenant clause', '/clause');

    expect($edit->path)->toBe('page_builder.1.body')
        ->and($edit->value[1]['body'])->toBe('Read about the [restrictive covenant clause](/clause) here.')
        ->and($edit->value[0]['heading'])->toBe('The restrictive covenant clause');

    $inBardSet = ['content' => [['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => 'aside', 'quote' => 'On the restrictive covenant clause.']]]]];

    expect(linker()->link($inBardSet, $types, 'restrictive covenant clause', '/clause')->value[0]['attrs']['values']['quote'])
        ->toBe('On the <a href="/clause">restrictive covenant clause</a>.');
});

it('links Markdown with Markdown and passes over images and tags', function () {
    $markdown = "![The restrictive covenant clause](/img.jpg)\n\n<span title=\"restrictive covenant clause\">Our</span> view of the restrictive covenant clause.";

    $edit = linker()->link(['body' => $markdown], ['body' => 'markdown'], 'restrictive covenant clause', '/clause');

    expect($edit->value)->toBe("![The restrictive covenant clause](/img.jpg)\n\n<span title=\"restrictive covenant clause\">Our</span> view of the [restrictive covenant clause](/clause).");

    expect(linker()->unlink(['body' => $edit->value], ['body' => 'markdown'], 'restrictive covenant clause', '/clause')->value)->toBe($markdown);
});

it('refuses a Markdown mention already linked somewhere else', function () {
    $edit = linker()->link(['body' => 'See [the restrictive covenant clause](/old) and the restrictive covenant clause.'], ['body' => 'markdown'], 'restrictive covenant clause', '/clause');

    expect($edit->outcome)->toBe('inside_link');
});

it('never links the title, the slug, an SEO field or a plain text field', function () {
    $data = [
        'title' => 'The restrictive covenant clause',
        'alt_seo_meta_description' => 'All about the restrictive covenant clause.',
        'summary' => 'The restrictive covenant clause',
    ];

    expect(linker()->link($data, ['title' => 'markdown', 'alt_seo_meta_description' => 'textarea', 'summary' => 'text'], 'restrictive covenant clause', '/clause')->outcome)
        ->toBe('not_found');
});
