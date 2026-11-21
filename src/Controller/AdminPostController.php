<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Session;
use App\Service\ImageUploader;
use App\Service\PostService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final class AdminPostController extends BaseController
{
    private const FIELDS = ['title', 'body', 'status', 'excerpt'];

    public function __construct(
        Environment $twig,
        Session $session,
        ResponseFactoryInterface $responses,
        private readonly PostService $posts,
        private readonly ImageUploader $uploader,
    ) {
        parent::__construct($twig, $session, $responses);
    }

    /** @param array<string,string> $vars */
    public function index(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $before = $request->getQueryParams()['before'] ?? null;
        $page = $this->posts->listForAdmin(is_string($before) ? $before : null);

        return $this->render('admin/index.twig', ['posts' => $page['posts'], 'nextBefore' => $page['nextBefore']]);
    }

    /** @param array<string,string> $vars */
    public function create(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        return $this->render('admin/form.twig', ['post' => null, 'old' => ['status' => 'draft'], 'errors' => []]);
    }

    /** @param array<string,string> $vars */
    public function store(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $input = [];
        try {
            $input = $this->body($request, self::FIELDS);
            $this->posts->create($this->userId(), $input, $this->uploadedFile($request, 'cover'));
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, 'admin/form.twig', ['post' => null, 'old' => $input]);
        }

        return $this->respond($request, [], '/admin', 'Post saved', true);
    }

    /** @param array<string,string> $vars */
    public function edit(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $post = $this->posts->getForEdit($this->uuid($vars));

        return $this->render('admin/form.twig', [
            'post' => $post,
            'old' => ['title' => $post->title, 'body' => $post->bodyMd, 'status' => $post->status->value, 'excerpt' => $post->excerpt],
            'errors' => [],
        ]);
    }

    /** @param array<string,string> $vars */
    public function update(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $id = $this->uuid($vars);
        $input = [];
        try {
            $input = $this->body($request, self::FIELDS);
            $this->posts->update($id, $input, $this->uploadedFile($request, 'cover'));
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, 'admin/form.twig', ['post' => $this->posts->getForEdit($id), 'old' => $input]);
        }

        return $this->respond($request, [], '/admin', 'Post saved', true);
    }

    /** @param array<string,string> $vars */
    public function destroy(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $id = $this->uuid($vars);
        $this->posts->delete($id);

        return $this->respond($request, ['id' => $id], '/admin', 'Post deleted');
    }

    /** @param array<string,string> $vars */
    public function uploadMedia(ServerRequestInterface $request, array $vars): ResponseInterface
    {
        $file = $this->uploadedFile($request, 'file');
        try {
            if ($file === null) {
                throw new ValidationException(['file' => 'Choose an image to upload']);
            }
            $url = $this->uploader->store($file, 'file');
        } catch (ValidationException $e) {
            return $this->respondInvalid($request, $e, null, [], '/admin');
        }

        return $this->json(['ok' => true, 'url' => $url, 'markdown' => '![Image](' . $url . ')']);
    }
}
