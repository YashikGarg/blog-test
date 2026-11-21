<?php

declare(strict_types=1);

use App\Controller\{AdminPostController, AuthController, CommentController, PostController, VoteController};
use App\Model\Role;

/** UUIDv7, lowercase. Anything else is a 404 before a query runs. */
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

/**
 * Handler: [Controller::class, 'method', ?Role $required, bool $guestOnly]
 * Returns a callable that receives the FastRoute collector (or anything with addRoute()).
 */
return static function ($r): void {
    $r->addRoute('GET', '/', [PostController::class, 'index']);
    $r->addRoute('GET', '/posts/{slug:[a-z0-9-]+}', [PostController::class, 'show']);

    $r->addRoute('GET', '/login', [AuthController::class, 'showLogin', null, true]);
    $r->addRoute('POST', '/login', [AuthController::class, 'login', null, true]);
    $r->addRoute('GET', '/register', [AuthController::class, 'showRegister', null, true]);
    $r->addRoute('POST', '/register', [AuthController::class, 'register', null, true]);
    $r->addRoute('POST', '/logout', [AuthController::class, 'logout', Role::User]);

    $r->addRoute('POST', '/posts/{id:' . UUID . '}/comments', [CommentController::class, 'store', Role::User]);
    $r->addRoute('POST', '/posts/{id:' . UUID . '}/vote', [VoteController::class, 'toggle', Role::User]);
    $r->addRoute('POST', '/comments/{id:' . UUID . '}', [CommentController::class, 'update', Role::User]);
    $r->addRoute('POST', '/comments/{id:' . UUID . '}/delete', [CommentController::class, 'destroy', Role::User]);

    $r->addRoute('GET', '/admin', [AdminPostController::class, 'index', Role::Admin]);
    $r->addRoute('GET', '/admin/posts/new', [AdminPostController::class, 'create', Role::Admin]);
    $r->addRoute('POST', '/admin/posts', [AdminPostController::class, 'store', Role::Admin]);
    $r->addRoute('GET', '/admin/posts/{id:' . UUID . '}/edit', [AdminPostController::class, 'edit', Role::Admin]);
    $r->addRoute('POST', '/admin/posts/{id:' . UUID . '}', [AdminPostController::class, 'update', Role::Admin]);
    $r->addRoute('POST', '/admin/posts/{id:' . UUID . '}/delete', [AdminPostController::class, 'destroy', Role::Admin]);
    $r->addRoute('POST', '/admin/media', [AdminPostController::class, 'uploadMedia', Role::Admin]);
    $r->addRoute('POST', '/admin/preview', [AdminPostController::class, 'preview', Role::Admin]);
};
