// Progressive enhancement: every form works without this file. Here they run without a page reload.
import {
  HttpError,
  ValidationError,
  defaultCover,
  isCommentPage,
  isCommentResult,
  isDeleted,
  isInternalPath,
  isPostPage,
  isRedirect,
  isVoteResult,
  send,
  type CommentData,
  type PostCard,
} from './http';

type Handler = (form: HTMLFormElement, body: FormData) => Promise<void>;

function find<T extends Element>(root: ParentNode, selector: string, type: new () => T): T | null {
  const node = root.querySelector(selector);
  return node instanceof type ? node : null;
}

function byId<T extends Element>(id: string, type: new () => T): T | null {
  const node = document.getElementById(id);
  return node instanceof type ? node : null;
}

/** Fill a <template>: the clone is only ever written with textContent / setAttribute, never innerHTML. */
function cloneTemplate(id: string): HTMLElement | null {
  const tpl = byId(id, HTMLTemplateElement);
  const first = tpl?.content.firstElementChild;
  const copy = first?.cloneNode(true);
  return copy instanceof HTMLElement ? copy : null;
}

// ---------- status line and field errors ----------

function statusLine(form: HTMLElement | null): HTMLElement | null {
  return (
    form?.querySelector<HTMLElement>('[data-form-status]') ??
    document.getElementById('admin-status') ??
    document.getElementById('flash')
  );
}

function setStatus(form: HTMLElement | null, text: string): void {
  const line = statusLine(form);
  if (line) line.textContent = text;
}

function linkError(control: Element, errorId: string, shown: boolean): void {
  const ids = (control.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((id) => id !== '' && id !== errorId);
  if (shown) ids.push(errorId);
  if (ids.length > 0) control.setAttribute('aria-describedby', ids.join(' '));
  else control.removeAttribute('aria-describedby');
}

function controlFor(error: HTMLElement): HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null {
  const control = error.closest('.field')?.querySelector('input, textarea, select');
  return control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement
    ? control
    : null;
}

function clearFieldError(error: HTMLElement): void {
  error.textContent = '';
  error.hidden = true;
  const control = controlFor(error);
  if (control) {
    control.setAttribute('aria-invalid', 'false');
    if (error.id !== '') linkError(control, error.id, false);
  }
}

function clearErrors(form: HTMLElement): void {
  form.querySelectorAll<HTMLElement>('[data-error-for]').forEach(clearFieldError);
  setStatus(form, '');
}

function showValidation(form: HTMLElement, errors: Record<string, string>): void {
  let firstInvalid: HTMLElement | null = null;
  const formWide: string[] = [];

  for (const [field, message] of Object.entries(errors)) {
    const error = form.querySelector<HTMLElement>(`[data-error-for="${CSS.escape(field)}"]`);
    if (error === null) {
      formWide.push(message);
      continue;
    }
    error.textContent = message;
    error.hidden = false;
    const control = controlFor(error);
    if (control) {
      control.setAttribute('aria-invalid', 'true');
      if (error.id !== '') linkError(control, error.id, true);
      firstInvalid ??= control;
    }
  }
  if (formWide.length > 0) setStatus(form, formWide.join(' '));
  firstInvalid?.focus();
}

function showError(form: HTMLElement, err: unknown): void {
  if (err instanceof ValidationError) showValidation(form, err.errors);
  else if (err instanceof HttpError) setStatus(form, err.message);
  else setStatus(form, 'The network failed. What you typed is still here; try again.');
}

// ---------- confirm dialog (native modal) ----------

async function confirmDialog(message: string): Promise<boolean> {
  const dialog = byId('confirm-dialog', HTMLDialogElement);
  if (dialog === null) return window.confirm(message);
  const title = document.getElementById('confirm-title');
  if (title) title.textContent = message;
  dialog.returnValue = '';
  dialog.showModal();
  await new Promise<void>((resolve) => dialog.addEventListener('close', () => resolve(), { once: true }));
  const confirmed = dialog.returnValue === 'confirm';
  dialog.returnValue = '';
  return confirmed;
}

// ---------- DOM builders ----------

function navigate(path: string): void {
  location.assign(isInternalPath(path) ? path : '/');
}

function removeById(id: string): void {
  for (const node of document.querySelectorAll<HTMLElement>('[data-id]')) {
    if (node.dataset['id'] === id) node.remove();
  }
  const list = document.getElementById('comment-list');
  const empty = document.getElementById('no-comments');
  if (list && empty) empty.hidden = list.children.length > 0;
}

function fillComment(li: HTMLElement, c: CommentData): void {
  li.dataset['id'] = c.id;
  const set = (selector: string, text: string): void => {
    const node = li.querySelector(selector);
    if (node) node.textContent = text;
  };
  set('.comment__author', c.userName);
  set('.comment__date', c.createdAt);
  set('.comment__body', c.body);
  const edited = li.querySelector<HTMLElement>('.comment__edited');
  if (edited) edited.hidden = !c.edited;

  const editForm = li.querySelector<HTMLFormElement>('form[data-ajax="comment-update"]');
  const deleteForm = li.querySelector<HTMLFormElement>('form[data-ajax="comment-delete"]');
  if (c.canEdit && editForm) {
    editForm.setAttribute('action', `/comments/${c.id}`);
    const textarea = editForm.querySelector('textarea');
    const label = editForm.querySelector('label');
    const error = editForm.querySelector('[data-error-for]');
    textarea?.setAttribute('id', `edit-${c.id}`);
    if (textarea) textarea.value = c.body;
    label?.setAttribute('for', `edit-${c.id}`);
    error?.setAttribute('id', `edit-${c.id}-err`);
  } else {
    li.querySelector('details')?.remove();
  }
  if (c.canDelete && deleteForm) deleteForm.setAttribute('action', `/comments/${c.id}/delete`);
  else deleteForm?.remove();
}

function buildCard(p: PostCard): HTMLElement | null {
  const card = cloneTemplate('card-tpl');
  if (card === null) return null;
  card.setAttribute('href', `/posts/${encodeURIComponent(p.slug)}`);
  const title = card.querySelector('h2');
  if (title) title.textContent = p.title;
  const facts = card.querySelector('.tile__facts');
  if (facts) facts.textContent = `${p.authorName} · ${p.publishedAt} · ${p.upvotes} upvotes`;

  const excerpt = card.querySelector('.post-card__excerpt');
  if (excerpt) {
    if (p.excerpt === '') excerpt.remove();
    else excerpt.textContent = p.excerpt;
  }
  const media = card.querySelector<HTMLElement>('.tile__media');
  const img = media?.querySelector('img');
  const cover = p.coverImage !== null && isInternalPath(p.coverImage) ? p.coverImage : defaultCover();
  if (media && img && cover !== '') img.setAttribute('src', cover);
  return card;
}

function appendCards(posts: PostCard[], replace: boolean): void {
  const list = document.getElementById('card-list');
  if (list === null) return;
  const cards = posts.map(buildCard).filter((c): c is HTMLElement => c !== null);
  if (replace) list.replaceChildren(...cards);
  else list.append(...cards);
  const empty = document.getElementById('no-posts');
  if (empty) empty.hidden = list.children.length > 0;
}

function updateLoadMore(nextCursor: string | null, q: string): void {
  const pager = document.querySelector('.pager');
  if (pager === null) return;
  let link = pager.querySelector<HTMLAnchorElement>('a[data-load-more]');
  if (nextCursor === null) {
    link?.remove();
    return;
  }
  if (link === null) {
    link = document.createElement('a');
    link.className = 'btn btn--secondary';
    link.textContent = 'Older posts';
    link.setAttribute('data-load-more', '');
    pager.prepend(link);
  }
  const params = new URLSearchParams({ cursor: nextCursor });
  if (q !== '') params.set('q', q);
  link.setAttribute('href', `/?${params.toString()}`);
}

// ---------- form handlers (one per data-ajax value) ----------

const handlers: Record<string, Handler> = {
  async vote(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isVoteResult);
    // The server sends no count: add or remove this user's vote. Other users' votes show on the next page load.
    const count = form.querySelector('[data-vote-count]');
    const shown = Number.parseInt(count?.textContent ?? '', 10);
    if (count && r.changed && Number.isFinite(shown)) count.textContent = String(Math.max(0, shown + (r.voted ? 1 : -1)));
    form.querySelector('button[type="submit"]')?.setAttribute('aria-pressed', String(r.voted));
    const wanted = form.querySelector<HTMLInputElement>('[data-vote-wanted]');
    if (wanted) wanted.value = r.voted ? '0' : '1';
    setStatus(form, r.voted ? 'Upvoted.' : 'Upvote removed.');
  },

  async 'comment-create'(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isCommentResult);
    const li = cloneTemplate('comment-tpl');
    const list = document.getElementById('comment-list');
    if (li && list) {
      fillComment(li, r.comment);
      list.prepend(li);
      const empty = document.getElementById('no-comments');
      if (empty) empty.hidden = true;
    }
    form.reset();
    setStatus(form, 'Comment added.');
  },

  async 'comment-update'(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isCommentResult);
    const li = form.closest('li');
    const text = li?.querySelector('.comment__body');
    if (text) text.textContent = r.comment.body;
    const edited = li?.querySelector<HTMLElement>('.comment__edited');
    if (edited) edited.hidden = !r.comment.edited;
    const details = form.closest('details');
    if (details) details.open = false;
  },

  async 'comment-delete'(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isDeleted);
    removeById(r.id);
    setStatus(document.getElementById('comments'), 'Comment deleted.');
  },

  async 'post-delete'(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isDeleted);
    removeById(r.id);
    setStatus(null, 'Post deleted.');
  },

  async form(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isRedirect);
    navigate(r.redirect);
  },

  async logout(form, body) {
    const r = await send(form.action, { method: 'POST', body }, isRedirect);
    navigate(r.redirect);
  },

  async search(form, body) {
    // A GET carries no body: the query goes in the URL.
    const params = new URLSearchParams();
    for (const [key, value] of body.entries()) {
      if (typeof value === 'string' && value !== '') params.append(key, value);
    }
    const query = params.toString();
    const r = await send(query === '' ? '/' : `/?${query}`, { method: 'GET' }, isPostPage);
    appendCards(r.posts, true);
    updateLoadMore(r.nextCursor, params.get('q') ?? '');
    document.querySelector('.pager a.link')?.remove();
    history.replaceState(null, '', query === '' ? '/' : `/?${query}`);
    setStatus(form, r.posts.length === 0 ? 'No posts found.' : `${r.posts.length} posts shown.`);
  },
};

// ---------- wiring ----------

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const kind = form.dataset['ajax'];
  const handler = kind === undefined ? undefined : handlers[kind];
  if (handler === undefined) return;
  // The search form falls back to a normal GET on pages without a card list.
  if (kind === 'search' && document.getElementById('card-list') === null) return;

  event.preventDefault();
  void run(form, handler);
});

async function run(form: HTMLFormElement, handler: Handler): Promise<void> {
  const message = form.dataset['confirm'];
  if (message !== undefined && !(await confirmDialog(message))) return;

  clearErrors(form);
  const body = new FormData(form);
  const buttons = form.querySelectorAll<HTMLButtonElement>('button[type="submit"]');
  buttons.forEach((b) => (b.disabled = true)); // no double-click, no double-toggle
  try {
    await handler(form, body);
  } catch (err) {
    showError(form, err);
  } finally {
    buttons.forEach((b) => (b.disabled = false));
  }
}

// "Older posts": fetch the link's own URL as JSON and append.
document.addEventListener('click', (event) => {
  const target = event.target;
  const link = target instanceof Element ? target.closest<HTMLAnchorElement>('a[data-load-more]') : null;
  if (link === null) return;
  const href = link.getAttribute('href') ?? '';
  if (!isInternalPath(href)) return;
  event.preventDefault();
  void (async () => {
    try {
      const r = await send(href, { method: 'GET' }, isPostPage);
      appendCards(r.posts, false);
      updateLoadMore(r.nextCursor, new URL(href, location.origin).searchParams.get('q') ?? '');
    } catch (err) {
      setStatus(null, err instanceof HttpError ? err.message : 'The network failed. Try again.');
    }
  })();
});

// "Older comments": fetch the link's own URL as JSON and append below the loaded ones.
document.addEventListener('click', (event) => {
  const target = event.target;
  const link = target instanceof Element ? target.closest<HTMLAnchorElement>('a[data-load-more-comments]') : null;
  if (link === null) return;
  const href = link.getAttribute('href') ?? '';
  if (!isInternalPath(href)) return;
  event.preventDefault();
  void (async () => {
    try {
      const r = await send(href, { method: 'GET' }, isCommentPage);
      const list = document.getElementById('comment-list');
      if (list) {
        for (const c of r.comments) {
          const li = cloneTemplate('comment-tpl');
          if (li === null) continue;
          fillComment(li, c);
          list.append(li);
        }
      }
      if (r.nextCommentCursor === null) {
        link.closest('nav')?.remove();
      } else {
        const params = new URLSearchParams({ comments_before: r.nextCommentCursor });
        const path = new URL(href, location.origin).pathname;
        link.setAttribute('href', `${path}?${params.toString()}#comments`);
      }
    } catch (err) {
      setStatus(document.getElementById('comments'), err instanceof HttpError ? err.message : 'The network failed. Try again.');
    }
  })();
});

// A typed-in fix clears that field's server error right away (validate on submit, clear on input).
document.addEventListener('input', (event) => {
  const control = event.target;
  if (!(control instanceof HTMLElement) || control.getAttribute('aria-invalid') !== 'true') return;
  const error = control.closest('.field')?.querySelector<HTMLElement>('[data-error-for]');
  if (error) clearFieldError(error);
});

// Cover preview: a data: URL is allowed by the CSP (img-src 'self' data:); a blob: URL would not be.
document.addEventListener('change', (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement)) return;

  if (input.matches('[data-cover-preview]')) {
    const file = input.files?.[0];
    const preview = byId('cover-preview', HTMLImageElement);
    if (!file || !preview) return;
    const reader = new FileReader();
    reader.addEventListener('load', () => {
      if (typeof reader.result === 'string') {
        preview.setAttribute('src', reader.result);
        preview.hidden = false;
      }
    });
    reader.readAsDataURL(file);
  }

  if (input.matches('[data-remove-cover]')) {
    const preview = byId('cover-preview', HTMLImageElement);
    const src = input.checked ? preview?.dataset['defaultSrc'] : preview?.dataset['currentSrc'];
    if (preview && src !== undefined && isInternalPath(src)) preview.setAttribute('src', src);
  }

});

// Fields start at aria-invalid="false" (an unset attribute is announced as "invalid" by some readers).
document.querySelectorAll('input, select, textarea').forEach((el) => {
  if (!el.hasAttribute('aria-invalid')) el.setAttribute('aria-invalid', 'false');
});
