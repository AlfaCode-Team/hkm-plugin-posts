import type { ReactNode } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Link, router } from "@pageflow/react";
import { AdminLayout, useSetPageHeader, usePageFooter } from "@pageflow/admin";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Badge } from "@ui/badge";
import { Checkbox } from "@ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@ui/table";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@ui/dropdown-menu";
import {
  AlertTriangle,
  Archive,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock,
  Copy,
  Eye,
  ExternalLink,
  FileEdit,
  FileText,
  ImageIcon,
  ListFilter,
  MoreHorizontal,
  Pencil,
  Plus,
  RotateCcw,
  Search,
  Star,
  Tag as TagIcon,
  Trash2,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { postsApi, PostsApiError } from "../../lib/api";
import type {
  BulkAction,
  CategoryView,
  EditorCapabilities,
  PostKind,
  PostListResponse,
  PostOrder,
  PostStatus,
  PostView,
} from "../../lib/types";

interface IndexProps {
  capabilities: EditorCapabilities;
  categories: CategoryView[];
  initialQuery: {
    kind: PostKind;
    status: PostStatus | "all";
    page: number;
    perPage: number;
    order: PostOrder;
  };
  initialPage: PostListResponse;
  /** Where this admin UI is mounted (hkmvote serves it under /content/blog). */
  basePath?: string;
}

type StatusFilter = PostStatus | "all";

const STATUS_OPTIONS: { value: StatusFilter; label: string; icon: React.ElementType }[] = [
  { value: "all", label: "All", icon: ListFilter },
  { value: "published", label: "Published", icon: CheckCircle2 },
  { value: "scheduled", label: "Scheduled", icon: Clock },
  { value: "draft", label: "Draft", icon: FileEdit },
  { value: "trash", label: "Trash", icon: Trash2 },
];

const ORDER_OPTIONS: { value: PostOrder; label: string }[] = [
  { value: "created", label: "Newest" },
  { value: "updated", label: "Recently updated" },
  { value: "published", label: "Publish date" },
  { value: "views", label: "Most viewed" },
];

const STATUS_BADGE: Record<PostStatus, string> = {
  draft: "bg-muted text-muted-foreground border border-border",
  scheduled: "bg-blue-500/10 text-blue-700 border border-blue-500/30 dark:text-blue-400",
  published: "bg-emerald-500/10 text-emerald-700 border border-emerald-500/30 dark:text-emerald-400",
  trash: "bg-destructive/10 text-destructive border border-destructive/30",
};

const STATUS_ICON: Record<PostStatus, React.ElementType> = {
  draft: FileEdit,
  scheduled: Clock,
  published: CheckCircle2,
  trash: Trash2,
};

const KIND_LABELS: Record<PostKind, string> = {
  blog: "Blog",
  article: "Articles",
  press: "Press",
};

const formatNum = (n: number) => (n >= 1000 ? (n / 1000).toFixed(1) + "k" : String(n));

function fmtDate(iso?: string | null) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "2-digit" });
}

function fmtTime(iso?: string | null) {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
}

/** Compact page list with -1 as an ellipsis marker. */
function pagesFor(current: number, last: number): number[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const out: number[] = [1];
  const lo = Math.max(2, current - 1);
  const hi = Math.min(last - 1, current + 1);
  if (lo > 2) out.push(-1);
  for (let p = lo; p <= hi; p++) out.push(p);
  if (hi < last - 1) out.push(-1);
  out.push(last);
  return out;
}

export default function PostsIndex({ capabilities, categories, initialQuery, initialPage, basePath }: IndexProps) {
  const base = (basePath ?? "/admin/posts").replace(/\/+$/, "");
  const [kind, setKind] = useState<PostKind>(initialQuery.kind);
  const [status, setStatus] = useState<StatusFilter>(initialQuery.status);
  const [search, setSearch] = useState("");
  const [tag, setTag] = useState("");
  const [category, setCategory] = useState("");
  const [locale, setLocale] = useState("");
  const [author, setAuthor] = useState("");
  const [order, setOrder] = useState<PostOrder>(initialQuery.order);
  const [page, setPage] = useState(initialQuery.page);
  const perPage = initialQuery.perPage;

  const [rows, setRows] = useState<PostView[]>(initialPage.data);
  const [meta, setMeta] = useState(initialPage.meta);
  const [loading, setLoading] = useState(false);
  const [statusCounts, setStatusCounts] = useState<Record<StatusFilter, number> | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [confirmTarget, setConfirmTarget] = useState<{ id: string; mode: "trash" | "purge" } | null>(null);
  const [confirmBusy, setConfirmBusy] = useState(false);
  const [showMoreFilters, setShowMoreFilters] = useState(false);

  const firstRunRef = useRef(true);

  const query = useMemo(
    () => ({ kind, status, q: search || undefined, tag: tag || undefined, category: category || undefined, locale: locale || undefined, author: capabilities.canManage ? author || undefined : undefined, order, page, perPage }),
    [kind, status, search, tag, category, locale, author, order, page, perPage, capabilities.canManage],
  );

  function load() {
    setLoading(true);
    postsApi
      .list(query)
      .then((res) => {
        setRows(res.data);
        setMeta(res.meta);
      })
      .catch((e) => toast.error(e instanceof PostsApiError ? e.message : "Could not load posts."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    if (firstRunRef.current) {
      firstRunRef.current = false;
      return;
    }
    const handle = setTimeout(load, search ? 300 : 0);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [kind, status, search, tag, category, locale, author, order, page]);

  useEffect(() => {
    let cancelled = false;
    postsApi
      .counts({ kind, q: search || undefined })
      .then(({ data }) => {
        if (cancelled) return;
        setStatusCounts({ all: data.draft + data.scheduled + data.published, ...data });
      })
      .catch(() => {
        if (!cancelled) setStatusCounts(null);
      });
    return () => {
      cancelled = true;
    };
  }, [kind, search]);

  useSetPageHeader(
    {
      title: "Posts",
      description: "Blog posts, articles and press releases.",
      actions: capabilities.canWrite ? [{ label: "New post", onClick: () => router.visit(`${base}/create`) }] : [],
    },
    [capabilities.canWrite],
  );

  const { setFooter, clearFooter } = usePageFooter();
  useEffect(() => {
    if (selected.size === 0) {
      clearFooter();
      return;
    }
    setFooter({
      content: (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <span className="text-sm font-medium">{selected.size} selected</span>
          <div className="flex flex-wrap gap-2">
            {status === "trash" ? (
              <>
                <Button size="sm" variant="secondary" disabled={bulkBusy} onClick={() => void runBulk("restore")}>
                  <RotateCcw className="mr-1.5 h-3.5 w-3.5" /> Restore
                </Button>
                {capabilities.canManage && (
                  <Button size="sm" variant="destructive" disabled={bulkBusy} onClick={() => void runBulk("purge")}>
                    Delete permanently
                  </Button>
                )}
              </>
            ) : (
              <>
                {capabilities.canPublish && (
                  <>
                    <Button size="sm" variant="secondary" disabled={bulkBusy} onClick={() => void runBulk("publish")}>
                      Publish
                    </Button>
                    <Button size="sm" variant="outline" disabled={bulkBusy} onClick={() => void runBulk("unpublish")}>
                      Unpublish
                    </Button>
                  </>
                )}
                <Button size="sm" variant="destructive" disabled={bulkBusy} onClick={() => void runBulk("trash")}>
                  <Trash2 className="mr-1.5 h-3.5 w-3.5" /> Trash
                </Button>
              </>
            )}
            <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>
              <X className="mr-1.5 h-3.5 w-3.5" /> Clear
            </Button>
          </div>
        </div>
      ),
    });
    return () => clearFooter();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected, bulkBusy, status, capabilities.canPublish, capabilities.canManage]);

  async function runBulk(action: BulkAction) {
    const ids = Array.from(selected);
    if (ids.length === 0) return;
    if ((action === "purge" || action === "trash") && !confirm(`${action === "purge" ? "Permanently delete" : "Trash"} ${ids.length} post(s)?`)) {
      return;
    }
    setBulkBusy(true);
    try {
      const { data: outcome } = await postsApi.bulk(action, ids);
      const counts: Record<string, number> = {};
      for (const result of Object.values(outcome)) counts[result] = (counts[result] ?? 0) + 1;
      const parts = Object.entries(counts).map(([k, v]) => `${v} ${k}`);
      const summary = parts.join(", ");
      if (counts.ok === ids.length) toast.success(summary);
      else toast.error(summary);
      setSelected(new Set());
      load();
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "That bulk action failed.");
    } finally {
      setBulkBusy(false);
    }
  }

  function toggleRow(id: string, checked: boolean) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (checked) next.add(id);
      else next.delete(id);
      return next;
    });
  }

  function toggleAll(checked: boolean) {
    setSelected(checked ? new Set(rows.map((r) => r.id)) : new Set());
  }

  async function duplicate(id: string) {
    try {
      const { data } = await postsApi.duplicate(id);
      toast.success("Duplicated");
      router.visit(`${base}/${data.id}/edit`);
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "Could not duplicate this post.");
    }
  }

  async function restoreOne(id: string) {
    try {
      await postsApi.restore(id);
      toast.success("Restored");
      load();
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "Could not restore this post.");
    }
  }

  async function runConfirm() {
    if (!confirmTarget) return;
    setConfirmBusy(true);
    try {
      if (confirmTarget.mode === "purge") {
        await postsApi.purge(confirmTarget.id);
        toast.success("Deleted permanently");
      } else {
        await postsApi.trash(confirmTarget.id);
        toast.success("Moved to trash");
      }
      setConfirmTarget(null);
      load();
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "That action failed.");
    } finally {
      setConfirmBusy(false);
    }
  }

  const allOnPageSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));
  const activeFilterCount = [tag, category, locale, author].filter(Boolean).length;

  return (
    <div className="space-y-4">
      {/* Kind tabs */}
      <div role="tablist" aria-label="Kind" className="inline-flex items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-0.5">
        {capabilities.kinds.map((k) => (
          <button
            key={k}
            type="button"
            role="tab"
            aria-selected={kind === k}
            onClick={() => {
              setKind(k);
              setPage(1);
              setSelected(new Set());
            }}
            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
              kind === k ? "border border-border bg-background shadow-sm" : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {KIND_LABELS[k] ?? k}
          </button>
        ))}
      </div>

      {/* Toolbar: search + order + new */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-56 flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            placeholder="Search posts by title, excerpt or body…"
            className="w-full rounded-md border border-border bg-input py-2.5 pl-10 pr-4 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary/50 focus:outline-none focus:ring-2 focus:ring-ring/40"
          />
        </div>
        <Select value={order} onValueChange={(v) => setOrder(v as PostOrder)}>
          <SelectTrigger className="h-10 w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {ORDER_OPTIONS.map((o) => (
              <SelectItem key={o.value} value={o.value}>
                {o.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Button
          variant={showMoreFilters || activeFilterCount > 0 ? "secondary" : "outline"}
          size="sm"
          className="h-10"
          onClick={() => setShowMoreFilters((o) => !o)}
        >
          <ListFilter className="mr-1.5 h-3.5 w-3.5" /> Filters{activeFilterCount > 0 ? ` (${activeFilterCount})` : ""}
        </Button>
        {capabilities.canWrite && (
          <Button size="sm" className="h-10" onClick={() => router.visit(`${base}/create`)}>
            <Plus className="mr-1.5 h-4 w-4" /> New post
          </Button>
        )}
      </div>

      {/* Status segmented group */}
      <div className="flex flex-wrap items-center gap-2">
        <span className="flex shrink-0 items-center gap-1.5 text-xs font-medium text-muted-foreground">
          <ListFilter className="h-3.5 w-3.5" /> Status
        </span>
        <div className="flex flex-wrap items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-0.5">
          {STATUS_OPTIONS.map((opt) => {
            const Icon = opt.icon;
            const active = status === opt.value;
            return (
              <button
                key={opt.value}
                type="button"
                onClick={() => {
                  setStatus(opt.value);
                  setPage(1);
                  setSelected(new Set());
                }}
                aria-pressed={active}
                className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
                  active ? "border border-border bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                <Icon className="h-3.5 w-3.5" />
                {opt.label}
                {statusCounts && <span className="opacity-70">{statusCounts[opt.value] ?? 0}</span>}
              </button>
            );
          })}
        </div>
      </div>

      {/* Secondary filters (collapsible) */}
      {showMoreFilters && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/20 p-3">
          <Input placeholder="Tag" value={tag} onChange={(e) => { setTag(e.target.value); setPage(1); }} className="h-9 w-32" />
          <Select value={category || "__any__"} onValueChange={(v) => { setCategory(v === "__any__" ? "" : v); setPage(1); }}>
            <SelectTrigger className="h-9 w-44">
              <SelectValue placeholder="Category" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__any__">Any category</SelectItem>
              {categories.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {" ".repeat(c.depth * 2)}
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Input placeholder="Locale" value={locale} onChange={(e) => { setLocale(e.target.value); setPage(1); }} className="h-9 w-24" />
          {capabilities.canManage && (
            <Input placeholder="Author id" value={author} onChange={(e) => { setAuthor(e.target.value); setPage(1); }} className="h-9 w-36" />
          )}
          {activeFilterCount > 0 && (
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                setTag("");
                setCategory("");
                setLocale("");
                setAuthor("");
                setPage(1);
              }}
            >
              <X className="mr-1.5 h-3.5 w-3.5" /> Clear filters
            </Button>
          )}
        </div>
      )}

      {/* Table */}
      <div className="overflow-x-auto rounded-md border border-border">
        <Table className="text-sm">
          <TableHeader>
            <TableRow className="bg-secondary/40 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              <TableHead className="w-10">
                <Checkbox aria-label="Select all posts on this page" checked={allOnPageSelected} onCheckedChange={(v) => toggleAll(Boolean(v))} />
              </TableHead>
              <TableHead>Post</TableHead>
              <TableHead>Author</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Views</TableHead>
              <TableHead>{status === "trash" ? "Updated" : "Published"}</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {!loading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={8} className="py-12 text-center text-sm text-muted-foreground">
                  No posts match these filters.
                </TableCell>
              </TableRow>
            )}
            {rows.map((post) => {
              const SIcon = STATUS_ICON[post.status] ?? CheckCircle2;
              const cat = post.categories[0];
              const dateIso = status === "trash" ? post.updatedAt : post.publishedAt ?? post.updatedAt;
              return (
                <TableRow key={post.id} className="group transition-colors hover:bg-accent/40">
                  <TableCell>
                    <Checkbox aria-label={`Select "${post.title}"`} checked={selected.has(post.id)} onCheckedChange={(v) => toggleRow(post.id, Boolean(v))} />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      {post.coverImageUrl ? (
                        <img src={post.coverImageUrl} alt="" loading="lazy" className="h-12 w-12 shrink-0 rounded-md object-cover ring-1 ring-border" onError={(e) => (e.currentTarget.style.visibility = "hidden")} />
                      ) : (
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground ring-1 ring-border">
                          <ImageIcon className="h-4 w-4" />
                        </div>
                      )}
                      <div className="min-w-0">
                        <Link href={`${base}/${post.id}/edit`} className="font-medium text-foreground hover:text-primary hover:underline">
                          {post.title || "(untitled)"}
                        </Link>
                        {post.featured && <Star className="ml-1.5 inline h-3 w-3 fill-amber-400 text-amber-400" aria-label="Featured" />}
                        <p className="truncate font-mono text-xs text-muted-foreground">{post.path}</p>
                        {post.tags.length > 0 && (
                          <div className="mt-1 flex flex-wrap gap-1">
                            {post.tags.slice(0, 3).map((t) => (
                              <span key={t} className="rounded border border-border bg-secondary px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                #{t}
                              </span>
                            ))}
                            {post.tags.length > 3 && <span className="text-[10px] text-muted-foreground">+{post.tags.length - 3}</span>}
                          </div>
                        )}
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm">{post.authorName ?? "—"}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    {cat ? (
                      <span className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                        <TagIcon className="h-3 w-3" />
                        {cat.name}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <span className={`inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold capitalize ${STATUS_BADGE[post.status]}`}>
                      <SIcon className="h-3 w-3" />
                      {post.status}
                    </span>
                  </TableCell>
                  <TableCell className="text-right text-sm text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                      <Eye className="h-3.5 w-3.5" />
                      {formatNum(post.viewCount)}
                    </span>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <p className="text-sm text-foreground">{fmtDate(dateIso)}</p>
                    <p className="text-xs text-muted-foreground">{fmtTime(dateIso)}</p>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 opacity-0 transition-opacity group-hover:opacity-100"
                        onClick={() => router.visit(`${base}/${post.id}/edit`)}
                        aria-label="Edit"
                      >
                        <Pencil className="h-3.5 w-3.5" />
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="h-8 w-8">
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => router.visit(`${base}/${post.id}/edit`)}>
                            <Pencil className="mr-2 h-3.5 w-3.5" /> Edit
                          </DropdownMenuItem>
                          {post.status === "published" && post.path && (
                            <DropdownMenuItem onClick={() => window.open(post.path, "_blank", "noreferrer")}>
                              <ExternalLink className="mr-2 h-3.5 w-3.5" /> View live
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuItem onClick={() => void duplicate(post.id)}>
                            <Copy className="mr-2 h-3.5 w-3.5" /> Duplicate
                          </DropdownMenuItem>
                          {post.status === "trash" ? (
                            <>
                              <DropdownMenuItem onClick={() => void restoreOne(post.id)}>
                                <RotateCcw className="mr-2 h-3.5 w-3.5" /> Restore
                              </DropdownMenuItem>
                              {capabilities.canManage && (
                                <DropdownMenuItem className="text-destructive" onClick={() => setConfirmTarget({ id: post.id, mode: "purge" })}>
                                  <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete permanently
                                </DropdownMenuItem>
                              )}
                            </>
                          ) : (
                            <DropdownMenuItem className="text-destructive" onClick={() => setConfirmTarget({ id: post.id, mode: "trash" })}>
                              <Trash2 className="mr-2 h-3.5 w-3.5" /> Trash
                            </DropdownMenuItem>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      <div className="flex items-center justify-between gap-3 pt-1">
        <p className="text-xs text-muted-foreground">
          {meta.total} result{meta.total === 1 ? "" : "s"} · page {meta.page} of {meta.lastPage}
        </p>
        {meta.lastPage > 1 && (
          <div className="flex items-center gap-1">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              className="flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
              aria-label="Previous page"
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
            {pagesFor(meta.page, meta.lastPage).map((pg, i) =>
              pg === -1 ? (
                <span key={`e${i}`} className="flex h-8 w-8 items-center justify-center text-xs text-muted-foreground">…</span>
              ) : (
                <button
                  key={pg}
                  onClick={() => setPage(pg)}
                  className={`h-8 min-w-8 rounded-md px-2 text-xs font-medium transition-colors ${
                    pg === meta.page ? "bg-primary text-primary-foreground" : "border border-border text-muted-foreground hover:text-foreground"
                  }`}
                >
                  {pg}
                </button>
              ),
            )}
            <button
              onClick={() => setPage((p) => (meta.hasMore ? p + 1 : p))}
              disabled={!meta.hasMore}
              className="flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
              aria-label="Next page"
            >
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        )}
      </div>

      {/* Delete / purge confirmation */}
      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
          <div className="w-full max-w-sm space-y-4 rounded-xl border border-border bg-card p-6 shadow-xl">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-destructive/10">
                <AlertTriangle className="h-5 w-5 text-destructive" />
              </div>
              <div>
                <h3 className="font-semibold text-foreground">{confirmTarget.mode === "purge" ? "Delete permanently" : "Move to trash"}</h3>
                <p className="text-sm text-muted-foreground">
                  {confirmTarget.mode === "purge" ? "This cannot be undone." : "You can restore it from the Trash tab."}
                </p>
              </div>
            </div>
            <div className="flex gap-2 pt-1">
              <Button variant="destructive" className="flex-1" onClick={() => void runConfirm()} disabled={confirmBusy}>
                {confirmBusy ? "Working…" : confirmTarget.mode === "purge" ? "Delete" : "Trash"}
              </Button>
              <Button variant="outline" className="flex-1" onClick={() => setConfirmTarget(null)} disabled={confirmBusy}>
                Cancel
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

PostsIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
