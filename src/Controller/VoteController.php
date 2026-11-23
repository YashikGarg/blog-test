<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
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
    public function vote(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $input = $this->body($request, ['slug', 'voted']);
        try {
            $result = $this->votes->vote($this->uuid($vars), $this->userId(), $input['voted']);
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, null, [], $this->postPath($input['slug']));
        }

        return $this->respond(
            $request,
            $result,
            $this->postPath($input['slug']),
            $result['voted'] ? 'Thanks for the upvote' : 'Upvote removed',
        );
    }
}
