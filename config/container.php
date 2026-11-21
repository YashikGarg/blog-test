<?php

declare(strict_types=1);

use App\Controller\{AdminPostController, AuthController, CommentController, PostController, VoteController};
use App\Http\Middleware\{CsrfMiddleware, ErrorMiddleware, RouteMiddleware, SecurityHeadersMiddleware, SessionMiddleware};
use App\Http\Session;
use App\Repository\{CommentRepositoryInterface, LoginAttemptRepositoryInterface, PdoCommentRepository,
    PdoLoginAttemptRepository, PdoPostRepository, PdoUserRepository, PdoVoteRepository,
    PostRepositoryInterface, UserRepositoryInterface, VoteRepositoryInterface};
use App\Service\{AuthService, CachedMarkdownRenderer, CommentService, CommonMarkRenderer, ImageUploader,
    LoginThrottle, MarkdownRendererInterface, PostService, VoteService};
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Clock\NativeClock;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * The one PDO factory: DSN, native prepares, strict sql_mode + UTC, optional TLS.
 * The app calls it with the runtime user, bin/migrate.php with the migrate user.
 */
function makePdo(array $db, string $user, string $pass): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
    ];
    if ($db['ssl_ca'] !== '') {
        $options[Pdo\Mysql::ATTR_SSL_CA] = $db['ssl_ca'];
        $options[Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);

    return new PDO($dsn, $user, $pass, $options);
}

function buildContainer(array $settings): ContainerInterface
{
    $builder = new ContainerBuilder();
    if ($settings['env'] === 'prod') {
        $builder->enableCompilation($settings['root'] . '/var/cache/di');
    }

    $builder->addDefinitions([
        'settings' => $settings,

        PDO::class => factory(fn(ContainerInterface $c) => makePdo(
            $c->get('settings')['db'],
            $c->get('settings')['db']['user'],
            $c->get('settings')['db']['pass'],
        )),

        Environment::class => factory(function (ContainerInterface $c) {
            $s = $c->get('settings');
            $twig = new Environment(new FilesystemLoader($s['root'] . '/views'), [
                'autoescape' => 'html',
                'cache' => $s['twig_cache'],
                'strict_variables' => $s['debug'],
                'debug' => $s['debug'],
            ]);
            $twig->addGlobal('defaultCover', $s['upload']['default_cover']);
            $twig->addGlobal('uploadMaxBytes', $s['upload']['max_bytes']);

            return $twig;
        }),

        CacheInterface::class => factory(function (ContainerInterface $c) {
            $s = $c->get('settings');
            $ttl = $s['cache']['ttl'];
            $useApcu = $s['cache']['driver'] === 'apcu' && function_exists('apcu_enabled') && apcu_enabled();
            $pool = $useApcu
                ? new ApcuAdapter('inkwell', $ttl)
                : new FilesystemAdapter('inkwell', $ttl, $s['root'] . '/var/cache/app');
            return new Psr16Cache($pool);
        }),

        ClockInterface::class => autowire(NativeClock::class),

        LoggerInterface::class => factory(function (ContainerInterface $c) {
            $s = $c->get('settings');
            $dir = $s['root'] . '/var/log';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return (new Logger('inkwell'))->pushHandler(new StreamHandler($dir . '/app.log'));
        }),

        Psr17Factory::class => autowire(),
        ResponseFactoryInterface::class => get(Psr17Factory::class),

        UserRepositoryInterface::class => autowire(PdoUserRepository::class),
        PostRepositoryInterface::class => autowire(PdoPostRepository::class),
        CommentRepositoryInterface::class => autowire(PdoCommentRepository::class),
        VoteRepositoryInterface::class => autowire(PdoVoteRepository::class),
        LoginAttemptRepositoryInterface::class => autowire(PdoLoginAttemptRepository::class),

        MarkdownRendererInterface::class => factory(fn(ContainerInterface $c) => new CachedMarkdownRenderer(
            new CommonMarkRenderer(),
            $c->get(CacheInterface::class),
            $c->get('settings')['cache']['ttl'],
        )),

        'throttle.account' => factory(fn(ContainerInterface $c) => new LoginThrottle(
            $c->get(LoginAttemptRepositoryInterface::class),
            $c->get(ClockInterface::class),
            $c->get('settings')['login']['free_attempts'],
            $c->get('settings')['login']['base_seconds'],
            $c->get('settings')['login']['max_seconds'],
        )),
        'throttle.ip' => factory(fn(ContainerInterface $c) => new LoginThrottle(
            $c->get(LoginAttemptRepositoryInterface::class),
            $c->get(ClockInterface::class),
            $c->get('settings')['login']['ip_free_attempts'],
            $c->get('settings')['login']['base_seconds'],
            $c->get('settings')['login']['max_seconds'],
        )),

        AuthService::class => factory(fn(ContainerInterface $c) => new AuthService(
            $c->get(UserRepositoryInterface::class),
            $c->get('throttle.account'),
            $c->get('throttle.ip'),
            $c->get(LoggerInterface::class),
            $c->get('settings')['password']['regex'],
            $c->get('settings')['password']['hint'],
        )),

        ImageUploader::class => factory(fn(ContainerInterface $c) => new ImageUploader(
            $c->get('settings')['upload']['dir'],
            $c->get('settings')['upload']['prefix'],
            $c->get('settings')['upload']['max_bytes'],
            $c->get('settings')['upload']['mimes'],
        )),

        PostService::class => factory(fn(ContainerInterface $c) => new PostService(
            $c->get(PostRepositoryInterface::class),
            $c->get(MarkdownRendererInterface::class),
            $c->get(ImageUploader::class),
            $c->get('settings')['posts_per_page'],
        )),

        CommentService::class => factory(fn(ContainerInterface $c) => new CommentService(
            $c->get(CommentRepositoryInterface::class),
            $c->get(PostRepositoryInterface::class),
            $c->get('settings')['comment_max_length'],
        )),

        VoteService::class => autowire(),

        Session::class => autowire(),

        SessionMiddleware::class => factory(fn(ContainerInterface $c) => new SessionMiddleware(
            $c->get(Session::class),
            $c->get('settings')['session'],
        )),

        CsrfMiddleware::class => factory(fn(ContainerInterface $c) => new CsrfMiddleware(
            $c->get(Session::class),
            $c->get(LoggerInterface::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get('settings')['url'],
        )),

        ErrorMiddleware::class => factory(fn(ContainerInterface $c) => new ErrorMiddleware(
            $c->get(Environment::class),
            $c->get(LoggerInterface::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get(Session::class),
            $c->get('settings')['debug'],
        )),

        SecurityHeadersMiddleware::class => factory(fn(ContainerInterface $c) => new SecurityHeadersMiddleware(
            $c->get('settings')['env'] === 'prod',
        )),

        AuthController::class => factory(fn(ContainerInterface $c) => new AuthController(
            $c->get(Environment::class),
            $c->get(Session::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get(AuthService::class),
            $c->get(LoggerInterface::class),
            $c->get('settings')['password']['regex'],
            $c->get('settings')['password']['hint'],
        )),

        RouteMiddleware::class => factory(fn(ContainerInterface $c) => new RouteMiddleware(
            $c,
            $c->get(Session::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get('settings')['root'] . '/config/routes.php',
        )),
    ]);

    return $builder->build();
}
