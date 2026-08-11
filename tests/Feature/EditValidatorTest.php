<?php

use AltDesign\Altimiser\Applying\EditValidator;

beforeEach(function () {
    $this->validator = app(EditValidator::class);
    $this->path = 'resources/views/page.antlers.html';
    $this->candidates = [
        $this->path => implode("\n", [
            '<article>',
            '    <img src="{{ hero:url }}" alt="{{ hero:alt }}">',
            '    <p>{{ content }}</p>',
            '</article>',
        ]),
    ];
});

function modelSaid(array $overrides = []): array
{
    return [
        'applicable' => true,
        'file' => 'resources/views/page.antlers.html',
        'start_line' => 2,
        'end_line' => 2,
        'new_text' => '    <img src="{{ hero:url }}" alt="{{ hero:alt }}" loading="lazy">',
        'reasoning' => 'The hero image is rendered from a variable here.',
        ...$overrides,
    ];
}

it('accepts a minimal edit to a tag built from variables', function () {
    $edit = $this->validator->validate(modelSaid(), $this->candidates);

    expect($edit->usable())->toBeTrue()
        ->and($edit->applyTo($this->candidates[$this->path]))
        ->toContain('loading="lazy"')
        ->toContain('{{ content }}')
        ->toContain('<article>');
});

it('preserves the rest of the file exactly', function () {
    $patched = $this->validator->validate(modelSaid(), $this->candidates)->applyTo($this->candidates[$this->path]);

    expect(preg_split('/\R/', $patched))->toHaveCount(4)
        ->and(preg_split('/\R/', $patched)[2])->toBe('    <p>{{ content }}</p>');
});

it('rejects a file that was never offered to it', function () {
    $edit = $this->validator->validate(modelSaid(['file' => '../../.env']), $this->candidates);

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('not offered');
});

it('rejects a line range past the end of the file', function () {
    $edit = $this->validator->validate(modelSaid(['start_line' => 3, 'end_line' => 99]), $this->candidates);

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('not in the file');
});

it('rejects a range that runs backwards', function () {
    $edit = $this->validator->validate(modelSaid(['start_line' => 3, 'end_line' => 2]), $this->candidates);

    expect($edit->usable())->toBeFalse();
});

it('rejects a missing range outright', function () {
    $edit = $this->validator->validate(modelSaid(['start_line' => 0, 'end_line' => 0]), $this->candidates);

    expect($edit->usable())->toBeFalse();
});

it('refuses a whole file rewrite', function () {
    $edit = $this->validator->validate(
        modelSaid(['start_line' => 1, 'end_line' => 4, 'new_text' => "<article>\n    <img loading=\"lazy\">\n    <p>x</p>\n</article>"]),
        $this->candidates,
    );

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('rewrite the whole file');
});

it('refuses a range too large to be one fix', function () {
    $long = ['resources/views/long.antlers.html' => implode("\n", array_fill(0, 40, '<p>x</p>'))];

    $edit = $this->validator->validate(
        modelSaid(['file' => 'resources/views/long.antlers.html', 'start_line' => 1, 'end_line' => 30, 'new_text' => 'x']),
        $long,
    );

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('more than the 12');
});

it('refuses an edit that mostly deletes', function () {
    $edit = $this->validator->validate(modelSaid(['new_text' => '<img>']), $this->candidates);

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('removes substantially more');
});

it('rejects an edit that changes nothing', function () {
    $edit = $this->validator->validate(
        modelSaid(['new_text' => '    <img src="{{ hero:url }}" alt="{{ hero:alt }}">']),
        $this->candidates,
    );

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('changes nothing');
});

it('honours the model declining', function () {
    $edit = $this->validator->validate(
        modelSaid(['applicable' => false, 'reasoning' => 'The image comes from a partial I was not given.']),
        $this->candidates,
    );

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('partial I was not given');
});

it('rejects a malformed response outright', function () {
    expect($this->validator->validate([], $this->candidates)->usable())->toBeFalse();
});

it('tolerates the model reindenting while still changing something', function () {
    $edit = $this->validator->validate(
        modelSaid(['new_text' => '<img src="{{ hero:url }}" alt="{{ hero:alt }}" loading="lazy">']),
        $this->candidates,
    );

    expect($edit->usable())->toBeTrue();
});

it('rejects a tag that cannot have rendered the reported element', function () {
    $candidates = ['resources/views/sets/header.antlers.html' => implode("\n", [
        '<section>',
        '    <img src="{{ glide :src=\'image\' }}" alt="{{ title }}" class="self-end w-full mt-auto" loading="eager">',
        '    <p>x</p>',
        '</section>',
    ])];

    $edit = $this->validator->validate(
        [
            'applicable' => true,
            'file' => 'resources/views/sets/header.antlers.html',
            'start_line' => 2,
            'end_line' => 2,
            'new_text' => '    <img src="{{ glide :src=\'image\' }}" alt="{{ title }}" class="self-end w-full mt-auto" loading="lazy">',
            'reasoning' => 'Confidently wrong.',
        ],
        $candidates,
        renderedTag: '<img src="/cached-img/containers/assets/group-396.png/abc/group-396.webp" alt="" class="">',
    );

    expect($edit->usable())->toBeFalse()
        ->and($edit->rejection)->toContain('cannot have rendered the reported element');
});

it('accepts the tag that did render the reported element', function () {
    $candidates = ['resources/views/sets/blog.antlers.html' => implode("\n", [
        '<article>',
        '    <img src="{{ glide :src=\'image\' }}" alt="" class="" loading="eager">',
        '    <p>x</p>',
        '</article>',
    ])];

    $edit = $this->validator->validate(
        [
            'applicable' => true,
            'file' => 'resources/views/sets/blog.antlers.html',
            'start_line' => 2,
            'end_line' => 2,
            'new_text' => '    <img src="{{ glide :src=\'image\' }}" alt="" class="" loading="lazy">',
            'reasoning' => 'Matches the rendered element.',
        ],
        $candidates,
        renderedTag: '<img src="/cached-img/containers/assets/group-396.png/abc/group-396.webp" alt="" class="">',
    );

    expect($edit->usable())->toBeTrue();
});

it('does not judge a class list built from a variable', function () {
    $candidates = ['resources/views/page.antlers.html' => implode("\n", [
        '<article>',
        '    <img src="{{ image }}" class="{{ classes }}" loading="eager">',
        '    <p>x</p>',
        '</article>',
    ])];

    $edit = $this->validator->validate(
        [
            'applicable' => true,
            'file' => 'resources/views/page.antlers.html',
            'start_line' => 2,
            'end_line' => 2,
            'new_text' => '    <img src="{{ image }}" class="{{ classes }}" loading="lazy">',
            'reasoning' => 'Cannot know the rendered classes from here.',
        ],
        $candidates,
        renderedTag: '<img src="/a.webp" class="whatever it rendered">',
    );

    expect($edit->usable())->toBeTrue();
});

it('allows the rendered element to carry extra classes added elsewhere', function () {
    $candidates = ['resources/views/page.antlers.html' => implode("\n", [
        '<article>',
        '    <img src="{{ image }}" class="hero rounded" loading="eager">',
        '    <p>x</p>',
        '</article>',
    ])];

    $edit = $this->validator->validate(
        [
            'applicable' => true,
            'file' => 'resources/views/page.antlers.html',
            'start_line' => 2,
            'end_line' => 2,
            'new_text' => '    <img src="{{ image }}" class="hero rounded" loading="lazy">',
            'reasoning' => 'Same tag, extra classes downstream.',
        ],
        $candidates,
        renderedTag: '<img src="/a.webp" class="hero rounded shadow-lg">',
    );

    expect($edit->usable())->toBeTrue();
});
