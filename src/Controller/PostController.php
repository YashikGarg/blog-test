<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CommentService;
use App\Service\PostService;
use App\Service\VoteService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use App\Http\Session;

final class PostController extends BaseController
{
    public function __construct(
        Environment $twig,
        Session $session,
        ResponseFactoryInterface $responses,
        private readonly PostService $posts,
        private readonly CommentService $comments,
        private readonly VoteService $votes,
    ) {
        parent::__construct($twig, $session, $responses);
    }

    /** @param array<string,string> $vars */
    public function index(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $query = $request->getQueryParams();
        $q = is_string($query['q'] ?? null) ? $query['q'] : null;
        $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;

        $page = $this->posts->listPublished($cursor, $q);
        $cards = array_map($this->card(...), $page['posts']);

        if ($this->wantsJson($request)) {
            return $this->json(['ok' => true, 'posts' => $cards, 'nextCursor' => $page['nextCursor']]);
        }

        return $this->render('post/index.twig', [
            'posts' => $cards,
            'nextCursor' => $page['nextCursor'],
            'q' => $page['q'] ?? '',
            'cursor' => $cursor !== null && $cursor !== '',
        ]);
    }

    /** @param array<string,string> $vars */
    public function show(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        ['post' => $post, 'html' => $html] = $this->posts->getPublishedBySlug($vars['slug'] ?? '');
        $userId = $this->session->userId();
        $query = $request->getQueryParams();
        $cursor = is_string($query['comments_before'] ?? null) ? $query['comments_before'] : null;
        $page = $this->comments->listForPost($post->id, $cursor);
        $comments = array_map($this->commentView(...), $page['comments']);

        if ($cursor !== null && $this->wantsJson($request)) {
            return $this->json(['ok' => true, 'comments' => $comments, 'nextCommentCursor' => $page['nextCursor']]);
        }

        return $this->render('post/show.twig', [
            'post' => array_replace($this->card($post), ['id' => $post->id, 'upvotes' => $this->votes->count($post->id)]),
            'html' => $html,
            'comments' => $comments,
            'nextCommentCursor' => $page['nextCursor'],
            'voted' => $userId !== null && $this->votes->hasVoted($post->id, $userId),
            'errors' => [],
            'old' => [],
        ]);
    }
}
