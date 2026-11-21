// All network access lives here: one typed fetch helper plus runtime type guards for every JSON response.

export type Guard<T> = (v: unknown) => v is T;

export interface CommentData {
  id: string;
  userName: string;
  body: string;
  createdAt: string;
  edited: boolean;
  canEdit: boolean;
  canDelete: boolean;
}

export interface PostCard {
  slug: string;
  title: string;
  excerpt: string;
  coverImage: string | null;
  authorName: string;
  publishedAt: string;
  upvotes: number;
}

export interface VoteResult { ok: true; voted: boolean; count: number }
export interface CommentResult { ok: true; comment: CommentData }
export interface Deleted { ok: true; id: string }
export interface PostPage { ok: true; posts: PostCard[]; nextCursor: string | null }
export interface MediaResult { ok: true; url: string; markdown: string }
export interface Redirect { ok: true; redirect: string }

/** 422: field errors, keyed by field name ("_form" = the form as a whole). */
export class ValidationError extends Error {
  constructor(readonly errors: Record<string, string>) {
    super('Validation failed');
  }
}

/** Any other failed response. The message comes from the server's JSON when it has one. */
export class HttpError extends Error {
  constructor(readonly status: number, message: string) {
    super(message);
  }
}

const isRecord = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);

const isStringRecord = (v: unknown): v is Record<string, string> =>
  isRecord(v) && Object.values(v).every((x) => typeof x === 'string');

/** Internal paths only: starts with a single /, no backslash, no control character (same rule as the server). */
export const isInternalPath = (p: string): boolean => p.startsWith('/') && !p.startsWith('//') && !/[\\\u0000-\u001f\u007f]/.test(p);

const isCommentData = (v: unknown): v is CommentData =>
  isRecord(v) &&
  typeof v['id'] === 'string' &&
  typeof v['userName'] === 'string' &&
  typeof v['body'] === 'string' &&
  typeof v['createdAt'] === 'string' &&
  typeof v['edited'] === 'boolean' &&
  typeof v['canEdit'] === 'boolean' &&
  typeof v['canDelete'] === 'boolean';

const isPostCard = (v: unknown): v is PostCard =>
  isRecord(v) &&
  typeof v['slug'] === 'string' &&
  typeof v['title'] === 'string' &&
  typeof v['excerpt'] === 'string' &&
  (v['coverImage'] === null || typeof v['coverImage'] === 'string') &&
  typeof v['authorName'] === 'string' &&
  typeof v['publishedAt'] === 'string' &&
  typeof v['upvotes'] === 'number';

export const isVoteResult: Guard<VoteResult> = (v): v is VoteResult =>
  isRecord(v) && v['ok'] === true && typeof v['voted'] === 'boolean' && typeof v['count'] === 'number';

export const isCommentResult: Guard<CommentResult> = (v): v is CommentResult =>
  isRecord(v) && v['ok'] === true && isCommentData(v['comment']);

export const isDeleted: Guard<Deleted> = (v): v is Deleted =>
  isRecord(v) && v['ok'] === true && typeof v['id'] === 'string';

export const isPostPage: Guard<PostPage> = (v): v is PostPage =>
  isRecord(v) &&
  v['ok'] === true &&
  Array.isArray(v['posts']) &&
  v['posts'].every(isPostCard) &&
  (v['nextCursor'] === null || typeof v['nextCursor'] === 'string');

export const isMediaResult: Guard<MediaResult> = (v): v is MediaResult =>
  isRecord(v) && v['ok'] === true && typeof v['url'] === 'string' && typeof v['markdown'] === 'string';

export interface PreviewResult { ok: true; html: string }
export const isPreviewResult: Guard<PreviewResult> = (v): v is PreviewResult =>
  isRecord(v) && v['ok'] === true && typeof v['html'] === 'string';

export const isRedirect: Guard<Redirect> = (v): v is Redirect =>
  isRecord(v) && v['ok'] === true && typeof v['redirect'] === 'string';

/** The default cover path from <meta name="default-cover">; empty when missing or not an internal path. */
export const defaultCover = (): string => {
  const v = document.querySelector('meta[name="default-cover"]')?.getAttribute('content') ?? '';
  return isInternalPath(v) ? v : '';
};

function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function errorMessage(json: unknown, status: number): string {
  if (isRecord(json) && isStringRecord(json['errors'])) {
    const first = json['errors']['_form'] ?? Object.values(json['errors'])[0];
    if (first !== undefined) return first;
  }
  return status === 404 ? 'Not found.' : `Something went wrong (${status}). Try again.`;
}

/** The one fetch helper: adds the CSRF header, asks for JSON, parses and guards the answer. */
export async function send<T>(
  url: string,
  init: { method: 'GET' | 'POST'; body?: FormData },
  guard: Guard<T>,
): Promise<T> {
  const options: RequestInit = {
    method: init.method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
  };
  if (init.body !== undefined) options.body = init.body;

  const res = await fetch(url, options);
  const json: unknown = await res.json().catch(() => null);

  if (res.status === 422 && isRecord(json) && isStringRecord(json['errors'])) {
    throw new ValidationError(json['errors']);
  }
  if (!res.ok) throw new HttpError(res.status, errorMessage(json, res.status));
  if (!guard(json)) throw new HttpError(res.status, 'The server sent an unexpected answer.');

  return json;
}
