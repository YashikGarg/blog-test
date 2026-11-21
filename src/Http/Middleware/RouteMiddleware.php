<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Http\Session;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function FastRoute\simpleDispatcher;

/** The last middleware: dispatches the route, checks the role, calls the controller. */
final class RouteMiddleware implements MiddlewareInterface
{
    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Session $session,
        private readonly ResponseFactoryInterface $responses,
        private readonly string $routesFile,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->dispatcher ??= simpleDispatcher(require $this->routesFile);
        $path = rawurldecode($request->getUri()->getPath());
        $info = $this->dispatcher->dispatch($request->getMethod(), $path === '' ? '/' : $path);

        if ($info[0] === Dispatcher::NOT_FOUND) {
            throw new NotFoundException();
        }
        if ($info[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return $this->responses->createResponse(405)->withHeader('Allow', implode(', ', $info[1]));
        }

        [$class, $method, $required, $guestOnly] = $info[1] + [2 => null, 3 => false];
        $loggedIn = $this->session->userId() !== null;

        if ($guestOnly && $loggedIn) {
            return $this->responses->createResponse(303)->withHeader('Location', '/');
        }

        if ($required !== null) {
            if (!$loggedIn) {
                return $this->unauthenticated($request);
            }
            $role = $this->session->role();
            if ($role === null || !$role->satisfies($required)) {
                throw new ForbiddenException();
            }
        }

        return $this->container->get($class)->$method($request, $info[2]);
    }

    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response = $this->responses->createResponse(401)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write(json_encode(
                ['ok' => false, 'errors' => ['_form' => 'Please log in first.']],
                JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP,
            ));

            return $response;
        }

        return $this->responses->createResponse(303)->withHeader('Location', '/login');
    }
}
