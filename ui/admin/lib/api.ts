// Thin client for the Posts admin JSON API (`/api/admin/posts…`,
// `/api/admin/post-categories…`). This is a PLAIN JSON API — not a Pageflow
// page — so it is reached with `fetch`, not `router.*`/`resourceRequest`
// (both of those expect a Pageflow page-object response). CSRF is attached by
// hand from `@pageflow/react`'s own resolver, the same token a Pageflow visit
// would send, so the platform's single CsrfTokenLayer verifies either path
// identically (see docs/ai-context/21_CSRF.md).
//
// Response envelope, matching every controller in
// Infrastructure/Http/Controllers/AdminPostController.php /
// AdminCategoryController.php:
//   success  { data, meta? }
//   failure  { error: { code, message, fields? } }   (422 carries `fields`)
import { csrfHeaderName, csrfToken } from "@pageflow/react";
import type {
  BulkAction,
  BulkOutcome,
  CategoryView,
  EditorCapabilities,
  FieldErrors,
  PostInput,
  PostListResponse,
  PostOrder,
  PostStats,
  PostStatus,
  PostView,
  PreviewLink,
  RenderedBody,
  RevisionView,
} from "./types";

export class PostsApiError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly fields?: FieldErrors;

  constructor(status: number, body: { code?: string; message?: string; fields?: FieldErrors } | null) {
    super(body?.message ?? `Request failed (${status}).`);
    this.name = "PostsApiError";
    this.status = status;
    this.code = body?.code;
    this.fields = body?.fields;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

async function send<T>(method: Method, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };

  let payload: string | undefined;
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }

  // GET/HEAD are not state-changing — CsrfTokenLayer never checks them, and
  // sending the header anyway would be harmless but is simply unnecessary.
  if (method !== "GET") {
    const token = csrfToken();
    if (token) headers[csrfHeaderName()] = token;
  }

  const res = await fetch(path, {
    method,
    headers,
    body: payload,
    credentials: "same-origin",
  });

  // 204 No Content (destroy/purge) — nothing to parse.
  if (res.status === 204) {
    return undefined as T;
  }

  let json: unknown = null;
  try {
    json = await res.json();
  } catch {
    // A non-JSON body (e.g. an upstream 502 HTML page) still needs to surface
    // as a transport failure rather than throw inside JSON.parse unhandled.
    if (!res.ok) throw new PostsApiError(res.status, null);
    throw new PostsApiError(res.status, { message: "The server returned an unexpected response." });
  }

  if (!res.ok) {
    const envelope = (json as { error?: { code?: string; message?: string; fields?: FieldErrors } })?.error;
    throw new PostsApiError(res.status, envelope ?? null);
  }

  return json as T;
}

function qs(params: Record<string, string | number | boolean | undefined | null>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === "") continue;
    search.set(key, String(value));
  }
  const s = search.toString();
  return s ? `?${s}` : "";
}

const BASE = "/api/admin/posts";
const CATEGORIES_BASE = "/api/admin/post-categories";

export interface ListPostsQuery {
  kind?: string;
  status?: PostStatus | "all";
  tag?: string;
  q?: string;
  locale?: string;
  category?: string;
  author?: string;
  order?: PostOrder;
  page?: number;
  perPage?: number;
}

export const postsApi = {
  me(): Promise<{ data: EditorCapabilities }> {
    return send("GET", `${BASE}/me`);
  },

  list(query: ListPostsQuery): Promise<PostListResponse> {
    const { status, ...rest } = query;
    return send(
      "GET",
      `${BASE}${qs({ ...rest, status: status && status !== "all" ? status : undefined })}`,
    );
  },

  /** One number per status tab, for the same filters as list() (status aside). */
  counts(query: Omit<ListPostsQuery, "status" | "page" | "perPage" | "order">): Promise<{ data: Record<PostStatus, number> }> {
    return send("GET", `${BASE}/counts${qs(query)}`);
  },

  stats(): Promise<{ data: PostStats }> {
    return send("GET", `${BASE}/stats`);
  },

  suggestSlug(params: { title: string; kind: string; locale?: string; except?: string }): Promise<{ data: { slug: string } }> {
    return send("GET", `${BASE}/slug${qs(params)}`);
  },

  render(body: string, bodyFormat: string): Promise<{ data: RenderedBody }> {
    return send("POST", `${BASE}/render`, { body, bodyFormat });
  },

  bulk(action: BulkAction, ids: string[]): Promise<{ data: BulkOutcome }> {
    return send("POST", `${BASE}/bulk`, { action, ids });
  },

  create(input: PostInput): Promise<{ data: PostView }> {
    return send("POST", BASE, input);
  },

  find(id: string): Promise<{ data: PostView }> {
    return send("GET", `${BASE}/${encodeURIComponent(id)}`);
  },

  update(id: string, input: PostInput): Promise<{ data: PostView }> {
    return send("PUT", `${BASE}/${encodeURIComponent(id)}`, input);
  },

  trash(id: string): Promise<void> {
    return send("DELETE", `${BASE}/${encodeURIComponent(id)}`);
  },

  restore(id: string): Promise<{ data: PostView }> {
    return send("POST", `${BASE}/${encodeURIComponent(id)}/restore`);
  },

  purge(id: string): Promise<void> {
    return send("DELETE", `${BASE}/${encodeURIComponent(id)}/permanent`);
  },

  duplicate(id: string): Promise<{ data: PostView }> {
    return send("POST", `${BASE}/${encodeURIComponent(id)}/duplicate`);
  },

  revisions(id: string): Promise<{ data: RevisionView[] }> {
    return send("GET", `${BASE}/${encodeURIComponent(id)}/revisions`);
  },

  revision(id: string, number: number): Promise<{ data: RevisionView }> {
    return send("GET", `${BASE}/${encodeURIComponent(id)}/revisions/${number}`);
  },

  restoreRevision(id: string, number: number): Promise<{ data: PostView }> {
    return send("POST", `${BASE}/${encodeURIComponent(id)}/revisions/${number}/restore`);
  },

  translations(id: string): Promise<{ data: PostView[] }> {
    return send("GET", `${BASE}/${encodeURIComponent(id)}/translations`);
  },

  previewLink(id: string, ttl?: number): Promise<{ data: PreviewLink }> {
    return send("POST", `${BASE}/${encodeURIComponent(id)}/preview-link`, ttl ? { ttl } : {});
  },
};

export const postCategoriesApi = {
  list(): Promise<{ data: CategoryView[] }> {
    return send("GET", CATEGORIES_BASE);
  },

  create(input: { name: string; slug?: string; description?: string; parentId?: string | null; position?: number }): Promise<{ data: CategoryView }> {
    return send("POST", CATEGORIES_BASE, input);
  },

  update(
    id: string,
    input: { name?: string; slug?: string; description?: string; parentId?: string | null; position?: number },
  ): Promise<{ data: CategoryView }> {
    return send("PUT", `${CATEGORIES_BASE}/${encodeURIComponent(id)}`, input);
  },

  destroy(id: string): Promise<void> {
    return send("DELETE", `${CATEGORIES_BASE}/${encodeURIComponent(id)}`);
  },
};
