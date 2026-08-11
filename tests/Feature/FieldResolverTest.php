<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\FieldResolver;
use AltDesign\Altimiser\Applying\ValueResolver;

beforeEach(function () {
    config()->set('altimiser.fields', [
        'title' => ['alt_seo_meta_title', 'seo_title', 'title'],
        'meta_description' => ['alt_seo_meta_description', 'seo_description', 'meta_description', 'description'],
    ]);

    $this->resolver = new FieldResolver(new ValueResolver);
});

function change(string $check, ?string $currentValue, string $suggested = 'New value'): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: $check,
        url: 'https://example.com/about',
        target: [],
        currentValue: $currentValue,
        suggestedValue: $suggested,
    );
}

it('finds the field holding the value the scan saw', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us.'),
        ['title' => 'About', 'seo_description' => 'About us.'],
        ['title', 'seo_description'],
    );

    expect($match->matched())->toBeTrue()
        ->and($match->field)->toBe('seo_description');
});

it('matches even when the stored value has different whitespace', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us and what we do.'),
        ['seo_description' => "About us\n  and what   we do."],
        ['seo_description'],
    );

    expect($match->field)->toBe('seo_description');
});

it('refuses to guess when two fields hold the same value', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['summary' => 'Same text.', 'intro' => 'Same text.'],
        ['summary', 'intro'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('ambiguous')
        ->and($match->message)->toContain('summary')
        ->and($match->message)->toContain('intro');
});

it('breaks a tie using the configured field order', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['seo_description' => 'Same text.', 'intro' => 'Same text.'],
        ['seo_description', 'intro'],
    );

    expect($match->field)->toBe('seo_description');
});

it('reports no match when nothing holds the value', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Text that is not stored anywhere.'),
        ['title' => 'About'],
        ['title'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('no_match');
});

it('picks the first empty configured field when nothing was rendered', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['title' => 'About', 'seo_description' => ''],
        ['title', 'seo_description', 'description'],
    );

    expect($match->field)->toBe('seo_description');
});

it('skips a configured field the blueprint does not define', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['title' => 'About'],
        ['title', 'description'],
    );

    expect($match->field)->toBe('description');
});

it('will not invent a location when no configured field is available', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['title' => 'About'],
        ['title'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('no_match');
});

it('never overwrites a configured field that already has content', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['seo_description' => 'Already written', 'description' => ''],
        ['seo_description', 'description'],
    );

    expect($match->field)->toBe('description');
});

it('ignores non-scalar field values when matching', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us.'),
        ['blocks' => [['type' => 'text']], 'seo_description' => 'About us.'],
        ['blocks', 'seo_description'],
    );

    expect($match->field)->toBe('seo_description');
});

it('treats an inherited site-wide default as a per-page override', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Award-winning studio in Bristol.'),
        ['title' => 'About', 'alt_seo_meta_description' => ''],
        ['title', 'alt_seo_meta_description'],
    );

    expect($match->matched())->toBeTrue()
        ->and($match->field)->toBe('alt_seo_meta_description')
        ->and($match->inherited)->toBeTrue();
});

it('matches a field whose stored value only resolves after substitution', function () {
    $match = $this->resolver->resolve(
        change('title.too_short', 'About | Acme Ltd'),
        ['title' => 'About', 'alt_seo_meta_title' => '{title} | {site_name}'],
        ['title', 'alt_seo_meta_title'],
        ['title' => 'About', 'site_name' => 'Acme Ltd'],
    );

    expect($match->field)->toBe('alt_seo_meta_title')
        ->and($match->flattensTemplate)->toBeTrue()
        ->and($match->inherited)->toBeFalse();
});

it('prefers a literal match over a templated one', function () {
    $match = $this->resolver->resolve(
        change('title.too_short', 'About | Acme Ltd'),
        ['alt_seo_meta_title' => '{title} | {site_name}', 'seo_title' => 'About | Acme Ltd'],
        ['alt_seo_meta_title', 'seo_title'],
        ['title' => 'About', 'site_name' => 'Acme Ltd'],
    );

    expect($match->field)->toBe('seo_title')
        ->and($match->flattensTemplate)->toBeFalse();
});

it('does not call a genuine match inherited', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us.'),
        ['alt_seo_meta_description' => 'About us.'],
        ['alt_seo_meta_description'],
    );

    expect($match->field)->toBe('alt_seo_meta_description')
        ->and($match->inherited)->toBeFalse();
});

it('will not override an inherited value into a field that already has content', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Some inherited default text.'),
        ['alt_seo_meta_description' => 'Something else entirely'],
        ['alt_seo_meta_description'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('no_match');
});

it('keeps reporting ambiguity rather than falling back to an override', function () {
    config()->set('altimiser.fields.meta_description', ['alt_seo_meta_description']);

    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['summary' => 'Same text.', 'intro' => 'Same text.', 'alt_seo_meta_description' => ''],
        ['summary', 'intro', 'alt_seo_meta_description'],
    );

    expect($match->reason)->toBe('ambiguous');
});
