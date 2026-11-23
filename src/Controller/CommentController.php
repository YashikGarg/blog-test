<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Session;
use App\Service\CommentService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final class CommentController extends BaseController
{
    public function __construct(
        Environment $twig,
        Session $session,
        ResponseFactoryInterface $responses,
        private readonly CommentService $comments,
    ) {
        parent::__construct($twig, $session, $responses);
    }

    /** @param array<string,string> $vars */
    public function store(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $postId = $this->uuid($vars);
        $input = $this->body($request, ['body', 'slug']);
        $back = $this->postPath($input['slug'], '#comments');

        try {
            $comment = $this->comments->add($postId, $this->userId(), $input['body']);
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, null, [], $back);
        }

        return $this->respond($request, ['comment' => $this->commentView($comment)], $this->postPath($comment->postSlug, '#comments'), 'Comment added');
    }

    /** @param array<string,string> $vars */
    public function update(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $id = $this->uuid($vars);
        $input = $this->body($request, ['body', 'slug']);

        try {
            $comment = $this->comments->update($id, $this->userId(), $input['body']);
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, null, [], $this->postPath($input['slug'], '#comments'));
        }

        return $this->respond($request, ['comment' => $this->commentView($comment)], $this->postPath($comment->postSlug, '#comments'), 'Comment updated');
    }

    /** @param array<string,string> $vars */
    public function destroy(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $input = $this->body($request, ['slug']);
        $id = $this->uuid($vars);
        $this->comments->delete($id, $this->userId(), $this->role());

        return $this->respond($request, ['id' => $id], $this->postPath($input['slug'], '#comments'), 'Comment deleted');
    }
}
