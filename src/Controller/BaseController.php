<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\Session;
use App\Model\Comment;
use App\Model\Post;
use App\Model\Role;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/** Shared HTTP helpers. Controllers never touch SQL, $_POST, $_SESSION or header(). */
abstract class BaseController
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP;

    public function __construct(
        protected readonly Environment $twig,
        protected readonly Session $session,
        protected readonly ResponseFactoryInterface $responses,
    ) {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $html = $this->twig->render($template, $data + $this->session->viewData());
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-cache');
        $response->getBody()->write($html);

        return $response;
    }

    /** Internal paths only: starts with / but not // (no open redirect). */
    protected function redirect(string $path, int $status = 303): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Location', $this->safePath($path));
    }

    /** One rule for every redirect (header and JSON): starts with a single /, no backslash, no control character. */
    private function safePath(string $path): string
    {
        $internal = str_starts_with($path, '/') && !str_starts_with($path, '//') && preg_match('/[\\\\\x00-\x1F\x7F]/', $path) !== 1;

        return $internal ? $path : '/';
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write(json_encode($data, self::JSON_FLAGS));

        return $response;
    }

    protected function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * The one success contract. AJAX: 200 {ok:true, ...data, redirect}. No JS: flash + 303 redirect (PRG).
     * $navigates: the AJAX client will load $redirect, so the flash is kept for that page.
     *
     * @param array<string,mixed> $data
     */
    protected function respond(ServerRequestInterface $request, array $data, string $redirect, string $flash, bool $navigates = false): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            // A flash is stored only when the client will navigate to a new page and show it there.
            if ($navigates) {
                $this->session->flash('success', $flash);
            }

            return $this->json(['ok' => true] + $data + ['redirect' => $this->safePath($redirect)]);
        }
        $this->session->flash('success', $flash);

        return $this->redirect($redirect);
    }

    /**
     * The one failure contract. AJAX: 422 {ok:false, errors}. No JS: re-render the form with errors and old input,
     * or (no template) flash the first error and redirect back.
     *
     * @param array<string,mixed> $viewData
     */
    protected function respondInvalid(
        ServerRequestInterface $request,
        ValidationException $e,
        ?string $template,
        array $viewData = [],
        string $backTo = '/',
    ): ResponseInterface {
        if ($this->wantsJson($request)) {
            return $this->json(['ok' => false, 'errors' => $e->errors], 422);
        }
        if ($template === null) {
            $this->session->flash('error', array_values($e->errors)[0] ?? 'Please check your input');

            return $this->redirect($backTo);
        }

        return $this->render($template, $viewData + ['errors' => $e->errors, 'old' => []], 422);
    }

    /**
     * Only the expected keys, each checked to be a string. An array or bool sent for a scalar field is a 422.
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    protected function body(ServerRequestInterface $request, array $keys): array
    {
        $parsed = $request->getParsedBody();
        $parsed = is_array($parsed) ? $parsed : [];
        $out = [];
        foreach ($keys as $key) {
            $value = $parsed[$key] ?? '';
            if (!is_string($value)) {
                throw new ValidationException([$key => 'Invalid value']);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    protected function uploadedFile(ServerRequestInterface $request, string $name): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()[$name] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /** A route id: the route regex already matched; isValid is the second check before any query. */
    protected function uuid(array $vars): string
    {
        $id = $vars['id'] ?? '';

        return is_string($id) && Uuid::isValid($id) ? $id : throw new NotFoundException();
    }

    protected function userId(): string
    {
        return $this->session->userId() ?? throw new ForbiddenException();
    }

    protected function role(): Role
    {
        return $this->session->role() ?? throw new ForbiddenException();
    }

    protected function ip(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    /** Where the no-JS fallback returns after a comment or vote: /posts/{slug}. The slug is checked, never trusted. */
    protected function postPath(string $slug, string $fragment = ''): string
    {
        return preg_match('/^[a-z0-9-]{1,220}$/', $slug) === 1 ? '/posts/' . $slug . $fragment : '/';
    }

    /** @return array{slug:string,title:string,excerpt:string,coverImage:?string,authorName:string,publishedAt:string,upvotes:int} */
    protected function card(Post $post): array
    {
        return [
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'coverImage' => $post->coverImage,
            'authorName' => $post->authorName,
            'publishedAt' => $this->dateLabel($post->publishedAt ?? $post->updatedAt),
            'upvotes' => $post->upvotes,
        ];
    }

    /** @return array{id:string,userName:string,body:string,createdAt:string,edited:bool,canEdit:bool,canDelete:bool} */
    protected function commentView(Comment $comment): array
    {
        $isOwner = $this->session->userId() === $comment->userId;
        $isAdmin = $this->session->role()?->satisfies(Role::Admin) ?? false;

        return [
            'id' => $comment->id,
            'userName' => $comment->userName,
            'body' => $comment->body,
            'createdAt' => $this->dateLabel($comment->createdAt, true),
            'edited' => $comment->isEdited(),
            'canEdit' => $isOwner,
            'canDelete' => $isOwner || $isAdmin,
        ];
    }

    private function dateLabel(string $utc, bool $withTime = false): string
    {
        $t = strtotime($utc . ' UTC');

        return $t === false ? '' : gmdate($withTime ? 'j M Y, H:i' : 'j M Y', $t);
    }
}
