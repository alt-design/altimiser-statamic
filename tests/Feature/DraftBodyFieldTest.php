<?php

use AltDesign\Altimiser\Drafts\BodyField;

function placed(array $fields, string $markdown = "Intro.\n\n## Costs\n\nFrom [our lofts](/lofts).", ?array &$converted = null): ?array
{
    return app(BodyField::class)->place($fields, $markdown, function (string $html, string $field, ?string $set) use (&$converted): array {
        $converted = compact('html', 'field', 'set');

        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'converted']]]];
    });
}

it('writes into a Bard field as Bard nodes, converted from the draft as HTML', function () {
    $body = placed(['title' => ['type' => 'text'], 'summary' => ['type' => 'markdown'], 'content' => ['type' => 'bard']], converted: $converted);

    expect($body)->toBe(['content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'converted']]]]])
        ->and($converted['html'])->toContain('<h2>Costs</h2>')
        ->and($converted['html'])->toContain('<a href="/lofts">our lofts</a>')
        ->and($converted['field'])->toBe('content')
        ->and($converted['set'])->toBeNull();
});

it('writes markdown as it is into a Markdown field', function () {
    expect(placed(['body' => ['type' => 'markdown']], 'Hello **there**.'))->toBe(['body' => 'Hello **there**.']);
});

it('adds one text row to a page builder, in the set that holds prose', function () {
    $fields = ['page_builder' => ['type' => 'replicator', 'sets' => [
        'hero' => ['heading' => 'text', 'image' => 'assets'],
        'quote' => ['quote' => 'textarea'],
        'text' => ['content' => 'bard', 'width' => 'select'],
    ]]];

    $body = app(BodyField::class)->place($fields, 'Hello.', fn (string $html, string $field, ?string $set): array => [$field, $set], 'row');

    expect($body['page_builder'])->toHaveCount(1)
        ->and($body['page_builder'][0]['type'])->toBe('text')
        ->and($body['page_builder'][0]['enabled'])->toBeTrue()
        ->and($body['page_builder'][0]['content'])->toBe(['content', 'text'])
        ->and($body['page_builder'][0]['row'])->toHaveLength(8);
});

it('falls back to a textarea only when it is plainly the body', function () {
    expect(placed(['excerpt' => ['type' => 'textarea']]))->toBeNull()
        ->and(placed(['body' => ['type' => 'textarea']], 'Hello.'))->toBe(['body' => 'Hello.']);
});

it('never writes the draft into the title, the slug or an SEO field', function () {
    expect(placed(['title' => ['type' => 'markdown'], 'alt_seo_meta_description' => ['type' => 'markdown']]))->toBeNull();
});

it('strips raw HTML from the draft before Bard sees it', function () {
    placed(['content' => ['type' => 'bard']], "Hello.\n\n<script>alert(1)</script>", $converted);

    expect($converted['html'])->not->toContain('<script>');
});
