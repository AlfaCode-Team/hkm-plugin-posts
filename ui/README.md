# Posts plugin UI — admin editorial surface

```
plugins/Posts/ui/
├─ ui.json                      alias "@posts" + surfaces map { admin: admin/Pages }
├─ index.ts                     barrel — exposes the admin API client + types as @posts
├─ admin/
│  ├─ nav.ts                    "Posts" nav section, gated on the `posts` feature flag
│  ├─ lib/
│  │  ├─ types.ts               mirrors API/DTO/*.php — PostView, PostPage, RevisionView, CategoryView…
│  │  └─ api.ts                 fetch client for /api/admin/posts… and /api/admin/post-categories…
│  └─ Pages/Posts/
│     ├─ Index.tsx              component "Posts/Index"      — list, filters, bulk actions, trash
│     ├─ Editor.tsx             component "Posts/Editor"     — create AND edit (postId null vs set)
│     └─ Categories.tsx         component "Posts/Categories" — tree manager
```

## Why a plain `fetch` client instead of `router.*`/`resourceRequest`

`/api/admin/posts…` and `/api/admin/post-categories…` are the plugin's existing,
tested JSON admin API (`AdminPostController`/`AdminCategoryController`) — a
*plain* JSON API, not a Pageflow page. `resourceRequest`/`useApi` both expect a
Pageflow page-object response (`{component, props, url, version}`); pointing
them at this API would parse the envelope as page props and silently misread
every response. `ui/admin/lib/api.ts` is a small `fetch` wrapper instead,
attaching the same CSRF token (`csrfToken()`/`csrfHeaderName()` from
`@pageflow/react`) a Pageflow visit would, so the platform's one
`CsrfTokenLayer` verifies either path identically.

## How it reaches a project

1. **Federation** — `hkm ui sync` mirrors this `ui/` into
   `frontend/plugins/posts/` and adds the `@posts` alias to
   `tsconfig.plugins.json`.
2. **Admin surface discovery** — `admin/Pages/**/*.tsx` is globbed by the admin
   surface; a project page of the same name overrides it.
3. **Server** — `Infrastructure/Http/Controllers/PostFlowController.php`
   renders the three component names and pre-loads the FIRST paint (editorial
   capabilities, the category tree, and — for Index/Editor — the first page of
   posts / the post being edited) by calling `PostEditorialService` directly,
   the same internal binding `AdminPostController` uses. Every subsequent
   interaction (search, filter, pagination, save, bulk actions, revisions,
   preview links, category writes) talks to the JSON admin API directly.

## Routes (module.json)

| Method · Path | Component | Filter |
|---|---|---|
| GET `/admin/posts` | `Posts/Index` | `posts.editor` |
| GET `/admin/posts/create` | `Posts/Editor` (`postId: null`) | `posts.editor` |
| GET `/admin/posts/{id}/edit` | `Posts/Editor` (`postId` set) | `posts.editor` |
| GET `/admin/posts/categories` | `Posts/Categories` | `posts.editor` |

All four declare `requires: ["http.pageflow"]` (route-level, so only these
routes pull Pageflow into the dependency graph — see the kernel's "Per-route
`requires`" rule) and sit behind the SAME `posts.editor` filter as the JSON
admin API: 401 for a guest, 403 for a signed-in user holding none of
`posts:write` / `posts:publish` / `posts:manage`. That filter answers with the
JSON error envelope unconditionally (it has no HTML-redirect branch) — an
anonymous hit on `/admin/posts` in a browser sees the raw
`{"error":{"code":"unauthorized",...}}` body rather than a login page. That is
existing, tested behaviour (`RequirePostsEditorStage`, unchanged), reused here
exactly as the JSON API already does.

## Capability gating in the UI

`GET /api/admin/posts/me` → `{kinds, userId, canWrite, canPublish, canManage}`
is loaded server-side (via `PostEditorialService::capabilities()`, the same
call the JSON endpoint makes) and threaded through as the `capabilities` prop
on every page, so the UI hides what the signed-in editor cannot do:

- no `canWrite` → no "New post" action, no bulk actions beyond read;
- no `canPublish` (and not a manager) → the Published switch/schedule controls
  are hidden, and a post that is already published or scheduled becomes
  **read-only** in the editor (`PostEditorialService::assertMayPublish` 403s
  on ANY save to such a post for a write-only user, since `update()` is a full
  replace and always sends `published` back unchanged);
- no `canManage` → no category writes, no "author id" override field (bylining
  someone else needs manage), no "delete permanently" / purge, author list
  filter scoped to the signed-in editor's own posts (enforced server-side
  regardless, mirrored client-side so the control is not shown pretending to
  do something it cannot).

## What was not verified in a browser

The admin pages have never been loaded in an actual browser against a running
project. What WAS verified:

- the real boot pipeline compiles all four routes cleanly (`hkm ground probe`);
- a real PHP dev server answers `GET /admin/posts` with the real 401 JSON
  envelope for a guest (`hkm ground serve` + `curl`);
- `tests/Feature/PostsHttpTest.php::test_admin_pages_render_behind_the_editor_door`
  drives all four routes through the REAL kernel (route compiler, dependency
  graph, scoped container, `PostFlowController`) as a guest, an unprivileged
  user and an editor, and asserts the exact Pageflow component name and prop
  shape each one returns;
- `ui/__tests__/posts-{index,editor,categories}.test.tsx` render each page
  component with props DUMPED from that same real response (not hand-mocked)
  through React Testing Library, under `AdminLayout`, and pass.

That is strong evidence the wiring and the components are not broken, but it
is not the same as a human clicking through the editor in a browser — nothing
exercises the live-preview debounce timing, the drag of a long form, or how
the layout actually looks.
