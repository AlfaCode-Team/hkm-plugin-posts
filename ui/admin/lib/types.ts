// Mirrors the admin API's DTOs byte-for-byte (see API/DTO/*.php — PostView,
// PostPage, RevisionView, CategoryView — and PostEditorialService::capabilities()).
// Field names are the PHP public property names verbatim: the kernel's JSON
// envelope is get_object_vars() on a camelCase DTO, so there is no
// snake_case/camelCase boundary to cross here (unlike File Manager's rows).

export type PostKind = "blog" | "article" | "press";
export type PostStatus = "draft" | "scheduled" | "published" | "trash";
export type BodyFormat = "markdown" | "html" | "text";
export type PostOrder = "created" | "updated" | "published" | "views";

export interface PostCategoryRef {
  id: string;
  slug: string;
  name: string;
}

export interface TocEntry {
  level: number;
  id: string;
  text: string;
}

/**
 * A post as the admin API returns it.
 *
 * `body`/`bodyHtml`/`toc` are present on a single-post read (GET …/{id}) and
 * absent on a LIST row — `PostView::toArray(includeBody: false)` unsets them
 * entirely rather than sending null, so they are optional here on purpose.
 */
export interface PostView {
  id: string;
  kind: PostKind;
  locale: string;
  slug: string;
  path: string;
  translationGroup: string | null;
  title: string;
  subtitle: string | null;
  excerpt: string | null;
  summary: string;
  body?: string | null;
  bodyFormat: BodyFormat;
  bodyHtml?: string | null;
  toc?: TocEntry[];
  readingMinutes: number;
  coverImageUrl: string | null;
  coverImageAlt: string | null;
  authorId: string | null;
  authorName: string | null;
  tags: string[];
  categories: PostCategoryRef[];
  dateline: string | null;
  contact: string | null;
  sourceName: string | null;
  sourceUrl: string | null;
  metaTitle: string | null;
  metaDescription: string | null;
  canonicalUrl: string | null;
  noindex: boolean;
  featured: boolean;
  commentsEnabled: boolean;
  published: boolean;
  publishedAt: string | null;
  status: PostStatus;
  viewCount: number;
  createdBy: string | null;
  createdAt: string;
  updatedAt: string;
  deletedAt: string | null;
  /** Admin views only. Sent back as PostInput.version to detect a concurrent save. */
  version: number;
}

export interface PostListMeta {
  total: number;
  page: number;
  perPage: number;
  lastPage: number;
  hasMore: boolean;
}

export interface PostListResponse {
  data: PostView[];
  meta: PostListMeta;
}

export interface RevisionView {
  number: number;
  title: string;
  note: string | null;
  createdBy: string | null;
  createdAt: string;
  /** Full create/update input of that version. Null on a LIST, present when one is fetched. */
  snapshot: Record<string, unknown> | null;
}

export interface CategoryView {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  parentId: string | null;
  position: number;
  depth: number;
  ancestors: string[];
  postCount: number;
}

export interface EditorCapabilities {
  kinds: PostKind[];
  userId: string;
  userName: string;
  canWrite: boolean;
  canPublish: boolean;
  canManage: boolean;
}

/** kind => status => count. Manage-only (AdminPostController::stats). */
export type PostStats = Record<string, Record<string, number>>;

export type BulkAction = "trash" | "restore" | "purge" | "publish" | "unpublish";

/** id => 'ok' | 'not_found' | 'forbidden' | 'invalid' */
export type BulkOutcome = Record<string, "ok" | "not_found" | "forbidden" | "invalid">;

export interface PreviewLink {
  token: string;
  expiresIn: number;
  url: string;
}

export interface RenderedBody {
  html: string;
  toc: TocEntry[];
}

/**
 * The full create/update input — see README.md "Input keys for create()/update()"
 * and PostInputValidator. PUT is a FULL replace, so the editor always sends
 * every one of these fields.
 */
export interface PostInput {
  kind: PostKind;
  locale?: string;
  slug?: string | null;
  title: string;
  subtitle?: string | null;
  excerpt?: string | null;
  body?: string | null;
  bodyFormat: BodyFormat;
  coverImageUrl?: string | null;
  coverImageAlt?: string | null;
  authorId?: string | null;
  authorName?: string | null;
  tags: string[];
  categories: string[];
  dateline?: string | null;
  contact?: string | null;
  sourceName?: string | null;
  sourceUrl?: string | null;
  metaTitle?: string | null;
  metaDescription?: string | null;
  canonicalUrl?: string | null;
  noindex: boolean;
  featured: boolean;
  commentsEnabled: boolean;
  published: boolean;
  publishedAt?: string | null;
  /** create() only. */
  translationOf?: string;
  /** update() only — recorded on the saved revision. */
  revisionNote?: string;
  /** update() only — the version this form was loaded at; a newer save answers 409. */
  version?: number;
}

/** Field-level 422 errors, keyed exactly as the input (`tags.3`, `categories.0`, `slug`, …). */
export type FieldErrors = Record<string, string | string[]>;
