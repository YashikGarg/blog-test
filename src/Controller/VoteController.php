<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Session;
use App\Service\VoteService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final class VoteController extends BaseController
{
    public function __construct(
        Environment $twig,
        Session $session,
        ResponseFactoryInterface $responses,
        private readonly VoteService $votes,
    ) {
        parent::__construct($twig, $session, $responses);
    }

    /** @param array<string,string> $vars */
    public function toggle(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $slug = $this->body($request, ['slug'])['slug'];
        $result = $this->votes->toggle($this->uuid($vars), $this->userId());

        return $this->respond(
            $request,
            $result,
            $this->postPath($slug),
            $result['voted'] ? 'Thanks for the upvote' : 'Upvote removed',
        );
    }
}
