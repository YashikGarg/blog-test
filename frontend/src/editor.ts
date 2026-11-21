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
