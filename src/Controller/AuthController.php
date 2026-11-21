<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Session;
use App\Service\AuthService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final class AuthController extends BaseController
{
    public function __construct(
        Environment $twig,
        Session $session,
        ResponseFactoryInterface $responses,
        private readonly AuthService $auth,
        private readonly LoggerInterface $logger,
        private readonly string $passwordRegex,
        private readonly string $passwordHint,
    ) {
        parent::__construct($twig, $session, $responses);
    }

    /** @param array<string,string> $vars */
    public function showLogin(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return $this->render('auth/login.twig', ['errors' => [], 'old' => []]);
    }

    /** @param array<string,string> $vars */
    public function login(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        try {
            $input = $this->body($request, ['email', 'password']);
        } catch (ValidationException $e) {
            $this->logMalformed('auth.login_malformed', $request);

            return $this->respondInvalid($request, $e, 'auth/login.twig', ['old' => []]);
        }
        try {
            $user = $this->auth->login($input['email'], $input['password'], $this->ip($request));
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, 'auth/login.twig', ['old' => ['email' => $input['email']]]);
        }
        $this->session->login($user);

        return $this->respond($request, [], '/', 'Welcome back, ' . $user->name, true);
    }

    /** @param array<string,string> $vars */
    public function showRegister(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return $this->render('auth/register.twig', $this->registerView([], []));
    }

    /** @param array<string,string> $vars */
    public function register(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        try {
            $input = $this->body($request, ['email', 'name', 'password']);
        } catch (ValidationException $e) {
            $this->logMalformed('auth.register_malformed', $request);

            return $this->respondInvalid($request, $e, 'auth/register.twig', $this->registerView([], $e->errors));
        }
        try {
            $user = $this->auth->register($input['email'], $input['name'], $input['password'], $this->ip($request));
        } catch (ValidationException $e) {
            $old = ['email' => $input['email'], 'name' => $input['name']];

            return $this->respondInvalid($request, $e, 'auth/register.twig', $this->registerView($old, $e->errors));
        }
        $this->session->login($user);

        return $this->respond($request, [], '/', 'Welcome to Inkwell, ' . $user->name, true);
    }

    /** @param array<string,string> $vars */
    public function logout(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $this->session->logout();

        return $this->respond($request, [], '/', 'You are logged out', true);
    }

    private function logMalformed(string $event, ServerRequestInterface $request): void
    {
        $this->logger->info('malformed auth form', ['event' => $event, 'ip' => $this->ip($request), 'user_id' => null]);
    }

    /**
     * @param array<string,string> $old
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function registerView(array $old, array $errors): array
    {
        return ['old' => $old, 'errors' => $errors, 'passwordHint' => $this->passwordHint, 'passwordPattern' => $this->htmlPattern()];
    }

    /** The server regex body without delimiters and anchors: the HTML pattern attribute is anchored already. */
    private function htmlPattern(): string
    {
        if (preg_match('~^/(.*)/[a-z]*$~s', $this->passwordRegex, $m) !== 1) {
            return '';
        }

        return preg_replace(['/^\^/', '/\$$/'], '', $m[1]) ?? '';
    }
}
