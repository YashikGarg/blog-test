<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final readonly class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responses,
        private Session $session,
        private bool $debug,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            return $this->render($request, 422, 'Please check the form', $e->errors);
        } catch (NotFoundException $e) {
            return $this->render($request, 404, 'Page not found');
        } catch (ForbiddenException $e) {
            $this->logger->warning('forbidden', [
                'event' => 'auth.forbidden',
                'ip' => (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                'user_id' => $this->session->userId(),
            ]);

            return $this->render($request, 403, 'You are not allowed to do that');
        } catch (\Throwable $e) {
            $this->logger->error('unhandled exception', ['event' => 'error', 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->render(
                $request,
                500,
                $this->debug ? $e->getMessage() : 'Something went wrong',
                [],
                $this->debug ? $e->getTraceAsString() : null,
            );
        }
    }

    /** @param array<string,string> $errors */
    private function render(ServerRequestInterface $request, int $status, string $message, array $errors = [], ?string $trace = null): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withHeader('Cache-Control', 'no-store');

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $errors = $errors !== [] ? $errors : ['_form' => $message];
            $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            $response->getBody()->write(json_encode(['ok' => false, 'errors' => $errors], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP));

            return $response;
        }

        $html = $this->twig->render('error.twig', $this->session->viewData() + [
            'status' => $status,
            'message' => $message,
            'trace' => $trace,
        ]);
        $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html);

        return $response;
    }
}
