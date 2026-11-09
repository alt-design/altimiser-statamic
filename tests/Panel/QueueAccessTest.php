<?php

use AltDesign\Altimiser\Altimiser;
use AltDesign\Altimiser\ServiceProvider;
use AltDesign\Altimiser\Tests\Panel\PanelTestCase;
use AltDesign\Altimiser\Widgets\AltimiserWidget;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

uses(PanelTestCase::class);

function panelUserWith(array $permissions): Statamic\Contracts\Auth\User
{
    $role = Role::make('queue-test-'.md5(implode(',', $permissions)))
        ->permissions(array_merge(['access cp'], $permissions));
    $role->save();

    $user = User::make()->email(uniqid().'@example.test')->assignRole($role->handle());
    $user->save();

    return $user;
}

function superPanelUser(): Statamic\Contracts\Auth\User
{
    $user = User::make()->email(uniqid().'@example.test')->makeSuper();
    $user->save();

    return $user;
}

function altimiserWidgetHtml(): string
{
    return (string) (new AltimiserWidget(app(Altimiser::class)))->html();
}

function altimiserNavItems(): array
{
    return collect(Nav::build())
        ->flatMap(fn ($section) => $section['items'])
        ->filter(fn ($item) => $item->name() === 'Altimiser')
        ->all();
}

it('registers the permission in its own labelled group', function () {
    $group = Permission::boot()->tree()->firstWhere('handle', 'altimiser');

    expect($group['label'])->toBe('Altimiser')
        ->and($group['permissions'][0]['value'])->toBe(ServiceProvider::PERMISSION)
        ->and($group['permissions'][0]['label'])->toBe('Use the Altimiser queue');
});

it('refuses the page, the widget and the nav item to a user without the permission', function () {
    $this->actingAs(panelUserWith([]));

    $this->getJson(cp_route('altimiser.index'))->assertForbidden();
    $this->get(cp_route('altimiser.index'))->assertRedirect();
    expect(altimiserWidgetHtml())->toBe('')
        ->and(altimiserNavItems())->toBeEmpty();
});

it('offers the page, the widget and the nav item to a role that has the permission', function () {
    $this->actingAs(panelUserWith([ServiceProvider::PERMISSION]));

    $this->get(cp_route('altimiser.index'))->assertOk();
    expect(altimiserWidgetHtml())->not->toBe('')
        ->and(altimiserNavItems())->toHaveCount(1);
});

it('offers the page, the widget and the nav item to a super user', function () {
    $this->actingAs(superPanelUser());

    $this->get(cp_route('altimiser.index'))->assertOk();
    expect(altimiserWidgetHtml())->not->toBe('')
        ->and(altimiserNavItems())->toHaveCount(1);
});
