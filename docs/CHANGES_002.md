# Change set 002: editor, preview, default cover, deleted_by, per-IP throttle

The user approved this change set on 2026-11-21. Implement it exactly as written. Read `CLAUDE.md` first (Parts A and B have already been updated to describe the target state). Rule A1.5 applies: **do not ask about security or design**, and do not add or skip anything. If something here can't work as written, stop and report it (A1.1).

Each claim below was checked in planning:
- the migration SQL ran on a scratch copy of the schema in Docker MySQL 8.4;
- the EasyMDE bundle was built with the project's esbuild and typechecked with its tsc;
- EasyMDE's source was read for CSP problems;
- the nested `GET_LOCK` calls were run as `inkwell_app`.

## Kickoff prompt (paste into Sonnet)

```
Read CLAUDE.md and docs/CHANGES_002.md fully. Implement change set 002 in the order of its sections (1 → 6).
Do not ask questions; follow the file. Use the skills and MCP tools CLAUDE.md §A2 names for each area.
Finish with section 6 (verification) and the §B9 entry. Report anything you could not do as written.
```

## 0. Summary of what changes

| # | Change | Files touched |
|---|---|---|
| 1 | `deleted_by` on posts and comments (who soft-deleted the row) | `database/migrations/002_deleted_by.sql` (new), Post/Comment repositories, interfaces, services, `AdminPostController`, `DevTools`, `CommentServiceTest` |
| 2 | Per-IP login limit across all accounts, on top of the per-IP+account limit | `AuthService`, `config/container.php`, `config/settings.php`, env files, `AuthServiceTest` |
| 3 | Cover files are never deleted; "Remove cover" checkbox; default cover image | `PostService`, `ImageUploader`, `AdminPostController`, views, `main.ts`, settings, env, container, `PostServiceTest` |
| 4 | Markdown editor (EasyMDE) with toolbar, image drag/paste upload | `frontend/src/editor.ts` (new), `http.ts`, `main.ts`, `views/admin/form.twig`, `views/layout.twig`, `package.json`, `tsconfig.json`, `.gitignore`, `app.css` |
| 5 | Server-rendered preview (`POST /admin/preview`) | `config/routes.php`, `AdminPostController`, `PostService`, `MarkdownRendererInterface`, `CommonMarkRenderer`, `CachedMarkdownRenderer` |
| 6 | Verification and docs | none |

New files: exactly **2 source files** (`002_deleted_by.sql`, `frontend/src/editor.ts`) plus **1 moved image** (`public/assets/default-cover.jpg`). The build creates more output (git-ignored): `public/assets/editor.js`, `public/assets/editor.css` and `public/assets/editor-assets/*`. Don't create any other file.

---

## 1. `deleted_by`: who deleted a post or comment

Skills: `mysql-patterns` (read before SQL), `acc:create-repository`. MCP: `db_schema` before, `verify_constraints` after.

### 1.1 Migration `database/migrations/002_deleted_by.sql` (exact, verified)

```sql
ALTER TABLE posts
  ADD COLUMN deleted_by BINARY(16) NULL AFTER deleted_at,
  ADD KEY idx_posts_deleted_by (deleted_by),
  ADD CONSTRAINT fk_posts_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_posts_deleted_by CHECK (deleted_by IS NULL OR status = 'deleted');

ALTER TABLE comments
  ADD COLUMN deleted_by BINARY(16) NULL AFTER deleted_at,
  ADD KEY idx_comments_deleted_by (deleted_by),
  ADD CONSTRAINT fk_comments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_comments_deleted_by CHECK (deleted_by IS NULL OR status = 'deleted');
```

- **Why NULL is allowed:** the local DB already has one deleted comment, and nobody knows who deleted it. NULL means "deleted before 002", which is a real unknown (A6). Every delete from now on sets `deleted_by`. The service always passes the actor, so no code path writes NULL.
- **Verified (scratch DB):**
  - the migration applies while a legacy deleted row exists;
  - an owner soft delete and an admin soft delete each update 1 row;
  - `deleted_by` on a visible comment fails with `3819 chk_comments_deleted_by`;
  - an unknown user fails with `1452 fk_posts_deleted_by`;
  - the comment list still uses `idx_comments_post_status_created`.
- Apply it with `php bin/migrate.php`. Never edit `001_init.sql`.

### 1.2 Repositories

- `PostRepositoryInterface::softDelete(string $id, string $deletedBy): bool`
  `PdoPostRepository`:
  ```sql
  UPDATE posts SET status = 'deleted', deleted_at = UTC_TIMESTAMP(), deleted_by = UUID_TO_BIN(:by)
   WHERE id = UUID_TO_BIN(:id) AND status <> 'deleted'
  ```
- `CommentRepositoryInterface::softDelete(string $id, string $deletedBy): void`
  `PdoCommentRepository`:
  ```sql
  UPDATE comments SET status = 'deleted', deleted_at = UTC_TIMESTAMP(), deleted_by = UUID_TO_BIN(:by)
   WHERE id = UUID_TO_BIN(:id) AND status = 'visible'
  ```
- No read query selects `deleted_by`, and the models don't change. It's an audit column, queried by hand when needed:
  `SELECT BIN_TO_UUID(c.id), u.email, u.role FROM comments c JOIN users u ON u.id = c.deleted_by WHERE c.status = 'deleted'`.

### 1.3 Services and controller

- `PostService::delete(string $id, string $actorId): void` passes `$actorId` to `softDelete`.
- `AdminPostController::destroy` calls `$this->posts->delete($id, $this->userId())`.
- `CommentService::delete(...)` already receives `$actorId`; pass it on: `$this->comments->softDelete($commentId, $actorId)`.

### 1.4 MCP `verify_constraints`: add 2 cases (13 → 15)

Add these to `DevTools::verifyConstraints()`. They run inside the existing rolled-back transaction and use the same `$post`/`$user` fixtures:

| # | case | expected detail | SQL |
|---|---|---|---|
| 14 | `deleted_by on a visible comment` | `chk_comments_deleted_by` | `INSERT INTO comments (id, post_id, user_id, body, status, deleted_by) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:p), UUID_TO_BIN(:u), 'x', 'visible', UUID_TO_BIN(:u2))` with `u2 = $user` |
| 15 | `deleted_by unknown user` | `foreign key constraint fails` | `INSERT INTO posts (id, author_id, title, slug, body_md, status, deleted_at, deleted_by) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:u), 'D', :s, 'b', 'deleted', UTC_TIMESTAMP(), UUID_TO_BIN(:x))` with `x = (string) Uuid::v7()` and a random slug |

Update the tool description to "Run the 15 bad writes".

### 1.5 Test

`CommentServiceTest::test_admin_can_delete_any_comment`: make the comment repository a `createMock` with
`->expects(self::once())->method('softDelete')->with($commentId, $adminId)`.
This proves the deleter is recorded. Don't add a new test.

---

## 2. Per-IP login limit (closes "IP X locks on account A, then attacks account B")

Skills: `acc:check-authentication`, `acc:check-insecure-design`. This is a user-approved security rule (A7 has already been updated).

### 2.1 The rule

- **Two counters**, both in `login_attempts`, both with the same atomic upsert and the same exponential backoff:
  - **account key** (unchanged): `sha1('u:' . $user->id . '|' . $ip)`, or `sha1('e:' . strtolower($email) . '|' . $ip)` for an unknown email. Free failures: `LOGIN_FREE_ATTEMPTS` (5).
  - **IP key** (new): `sha1('ip:' . $ip)`, counted across all accounts. Free failures: `LOGIN_IP_FREE_ATTEMPTS` (20; higher because an office or mobile network can put many real users behind one IP).
  - Backoff for both: `LOGIN_BACKOFF_BASE_SECONDS` / `LOGIN_BACKOFF_MAX_SECONDS`.
- **A successful login resets only the account key, never the IP key.** Otherwise an attacker could log into their own account between guesses and wipe the IP counter. The IP counter only resets after a quiet period longer than the max lock (the existing `IF(last_failed_at < now - window, 1, …)` in the upsert).
- **Locks:** `GET_LOCK` on the IP key first, then the account key (fixed order, so no deadlock). Release them in the reverse order in `finally`. This serializes logins from one IP, so parallel guesses spread over many accounts can't overshoot the IP limit. MySQL allows several named locks per session (verified as `inkwell_app`: both return 1).
- Still a known gap (log it in §B9): a *distributed* attack (many IPs on one account). The user declined a per-account limit across all IPs, because it would let anyone lock a real user out.

### 2.2 Config

- `.env.example`, `.env.local`, `.env.dev`, `.env.prod`: add `LOGIN_IP_FREE_ATTEMPTS=20` after `LOGIN_FREE_ATTEMPTS`.
- `config/settings.php` under `'login'`: `'ip_free_attempts' => $int('LOGIN_IP_FREE_ATTEMPTS', 20),`
- `config/container.php`: replace the `LoginThrottle::class` entry with two named entries and pass both to `AuthService`:

```php
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
```

`LoginThrottle` itself does not change.

### 2.3 `AuthService`

The constructor becomes `(UserRepositoryInterface $users, LoginThrottle $accountThrottle, LoginThrottle $ipThrottle, LoggerInterface $logger, string $passwordRegex, string $passwordHint)`.

Replace `login()`, starting from the line after `$user = $this->users->findByEmail($email);`, with:

```php
$key = $user !== null
    ? sha1('u:' . $user->id . '|' . $ip)
    : sha1('e:' . strtolower($email) . '|' . $ip);
$ipKey = sha1('ip:' . $ip);

// One attempt at a time per IP, then per IP+account (fixed order: no deadlock), so parallel guesses can't pass the checks first.
if (!$this->ipThrottle->acquire($ipKey, 5)) {
    $this->logger->warning('login lock timeout', ['event' => 'auth.lock_timeout', 'ip' => $ip, 'user_id' => $user?->id]);
    throw new ValidationException(['_form' => 'Too many attempts at once, try again']);
}
try {
    if (!$this->accountThrottle->acquire($key, 5)) {
        $this->logger->warning('login lock timeout', ['event' => 'auth.lock_timeout', 'ip' => $ip, 'user_id' => $user?->id]);
        throw new ValidationException(['_form' => 'Too many attempts at once, try again']);
    }
    try {
        $ipWait = $this->ipThrottle->retryAfter($ipKey);
        $wait = max($ipWait, $this->accountThrottle->retryAfter($key));
        if ($wait > 0) {
            $event = $ipWait > 0 ? 'auth.ip_locked' : 'auth.locked';
            $this->logger->warning('login locked', ['event' => $event, 'ip' => $ip, 'user_id' => $user?->id]);
            throw new ValidationException(['_form' => 'Too many attempts. Try again in ' . (int) ceil($wait / 60) . ' minutes.']);
        }

        // Verify against a dummy hash when the user is missing, so timing doesn't reveal which emails exist.
        $valid = password_verify($password, $user->passwordHash ?? self::DUMMY_HASH);
        if ($user === null || !$valid) {
            $this->accountThrottle->recordFailure($key);
            $this->ipThrottle->recordFailure($ipKey);
            $this->logger->warning('login failed', ['event' => 'auth.login_failed', 'ip' => $ip, 'user_id' => $user?->id]);
            throw new ValidationException(['_form' => self::GENERIC_LOGIN_ERROR]);
        }

        // Only the account counter resets. The IP counter must not: an attacker could log into their own account to wipe it.
        $this->accountThrottle->reset($key);

        return $user;
    } finally {
        $this->accountThrottle->release($key);
    }
} finally {
    $this->ipThrottle->release($ipKey);
}
```

The early `filter_var` / empty-password return above stays as it is.

### 2.4 Tests (`AuthServiceTest`)

- Change the `service()` helper to build **two** `LoginThrottle` objects on the same attempts stub: `new LoginThrottle($attempts, $clock, 5, 60, 7200)` and `new LoginThrottle($attempts, $clock, 20, 60, 7200)`. Let the helper take an optional `?LoginAttemptRepositoryInterface $attempts = null`.
- Add **one** test:
  `test_login_is_refused_while_ip_is_locked_even_with_correct_password`
  - Arrange: a stub with `acquire → true` and `lockedUntil` → `willReturnMap([[sha1('ip:127.0.0.1'), $clock->now()->modify('+10 minutes')]])`. Unmatched keys return null, so only the IP is locked. Use a shared `MockClock`.
  - Act: call `login()` with the correct password.
  - Assert: `_form` starts with `Too many attempts`.
  - Keep at most 3 doubles.
- The test count goes from 22 to 23 here (24 after §3).

---

## 3. Covers: never delete files, "Remove cover", default image

Skills: `acc:check-path-traversal` (it confirms the `unlink` is gone), `modern-web-guidance` is not needed (no new web API).

### 3.1 Default cover image

- Move the user's image: `mv public/uploads/default.jpg public/assets/default-cover.jpg`. `public/uploads/*` is git-ignored, so the image only gets committed once it's under `assets/`. Don't resize or re-encode it.
- Env (all 4 files): `DEFAULT_COVER=/assets/default-cover.jpg`. Settings, under `'upload'`: `'default_cover' => $e('DEFAULT_COVER', '/assets/default-cover.jpg'),`
- `posts.cover_image` stays NULL for "no cover", and the default is applied only when rendering. So changing `DEFAULT_COVER` changes every post that has no cover, and nothing is written to the DB.
- `config/container.php`, in the `Environment::class` factory after creating `$twig`:
  `$twig->addGlobal('defaultCover', $s['upload']['default_cover']);` and `$twig->addGlobal('uploadMaxBytes', $s['upload']['max_bytes']);` (the second one is used in §4).
- `views/layout.twig`, next to the csrf meta: `<meta name="default-cover" content="{{ defaultCover }}">`
- `views/post/index.twig`:
  - Always render the card media as `<img src="{{ p.coverImage ?: defaultCover }}" …>`, keeping the `fetchpriority`/`loading` logic. Drop the `{% if p.coverImage %}`.
  - In the `<template id="card-tpl">` the `.tile__media` is no longer `hidden`.
- `views/post/show.twig`: always render `<img class="article__cover" src="{{ post.coverImage ?: defaultCover }}" …>`.
- `frontend/src/main.ts`, in the card builder (it currently has `media?.remove()`): always set the image. Use `p.coverImage` when it's non-null and passes `isInternalPath`; otherwise use the meta value. Add a helper next to `csrfToken()`:
  `const defaultCover = (): string => { const v = document.querySelector('meta[name="default-cover"]')?.getAttribute('content') ?? ''; return isInternalPath(v) ? v : ''; };`
  Remove the `media?.remove()` branch.

### 3.2 Never delete cover files; add "Remove cover"

- `PostService::update`:
  ```php
  $newCover = $this->hasUpload($cover) ? $this->uploader->store($cover) : null;
  $removeCover = ($input['remove_cover'] ?? '') === '1';
  ...
  'cover_image' => $newCover ?? ($removeCover ? null : $existing->coverImage),
  ```
  **Delete the block that calls `$this->uploader->delete(...)`**. Old files stay on disk when a cover is replaced or removed, the same as a soft-deleted post (user decision). A new upload wins over the checkbox.
- `ImageUploader::delete()` now has no callers. Remove it.
- `AdminPostController::FIELDS = ['title', 'body', 'status', 'excerpt', 'remove_cover']`. `body()` returns `''` for an unticked box. `PostService::validate()` ignores the key, and create ignores it too.
- `views/admin/form.twig`, cover field:
  - The preview `<img id="cover-preview">` is always visible: `src="{{ post and post.coverImage ? post.coverImage : defaultCover }}"`, plus `data-default-src="{{ defaultCover }}"` and `data-current-src="{{ post and post.coverImage ? post.coverImage : defaultCover }}"`.
  - Only when `post and post.coverImage`, add:
    ```twig
    <label><input type="checkbox" name="remove_cover" value="1" data-remove-cover> Remove the cover (the default image is shown instead)</label>
    ```
- `main.ts`, in the existing `change` listener: when `input.matches('[data-remove-cover]')`, set `#cover-preview` `src` to `dataset['defaultSrc']` if checked, else `dataset['currentSrc']`. Check both with `isInternalPath`. The existing file-input preview keeps working.

### 3.3 Test (`PostServiceTest`)

Add `test_update_with_remove_cover_clears_cover_and_keeps_file`:
- Arrange:
  - a posts mock whose `findById` returns a Post with `coverImage: '/uploads/old.png'`, expecting `update` once with `self::callback(fn(array $d) => $d['cover_image'] === null)`;
  - a real `ImageUploader` on a temp dir, containing a real file `old.png`.
- Act: `update($id, ['title' => 'T', 'body' => 'B', 'status' => 'draft', 'excerpt' => '', 'remove_cover' => '1'], null)`.
- Assert: `self::assertFileExists($dir . '/old.png')`.
- The test count becomes **24**.

---

## 4. Markdown editor: EasyMDE

Skills: `modern-web-guidance:modern-web-guidance` (forms, accessible error announcement), `taste-skill` system layer for styling only.

### 4.1 Packages and build

- `npm i -E easymde@2.21.0 font-awesome@4.7.0`. Both go under **`dependencies`** (not dev) because they ship in the bundle, and `npm audit --omit=dev` must cover them (A7).
  - EasyMDE: MIT, released 2026-08.
  - Font Awesome 4.7: font under OFL-1.1, CSS under MIT. It's the icon set EasyMDE's toolbar classes expect (`fa fa-bold`, …).
- `tsconfig.json` → `compilerOptions`: add `"noUncheckedSideEffectImports": false`. Without it, TS 7 rejects `import '….css'` with TS2882 (verified).
- `package.json` scripts:
  ```json
  "build": "npm run build:app && npm run build:editor",
  "build:app": "esbuild frontend/src/main.ts --bundle --minify --format=esm --target=es2022 --outfile=public/assets/app.js",
  "build:editor": "esbuild frontend/src/editor.ts --bundle --minify --format=esm --target=es2022 --outfile=public/assets/editor.js --loader:.woff2=file --loader:.woff=file --loader:.ttf=file --loader:.eot=file --loader:.svg=file '--asset-names=editor-assets/[name]-[hash]'",
  ```
  Keep `watch` and `typecheck` as they are.
  - Verified output: `editor.js` is about 341 KB, `editor.css` about 43 KB, and the fonts go to `editor-assets/`. The browser downloads only the 75 KB woff2.
  - `editor.css` uses relative `url("./editor-assets/…")`, so the CSP's `default-src 'self'` covers the fonts.
- `.gitignore`: add `public/assets/editor.js`, `public/assets/editor.css`, `public/assets/editor-assets/`.

### 4.2 Load it only on the post form

- `views/layout.twig`: add `{% block head_extra %}{% endblock %}` right after the `app.js` script tag.
- `views/admin/form.twig`:
  ```twig
  {% block head_extra %}
    <link rel="stylesheet" href="/assets/editor.css">
    <script type="module" src="/assets/editor.js"></script>
  {% endblock %}
  ```
- In the form, delete the whole "Insert an image into the body" field (`#media`, `data-media-upload`, `#media-err`). The editor's upload button and drag/paste replace it. In `main.ts`, delete `uploadMedia()` and its `[data-media-upload]` branch, and drop the `isMediaResult` import from `main.ts` (`editor.ts` imports it now). Keep the `/admin/media` route.
- The body textarea gets `data-max-bytes="{{ uploadMaxBytes }}"`, and its label gets `id="body-label"` (`<label for="body" id="body-label">`), so the editor is named by the visible label. Keep `name="body"` and `#body-err`.

### 4.3 `frontend/src/editor.ts` (new; write it exactly like this, verified)

This exact file was typechecked, bundled and run in headless Chrome 157 under the app's CSP, with fake preview and upload endpoints. All 16 checks passed:
- the editor mounts and its icons load from the bundle;
- `forceSync` keeps the textarea in sync;
- the preview shows the server HTML through `setHTML`, with the image kept and `onerror` removed;
- a 422 on the body sets `aria-invalid`, links the error and moves focus into the editor;
- an earlier invalid field keeps focus;
- typing clears and unlinks the error;
- an upload error goes to the status line with no `alert()`;
- no horizontal scroll at 390 px.

It follows the `modern-web-guidance` guides `sanitize-untrusted-html`, `accessible-error-announcement` and `forms`.

```ts
// Admin post form only: EasyMDE replaces the body textarea. The preview HTML comes from the server's CommonMark,
// so it is exactly what the post page will show. The no-JS form still posts the plain textarea.
import EasyMDE from 'easymde';
import 'easymde/dist/easymde.min.css';
import 'font-awesome/css/font-awesome.min.css';
import { HttpError, ValidationError, isMediaResult, isPreviewResult, send } from './http';

function message(err: unknown, fallback: string): string {
  if (err instanceof ValidationError) return Object.values(err.errors)[0] ?? fallback;
  return err instanceof HttpError ? err.message : fallback;
}

// Everything core CommonMark can output, and nothing else (verified in Chrome 157: the default
// Sanitizer drops every <img>, so it is listed with only src/alt/title).
const PREVIEW_SANITIZER = {
  elements: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'strong', 'em', 'code', 'pre', 'blockquote', 'ul', 'li',
    { name: 'ol', attributes: ['start'] }, { name: 'a', attributes: ['href', 'title'] }, { name: 'img', attributes: ['src', 'alt', 'title'] }],
  attributes: [],
};
type HtmlSetter = { setHTML(html: string, options: { sanitizer: typeof PREVIEW_SANITIZER }): void };
const canSetHTML = (el: Element): el is Element & HtmlSetter => typeof (el as Partial<HtmlSetter>).setHTML === 'function';

/** Server-rendered CommonMark (html_input: escape, allow_unsafe_links: false), the same HTML show.twig prints with |raw.
 *  The browser's Sanitizer API runs on top where it exists (Chrome, Firefox); Safari gets the server-escaped HTML as is. */
function showPreview(target: HTMLElement, html: string): void {
  if (canSetHTML(target)) target.setHTML(html, { sanitizer: PREVIEW_SANITIZER });
  else target.innerHTML = html;
}

const textarea = document.getElementById('body');
const bodyError = document.getElementById('body-err');
if (textarea instanceof HTMLTextAreaElement) {
  // The original textarea is hidden by the editor; a hidden `required` control blocks submit. The server validates the body.
  textarea.required = false;
  const status = textarea.form?.querySelector<HTMLElement>('[data-form-status]') ?? null;

  let latest = 0;
  let timer = 0;
  const renderPreview = (markdown: string, target: HTMLElement): void => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      const mine = ++latest;
      const body = new FormData();
      body.append('body', markdown);
      send('/admin/preview', { method: 'POST', body }, isPreviewResult)
        .then((r) => {
          if (mine === latest) showPreview(target, r.html);
        })
        .catch((err: unknown) => {
          if (mine === latest) target.textContent = message(err, 'Preview failed. Try again.');
        });
    }, 300);
  };

  const editor = new EasyMDE({
    element: textarea,
    forceSync: true, // the textarea always holds the text, so the shared AJAX submit sends it
    autoDownloadFontAwesome: false, // icons are bundled; the CSP blocks the CDN
    spellChecker: false, // it downloads dictionaries from a CDN
    status: false,
    minHeight: '320px',
    sideBySideFullscreen: false,
    previewClass: ['editor-preview', 'prose'],
    uploadImage: true,
    imageAccept: 'image/png, image/jpeg, image/webp, image/gif',
    imageMaxSize: Number(textarea.dataset['maxBytes'] ?? '0') || 3145728, // EasyMDE's own default is 2 MB
    imageUploadFunction: (file, onSuccess, onError) => {
      const body = new FormData();
      body.append('file', file);
      send('/admin/media', { method: 'POST', body }, isMediaResult)
        .then((r) => onSuccess(r.url))
        .catch((err: unknown) => onError(message(err, 'Upload failed. Try again.')));
    },
    // EasyMDE's default is alert(), also for "file too large". The form's status line (role="status") is announced instead.
    errorCallback: (text) => {
      if (status !== null) status.textContent = text;
    },
    previewRender: (markdown, preview) => {
      renderPreview(markdown, preview);
      return null; // we fill the element ourselves when the server answers
    },
    toolbar: ['bold', 'italic', 'heading', '|', 'quote', 'unordered-list', 'ordered-list', '|',
      'link', 'upload-image', '|', 'preview', 'side-by-side', 'fullscreen'],
  });

  // The editor's input is named by the visible label, not an aria-label.
  const input = editor.codemirror.getInputField();
  input.setAttribute('aria-labelledby', 'body-label');
  input.setAttribute('aria-invalid', 'false');

  // main.ts shows and clears #body-err after a save. Mirror that state onto the editor: aria-invalid, and the error
  // linked in aria-describedby only while it is shown. After a failed save main.ts tried to focus the hidden
  // textarea, so focus moves into the editor unless main.ts already focused an earlier invalid field.
  const syncError = (): void => {
    if (bodyError === null) return;
    const shown = !bodyError.hidden && bodyError.textContent !== '';
    input.setAttribute('aria-invalid', shown ? 'true' : 'false');
    if (shown) input.setAttribute('aria-describedby', 'body-err');
    else input.removeAttribute('aria-describedby');
    const active = document.activeElement;
    const otherField = active instanceof HTMLInputElement || active instanceof HTMLSelectElement || active === input;
    if (shown && !otherField) editor.codemirror.focus();
  };
  if (bodyError !== null) {
    new MutationObserver(syncError).observe(bodyError, { attributes: true, attributeFilter: ['hidden'], childList: true, characterData: true, subtree: true });
  }
  syncError(); // a server-rendered error (no-JS re-render) is linked on load

  // Typing clears the body error (CodeMirror edits fire no input event on the textarea); the observer then unlinks it.
  editor.codemirror.on('change', () => {
    if (bodyError !== null && !bodyError.hidden) {
      bodyError.hidden = true;
      bodyError.textContent = '';
    }
  });
}
```

Don't enable `previewImagesInEditor` (it writes `style=""`, which the CSP blocks), `autosave` (it writes with `innerHTML` and uses localStorage), or the `guide` button (it links to an external site). The bundle contains CDN URLs for the spell checker and Font Awesome, but both are disabled above and the CSP would block them anyway. That's expected.

### 4.4 `http.ts`

Add:
```ts
export interface PreviewResult { ok: true; html: string }
export const isPreviewResult: Guard<PreviewResult> = (v): v is PreviewResult =>
  isRecord(v) && v['ok'] === true && typeof v['html'] === 'string';
```

### 4.5 `app.css`

At the end, keep the editor inside the taste system tokens. Only these rules:
```css
/* EasyMDE: match the form tokens; side-by-side is too narrow on phones. */
.EasyMDEContainer .CodeMirror, .editor-toolbar { border-color: var(--line); }
.EasyMDEContainer .CodeMirror { border-radius: 0 0 var(--r-md) var(--r-md); font: inherit; font-size: max(1rem, 1em); } /* ≥16px: iOS does not zoom */
.editor-toolbar { border-radius: var(--r-md) var(--r-md) 0 0; }
.editor-toolbar button { min-width: 44px; min-height: 44px; }
@media (max-width: 767px) { .editor-toolbar .side-by-side, .editor-toolbar .fullscreen { display: none; } }
```
`--line` and `--r-md` already exist in `app.css` (checked). Don't add new tokens.

---

## 5. Server preview `POST /admin/preview`

### 5.1 Route (`config/routes.php`, next to `/admin/media`)

```php
$r->addRoute('POST', '/admin/preview', [AdminPostController::class, 'preview', Role::Admin]);
```
It's admin only, and CsrfMiddleware checks the `X-CSRF-Token` header like any other POST.

### 5.2 Renderer: let callers skip the cache

- `MarkdownRendererInterface::render(string $markdown, ?string $cacheKey): string`, where `null` means "don't cache". Update the docblock.
- `CommonMarkRenderer::render(string $markdown, ?string $cacheKey)`: no other change.
- `CachedMarkdownRenderer::render`: `if ($cacheKey === null) { return $this->inner->render($markdown, null); }`, then the existing logic.
- Previews aren't cached, because every keystroke is a new text and would fill the cache. Published posts keep the key `md.{id}.{updatedAt}` (B7).

### 5.3 Service and controller

- `PostService::preview(string $markdown): string { return $this->markdown->render($markdown, null); }`
- `AdminPostController::preview`:
  ```php
  /** @param array<string,string> $vars */
  public function preview(ServerRequestInterface $request, array $vars): ResponseInterface
  {
      try {
          $input = $this->body($request, ['body']);
      } catch (ValidationException $e) {
          return $this->respondInvalid($request, $e, null, [], '/admin');
      }

      return $this->json(['ok' => true, 'html' => $this->posts->preview($input['body'])]);
  }
  ```
- Safety: the HTML comes from the same CommonMark config as published posts (`html_input: escape`, `allow_unsafe_links: false`). In the browser it's inserted with `setHTML()` and an explicit CommonMark allow-list where the Sanitizer API exists (Chrome 146+, Firefox 148+). Otherwise it falls back to `innerHTML`, which is the one allowed `innerHTML` in TS, the same way `{{ html|raw }}` is the one allowed `|raw` in Twig (A7/A9 have been updated). Don't use the default Sanitizer: in Chrome 157 it removes every `<img>`. Don't add DOMPurify (the user declined it).

---

## 6. Verification (all required before the B9 entry)

1. `php bin/migrate.php`, which applies `002_deleted_by`.
2. MCP:
   - `db_schema` shows `deleted_by`, `fk_*_deleted_by`, `chk_*_deleted_by`;
   - `verify_constraints` is **15/15 PASS**;
   - `verify_vote_counts` returns `[]`;
   - `list_routes` includes `POST /admin/preview`, role admin;
   - `explain_query comments_for_post` still uses `idx_comments_post_status_created`.
3. `composer test`: **24 tests**, green. `npm run typecheck` is clean. `npm run build` produces `app.js`, `editor.js`, `editor.css` and `editor-assets/`. `composer audit` and `npm audit --omit=dev` are clean.
4. `grep -rn "uploader->delete\|unlink(" src/` returns nothing. The §A7 grep rules still pass. In TS, `innerHTML` appears only in `editor.ts`'s `showPreview()` fallback.
5. Headless browser (as in the first build) on `/admin/posts/new` and `/admin/posts/{id}/edit` at 390 px and 1440 px:
   - no CSP violations in the console;
   - toolbar icons are visible;
   - Preview and Side-by-side show the server HTML;
   - pasting or dropping an image inserts `![](/uploads/…)`;
   - saving works without a page reload;
   - saving with an empty body shows `#body-err` and moves focus into the editor;
   - a non-image upload shows its error in the form's status line, with no `alert()`.
   - Expect no CSP reports in normal use. A `script-src-attr` report appears only if the HTML contains an inline handler, which the server's escaping prevents.
6. Manual DB checks:
   - Delete a comment as its owner and another as admin. `SELECT BIN_TO_UUID(deleted_by)` shows the owner id and the admin id.
   - From one IP, fail 4 logins on each of 5 emails (20 in total, each email under its own limit of 5). A sixth email from that IP is then refused with "Too many attempts". The unit test from §2.4 covers the per-key logic; this manual check covers the SQL and the wiring.
   - A post with no cover shows `/assets/default-cover.jpg` on the list, the post page and the AJAX "Older posts" cards. "Remove cover" clears it, and the old file still exists in `public/uploads/`.
7. Update `CLAUDE.md` §B9: change the "planned" entry for 002 to done, with any deviations. Fix any B-section that differs from what you built.
