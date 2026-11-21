<?php

it('exposes named routes for every primary dashboard section', function () {
    expect(route('bots.index', absolute: false))->toBe('/bots')
        ->and(route('exchange-credentials.index', absolute: false))->toBe('/exchange-credentials')
        ->and(route('kill-switch.create', absolute: false))->toBe('/kill-switch');
});

it('links every primary section from the authenticated navigation', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));

    foreach (['bots.index', 'exchange-credentials.index', 'kill-switch.create'] as $route) {
        expect(substr_count($layout, "route('{$route}')"))->toBeGreaterThanOrEqual(2);
    }
});

it('renders shared flash messages inside the authenticated layout', function () {
    $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));
    $component = resource_path('js/Components/FlashMessage.vue');

    expect(file_exists($component))->toBeTrue()
        ->and($layout)->toContain('FlashMessage')
        ->and(file_get_contents($component))->toContain('$page.props.flash.status');
});
