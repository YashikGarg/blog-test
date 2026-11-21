<?php

declare(strict_types=1);

use App\Mcp\DevTools;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/config/settings.php';
require $root . '/config/container.php';

loadEnv($root);
$settings = settings($root);

if ($settings['env'] === 'prod') {
    fwrite(STDERR, "inkwell-dev MCP server refuses to run with APP_ENV=prod\n");
    exit(1);
}

$container = buildContainer($settings);
$container->set(DevTools::class, new DevTools($container->get(PDO::class), $root . '/config/routes.php'));

// stdout carries the protocol: never echo here; the library logs to stderr.
Server::make()
    ->withServerInfo('inkwell-dev', '1.0.0')
    ->withContainer($container)
    ->withTool([DevTools::class, 'dbSchema'], 'db_schema', 'SHOW CREATE TABLE for every table')
    ->withTool([DevTools::class, 'explainQuery'], 'explain_query', 'EXPLAIN a whitelisted repository query by name')
    ->withTool([DevTools::class, 'verifyConstraints'], 'verify_constraints', 'Run the 15 bad writes (rolled back); each must fail as expected')
    ->withTool([DevTools::class, 'verifyVoteCounts'], 'verify_vote_counts', 'Posts whose vote counter drifted from the real count; must be empty')
    ->withTool([DevTools::class, 'listRoutes'], 'list_routes', 'The route table')
    ->build()
    ->listen(new StdioServerTransport());
