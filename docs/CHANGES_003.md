# Change set 003: delete unused images, fix the "Remove cover" checkbox

The user approved this on 2026-11-21. **Time limit: 30 minutes** (change set 002 took about 30 minutes; this one is smaller). Read `CLAUDE.md` first; Parts A and B already describe the target state. Implement exactly what's written here, and don't ask (A1.5). If something can't work as written, stop and report it (A1.1).

This change **reverses one decision from 002**: "uploaded files are never deleted" becomes "an uploaded file is deleted as soon as no live post uses it".

All of it was checked in planning:
- The CSS fix was injected into the real edit page in headless Chrome and screenshotted at 1440 px and 390 px.
- The in-use query ran as `inkwell_app` on the live Docker DB. EXPLAIN shows `range` on `idx_posts_status_published`, and the results were checked against the 7 files in `public/uploads/`.

## Kickoff prompt (paste into Sonnet)

```
Read CLAUDE.md and docs/CHANGES_003.md fully. Implement change set 003 using the parallel plan in "Time budget":
start subagents A and B at once with the exact prompts given there, do stream M yourself, then integrate and verify.
Do not ask questions; follow the file. Finish with section 4 (verification) and the §B9 entry.
```

## Time budget: about 15 minutes with subagents (30 minutes in sequence)

The signatures in §2.2–2.4 are fixed, so three streams can write code at the same time without waiting for each other. Each stream **owns its files**; no two streams edit the same file (CLAUDE.md B9: subagents need file ownership plus a review by the main agent).

| Stream | Who | Owns (only these files) | Work | Min |
|---|---|---|---|---|
| **A** | subagent | `public/assets/app.css`, `src/Service/ImageUploader.php`, `tests/Unit/ImageUploaderTest.php` | §1 CSS; §2.2 `delete()` + `pathsIn()`; §3 ImageUploader test | 8 |
| **B** | subagent | `src/Repository/PostRepositoryInterface.php`, `src/Repository/PdoPostRepository.php`, `src/Mcp/DevTools.php`, `bin/mcp-server.php` | §2.3 `isUploadInUse` + `UPLOAD_IN_USE` + the DevTools whitelist entry | 6 |
| **M** | main agent | `src/Service/PostService.php`, `tests/Unit/PostServiceTest.php` | §2.4 `deleteUnused` / `update` / `delete`, written against the fixed signatures | 8 |
| **Join** | main agent | none | Review A and B (read the diff against this file), `composer test`, then the 2 PostService tests (§3) once B's interface method exists, then §4 | 6 |

Total time is about 8 minutes of parallel work plus 6 minutes of join, so **about 15 minutes**.

- **Why the PostService tests wait for B:** PHPUnit can't stub `isUploadInUse` until the interface has it. Write them in M, and run them at the join.
- **Behind schedule?** Cut only the manual part of §4 (step 4). Never cut code or tests, and never skip the main agent's review of A and B.

**Prompt for subagent A** (copy it as written):
```
Read CLAUDE.md (Part A + B9 last entry) and docs/CHANGES_003.md §1, §2.2 and the ImageUploaderTest part of §3.
Implement exactly that. You own ONLY: public/assets/app.css, src/Service/ImageUploader.php, tests/Unit/ImageUploaderTest.php.
Do not edit any other file. Run `composer test -- --filter ImageUploaderTest` and report the diff summary and result.
```

**Prompt for subagent B** (copy it as written):
```
Read CLAUDE.md (Part A + B9 last entry) and docs/CHANGES_003.md §2.3. Use the mysql-patterns skill before writing SQL.
Implement exactly that. You own ONLY: src/Repository/PostRepositoryInterface.php, src/Repository/PdoPostRepository.php,
src/Mcp/DevTools.php, bin/mcp-server.php. Do not edit any other file. Run `php -l` on each file you changed and report
the diff summary. (The main agent runs MCP explain_query after the join.)
```

Sequential fallback, if subagents aren't available: §1 (3 min), §2 (15), §3 (6), §4 (6), for 30 minutes.

New files: **none**. Files touched: `public/assets/app.css`, `src/Service/ImageUploader.php`, `src/Repository/PostRepositoryInterface.php`, `src/Repository/PdoPostRepository.php`, `src/Service/PostService.php`, `src/Mcp/DevTools.php` (+ `bin/mcp-server.php` description), `tests/Unit/PostServiceTest.php`, `tests/Unit/ImageUploaderTest.php`, `CLAUDE.md` (§B9 only).

---

## 1. The checkbox and the field width (CSS only)

**Bug:** `system.css:1241` gives every `input` inside `.field` the text-field look (`width: 100%`, `height: var(--btn-h)`, border, padding). That includes the "Remove cover" checkbox. It renders as a 44 px box centred on its own line, with its text pushed below (the user's screenshot).

Also, `.field` is a grid with an `auto` column, so a long line in the editor can stretch it past the page's side margin.

**Fix:** add these 3 rules to the end of `public/assets/app.css`, exactly as written:

```css
/* .field is a one-column grid: a long editor line must not widen it past the page gutter. */
.field { grid-template-columns: minmax(0, 1fr); }
/* A checkbox in a field: native box and its text on one line (system.css styles every .field input as a text box). */
.field label:has(> input[type="checkbox"]) { display: flex; align-items: center; gap: var(--s-2); min-height: 44px; font-weight: 400; cursor: pointer; }
.field input[type="checkbox"] { width: 1.25rem; height: 1.25rem; padding: 0; flex: none; accent-color: var(--p-accent); }
```

Verified live:
- The box is 20×20 and sits on the same line as its text. The label row is 44 px tall (a tap target per A9).
- At 390 px the text wraps beside the box, and the form keeps its 24 px side margins.
- The `form.twig` markup doesn't change (input inside the label, text after it, as the `forms` guide asks).

---

## 2. Delete an uploaded image as soon as no live post uses it

### 2.1 The rule

- **"In use"** means the image's path (`/uploads/<32 hex>.<ext>`) appears in a post whose `status` is not `'deleted'`, either as its `cover_image` or anywhere in its `body_md` (inline images).
- **When to check**, always *after* the DB write has succeeded, so the post's new state counts:
  - **Cover replaced** (new upload) or **removed** (checkbox): the old cover path is a candidate.
  - **Body edited:** every upload path in the old body that is no longer in the new body is a candidate.
  - **Post soft-deleted:** the post's cover and every upload path in its body are candidates. The post now has status `deleted`, so it no longer counts.
- Delete each candidate that is **not in use**. One that another live post still uses is kept.
- Only paths that match `^/uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$` are ever deleted, so the default cover (`/assets/…`) and any other path are never touched.
- **Out of scope (record as known gaps in §B9):**
  - Images uploaded in the editor but never saved in a post.
  - Files orphaned before this change (one exists locally: `e48a335f….jpg`).
  - A restore feature (there is none): a soft-deleted post loses its unused images.
  - A race between two admins saving at the same moment (there is one admin).

### 2.2 `ImageUploader`: add `delete()` back, strictly

```php
private const STORED_PATH = '~^/uploads/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$~';

/** Deletes a stored upload. Anything that is not exactly a name this class generated is ignored (no path from input reaches unlink). */
public function delete(string $publicPath): void
{
    if (preg_match(self::STORED_PATH, $publicPath) !== 1) {
        return;
    }
    $file = $this->uploadDir . '/' . basename($publicPath);
    if (is_file($file)) {
        unlink($file);
    }
}

/** @return list<string> every stored-upload path that appears in a markdown body */
public function pathsIn(string $markdown): array
{
    preg_match_all('~/uploads/[a-f0-9]{32}\.(?:jpg|png|webp|gif)~', $markdown, $m);

    return array_values(array_unique($m[0]));
}
```

The regex checks against `/uploads/`, which is `upload.prefix` in settings. Keep it literal, matching how `store()` builds the name: `bin2hex(random_bytes(16))` plus the extension.

### 2.3 Repository: "is this path still used by a live post?"

`PostRepositoryInterface::isUploadInUse(string $publicPath): bool`

`PdoPostRepository`, as a public constant (so `DevTools` can EXPLAIN it, as with the other queries):

```php
public const UPLOAD_IN_USE = "SELECT 1 FROM posts
    WHERE status <> 'deleted' AND (cover_image = :path OR LOCATE(:path2, body_md) > 0)
    LIMIT 1";
```

- Bind `:path` and `:path2` to the same value (native prepares don't allow reusing a name).
- Return `$stmt->fetchColumn() !== false`.
- The planning EXPLAIN gave `range` on `idx_posts_status_published` with `Using where`. `LOCATE` reads `body_md` for the live rows, which is acceptable: it runs only on an admin save or delete, and the table is small. Log this in §B9.
- Add `'upload_in_use' => [PdoPostRepository::UPLOAD_IN_USE, ['path' => '/uploads/x.jpg', 'path2' => '/uploads/x.jpg']],` to the `$queries` map in `DevTools::explainQuery`, and add `upload_in_use` to the tool description's list of names (in `bin/mcp-server.php` if it's listed there).

### 2.4 `PostService`

```php
/** @param list<string> $candidates paths the post no longer references; each is deleted unless another live post uses it */
private function deleteUnused(array $candidates): void
{
    foreach (array_unique($candidates) as $path) {
        if (!$this->posts->isUploadInUse($path)) {
            $this->uploader->delete($path);
        }
    }
}
```

`update()`. Keep everything as it is, and after `$this->posts->update(...)` add:

```php
$newCoverValue = $newCover ?? ($removeCover ? null : $existing->coverImage);
$candidates = array_diff($this->uploader->pathsIn($existing->bodyMd), $this->uploader->pathsIn($data['body_md']));
if ($existing->coverImage !== null && $existing->coverImage !== $newCoverValue) {
    $candidates[] = $existing->coverImage;
}
$this->deleteUnused(array_values($candidates));
```

Use `$newCoverValue` for the `'cover_image'` key too, so it's computed once. Replace the comment "Old files stay on disk (they are never deleted)" with "Unused old files are deleted after the write (deleteUnused)".

`delete()`:

```php
public function delete(string $id, string $actorId): void
{
    $post = $this->getForEdit($id); // NotFoundException if missing or already deleted
    if (!$this->posts->softDelete($id, $actorId)) {
        throw new NotFoundException('Post not found');
    }
    // The post is 'deleted' now, so it no longer counts as using its images.
    $this->deleteUnused([...$this->uploader->pathsIn($post->bodyMd), ...($post->coverImage !== null ? [$post->coverImage] : [])]);
}
```

Update its docblock to: "Soft delete; then its images that no live post uses are deleted."

`create()` doesn't change: nothing is orphaned on create.

---

## 3. Tests (24 → 26)

`PostServiceTest`:
- **Replace** `test_update_with_remove_cover_clears_cover_and_keeps_file` with `test_update_with_remove_cover_deletes_the_unused_old_file`. Arrange it the same way (real `ImageUploader` on a temp dir with a real `<32hex>.png` file; the existing post's `coverImage` is that path; a posts mock or stub with `isUploadInUse → false`). Act: update with `remove_cover = '1'`. Assert: `self::assertFileDoesNotExist(...)`. The file name **must** match the 32-hex pattern, or `delete()` ignores it.
- **Add** `test_update_keeps_old_cover_still_used_by_another_post`: same setup, but `isUploadInUse → true`, then `assertFileExists`.

`ImageUploaderTest`:
- **Add** `test_delete_ignores_paths_that_are_not_stored_uploads`. Put a real file `keep.png` in the temp upload dir, call `delete('/uploads/../uploads/keep.png')` and `delete('/uploads/keep.png')`, then `assertFileExists`.
  - That's two calls but one behaviour ("only generated names are deleted"), which is fine under A8.

Keep the A8 rules: AAA, at most 3 doubles, no conditionals or loops in tests.

---

## 4. Verification

1. **must:** `composer test` passes with 26 tests. `npm run build` and `npm run typecheck` are clean (no TS change is expected; the CSS is static).
2. **must:** MCP `explain_query upload_in_use` uses `idx_posts_status_published` (or the PK), not `type: ALL`. `verify_constraints` 15/15 and `verify_vote_counts` `[]` still pass.
3. **must:** `grep -n "unlink(" src/` matches only `ImageUploader::delete`.
4. **Manual, in the browser:**
   - Edit a post with a cover and tick "Remove cover". The old file disappears from `public/uploads/` (unless another post uses it).
   - Upload a new cover on a post with an old one. The old file disappears.
   - Remove an inline `![](/uploads/…)` from a body and save. That file disappears.
   - Delete a post. Its cover and inline images disappear, but not any image that another post still uses.
   - The checkbox sits beside its text at 390 and 1440 px.
5. Update `CLAUDE.md` §B9: change the 003 entry from "planned" to done, with any deviations.
