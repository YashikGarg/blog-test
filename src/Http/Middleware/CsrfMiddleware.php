<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class CsrfMiddleware implements MiddlewareInterface
{
    private string $appOrigin;

    public function __construct(
        private Session $session,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responses,
        string $appUrl,
    ) {
        $p = parse_url($appUrl);
        $this->appOrigin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $reason = $this->rejectReason($request);
        if ($reason !== null) {
            $this->logger->warning('csrf rejected', [
                'event' => 'csrf.rejected',
                'reason' => $reason,
                'ip' => (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                'user_id' => $this->session->userId(),
            ]);

            return $this->forbidden($request);
        }

        return $handler->handle($request);
    }

    private function rejectReason(ServerRequestInterface $request): ?string
    {
        if ($request->getHeaderLine('Sec-Fetch-Site') === 'cross-site') {
            return 'cross-site';
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== $this->appOrigin) {
            return 'origin';
        }

        $body = $request->getParsedBody();
        $token = is_array($body) ? ($body['_csrf'] ?? null) : null;
        if (!is_string($token)) {
            $token = $request->getHeaderLine('X-CSRF-Token');
        }

        return $token !== '' && hash_equals($this->session->csrfToken(), $token) ? null : 'token';
    }

    private function forbidden(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse(403);
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response = $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write(json_encode(
                ['ok' => false, 'errors' => ['_form' => 'Security check failed. Reload the page and try again.']],
                JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP,
            ));

            return $response;
        }
        $response = $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->getBody()->write('403 Forbidden: security check failed. Go back, reload the page and try again.');

        return $response;
    }
}
