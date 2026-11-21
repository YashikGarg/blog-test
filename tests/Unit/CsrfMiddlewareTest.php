<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\CsrfMiddleware;
use App\Http\Session;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

#[Group('unit')]
#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareTest extends TestCase
{
    private Session $session;
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->session = new Session();
        $this->middleware = new CsrfMiddleware($this->session, new NullLogger(), new Psr17Factory(), 'http://localhost:8000');
    }

    public function test_post_without_token_is_rejected_with_403(): void
    {
        $handler = $this->handler();

        $response = $this->middleware->process(new ServerRequest('POST', '/logout'), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->reached);
    }

    public function test_post_with_valid_token_reaches_handler(): void
    {
        $handler = $this->handler();
        $request = (new ServerRequest('POST', '/logout'))->withParsedBody(['_csrf' => $this->session->csrfToken()]);

        $response = $this->middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->reached);
    }

    public function test_cross_site_post_is_rejected_even_with_valid_token(): void
    {
        $handler = $this->handler();
        $request = (new ServerRequest('POST', '/logout'))
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->withParsedBody(['_csrf' => $this->session->csrfToken()]);

        $response = $this->middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->reached);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $reached = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;

                return new \Nyholm\Psr7\Response(200);
            }
        };
    }
}
