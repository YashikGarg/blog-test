<?php

declare(strict_types=1);

use Symfony\Component\Uid\Uuid;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/config/settings.php';
require $root . '/config/container.php';

loadEnv($root);
$s = settings($root);

if ($s['db']['migrate_user'] === '') {
    fwrite(STDERR, "DB_MIGRATE_USER is not set\n");
    exit(1);
}

// Guard (every env except local): never seed an admin with an empty, example, or weak password.
$adminPass = $s['admin']['password'];
if ($s['env'] !== 'local') {
    $example = parse_ini_file($root . '/.env.example')['ADMIN_PASSWORD'] ?? '';
    if ($adminPass === '' || $adminPass === $example || preg_match($s['password']['regex'], $adminPass) !== 1) {
        fwrite(STDERR, "Refusing to seed admin when APP_ENV is not local: ADMIN_PASSWORD is empty, the example value, or fails PASSWORD_REGEX\n");
        exit(1);
    }
}

$pdo = makePdo($s['db'], $s['db']['migrate_user'], $s['db']['migrate_pass']);
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(50) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        continue;
    }
    // DDL auto-commits, so no transaction. Run statement by statement so any error is reported.
    foreach (preg_split('/;\s*\n/', (string) file_get_contents($file)) ?: [] as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, "FAILED {$version}: {$e->getMessage()}\n");
            exit(1);
        }
    }
    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute(['v' => $version]);
    echo "applied {$version}\n";
}

// Admin upsert (row alias, not the deprecated VALUES(col)).
$pdo->prepare(
    "INSERT INTO users (id, email, name, password_hash, role) VALUES (UUID_TO_BIN(:id), :email, 'Admin', :hash, 'admin') AS new
     ON DUPLICATE KEY UPDATE password_hash = new.password_hash, role = 'admin'"
)->execute([
    'id' => (string) Uuid::v7(),
    'email' => $s['admin']['email'],
    'hash' => password_hash($adminPass, PASSWORD_ARGON2ID),
]);

foreach (preg_split('/^-- split\s*$/m', (string) file_get_contents($root . '/database/seed.sql')) ?: [] as $i => $statement) {
    // Strip the leading comment line, then bind only the params this statement uses.
    $statement = trim(preg_replace('/^--.*$/m', '', $statement) ?? '');
    $params = array_filter([
        'id' => (string) Uuid::v7(),
        'author' => $s['admin']['email'],
    ], static fn(string $k): bool => str_contains($statement, ':' . $k), ARRAY_FILTER_USE_KEY);
    $pdo->prepare($statement)->execute($params);
}
echo "seeded\n";
