# CLAUDE.md

Read this file in full before you touch code. It has two parts:

- **Part A — Engineering rules.** These apply to any PHP + MySQL + TypeScript project. Copy Part A into a new project as is.
- **Part B — This project.** Facts about this repo: stack, structure, schema, routes, env, commands. Rewrite Part B for each new project.

The step-by-step build order is in `docs/IMPLEMENTATION_PLAN.md`. Later approved changes each get their own file, `docs/CHANGES_NNN.md` (currently `docs/CHANGES_002.md` and `docs/CHANGES_003.md`). Implement such a file exactly as written, the same way as the plan.

---

# Part A — Engineering rules

## A1. Change control (most important rule)

1. Part B describes the **approved architecture**: folders, layers, libraries, schema, and routes. Do not change any of these without asking the user first. If you hit a problem that needs a change, **stop**, explain the problem and your proposed fix in 2–3 lines, and wait for an answer.
2. Small changes inside a layer are fine without asking: a private helper, a renamed local variable, or an extra index that serves an existing query.
3. **At the end of every task, update this file.** Add a line to Part B §B9 (Decision log) for anything that changed or was decided. Also update the B-sections that are affected (files, routes, env, schema). If it isn't written here, it didn't happen.
4. If you are unsure about something, ask. Don't guess about features, scope, or security.
5. **Security rules are decided with the user while planning, never by the implementer.**
   - **Planning a new project** (this file reused, before Part B and the plan exist):
     1. Run the security knowledge skills against the planned features: `acc:check-sql-injection`, `acc:check-csrf-protection`, `acc:check-authentication`, `acc:check-authorization`, `acc:check-output-encoding`, `acc:check-input-validation`, `acc:check-type-juggling`, `acc:check-insecure-design`, `acc:check-path-traversal`, `acc:check-secure-headers`, `acc:check-sensitive-data`, and `acc:check-dependency-vulnerabilities`. Add `acc:check-ssrf`, `acc:check-xxe`, `acc:check-command-injection`, or `acc:check-deserialization` only if the project does those things.
     2. List every recommended check that isn't in A7 yet, each with a one-line cost.
     3. **Ask the user which to add** (multi-select). Add only approved items to A7, and record the declined ones in §B9 as known gaps.
   - **Implementing** (this project, or any project whose plan is approved): don't ask again. Follow A7 exactly as written: no extra security rules, and none skipped. If the code reveals a security problem A7 doesn't cover, that counts as an architecture change, so stop and ask per rule 1.

## A2. Skills and tools (required)

| When | Use |
|---|---|
| Planning or reviewing PHP structure | `acc:no-framework-knowledge`, `acc:clean-arch-knowledge`, `acc:solid-knowledge` |
| Creating a repository, use case, middleware, or test | `acc:create-repository`, `acc:create-psr15-middleware`, `acc:create-unit-test` (adapt their output to Part B's structure; **never add their extra layers**: no DDD aggregates, CQRS, buses, or value objects for every field) |
| Security review before finishing | `acc:audit-security` (or `acc:check-sql-injection`, `acc:check-csrf-protection`, `acc:check-authentication`, `acc:check-output-encoding`, `acc:check-path-traversal`) |
| DB design, migrations, queries | `mysql-patterns` (project skill: `.claude/skills/mysql-patterns/SKILL.md`; read it before writing any SQL). Review with `acc:check-index-usage`, `acc:check-query-efficiency`, `acc:detect-n-plus-one`. |
| Test review | `acc:check-test-quality` |
| Any HTML/CSS/client-side TS | `modern-web-guidance:modern-web-guidance`. Search first, then retrieve. Guide ids used here: `dialog`, `forms`, `validate-input-after-interaction`, `accessible-error-announcement`, `autofill-sign-in-form`, `sanitize-untrusted-html`, `security`, `optimize-image-priority`, `dark-mode`, `responsive-design` |
| Visual design | `taste-skill:design-taste-frontend`. Use its **system layer only** (`system.css` and `system.js` tokens, `.wrap`, `.btn`, `.card`, nav, form recipe, hover/press motion, access rules). Skip the marketing-page parts: hero dealing, moments, shader effects, photo quotas, one-section-per-screen, and the gate. This is an app, not a landing page. |
| Inspecting the app or DB while you build | The project MCP server `inkwell-dev` (`bin/mcp-server.php`, built on `php-mcp/server`, registered in `.mcp.json`). It's built in step 1 of the plan; from then on use its tools instead of guessing: `db_schema` before editing SQL, `explain_query` after editing a query (it must hit an index), `verify_constraints` after any schema change, and `list_routes` before adding a link or form action. Every DB claim must be backed by a tool result, not by memory. |
| Docker / local DB | `acc:create-docker-compose-dev`, `acc:docker-compose-knowledge`. MySQL runs **only** in Docker (`docker-compose.yml`); PHP runs on the host. Note: the skill's MySQL template passes `--default-authentication-plugin`, which was removed in MySQL 8.4 and stops the server from starting, and it uses `MYSQL_USER`, which grants ALL. We do neither. |
| Testing rules | `acc:testing-knowledge` (AAA, naming, ≤3 doubles, no logic in tests) |

## A3. Architecture: MVC with a service layer

```
HTTP request → public/index.php (front controller)
  → middleware pipeline (PSR-15): Error → SecurityHeaders → Session → Csrf → Route(+role check)
  → Controller   (HTTP only: read the request, call a service, return render/redirect/json)
  → Service      (business rules, validation, transactions, cache)
  → Repository   (interface → PDO implementation; the ONLY place SQL lives)
  → Model        (final readonly data classes + enums, no I/O)
View = Twig templates (autoescaped). Frontend TS = progressive enhancement only.
```

Dependency direction is one way: Controller → Service → Repository interface ← PDO repository. Model depends on nothing.

- **Controllers** do not touch SQL, `$_POST`, `$_SESSION`, or `header()`. They read the PSR-7 request and the injected `Session` and return a PSR-7 response.
- **Services** know nothing about HTTP: no request or response objects. On failure they throw `ValidationException`, `NotFoundException`, or `ForbiddenException`.
- **Repositories**: prepared statements only, named params, explicit column lists (no `SELECT *`). They return Models, scalars, or a small typed array shape documented with `@return array{…}` (for example `toggle(): array{voted: bool, count: int}`), never raw DB rows.
  - **Exception to "transactions in services":** a write that must be atomic across tables and is pure SQL may own its transaction inside the repository. Examples: post create plus its `post_vote_counts` row, and the vote toggle. The service still owns the business rules around it.
- **Models** are `final readonly class` with typed promoted properties. Use a static `fromRow(array $row): self` to map rows.

## A4. SOLID and DI

- **S**: one reason to change per class. A controller per resource, a service per use-case group, and a repository per table.
- **O**: extend by decorating, not by editing. Example: `CachedMarkdownRenderer` wraps `CommonMarkRenderer`.
- **L / I**: small interfaces with only the methods callers use.
- **D**: services depend on interfaces. All wiring lives in `config/container.php` (PHP-DI). Never call `new` on a service or repository inside another class. Never use a service locator: no `$container->get()` outside bootstrap or the router.
- `declare(strict_types=1);` in every PHP file. PSR-4 and PSR-12 apply. Use `final` by default.
- **Reuse before you add.** Put shared controller helpers (`render`, `redirect`, `json`, `flash`) in `BaseController`. Use one exception family and one layout template. Don't create a file that has only one caller and under ~15 lines. Inline it unless a layer rule requires it.

## A5. Library first: don't build what a library does

Use well-known Composer or npm packages for infrastructure: env, routing, DI, PSR-7, templates, markdown, cache, clock, logging, and tests. If no library does the job (example: exponential login backoff), write the smallest class you can on top of a PSR interface, and log why in §B9. **No ORM or query builder.** SQL is handwritten in repositories. List every library in Part B §B2 with its job.

## A6. Database rules

- InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, **UUIDv7 `BINARY(16)` PKs and FKs** (generated in PHP with `symfony/uid`; `UUID_TO_BIN`/`BIN_TO_UUID` **without** the swap flag; PHP handles only the 36-char string, validated with `Uuid::isValid()` before any query), and `NOT NULL` unless there is a real "unknown".
- Every FK has an index and `ON DELETE RESTRICT`. Rows are never hard-deleted, so the DB refuses any delete that would orphan a child row.
- `UNIQUE` for natural keys (email, slug, (post_id, user_id) votes). Use `CHECK` constraints for lengths and ranges.
- **Every enum is also enforced by the database.** A status, role, or type column is `VARCHAR(n) NOT NULL` with a named `CHECK (col IN ('a','b'))` (for example `chk_posts_status`), and it mirrors a PHP backed enum (`PostStatus::from()`). An insert or update with `'abcd'` must fail in the DB even if PHP is bypassed. Don't use MySQL `ENUM`: in non-strict mode it silently stores `''`. Whenever you add an enum case, change the PHP enum and the CHECK in the same migration.
- The connection forces strict mode: `SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'`. Pass it via `Pdo\Mysql::ATTR_INIT_COMMAND` (the old `PDO::MYSQL_ATTR_*` constants are deprecated in PHP 8.5). All DATETIMEs are UTC.
- Index each `WHERE` / `ORDER BY` pair your queries actually use (composite, in the right column order). Do not add speculative indexes.
- **Never hard-delete.** Every delete is a soft delete:
  - Use `status = 'deleted'` plus `deleted_at`. A CHECK enforces `(status = 'deleted') = (deleted_at IS NOT NULL)`.
  - Record **who** deleted the row in `deleted_by` (FK to `users`, RESTRICT, indexed), with a CHECK `deleted_by IS NULL OR status = 'deleted'`. The service always passes the acting user. NULL is allowed only for rows deleted before the column existed (a real "unknown").
  - Uploaded files are different: an uploaded file is **deleted as soon as no live post uses it**, either as a cover or as an inline image in a body. This happens when the cover is replaced or removed, when the body drops the image, or when the post is soft-deleted, and it runs *after* the DB write so the post's new state counts. A file still used by another live post is kept. (Change set 003; this reverses 002's "never delete files".)
  - Toggles (votes) flip a status (`'active'`/`'removed'`) with one atomic upsert.
  - Every read filters on status, and the status column is in the index the read uses.
  - The runtime DB user has **no DELETE grant**, so a hard delete fails even if code tries one.
- **No derived values stored on a parent row** (3NF). A count lives in its own 1:1 table, for example `post_vote_counts(post_id PK/FK, upvotes INT UNSIGNED)`:
  - It is created in the same transaction as the parent and changed only in the same transaction as the child write (fixed lock order: child row first, then counter).
  - It is guarded by `UNSIGNED` under strict mode, and checked for drift by MCP `verify_vote_counts`.
  - Child writes never touch the parent row, so `posts.updated_at` and the cache keys built from it stay correct.
- Atomic counters (for example login failures) use one upsert, `INSERT … AS new ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(n + 1)`, and read the new value from `lastInsertId()`. Never read, modify, then write from PHP.
- Avoid N+1 queries. A list page uses one query (JOIN or aggregate).
- Use **keyset pagination** for lists that grow (`WHERE (sort_col, id) < cursor ORDER BY sort_col DESC, id DESC LIMIT n+1`), backed by an index that matches the cursor. Don't use deep `OFFSET`. Bind `LIMIT` as an int.
- **Concurrency rules** (verified with two live connections):
  - Isolation is `REPEATABLE-READ`, set in `docker/mysql/conf.d/app.cnf`. Never use `READ UNCOMMITTED`, so dirty reads are impossible.
  - Counters change only through `SET col = col + :delta` inside SQL. That is a locking current read, so a concurrent writer **waits** (verified `1205` while held) instead of overwriting. The result: no lost updates.
  - Never read a value into PHP, change it, and write it back.
  - A check-then-act flow that spans several statements (login) is serialized with `GET_LOCK(name, timeout)` and released in `finally`.
- Keep transactions short, with no I/O inside, and always lock tables in the same order. Retry once on deadlock (SQLSTATE 40001 / error 1213).
- Upserts use the MySQL row alias (`VALUES (...) AS new ON DUPLICATE KEY UPDATE col = new.col`), not the deprecated `VALUES(col)`.
- Use least privilege. The runtime DB user has only `SELECT, INSERT, UPDATE` (no DELETE, no DDL). A separate migrate user runs DDL. In prod the app user `REQUIRE SSL`. Never grant `ALL` or `*.*`.
- Ownership rules (who may edit or delete a row) are enforced in the **service**, never only in the view.
- **Migrations** are numbered plain SQL files in `database/migrations/` (`001_init.sql`, `002_...sql`). `bin/migrate.php` applies each one once, in order, and records it in `schema_migrations(version PK, applied_at)`. Never edit a migration after it has been applied; add a new one. Seed data is in `database/seed.sql` and is idempotent.
- Check every new query with `EXPLAIN` (the MCP `explain_query` tool). Red flags: `type: ALL`, `key: NULL`, and `Using filesort` / `Using temporary` on a hot path.

## A7. Security checklist (all required)

### SQL injection (OWASP A03)
- **Native prepared statements only.** PDO uses `ATTR_EMULATE_PREPARES=false` and `ERRMODE_EXCEPTION`, so the SQL text and the data travel separately and input can never become SQL.
- **Every value is bound** with a named placeholder: text, search terms, UUIDs (`UUID_TO_BIN(:id)`), dates, and `LIMIT` (`PDO::PARAM_INT`). Never use string concatenation, interpolation, or `sprintf` to put input into SQL. `query()`/`exec()` are allowed only on fully static SQL.
- **Identifiers are never taken from input.** Table names, columns, and `ORDER BY` directions are fixed in code. An optional clause (for example the keyset cursor) is a static string that code chooses to append; its values are still bound.
- **Validate before querying.** Check UUIDs with the route regex plus `Uuid::isValid()`, statuses with `Enum::tryFrom()`, and ints with a cast. Search text is trimmed, capped at 100 chars, and run in `NATURAL LANGUAGE MODE` (no boolean-operator parsing).
- The DSN sets `charset=utf8mb4`, so the connection charset can't be used to bypass escaping.
- **Limit the blast radius.** The app DB user has no DELETE and no DDL rights. The MCP tools accept whitelisted query **names**, never raw SQL.
- **Review:** run `acc:check-sql-injection` on `src/Repository`, and `grep -nE '(query|exec|prepare)\(.*\$' src/` must show only static SQL. Manual probe: searching `' OR 1=1 -- ` returns ordinary "no results", not every row and not an error.
- **XSS**: Twig autoescape on. Markdown goes through league/commonmark with `html_input: 'escape'` and `allow_unsafe_links: false`. The only `|raw` allowed is that rendered markdown. TS writes `textContent`, never `innerHTML` with data. **The one exception** is the admin editor preview: it puts the HTML returned by `POST /admin/preview` into the preview element with `innerHTML`. That HTML comes from the same CommonMark config, so it is exactly what `|raw` prints on the post page. Markdown is never rendered in the browser.
  - Where the browser has the Sanitizer API, insert it with `setHTML()` and an **explicit allow-list** of the elements CommonMark outputs, with `img` limited to `src`, `alt` and `title`. The default Sanitizer removes every `<img>` (verified in Chrome 157).
  - Elsewhere (Safari), fall back to `innerHTML`. That is still safe, because the server escapes raw HTML and the CSP blocks inline handlers.
### CSRF protection (OWASP A01)
- **Synchronizer token.** Each session gets a 32-byte token (`bin2hex(random_bytes(32))`). It is **rotated on login and logout** together with `session_regenerate_id(true)`.
- **Sent on every state change.** Every HTML form carries a hidden `_csrf` field. Every fetch/AJAX call sends `X-CSRF-Token`, read from `<meta name="csrf-token">` by the one TS fetch helper.
- **Checked centrally.** `CsrfMiddleware` checks **every** non-GET/HEAD/OPTIONS request with `hash_equals`: forms, AJAX JSON endpoints, uploads, and logout. A missing or wrong token gets 403 (JSON for fetch), and nothing changes.
- **Defense in depth.** The same middleware rejects any unsafe request whose `Sec-Fetch-Site` is `cross-site`, or whose `Origin` header is present and differs from `APP_URL`'s origin, **even with a valid token**.
- The session cookie is `SameSite=Lax`.
- **No state change on GET.** Every write route in §B5 is POST.
- No CORS headers are sent, so other sites can't read our JSON responses.
- **Tests:** `CsrfMiddlewareTest` covers a missing token, a valid token, and a cross-site origin.
- **Auth**: `password_hash(PASSWORD_ARGON2ID)` and `password_verify`. Each hash gets its own random salt, generated by PHP and embedded in the hash. Never supply a salt or roll your own hashing. Login attempts per IP+account are serialized with a MySQL `GET_LOCK`. Call `session_regenerate_id(true)` on login and logout. Login failures get a generic message so attackers can't tell which emails exist. Login is throttled per IP+email with **exponential backoff**, stored in the `login_attempts` table. The failure count is incremented atomically in SQL, so parallel guesses can't race past it. A cache wouldn't be atomic and would be lost on a clear. The rule: the `LOGIN_FREE_ATTEMPTS`-th failure (default 5) locks for `LOGIN_BACKOFF_BASE_SECONDS` (60 s), and each further failure doubles it (120, 240, …), capped at `LOGIN_BACKOFF_MAX_SECONDS` (default 7200 = 2 h). The throttle key is built from the **account id** once the user is found (never from the typed email), because `utf8mb4_unicode_ci` treats `ädmin@` as `admin@`. A success resets the counter (`UPDATE failures = 0, locked_until = NULL`; no delete). After a quiet period longer than the max lock, the next failure starts again at 1.
  - **Per-IP limit across all accounts.** A second counter in the same table, key `sha1('ip:' + ip)`, uses the same atomic upsert and backoff. Its free failures are `LOGIN_IP_FREE_ATTEMPTS` (default 20; higher because many real users can share one IP). It stops one IP that is locked on account A from moving on to account B.
  - A success resets **only** the account counter, never the IP counter. Otherwise an attacker could log into their own account between guesses and wipe it.
  - `GET_LOCK` is taken on the IP key first, then the account key (fixed order), and released in reverse order in `finally`.
  - There is no per-account limit across all IPs, by design: it would let anyone lock a real user out.
  - The password policy regex comes from env.
- **Session cookie**: `HttpOnly`, `SameSite=Lax`, `Secure` in prod, `use_strict_mode=1`, and a custom name.
- **Authorization**: role checks happen on the server in `RouteMiddleware`, using the route's required role through `Role::satisfies()`. This is a hierarchy: **admin satisfies user**, so admins can also comment, vote, moderate, and log out. Never compare roles with `===` for access. The UI can hide buttons, but hiding a button is not a security control.
- **Uploads**: enforce the size limit from env, check MIME with `finfo` against the whitelist from env, and verify with `getimagesize`. Use a random filename with the extension taken from the MIME type. Store files in `public/uploads/`, and don't let PHP execute anything there. Deleting an unused upload goes through `ImageUploader::delete()` only. It acts only on a path that exactly matches a generated name (`^/uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$`) and uses `basename()`, so no path from input can reach `unlink` outside the upload folder, and the default cover (`/assets/…`) is never touched. This is the only `unlink` in `src/`.
- **Headers**: strict CSP (`default-src 'self'`, no inline script), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `frame-ancestors 'none'`, and HSTS in prod.
- **Errors**: details only when `APP_DEBUG=true`. In prod, show a generic page and log through Monolog.
- **Mass assignment**: services pick fields explicitly and never pass a request array through unfiltered.
- Route id params must match the UUIDv7 regex (`[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}`), so anything else is a 404 before a query runs.

### PHP-specific hardening (user-approved; from `acc:check-type-juggling` and `acc:check-insecure-design`)
- **Type juggling.**
  - Use `===` / `!==` everywhere. Pass `in_array(…, true)` and `array_search(…, true)`. Use `match` instead of `switch` on input.
  - Never compare tokens or hashes with `==`; use `hash_equals` (magic hashes like `'0e…' == '0e…'` are true).
  - Use `str_starts_with`/`str_contains` instead of `strpos(...) == 0`. Check `preg_match(...) === 1`, because it returns `false` on error.
- **Request shape.** `BaseController::body($request, $keys)` returns only the expected keys, each **checked with `is_string`**. An array or bool sent for a scalar field (`email[]=x`, a JSON `true`) is a 422, never a `TypeError` 500 and never a loose match. Decode JSON only with `json_decode(..., true, 32, JSON_THROW_ON_ERROR)`.
- **Forbidden functions:** `unserialize` on any external data, `eval`, `extract`, `exec`/`shell_exec`/`system`/`passthru`/backticks, `assert` with strings, `rand`/`mt_rand`/`uniqid` for anything security-related, and `md5`/`sha1` for passwords. Use `random_bytes` for randomness. The only allowed `sha1` builds the throttle key, which isn't a secret.
- **Dependencies (A06).** `composer audit` and `npm audit --omit=dev` must report nothing before finishing.
- **Prod PHP config** (`config/php.prod.ini`): `expose_php=Off` (no `X-Powered-By`), `display_errors=Off`, `log_errors=On`, `session.use_only_cookies=1`, `session.use_trans_sid=0`, `session.use_strict_mode=1`, and `opcache.validate_timestamps=0`. The prod session cookie is named **`__Host-inkwell_sid`**, so the browser enforces Secure, path `/`, and no Domain.
- **Server-side idle timeout.** `Session` stores `last_seen` on every request. If more than `SESSION_LIFETIME` seconds have passed, it calls `logout()` (destroy plus regenerate) before anything else runs. Expiry never depends on the cookie's own lifetime.
- **Security logging (A09).** Log failed logins, lockouts, 403s (authorization and CSRF), and 422s on auth forms. Each entry is a Monolog line with `event`, `ip`, and `user_id`. **Never** log passwords, tokens, CSRF values, session ids, or full request bodies.
- **Prod admin seed guard.** With `APP_ENV=prod`, `bin/migrate.php` refuses to seed the admin when `ADMIN_PASSWORD` is empty, equals the `.env.example` value, or fails `PASSWORD_REGEX`. It exits non-zero with a clear message.
- **Not applicable here, by design:** SSRF (the app makes no outgoing requests), XXE (no XML parsing), command injection (no shell calls), and deserialization (no `unserialize`). If a feature ever adds one of these, it needs its own check: `acc:check-ssrf`, `acc:check-xxe`, `acc:check-command-injection`, or `acc:check-deserialization`.

## A8. Testing rules

- PHPUnit, **unit tests only**, for services and security middleware. Mock repository **interfaces** with `createStub` / `createMock`. Never mock final Models; build them. Use at most 3 doubles per test. Prefer real in-memory infrastructure (`ArrayAdapter` cache, `MockClock`) to mocks.
- Every test class is `final`, with `#[Group('unit')]` and `#[CoversClass(...)]`.
- **Only the tests that matter:** one happy path and the important unhappy paths per behaviour. Example: login works, login fails with the wrong password. Don't test getters, framework glue, or every permutation.
- Readable: the test name is a sentence (`test_login_fails_with_wrong_password`), the body is Arrange / Act / Assert separated by blank lines, there is one behaviour per test, and there are no loops or conditionals in tests.
- If you need throwaway tests to try something out, put them in `tests/_scratch/`. It is git-ignored and excluded from `phpunit.xml`, so delete them or leave them there.

## A9. Frontend rules

- Pages are rendered on the server with Twig. TypeScript **enhances them with AJAX so no action reloads the page**. Every form still works without JS (progressive enhancement), because the same endpoint falls back to redirect / re-render.
- **One AJAX contract for every endpoint.** If the request has `Accept: application/json`, the controller answers with JSON. Otherwise it answers with the normal redirect / re-render. `BaseController::respond()` and `respondInvalid()` decide this, so no controller branches on its own.
  - Success: `200 {ok: true, ...data, redirect?: "/internal/path"}`
  - Validation failure: `422 {ok: false, errors: {field: message}}`
  - Not logged in: 401. Forbidden or a CSRF failure: 403. Not found: 404. All JSON, produced by `ErrorMiddleware` / `RouteMiddleware`.
  - JSON responses send `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-store`, encoded with `JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP`.
- **Client-side rendering from JSON is safe by construction.** Clone a `<template>` and fill it with `textContent` / `setAttribute`. Never put data into `innerHTML`; the only exception is the server-rendered markdown preview (A7, XSS). URLs taken from JSON must start with `/` but not `//`.
- **Third-party UI libraries** (for example the markdown editor) are bundled from npm by esbuild and loaded only on the pages that use them. Never load them from a CDN, because the CSP is `'self'`. Turn off every option that downloads from a CDN or writes `style=""` attributes.
- TS runs in `strict` mode with no `any`. Define typed response interfaces plus a runtime type guard for every JSON response, and keep one typed fetch helper for all requests (it adds the CSRF header and parses and guards the JSON).
- Use native platform features: `<dialog>` for confirms, `:user-invalid` with `aria-invalid` for errors, and `loading="lazy"` and `fetchpriority` for images. This project's look is light only (taste-skill reserves dark grades for tech sites), so set `color-scheme: light`.
- Mobile-first and responsive at 390 / 768 / 1024 / 1440: one column on phones, 44px tap targets, and no horizontal scroll.

## A10. Config and environments

- Use one env loader (`vlucas/phpdotenv`). It loads `.env` first, then `.env.{APP_ENV}`, and never overrides real server env vars (that's how prod secrets get in).
- Every tunable lives in env: DB, debug, cookie flags, password regex, upload limits, rate limits, cache driver, and page size. No magic numbers in code. Read env **only** in `config/settings.php`, then inject typed values.
- Environments are `local` (your machine), `dev` (shared dev server), and `prod`. Only `*.example` and placeholder files are committed. Real secrets are never committed.

## A11. Definition of done (every task)

`composer test` is green, `npm run typecheck` is clean, the security checklist (A7) has been reviewed, and this file is updated (A1.3).

---

# Part B — This project: "Inkwell" simple blog

## B1. Scope

- **Admin**: logs in; creates, edits, and deletes posts (title, markdown body, cover photo, inline media upload, draft or published); deletes **any** comment (moderation).
  - The body uses a markdown editor (EasyMDE): a toolbar, drag/paste image upload, and a Preview / Side-by-side view rendered by the server.
  - A cover can be replaced or removed, and the old image is deleted from `public/uploads/` once no live post uses it (003). A post without a cover shows the default image (`DEFAULT_COVER`).
  - Every soft delete records who did it (`deleted_by`).
- **User**: registers and logs in. Must be logged in to comment or upvote (toggle, one per user per post).
- **Comments are full CRUD**: create (logged in), read (everyone, on the post page), update (**owner only**), and delete (**owner or admin**). Edited comments show an "edited" label.
- **Anyone**: reads published posts, uses the simple search (MySQL FULLTEXT on title and body), and pages through results with "Older posts" (keyset).
- Out of scope unless the user approves: tags, password reset, email, an API, comment threads/replies.

## B2. Libraries (where we use a library instead of writing it)

| Need | Library | Notes |
|---|---|---|
| Env files | `vlucas/phpdotenv` | `.env` + `.env.{APP_ENV}` |
| Routing | `nikic/fast-route` | dispatched inside our `RouteMiddleware` |
| DI container (PSR-11) | `php-di/php-di` | autowire + explicit interface bindings; compiled in prod |
| PSR-7 / PSR-17 | `nyholm/psr7`, `nyholm/psr7-server` | request from globals |
| Middleware pipeline (PSR-15) | `relay/relay` | |
| Response emitter | `laminas/laminas-httphandlerrunner` | `SapiEmitter` |
| Templates | `twig/twig` | autoescape html; cache in prod |
| Markdown | `league/commonmark` | `html_input: escape`, `allow_unsafe_links: false` |
| Cache (PSR-16) | `symfony/cache` | filesystem (local/dev), APCu (prod), via `CACHE_DRIVER` |
| UUIDv7 ids | `symfony/uid` | `Uuid::v7()`, `Uuid::isValid()` |
| Clock (PSR-20) | `symfony/clock` | `NativeClock` in app, `MockClock` in tests (used by `LoginThrottle`) |
| Logging (PSR-3) | `monolog/monolog` | `var/log/app.log` |
| MCP dev server | `php-mcp/server` | `bin/mcp-server.php` (dev only) |
| Tests | `phpunit/phpunit` | unit only |
| Database | Docker `mysql:8.4` (LTS) | `docker-compose.yml`, `docker/mysql/conf.d/app.cnf` (strict mode, UTC, slow log), `docker/mysql/init/01-users.sh` (least-privilege users) |
| TS build | `typescript` (typecheck), `esbuild` (bundle) | no frontend framework |
| UI system | taste-skill `system.css`/`system.js` + `motion.css`/`motion.js` | copied to `public/assets/vendor/taste/`; look dealt once (service kind, `warm-paper-walnut` palette, Schibsted Grotesk), tokens at the top of `app.css` |
| Font | `@fontsource-variable/schibsted-grotesk` (OFL) | self-hosted woff2 in `public/assets/fonts/` (the CSP forbids Google Fonts) |
| Markdown editor | `easymde` 2.21.0 (MIT; bundles CodeMirror 5) | npm `dependencies`, bundled by esbuild into `public/assets/editor.{js,css}`, loaded only on the admin post form. Options: `autoDownloadFontAwesome: false`, `spellChecker: false`, `forceSync: true`, `previewRender` calls the server |
| Editor icons | `font-awesome` 4.7.0 (font OFL-1.1, CSS MIT) | bundled by esbuild into `public/assets/editor-assets/`; the icon classes EasyMDE's toolbar uses |

**Our own code** (small, on purpose): `CsrfMiddleware`, `RouteMiddleware`, `Session` wrapper, the repositories (SQL), the services, `ImageUploader`, and `LoginThrottle` (exponential backoff on top of `LoginAttemptRepositoryInterface`; `symfony/rate-limiter` only offers fixed and sliding windows and token buckets, with no exponential backoff).

## B3. Directory structure (approved; don't add files or folders without asking)

```
blog-test-4/
├── .claude/skills/mysql-patterns/SKILL.md   # DB design skill (project skill)
├── .taste-skill/           # taste-skill deal history (gate closed: GATE: DONE); never re-deal
├── .env                    # APP_ENV only
├── .env.example            # committed: every key, placeholder values
├── .env.local              # git-ignored: local values
├── .env.dev                # git-ignored: shared dev values
├── .env.prod               # git-ignored: prod placeholders (real secrets come from server env)
├── .gitignore
├── .mcp.json               # registers the inkwell-dev MCP server
├── CLAUDE.md               # this file
├── README.md               # setup in ~10 lines (copied from §B8)
├── composer.json
├── docker-compose.yml      # MySQL 8.4 only (verified)
├── package.json
├── phpunit.xml
├── tsconfig.json
├── bin/
│   ├── migrate.php         # pending migrations + seed (migrate user, prod admin guard)
│   └── mcp-server.php      # php-mcp/server over stdio (refuses APP_ENV=prod)
├── config/
│   ├── container.php       # PHP-DI definitions + makePdo($user, $pass)
│   ├── php.prod.ini        # prod PHP hardening
│   ├── routes.php          # FastRoute table + UUID regex constant
│   └── settings.php        # loadEnv() + env → typed array (the only reader of env)
├── database/
│   ├── migrations/
│   │   ├── 001_init.sql    # verified schema (exactly as in the plan)
│   │   └── 002_deleted_by.sql  # deleted_by on posts + comments (change set 002)
│   └── seed.sql
├── docker/mysql/
│   ├── conf.d/app.cnf      # strict sql_mode, UTC, REPEATABLE-READ, slow log (verified)
│   └── init/01-users.sh    # least-privilege app + migrate users (verified)
├── docs/
│   ├── IMPLEMENTATION_PLAN.md
│   ├── CHANGES_002.md      # editor, preview, default cover, deleted_by, per-IP throttle
│   └── CHANGES_003.md      # delete unused images, checkbox CSS fix (30 min)
├── frontend/src/
│   ├── editor.ts           # admin post form only: EasyMDE + server preview + image upload (change set 002)
│   ├── http.ts             # send<T>() + runtime type guards (all network access)
│   └── main.ts             # data-ajax handlers, dialog, cover preview, remove-cover, default cover
├── public/
│   ├── .htaccess
│   ├── index.php           # front controller
│   ├── assets/
│   │   ├── app.css
│   │   ├── app.js          # built by esbuild, git-ignored
│   │   ├── default-cover.jpg   # the user's default cover (moved from uploads/, committed)
│   │   ├── editor.js, editor.css, editor-assets/   # built by esbuild, git-ignored
│   │   ├── fonts/schibsted-grotesk.woff2
│   │   └── vendor/taste/{system.css, system.js, motion.css, motion.js}
│   └── uploads/.htaccess   # no PHP execution
├── src/
│   ├── Controller/
│   │   ├── AdminPostController.php
│   │   ├── AuthController.php
│   │   ├── BaseController.php      # render, redirect, json, respond, respondInvalid, body
│   │   ├── CommentController.php
│   │   ├── PostController.php
│   │   └── VoteController.php
│   ├── Exception/
│   │   ├── ForbiddenException.php
│   │   ├── NotFoundException.php
│   │   └── ValidationException.php
│   ├── Http/
│   │   ├── Session.php
│   │   └── Middleware/
│   │       ├── CsrfMiddleware.php
│   │       ├── ErrorMiddleware.php
│   │       ├── RouteMiddleware.php
│   │       ├── SecurityHeadersMiddleware.php
│   │       └── SessionMiddleware.php
│   ├── Mcp/
│   │   └── DevTools.php            # db_schema, explain_query, verify_constraints, verify_vote_counts, list_routes
│   ├── Model/
│   │   ├── Comment.php
│   │   ├── CommentStatus.php       # enum visible|deleted
│   │   ├── Post.php
│   │   ├── PostStatus.php          # enum draft|published|deleted
│   │   ├── Role.php                # enum admin|user + satisfies()
│   │   ├── User.php
│   │   └── VoteStatus.php          # enum active|removed
│   ├── Repository/
│   │   ├── CommentRepositoryInterface.php      ├── PdoCommentRepository.php
│   │   ├── LoginAttemptRepositoryInterface.php ├── PdoLoginAttemptRepository.php
│   │   ├── PostRepositoryInterface.php         ├── PdoPostRepository.php
│   │   ├── UserRepositoryInterface.php         ├── PdoUserRepository.php
│   │   └── VoteRepositoryInterface.php         └── PdoVoteRepository.php
│   └── Service/
│       ├── AuthService.php
│       ├── CachedMarkdownRenderer.php
│       ├── CommentService.php
│       ├── CommonMarkRenderer.php
│       ├── ImageUploader.php
│       ├── LoginThrottle.php
│       ├── MarkdownRendererInterface.php
│       ├── PostService.php
│       └── VoteService.php
├── tests/
│   ├── Unit/
│   │   ├── AuthServiceTest.php
│   │   ├── CommentServiceTest.php
│   │   ├── CsrfMiddlewareTest.php
│   │   ├── ImageUploaderTest.php
│   │   ├── LoginThrottleTest.php
│   │   ├── PostServiceTest.php
│   │   ├── RoleTest.php
│   │   └── VoteServiceTest.php
│   └── _scratch/           # throwaway, git-ignored
├── var/                    # git-ignored
│   ├── cache/              # app (PSR-16), twig, di
│   └── log/app.log
└── views/
    ├── layout.twig
    ├── error.twig
    ├── admin/{form.twig, index.twig}
    ├── auth/{login.twig, register.twig}
    └── post/{index.twig, show.twig}   # include <template id="card-tpl"> / <template id="comment-tpl">
```

That's 48 PHP files under `src/`, `config/`, `bin/` and `public/`, 3 TS files, 8 Twig files, 2 migrations, and 8 test files.

## B4. Database (see `database/migrations/001_init.sql`, verified through MCP on MySQL 8.4)

Every FK is `ON DELETE RESTRICT`. Nothing is ever hard-deleted.

| Table | Key points |
|---|---|
| `users` | email UNIQUE; `role` CHECK in (`admin`, `user`) |
| `posts` | slug UNIQUE; `status` CHECK in (`draft`, `published`, `deleted`); `deleted_at` must be set exactly when status is `deleted`; a published post needs `published_at`. Indexes: `(status, published_at)` for the public list and keyset cursor, FULLTEXT `(title, body_md)` for search. The admin list uses keyset on the PK (`id DESC`, which is creation order thanks to v7). **No counter column on posts.** `cover_image` is NULL when there is no cover (the default image is applied when rendering, never stored). `deleted_by` → `users` (002, see below). |
| `comments` | `status` CHECK in (`visible`, `deleted`) plus `deleted_at`; `updated_at` drives the "edited" label. Index `(post_id, status, created_at)` serves the visible-comments list. `deleted_by` → `users` (002). |
| `deleted_by` (posts, comments; `002_deleted_by.sql`) | `BINARY(16) NULL`, FK `fk_*_deleted_by` → `users` RESTRICT, index `idx_*_deleted_by`, CHECK `chk_*_deleted_by` (`deleted_by IS NULL OR status = 'deleted'`). Set by every soft delete; NULL only on rows deleted before 002. Audit only: no app query reads it. |
| `post_votes` | PK `(post_id, user_id)`, one row per user per post; `status` CHECK in (`active`, `removed`). The toggle is one atomic upsert. The counter lives in `post_vote_counts`. |
| `post_vote_counts` | PK/FK `post_id`; `upvotes INT UNSIGNED`. Created with the post; changed only inside the vote-toggle transaction. Read through `LEFT JOIN … eq_ref PRIMARY`. |
| `login_attempts` | PK `throttle_key BINARY(20)`, which is one of three key kinds: `sha1('u:' + user_id + '\|' + ip)` (known account), `sha1('e:' + lower(email) + '\|' + ip)` (unknown email), and `sha1('ip:' + ip)` (per-IP across accounts, 002). Columns `failures`, `locked_until`, `last_failed_at`. No raw email, IP or user id is stored; who and where are in the security log (A09). |
| `schema_migrations` | version PK, applied_at |

## B5. Routes (role: `-` public, `user` logged in, `admin`)

`{id:uuid}` means FastRoute's `{id:<UUIDv7 regex>}` (see A7). It's written out once as a constant in `config/routes.php`.

| Method | Path | Handler | Role |
|---|---|---|---|
| GET | `/` (`?q=&cursor=`; keyset, no page numbers) | PostController::index (HTML, or JSON for AJAX search and Older posts) | - |
| GET | `/posts/{slug}` | PostController::show | - |
| GET/POST | `/login`, `/register` | AuthController | - (guest) |
| POST | `/logout` | AuthController::logout | user |
| POST | `/posts/{id:uuid}/comments` | CommentController::store | user |
| POST | `/posts/{id:uuid}/vote` | VoteController::toggle (JSON, or redirect if no JS) | user |
| POST | `/comments/{id:uuid}` | CommentController::update (the service checks owner) | user |
| POST | `/comments/{id:uuid}/delete` | CommentController::destroy: **soft delete** (the service checks owner or admin) | user |
| GET | `/admin` (`?before=<uuid>`; keyset by id) | AdminPostController::index | admin |
| GET/POST | `/admin/posts/new`, `/admin/posts` | AdminPostController::create/store | admin |
| GET/POST | `/admin/posts/{id:uuid}/edit`, `/admin/posts/{id:uuid}` | edit/update | admin |
| POST | `/admin/posts/{id:uuid}/delete` | destroy: **soft delete** (`status='deleted'`, cover file kept) | admin |
| POST | `/admin/media` | AdminPostController::uploadMedia (JSON `{url, markdown}`; used by the editor's image upload) | admin |
| POST | `/admin/preview` | AdminPostController::preview (field `body` → JSON `{ok, html}`, server CommonMark, not cached) | admin |

## B6. Environment variables (`.env.example`; `.env.local`, `.env.dev`, `.env.prod` override)

`APP_ENV, APP_DEBUG, APP_URL, DB_HOST, DB_PORT (3308), DB_ROOT_PASS (Docker init only), DB_NAME, DB_USER, DB_PASS, DB_MIGRATE_USER, DB_MIGRATE_PASS, DB_SSL_CA, SESSION_NAME, SESSION_SECURE, SESSION_LIFETIME, PASSWORD_REGEX, PASSWORD_HINT, LOGIN_FREE_ATTEMPTS, LOGIN_IP_FREE_ATTEMPTS (20), LOGIN_BACKOFF_BASE_SECONDS, LOGIN_BACKOFF_MAX_SECONDS, UPLOAD_MAX_BYTES, UPLOAD_ALLOWED_MIMES, DEFAULT_COVER (/assets/default-cover.jpg), CACHE_DRIVER, CACHE_TTL, TWIG_CACHE, POSTS_PER_PAGE, COMMENT_MAX_LENGTH, ADMIN_EMAIL, ADMIN_PASSWORD`

## B7. Cache

- Rendered markdown HTML: `CachedMarkdownRenderer` with the key `md.{postId}.{updatedAtTimestamp}`, so an edit makes a new key and nothing needs invalidating.
- The editor preview is **not** cached. It passes a `null` cache key, because every keystroke is a new text.
- Twig compiled templates (`TWIG_CACHE`), the PHP-DI compiled container, and OPcache in prod.
- Login throttle state is **not** cached. It lives in the `login_attempts` table, because it must be atomic and durable.

## B8. Commands

```
composer install && npm install
composer db:up                       # docker compose --env-file .env.local up -d --wait (MySQL 8.4 on 127.0.0.1:3308)
php bin/migrate.php                  # schema + seed (admin from ADMIN_EMAIL/ADMIN_PASSWORD)
npm run build                        # esbuild: main.ts → public/assets/app.js, editor.ts → public/assets/editor.{js,css} + editor-assets/
npm run typecheck                    # tsc --noEmit (strict)
composer test                        # phpunit tests/Unit
composer serve                       # php -S localhost:8000 -t public public/index.php
```

## B9. Decision log (append; newest last)

- 2026-11-20: Architecture, libraries, schema, routes, and env approved (this file plus `docs/IMPLEMENTATION_PLAN.md`).
- 2026-11-20: Inline media is stored as files only (no `media` table). The upload returns a markdown snippet that the admin pastes into the body, which keeps the file count down.
- 2026-11-20 (user request): Login throttling uses exponential backoff with a 2 h cap (configurable in env). `symfony/rate-limiter` is replaced by our own `LoginThrottle` (PSR-16 cache + PSR-20 clock), because no library provides exponential lockout. *(Storage superseded: it moved to the `login_attempts` table, see the "user review: Three design changes" entry, item 3.)*
- 2026-11-20 (user request): Every enum is a VARCHAR plus a CHECK constraint, enforced in the DB. The connection uses strict sql_mode. Migrations are numbered SQL files tracked in `schema_migrations`.
- 2026-11-20 (user request): Comments are full CRUD (owner edits; owner or admin deletes), and `comments.updated_at` is added. The `/comments/{id}/delete` route role changes from admin to user, with ownership enforced in `CommentService`.
- 2026-11-20 (user request): DB design follows the `mysql-patterns` skill: keyset pagination (no COUNT/OFFSET), a row-alias upsert, a least-privilege runtime user plus a separate migrate user, optional TLS (`DB_SSL_CA`), and a one-time deadlock retry on vote toggle. Search is ordered by date, not relevance, so one keyset path serves both lists.
- 2026-11-20: taste-skill is used for its system layer only. Its landing-page gate is not run, because this is an app UI.
- 2026-11-20 (user request): MySQL runs in Docker (`mysql:8.4` LTS, host port 3308, because 3307 is in use on the dev machine). The MCP server is built in plan step 1. The former separate MCP step was merged into step 1; step 8 is now **Review and finish**. Subagents are allowed only when more than 5 minutes behind, with file ownership and a main-agent review.
- 2026-11-20 **Verified through an MCP server** (php-mcp/server 3.3 over stdio, against Docker MySQL 8.4.13):
  - The migration applies cleanly.
  - CHECK constraints reject `status='abcd'`, `role='superuser'`, a published post with no `published_at`, an empty title, and an empty comment.
  - The UNIQUE slug and the vote PK reject duplicates. The author FK RESTRICT blocks the delete. *(Superseded: FKs are now RESTRICT and nothing is hard-deleted, see "Three design changes", item 2.)*
  - The row-alias upsert works.
  - The app user is denied DDL.
  - `updated_at` moves when a comment is edited.
  - EXPLAIN: the listing and the keyset cursor use `idx_posts_status_published` (backward index scan, no filesort, at realistic stats), search uses `ft_posts_search`, and comments use `idx_comments_post_created` *(renamed `idx_comments_post_status_created` when soft delete was added)*.
  - All 14 Composer packages install on PHP 8.5.12. `Pdo\Mysql::ATTR_INIT_COMMAND` and `PASSWORD_ARGON2ID` are available. APCu is **not** installed locally, so the filesystem cache fallback is needed.
- 2026-11-20 (user review): Three design changes, each verified through the MCP server on Docker MySQL 8.4:
  1. **`posts.upvotes_count` is removed.** It was derived data, which breaks 3NF. Every vote also rewrote the post row, which bumped `posts.updated_at` and so changed the markdown cache key `md.{id}.{updated_at}` on every vote. Upvotes are now counted from `post_votes` through its PK (verified: `ref PRIMARY`, about 2 rows per post, and voting leaves `posts.updated_at` unchanged). *(Count-on-read superseded by the `post_vote_counts` table, see the "UUIDv7 … post_vote_counts" entry.)*
  2. **No hard deletes anywhere.**
     - Posts and comments are soft-deleted through status plus `deleted_at`, enforced by a CHECK. Votes toggle `active`/`removed`.
     - All FKs are `RESTRICT`, and the app DB user has no DELETE grant. Verified: `DELETE command denied`, `chk_posts_deleted_at` and `chk_comments_status` reject bad states, and soft-deleted posts are hidden from the public list.
     - The unused `idx_posts_status_created` and `idx_votes_post_status` were removed after EXPLAIN showed they weren't used. The admin list moves to keyset on the PK (`range PRIMARY, Backward index scan`, no filesort).
  3. **Login throttle moves from the cache to the `login_attempts` table.** It uses an atomic upsert with `LAST_INSERT_ID(failures + 1)`. Verified: the count returned is 1→6 across six failures; `GREATEST()` never shortens a lock; a success resets to 0 by UPDATE; after more than 2 h of quiet the count restarts at 1.
- 2026-11-20 (user decision): **UUIDv7 for every PK and FK**, plus a **`post_vote_counts` 1:1 table**. This replaces BIGINT ids and count-on-read. Verified through MCP on Docker MySQL 8.4:
  - PHP `symfony/uid` v7s round-trip through `UUID_TO_BIN` / `BIN_TO_UUID`, and prepared params bind correctly.
  - v7 order equals insert order.
  - Every PK lookup is `const`; the list, the keyset cursor, and the admin keyset still use their indexes with no filesort; the counts join is `eq_ref PRIMARY`.
  - The vote toggle on→on→off→on gives 1, 2, 1, 2 with zero drift.
  - The counter can't go below 0 (`1690`) or exist for an unknown post (`1452`), and the app user can't delete it.
  - A bad UUID string makes MySQL error 1411, so the route regex plus `Uuid::isValid()` are mandatory.
  - Cost: keys are 16 bytes instead of 8, and a popular post's counter row is a hot spot. Both are acceptable at this scale.
  - Plan time: step 3 gets +2 min; steps 5 and 7 give 1 min each.
- 2026-11-20 (user review): **Atomicity and password storage**, verified with two live PDO connections on Docker MySQL 8.4:
  - **No dirty reads.** B saw 0 while A's +1 was uncommitted; isolation is `REPEATABLE-READ`, now set explicitly in `app.cnf`.
  - **No lost updates.** B's concurrent `UPDATE … upvotes = upvotes + 1` waited on A's row lock (1205 with a 1 s timeout), then applied on top, giving 2.
  - **Login race closed.** Check → verify → record was not atomic across statements. It is now serialized per IP+account with `GET_LOCK` (a second holder gets 0, then 1 after release).
  - **Passwords.** argon2id with a random per-hash salt (two hashes of the same password differ; `m=65536, t=4, p=1`).
- 2026-11-20 (user review): The vote toggle's final SELECT moves **out** of the transaction. Writes stay atomic inside it; the read after COMMIT sees only committed data (never dirty, possibly newer) and releases row locks one round-trip earlier. The nested subquery became a `LEFT JOIN` (`const PRIMARY` on both tables). Verified through MCP: 1→2→1→2, `null` for a user who never voted, zero drift.
- 2026-11-20 (user request): **AJAX everywhere, no page reloads.** Every form is `data-ajax`: vote, comment create/edit/delete, admin post create/edit/delete, admin comment delete, login, register, logout, search, and "Older posts". There is one JSON contract (`BaseController::respond` / `respondInvalid`), and progressive enhancement keeps the no-JS fallback working. Plan steps 6 (TS) and 7 (tests) now **always run in parallel**: a subagent owns `tests/` and the main agent reviews it. This pays for the extra TS work inside the 60 minutes.
- 2026-11-20 (user request): **Explicit SQL-injection and CSRF sections** added to A7. CSRF gains token rotation on login/logout and a `Sec-Fetch-Site` / `Origin` check. `CsrfMiddlewareTest` gains a cross-site case, so the plan now has 21 tests.
- 2026-11-20 (user decision): A **PHP-specific hardening** section is added to A7: type juggling rules, a request-shape check, forbidden functions, a dependency audit, `config/php.prod.ini` with a `__Host-` session cookie, a server-side idle timeout, A09 security logging, and a prod admin seed guard.
  - **Declined for now (known gaps):** a register/comment spam rate limit (only login is throttled); GD re-encoding of uploads and dropping SVG/GIF; trusted-proxy handling of the client IP plus the Host-header rule; an explicit open-redirect rule beyond the internal-path check already in `BaseController::redirect`; and extra input size caps.
- 2026-11-20 (user approved): **Final verification** with `acc:audit-documentation` (documentation auditor agent) and `acc:security-reviewer`, followed by a recheck with `acc:check-authentication` and `acc:check-authorization`. Applied:
  - All 20 documentation consistency fixes: keyset routes, a required migrate user, two-pass env loading, `makePdo($user, $pass)`, the comment service returns the slug, an ErrorMiddleware 422 backstop, complete MCP lists, one 13-row constraint table, the lock starting at the Nth failure, superseded markers, a repository-transaction exception, specified cover-preview and media-upload, logout and GET-search handlers, the full directory tree, the CSRF logger, the comment-length note, no OFFSET, and the testing skill.
  - **Security #1:** the throttle key is built from the account id (closes the accent-collation bypass), and the login email is checked with `filter_var`.
  - **Security #2:** `Role::satisfies()` hierarchy (admin ⊇ user), plus `RoleTest`. The plan now has 22 tests.
  - **Not applied (left to the implementer or declined):** security #3 (per-IP limit), #4 (status `deleted` from input), #6 (register race and email enumeration), #7 (empty slug), #8 (editing comments on unpublished posts), #9 (`Vary: Accept`), #11 (single host), #12 (root password in app env, init-script quoting). #5 is covered by doc fix #7, and #10 by doc fix #14.
- 2026-11-21 (user: "decide yourself, easy to understand, not fancy"):
  - **The look was dealt once with taste-skill:** kind `service`, trade `studio` (the dealer has no "blog" trade), main action "Read the latest posts". The **second** palette, `warm-paper-walnut`, was taken over the first (`oxblood-grey`), because the first sets every line of body text in oxblood on grey, which is hard to read and against the user's "not too fancy". The type pairing is the first, Schibsted Grotesk (editorial).
  - Tokens go in `app.css` (the CSP blocks inline `<style>`). The font is self-hosted through Fontsource (the CSP blocks Google Fonts), and the motion layer is copied for hover/press.
  - The deal **armed taste-skill's landing-page gate**. It was closed with `gate.mjs --done`, giving `GATE: DONE`, so its Stop hook won't block Sonnet. The plan forbids re-dealing.
  - Light only. This replaces the earlier "dark mode" item.
  - The step 0 copy path now uses `${CLAUDE_CONFIG_DIR:-$HOME/.claude}`, so it works on any profile.
- 2026-11-21 (implementation, steps 0-8 done; user approved this entry and the four security items below). Verified: `composer test` 22 green (one mutation check on the comment-ownership rule failed the test as it should), `npm run typecheck` and `npm run build` clean, `composer audit` and `npm audit --omit=dev` clean, MCP `verify_constraints` 13/13 PASS and `verify_vote_counts` `[]`, and a headless-Chrome click-through (user flow at 390 px: 21 checks, admin flow at 1440 px: 12 checks, no page reloads).
  - **Deviations and decisions:**
    1. `PASSWORD_REGEX` and `PASSWORD_HINT` are single-quoted in the env files (phpdotenv rejects `\d` inside double quotes).
    2. `makePdo(array $db, string $user, string $pass)` takes the DB settings first.
    3. `RouteMiddleware` has no logger. 403s are logged in `ErrorMiddleware`.
    4. Repository SQL lives in public constants that `DevTools::explainQuery` reuses, so the two cannot drift.
    5. List queries return `'' AS body_md` (cards do not need the body).
    6. The non-search list uses `FORCE INDEX (idx_posts_status_published)`. With almost every post `published`, MCP EXPLAIN on 300 posts showed `type ALL` + `Using filesort`; now it is a backward index scan with no filesort. Search keeps the FULLTEXT index.
    7. taste `system.js` and `motion.js` load as `type="module"` (they are ES modules; step 5 said "deferred").
    8. `CommentService` add/update/delete return a `Comment` (it carries the post slug). `PostService` gained `listForAdmin` and `getForEdit`. `VoteService` gained `hasVoted`.
    9. No password rehash on login: `UserRepositoryInterface` has no update method.
    10. The nav has a Search link to `/#search`. The search form itself is on the index page.
    11. `BaseController::respond()` has a `$navigates` flag, so a flash survives an AJAX redirect (login, logout, post save).
    12. `AuthService` uses a fixed `DUMMY_HASH` constant. Hashing a dummy per request made unknown emails about twice as slow as known ones (timing oracle).
    13. `config/settings.php` reads each variable through `envValue()`: `getenv()` first, then `$_ENV`, then `$_SERVER`. Under a web SAPI a real server variable is visible only through `getenv()` (PHP's default `variables_order` has no `E`), and phpdotenv is immutable, so reading `$_ENV` first made the file beat the real variable. `dotenv()` builds the loader with a `PutenvAdapter` so it sees real variables and `required()` works with secrets that exist only in the server environment. A10 ("real server env vars win") now holds under `php -S`, CLI and FPM; verified with `SESSION_LIFETIME=2` (idle timeout fires after 3.5 s idle).
    14. Auth forms log malformed bodies (`auth.login_malformed`, `auth.register_malformed`) and the GET_LOCK timeout (`auth.lock_timeout`), as A7 requires for 422s on auth forms. The vote body is validated before the vote flips.
    15. The `<dialog>` confirm is one shared element in `layout.twig`. App pages replace the kit's `main > section` height and padding with plain page spacing (`main > .page` in `app.css`).
    16. Fixed after a no-JavaScript pass over every form: `BaseController::respondInvalid` called `reset()` on a readonly property, so a blank comment without JS gave a 500 instead of a flash and redirect. The edit `<summary>` is now 44 px wide as well as tall (A9).
  - **Security audit** (`acc:audit-security` via the read-only `acc:security-reviewer` agent): 0 Critical, 0 High. User approved these four changes, now applied:
    1. **Headers on error pages:** `SecurityHeadersMiddleware` is now outermost in `public/index.php` (before `ErrorMiddleware`), so 403/404/422/500 pages carry the CSP and nosniff. The A3 diagram and plan step 2 still say "Error first"; the code is the source of truth here.
    2. **Admin seed guard:** `bin/migrate.php` refuses an empty, example-value or regex-failing `ADMIN_PASSWORD` in every env except `local` (was prod only). `AuthService::login` rejects an empty password. `.env.dev` has a non-example placeholder.
    3. **Redirect rule:** one rule (single leading `/`, no backslash, no control character) for header redirects, the JSON `redirect` value (`BaseController::safePath`) and `isInternalPath` in `http.ts`.
    4. **Uploads and prod ini:** `public/uploads/.htaccess` allows image extensions only, sets `nosniff` and a sandbox CSP, and wraps `php_flag` in `<IfModule mod_php.c>`. `config/php.prod.ini` adds `zend.exception_ignore_args = On`.
  - **Known gaps (unchanged from earlier decisions, confirmed by the audit):** register email enumeration; throttle key uses the full client IP, so one IPv6 /64 gives many counters; the role is cached in the session until logout; comments on unpublished posts can still be edited or deleted; no spam rate limit on register/comment. Under `php -S` the uploads `.htaccess` is not read, so use Apache/nginx rules there.
  - **Subagents:** step 7 tests (reviewed by the main agent; it skipped the three required test skills, so `acc:check-test-quality` and `acc:testing-knowledge` were run by the main agent and three try/catch blocks became a helper) and the read-only security audit above.
  - **Dev data:** the local DB was reset to the seed state (one admin, one welcome post) after testing.

- 2026-11-21 (post-build review, Opus): The review used the MCP tools (`verify_constraints` 13/13, `verify_vote_counts` `[]`, `list_routes` matches §B5, `explain_query` on all queries using a temporary 300-post dataset, which was removed afterwards) and applied the checklists of mysql-patterns, `acc:check-sql-injection`, `acc:check-type-juggling`, `acc:check-authorization`, `acc:check-query-efficiency`, `acc:clean-arch-knowledge` and `acc:solid-knowledge`. Result: 0 critical, 0 high. **Deferred by the user (future work, not now):**
  1. The markdown cache key uses `posts.updated_at` at one-second precision, so two saves within one second show the older render. Fix: key on a hash of `body_md`.
  2. `uniqueSlug` checks and then inserts, so two same-title posts saved at the same moment give a 1062 → 500. Fix: catch 1062 and retry with the next suffix.
  3. The §A3 diagram still shows `Error → SecurityHeaders`; the code (and the entry above) has SecurityHeaders outermost.
- 2026-11-21 (user approved; **done**, see `docs/CHANGES_002.md`): change set 002. The implementation result and the two deviations are the last bullets of this entry.
  1. **Markdown editor:** EasyMDE 2.21.0 plus Font Awesome 4.7 icons, bundled by esbuild and loaded only on the admin post form.
     - Checked: the bundle builds (editor.js about 341 KB) and typechecks once `noUncheckedSideEffectImports: false` is set.
     - CDN features (Font Awesome download, spell checker, guide) and `previewImagesInEditor` (it writes `style=""`) are turned off for the CSP.
     - The separate "insert image" field is replaced by the editor's upload, which still goes through `/admin/media`.
     - **Skill review of the plan** (`acc:check-output-encoding`, `acc:check-dependency-vulnerabilities`):
       - `npm audit --omit=dev` on easymde 2.21.0 + font-awesome 4.7.0 (resolves codemirror 5.65.21, marked 4.3.0, codemirror-spell-checker 1.1.2) found 0 vulnerabilities.
       - Fix added: EasyMDE's default `errorCallback` is `alert()` (also for "file too large", with the file name), so the plan sets `errorCallback` to write `#body-err` with `textContent`.
       - The plan's exact `editor.ts` was typechecked and bundled.
       - Accepted: `marked` is old but unused (the preview goes to the server), and CodeMirror 5 and Font Awesome 4 are in maintenance with no advisories.
     - **`modern-web-guidance` review** (`sanitize-untrusted-html`, `accessible-error-announcement`, `forms`). The user approved 3 of 4 fixes:
       - (a) The editor is named by the visible label (`aria-labelledby="body-label"`). `aria-invalid` and the `aria-describedby` error link are set only while `#body-err` shows. After a failed save, focus moves into the editor unless an earlier field already has it. The font size is ≥ 16 px, so iOS doesn't zoom.
       - (b) Upload errors go to the form's `role="status"` line.
       - (c) The preview uses `setHTML()` with a CommonMark allow-list, falling back to `innerHTML`.
       - **Declined:** a DOMPurify fallback for Safari.
       - The plan's `editor.ts` passed 16/16 checks in headless Chrome 157 under the app's CSP, with fake endpoints.
       - Finding: the default Sanitizer drops every `<img>`, so the allow-list is required.
       - Chrome logs a `script-src-attr` report while parsing HTML that contains an inline handler, even inside `setHTML`. Nothing runs, and our server output never contains one.
     - **Current frontend smoke test** (headless Chrome against the running app, 29 checks at 390 and 1440 px):
       - No CSP violations, JS errors or horizontal scroll, and tap targets are ≥ 44 px.
       - Search, register-422 (with focus and `aria-invalid`), login, vote, comment add/edit/delete (with the `<dialog>` confirm) and logout all work by AJAX with no page reload.
       - The test comments and vote were deleted afterwards, and `verify_vote_counts` returned `[]`.
       - Noted (same cause as deferred item 1): a comment edited within the same second it was created shows no "edited" label, because `updated_at` has one-second precision.
  2. **Preview:** `POST /admin/preview` renders with the server's CommonMark (not cached), so the preview matches the live page. This is the only TS `innerHTML` (A7/A9 updated). Picked over rendering markdown in the browser.
  3. **`deleted_by`** on posts and comments (migration 002). Verified on a scratch DB in Docker: it applies while a legacy deleted row exists; the owner and admin deletes are recorded; the CHECK (3819) and FK (1452) reject bad rows; the comment-list index is unchanged. NULL means the row was deleted before 002. `verify_constraints` goes to 15 cases.
  4. **Per-IP login limit** (closes "IP X locked on account A moves on to account B"; supersedes declined security #3): a `sha1('ip:'+ip)` counter with `LOGIN_IP_FREE_ATTEMPTS=20`, the same backoff, and never reset by a success. `GET_LOCK` is taken on the IP key, then the account key (two named locks per session verified as `inkwell_app`).
     - **Declined:** a per-account limit across all IPs (anyone could lock a real user out).
     - **Still a known gap:** a distributed many-IP attack on one account, and IPv6 /64 rotation.
  5. **Covers:**
     - Files are never deleted: `ImageUploader::delete` and its `unlink` are removed.
     - A "Remove cover" checkbox is added.
     - A post with no cover shows the user's image, moved from `public/uploads/default.jpg` to `public/assets/default-cover.jpg` (`DEFAULT_COVER`, a Twig global plus a meta tag for AJAX cards). `cover_image` stays NULL in the DB.
  6. **Last login IP/user in `login_attempts`: declined** (user agreed). The counter table keeps one row per key, so it would be a poor history. Failed logins and lockouts are already logged with `ip` and `user_id` (A09).
  - **Tests:** 22 → 24 (an IP lock refuses even a correct password; remove-cover clears the cover and keeps the file). The `CommentServiceTest` admin-delete test now also checks `deleted_by`.
  - **Implementation result (all 6 sections, in order):** migration `002_deleted_by` applied (second run applies nothing); `composer test` 24 green; `npm run typecheck`, `npm run build` (`app.js`, `editor.js`, `editor.css`, `editor-assets/`), `composer audit` and `npm audit --omit=dev` clean; no `unlink(` or `uploader->delete` in `src/`; in TS `innerHTML` appears only in `editor.ts`'s `showPreview()` fallback; new files are exactly `002_deleted_by.sql`, `editor.ts` and `default-cover.jpg`.
    - **DB:** `verify_constraints` 15/15 PASS and `verify_vote_counts` `[]` (run through `DevTools` directly; re-run through the live MCP tools after the server reconnects); `explain_query comments_for_post` still uses `idx_comments_post_status_created`; `list_routes` shows `POST /admin/preview`, admin; owner and admin comment deletes record their own ids in `deleted_by` (the one older deleted row stays NULL, as designed).
    - **Per-IP limit** (real HTTP, 4 failures on each of 5 emails from one IP): the IP counter went 4, 8, 12, 16, 20 and locked at 20 while no account key locked; the attacker's own successful login left it unchanged; a 6th email and the correct admin password were both refused; `auth.ip_locked` was logged. The new unit test fails when the IP lock is ignored (mutation check).
    - **Browser** (headless Chrome, 1440 px: 31 checks, 390 px: 32 checks): the editor mounts with bundled icons and no CSP violations; Preview and Side-by-side show the server HTML (`<script>` stays inert text, the image is kept); an image paste uploads and inserts `![](/uploads/…)`; a non-image upload shows its error in the status line with no `alert()`; an empty-body save sets `aria-invalid`, links `#body-err` and moves focus into the editor; a post without a cover shows `/assets/default-cover.jpg` on the list, the post page and AJAX "Older posts" cards while `cover_image` stays NULL; "Remove cover" clears the cover and the old file stays on disk; deleting a post from the admin list records the admin in `deleted_by`.
  - **Deviations from the change set (both CSS, in `app.css`; the design did not change):**
    1. The toolbar rule is `.EasyMDEContainer .editor-toolbar button { min-width: 44px; … }`, one class more than written. EasyMDE's own `min-width: 30px` has the same specificity and `editor.css` loads after `app.css`, so the written rule left nine toolbar buttons 30 px wide on phones. Now measured at 44 px.
    2. Added `.EasyMDEContainer .prose { margin-block: 0; }`. The preview pane carries the page's `.prose` class (`previewClass`), whose 32 px margin pushed the absolutely positioned preview down and showed the editor text under it.
  - **Unchanged:** B1–B8 already match what was built. The A3 diagram still shows `Error → SecurityHeaders` (deferred item 3 above).
- 2026-11-21 (user request; **done**, see `docs/CHANGES_003.md`, 30-minute limit): change set 003. The implementation result is the last bullet of this entry.
  1. **Delete unused images** (reverses 002's "files are never deleted"). An `/uploads/…` file is deleted once no post with status other than `deleted` uses it, as a cover or inline in a body. It's checked after the DB write when the cover is replaced or removed, the body drops the image, or the post is soft-deleted.
     - The in-use query `UPLOAD_IN_USE` (`cover_image = :path OR LOCATE(:path2, body_md) > 0`) was run as `inkwell_app` on the live Docker DB: EXPLAIN gives `range` on `idx_posts_status_published`. It reads `body_md` of live rows, which is accepted because it runs only on admin saves and deletes.
     - It correctly marked 6 of the 7 local upload files as in use.
     - `ImageUploader::delete()` acts only on generated names (32 hex + extension) via `basename()`.
     - **Known gaps:**
       - Images uploaded in the editor but never saved in a post.
       - Files orphaned before 003 (one locally: `e48a335f….jpg`).
       - Soft-deleted posts can't be restored with their images (no restore feature exists).
       - Two admins saving at the same moment (single admin).
  2. **Checkbox CSS fix** (user screenshot). `system.css` styles every `.field input` as a text box, so the "Remove cover" checkbox rendered as a 44 px box on its own line with its text below.
     - Fix: three `app.css` rules (an inline flex label with a 44 px row, a 20 px native box with `accent-color`, and a `.field` column of `minmax(0, 1fr)` so the editor can't push the field past the gutter).
     - Verified by injecting the rules into the real edit page in headless Chrome at 1440 and 390 px.
  - Tests: 24 → 26 (the remove-cover test now expects the file deleted; one new test keeps a file still used by another post; one checks that `delete()` ignores paths that aren't generated names).
  - **Parallel plan** (user short on time): subagent A owns CSS and `ImageUploader` plus its test; subagent B owns the repository, `DevTools` and the MCP description; the main agent writes `PostService` and its tests, then reviews A and B and runs the verification. That's about 15 minutes instead of 30. No two streams touch the same file.
  - **Implementation result (all sections; no deviations from the doc):**
    - **Streams:** A (CSS, `ImageUploader`, its test), B (`isUploadInUse`, `UPLOAD_IN_USE`, the `DevTools` whitelist entry) and M (`PostService` and its tests) ran as planned, and the main agent reviewed both diffs against the doc. Subagent A did not invoke the required skills (`acc:check-path-traversal` and others), so the main agent reviewed its `unlink` path with that checklist: the strict `^/uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$` match plus `basename()` means no input path can leave the upload folder, and a trailing newline gives a file name that does not exist. No new files; `bin/mcp-server.php` needed no change (it lists no query names).
    - **Tests:** 26 green. Two mutation checks failed the right test each: removing `deleteUnused` fails the delete test, and ignoring `isUploadInUse` fails the keep-if-used test. Typecheck, build, `composer audit` and `npm audit --omit=dev` clean. `unlink(` appears once in `src/`, in `ImageUploader::delete`.
    - **DB:** `explain_query upload_in_use` is `range` on `idx_posts_status_published` (run through `DevTools` directly; the live MCP session was stale, so re-run it after `/mcp` reconnects). `verify_constraints` 15/15 and `verify_vote_counts` `[]`. On the real files the query marked 6 of 7 as in use and only the pre-003 orphan `e48a335f….jpg` as unused.
    - **End to end over HTTP as the admin:** replacing a cover deletes the old file; "Remove cover" deletes it and sets the cover to NULL; dropping an inline image from the body deletes it; an image still used by another live post is kept (and deleted when that last post is deleted); deleting a post removes its unused cover; the default cover and the other uploads were never touched. Test rows (`T003-…`) were removed afterwards, and the uploads folder was identical to before the test.
    - **Checkbox CSS** (headless Chrome, 1440 px: 6 checks, 390 px: 7 checks): a 20×20 native box on one line with its text, a 44 px label row, no horizontal scroll, the form keeps its 24 px margins on the phone, and a long editor line does not widen the page.
  - **Not changed (outside §B9, left as is because this change set may edit §B9 only):** the §B5 row for `POST /admin/posts/{id}/delete` still says "cover file kept". It is now stale: the post's unused images are deleted. Fix that row the next time CLAUDE.md is edited.
