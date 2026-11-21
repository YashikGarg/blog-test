<?php

declare(strict_types=1);

use App\Http\Middleware\{CsrfMiddleware, ErrorMiddleware, RouteMiddleware, SecurityHeadersMiddleware, SessionMiddleware};
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Relay\Relay;

// PHP's built-in server: let it serve real static files itself.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/config/settings.php';
require $root . '/config/container.php';

loadEnv($root);
$settings = settings($root);
if (!$settings['debug']) {
    ini_set('display_errors', '0');
}

$container = buildContainer($settings);

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

// SecurityHeaders is outermost so error pages (403/404/422/500) carry the CSP and nosniff headers too.
$pipeline = new Relay([
    $container->get(SecurityHeadersMiddleware::class),
    $container->get(ErrorMiddleware::class),
    $container->get(SessionMiddleware::class),
    $container->get(CsrfMiddleware::class),
    $container->get(RouteMiddleware::class),
]);

(new SapiEmitter())->emit($pipeline->handle($request));
