# Posts Plugin

> Solves **`post.publishing`** · Namespace **`Plugins\Posts\`** · Package `alfacode-team/hkm-plugin-posts`

One plugin for a site's **blog**, **articles** and **press**. They share one
`posts` table and are told apart by `kind`:

| `kind` | Address (default) | What it usually carries |
|---|---|---|
| `blog` | `/blog/{slug}` | body, cover, author, tags |
| `article` | `/articles/{slug}` | the same, plus subtitle and excerpt |
| `press` | `/press/{slug}` | a **dateline** and **media contact** for a release; **source name + URL** for coverage elsewhere |

No field is forbidden on any kind. Readers' comments are a separate plugin,
[`hkm-plugin-comments`](../hkm-plugin-comments), which requires this one.

## What you get

**Writing**
- Markdown, HTML or plain-text bodies, **rendered to safe HTML when saved**
  (GitHub-flavoured markdown: tables, task lists, strikethrough, autolinks).
  Raw HTML in markdown is escaped, HTML bodies go through a sanitiser, links
  can't be `javascript:`, and a lone YouTube/Vimeo link becomes a
  privacy-friendly embed. Headings get ids, collected as a table of contents.
- Revisions on every save, with notes; restore any one. Numbers never repeat.
- Trash with restore; permanent delete; emptying the trash on a schedule.
- Duplicate a post as a new draft.
- Scheduling: a future `publishedAt` publishes itself at that time.
- Shareable preview links for drafts (signed, expiring, no account needed).
- Write / publish / manage permissions: contributor, author, editor.

**Organising**
- A category **tree**; listing a category includes its subcategories.
- Tags, normalised (`Mobile Money` and `mobile-money` are one tag).
- Translations: language versions of a post linked together, each with its
  own slug. hreflang alternates come out in sitemaps and `<head>`.
- Featured posts, popular posts (view counts, one view per reader per 30
  minutes), month archives, previous/next, related posts by shared tags and
  categories.

**Publishing**
- A public JSON API.
- RSS 2.0, Atom 1.0 and JSON Feed 1.1, site-wide or per kind, with full content.
- An XML sitemap (with images and hreflang alternates) and a Google News
  sitemap.
- schema.org structured data (`BlogPosting`, `Article`, `NewsArticle`), plus
  Open Graph, Twitter cards, canonical and robots tags, ready to print in `<head>`.
- When a post's slug changes, its old address keeps working as a **301**.
- Events after every change, so sitemaps, caches and newsletters can react:
  `post.published`, `post.updated`, `post.unpublished`, `post.deleted`.

## Install

```php
// bootstrap: Posts needs the Database plugin (database.management) and,
// for its admin pages, Pageflow (http.pageflow, which brings vite.manifest
// and i18n.translation).
->withModules([
    \Plugins\Database\Provider::class,
    \Plugins\I18n\Provider::class,
    \Plugins\ViteManifest\Provider::class,
    \Plugins\Pageflow\Provider::class,
    \Plugins\Posts\Provider::class,
])
```

Pageflow is a **boot** requirement, not just a page one. The kernel checks
every route's `requires[]` at boot, *before* it applies
`routePolicy.disable`, so disabling the `/admin/posts` pages does not remove
the need for `http.pageflow`. A headless project that will never show the
admin pages still has to register Pageflow. User, Auth and Tenancy work the
same way.

```
hkm plugins enable Posts        # seeds the POSTS_* settings into .env and migrates
```

The migration ships twice, identically: in `database/migrations` (central)
and in `database/tenant-template`. Posts live on whichever database the
request is routed to, so a project with Tenancy gets one blog per tenant and a
project without it gets one blog. A test fails if the two copies drift apart.

Importing rows with plain SQL instead of the service? Give every published
row a `published_at` (UTC, `Y-m-d H:i:s`). Readers only see posts whose date
has passed, which is what lets listings use the index instead of sorting the
table. A published row without a date is not listed. The service stamps
the date itself, so this only matters for hand-written inserts.

### Cron

```
* * * * *  hkm posts:publish-due          # announce scheduled posts as they go live
15 3 * * * hkm posts:empty-trash --days=30
```

Add `--all-tenants` on a Tenancy project. Readers see a scheduled post at its
time whether or not the cron runs. `publish-due` only sends `post.published`,
so the side effects (sitemap ping, newsletter) happen on time. Concurrent runs
are safe: each post is claimed before it is announced.

After upgrading this plugin, run `hkm posts:rerender` once so old posts pick
up the renderer's changes. It touches no timestamps and sends no events.

## Routes

All routes are declared in `module.json`. To drop one, use the project's
`proj.json` `routePolicy.disable`, e.g. `"GET /api/posts/popular"`. To drop
all of them, disable the whole domain with `"post.publishing"` and keep only
the contracts.

**Public**

| Method | Path | |
|---|---|---|
| GET | `/api/posts` | `?kind&tag&category&q&featured&locale&year&month&author&page&perPage`. `author` is the credited author's id (an author archive) |
| GET | `/api/posts/{kind}/{slug}` | the post, previous/next, related, translations (`?locale`). **301** to the new address if renamed |
| GET | `/api/posts/tags` · `/categories` · `/archive` · `/popular` | sidebars |
| POST | `/api/posts/views/{id}` | read beacon, always 204 (rate-limited) |
| GET | `/api/posts/preview/{token}` | a draft shared by link (`no-store`, `noindex`) |
| GET | `/feeds/posts/{rss\|atom\|json}` · `/feeds/{kind}/{rss\|atom\|json}` | feeds |
| GET | `/sitemaps/posts` · `/sitemaps/posts-news` | sitemaps |

Every public read route carries the plugin's `posts.cache` filter. A cacheable
`200` gets a weak `ETag`, and a request sending that tag back in
`If-None-Match` gets an empty `304`. Feed readers and crawlers revalidate this
way on every poll, so an unchanged feed costs a few header bytes. The body is
still built on the server; what this saves is the transfer and the client's
parse.

Feed and sitemap paths deliberately have **no `.xml` extension**. An edge
that serves asset extensions statically would never pass `/feed.xml` to PHP,
so the feed would 404 in production only.

The view beacon is a `POST`. If your CSRF layer covers `/api`, add
`/api/posts/views` to its exempt paths, because a reader has no CSRF token.

**Admin**: behind the plugin's own `posts.editor` filter: **401** for a
guest, **403** without one of the permissions below.

| Method | Path | |
|---|---|---|
| GET | `/api/admin/posts` · `/me` · `/stats` · `/slug?title=` | list, capabilities, dashboard counts, slug suggestion |
| GET | `/api/admin/posts/counts` | posts per status for the list's filters (status tabs), in one query |
| POST | `/api/admin/posts` · `/render` · `/bulk` | create, live preview, bulk trash/restore/purge/publish/unpublish |
| GET PUT DELETE | `/api/admin/posts/{id}` | read, full replace, move to trash |
| POST | `/{id}/restore` · `/{id}/duplicate` · `/{id}/preview-link` | |
| DELETE | `/{id}/permanent` | delete for good |
| GET POST | `/{id}/revisions` · `/{id}/revisions/{n}` · `/{id}/revisions/{n}/restore` | history |
| GET | `/{id}/translations` | language versions |
| GET POST PUT DELETE | `/api/admin/post-categories[/{id}]` | category tree |

**Two people editing one post.** Every admin view carries a `version`. Send
it back on `PUT /api/admin/posts/{id}` and a save made after someone else's
returns **409** `post.edit_conflict`; nothing is overwritten. The admin editor
does this and offers a reload. Without `version` the save still goes through,
but the write itself is conditional on the version the server just read, so
two requests racing each other still cannot silently overwrite one another.

A translation group holds one post per language. Moving a post to a language
its group already has is a 422 on `locale`.

Errors use the kernel envelope `{"error": {"code", "message", "fields"?}}`.
A 422 lists every field error at once, keyed by input key (`title`, `tags.3`,
`categories.0`).

**Admin pages** (Pageflow, behind the same `posts.editor` filter)

| Method | Path | Component |
|---|---|---|
| GET | `/admin/posts` | `Posts/Index`: list, filters, bulk actions |
| GET | `/admin/posts/create` · `/admin/posts/{id}/edit` | `Posts/Editor` |
| GET | `/admin/posts/categories` | `Posts/Categories` |

## Admin UI

The screens live in `ui/` (alias `@posts`, admin surface) and register a
**Posts** nav section (All posts · New post · Categories), gated on the
`posts` feature flag. `ui/README.md` covers them in detail.

- **List:** kind tabs, status chips with counts, search and filters
  (tag, category, locale, author, order), pagination, row actions (edit,
  duplicate, trash, restore, permanent delete for managers, view live), and
  bulk actions that report the outcome for each row.
- **Editor:** markdown, html or text with a live, sanitised preview and TOC
  (rendered by the server's own renderer, so what you see is what is
  saved), slug suggestion, cover image, tags, categories, a press panel,
  SEO fields with a snippet preview, publish/schedule/unpublish according to
  the editor's permissions, revisions with restore, translations, a
  shareable preview link, an unsaved-changes guard and Ctrl/Cmd+S.
- **Categories:** a tree you can create, rename, move and delete in. A
  category can't be moved under itself.

The page controller renders only the first paint. Everything after that
goes to the `/api/admin/posts…` JSON API with the CSRF header attached.

Things to know:

- A guest who opens `/admin/posts` gets the 401 **JSON** envelope from
  `posts.editor`, not a redirect to a login page. Put the pages behind your
  project's own auth redirect if you want one.
- **View live** opens `post.path`, which is relative. That assumes the admin
  and the public site share an origin.
- A project that adds a new admin page must rebuild its frontend surface,
  or the browser shows "Page not found".

## Permissions

| Setting (default) | Grants |
|---|---|
| `POSTS_WRITE_PERMISSION` (`posts:write`) | create posts; edit and trash their **own** drafts |
| `POSTS_PUBLISH_PERMISSION` (`posts:publish`) | with write: publish, schedule and unpublish their own |
| `POSTS_MANAGE_PERMISSION` (`posts:manage`) | everything: anyone's posts, categories, permanent delete, stats |

"Own" means created by the user, or bylined to them. A non-manager sees only
their own posts and cannot byline anyone else. New posts are bylined to the
signed-in user unless an editor says otherwise. Setting a permission to an
empty string means the project authorises that action itself.

The `*` permission grants all three (`Identity::hasPermission`).

## Rendering a post page in your project

The public API serves front-end apps. A server-rendered page calls the
contracts directly from the project's own route, with
`"requires": ["post.publishing"]`:

```php
public function show(Request $request, string $slug): Response
{
    $now    = new \DateTimeImmutable();
    $detail = $this->posts->show('blog', $slug, $now);

    if ($detail === null) {
        $moved = $this->posts->redirectFor('blog', $slug, $now);
        return $moved ? Response::permanentRedirect($moved->path) : Response::notFound();
    }

    $site = new SiteInfo('My Site', $request->site()->base(), logoUrl: '/logo.png');

    return $this->views->render('blog/show', [
        'post' => $detail->post,            // ->bodyHtml is safe to print as-is
        'head' => $this->syndication->headHtml($detail->post, $site, $detail->translations),
        'next' => $detail->next,
        'related' => $detail->related,
    ]);
}
```

Print `bodyHtml` as-is: it was sanitised when the post was saved. Never
print `body`, which is the author's source text.

If the project serves posts at other addresses, set `POSTS_PATHS` so that
`path`, feeds, sitemaps and events point at the right URLs:

```
POSTS_PATHS="blog=/news/{slug};press=/media/{slug}"          # {slug}, {kind}, {locale}
```

A post with a locale gets `/{locale}` in front unless its pattern uses
`{locale}` itself.

## Configuration

`hkm plugins enable` writes these to `.env` with their defaults.

| Key | Default | |
|---|---|---|
| `POSTS_PATHS` | `blog=/blog/{slug};article=/articles/{slug};press=/press/{slug}` | where each kind lives |
| `POSTS_SITE_NAME` | `APP_NAME`, else `Blog` | feeds, structured data, `<title>` |
| `POSTS_SITE_DESCRIPTION` / `POSTS_SITE_LOGO` / `POSTS_TWITTER_HANDLE` | — | feeds and social tags |
| `POSTS_DEFAULT_LOCALE` | `en` | language of posts with no locale |
| `POSTS_PREVIEW_SECRET` | derived from `APP_KEY` | signs preview links. With neither set, previews are off (fail closed) |
| `POSTS_MAX_REVISIONS` | `50` | revisions kept per post |
| `POSTS_WRITE_PERMISSION` / `_PUBLISH_` / `_MANAGE_` | `posts:write` / `posts:publish` / `posts:manage` | see Permissions |
| `POSTS_NEWS_KINDS` | `press,article` | kinds in the Google News sitemap |
| `POSTS_FEED_FULL_CONTENT` | `true` | full HTML in feeds, not just the summary |
| `POSTS_VIEW_DEDUPE_SECONDS` | `1800` | one view per reader per window (needs a CachePort) |

Install the `intl` extension so a title gives the same slug on every
platform. Without it, macOS and Linux transliterate differently.

## Contracts

Three published contracts. None of them does authorization; the caller owns
that check, and the plugin's admin routes use `PostEditorialService` for it.

- **`PostServiceContract`**: `list`, `find`, `create`, `update`, `trash`,
  `restore`, `purge`, `duplicate`, `revisions`, `revision`,
  `restoreRevision`, `translations`, `stats`, `suggestSlug`, `previewToken`,
  `findForPreview`. Public reads: `published`, `findPublished`, `show`,
  `redirectFor`, `popular`, `tags`, `archive`, `recordView`. Ops:
  `publishDue`, `emptyTrash`, `rerender`.
- **`PostCategoryServiceContract`**: `tree`, `find`, `findBySlug`, `create`,
  `update`, `delete`.
- **`PostSyndicationContract`**: `feed`, `feedContentType`, `sitemap`,
  `newsSitemap`, `structuredData`, `headHtml`.

Read models are `PostView`, `PostPage`, `PostDetail`, `CategoryView` and
`RevisionView`. All are primitives-only. `toArray()` is the admin shape;
`toPublicArray()` drops the source text and bookkeeping.

### Input for `create()` / `update()`

| Key | Rule |
|---|---|
| `kind` | `blog` (default), `article` or `press` |
| `locale` | BCP 47 (`en`, `pt-BR`); empty = the default language |
| `title` | required, ≤ 200 |
| `slug` | optional; lower-case letters, digits, single hyphens, ≤ 160; unique per kind and locale |
| `subtitle` ≤ 200 · `excerpt` ≤ 500 | |
| `body` | ≤ 200,000 characters, in `bodyFormat`: `markdown` (default), `html` or `text` |
| `coverImageUrl` · `coverImageAlt` ≤ 200 | absolute `http(s)` or a root-relative path |
| `authorId` ≤ 64 · `authorName` ≤ 120 | the byline |
| `tags` | list or comma-separated; ≤ 10, each ≤ 40 once normalised |
| `categories` | list of category ids; ≤ 5 |
| `dateline` ≤ 120 · `contact` ≤ 500 · `sourceName` ≤ 120 · `sourceUrl` | press fields |
| `metaTitle` ≤ 160 · `metaDescription` ≤ 320 · `canonicalUrl` · `noindex` | SEO |
| `featured` · `commentsEnabled` (default **on**) · `published` | bool-ish |
| `publishedAt` | ISO-8601 **with an offset**; a future date schedules the post |
| `translationOf` | `create` only: the id of the post this one translates |

`update()` is a **full replace**, with two exceptions: an omitted `slug`
keeps the current URL, and an omitted `publishedAt` keeps the current date.
Fixing a typo must not move a post or re-date it.

## Tests

```
composer install
vendor/bin/phpunit
```

- **Unit and integration:** these run against a SQLite database built by the
  plugin's own migration.
- **End to end:** `tests/Feature` boots a real kernel and drives every route
  through the real pipeline, including the CLI scheduler.
- **XSS:** the renderer is tested against a list of XSS payloads by
  inspecting the parsed output.
- **UI:** `cd ui && npx vitest run` renders each page from props dumped
  from a real kernel response.

Not yet verified: the migration and queries running against a live MySQL or
PostgreSQL server. The DDL compiles for both and reads correctly, but it has
only been executed on SQLite. The admin screens have never been opened in a
real browser, and no full `tsc` type-check has run on them.
