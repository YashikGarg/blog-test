# Implementation plan for Claude Sonnet (time box: 60 minutes)

**Read `CLAUDE.md` first.** It is the law; this file is the order of work.
- Do the steps in order. Each step has a time box and a "Done when" check. If a step overruns by 5 minutes, take the simplest path that still meets CLAUDE.md, and log the shortcut in CLAUDE.md §B9.
- Do **not** change architecture, libraries, schema, or routes without asking (CLAUDE.md A1).
- Don't re-plan. Everything below is already decided.
- **No new features during the 60 minutes.** The scope is fixed by CLAUDE.md §B1.
- **Correctness beats speed.** A cut from the cut list is fine; a buggy or half-working feature is not. Never skip validation, CSRF, ownership checks, or tests to save time.
- **Subagents:** steps 6 and 7 always run in parallel (see the time budget). Other steps may be handed off only if you're more than 5 minutes behind, and only if they're independent. Rules:
  1. Each subagent gets CLAUDE.md, this plan, and an explicit list of the files it **owns**. It may not touch any other file.
  2. Each one must invoke the same skills its step lists.
  3. When it finishes, **you** review its diff and run `npm run typecheck` / `composer test` yourself. Accept its work only if that passes.
  4. Never run two agents on the same file.
  5. Log every subagent you used in CLAUDE.md §B9.

## Time budget (total 60 min; check the clock after every step)

| Step | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 |
|---|---|---|---|---|---|---|---|---|---|
| Minutes | 3 | 8 | 8 | 11 | 10 | 10 | 8 ‖ | 8 ‖ | 2 |
| Ends by | 0:03 | 0:11 | 0:19 | 0:30 | 0:40 | 0:50 | 0:58 | 0:58 | 1:00 |

‖ **Steps 6 and 7 run in parallel, always.** At 0:50, spawn one subagent for step 7. It owns `tests/` and `phpunit.xml` only, and must invoke `acc:create-unit-test`, `acc:testing-knowledge`, and `acc:check-test-quality`. The main agent does step 6 (TS) meanwhile. At 0:58 the main agent reviews the subagent's diff and runs `composer test` itself before accepting.

The MCP server is built in step 1, so it's available for every later step.

Speed rules:
- Run `composer require`, `npm install`, and `composer db:up` (Docker MySQL) with `run_in_background`, and write the step 1 SQL while they run.
- Write each file once, completely. Don't scaffold and revisit.
- Run tests once at step 7, plus a `php -l` sweep. Don't run anything after every file.
- Ask the user only when blocked. Otherwise follow the plan.

**If you're behind, cut in this order** (these never touch required features, security, or tests):
1. Cover preview (TS).
2. Insert-at-cursor for media. Show the returned markdown in a readonly input instead.
3. The `excerpt` field. Derive it from the first 200 characters of the body instead.
4. The `list_routes` MCP tool. Never cut `verify_constraints` or `explain_query`.

Log every cut in CLAUDE.md §B9.

## Kickoff prompt to paste into Sonnet

> Read CLAUDE.md and docs/IMPLEMENTATION_PLAN.md fully. Implement steps 0→8 in order, respecting each time box. Use the skills listed per step. Don't change the architecture without asking me. After each step, run its "Done when" check and give a one-line status. At the end, update CLAUDE.md §B9.

---

## Step 0 — Tooling and env (3 min)

Skills: `acc:no-framework-knowledge` (architecture and composer refs).

1. Write `composer.json`:
   - `"name": "inkwell/blog"`, `"require": {"php": ">=8.4"}` (symfony/cache 8.x needs PHP 8.4 or later)
   - PSR-4 `"App\\": "src/"` and `"Tests\\": "tests/"`
   - scripts: `test`, `serve`, `migrate`, `db:up` (`docker compose --env-file .env.local up -d --wait`), `db:down` (`docker compose --env-file .env.local down`)
   ```
   composer require vlucas/phpdotenv nikic/fast-route php-di/php-di nyholm/psr7 nyholm/psr7-server \
     relay/relay laminas/laminas-httphandlerrunner twig/twig league/commonmark symfony/cache \
     symfony/clock symfony/uid monolog/monolog php-mcp/server
   composer require --dev phpunit/phpunit
   ```
   Verified on 2026-11-20 with PHP 8.5.12: all of these install cleanly. The resolved versions are phpdotenv 5.8, fast-route 1.3, php-di 7.1, nyholm/psr7 1.8, relay 3.0, laminas runner 2.15, twig 3.30, commonmark 2.10, symfony/cache, symfony/clock, and symfony/uid 8.1, monolog 3.13, php-mcp/server 3.3, and phpunit 13.4. If anything fails anyway, **stop and ask**. Don't swap a package silently.
2. Write `package.json`:
   - devDeps `typescript`, `esbuild`
   - scripts:
     - `"build": "esbuild frontend/src/main.ts --bundle --minify --format=esm --target=es2022 --outfile=public/assets/app.js"`
     - `"watch"`: the same build with `--watch`
     - `"typecheck": "tsc --noEmit"`
3. Write `tsconfig.json`: `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, `target ES2022`, `module ESNext`, `moduleResolution Bundler`, `lib ["ES2022","DOM","DOM.Iterable"]`, `include ["frontend/src"]`.
4. Write `phpunit.xml`: one suite `tests/Unit`, exclude `tests/_scratch`, `failOnWarning`, `failOnRisky`.
5. Write `.gitignore` with: `vendor/ node_modules/ var/ .env.local .env.dev .env.prod public/uploads/* !public/uploads/.htaccess public/assets/app.js tests/_scratch/`
6. Write the env files: `.env.example` (committed, full list below), plus `.env.local`, `.env.dev`, `.env.prod` with the same keys and per-environment values. `.env` holds only `APP_ENV=local`.

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
DB_HOST=127.0.0.1
DB_PORT=3308                    # Docker MySQL; 3307 is taken on this machine
DB_ROOT_PASS=change-me          # Docker only: used once to create the users
DB_NAME=inkwell
DB_USER=inkwell_app             # runtime user: SELECT, INSERT, UPDATE only (no DELETE: soft delete; no DDL)
DB_PASS=change-me
DB_MIGRATE_USER=inkwell_migrate # DDL user for bin/migrate.php; REQUIRED in every env (never DB_USER)
DB_MIGRATE_PASS=change-me
DB_SSL_CA=                      # prod: path to CA file -> TLS required; empty = no TLS
SESSION_NAME=inkwell_sid        # prod: __Host-inkwell_sid
SESSION_SECURE=false            # prod: true
SESSION_LIFETIME=7200
PASSWORD_REGEX="/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{10,72}$/"
PASSWORD_HINT="10-72 characters with upper, lower case and a number"
LOGIN_FREE_ATTEMPTS=5              # failures allowed before backoff starts
LOGIN_BACKOFF_BASE_SECONDS=60      # first lock: 60s, then 120, 240, 480 ...
LOGIN_BACKOFF_MAX_SECONDS=7200     # cap: 2 hours
UPLOAD_MAX_BYTES=3145728
UPLOAD_ALLOWED_MIMES=image/jpeg,image/png,image/webp,image/gif
CACHE_DRIVER=filesystem         # prod: apcu (falls back to filesystem if APCu missing)
CACHE_TTL=86400
TWIG_CACHE=false                # prod: var/cache/twig
POSTS_PER_PAGE=10
COMMENT_MAX_LENGTH=2000         # app policy; the DB CHECK (5000) is only a backstop
ADMIN_EMAIL=admin@inkwell.test
ADMIN_PASSWORD=Admin12345x      # seed only; prod: set via real env
```

7. Copy the taste-skill system layer and the motion layer (house rule 5: hover and press micro-animations). Then self-host the dealt font, because the CSP blocks Google Fonts:
   ```
   T=$(ls -d "${CLAUDE_CONFIG_DIR:-$HOME/.claude}"/plugins/cache/taste-skill/taste-skill/*/skills/taste-skill | head -1)
   mkdir -p public/assets/vendor/taste public/assets/fonts
   cp "$T"/assets/system.{css,js} "$T"/assets/motion/motion.{css,js} public/assets/vendor/taste/
   npm i -D @fontsource-variable/schibsted-grotesk        # OFL-1.1, verified 5.3.0
   cp node_modules/@fontsource-variable/schibsted-grotesk/files/*-latin-wght-normal.woff2 public/assets/fonts/schibsted-grotesk.woff2
   ```
   - Read `references/system.md` (Base, Radius and buttons, Nav, Cards, Forms, Access) and `references/motion.md` (hover/press only).
   - **Never run `start.mjs deal` or `gate.mjs --start`.** The look is already dealt (see the design tokens in step 5), and dealing again re-arms the landing-page gate, which would block your Stop. The gate was closed with `GATE: DONE` on 2026-11-21.

8. `docker-compose.yml`, `docker/mysql/conf.d/app.cnf`, and `docker/mysql/init/01-users.sh` **already exist and are verified**. Don't rewrite them. The image is MySQL 8.4 LTS with strict `sql_mode`, UTC, utf8mb4, and the slow log, and it creates the least-privilege `inkwell_app` and `inkwell_migrate` users.

**Done when:** `composer install` and `npm install` succeed, and `composer db:up` reports the container as `Healthy`.

## Step 1 — Database, migrations and MCP server (8 min)

Skills: `mysql-patterns` (project skill in `.claude/skills/`), then `acc:check-index-usage` for a quick self-review.

1. Write `database/migrations/001_init.sql` **exactly** as below. This file was applied and tested through MCP on Docker MySQL 8.4.13. Don't change it without asking.

```sql
CREATE TABLE IF NOT EXISTS users (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(10) NOT NULL DEFAULT 'user',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT chk_users_role CHECK (role IN ('admin','user')),
  CONSTRAINT chk_users_name CHECK (CHAR_LENGTH(name) BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  author_id     BINARY(16) NOT NULL,
  title         VARCHAR(200) NOT NULL,
  slug          VARCHAR(220) NOT NULL,
  excerpt       VARCHAR(300) NOT NULL DEFAULT '',
  body_md       MEDIUMTEXT NOT NULL,
  cover_image   VARCHAR(255) NULL,
  status        VARCHAR(10) NOT NULL DEFAULT 'draft',
  published_at  DATETIME NULL,
  deleted_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_status_published (status, published_at),
  KEY idx_posts_author (author_id),
  FULLTEXT KEY ft_posts_search (title, body_md),
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_posts_status CHECK (status IN ('draft','published','deleted')),
  CONSTRAINT chk_posts_published_at CHECK (status <> 'published' OR published_at IS NOT NULL),
  CONSTRAINT chk_posts_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
  CONSTRAINT chk_posts_title CHECK (CHAR_LENGTH(title) BETWEEN 1 AND 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id         BINARY(16) NOT NULL PRIMARY KEY,
  post_id    BINARY(16) NOT NULL,
  user_id    BINARY(16) NOT NULL,
  body       TEXT NOT NULL,
  status     VARCHAR(10) NOT NULL DEFAULT 'visible',
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comments_post_status_created (post_id, status, created_at),
  KEY idx_comments_user (user_id),
  CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_comments_status CHECK (status IN ('visible','deleted')),
  CONSTRAINT chk_comments_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
  CONSTRAINT chk_comments_body CHECK (CHAR_LENGTH(body) BETWEEN 1 AND 5000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_votes (
  post_id    BINARY(16) NOT NULL,
  user_id    BINARY(16) NOT NULL,
  status     VARCHAR(10) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_votes_user (user_id),
  CONSTRAINT fk_votes_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_votes_status CHECK (status IN ('active','removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_vote_counts (
  post_id BINARY(16) NOT NULL PRIMARY KEY,
  upvotes INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_vote_counts_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  throttle_key   BINARY(20) NOT NULL PRIMARY KEY,
  failures       INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  last_failed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Index notes (`mysql-patterns`):
- Equality comes first, then the sort column. `idx_posts_status_published (status, published_at)` serves `WHERE status='published' ORDER BY published_at DESC, id DESC`, because InnoDB appends the PK `id` to every secondary index, so the keyset cursor `(published_at, id)` is covered too.
- Every FK column has an index, and every FK is `RESTRICT`. **Nothing is hard-deleted**: posts and comments use `status='deleted'` + `deleted_at`, votes use `status='removed'`, and the app DB user has no DELETE grant.
- **IDs are UUIDv7 everywhere** (user decision): `BINARY(16)` PKs and FKs, generated in PHP with `symfony/uid` (`Uuid::v7()`), because MySQL 8.4 has no v7 function.
  - v7 is time-ordered, so inserts append to the B-tree, and `ORDER BY id DESC` equals creation order. Verified: the newest post came first.
  - In SQL, always convert with `UUID_TO_BIN(:id)` and `BIN_TO_UUID(col)`, with **no swap flag**. The `1` flag exists for v1 and would break v7's time order.
  - PHP only ever handles the 36-char string form. **Validate with `Uuid::isValid()` before the query**, because MySQL fails on a bad string (`1411 Incorrect string value … uuid_to_bin`), which would surface as a 500 error.
- **Upvotes live in `post_vote_counts`** (user decision): a 1:1 table keyed by `post_id` (PK and FK). Votes never touch the `posts` row or bump `posts.updated_at`, so the markdown cache key stays valid.
  - `PostRepository::create` inserts the counts row **in the same transaction** as the post, so it always exists.
  - The list reads it with `LEFT JOIN post_vote_counts c ON c.post_id = p.id` (verified `eq_ref PRIMARY`), as `COALESCE(c.upvotes, 0)`.
  - `upvotes` is `INT UNSIGNED` under strict mode, so it can never go below 0. Verified: `1690 … out of range`.
- There are no speculative indexes. EXPLAIN showed `idx_posts_status_created` and `idx_votes_post_status` were never used, so they were removed.

2. Write `database/seed.sql` with one sample published post. The admin user is inserted by `bin/migrate.php`, which hashes `ADMIN_PASSWORD` and uses the MySQL row-alias upsert: `INSERT ... VALUES (...) AS new ON DUPLICATE KEY UPDATE password_hash = new.password_hash`. `VALUES(col)` is deprecated in MySQL.
3. Write `bin/migrate.php`, about 40 lines:
   1. Load settings. Docker already created the database. **Don't** run `CREATE DATABASE`: the migrate user has database-level grants only.
   2. Connect as `DB_MIGRATE_USER`, which is **required**. If it's empty, exit 1 with `DB_MIGRATE_USER is not set`. There is no fallback to `DB_USER`, because the app user has no DDL rights. Use the shared `makePdo($user, $pass)` factory from `config/container.php` (doc fix #5), so the strict `sql_mode` init command is identical.
   3. Create `schema_migrations(version VARCHAR(50) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)`.
   4. For each `database/migrations/*.sql` in sorted order that isn't recorded yet: execute it, insert its version, and print `applied 001_init`.
   5. **Prod guard:** when `APP_ENV=prod`, refuse to seed (exit 1 with a message) if `ADMIN_PASSWORD` is empty, equals the `.env.example` value, or fails `PASSWORD_REGEX`. Otherwise upsert the admin by hashing `ADMIN_PASSWORD` with the row-alias form `INSERT … AS new ON DUPLICATE KEY UPDATE`, then run `seed.sql`.

   DDL auto-commits in MySQL, so don't wrap migrations in a transaction. If a file fails, stop at once and print it.
4. Least privilege is already set up by `docker/mysql/init/01-users.sh`. MCP check result: the app user gets `DROP command denied` and `DELETE command denied`. In prod, also run `ALTER USER 'inkwell_app'@'%' REQUIRE SSL` and set `DB_SSL_CA`.
5. **Build the MCP server now** (CLAUDE.md A2). This code pattern was verified on 2026-11-20 against php-mcp/server 3.3 and MySQL 8.4.
   - `src/Mcp/DevTools.php` gets the PDO and routes from the container. All its tools are read-only:
     - `db_schema()` runs `SHOW CREATE TABLE` for users, posts, comments, post_votes, post_vote_counts, login_attempts, and schema_migrations.
     - `explain_query(string $name)` runs `EXPLAIN` on **whitelisted** named queries: `list_published`, `list_published_after_cursor`, `search`, `admin_list`, `post_by_slug`, `post_by_id`, `slug_exists`, `comments_for_post`, `vote_state` (the read after a toggle), `has_voted`, and `login_lookup` (`login_attempts` by key). They are the same SQL strings as the repositories, so keep them in sync. It never accepts raw SQL.
     - `verify_constraints()` runs on the **app** connection. It does `BEGIN`, then the 13 bad writes below, then `ROLLBACK`. Each write must fail with **exactly** its expected error:

       | # | Bad write | Expected error |
       |---|---|---|
       | 1 | post `status='abcd'` | `chk_posts_status` |
       | 2 | user `role='superuser'` | `chk_users_role` |
       | 3 | comment `status='hidden'` | `chk_comments_status` |
       | 4 | vote `status='abcd'` | `chk_votes_status` |
       | 5 | `status='published'` with `published_at` NULL | `chk_posts_published_at` |
       | 6 | post `status='deleted'` with `deleted_at` NULL | `chk_posts_deleted_at` |
       | 7 | comment `status='deleted'` with `deleted_at` NULL | `chk_comments_deleted_at` |
       | 8 | post with empty title | `chk_posts_title` |
       | 9 | empty comment body | `chk_comments_body` |
       | 10 | duplicate vote (same post and user) | `1062 … post_votes.PRIMARY` |
       | 11 | `DELETE FROM posts` | `1142 DELETE command denied` |
       | 12 | `upvotes = upvotes - 1` on a zero counter | `1690 out of range` |
       | 13 | `post_vote_counts` row for an unknown post | `1452` FK error |

       It returns PASS for each error and FAIL for any write that succeeds.
     - `verify_vote_counts()` returns every post whose `post_vote_counts.upvotes` differs from its real active `COUNT(*)`. It must return `[]` (drift check).
     - `list_routes()` returns `config/routes.php` as a table.
   - `bin/mcp-server.php`:
     ```php
     Server::make()->withServerInfo('inkwell-dev', '1.0.0')->withContainer($container)
         ->withTool([DevTools::class, 'dbSchema'], 'db_schema')
         ->withTool([DevTools::class, 'explainQuery'], 'explain_query')
         ->withTool([DevTools::class, 'verifyConstraints'], 'verify_constraints')
         ->withTool([DevTools::class, 'verifyVoteCounts'], 'verify_vote_counts')
         ->withTool([DevTools::class, 'listRoutes'], 'list_routes')
         ->build()->listen(new StdioServerTransport());
     ```
     It refuses to start when `APP_ENV=prod`. Logging goes to stderr or a file, **never stdout**, because stdout carries the protocol.
   - `.mcp.json`: `{"mcpServers":{"inkwell-dev":{"command":"php","args":["bin/mcp-server.php"]}}}`.
   - **Claude Code only loads `.mcp.json` when a session starts.** Tell the user: "Run `/mcp` (or restart Claude Code) and approve `inkwell-dev`." Then continue. Until it's connected, don't skip the checks. Call the same `DevTools` methods with `php -r`, or run the SQL with `docker exec inkwell-mysql mysql ...`.

**Done when:**
- `php bin/migrate.php` runs twice. The second run applies nothing.
- The MCP `verify_constraints` tool returns PASS for all 13 rows of the table above. Every expected error in it was verified on Docker MySQL 8.4 on 2026-11-20.

## Step 2 — Bootstrap, DI, middleware, router (8 min)

Skills: `acc:create-psr15-middleware`, `acc:create-di-container` (for reference only; we use PHP-DI).

- `config/settings.php`: returns a typed nested array built from `$_ENV`. Cast bools and ints, and split `UPLOAD_ALLOWED_MIMES` on commas. This is the **only** file that reads env.
- `config/container.php`: a PHP-DI `ContainerBuilder`, with `enableCompilation('var/cache/di')` when `APP_ENV=prod`. Definitions:
  - `PDO`: DSN `mysql:host=;port=;dbname=;charset=utf8mb4`, with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES false`, `DEFAULT_FETCH_MODE ASSOC`, and `Pdo\Mysql::ATTR_INIT_COMMAND` set to the strict `sql_mode` + UTC string from CLAUDE.md A6. When `DB_SSL_CA` is set, also pass `Pdo\Mysql::ATTR_SSL_CA` and `ATTR_SSL_VERIFY_SERVER_CERT => true`. Write the PDO factory once as `makePdo(string $user, string $pass): PDO` in `config/container.php`. The container's `PDO` entry calls it with `DB_USER`/`DB_PASS`, and `bin/migrate.php` calls it with `DB_MIGRATE_USER`/`DB_MIGRATE_PASS`. That's one place for the DSN, the options, and the strict init command, with two users.
  - `Twig\Environment`: `FilesystemLoader('views')`, `autoescape 'html'`, `cache` from settings, `strict_variables` in debug.
  - `Psr\SimpleCache\CacheInterface`: `Psr16Cache` over APCu or Filesystem (`var/cache/app`).
  - `Psr\Clock\ClockInterface` → `Symfony\Component\Clock\NativeClock`.
  - `LoginThrottle`: autowired (it gets `LoginAttemptRepositoryInterface` and the clock), plus `constructorParameter` for `freeAttempts`, `baseSeconds`, and `maxSeconds` from settings.
  - `LoggerInterface`: Monolog.
  - Interface → implementation bindings for the five repositories, plus `MarkdownRendererInterface` → `CachedMarkdownRenderer` wrapping `CommonMarkRenderer`.
  - Scalar params (password regex, upload limits, page size) via `DI\autowire()->constructorParameter(...)`.
- `public/index.php`:
  1. In `cli-server` mode, `return false` when the request is for a real static file.
  2. Load the autoloader, then env in **two passes**. phpdotenv stops at the first file it finds, so a single call with both files would never read `.env.local`.
     1. `Dotenv::createImmutable($root, '.env')->safeLoad()` gives `APP_ENV`. A real server env var wins, because the loader is immutable.
     2. `Dotenv::createImmutable($root, '.env.' . $_ENV['APP_ENV'])->load()`.
     3. Then call `->required([...])` for the DB keys.

     `bin/migrate.php` and `bin/mcp-server.php` use the same two-pass loader (`config/settings.php` provides `loadEnv($root)`).
  3. Build the container and `ServerRequestCreator::fromGlobals()`.
  4. Create `Relay` with `[ErrorMiddleware, SecurityHeadersMiddleware, SessionMiddleware, CsrfMiddleware, RouteMiddleware]` and send the result through `SapiEmitter`.
- `src/Http/Session.php`: wraps the session behind methods: `get`, `set`, `remove`, `regenerate`, `destroy`, `userId(): ?string`, `role(): ?Role`, `login(User)`, `logout()`, `csrfToken()` (created with `bin2hex(random_bytes(32))`), `flash(string $type, string $msg)`, `pullFlash()`. This is the only class that touches `$_SESSION`.
- `SessionMiddleware`: calls `session_start` with the cookie options from settings (`httponly`, `samesite Lax`, `secure`, `lifetime`, `use_strict_mode`, `name`). It then enforces the **idle timeout**: if `now - last_seen > SESSION_LIFETIME`, it calls `Session::logout()`; after that check it sets `last_seen = now`.
- `config/php.prod.ini`: the exact settings listed in CLAUDE.md A7 (PHP-specific hardening). Prod runs with `php -c config/php.prod.ini`, or the server's php.ini includes these settings.
- Security logging: `AuthService`, `RouteMiddleware` (403), and `CsrfMiddleware` get the `LoggerInterface` and log `event`, `ip`, and `user_id` only. Never log secrets.
- `CsrfMiddleware(Session, LoggerInterface, string $appOrigin)`: lets GET/HEAD/OPTIONS pass. For any other method:
  1. If `Sec-Fetch-Site` is `cross-site`, or `Origin` is present and isn't `$appOrigin`, return 403.
  2. Read the token from the parsed body `_csrf` or the `X-CSRF-Token` header. Compare it with `hash_equals($session->csrfToken(), $token)`, after checking it's a string, so it never compares against null.
  3. On failure, return 403 (JSON if the request accepts JSON). The handler is **not** called.
- `Session::login()` and `logout()` call `session_regenerate_id(true)` **and** rotate the CSRF token.
- `SecurityHeadersMiddleware`:
  - CSP `default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`
  - `nosniff`, `Referrer-Policy`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`
  - HSTS when `APP_ENV=prod`.
- `ErrorMiddleware`: maps `ValidationException` → **422** (a backstop: an uncaught validation error, for example from `body()` on `email[]=x`, never becomes a 500; JSON `{ok:false, errors}` or `error.twig`), `NotFoundException` → 404, `ForbiddenException` → 403, and any other `Throwable` → 500. It logs and renders `error.twig` (or JSON), and shows the message and trace only when debug is on. Controllers still catch `ValidationException` themselves to re-render the form with the old input; the middleware mapping is only the safety net.
- `RouteMiddleware`:
  - Uses FastRoute `simpleDispatcher(require config/routes.php)`. A route handler is `[ControllerClass, 'method', ?Role $required, bool $guestOnly=false]`.
  - Returns 404 or 405 when no route matches.
  - If a role is required and the user isn't logged in, it redirects to `/login` (401 JSON for fetch).
  - If the user is logged in, it checks `$session->role()->satisfies($required)`. **`Role::satisfies()` is a role hierarchy: `Admin` satisfies `Admin` and `User`; `User` satisfies only `User`.** So an admin can comment, upvote, delete (moderate) comments, and log out on `user` routes. Never compare roles with `===` for access. If the role doesn't satisfy, throw `ForbiddenException`.
  - Otherwise calls `$container->get($class)->$method($request, $vars)`.
- `config/routes.php`: exactly the table in CLAUDE.md §B5. Id params use the shared regex constant `UUID = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'` (UUIDv7, lowercase), so FastRoute returns 404 for anything else before a query runs.

**Done when:** `composer serve` runs and `/` renders a stub page through Twig.

## Step 3 — Models and repositories (11 min)

Skills: `acc:create-repository`, `acc:check-sql-injection`.

- Models are `final readonly` classes with `fromRow()`:
  - `User`: id, email, name, passwordHash, role.
  - IDs in every model are `string` (UUIDv7, canonical 36-char form).
  - `Post`: id, authorId, authorName, title, slug, excerpt, bodyMd, coverImage, status, `upvotes` (from `post_vote_counts`), publishedAt, updatedAt.
  - `Comment`: id, postId, userId, userName, body, createdAt, updatedAt, and `isEdited(): bool` (true when `updatedAt > createdAt`).
  - `Role` (`admin`, `user`, with `satisfies(Role $required): bool`, where admin ⊇ user), `PostStatus` (`draft`, `published`, `deleted`), `CommentStatus` (`visible`, `deleted`), and `VoteStatus` (`active`, `removed`) are string-backed enums, with cases matching the DB CHECK lists exactly. Each is a tiny file, as PSR-4 requires one per class. The admin form offers only draft and published; `deleted` is set only by `softDelete`. Services parse input with `PostStatus::tryFrom($input)` and throw a `ValidationException` on `null`, so PHP rejects `'abcd'` before the DB does.
- Every repository SELECTs ids as `BIN_TO_UUID(x.id) AS id`, and binds every id parameter as `UUID_TO_BIN(:id)`. `create()` generates the id with `Uuid::v7()` and returns it as a string.
- Repository interfaces contain only the methods that are actually used:
  - `UserRepositoryInterface`: `findByEmail`, `findById`, `create(email,name,hash): string`.
  - `PostRepositoryInterface`:
    - `findPublished(int $limit, ?string $search, ?array $before): list<Post>` uses **keyset pagination** (`mysql-patterns`: no deep OFFSET).
      - It `JOIN`s users for the author name, filters on `status='published'` (which also excludes soft-deleted posts), and selects `COALESCE(c.upvotes, 0) AS upvotes` through `LEFT JOIN post_vote_counts c ON c.post_id = p.id`. Verified: `eq_ref PRIMARY`.
      - With a `$before` cursor `{publishedAt, id}`, it adds `AND (p.published_at < :pa OR (p.published_at = :pa2 AND p.id < UUID_TO_BIN(:id)))`.
      - Search adds `AND MATCH(p.title, p.body_md) AGAINST(:q IN NATURAL LANGUAGE MODE)`.
      - It always ends with `ORDER BY p.published_at DESC, p.id DESC LIMIT :limit`. Fetch `limit + 1` rows to know whether there's an older page. This is one query, with no COUNT.
      - Use **distinct placeholder names** (`:pa`, `:pa2`) because native prepares can't reuse a named param.
      - MCP EXPLAIN result, verified with 200 users and 300 posts: `idx_posts_status_published`, type ref/range, `Backward index scan`, users `eq_ref PRIMARY`, and **no filesort**. With only 1–2 users the optimizer picks a hash join and shows `Using temporary; Using filesort`. That's a statistics artifact, not a bug, so don't add indexes for it. Run `explain_query` against seeded data.
      - Search is `type: fulltext` on `ft_posts_search`. Its `Using filesort` covers only the matched rows, which is acceptable.
    - `findBySlug(string $slug)`: published only, with upvotes; `const` on `uq_posts_slug`. Also `findById(string $id)`, which excludes deleted posts.
    - `findForAdmin(?string $beforeId, int $limit)`: `WHERE status IN ('draft','published') [AND id < UUID_TO_BIN(:before)] ORDER BY id DESC LIMIT :limit`. Verified: `range PRIMARY, Backward index scan`, no filesort.
    - `slugExists(string $slug, ?string $exceptId)` checks **all** rows, deleted ones included, because a slug stays reserved. Also `create(array $data): string`, which inserts the post plus its `post_vote_counts` row in **one transaction**, and `update(string $id, array $data)`.
    - `softDelete(string $id)`: `UPDATE posts SET status='deleted', deleted_at=UTC_TIMESTAMP() WHERE id=UUID_TO_BIN(:id) AND status<>'deleted'`. There is **no** `delete()` method anywhere.
  - `CommentRepositoryInterface`: `findVisibleByPost(string $postId)` (JOIN users, `WHERE c.post_id=:p AND c.status='visible' ORDER BY c.created_at, c.id`; verified on `idx_comments_post_status_created`), `findVisibleById`, `create`, `update(string $id, string $body)`, and `softDelete(string $id)` (`status='deleted', deleted_at=UTC_TIMESTAMP()`). This covers the full CRUD set, and nothing is ever deleted.
  - `VoteRepositoryInterface`: `hasVoted(postId, userId)` (`const` on the PK, `status='active'`) and `toggle(postId, userId): array{voted: bool, count: int}`.
  - `LoginAttemptRepositoryInterface`: `lockedUntil(string $key): ?DateTimeImmutable`, `incrementFailures(string $key, DateTimeImmutable $now, int $windowSeconds): int`, `extendLock(string $key, DateTimeImmutable $until)`, `reset(string $key)`, `acquire(string $key, int $timeoutSeconds): bool` (`SELECT GET_LOCK(:name, :t)`), and `release(string $key)` (`SELECT RELEASE_LOCK(:name)`). The lock name is `'login:' . $key`, 46 characters, under MySQL's 64-character limit. Bind the key as `UNHEX(:k)`, where `:k` is `sha1(...)` as hex.
- `toggle` has **two parts**. The **write** is one short transaction with a fixed lock order (`post_votes` row first, then `post_vote_counts`), so it's atomic, with no lost update and no dirty read. The **read** runs **after COMMIT**: it sees only committed data (possibly including another user's newer vote, which is fine) and holds no locks. Verified: U1 on (1) → U2 on (2) → U1 off (1) → U1 on (2); a user who never voted gets `null`; zero drift; the read is `const PRIMARY` on both tables.
  ```sql
  -- write (transaction)
  START TRANSACTION;
  INSERT INTO post_votes (post_id, user_id, status) VALUES (UUID_TO_BIN(:p), UUID_TO_BIN(:u), 'active') AS new
    ON DUPLICATE KEY UPDATE status = IF(post_votes.status = 'active', 'removed', 'active');
  UPDATE post_vote_counts
     SET upvotes = upvotes + IF(EXISTS(SELECT 1 FROM post_votes WHERE post_id = UUID_TO_BIN(:p2) AND user_id = UUID_TO_BIN(:u2) AND status = 'active'), 1, -1)
   WHERE post_id = UUID_TO_BIN(:p3);
  COMMIT;

  -- read (outside the transaction)
  SELECT v.status AS my_vote, c.upvotes
    FROM post_vote_counts c
    LEFT JOIN post_votes v ON v.post_id = c.post_id AND v.user_id = UUID_TO_BIN(:u)
   WHERE c.post_id = UUID_TO_BIN(:p);
  ```
  - If the `UPDATE` affects 0 rows, the counts row is missing: throw and roll back.
  - On a deadlock (SQLSTATE `40001` / error 1213), roll back and **retry the write once** (`mysql-patterns`: bounded retry).
  - Use distinct placeholder names, because native prepares can't reuse a name.
  - The same read query (without the toggle) feeds the post page's "you voted" state and its count.
- `incrementFailures` is **one atomic upsert**. The new count comes back through `PDO::lastInsertId()`, so parallel failures can't race. Verified 1→6, and a restart at 1 after the window:
  ```sql
  INSERT INTO login_attempts (throttle_key, failures, last_failed_at) VALUES (UNHEX(:k), LAST_INSERT_ID(1), :now) AS new
  ON DUPLICATE KEY UPDATE
    failures = LAST_INSERT_ID(IF(login_attempts.last_failed_at < new.last_failed_at - INTERVAL :window SECOND, 1, login_attempts.failures + 1)),
    last_failed_at = new.last_failed_at;
  ```
  Keep `failures` **before** `last_failed_at`, because MySQL evaluates the assignments left to right.
  - `extendLock`: `UPDATE login_attempts SET locked_until = GREATEST(COALESCE(locked_until, :u), :u2) WHERE throttle_key = UNHEX(:k)`. It never shortens a lock.
  - `reset`: `UPDATE login_attempts SET failures = 0, locked_until = NULL WHERE throttle_key = UNHEX(:k)`.
- Every query is prepared. Bind `LIMIT` with `PDO::PARAM_INT` (there is no `OFFSET` anywhere: keyset only). Write explicit column lists.

**Done when:** `acc:check-sql-injection` finds nothing in `src/Repository`.

## Step 4 — Services (10 min)

Skills: `acc:solid-knowledge`, `acc:check-authentication`.

- `LoginThrottle(LoginAttemptRepositoryInterface $attempts, ClockInterface $clock, int $freeAttempts, int $baseSeconds, int $maxSeconds)`, about 35 lines. **The failure count is stored per IP+account in `login_attempts` and reset on success.**
  - `retryAfter(string $key): int` returns `max(0, lockedUntil - now)` in seconds.
  - `recordFailure(string $key): void`:
    1. `$n = attempts->incrementFailures($key, $now, $maxSeconds)`.
    2. If `$n >= $freeAttempts`, call `attempts->extendLock($key, $now + lockSeconds($n))`. With the defaults, failures 1–4 don't lock; the **5th** failure locks for 60 s, the 6th for 120 s, the 7th for 240 s, and so on, up to 7200 s. CLAUDE.md A7 says the same.
  - `lockSeconds(int $failures): int` is public and pure: `min($baseSeconds * 2 ** min($failures - $freeAttempts, 30), $maxSeconds)`. The `min(…, 30)` guards against overflow.
  - `reset(string $key): void` calls `attempts->reset($key)`.
  - `acquire(string $key, int $timeout): bool` and `release(string $key): void` pass through to the repository.
  - AuthService builds the key **from the account, not from the typed text** (security fix #1). The `users.email` collation `utf8mb4_unicode_ci` ignores accents, so `ädmin@…` still finds the admin row. A key built from the typed email would give each spelling a fresh counter, which means unlimited guesses.
    - If the user is found, the key is `sha1('u:' . $user->id . '|' . $ip)`.
    - If not, it's `sha1('e:' . strtolower(trim($email)) . '|' . $ip)`. No account exists, so variants gain nothing.
- `AuthService(UserRepositoryInterface, LoginThrottle, string $passwordRegex, string $passwordHint)`:
  - `register(email, name, password): User` validates the email with `filter_var`, the name length, and the password with `preg_match($regex)`. It checks the email is unique, then saves with `password_hash($password, PASSWORD_ARGON2ID)`. PHP generates a **random 16-byte salt per hash** from the CSPRNG and stores it inside the hash string. **Never pass a `salt` option** (PHP 8 removed it). Verified: the same password gives two different hashes, both verify, and a wrong password fails. The defaults `m=65536 KiB, t=4, p=1` are above OWASP's minimum for Argon2id. On failure it throws `ValidationException(array $fieldErrors)`.
  - `login(email, password, ip): User` first checks the email with `filter_var($email, FILTER_VALIDATE_EMAIL)`. If that fails, it throws the same generic "Email or password is incorrect" error. Next it calls `findByEmail`, then builds the key as above. It then **serializes attempts per IP+account**: It calls `throttle->acquire($key, 5)`. If that returns false, it throws `ValidationException(['email' => 'Too many attempts at once, try again'])`. The whole check → verify → record sequence then runs inside `try { … } finally { throttle->release($key); }`. Without the lock, N parallel requests could all pass the check before the first failure is recorded. Verified: a second `GET_LOCK` on the same key returns 0 while the first holds it, and 1 after release. Inside the lock, it first checks `throttle->retryAfter($key)`. If that is above 0, it throws `ValidationException(['email' => 'Too many attempts. Try again in N minutes.'])` **without checking the password**. On a failure it calls `recordFailure`. It throws a generic "Email or password is incorrect" error when the user is missing or the password is wrong. To resist timing attacks, it runs `password_verify` against a dummy hash when the user is missing. On success it calls `throttle->reset($key)` and `password_needs_rehash`.
- `PostService(PostRepositoryInterface, MarkdownRendererInterface, ImageUploader, int $perPage)`:
  - `listPublished(?string $cursor, ?string $q)` returns `{posts, nextCursor, q}`.
    - The cursor is an opaque base64url of `"Y-m-d H:i:s|id"`. Decode it and validate the date part with `^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$` and the id part with `Uuid::isValid()`. Treat an invalid cursor as null; never let it cause an error.
    - Trim `q`, limit it to 100 characters, and treat an empty string as null.
  - `getPublishedBySlug(slug)` returns `{post, html}` or throws `NotFoundException`.
  - `create(authorId, input, ?UploadedFileInterface cover)` and `update(id, input, cover)`:
    - Pick `title`, `body`, `status`, and `excerpt` explicitly from the input.
    - Validate the title (1–200 characters) and the body (non-empty).
    - Generate the slug: lowercase ASCII via `iconv` and `preg_replace`. If it's taken, add `-2`, `-3`, and so on.
    - Set `published_at` the first time the post is published.
    - Store the cover through the uploader. When a cover is **replaced**, remove the old file.
  - `delete(id)` calls `softDelete`. The cover file is **kept**, so the post stays restorable. A missing or already-deleted post throws `NotFoundException`.
- `CommentService(CommentRepositoryInterface, PostRepositoryInterface, int $maxLength)` does the full CRUD. Ownership is checked **here**, not in the controller or the view. The route only requires a logged-in user.
  - `listForPost(postId)` (read).
  - `add(postId, userId, body)` trims the body, requires 1..maxLength characters, and requires the post to exist and be published.
  - `update(commentId, actorId, body)`: only the **owner** may edit. Anyone else gets `ForbiddenException`, even an admin, since admins moderate by deleting. The body is validated the same way as in `add`.
  - `delete(commentId, actorId, Role $actorRole)`: the **owner or an admin** may delete, and it calls `softDelete`. Anyone else gets `ForbiddenException`. A deleted comment disappears from the list but stays in the DB.
  - `update` and `delete` throw `NotFoundException` when the comment doesn't exist. **Every method returns the post's `slug`**: it loads the comment joined to its post anyway. The no-JS fallback redirects to `/posts/{slug}#comments`. Comment routes only carry the comment id, and posts are found by slug, so a post id would land on a 404.
  - One private `validateBody()` and one private `findOrFail()` are reused by `add`, `update`, and `delete`.
- `VoteService(VoteRepositoryInterface, PostRepositoryInterface)`: `toggle(postId, userId)` requires a published post.
- `ImageUploader(string $uploadDir, string $publicPrefix, int $maxBytes, list<string> $allowedMimes)`:
  - `store(UploadedFileInterface): string` returns the public path.
  - Check `getError() === UPLOAD_ERR_OK`, then the size, then `finfo(FILEINFO_MIME_TYPE)` against the whitelist, then `getimagesize`.
  - Use the filename `bin2hex(random_bytes(16)).'.'.extFromMime`, and save with `moveTo`.
  - `delete(string $publicPath)` resolves the path with `basename()` only, so `../` can't escape the upload dir.
- `CommonMarkRenderer`: a `CommonMarkConverter` with `['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]`.
- `CachedMarkdownRenderer(MarkdownRendererInterface $inner, CacheInterface, int $ttl)`: `render(string $md, string $cacheKey): string`.

**Done when:** everything is wired in the container and `php -l` passes on all of them.

## Step 5 — Controllers and Twig views (10 min)

Skills: `taste-skill` (system layer only), `modern-web-guidance` (`forms`, `autofill-sign-in-form`, `dialog`, `optimize-image-priority`).

- `BaseController(Twig, Session, ResponseFactory)`:
  - `render(template, data, status=200)` adds the globals `csrf`, `currentUser`, `flash`, and `isAdmin`.
  - `redirect(path)` accepts **internal paths only**, meaning paths that start with `/` but not `//`.
  - Also provides `json(data, status)` and `body(request, list<string> $keys): array<string,string>`. It returns only those keys, and any value that isn't a string is a `ValidationException`, so `email[]=x` or a JSON `true` gives 422, not 500.
- **AJAX contract** (CLAUDE.md A9) in `BaseController`:
  - `wantsJson(request)` checks for `Accept: application/json`.
  - `respond(request, array $data, string $redirect, string $flash)` returns JSON `{ok: true, ...$data, redirect}` for AJAX. Otherwise it returns a flash message plus a 303 redirect (PRG).
  - `respondInvalid(request, ValidationException $e, string $template, array $viewData)` returns `422 {ok: false, errors}` for AJAX. Otherwise it re-renders the form with `errors` and the old input (never the password), with status 422.
  - Every controller action uses these two. None of them branches on JSON by itself.
- `CommentController`: `store`, `update`, and `destroy`. Each one passes the session user id and role to the service, then calls `respond()`. `store` and `update` return `comment: {id, userName, body, createdAt, edited, canEdit, canDelete}`, and `destroy` returns `{id}`. The no-JS fallback redirects to `/posts/{slug}#comments`.
- `VoteController::toggle` calls `respond()` with `{voted, count}`.
- `PostController::index` calls `respond()` with `{posts: PostCard[], nextCursor}` for AJAX (search and Older posts), and renders the page otherwise. `PostCard` is `{slug, title, excerpt, coverImage, authorName, publishedAt, upvotes}`.
- `AuthController` (login, register, logout) and `AdminPostController` (store, update, destroy) also call `respond()` / `respondInvalid()`. Success returns `redirect` (for example `/` after login, or the post URL after saving), and TS navigates there. Validation errors appear under the fields without a reload.
- `AdminPostController::uploadMedia` returns the JSON `{url, markdown: "![alt](url)"}`.
- Views:
  - `layout.twig`:
    - `<html lang="en" data-type="service" data-radius="soft" data-buttons="solid">`, `meta viewport`, and `<meta name="color-scheme" content="light">`. The service kind is light only: the skill reserves dark grades for tech sites.
    - Stylesheets in this order: `vendor/taste/system.css`, `vendor/taste/motion.css`, then `app.css` (which holds the dealt tokens, so it must come last). Then `<script type="module" src="/assets/app.js">`. Add `<link rel="preload" href="/assets/fonts/schibsted-grotesk.woff2" as="font" type="font/woff2" crossorigin>`. Use **no** Google Fonts links and **no** inline `<style>`, because the CSP blocks both.
    - Includes `vendor/taste/system.js` and `vendor/taste/motion.js` as deferred scripts (both are plain files, so they're CSP-safe).
    - A `<meta name="csrf-token">`.
    - `header.nav` with a skip link, then Home, a search form, Login/Register or Logout, and Admin.
  - `post/index.twig`: the search form (GET `q`, `type="search"`), and a grid of `.card` items. Covers use `loading="lazy"`, except the first card, which gets `fetchpriority="high"`. Below the grid: an "Older posts" link (`?cursor=…&q=…`, shown only when `nextCursor` is set) and a "Latest" link when a cursor is active.
  - `post/show.twig`:
    - Cover image, title, meta, `{{ html|raw }}` (the only raw output), and the upvote form (`POST`, `_csrf`, `data-vote`).
    - The comment list, with an "edited" label when `isEdited()` is true. Each comment shows:
      - for the **owner**: an Edit action inside `<details><summary>Edit</summary>`, holding a `POST /comments/{id}` form with a textarea. This works without JS and needs no new TS.
      - for the **owner or an admin**: a delete form with `data-confirm="Delete this comment?"`.
      - The view checks `comment.userId == currentUser.id` only to hide buttons. The service enforces the rule.
    - The comment form when logged in; otherwise a "Log in to comment" link.
  - `auth/login.twig` and `auth/register.twig`: visible labels, `autocomplete` set to `email`, `current-password`, or `new-password`, `pattern` set from the PASSWORD_REGEX body with the hint shown, and each error in a `.field__error` with `aria-describedby`.
  - `admin/index.twig`: a table of draft and published posts, newest first, with an "Older" keyset link (`?before={lastId}`, validated with `Uuid::isValid`), (wrapped in `overflow-x:auto`) with status, Edit, and a Delete form with `data-confirm`.
  - `admin/form.twig`: title, `textarea` body (markdown), status select, cover `<input type=file accept="image/*" data-cover-preview>`, and a media upload `<input type=file data-media-upload>` that inserts the snippet into the textarea. The form uses `enctype="multipart/form-data"`.
  - `error.twig`.
- `public/assets/app.css` **starts with the dealt design tokens**, copied exactly as produced on 2026-11-21 by `start.mjs css --page service --palette warm-paper-walnut --type schibsted --radius soft`:
  ```css
  @font-face { font-family: "Schibsted Grotesk"; src: url("/assets/fonts/schibsted-grotesk.woff2") format("woff2"); font-weight: 400 700; font-display: swap; }
  :root {
    --p-bg: #f7f3ec; --p-surface: #ede6da; --p-ink: #2a2622; --p-muted: #665e55;
    --p-accent: #7a4a2c; --p-accent-ink: #ffffff; --p-action: #7a4a2c; --p-action-ink: #ffffff;
    --p-inv-bg: #2a2622; --p-inv-surface: #36322e; --p-inv-ink: #f7f3ec; --p-inv-muted: #a29e98;
    --p-inv-accent: #f7f3ec; --p-inv-accent-ink: #2a2622; --p-inv-action: #f7f3ec; --p-inv-action-ink: #2a2622;
    --font-display: "Schibsted Grotesk", system-ui, sans-serif;
    --font-text: "Schibsted Grotesk", system-ui, sans-serif;
    --display-weight: 400; --display-tracking: -0.035em; --display-tracking-xl: -0.042em;
  }
  ```
  After the tokens come only page-specific bits, built on the taste tokens (`--s-*`, `--r-*`). Includes the card grid `repeat(auto-fill, minmax(min(100%, 18rem), 1fr))`, the article max-width `70ch`, and prose image `max-width:100%`. Use no other colours or radii.

**Done when:** a logged-out visitor can browse and search, and the admin can create a post with a cover.

## Step 6 — TypeScript: AJAX, no page reloads (8 min, in parallel with step 7)

Skills: `modern-web-guidance` (`forms`, `accessible-error-announcement`, `dialog`, `sanitize-untrusted-html`, `optimize-image-priority`). `sanitize-untrusted-html` is there to confirm that `<template>` + `textContent` needs no sanitizer.

- `frontend/src/http.ts` holds **all** network access:
  ```ts
  export type Guard<T> = (v: unknown) => v is T;
  export type ApiError = { ok: false; errors?: Record<string, string> };
  export async function send<T>(url: string, init: { method: 'GET' | 'POST'; body?: FormData }, guard: Guard<T>): Promise<T>
  ```
  - It adds `Accept: application/json` and `X-CSRF-Token` (from `<meta name="csrf-token">`), and uses `credentials: 'same-origin'`.
  - A 422 throws `ValidationError(errors)`. Any other `!ok` throws `HttpError(status)`. A body that fails the guard throws too.
  - Guards: `isVoteResult`, `isCommentResult`, `isDeleted`, `isPostPage`, `isMediaResult`, `isRedirect`. They check every field's type at runtime, with no `any` and no `as` casts.
- `frontend/src/main.ts`:
  - **One delegated `submit` listener** on `document` for `form[data-ajax]`. It calls `preventDefault`, disables the submit button while the request runs (so a double click can't double-toggle a vote), and runs the `data-confirm` `<dialog>` first if the form has one. It then sends `new FormData(form)` to `form.action` and dispatches on `data-ajax`:

    | `data-ajax` | On success |
    |---|---|
    | `vote` | update the count (`textContent`) and `aria-pressed` |
    | `comment-create` | clone `<template id="comment-tpl">`, fill it, append it, reset the form |
    | `comment-update` | update the body text, show "edited", close the `<details>` |
    | `comment-delete` / `post-delete` | remove the element with the matching `data-id` |
    | `form` (login, register, admin post) | `location.assign(redirect)` after checking the path is internal |
    | `logout` | `location.assign(redirect)`, which is `/` |
    | `search` (GET form) | build `'/?' + new URLSearchParams(new FormData(form))` and call `send(url, {method: 'GET'})` with **no body** (a GET can't carry one). Then replace the card list from `<template id="card-tpl">`, then `history.replaceState` so the URL can be shared and refreshed |

  - `a[data-load-more]` (Older posts) fetches its `href` as JSON, appends cards from `card-tpl`, and updates or removes itself according to `nextCursor`.
  - **Errors:** a `ValidationError` writes each message into the field's `.field__error`, sets `aria-invalid="true"`, and focuses the first invalid field. Any other error writes into the form's `[role=status]` line. Typed input is never lost.
  - `initCoverPreview()`: on `change` of `input[data-cover-preview]`, it reads the file with `FileReader.readAsDataURL` and sets it as the `src` of the preview `<img>`. A `data:` URL is allowed by the CSP (`img-src 'self' data:`); a `blob:` URL would be blocked.
  - `initMediaUpload()`: on `change` of `input[data-media-upload]`:
    1. `send('/admin/media', {method: 'POST', body: FormData with the file}, isMediaResult)`.
    2. Insert the returned `markdown` into the body textarea at `selectionStart`, then fire an `input` event.
    3. On error, write the message into the field's `.field__error`.
  - Every DOM write uses `textContent` / `setAttribute`. `src` and `href` values from JSON must start with `/` and not `//`.
- Views (step 5) provide: `data-ajax`, `data-id`, and `data-confirm` on the forms; `<template id="comment-tpl">` and `<template id="card-tpl">`; and a `[role=status]` line in each form.

**Done when:** `npm run typecheck` and `npm run build` are both clean. On a phone width and a laptop width, every action works **without a page reload**: vote, comment add/edit/delete, admin delete, search, Older posts, login, and saving a post. With JS disabled, the same actions still work through normal page loads.

## Step 7 — Unit tests (8 min, subagent, in parallel with step 6)

Skills: `acc:create-unit-test`, `acc:testing-knowledge`, then `acc:check-test-quality`.

Write only these tests: readable, Arrange/Act/Assert, repositories stubbed.

| File | Tests |
|---|---|
| AuthServiceTest | `test_login_succeeds_with_correct_password`, `test_login_fails_with_wrong_password`, `test_register_rejects_password_not_matching_policy`, `test_register_rejects_duplicate_email` |
| LoginThrottleTest | `test_lock_doubles_after_free_attempts_and_caps_at_max` (pure `lockSeconds`), `test_failure_at_threshold_extends_lock` (repository mock expects `extendLock` with now + 60s) |
| PostServiceTest | `test_create_adds_suffix_when_slug_is_taken`, `test_get_published_by_slug_throws_when_missing` |
| CommentServiceTest | `test_add_saves_comment_on_published_post`, `test_add_rejects_empty_comment`, `test_owner_can_update_own_comment`, `test_user_cannot_update_someone_elses_comment`, `test_user_cannot_delete_someone_elses_comment`, `test_admin_can_delete_any_comment` |
| RoleTest | `test_admin_satisfies_user_but_user_does_not_satisfy_admin` |
| VoteServiceTest | `test_toggle_returns_new_vote_state`, `test_toggle_throws_for_unknown_post` |
| CsrfMiddlewareTest | `test_post_without_token_is_rejected_with_403`, `test_post_with_valid_token_reaches_handler`, `test_cross_site_post_is_rejected_even_with_valid_token` |
| ImageUploaderTest | `test_rejects_file_with_disallowed_mime`, `test_stores_valid_png_with_random_name` (use a tiny PNG fixture written into a temp dir) |

AuthServiceTest uses a real `LoginThrottle` with a stubbed `LoginAttemptRepositoryInterface` and `new MockClock()`. Add `#[Group('unit')]` and `#[CoversClass]` to every test class (`acc:testing-knowledge`).

**Done when:** `composer test` is green with 22 tests, **run by the main agent** after reviewing the subagent's diff.

## Step 8 — Review and finish (2 min)

1. **PHP hardening sweep** (CLAUDE.md A7). Each of these must be clean:
   - `composer audit` and `npm audit --omit=dev`
   - `grep -rnE '[^=!<>]==[^=]|!=[^=]' src/` (no loose comparisons)
   - `grep -rn 'in_array(' src/`: every call must have `true` as the third argument
   - `grep -rnE 'unserialize|eval\(|extract\(|shell_exec|[^_]exec\(|system\(|passthru|mt_rand|uniqid' src/` (no forbidden functions)
   - `php -c config/php.prod.ini -S …` and then `curl -sI` must show no `X-Powered-By`
   - posting `email[]=x` to `/login` must return 422, not 500
   **SQL injection probe:** search `' OR 1=1 -- ` must give normal results with no error, and `grep -nE '(query|exec|prepare)\(.*\$' src/` must show only static SQL. **CSRF probe:** `curl -X POST` to `/posts/<id>/vote` without a token must return 403. Then run MCP `verify_constraints`, `verify_vote_counts` (it must be `[]`), and `explain_query` for every named query one last time. Then run `acc:audit-security` on `src/`, `views/`, and `public/`. Fix Critical and High findings only. Ask before any structural fix.
2. Click through on a phone width (390) and a laptop width (1440): login, register, comment create/edit/delete as the owner, a delete attempt on someone else's comment (must return 403), admin comment delete, vote, search and older posts, and admin post CRUD.
3. Update CLAUDE.md §B9 with the shortcuts you took and any deviations. Write `README.md` with setup in 10 lines (copy from CLAUDE.md §B8).

## Suggested extras (only if time is left and the user approves)

- Reading time on cards.
- Comment count on cards. Add a `comments_count` counter column updated in the same transaction; don't use `COUNT(*)` per card.
- `rel="next"`/`prev` links and an `og:` meta tag per post.
