<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SessionMiddleware implements MiddlewareInterface
{
    /** @param array{name:string,secure:bool,lifetime:int} $settings */
    public function __construct(private Session $session, private array $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name($this->settings['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->settings['secure'],
        ]);
        session_start();

        // Server-side idle timeout: expiry never depends on the cookie's lifetime.
        $now = time();
        $last = $this->session->lastSeen();
        if ($last !== null && $now - $last > $this->settings['lifetime']) {
            $this->session->logout();
        }
        $this->session->touch($now);

        try {
            return $handler->handle($request);
        } finally {
            session_write_close();
        }
    }
}
