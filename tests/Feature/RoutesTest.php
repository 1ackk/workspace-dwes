<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Facades\Route;

/**
 * Solo las rutas de routes/web.php: sus controladores y las de Route::view().
 *
 * @return list<RoutingRoute>
 */
function applicationRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        function (RoutingRoute $route) {
            $action = ltrim($route->getActionName(), '\\');

            return str_starts_with($action, 'App\\Http\\Controllers\\')
                || str_starts_with($action, ViewController::class);
        },
    ));
}

test('selects only the routes of the application', function () {
    $uris = array_map(fn (RoutingRoute $route) => $route->uri(), applicationRoutes());

    expect($uris)->toContain('/', 'login', 'dashboard', 'my-bookings', 'manager/resources')
        ->not->toContain('up');

    foreach ($uris as $uri) {
        expect($uri)->not->toStartWith('livewire')
            ->not->toStartWith('storage');
    }
});

test('every application route has a name', function () {
    foreach (applicationRoutes() as $route) {
        expect($route->getName())->not->toBeNull(implode('|', $route->methods()).' '.$route->uri().' has no name');
    }
});
