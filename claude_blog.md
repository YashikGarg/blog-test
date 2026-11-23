# claude_blog.md: plan a PHP + MySQL + TypeScript blog from scratch

**For the planner (Opus).** This file is self-contained: no other project files are needed. It holds the workflow, every rule, and a **verified default blog design**. The user gives only a title, the blog type, the features, and a time limit. You plan, ask for whatever is missing, suggest the security add-ons and a palette, verify in Docker, then write `CLAUDE.md` and `docs/IMPLEMENTATION_PLAN.md`. **Sonnet implements.**

Stack (fixed): PHP ≥ 8.4 with no framework, MySQL 8.4 in Docker, TypeScript (strict, esbuild), Twig, and handwritten SQL (no ORM). The architecture is MVC + service + repository, with SOLID, DI and unit tests.

## Standing requirements (the user's brief; they apply to every project, so never ask about them)

1. **Graded on:** clean architecture (MVC, SOLID, DI, unit tests); an efficient DB (proper indexes and constraints); a basic, type-safe frontend; and security with no bugs. **Don't over-engineer.**
2. **Stack:** PHP with no framework, MySQL, and TypeScript for the frontend.
3. **Use these skills and tools**, both while planning and while implementing. If one is missing, give the user the install line and continue:
   ```
   /plugin marketplace add dykyi-roman/awesome-claude-code      then  /plugin install acc@awesome-claude-code        # PHP skills (acc:*)
   /plugin marketplace add GoogleChrome/modern-web-guidance     then  /plugin install modern-web-guidance@googlechrome  # HTML/CSS/JS
   taste-skill (frontend design): https://github.com/leonxlnx/taste-skill                                             # plugin: taste-skill
   mysql-patterns (DB design):    https://mcpmarket.com/tools/skills/mysql-patterns  → .claude/skills/mysql-patterns/SKILL.md
   MCP server library:            https://github.com/php-mcp/server  (composer require php-mcp/server)                # the project's own <app>-dev server
   ```
4. **Tests:** unit tests only, **readable for a reviewer**, and **only the required ones**: one happy path plus the important unhappy paths per behaviour (e.g. login works / wrong password fails). Throw-away tests for trying out a design go in `tests/_scratch/` (git-ignored).
5. **Minimum files:** reuse as much as you can and keep the file count small, while still following the layer rules. Every file in the B3 tree needs a reason. Don't add a file with one caller under ~15 lines. No extra docs beyond `CLAUDE.md`, the plan, and a ~10-line `README.md`.
6. **If in doubt, ask** while planning. **While implementing, the architecture can't change without the user's permission**: stop and report (A1.1).
7. **Environments:** `local`, `dev` and `prod` env files. Every variable goes in env: DB settings, the password regex, limits, cache, and throttle numbers.
8. **Libraries first, and show where:** use libraries for env, routing, HTTP/PSR-7, templates, DI, cache, logging, and so on. **No ORM:** SQL is handwritten in repositories with a proper folder structure. §B2 must list each library and its job, plus "our own code" with the reason no library fits.
9. **Cache wherever it's safe:** rendered output, compiled templates, the compiled container, and OPcache. Never security state.
10. **Frontend:** use the skills, keep it simple and type-safe, and make it mobile- and laptop-friendly (390 to 1440 px). **It must look current, not like a 1990s form page.** Build every component on taste-skill's kit (§2.3), and judge it from real screenshots, not from DOM checks alone.
11. **Skills leave proof, before and after every edit.** A skill counts as used only if it was **invoked** (Skill tool) in this task, not remembered. **Before** the first edit (even a small one): the design skills for what you'll touch (`mysql-patterns` for tables and SQL, `modern-web-guidance` for HTML/CSS/TS, `acc:create-*` for new classes). **After** it: the review skills (`acc:detect-n-plus-one`, `acc:check-query-efficiency`, `acc:check-test-quality`, the matching `acc:check-*`) and the MCP checks. Every report (planner, Sonnet, subagent) ends with a `Skills run` list: skill → "clean", or the finding + fix. A report without it is rejected. *Lesson:* skills written only as advice in a prompt were skipped under time pressure, and the reviews missed real N+1s and over-budget writes as a result.
12. **Fewest queries.** Every page has a query count (cold and warm cache) and every write action has a **query budget** in the plan, measured with the MySQL general log. `acc:detect-n-plus-one` and `acc:check-query-efficiency` run on every change to the PHP, in planning, in implementation and in review.

---

## 0. How the user uses this file

1. Create an empty project folder and copy this file into it.
2. Open Claude Code there with **Opus** (the planner), and send one prompt like:
   ```
   Read <this file> fully and follow it.
   Title: <name>   Type: <kind>   Time limit: <minutes for Sonnet's implementation>
   Features: <bullet list>
   ```
3. The planner runs §1 end to end. The user answers 1–2 rounds of questions. The planner writes `CLAUDE.md` and `docs/IMPLEMENTATION_PLAN.md`.
4. The user opens a session with **Sonnet**, pastes the kickoff prompt from the top of the plan, and Sonnet implements it.
5. Afterwards the user asks Opus "review with skills and MCP", and the planner runs §8.

The user gives few instructions on purpose. **This file is the instruction set.** Don't ask about anything it already decides. Ask only about what's missing, and offer what the skills recommend.

---

## 1. Planner workflow (Opus)

| Phase | What | Budget |
|---|---|---|
| P0 Setup check | Skills and plugins present, Docker running, a free DB port (§1.1) | 3 min |
| P1 Intake | Read the prompt, fill the defaults, ask **one** AskUserQuestion round (≤ 4 questions, multi-select where it fits) (§2) | 5 min |
| P2 Design | Entities, schema, routes, folders, using the skills (§1.2) | 10 min |
| P3 Verify | Start MySQL in Docker, apply the schema, probe the constraints, EXPLAIN the key queries (§1.3) | 10 min |
| P4 Write | `CLAUDE.md` (Part A copied from §4 + Part B filled in) and `docs/IMPLEMENTATION_PLAN.md` (§6) | 10 min |
| P5 Hand-off | Show the user a short summary, the kickoff prompt, and the time budget | 1 min |

The rules the user set for planning:
- **Ask before adding anything** that isn't in this file or the user's prompt: a security rule, a feature, a library or a table. Present it as a multi-select with a one-line cost each, apply only what's approved, and log the declined items as known gaps.
- **Verify, don't assert.** Every DB claim (constraints, indexes, concurrency) is backed by a run against MySQL **in Docker**, never a local mysqld and never memory. Name the skill behind each design decision.
- **Don't over-engineer.** MVC + service + repository, and nothing more: no DDD aggregates, CQRS, buses, or value objects per field. Keep the file count minimal but follow the layers.
- When the user asks "explain #N", explain it plainly, with a concrete example.

### 1.1 P0 setup check

- Plugins or skills: `acc` (awesome-claude-code), `modern-web-guidance`, `taste-skill`. Check `ls "${CLAUDE_CONFIG_DIR:-$HOME/.claude}"/plugins/cache/`. If one is missing, tell the user the `/plugin` command to install it, and continue without it.
- `mysql-patterns` is a *project* skill, so a new folder doesn't have it. Look in `.claude/skills/mysql-patterns/SKILL.md` and in `~/.claude*/skills/`. If it's missing, ask the user whether to fetch it (the source is the affaan-m "everything-claude-code" repo, `skills/mysql-patterns`). If they decline, §4 A6 already holds every DB rule it would have taught.
- PHP ≥ 8.4 with pdo_mysql (`php -v`, `php -m`), Composer, Node ≥ 20, Docker running.
- Pick a free host port for MySQL: try 3308, and use `lsof -iTCP:<port> -sTCP:LISTEN` to check it's free.
- Chrome exists for the headless checks in §8 (`/Applications/Google Chrome.app` on macOS).

### 1.2 P2 design: which skill for what

| Decision | Skill |
|---|---|
| Layers, PSR stack, DI | `acc:no-framework-knowledge`, `acc:clean-arch-knowledge`, `acc:solid-knowledge` |
| Tables, indexes, upserts, keyset, users and grants | `mysql-patterns` (read before writing SQL), `acc:check-index-usage`, `acc:check-query-efficiency`, `acc:detect-n-plus-one` |
| **Every page and every write** | `acc:detect-n-plus-one` on each read path: an admin page that lists parents with children, a form that needs lookups per group, a loop over ids. `acc:find-race-conditions` / `acc:check-insecure-design` on each write. Write each write as one statement using the shapes in §4 A6 ("Fewest round trips") and §B.4, and give it a budget in the plan |
| Security menu (§3) | `acc:check-sql-injection`, `acc:check-csrf-protection`, `acc:check-authentication`, `acc:check-authorization`, `acc:check-output-encoding`, `acc:check-input-validation`, `acc:check-type-juggling`, `acc:check-insecure-design`, `acc:check-path-traversal` (if uploads), `acc:check-secure-headers`, `acc:check-sensitive-data`, `acc:check-dependency-vulnerabilities` |
| Docker | `acc:create-docker-compose-dev`, `acc:docker-compose-knowledge`, but use §5's verified files (the skill's MySQL template breaks on 8.4, see §7) |
| Frontend patterns | `modern-web-guidance` (`npx -y modern-web-guidance@latest search "<2–5 words>"`, then `retrieve`). It needs network access: ask once. |
| Look | `taste-skill:design-taste-frontend` (§2.3): **invoked** for the deal, again for each page's components, and its `copy-lint` + `consistency.mjs` checks run before hand-off |
| Tests | `acc:testing-knowledge`, `acc:create-unit-test`, `acc:check-test-quality` |

### 1.3 P3 verify (Docker + MCP-style checks)

1. Write `docker-compose.yml`, `docker/mysql/conf.d/app.cnf` and `docker/mysql/init/01-users.sh` from §5, plus `.env.local` with real local passwords. Run `docker compose --env-file .env.local up -d --wait`.
2. Apply `database/migrations/001_init.sql` as the **migrate** user.
3. **Constraint probes:** for every CHECK, UNIQUE and FK, run one bad write as the **app** user inside `START TRANSACTION … ROLLBACK`. Each must fail with the expected constraint name. Also run `DELETE FROM <table>`, which must fail with "DELETE command denied".
4. **EXPLAIN** every list, search and lookup query. Seed about 300 rows first, because tiny tables give misleading plans. Red flags: `type: ALL`, `key: NULL`, and `Using filesort` on a hot path.
   - **Never run `ANALYZE TABLE` (or any DDL) inside a probe transaction.** It commits implicitly, so the "rolled-back" probe rows are saved (it happened). Either EXPLAIN inside the transaction without ANALYZE, or commit the seed, ANALYZE, EXPLAIN, and then delete the seeded rows **by name** (as root).
   - **Probe the whole repository method in PHP**, not only the SQL text: the PDO calls around it matter. A verified `LAST_INSERT_ID` SQL still returned 0 because `lastInsertId()` was read after `COMMIT`.
5. **Concurrency** (only if there are counters or a check-then-act flow): open two connections, hold a row lock in A, and show that B waits (1205 with a 1 s timeout) instead of overwriting.
6. Record each result in one line for the §B9 decision log. Drop the throw-away rows and databases afterwards.

The project's own MCP server is built by Sonnet in step 1 of the plan (§5.5). During planning, plain `docker exec … mysql` or a tiny PHP/PDO script is fine.

---

## 2. P1 intake

### 2.1 Read from the prompt, and default whatever is missing

| Value | Default if missing | Ask? |
|---|---|---|
| Title | none | **yes**, required |
| Time limit (Sonnet implementation) | 60 min | yes, if missing |
| Roles | `admin` + `user`, with a hierarchy (admin satisfies user) | only if the features suggest something else |
| Sign-up allowed? | yes, if any feature needs a logged-in user | yes, if unclear |
| Uploads? | yes, if any feature has images or files | no |
| Search / pagination | FULLTEXT search + keyset "Older" pagination on growing lists | no |
| AJAX (no page reloads) | yes, with progressive enhancement | no |
| Light/dark | light only, unless it's a tech or software kind (taste-skill) | no |
| Language | English UI | no |

### 2.2 The question round (one AskUserQuestion call, ≤ 4 questions)

1. **Missing required values**, if any (title, time limit, unclear sign-up or roles).
2. **Security add-ons** (multi-select): the §3 optional menu items the skills flag for these features, each with a one-line cost.
3. **Look** (single-select): 2–3 palette and type options from taste-skill (§2.3), with a "decide for me" option first. If the app is public, also ask whether it needs a **landing page** (taste full run) or the list page is enough.
4. **Feature gaps** (multi-select): the §B.2 suggestions for the blog type, plus things the feature list probably needs but doesn't say. For example: edit/delete for every create (full CRUD); who may edit or delete (owner/admin); an empty state; a search/filter for a list; a confirm before delete; drafts; an image alt text. Mark the recommended ones.

5. **Cache per service** (blog): a second AskUserQuestion call with one question per service from §B.8 (post page, home list, comments, vote count, has-voted). Each offers the recommended strategy first, plus "no cache". Never decide the cache strategy for the user.

If the user removes a feature or asks for an extra one later, update Part B and the plan before writing more. Ask again only if the change touches security or the schema.

### 2.3 Palette via taste-skill (app mode)

- Read the skill's system-layer docs. **App pages** (lists, details, forms, admin) use the **system layer**, and use it properly: every component is built on the kit classes and tokens (`.wrap`, `.btn` + exactly one primary and one secondary style, `.card` with a stretched link, nav, the form recipe, hover/press motion, access rules). Never hand-roll plain HTML defaults next to the kit. *Lesson:* a project that only copied the tokens shipped a UI the user called "from the 90s", and had to be rebuilt.
- **A public landing page** (if the app has one) gets the **full run**: dealt hero, nav, footer, close and moment, the copy rules, and `copy-lint` + `consistency.mjs`. Offer this in the question round (§2.2, Look).
- **Kit rules that clash with app pages** (one section per screen, fixed screen-tall heights): turn them off on app pages with content-height sections and log the failing checks as "by design". Otherwise the pages get huge empty bands.
- **No empty visuals:** a card or details page without an image shows a compact insurer/brand/initials strip, never a large grey panel.
- To get palette options: if the skill has a deal script (`scripts/start.mjs deal`), run it once for the kind and trade that fit the title. Offer its first 2 palettes plus 1 type pairing. Choose for **readability**: body text must stay ink-on-paper with a contrast ratio of at least 4.5:1, so reject palettes that set body text in the accent colour.
- **Close the skill's landing gate right after dealing** (`scripts/gate.mjs --done`). Otherwise its Stop hook blocks Sonnet later. Never re-deal during implementation.
- The CSP forbids Google Fonts and inline `<style>`. **Self-host the font** (`npm i -D @fontsource-variable/<font>`, then copy the woff2 to `public/assets/fonts/`), and put the tokens at the top of `public/assets/app.css`. Copy `system.css`, `system.js`, `motion.css` and `motion.js` into `public/assets/vendor/taste/`, and load the JS files as `type="module"`.
- Record the decision in §B9: kind, trade, palette, font, and why.

---

## 3. Security

### 3.1 Always on (never ask; the user requires these in every project)

- **SQL injection:** native prepared statements, every value bound, identifiers never taken from input.
- **CSRF:** a synchronizer token rotated on login/logout, checked centrally on every unsafe method, plus a `Sec-Fetch-Site`/`Origin` check and SameSite=Lax.
- **Auth** (if the app has accounts): argon2id, a random salt per hash (PHP's default), `session_regenerate_id(true)` on login/logout, generic login errors, and a constant-time dummy hash for unknown emails.
- **Login throttle with exponential backoff** (if there's sign-in or sign-up): per account+IP **and** per IP across accounts, stored in a `login_attempts` table, with an atomic upsert and `GET_LOCK`. All numbers come from env, with a 2 h cap by default.
- **XSS:** Twig autoescape, `textContent` in TS, and a strict CSP.
- **Authorization:** server-side role checks through `Role::satisfies()`; ownership is checked in services.
- **Type juggling and request shape** (strict comparisons, `is_string` on every input), **forbidden functions**, `composer audit` / `npm audit --omit=dev`, a prod PHP ini with a `__Host-` cookie, a server-side idle timeout, security logging, and a prod admin seed guard.

All of these are written out in §4 A7. Copy them as they are.

### 3.2 Optional menu (offer the ones that fit; one-line cost each)

| Item | Offer when | Cost |
|---|---|---|
| Spam limit on sign-up/comment/post | public write forms | 1 table key kind + 10 lines |
| Re-encode uploads with GD (strips metadata and payloads) | uploads | ext-gd + 20 lines |
| Trusted-proxy client IP | behind a proxy or CDN | 1 env + 10 lines |
| Email enumeration fix on sign-up | sign-up | the message changes to "check your email"; needs email |
| Per-account lock across IPs | high-value accounts | lockout DoS risk; usually declined |
| Content moderation queue | user-generated posts | 1 status + admin screen |
| Audit log table | money, roles, admin actions | 1 table |
| Re-check the role per request | more than one admin, or user management | 1 PK query per admin request |
| Absolute session lifetime | admin panels | 1 env + 3 lines (logout after N hours even when active) |
| SSRF / XXE / command injection / deserialization checks | only if a feature fetches URLs, parses XML, shells out, or unserializes | depends |

---

## 4. Part A: copy into `CLAUDE.md` exactly as written

The planner writes `CLAUDE.md` as: a 6-line header, then this Part A, then Part B (§6.1). Replace `<app>` with the project slug.

````markdown
# Part A — Engineering rules

## A1. Change control (most important rule)
1. Part B is the **approved architecture** (folders, layers, libraries, schema, routes). Don't change it without asking. If a problem needs a change: stop, explain the problem and your fix in 2–3 lines, and wait.
2. Small changes inside a layer are fine without asking (a private helper, a rename, an index that serves an existing query).
3. **At the end of every task, update this file.** Add a §B9 line for anything changed or decided, and update the affected B-sections. If it isn't written here, it didn't happen.
4. If unsure, ask. Don't guess about features, scope or security.
5. **Security rules are decided with the user while planning, never by the implementer.** When implementing: don't ask again. Follow A7 exactly (no extras, none skipped). A security problem A7 doesn't cover counts as an architecture change, so stop and ask per rule 1.

## A2. Skills and tools (required)
| When | Use |
|---|---|
| PHP structure | `acc:no-framework-knowledge`, `acc:clean-arch-knowledge`, `acc:solid-knowledge` |
| Repository, middleware, test | `acc:create-repository`, `acc:create-psr15-middleware`, `acc:create-unit-test`. Adapt the output to Part B, and **never add their extra layers** (no DDD aggregates, CQRS, buses, or value objects per field). |
| Security review before finishing | `acc:audit-security` (or the individual `acc:check-*` skills) |
| DB, migrations, queries | `mysql-patterns` (read before any SQL); review with `acc:check-index-usage`, `acc:check-query-efficiency`, `acc:detect-n-plus-one` |
| Tests | `acc:testing-knowledge`, `acc:check-test-quality` |
| HTML/CSS/client TS | `modern-web-guidance` (search, then retrieve) |
| Visual design | `taste-skill`: the **system layer** (kit classes + tokens, one primary + one secondary button) on every app page; the **full run** on a landing page, if there is one. The look is already dealt in §B9: never re-deal. Read real screenshots at 390/768/1024/1440 **and** a short window (about 1366×650) before calling a UI done. |
| While building | the project MCP server `<app>-dev` (§B8): `db_schema` before SQL, `explain_query` after any query change (it must hit an index), `verify_constraints` after schema changes, `list_routes` before adding a link or form. A DB claim needs a tool result. If the MCP session expires, ask the user to run `/mcp`. |
| Docker | MySQL runs **only** in Docker; PHP runs on the host. |
| Subagents | Only when the plan says so, with **file ownership** (no two agents edit one file). A subagent must invoke the skills for its files; the main agent reviews its diff against the plan. |
| Proof of skill use | A skill counts as used only when it was **invoked** (Skill tool) in this task, not remembered. Every report (subagent or main) ends with a `Skills run` list: skill → "clean" or finding + fix. The main agent rejects a subagent report without it. A review always invokes `acc:detect-n-plus-one` and `acc:check-query-efficiency` on the changed PHP. **Both before and after every edit, even a small one:** design skills **before** the first Edit (`mysql-patterns` before SQL, `modern-web-guidance` before HTML/CSS/TS, `acc:create-*` before a new class), review skills + MCP **after**. If MCP has expired, ask for `/mcp`; never skip it silently. When SQL constants are renamed or removed, update the `explain_query` whitelist (and its description) in the same change and re-run it on the new names. |

## A3. Architecture: MVC with a service layer
```
request → public/index.php → PSR-15 pipeline: SecurityHeaders → Error → Session → Csrf → Route(+role)
  → Controller (HTTP only) → Service (rules, validation, transactions, cache)
  → Repository (interface → PDO class; the ONLY place SQL lives) → Model (final readonly + enums, no I/O)
View = Twig (autoescape). TS = progressive enhancement.
```
- Dependencies go one way: Controller → Service → Repository interface ← PDO repository. Models depend on nothing.
- Controllers never touch SQL, `$_POST`, `$_SESSION` or `header()`.
- Services know nothing about HTTP. They throw `ValidationException`, `NotFoundException` or `ForbiddenException`.
- Repositories use prepared statements only, named params, and explicit columns (no `SELECT *`). They return Models, scalars, or a documented `@return array{…}` shape. A pure-SQL write that must be atomic across tables may own its transaction in the repository.
- SQL strings are **public class constants**, so the MCP `explain_query` reuses them and the two can't drift.

## A4. SOLID and DI
- S: one controller per resource, one service per use-case group, one repository per table. O: extend by decorating (e.g. a cached renderer). L/I: small interfaces. D: services depend on interfaces, wired only in `config/container.php` (PHP-DI). No `new` of a service or repository inside a class, and no service locator.
- `declare(strict_types=1)` everywhere, PSR-4/PSR-12, `final` by default.
- Reuse before adding: shared helpers (`render`, `redirect`, `json`, `respond`, `respondInvalid`, `body`, `currentUserId()`) live in `BaseController`. One exception family, one layout. No file with a single caller under ~15 lines. **One rule, one place:** never copy a private helper into several controllers, and never re-check in a service what the boundary already guarantees (e.g. the session user id).
- Constructor injection only: no `#[Inject]` property injection and no hidden dependencies. **No controller → controller dependency:** shared view-building code goes in one small presenter class.
- **Minimum files:** every file in §B3 has a reason. Don't add files, layers or docs that aren't in §B3 without asking.

## A5. Library first
Use Composer/npm packages for env, routing, DI, PSR-7/15/17, templates, markdown, cache, clock, logging and tests. **No ORM or query builder:** SQL is handwritten. If no library fits (e.g. exponential backoff), write the smallest class on a PSR interface and log why. List every library in §B2.

## A6. Database
- InnoDB, `utf8mb4_unicode_ci`, **UUIDv7 `BINARY(16)` PKs/FKs** (`symfony/uid`; `UUID_TO_BIN`/`BIN_TO_UUID` without the swap flag; validate with `Uuid::isValid()` before a query). `NOT NULL` unless there's a real unknown.
- Every FK is indexed with `ON DELETE RESTRICT`. `UNIQUE` on natural keys. `CHECK` on lengths and ranges.
- **Every enum is DB-enforced:** `VARCHAR(n)` plus a named `CHECK (col IN (…))`, mirrored by a PHP backed enum. `'abcd'` must fail in the DB. No MySQL `ENUM`.
- Strict session: `Pdo\Mysql::ATTR_INIT_COMMAND` = `SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'`. UTC everywhere.
- **Never hard-delete:** `status='deleted'` + `deleted_at` + `deleted_by` (FK users), with CHECKs `(status='deleted') = (deleted_at IS NOT NULL)` and `deleted_by IS NULL OR status='deleted'`. **One-per-user actions** (vote, like, follow, RSVP): the client sends the **wanted state** (`voted=1|0`), never a toggle, so a double-click can't cancel itself. "On" is `INSERT … SELECT … FROM parent WHERE id = :p AND status = 'published' ON DUPLICATE KEY UPDATE status = 'active'`. "Off" is `UPDATE pair p JOIN parent … SET status = 'removed' WHERE … AND status = 'active'`, which never inserts. The counter moves ±1 only when the pair row really changed (`rowCount() > 0`), in the same transaction. Every read filters on status, and status is in the index. The app DB user has **no DELETE grant**.
- **Files are different:** an uploaded file is deleted once nothing live references it, checked after the DB write.
- **No derived values on a parent row (3NF):** counts live in a 1:1 table changed in the same transaction as the child write (lock order: child, then counter), with `UNSIGNED` against negatives.
- Atomic counters: `INSERT … AS new ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(n + 1)`, then read `lastInsertId()`. Inside a transaction, read it **right after that statement, before `COMMIT`**: PDO returns 0 after `COMMIT` (verified). Never read-modify-write in PHP.
- One query per list (no N+1). **Keyset pagination** `(sort_col, id) < cursor`, backed by a matching index, with `LIMIT` bound as an int. No deep OFFSET.
- **Concurrency:** `REPEATABLE-READ` (set in `app.cnf`). Counters only change via `SET c = c + :d`. Check-then-act flows use `GET_LOCK` and release it in `finally`. Keep transactions short, with no I/O, and always lock in the same order. Retry once on deadlock (1213/40001).
- Upserts use the row alias (`AS new … new.col`), never `VALUES(col)`.
- **Fewest round trips per action:** no count-then-insert; no read-then-write when one `INSERT … SELECT` / `UPDATE … WHERE` can do it; no existence check the DB already enforces (UNIQUE, FK, CHECK); no query inside a loop (batch with `JSON_TABLE` / `IN`). Each write action has a **query budget** in its plan.
  - Duplicates: a plain INSERT/UPDATE plus the UNIQUE key. Map error 1062 to a `DuplicateException`, which becomes a field error. Never SELECT-then-INSERT.
  - The parent must be alive: `INSERT … SELECT … FROM parent WHERE id = :p AND status = 'active'`. 0 rows means gone.
  - A blocked delete: `UPDATE … SET status='deleted' … WHERE id = :id AND NOT EXISTS (live children)`. 0 rows means blocked or gone; only then count, to build the message.
  - A computed default (e.g. "last position"): compute it inside the INSERT (`COALESCE(:v, (SELECT MAX(…)+1 …))`), not with a separate SELECT.
  - Many child rows: one multi-row `INSERT … SELECT … FROM JSON_TABLE(:json …)`. A tick/untick set: one upsert.
  - **Every edit updates only the fields that changed, never all of them** (user rule).
    - **Client:** the TS submit compares each input with its `defaultValue` / `defaultChecked` and drops the unchanged entries from the `FormData`. After a successful save it resets the defaults.
    - **Server:** one static UPDATE with `SET col = COALESCE(:col, col)` for every editable column, so a field that was **not sent** binds `NULL`, which means keep. A field that **was** sent is a real value even when it's `''`, and it is validated as on create, so an optional field can be cleared. The SQL stays a fixed constant, never built from the field list (A7). If nothing was sent, return 422 "Change at least one field". **0 rows** (no `ATTR_FOUND_ROWS`) means "nothing changed" or "gone": run one existence check on that path only, or use the row the service already loaded. Still there = saved with no change (200); missing = 404.
    - **Columns where NULL is a real value** (e.g. remove an optional image): send an explicit flag (`remove_image=1`) and use `CASE WHEN :remove = 1 THEN NULL ELSE COALESCE(:v, col) END`.
    - **Checkbox sets** (an unticked box is never sent): the client adds a `features_changed=1` marker only if the set changed. The server touches the child rows only when the marker is there, and then with one upsert.
    - **Without JS** the full form is sent. Values equal to the current ones change nothing, so this is still correct.
    - **Why:** it prevents **lost updates** (tab A renames, a stale tab B changes the price and would bring the old name back), and the code says what the user did. It does not save disk writes: MySQL already writes nothing for an unchanged value ("Changed 0"). Don't claim that it does.
  - When two editors may change **the same field**, also add an optimistic version check: `… AND updated_at = :seen`, where 0 rows means "changed elsewhere, reload".
  - An optional file column uses `logo = COALESCE(:logo, logo)` in the same UPDATE. Read the old file name first only when a new file was sent. Delete the old file **after** success, and delete the new file on 0 rows or 1062.
  - **Don't set `ATTR_FOUND_ROWS`.** With it, an `INSERT … ON DUPLICATE KEY UPDATE` that changes nothing reports 1, the same as a new row, so every "did it change?" check (counter ±1) double-counts (verified: repeat upvote = 1). Without it, 0 rows means "unchanged or gone"; when the caller must tell them apart, run one existence check on that 0-row path only.
- **ORDER BY uses qualified columns** (`p.price, p.id`). An unqualified `id` resolves to the select alias `BIN_TO_UUID(id) AS id` and forces a filesort. Keyset uses `price > :c OR (price = :c2 AND id > :id)`; MySQL doesn't range-optimise a row constructor. A named placeholder can't repeat under native prepares.
- Least privilege: the app user has `SELECT, INSERT, UPDATE` only; a separate migrate user runs DDL. Prod uses `REQUIRE SSL`. Never `ALL` or `*.*`.
- Ownership is enforced in the **service**.
- Migrations are `database/migrations/NNN_name.sql`, applied once in order by `bin/migrate.php` and recorded in `schema_migrations`. Never edit an applied one. `database/seed.sql` is idempotent.
- EXPLAIN every new query (MCP). Red flags: `ALL`, `key NULL`, filesort or temporary on a hot path. If the optimizer skips a needed index at realistic data sizes, use `FORCE INDEX` and log it.

## A7. Security (all required)
### SQL injection
Native prepares (`ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, DSN `charset=utf8mb4`). Every value is bound, `LIMIT` with `PARAM_INT`. No concatenation or interpolation of input. Identifiers are fixed in code; optional clauses are static strings chosen by code. Validate first (UUID regex + `isValid`, `Enum::tryFrom`, int casts; search trimmed, ≤100 chars, `NATURAL LANGUAGE MODE`). The app user has no DELETE/DDL. MCP tools take query **names**, never SQL. Review: `acc:check-sql-injection`, and `grep -nE '(query|exec|prepare)\(.*\$' src/` shows only static SQL. Probe: searching `' OR 1=1 -- ` gives "no results".
### XSS
Twig autoescape. Markdown via league/commonmark with `html_input: 'escape'`, `allow_unsafe_links: false`. The only `|raw` is that rendered markdown. TS uses `textContent`/`setAttribute`. If HTML from the server must be inserted (e.g. a preview): `setHTML()` with an **explicit allow-list** (the default Sanitizer drops `<img>`), falling back to `innerHTML` only where the Sanitizer API is missing.
### CSRF
A 32-byte session token, rotated with `session_regenerate_id(true)` on login/logout. A hidden `_csrf` in every form; `X-CSRF-Token` from `<meta name="csrf-token">` in the one TS fetch helper. `CsrfMiddleware` checks every non-GET/HEAD/OPTIONS request with `hash_equals` and returns 403 (JSON for fetch). It also rejects `Sec-Fetch-Site: cross-site`, or an `Origin` that differs from `APP_URL`, even with a valid token. SameSite=Lax. No state change on GET. No CORS headers. Tests: missing token, valid token, cross-site.
### Auth and throttle
- `password_hash(PASSWORD_ARGON2ID)` (random salt per hash), `password_verify`, and a fixed dummy argon2id hash verified for unknown emails (equal timing).
- A generic login error.
- Throttle per IP+account (key built from the **account id** once found, never the typed email, because the collation folds accents) **and** per IP across accounts (`sha1('ip:'+ip)`, higher free limit).
- Exponential backoff: the Nth free failure locks for BASE seconds, doubling, capped at MAX (all env).
- Stored in `login_attempts` with an atomic upsert and `GREATEST()` so a lock never shortens.
- `GET_LOCK` on the IP key, then the account key, released in reverse order in `finally`.
- A success resets **only** the account counter. After a quiet period longer than MAX, the count restarts at 1.
- The password regex comes from env.
### Session, authorization, uploads, headers, errors
- **Session cookie:** HttpOnly, SameSite=Lax, Secure in prod, strict mode, custom name (`__Host-` in prod), and a server-side idle timeout (`last_seen`, `SESSION_IDLE_SECONDS`). Set `session.gc_maxlifetime` from that same value, ≥ the idle seconds (PHP's default 1440 s can delete a session before a 30-minute timeout). Unit-test the timeout with `MockClock`: active → kept, idle → logged out.
- **Authorization:** roles are checked on the server in `RouteMiddleware` through `Role::satisfies()` (a hierarchy). Never compare roles with `===` for access. Hidden buttons aren't security.
- **Uploads:** a size limit from env, `finfo` MIME against an env whitelist, `getimagesize`, and a random name with the extension taken from the MIME type. Files go in `public/uploads/` with PHP execution off. Deletes only accept exact generated names via `basename()`.
- **Headers:** CSP `default-src 'self'` (no inline script or style, no CDN), nosniff, `Referrer-Policy: strict-origin-when-cross-origin`, `frame-ancestors 'none'`, HSTS in prod. SecurityHeaders is the **outermost** middleware so error pages carry the headers too. HTML responses (pages and error pages) send `Cache-Control: no-store` (they carry the CSRF token and admin data).
- **Errors:** details only when `APP_DEBUG=true`; otherwise a generic page and Monolog.
- **Mass assignment:** services pick fields explicitly. Route ids must match the UUIDv7 regex `[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}`.
### PHP hardening
- **Type juggling:** `===` only, `in_array(…, true)`, `match` over `switch`, `hash_equals` for tokens, `str_starts_with`, `preg_match(...) === 1`.
- **Request shape:** `BaseController::body($req, $keys)` returns only the expected keys, each checked with `is_string` (else 422). JSON is decoded with `JSON_THROW_ON_ERROR`.
- **Forbidden functions:** `unserialize` on input, `eval`, `extract`, shell functions, string `assert`, `rand`/`mt_rand`/`uniqid` for security, `md5`/`sha1` for passwords.
- **Dependencies:** `composer audit` and `npm audit --omit=dev` must be clean (put shipped npm packages in `dependencies`).
- **Prod PHP config:** `config/php.prod.ini` with `expose_php=Off`, `display_errors=Off`, `log_errors=On`, `session.use_only_cookies=1`, `session.use_trans_sid=0`, `session.use_strict_mode=1`, `opcache.validate_timestamps=0`, `zend.exception_ignore_args=On`.
- **Security logging (A09):** failed logins, lockouts, 403s, and auth-form 422s, each with `event`, `ip`, `user_id`. Never log secrets, tokens or bodies.
- **Admin seed guard:** `bin/migrate.php` refuses an empty, example or weak `ADMIN_PASSWORD` outside `local`.

## A8. Testing
- PHPUnit **unit tests only**, for services and security middleware. Mock repository **interfaces**; build real final Models. At most 3 doubles per test; prefer `ArrayAdapter` / `MockClock`.
- Test classes are `final`, with `#[Group('unit')]` and `#[CoversClass]`.
- **Only the required tests**, readable for a reviewer: one happy path plus the important unhappy paths per behaviour (e.g. login works / wrong password fails). Don't test getters, glue, or every permutation.
- Names are sentences, bodies follow AAA, no loops or ifs.
- Throw-away tests go in `tests/_scratch/` (git-ignored, excluded).

## A9. Frontend
- Twig server render. TS adds **AJAX so no action reloads the page**, and every form still works without JS.
- **One JSON contract:** with `Accept: application/json`, the response is `200 {ok:true,…,redirect?}`, `422 {ok:false,errors:{field:msg}}`, or 401/403/404 JSON; otherwise redirect/re-render. `respond()` and `respondInvalid()` decide which. JSON sends `no-store` and is encoded with `JSON_HEX_TAG|JSON_HEX_AMP`.
- JSON is rendered by cloning a `<template>` and filling it with `textContent`. Internal URLs only (start with `/`, not `//`, no backslash or control characters).
- TS `strict`, no `any`. Every JSON response has an interface plus a runtime guard, and one fetch helper adds the CSRF header.
- A superseded filter/search request is aborted (`AbortController` + `AbortSignal.timeout`). A fetch that swaps page HTML checks `response.ok` and the content type first.
- Native first: `<dialog>` confirms, `:user-invalid` plus `aria-invalid` (the error linked in `aria-describedby` only while shown), focus on the first invalid field, `loading="lazy"`/`fetchpriority`.
- Third-party UI libraries are bundled by esbuild (never a CDN), loaded only where used, with CDN-download and `style=""` options turned off.
- Mobile-first at 390/768/1024/1440: 44 px tap targets, no horizontal scroll, side gutters at every width, `color-scheme` per §B9.

## A10. Config
phpdotenv loads `.env`, then `.env.{APP_ENV}`. Real server env wins (read via `getenv()` first). Every tunable lives in env, read **only** in `config/settings.php`. Environments: `local`, `dev`, `prod`. Only `.env.example` is committed.

## A11. Done means
The query-count table (MySQL general log, every route incl. admin pages and POSTs, cold/warm) is within budget, every report has its `Skills run` list, `composer test` is green, `composer stan` reports 0 errors (PHPStan level 8, no baseline, no ignores), `npm run typecheck` and `npm run build` are clean, the audits are clean, the MCP `verify_constraints` all pass, A7 has been reviewed, real screenshots have been read at every width, and this file is updated (A1.3).
````

---

## 5. Verified infrastructure templates (write these; replace `<app>`)

### 5.1 `docker-compose.yml` (MySQL only; PHP on the host)
```yaml
services:
  mysql:
    image: mysql:8.4
    container_name: <app>-mysql
    ports: ["127.0.0.1:${DB_PORT:-3308}:3306"]
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASS:?set DB_ROOT_PASS}
      MYSQL_DATABASE: ${DB_NAME:-<app>}
      APP_DB_USER: ${DB_USER:-<app>_app}
      APP_DB_PASS: ${DB_PASS:?set DB_PASS}
      MIGRATE_DB_USER: ${DB_MIGRATE_USER:-<app>_migrate}
      MIGRATE_DB_PASS: ${DB_MIGRATE_PASS:?set DB_MIGRATE_PASS}
    volumes:
      - mysql-data:/var/lib/mysql
      - ./docker/mysql/conf.d:/etc/mysql/conf.d:ro
      - ./docker/mysql/init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -uroot -p\"$$MYSQL_ROOT_PASSWORD\" --silent"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 20s
volumes:
  mysql-data:
```
Start it with `docker compose --env-file .env.local up -d --wait`.

### 5.2 `docker/mysql/conf.d/app.cnf`
```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
default-time-zone = '+00:00'
sql_mode = STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
innodb_lock_wait_timeout = 10
transaction_isolation = REPEATABLE-READ
slow_query_log = ON
long_query_time = 1
log_queries_not_using_indexes = ON
```

### 5.3 `docker/mysql/init/01-users.sh` (runs once on an empty volume)
```bash
#!/bin/bash
set -euo pipefail
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'%' IDENTIFIED BY '${APP_DB_PASS}';
GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.* TO '${APP_DB_USER}'@'%';
CREATE USER IF NOT EXISTS '${MIGRATE_DB_USER}'@'%' IDENTIFIED BY '${MIGRATE_DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON \`${MYSQL_DATABASE}\`.* TO '${MIGRATE_DB_USER}'@'%';
DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS ''@'%';
FLUSH PRIVILEGES;
SQL
```

### 5.4 Libraries (Composer + npm)
- **Composer require:** `vlucas/phpdotenv`, `nikic/fast-route`, `php-di/php-di`, `nyholm/psr7`, `nyholm/psr7-server`, `relay/relay`, `laminas/laminas-httphandlerrunner`, `twig/twig`, `monolog/monolog`, `symfony/cache`, `symfony/clock`, `symfony/uid`, `php-mcp/server`. Add `league/commonmark` if there's markdown.
- **Composer require-dev:** `phpunit/phpunit`, `phpstan/phpstan` (level 8 on `src/` from step 1, script `composer stan`; fixing 50+ errors at the end costs far more). `php-mcp/server` may live in require-dev too (dev-only tool).
- **`config/php.prod.ini`** also sets `session.gc_maxlifetime` ≥ `SESSION_IDLE_SECONDS`.
- **`"php": ">=8.4"`**, because symfony 8 needs it.
- **Composer scripts:** `test`, `serve` (`php -S localhost:8000 -t public public/index.php`), `migrate`, `db:up`, `db:down`.
- **npm devDependencies:** `typescript`, `esbuild`, `@fontsource-variable/<font>`. Runtime UI libraries go under `dependencies`, with exact versions at least 2 weeks old.
- **`tsconfig`:** `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, ES2022, `moduleResolution: Bundler`, `noEmit`, `skipLibCheck`. Add `noUncheckedSideEffectImports: false` if TS imports CSS.
- **`package.json` scripts:** `build` (esbuild `frontend/src/main.ts` → `public/assets/app.js`, esm, es2022, minify) and `typecheck` (`tsc --noEmit`).
- **`phpunit.xml`:** suite `tests/Unit`, `failOnWarning`, `failOnRisky`.

### 5.5 Project MCP server `<app>-dev` (built by Sonnet in plan step 1)
- **`.mcp.json`:** `{"mcpServers":{"<app>-dev":{"type":"stdio","command":"php","args":["bin/mcp-server.php"],"env":{}}}}`
- **`bin/mcp-server.php`:**
    - It loads env and the container, and **refuses `APP_ENV=prod`**.
    - It builds with `PhpMcp\Server\Server::make()->withServerInfo('<app>-dev','1.0.0')->withContainer($c)->withTool([DevTools::class,'dbSchema'],'db_schema','…')…->build()->listen(new StdioServerTransport())`.
    - It never echoes to stdout, because stdout carries the protocol.
- **`src/Mcp/DevTools.php`** (dev-only, connects as the app user):
    - `db_schema`: `SHOW CREATE TABLE` for a fixed table list.
    - `explain_query(name)`: a whitelist map from name to `[Repository::CONST_SQL, params]`. It returns `['error','allowed']` for an unknown name.
    - `verify_constraints`: an N-row table of bad writes inside one transaction that's always rolled back. Each row is `{#, case, result: PASS|FAIL, detail}`, and a PASS means the expected constraint name appeared.
    - one `verify_<counter>` per counter table, which lists drifted rows (must be `[]`).
    - `list_routes`, built by replaying `config/routes.php` into a collector.
- If the Claude session says "Invalid or expired session", the user runs `/mcp`. Meanwhile, call the server over stdio with a JSON-RPC client: `initialize`, then `notifications/initialized`, then `tools/call`.

---

## 6. P4: what to write

### 6.1 `CLAUDE.md`
- **Header:** a 6-line note saying to read it in full; Part A is generic and Part B is this project; the plan is in `docs/IMPLEMENTATION_PLAN.md`; later changes go in `docs/CHANGES_NNN.md`.
- **Part A:** §4, verbatim.
- **Part B:**
    - **B1 Scope:** roles, features, and what's out of scope.
    - **B2 Libraries:** a table of need, library and notes, plus "our own code" with the reasons.
    - **B3 Full directory tree:** every file with a one-line comment, and the counts.
    - **B4 DB:** a table of tables and key points. The SQL itself goes in the plan.
    - **B5 Routes:** method, path, handler and role.
    - **B6 Env vars**, with defaults.
    - **B7 Cache.**
    - **B8 Commands and MCP.**
    - **B9 Decision log:** dated, newest last. It holds every approved and declined item, each verification result, the look decision, and known gaps.

### 6.2 `docs/IMPLEMENTATION_PLAN.md`
1. **Kickoff prompt** for Sonnet, at the top: "Read CLAUDE.md and docs/IMPLEMENTATION_PLAN.md fully. Implement steps 0–N in order within <T> minutes, running the parallel streams as written. Don't ask questions; follow the files. Finish with the review step and the §B9 entry."
2. **Time budget table** that sums to the user's limit. Use **parallel streams** wherever the signatures are fixed in the plan: each subagent gets an exact prompt and a list of the files it owns, and the main agent reviews and joins. Tests (in a subagent) and the TS frontend (main agent) always run in parallel. Include a **cut list** (what to drop if behind; never security, never tests of security).
3. **Step 0, setup:** the commands, copying the taste assets and the font, `.env.*` files, and `.gitignore`.
4. **Step 1, infra + MCP:** composer and npm installs, settings/container/`makePdo` (`Pdo\Mysql::ATTR_INIT_COMMAND`, `EMULATE_PREPARES=false`), Docker, migrate, and the MCP server. It gates on `verify_constraints` all passing.
5. **Steps 2…:** middleware and the session, then models, then repositories (with **exact SQL**, as already verified in P3), then services, then controllers and views, then TS, then tests (a table of name, behaviour and doubles), then review.
6. **Exact schema SQL** (`001_init.sql`), exactly as verified in P3.
7. **The MCP `verify_constraints` table:** case, SQL and expected constraint.
8. **The query budget table:** one row per route and write action (GET cold/warm, every POST), with the expected statement count. Reads: each page is a fixed number of queries whatever the row count (no N+1). Writes: one statement each, unless the plan says why (e.g. a file name that must be read before a soft delete, because MySQL has no `RETURNING`).
9. **Skills per step:** each step names the skills to **invoke**, and its report ends with `Skills run`. Step 0b: the main agent invokes `acc:detect-n-plus-one` and `acc:check-query-efficiency` on the plan's SQL and loops **before** any subagent starts.
10. **The review step:** `composer test`, `composer stan`, typecheck, build, the audits, MCP, `acc:audit-security`, `acc:detect-n-plus-one`, `acc:check-query-efficiency`, the §A7 greps, the query-count run against the budget table (general log), taste `copy-lint` + `consistency.mjs`, and a headless Chrome click-through at 390/768/1024/1440 (CSP violations, horizontal scroll, AJAX with no reload) **plus reading the screenshots**. Then the §B9 entry.

Sizing guide: about 22 tests for a medium app. Steps get roughly 5–12 minutes each. If the user's time is tight, plan **more parallel streams rather than fewer features**, and ask before cutting a feature.

---

## 7. Gotchas already paid for (verified; don't relearn them)

| Area | Gotcha → fix |
|---|---|
| Docker/MySQL 8.4 | `--default-authentication-plugin` was removed in 8.4, and the server won't start with it, so don't pass it. `MYSQL_USER` grants ALL, so create users in `init/01-users.sh`. A host port can already be taken by another app, so check it with `lsof` first. |
| Migrate user | It can't `CREATE DATABASE`; the compose file creates the DB. Split migration statements on `;\n` and stop on the first error. |
| phpdotenv | A regex containing `\d` must be **single-quoted** in `.env` files. Real server variables are visible only through `getenv()` under a web SAPI, so read `getenv()` → `$_ENV` → `$_SERVER`, and build Dotenv with a `PutenvAdapter`. |
| PDO on PHP 8.5 | Use `Pdo\Mysql::ATTR_INIT_COMMAND`; the old `PDO::MYSQL_ATTR_*` constants are deprecated. |
| UUID | A bad UUID string gives MySQL error 1411, so the route regex plus `Uuid::isValid()` are mandatory. v7 order equals insert order, so an admin list can use keyset on the PK. |
| Optimizer | With almost all rows `published`, the status index gets skipped (`ALL` + filesort). Use `FORCE INDEX` for the date list, never for FULLTEXT. Plans on tiny tables lie, so test with about 300 rows. |
| Counters | A counter column on the parent bumps `updated_at` and breaks cache keys, so use a 1:1 table. A **SELECT** of the result runs after COMMIT, outside the transaction; `lastInsertId()` is the opposite and must be read **before** COMMIT. |
| Login | Accent folding (`ädmin@` = `admin@`) gives an attacker fresh counters, so key on the account id. Hashing a dummy *per request* creates a timing oracle, so use a fixed dummy hash. |
| Readonly | Calling `reset()` on a readonly array property is a fatal error, so copy the array to a local first. |
| AJAX flash | A flash must survive the client's navigation, so `respond(..., navigates: true)`. |
| Timestamps | `DATETIME` has one-second precision: an "edited" label or a cache key from `updated_at` misses edits within the same second. Use `DATETIME(3)` if that matters. A render cache key uses a hash of the content (`md.{id}.{sha1(body)}`), never `updated_at`. |
| taste-skill | Dealing arms a landing-page gate whose Stop hook blocks later sessions, so run `gate.mjs --done`. `system.css` styles every `.field input` as a text box, so add a checkbox rule (an inline flex label with min-height 44 px and a 20 px box with `accent-color`) and `.field{grid-template-columns:minmax(0,1fr)}`. The kit's JS files are ES modules. |
| CSP | It blocks Google Fonts, inline `<style>`, `style=""` set through `setAttribute`, and CDNs. Chrome logs a `script-src-attr` report while parsing an inline handler, even inside `setHTML`, but nothing runs. |
| Editor libraries (e.g. EasyMDE) | Set `autoDownloadFontAwesome:false` (bundle font-awesome 4.7 via esbuild file loaders), `spellChecker:false`, `forceSync:true`, `previewRender` returning null (fill the preview from the server), `errorCallback` to the status line (the default is `alert()`), `imageMaxSize` (the default is 2 MB), and remove `required` from the hidden textarea. Its CSS loads after `app.css`, so a 44 px toolbar rule needs one more class (`.EasyMDEContainer .editor-toolbar button { min-width: 44px }`) to win, and a preview pane that reuses the page's `.prose` class needs `margin-block: 0` (otherwise the text shows under the preview). |
| Sanitizer API | The default `setHTML()` removes every `<img>`, so pass an explicit allow-list. |
| TS 7 | A side-effect CSS import gives TS2882, so set `noUncheckedSideEffectImports:false`. |
| Subagents | They skip skills unless told to, so put "invoke skill X" in their prompt and have the main agent re-run the checklist. They need file ownership. |
| Test scripts | Tests must wait for a result, never sleep a fixed time. `requestSubmit()` ignores a disabled button, so overlapping requests are possible in tests. |
| N+1 that slipped through | An admin page listing parents with their children ran one query per parent; a form needing lookups per group did the same. Load all children in **one** query and group them in PHP. Only `acc:detect-n-plus-one` plus a general-log count caught it; reading the code didn't. |
| Query counting | Use `SET GLOBAL general_log=1, log_output='TABLE'`, then count `mysql.general_log` rows for the app user with `command_type IN ('Execute','Query')`, excluding the connection's `SET NAMES`. Wipe `var/cache/app` for the cold run. Wait about 1 s before counting (the log table flushes late). |
| Cache | Cache **one entry per entity** and read a list in **one batch** (`getItems`), with tags per entity and a `lists` tag for id lists. Invalidate only the edited entity. Never cache search or cursor pages (unbounded keys fill the disk). A fully cached page still opens a PDO connection unless PDO is lazy. **Never read → append → write back a cached list:** two writers at once lose an id. Invalidate the list key instead, or read the ids from the DB (index-only) and cache only the entities. **A counter is cached write-through:** after a change, ±1 on the cached value (no DB read), `max(0, …)`, with a short TTL that heals drift from concurrent votes; the DB counter stays exact. **Clear the app cache on every deploy:** cached serialized objects break when their classes change. |
| EXPLAIN | Run `ANALYZE TABLE` after a bulk insert, or stale statistics pick the wrong index. But **never inside a probe transaction**: ANALYZE commits implicitly and saves the probe rows. Clean up seeded rows by name. |
| Browser checks | With `Emulation.setDeviceMetricsOverride(mobile:true)` Chrome widens the viewport to fit overflow, so "no horizontal scroll" passes falsely: use `mobile:false`. Check a short window too (about 650 px tall): fixed one-screen heroes overlap the next section there. Absolutely positioned visually-hidden text escapes a scroll wrapper unless the wrapper is `position: relative`. |
| taste kit + CSP | The kit CSS has `data:` URI glyphs and an inline entrance script that the CSP blocks: replace them with files. The kit's screen-tall section heights leave empty bands on content pages. |
| Uploads in tests | **Never delete files under `public/uploads/` by glob.** Remove only the files the test created, by name (a glob once deleted a user's real upload). |
| Write consistency | Validate the same thing in one place for every resource (e.g. the session user id only in `BaseController`), or reviewers see "inconsistent" checks. |

---

## 8. Review after Sonnet (when the user asks)

1. Run `composer test`, `npm run typecheck`, `npm run build`, `composer audit` and `npm audit --omit=dev`.
2. MCP: `verify_constraints` all PASS, every `verify_<counter>` returns `[]`, `list_routes` matches §B5, and `explain_query` on every name shows no `ALL` (seed about 300 rows temporarily, then remove them).
3. Skills (**invoke** each; list them in `Skills run`): `acc:detect-n-plus-one`, `acc:check-query-efficiency`, `acc:check-sql-injection`, `acc:check-csrf-protection`, `acc:check-authentication`, `acc:check-authorization`, `acc:check-output-encoding`, `acc:check-type-juggling`, `acc:check-path-traversal` (if uploads), `acc:check-test-quality`, `acc:find-type-issues`, `modern-web-guidance` (forms, accessible-error-announcement), taste `copy-lint` + `consistency.mjs`.
4. **Query count** (general log) for every route, cold and warm, and every POST, compared with the plan's budget table. Anything over budget is a finding unless the plan explained it.
5. A headless Chrome click-through over CDP (Node's built-in WebSocket, no dependencies, `mobile:false`) at 390/768/1024/1440 and one short window: no CSP violations, no horizontal scroll, every main flow working by AJAX with no reload, and the test data cleaned up by name afterwards. **Read the screenshots** (empty bands, overlaps, broken images, squeezed messages).
6. Live probes for every write: the happy path, a duplicate, a gone parent, a guest with and without a CSRF token, and the statement count per action.
7. Report a table of check and result, then the findings ranked by severity. **Ask before fixing anything that changes design or security**, and write the fixes as `docs/CHANGES_NNN.md` with a time budget and parallel streams.

---

## B. Default blog design (verified on MySQL 8.4; adapt it to the prompt, don't redesign it)

Start from this design. Remove what the user's features don't need, and add only what they ask for or approve in §2.2. Every table, constraint and query shape below was run in Docker: CHECKs and FKs rejected bad writes, EXPLAIN used the indexes at about 300 rows, and the vote write and login counter were verified under two connections. Change set 004 (fewer queries + caching) was verified the same way on 2026-11-23.

### B.1 Default scope
- **Admin:** logs in; creates, edits and deletes posts (title, markdown body, excerpt, cover image, inline images, draft/published); deletes any comment.
- **User:** signs up and logs in. Must be logged in to comment or upvote. Comments are full CRUD: the owner edits; the owner or an admin deletes. Comments show **newest first, 30 per page**, with "Older comments" (keyset). The upvote button sends the **wanted state** (`voted=1|0`), so a double-click never cancels a vote.
- **Anyone:** reads published posts, searches (FULLTEXT on title and body), and pages with "Older posts" (keyset).
- **Admin editor:** a markdown editor (EasyMDE) with a server-rendered preview (`POST /admin/preview`, same CommonMark settings, not cached) and image drag/paste upload. A default cover image is shown when a post has none (`DEFAULT_COVER`). A "Remove cover" checkbox is offered. An image is deleted once no live post uses it.
- **Out of scope unless asked:** tags, password reset, email, an API, threaded replies.

### B.2 Feature suggestions by blog type (offer in §2.2 question 4; recommend 1–3)
| Type | Suggest |
|---|---|
| Personal / diary | an about page, reading time, drafts, private posts (status `private`) |
| Tech / dev | code-block styling (no client-side highlighter needed), tags, an RSS feed, a dark grade (taste-skill allows dark for tech) |
| Recipe / food | structured fields (prep time, servings) in their own table, an ingredient list, print CSS |
| Travel / photo | bigger covers, a gallery (an images table), image alt text required |
| News / magazine | categories, featured posts, multiple authors (an `author` role) |
| Multi-author / community | a user-post role, a moderation queue, author pages |
For every suggestion, show its cost: tables, routes, tests and minutes.

### B.3 Schema `database/migrations/001_init.sql` (verified; includes `deleted_by`)
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
  id           BINARY(16) NOT NULL PRIMARY KEY,
  author_id    BINARY(16) NOT NULL,
  title        VARCHAR(200) NOT NULL,
  slug         VARCHAR(220) NOT NULL,
  excerpt      VARCHAR(300) NOT NULL DEFAULT '',
  body_md      MEDIUMTEXT NOT NULL,
  cover_image  VARCHAR(255) NULL,
  status       VARCHAR(10) NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  deleted_at   DATETIME NULL,
  deleted_by   BINARY(16) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_status_published (status, published_at),
  KEY idx_posts_author (author_id),
  KEY idx_posts_deleted_by (deleted_by),
  FULLTEXT KEY ft_posts_search (title, body_md),
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_posts_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_posts_status CHECK (status IN ('draft','published','deleted')),
  CONSTRAINT chk_posts_published_at CHECK (status <> 'published' OR published_at IS NOT NULL),
  CONSTRAINT chk_posts_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
  CONSTRAINT chk_posts_deleted_by CHECK (deleted_by IS NULL OR status = 'deleted'),
  CONSTRAINT chk_posts_title CHECK (CHAR_LENGTH(title) BETWEEN 1 AND 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id         BINARY(16) NOT NULL PRIMARY KEY,
  post_id    BINARY(16) NOT NULL,
  user_id    BINARY(16) NOT NULL,
  body       TEXT NOT NULL,
  status     VARCHAR(10) NOT NULL DEFAULT 'visible',
  deleted_at DATETIME NULL,
  deleted_by BINARY(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comments_post_status_created (post_id, status, created_at),
  KEY idx_comments_user (user_id),
  KEY idx_comments_deleted_by (deleted_by),
  CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_comments_status CHECK (status IN ('visible','deleted')),
  CONSTRAINT chk_comments_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
  CONSTRAINT chk_comments_deleted_by CHECK (deleted_by IS NULL OR status = 'deleted'),
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

### B.4 Verified query shapes (repository constants)
Every write is one conditional statement; a lookup runs only on the rare 0-row path. Nothing runs a query inside a loop. All shapes below were run on MySQL 8.4 as the app user.

**Posts**
- **One column list:** two constants, `POST_SELECT_LIST` (`'' AS body_md`) and `POST_SELECT_FULL` (`p.body_md`), with the same `JOIN users u … LEFT JOIN post_vote_counts c …`. Every post query is built from them; never copy the column list.
- **Public list:** `POST_SELECT_LIST` with `FORCE INDEX (idx_posts_status_published)` `WHERE p.status = 'published' [AND (p.published_at < :pa OR (p.published_at = :pa2 AND p.id < UUID_TO_BIN(:id)))] ORDER BY p.published_at DESC, p.id DESC LIMIT :limit`. Fetch `perPage + 1` to know whether there's a next page. The cursor is base64url of `published_at|id`, and an invalid cursor is ignored.
- **Search:** the same list without `FORCE INDEX`, plus `AND MATCH(p.title, p.body_md) AGAINST (:q IN NATURAL LANGUAGE MODE)`, ordered by date.
- **Admin list:** `WHERE p.status IN ('draft','published') [AND p.id < UUID_TO_BIN(:before)] ORDER BY p.id DESC LIMIT :limit` (keyset on the v7 PK).
- **Create:** the post and its `post_vote_counts(post_id, 0)` row in one transaction. Unique slug: **one** query `SELECT slug FROM posts WHERE slug = :base OR slug LIKE :prefix` (`:prefix = addcslashes($base, '%_\\') . '-%'`, so `_` in a title can't match), pick the next free `-N` in PHP, and on a 1062 race compute it again and retry once.
- **Edit** (only changed fields, §4 A6; verified): 
  ```sql
  UPDATE posts SET
      title = COALESCE(:title, title), excerpt = COALESCE(:excerpt, excerpt), body_md = COALESCE(:body, body_md),
      cover_image = CASE WHEN :remove = 1 THEN NULL ELSE COALESCE(:cover, cover_image) END,
      published_at = COALESCE(published_at, IF(COALESCE(:status2, status) = 'published', UTC_TIMESTAMP(), NULL)),
      status = COALESCE(:status, status)
    WHERE id = UUID_TO_BIN(:id) AND status <> 'deleted'
  ```
  A field not sent binds NULL (keep); a sent `''` clears an optional field; `remove_cover=1` sets the cover to NULL; the first `published_at` is kept on republish. A named placeholder can't repeat, hence `:status2`. The service reads the post once before the write (its slug for the cache key, its old body and cover for the image cleanup), so "gone" is known before the UPDATE.
- **Images still in use** (after an edit or delete, one query for all candidate paths): `SELECT j.path FROM JSON_TABLE(:paths, '$[*]' COLUMNS (path VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '$')) AS j WHERE EXISTS (SELECT 1 FROM posts p WHERE p.status <> 'deleted' AND (p.cover_image = j.path OR LOCATE(j.path, p.body_md) > 0))`. The `COLLATE` is required (error 1267 without it). Delete only the paths **not** returned.

**Votes** (wanted state; one short transaction; fixed lock order posts → post_votes → post_vote_counts; retry once on deadlock)
- **On:** `INSERT INTO post_votes (post_id, user_id, status) SELECT p.id, UUID_TO_BIN(:u), 'active' FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published' ON DUPLICATE KEY UPDATE status = 'active'`. Rows 1 (new) or 2 (removed → active) = changed; 0 = already on, or the post isn't published.
- **Off:** `UPDATE post_votes v JOIN posts p ON p.id = v.post_id AND p.status = 'published' SET v.status = 'removed' WHERE v.post_id = UUID_TO_BIN(:p) AND v.user_id = UUID_TO_BIN(:u) AND v.status = 'active'`. Never inserts a row. 1 = changed; 0 = no vote, already off, or not published.
- **Counter, only when changed:** `UPDATE post_vote_counts SET upvotes = upvotes + :d WHERE post_id = UUID_TO_BIN(:p)` (`:d` = ±1 as `PARAM_INT`); it must change exactly 1 row.
- **Only on 0 rows:** `SELECT 1 FROM posts WHERE id = UUID_TO_BIN(:p) AND status = 'published'`. No row = 404; a row = a repeat click (200, nothing changed).
- **The response is `{voted, changed}` with no count:** the page adds or removes 1 itself. Verified: on, on again, off, off again, on → rows 1, 0, 1, 0, 2; two connections voting at once → the second waits (1205) and changes nothing, so the count moves +1 only. **No `ATTR_FOUND_ROWS`** (with it, the repeat "on" returns 1 and double-counts).

**Comments**
- **Ids of one page (index-only, newest first):** `SELECT BIN_TO_UUID(id) AS id, created_at FROM comments WHERE post_id = UUID_TO_BIN(:post) AND status = 'visible' [AND (created_at < :ca OR (created_at = :ca2 AND id < UUID_TO_BIN(:cid)))] ORDER BY comments.created_at DESC, comments.id DESC LIMIT :limit`. **Qualify** `comments.id`: a bare `id` means the `BIN_TO_UUID` alias and causes a filesort. The `id` tie-break is required, because `created_at` has one-second precision.
- **Bodies of the ids missing from the cache, in one query:** the comment columns `… WHERE c.status = 'visible' AND c.id IN (UUID_TO_BIN(:id0), …)` (placeholders generated in code, at most 100, every value bound). The list keeps the order of the ids; `IN` returns rows in any order.
- **Add:** `INSERT INTO comments (id, post_id, user_id, body) SELECT UUID_TO_BIN(:id), p.id, UUID_TO_BIN(:u), :b FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published'`; 0 rows = 404. Then one PK read of the new comment (for the JSON and the cache).
- **Edit (owner):** `UPDATE comments SET body = :body WHERE id = … AND status = 'visible' AND user_id = UUID_TO_BIN(:actor)`, then one PK read. On 0 rows that read decides: not found → 404, someone else's → 403, otherwise the same text.
- **Delete (owner or admin):** `UPDATE comments SET status = 'deleted', deleted_at = UTC_TIMESTAMP(), deleted_by = UUID_TO_BIN(:actor) WHERE id = … AND status = 'visible' AND (user_id = UUID_TO_BIN(:actor2) OR :is_admin = 1)`. 1 row = done (1 query). Only on 0 rows, one read decides 404 or 403.

**Accounts**
- **Register:** a plain INSERT; the UNIQUE email answers "already registered" (catch 1062 → field error). No pre-check SELECT, no re-read (build the `User` from the values you have).
- **Login counter:** `INSERT INTO login_attempts (throttle_key,failures,last_failed_at) VALUES (UNHEX(:k), LAST_INSERT_ID(1), :now) AS new ON DUPLICATE KEY UPDATE failures = LAST_INSERT_ID(IF(login_attempts.last_failed_at < new.last_failed_at - INTERVAL :window SECOND, 1, login_attempts.failures + 1)), last_failed_at = new.last_failed_at`. The `failures` assignment must come first. Read the new count from `lastInsertId()` right after the statement.
  - Lock: `UPDATE … SET locked_until = GREATEST(COALESCE(locked_until,:u),:u2)`.
  - Reset: `UPDATE … SET failures=0, locked_until=NULL`.
  - Serialize: `SELECT GET_LOCK(:name, :t)`, with the name `'login:' . $key` built in PHP and bound. Don't concatenate with `||` in SQL: in MySQL that's OR.

### B.5 Routes (`{id}` = the UUIDv7 regex constant in `config/routes.php`)
| Method | Path | Handler | Role |
|---|---|---|---|
| GET | `/` (`?q=&cursor=`) | PostController::index (HTML, or JSON for search and Older posts) | - |
| GET | `/posts/{slug:[a-z0-9-]+}` (`?comments_before=`) | PostController::show (HTML, or JSON for "Older comments") | - |
| GET/POST | `/login`, `/register` | AuthController (guest only) | - |
| POST | `/logout` | AuthController::logout | user |
| POST | `/posts/{id}/comments` | CommentController::store | user |
| POST | `/posts/{id}/vote` | VoteController::vote (body `voted=1\|0`, JSON `{voted, changed}`) | user |
| POST | `/comments/{id}` | CommentController::update (owner) | user |
| POST | `/comments/{id}/delete` | CommentController::destroy (owner or admin; soft delete) | user |
| GET | `/admin` (`?before=`) | AdminPostController::index | admin |
| GET/POST | `/admin/posts/new`, `/admin/posts` | create/store | admin |
| GET/POST | `/admin/posts/{id}/edit`, `/admin/posts/{id}` | edit/update (only changed fields; `remove_cover` checkbox) | admin |
| POST | `/admin/posts/{id}/delete` | destroy (soft delete with `deleted_by`, then delete unused images) | admin |
| POST | `/admin/media` | uploadMedia → JSON `{url, markdown}` | admin |
| POST | `/admin/preview` | preview → JSON `{ok, html}` | admin |

### B.6 Directory tree (≈48 PHP files, 3 TS, 8 Twig, 9 test files)
```
bin/{migrate.php, mcp-server.php}
config/{container.php, php.prod.ini, routes.php, settings.php}
database/{migrations/001_init.sql, seed.sql}
docker/mysql/{conf.d/app.cnf, init/01-users.sh}
docs/IMPLEMENTATION_PLAN.md
frontend/src/{http.ts (send<T> + guards), main.ts (data-ajax handlers, dialog, cover preview), editor.ts (EasyMDE + preview)}
public/{index.php, .htaccess, uploads/.htaccess, assets/{app.css, default-cover.jpg, fonts/, vendor/taste/}}
src/Controller/{BaseController, AuthController, PostController, AdminPostController, CommentController, VoteController}.php
src/Exception/{ValidationException, NotFoundException, ForbiddenException}.php
src/Http/Session.php, src/Http/Middleware/{SecurityHeaders, Error, Session, Csrf, Route}Middleware.php
src/Mcp/DevTools.php
src/Model/{User, Post, Comment}.php + enums {Role (satisfies()), PostStatus, CommentStatus, VoteStatus}.php
src/Repository/{User, Post, Comment, Vote, LoginAttempt}RepositoryInterface.php + Pdo* implementations
src/Service/{AuthService, LoginThrottle, PostService, CommentService, VoteService, ImageUploader, MarkdownRendererInterface, CommonMarkRenderer, CachedMarkdownRenderer}.php
tests/Unit/{AuthService, CommentService, CsrfMiddleware, ImageUploader, LoginThrottle, PostService, Role, Session, VoteService}Test.php
views/{layout, error}.twig, views/admin/{form, index}.twig, views/auth/{login, register}.twig, views/post/{index, show}.twig
.env, .env.example, .env.local, .env.dev, .env.prod, .gitignore, .mcp.json, composer.json, package.json, tsconfig.json, phpunit.xml, docker-compose.yml, README.md, CLAUDE.md
```

### B.7 Env (`.env.example`)
`APP_ENV, APP_DEBUG, APP_URL, DB_HOST, DB_PORT, DB_ROOT_PASS, DB_NAME, DB_USER, DB_PASS, DB_MIGRATE_USER, DB_MIGRATE_PASS, DB_SSL_CA, SESSION_NAME, SESSION_SECURE, SESSION_LIFETIME, PASSWORD_REGEX='…', PASSWORD_HINT='…', LOGIN_FREE_ATTEMPTS=5, LOGIN_IP_FREE_ATTEMPTS=20, LOGIN_BACKOFF_BASE_SECONDS=60, LOGIN_BACKOFF_MAX_SECONDS=7200, UPLOAD_MAX_BYTES, UPLOAD_ALLOWED_MIMES, DEFAULT_COVER, CACHE_DRIVER, CACHE_TTL, CACHE_COUNT_TTL=300, TWIG_CACHE, POSTS_PER_PAGE, COMMENTS_PER_PAGE=30, COMMENT_MAX_LENGTH, ADMIN_EMAIL, ADMIN_PASSWORD`

### B.8 Cache (ask the user per service in §2.2 question 5; these are the recommended answers)
PSR-16 via symfony/cache (filesystem locally, APCu in prod). The DB stays the source of truth; every write removes or updates exactly the keys it makes stale.

| Service | Key → value | Recommended strategy | Removed / updated |
|---|---|---|---|
| Post page | `post.{slug}` → `['post' => Post, 'html' => string]` | cache-aside; only published posts (a draft is never cached). Validate the slug with `^[a-z0-9-]{1,220}$` first: no query for junk, and a safe key | deleted on post edit or delete (the service has the slug) |
| Home list, page 1 | `posts.home` → the first-page array | cache-aside, TTL `CACHE_COUNT_TTL`. **Never cache search or cursor pages** | deleted on any post create, edit or delete |
| Comments | `comment.{id}` → `Comment` | **ids always from the DB** (index-only); bodies from the cache with `getMultiple`; misses in **one** `IN` query, then `setMultiple`. **Never cache the id list** (read → append → write loses ids when two people comment at once) | set on add and edit; **deleted** on delete |
| Vote count | `votes.{postId}` → int | write-through: a changed vote does `±1` on the cached value (`max(0, …)`, no DB read). Not atomic, so concurrent votes can be off by one until the TTL `CACHE_COUNT_TTL`; the DB counter stays exact | updated in place on every changed vote |
| Has voted | `voted.{postId}.{userId}` → bool | write-through: after **every** vote, set it to the wanted state (it's exact, because only that user changes it). TTL `CACHE_COUNT_TTL` | set on every vote |
| Rendered markdown | `md.{id}.{sha1(body_md)}` → HTML | a decorator over the renderer; the content hash changes on every edit (`updated_at` has one-second precision, so two saves in one second would serve the old HTML) | never (new key per body) |

- With all of the defaults, a post page view costs **1 query** (the comment ids) for guests and logged-in users alike.
- The editor preview is **never** cached. Twig templates, the compiled PHP-DI container and OPcache are cached in prod.
- Login throttle state lives in the DB, never in the cache (it must be atomic).
- **Known, accepted gap:** a reader that loaded an entity just before an admin save can write the old copy back after the save cleared the key; it stays until the key's TTL. Give entity keys a bounded TTL if that matters.
- **On every deploy, clear the app cache** (`rm -rf var/cache/app/*`; with APCu restart PHP-FPM): cached `Post`/`Comment` objects break when their classes change.

### B.9 Default tests (≈38)
- **AuthService:** login works; a wrong password fails; an IP lock refuses even the correct password; a weak password is rejected on sign-up; a duplicate email is rejected (the repository throws 1062).
- **LoginThrottle:** the lock doubles after the free attempts and caps at the max; a failure at the threshold extends the lock.
- **Session:** an active session is kept; an idle one is logged out (`MockClock`).
- **CsrfMiddleware:** a missing token gives 403; a valid token passes; a cross-site request gives 403 even with a valid token.
- **Role:** admin satisfies user, and not the other way round.
- **CommentService:** add on a published post; empty rejected; add on an unpublished post → 404; the owner can update; others can't update or delete; an admin can delete (`deleted_by` recorded); delete removes the cached comment; a list fetches only the uncached comments, in one query, in id order.
- **VoteService:** a changed vote updates the cached count without a query; a repeat vote keeps it; an unpublished post throws; the count comes from the cache without a query; has-voted is set in the cache after a vote.
- **PostService:** slug suffix from one `slugsLike` call; missing slug throws; "remove cover" deletes an unused file; a cover still used elsewhere is kept; fields not sent pass `null` (keep) to the repository; nothing sent is a 422; a published post is read once, then served from the cache; an edit clears the cached post.
- **ImageUploader:** a disallowed MIME is rejected; a valid PNG is stored with a random name; `delete()` ignores names it didn't generate.

### B.10 MCP `verify_constraints` cases (expected detail)
1. Post status `abcd` → `chk_posts_status`.
2. Role `superuser` → `chk_users_role`.
3. Comment status `hidden` → `chk_comments_status`.
4. Vote status `abcd` → `chk_votes_status`.
5. Published post without `published_at` → `chk_posts_published_at`.
6. Deleted post without `deleted_at` → `chk_posts_deleted_at`.
7. Deleted comment without `deleted_at` → `chk_comments_deleted_at`.
8. Empty title → `chk_posts_title`.
9. Empty comment → `chk_comments_body`.
10. Duplicate vote → `post_votes.PRIMARY`.
11. `DELETE FROM posts` → "DELETE command denied".
12. Counter − 1 at 0 → "out of range".
13. Counter row for an unknown post → "foreign key constraint fails".
14. `deleted_by` on a visible comment → `chk_comments_deleted_by`.
15. `deleted_by` with an unknown user → "foreign key constraint fails".

### B.11 Default plan shape (60 min; scale it to the user's limit)
| Step | Min | Parallel |
|---|---|---|
| 0 Setup (files, env, taste assets, font) | 3 | |
| 1 Infra + DB + MCP (gate: verify_constraints) | 8 | |
| 2 Middleware + Session + BaseController | 8 | ‖ subagent: Models + Repositories (exact SQL from the plan) |
| 3 Services (Auth, Throttle, Post, Comment, Vote, Uploader, Markdown) | 11 | |
| 4 Controllers + Twig views | 10 | |
| 5 TS (http.ts, main.ts, editor.ts) | 10 | ‖ subagent: tests (owns `tests/`) |
| 6 Review (audits, MCP, headless Chrome, §B9) | 10 | |
If the time is under 45 min, propose cutting the editor/preview and the votes first (ask). Never cut security or its tests.

### B.12 Query budget (write it into the plan; measure with the general log, §7)
| Route / action | Cold cache | Warm cache |
|---|---|---|
| `GET /` (page 1) | 1 | 0 |
| `GET /?q=…` or `?cursor=…` | 1 | 1 (never cached) |
| `GET /posts/{slug}` (guest or user) | post 1 + count 1 + comment ids 1 + comment batch 1 + has-voted 1 | 1 (comment ids) |
| `GET /posts/{slug}?comments_before=` (JSON) | ids 1 + batch ≤1 | 1 |
| `POST /posts/{id}/vote` | changed: 2 (vote row + counter) · repeat or unpublished: 2 (0-row write + published check) | same |
| `POST /posts/{id}/comments` | 2 (guarded insert + PK read) | same |
| `POST /comments/{id}` (edit) | 2 (conditional update + PK read) | same |
| `POST /comments/{id}/delete` | 1 (+1 only on 0 rows, for 404 vs 403) | same |
| `POST /register` | 1 (INSERT; 1062 → 422) | same |
| `POST /login` | a fixed number (2 locks, 2 counters, 1 user lookup, reset or record); no loop | same |
| `GET /admin` | 1 | 1 (admin pages aren't cached) |
| `POST /admin/posts` (create) | slug family 1 + post insert 1 + counter insert 1 | same |
| `POST /admin/posts/{id}` (edit) | read 1 + update 1 + images-in-use 1 (only when an image was dropped) | same |
| `POST /admin/posts/{id}/delete` | read 1 + soft delete 1 + images-in-use 1 | same |
Anything over budget is a finding in the review (§8) unless the plan explains it.

