<?php

it('exposes named routes for every primary dashboard section', function () {
    expect(route('bots.index', absolute: false))->toBe('/bots')
        ->and(route('backtests.index', absolute: false))->toBe('/backtests')
        ->and(route('settings.index', absolute: false))->toBe('/settings')
        ->and(route('exchange-credentials.index', absolute: false))->toBe('/settings/credentials')
        ->and(route('kill-switch.create', absolute: false))->toBe('/kill-switch')
        ->and(route('risk-limits.edit', absolute: false))->toBe('/settings/risk-limits');
});

it('links every primary section from the authenticated navigation', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));

    foreach (['dashboard', 'alerts.index', 'bots.index', 'backtests.index', 'settings.index'] as $route) {
        expect(substr_count($layout, "route('{$route}')"))->toBeGreaterThanOrEqual(2);
    }

    foreach (['exchange-credentials.index', 'kill-switch.create', 'risk-limits.edit'] as $route) {
        expect($layout)->not->toContain("route('{$route}')");
    }
});

it('keeps settings active on its sub-pages', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));

    expect(substr_count($layout, "route().current('settings.*')"))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($layout, "'exchange-credentials.*'"))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($layout, "'risk-limits.*'"))->toBeGreaterThanOrEqual(2);
});

it('places the kill switch next to create bot on the bot list', function () {
    $bots = file_get_contents(resource_path('js/Pages/Bots/Index.vue'));

    expect($bots)->toContain("route('kill-switch.create')")
        ->and($bots)->toContain("route('bots.create')")
        ->and($bots)->toContain('bg-rose-950')
        ->and($bots)->toContain('border-rose-400')
        ->and($bots)->toContain('text-rose-200')
        ->and($bots)->toContain('hover:bg-rose-900');
});

it('no longer serves the old settings urls', function () {
    $this->get('/exchange-credentials')->assertNotFound();
    $this->get('/risk-limits')->assertNotFound();
});

it('renders shared flash messages inside the authenticated layout', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));
    $component = resource_path('js/Components/FlashMessage.vue');

    expect(file_exists($component))->toBeTrue()
        ->and($layout)->toContain('FlashMessage')
        ->and(file_get_contents($component))->toContain('$page.props.flash.status');
});
