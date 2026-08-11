<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\TemplatePatcher;

beforeEach(function () {
    $this->patcher = new TemplatePatcher;
});

function patchRequest(string $check, string $match, string $suggested, ?string $current = null): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: $check,
        url: 'https://example.com/',
        target: ['match' => $match],
        currentValue: $current,
        suggestedValue: $suggested,
    );
}

it('adds a loading attribute to an image that has none', function () {
    $template = '<div><img src="/hero.jpg" alt="Hero" width="800" height="600"></div>';

    $patched = $this->patcher->patch($template, patchRequest('image.not_lazy_loaded', '/hero.jpg', 'lazy'));

    expect($patched)->toBe('<div><img src="/hero.jpg" alt="Hero" width="800" height="600" loading="lazy"></div>');
});

it('preserves a self closing tag', function () {
    $template = '<img src="/hero.jpg" alt="Hero" />';

    $patched = $this->patcher->patch($template, patchRequest('image.not_lazy_loaded', '/hero.jpg', 'lazy'));

    expect($patched)->toBe('<img src="/hero.jpg" alt="Hero" loading="lazy" />');
});

it('rewrites an existing loading attribute rather than adding a second', function () {
    $template = '<img src="/hero.jpg" loading="lazy" alt="Hero">';

    $patched = $this->patcher->patch($template, patchRequest('image.lazy_above_fold', '/hero.jpg', 'eager'));

    expect($patched)->toBe('<img src="/hero.jpg" loading="eager" alt="Hero">')
        ->and(substr_count($patched, 'loading='))->toBe(1);
});

it('handles single quoted attributes', function () {
    $template = "<img src='/hero.jpg' loading='lazy'>";

    $patched = $this->patcher->patch($template, patchRequest('image.lazy_above_fold', '/hero.jpg', 'eager'));

    expect($patched)->toBe("<img src='/hero.jpg' loading='eager'>");
});

it('adds lazy loading to an iframe', function () {
    $template = '<iframe src="https://youtube.com/embed/abc" title="Video"></iframe>';

    $patched = $this->patcher->patch($template, patchRequest('iframe.not_lazy_loaded', 'https://youtube.com/embed/abc', 'lazy'));

    expect($patched)->toContain('loading="lazy"')
        ->and($patched)->toEndWith('</iframe>');
});

it('appends display swap to a google fonts link', function () {
    $template = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">';
    $change = patchRequest(
        'font.no_display_swap',
        'https://fonts.googleapis.com/css2?family=Inter',
        'https://fonts.googleapis.com/css2?family=Inter&display=swap',
    );

    expect($this->patcher->patch($template, $change))
        ->toBe('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter&display=swap">');
});

it('refuses to patch when the literal appears twice', function () {
    $template = '<img src="/hero.jpg"><img src="/hero.jpg">';

    expect($this->patcher->patch($template, patchRequest('image.not_lazy_loaded', '/hero.jpg', 'lazy')))->toBeNull();
});

it('returns null when the literal is absent', function () {
    $template = '<img src="{{ hero_image }}">';

    expect($this->patcher->patch($template, patchRequest('image.not_lazy_loaded', '/hero.jpg', 'lazy')))->toBeNull();
});

it('returns null for a check it has no transform for', function () {
    $template = '<img src="/hero.jpg">';

    expect($this->patcher->patch($template, patchRequest('image.dimensions_missing', '/hero.jpg', '800')))->toBeNull();
});

it('leaves the rest of the template untouched', function () {
    $template = <<<'HTML'
    <section>
        <h1>{{ title }}</h1>
        <img src="/hero.jpg" alt="Hero">
        <p>{{ content }}</p>
    </section>
    HTML;

    $patched = $this->patcher->patch($template, patchRequest('image.not_lazy_loaded', '/hero.jpg', 'lazy'));

    expect($patched)->toContain('{{ title }}')
        ->and($patched)->toContain('{{ content }}')
        ->and($patched)->toContain('<img src="/hero.jpg" alt="Hero" loading="lazy">');
});

it('does nothing when the attribute already has the wanted value', function () {
    $template = '<img src="/hero.jpg" loading="lazy">';

    expect($this->patcher->patch($template, patchRequest('image.not_lazy_loaded', '/hero.jpg', 'lazy')))->toBeNull();
});
