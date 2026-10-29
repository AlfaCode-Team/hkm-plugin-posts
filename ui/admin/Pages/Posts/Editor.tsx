import type { ReactNode } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Link, router, useDirtyGuard } from "@pageflow/react";
import { AdminLayout, useSetPageHeader, FieldHelp, DetailDrawer, DrawerSection, DrawerField, copyToClipboard } from "@pageflow/admin";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Textarea } from "@ui/textarea";
import { Switch } from "@ui/switch";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@ui/select";
import { Badge } from "@ui/badge";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@ui/dialog";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@ui/collapsible";
import {
  CheckCircle2,
  ChevronDown,
  Clock,
  Copy,
  Eye,
  ExternalLink,
  FolderOpen,
  Globe,
  History,
  ImageIcon,
  ImagePlus,
  Languages,
  Link as LinkIcon,
  Loader2,
  Megaphone,
  Save,
  Search,
  Settings2,
  SlidersHorizontal,
  Share2,
  Tag as TagIcon,
  User,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { postsApi, PostsApiError } from "../../lib/api";
import { RichTextEditor } from "../../lib/RichTextEditor";
import { useImagePicker } from "../../lib/imagePicker";
import type {
  BodyFormat,
  CategoryView,
  EditorCapabilities,
  FieldErrors,
  PostInput,
  PostKind,
  PostView,
  RevisionView,
} from "../../lib/types";

interface EditorProps {
  postId: string | null;
  post: PostView | null;
  capabilities: EditorCapabilities;
  categories: CategoryView[];
  /** Where this admin UI is mounted. The host sets it so links stay inside the
   *  host's own shell (hkmvote serves these pages under /content/blog). */
  basePath?: string;
  /** The public site's origin (e.g. https://africavoting.com). The host passes
   *  it so the editor can SHOW a post's live address — the author never types
   *  it; the URL is this origin + the post's path. */
  publicBaseUrl?: string;
}

/** A labelled section in the settings drawer — icon, title, optional hint. */
function Section({
  icon: Icon,
  title,
  hint,
  children,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <section className="space-y-3">
      <div className="flex items-start gap-2">
        <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
        <div className="min-w-0">
          <h3 className="text-sm font-semibold leading-none">{title}</h3>
          {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
      </div>
      <div className="space-y-3 border-l border-border pl-3">{children}</div>
    </section>
  );
}

/** A read-only "the system fills this in" row. */
function AutoField({ label, value, hint, onCopy }: { label: string; value: string; hint?: string; onCopy?: () => void }) {
  return (
    <div>
      <Label className="text-muted-foreground">{label}</Label>
      <div className="mt-1 flex items-center gap-2 rounded-md border border-dashed border-border bg-muted/40 px-3 py-2">
        <span className="min-w-0 flex-1 truncate text-sm">{value}</span>
        {onCopy && value && (
          <button type="button" onClick={onCopy} aria-label={`Copy ${label}`} className="shrink-0 text-muted-foreground hover:text-foreground">
            <Copy className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

const BODY_FORMATS: { value: BodyFormat; label: string }[] = [
  { value: "html", label: "Rich text" },
  { value: "markdown", label: "Markdown" },
  { value: "text", label: "Plain text" },
];

const KIND_LABELS: Record<PostKind, string> = {
  blog: "Blog post",
  article: "Article",
  press: "Press release",
};

const STATUS_PILL: Record<string, string> = {
  draft: "border-border bg-muted text-muted-foreground",
  scheduled: "border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-400",
  published: "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400",
  trash: "border-destructive/30 bg-destructive/10 text-destructive",
};

function emptyInput(kind: PostKind): PostInput {
  return {
    kind,
    locale: "en",
    slug: "",
    title: "",
    subtitle: "",
    excerpt: "",
    body: "",
    bodyFormat: "html",
    coverImageUrl: "",
    coverImageAlt: "",
    authorId: "",
    authorName: "",
    tags: [],
    categories: [],
    dateline: "",
    contact: "",
    sourceName: "",
    sourceUrl: "",
    metaTitle: "",
    metaDescription: "",
    canonicalUrl: "",
    noindex: false,
    featured: false,
    commentsEnabled: true,
    published: false,
    publishedAt: null,
  };
}

/** `PostView.toInput()`, client-side — the shape AdminPostController@update needs for a full replace. */
function inputFromPost(post: PostView): PostInput {
  return {
    kind: post.kind,
    locale: post.locale,
    slug: post.slug,
    title: post.title,
    subtitle: post.subtitle ?? "",
    excerpt: post.excerpt ?? "",
    body: post.body ?? "",
    bodyFormat: post.bodyFormat,
    coverImageUrl: post.coverImageUrl ?? "",
    coverImageAlt: post.coverImageAlt ?? "",
    authorId: post.authorId ?? "",
    authorName: post.authorName ?? "",
    tags: post.tags,
    categories: post.categories.map((c) => c.id),
    dateline: post.dateline ?? "",
    contact: post.contact ?? "",
    sourceName: post.sourceName ?? "",
    sourceUrl: post.sourceUrl ?? "",
    metaTitle: post.metaTitle ?? "",
    metaDescription: post.metaDescription ?? "",
    canonicalUrl: post.canonicalUrl ?? "",
    noindex: post.noindex,
    featured: post.featured,
    commentsEnabled: post.commentsEnabled,
    published: post.published,
    publishedAt: post.publishedAt,
  };
}

/** Local datetime-input value (no offset) <-> ISO-8601 WITH offset the API requires. */
function toLocalInputValue(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fromLocalInputValue(value: string): string | null {
  if (!value) return null;
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return null;
  return d.toISOString(); // always carries the "Z" offset — valid ISO-8601 with offset.
}

/** Same shape the server's slugger produces, so a typed slug can be compared to a suggestion. */
function slugify(s: string): string {
  return s
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

export default function PostsEditor({ postId, post: initialPost, capabilities, categories, basePath, publicBaseUrl }: EditorProps) {
  const base = (basePath ?? "/admin/posts").replace(/\/+$/, "");
  const pickImage = useImagePicker();
  const [post, setPost] = useState<PostView | null>(initialPost);
  const [form, setForm] = useState<PostInput>(() => (initialPost ? inputFromPost(initialPost) : emptyInput(capabilities.kinds[0] ?? "blog")));
  const baselineRef = useRef<string>(JSON.stringify(form));
  const [slugTouched, setSlugTouched] = useState(Boolean(initialPost?.slug));
  const [slugState, setSlugState] = useState<"idle" | "checking" | "ok" | "taken">("idle");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);
  const [revisionNote, setRevisionNote] = useState("");
  const [tagDraft, setTagDraft] = useState("");

  const [settingsOpen, setSettingsOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [bylineOpen, setBylineOpen] = useState(false);

  const [previewOpen, setPreviewOpen] = useState(false);
  const [preview, setPreview] = useState<{ html: string; toc: { level: number; id: string; text: string }[] } | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  const [revisionsOpen, setRevisionsOpen] = useState(false);
  const [revisions, setRevisions] = useState<RevisionView[] | null>(null);
  const [revisionsLoading, setRevisionsLoading] = useState(false);
  const [openRevision, setOpenRevision] = useState<RevisionView | null>(null);

  const [translations, setTranslations] = useState<PostView[] | null>(null);
  const [shareOpen, setShareOpen] = useState(false);
  const [shareUrl, setShareUrl] = useState<string | null>(null);
  const [shareLoading, setShareLoading] = useState(false);

  const isDirty = JSON.stringify(form) !== baselineRef.current;
  useDirtyGuard(isDirty);

  // A write-only user (no publish/manage) cannot touch a post that is already
  // published or scheduled at all — PostEditorialService::assertMayPublish
  // would 403 on ANY save, since the input always carries published:true back
  // unchanged. Lock the form rather than let Save fail with a confusing toast.
  const lockedByPolicy = Boolean(post?.published) && !capabilities.canPublish && !capabilities.canManage;
  const canEditAuthorId = capabilities.canManage;
  const canPublish = capabilities.canPublish || capabilities.canManage;

  // Routed through a ref so the page-header buttons (whose effect only re-runs
  // on the small deps list below) always call the LATEST handlers, even after
  // the user edits a field not in those deps. Same reason as the original.
  const actionsRef = useRef({ handleSave, openRevisions, openSharePreview });
  actionsRef.current = { handleSave, openRevisions, openSharePreview };

  // Use the APP SURFACE's standard page header/chrome — no bespoke top bar.
  useSetPageHeader(
    {
      title: postId ? form.title || "Edit post" : "New post",
      description: `${KIND_LABELS[form.kind]}${post ? ` · ${post.status}` : ""}`,
      back: { href: base, label: "Back to posts" },
      actions: [
        ...(postId
          ? [
              { label: "Revisions", icon: <History className="h-4 w-4" />, menu: true, onClick: () => actionsRef.current.openRevisions() },
              { label: "Share preview", icon: <Share2 className="h-4 w-4" />, menu: true, onClick: () => void actionsRef.current.openSharePreview() },
            ]
          : []),
        ...(post?.status === "published" && post.path
          ? [{ label: "View live", icon: <ExternalLink className="h-4 w-4" />, menu: true, onClick: () => { if (post?.path) window.open(post.path, "_blank", "noreferrer"); } }]
          : []),
        ...(canPublish && form.published
          ? [{ label: "Unpublish", icon: <Clock className="h-4 w-4" />, menu: true, onClick: () => void actionsRef.current.handleSave("unpublish") }]
          : []),
        { label: "Preview", icon: <Eye className="h-4 w-4" />, variant: "outline" as const, onClick: () => setPreviewOpen(true) },
        { label: "Settings", icon: <Settings2 className="h-4 w-4" />, variant: "outline" as const, onClick: () => setSettingsOpen((o) => !o) },
        ...(canPublish && !form.published
          ? [{ label: "Publish", icon: <CheckCircle2 className="h-4 w-4" />, variant: "default" as const, onClick: () => void actionsRef.current.handleSave("publish") }]
          : []),
        { label: saving ? "Saving…" : postId ? "Save" : "Create post", icon: <Save className="h-4 w-4" />, onClick: () => void actionsRef.current.handleSave() },
      ],
    },
    [form.title, form.kind, post?.status, post?.path, form.published, canPublish, saving, postId],
  );

  // Ctrl/Cmd+S saves instead of triggering the browser's "Save page".
  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "s") {
        e.preventDefault();
        void actionsRef.current.handleSave();
      }
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  // Slug auto-suggest — only while the user has not typed their own slug.
  useEffect(() => {
    if (slugTouched) return;
    if (!form.title.trim()) return;
    const handle = setTimeout(() => {
      postsApi
        .suggestSlug({ title: form.title, kind: form.kind, locale: form.locale, except: postId ?? undefined })
        .then(({ data }) => setForm((f) => (slugTouched ? f : { ...f, slug: data.slug })))
        .catch(() => void 0);
    }, 400);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.title, form.kind, form.locale, slugTouched]);

  // Slug availability — once the user edits the slug, ask the slug endpoint for
  // the unique slug it would mint from that text; if it hands back the SAME
  // value the slug is free, if it appended a suffix it is taken.
  useEffect(() => {
    if (!slugTouched) {
      setSlugState("idle");
      return;
    }
    const typed = (form.slug ?? "").trim();
    if (!typed) {
      setSlugState("idle");
      return;
    }
    setSlugState("checking");
    const handle = setTimeout(() => {
      postsApi
        .suggestSlug({ title: typed, kind: form.kind, locale: form.locale, except: postId ?? undefined })
        .then(({ data }) => setSlugState(data.slug === slugify(typed) ? "ok" : "taken"))
        .catch(() => setSlugState("idle"));
    }, 500);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.slug, form.kind, form.locale, slugTouched]);

  // Live preview — render the body exactly as saving it would, but only while
  // the preview drawer is open (no network cost for authors who never open it).
  useEffect(() => {
    if (!previewOpen) return;
    if (!form.body?.trim()) {
      setPreview({ html: "", toc: [] });
      return;
    }
    setPreviewLoading(true);
    const handle = setTimeout(() => {
      postsApi
        .render(form.body ?? "", form.bodyFormat)
        .then(({ data }) => setPreview(data))
        .catch(() => void 0)
        .finally(() => setPreviewLoading(false));
    }, 400);
    return () => clearTimeout(handle);
  }, [previewOpen, form.body, form.bodyFormat]);

  useEffect(() => {
    if (!postId) return;
    postsApi
      .translations(postId)
      .then(({ data }) => setTranslations(data))
      .catch(() => setTranslations(null));
  }, [postId]);

  function set<K extends keyof PostInput>(key: K, value: PostInput[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function errorsFor(key: string): string[] {
    const direct = fieldErrors[key];
    const list: string[] = direct ? (Array.isArray(direct) ? direct : [direct]) : [];
    for (const [k, v] of Object.entries(fieldErrors)) {
      if (k.startsWith(`${key}.`)) list.push(...(Array.isArray(v) ? v : [v]));
    }
    return list;
  }

  function Field({ k }: { k: string }) {
    const errors = errorsFor(k);
    if (errors.length === 0) return null;
    return <p className="mt-1 text-xs font-medium text-destructive">{errors.join(" ")}</p>;
  }

  const drawerErrorKeys = [
    "metaTitle",
    "metaDescription",
    "canonicalUrl",
    "authorName",
    "authorId",
    "dateline",
    "contact",
    "sourceName",
    "sourceUrl",
    "locale",
    "publishedAt",
    "categories",
  ];

  const plainBody = useMemo(() => (form.body ?? "").replace(/<[^>]*>/g, " ").replace(/&[a-z]+;/gi, " "), [form.body]);
  const wordCount = useMemo(() => plainBody.split(/\s+/).filter(Boolean).length, [plainBody]);
  const readingTime = Math.max(1, Math.round(wordCount / 230));

  async function handleSave(nextStatus?: "publish" | "unpublish") {
    if (lockedByPolicy || saving) return;
    if (!form.title.trim()) {
      toast.error("Add a title before saving.");
      return;
    }
    setSaving(true);
    setFieldErrors({});
    try {
      const payload: PostInput = { ...form };
      if (nextStatus === "publish" && canPublish) {
        payload.published = true;
        if (!payload.publishedAt) payload.publishedAt = new Date().toISOString();
      } else if (nextStatus === "unpublish" && canPublish) {
        payload.published = false;
      }

      if (postId) {
        delete payload.translationOf;
        if (revisionNote.trim()) payload.revisionNote = revisionNote.trim();
        if (post) payload.version = post.version;
      } else {
        payload.translationOf = form.translationOf?.trim() || undefined;
        delete payload.revisionNote;
      }

      const { data } = postId ? await postsApi.update(postId, payload) : await postsApi.create(payload);

      setPost(data);
      setForm(inputFromPost(data));
      baselineRef.current = JSON.stringify(inputFromPost(data));
      setRevisionNote("");
      setSlugState("idle");
      toast.success(postId ? "Post saved" : "Post created");

      if (!postId) {
        router.visit(`${base}/${data.id}/edit`, { preserveScroll: true });
      }
    } catch (e) {
      if (e instanceof PostsApiError) {
        if (e.isValidation && e.fields) {
          setFieldErrors(e.fields);
          const fields = e.fields;
          // Surface drawer-only errors by opening the drawer so they are visible.
          if (drawerErrorKeys.some((k) => k in fields || Object.keys(fields).some((f) => f.startsWith(`${k}.`)))) {
            setSettingsOpen(true);
          }
          toast.error("Fix the highlighted fields before saving.");
        } else if (e.status === 409) {
          toast.error(e.message, {
            duration: Infinity,
            action: { label: "Reload", onClick: () => window.location.reload() },
          });
        } else {
          toast.error(e.message || "Could not save this post.");
        }
      } else {
        toast.error("Could not reach the server. Check your connection and try again.");
      }
    } finally {
      setSaving(false);
    }
  }

  function openRevisions() {
    if (!postId) return;
    setRevisionsOpen(true);
    if (revisions === null) {
      setRevisionsLoading(true);
      postsApi
        .revisions(postId)
        .then(({ data }) => setRevisions(data))
        .catch(() => toast.error("Could not load revisions."))
        .finally(() => setRevisionsLoading(false));
    }
  }

  async function viewRevision(number: number) {
    if (!postId) return;
    try {
      const { data } = await postsApi.revision(postId, number);
      setOpenRevision(data);
    } catch {
      toast.error("Could not load that revision.");
    }
  }

  async function restoreRevision(number: number) {
    if (!postId) return;
    if (!confirm(`Restore revision #${number}? This replaces the current content.`)) return;
    try {
      const { data } = await postsApi.restoreRevision(postId, number);
      setPost(data);
      setForm(inputFromPost(data));
      baselineRef.current = JSON.stringify(inputFromPost(data));
      setRevisionsOpen(false);
      setOpenRevision(null);
      toast.success(`Restored revision #${number}`);
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "Could not restore that revision.");
    }
  }

  async function openSharePreview() {
    if (!postId) return;
    setShareOpen(true);
    setShareLoading(true);
    try {
      const { data } = await postsApi.previewLink(postId);
      setShareUrl(data.url);
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "Could not create a preview link.");
      setShareOpen(false);
    } finally {
      setShareLoading(false);
    }
  }

  /** Pick/upload a cover image via the host picker, or fall back to the drawer field. */
  async function pickCover() {
    if (lockedByPolicy) return;
    if (!pickImage) {
      setSettingsOpen(true);
      return;
    }
    const url = await pickImage({ title: "Cover image", value: form.coverImageUrl });
    if (url) set("coverImageUrl", url);
  }

  function addTag(raw: string) {
    const t = raw.trim().toLowerCase();
    setTagDraft("");
    if (!t || form.tags.includes(t)) return;
    if (form.tags.length >= 10) {
      toast.error("Up to 10 tags.");
      return;
    }
    set("tags", [...form.tags, t]);
  }

  const selectedCategoryCount = form.categories.length;

  // Auto-derived, shown read-only — the author never types these.
  const siteOrigin = (publicBaseUrl ?? "").replace(/\/+$/, "");
  const relPath = post?.path ?? `/blog/${form.slug || "…"}`;
  const publicUrl = siteOrigin ? siteOrigin + relPath : relPath;
  const authorDisplay = (form.authorName ?? "").trim() || capabilities.userName || "You";

  return (
    <div className="space-y-4">
      {lockedByPolicy && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          This post is published. Editing it needs the publish permission — ask an editor with that permission, or a manager, to make further changes.
        </div>
      )}

      {/* Title */}
      <div>
        <Input
          value={form.title}
          disabled={lockedByPolicy}
          onChange={(e) => set("title", e.target.value)}
          placeholder="A clear, specific headline"
          aria-label="Title"
          className="h-auto border-none bg-transparent px-0 text-2xl font-semibold shadow-none focus-visible:ring-0"
        />
        <Field k="title" />
      </div>

      {/* Meta bar: slug · category · cover · tags */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm">
        <div className="flex min-w-0 flex-1 items-center gap-1.5">
          <LinkIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <Input
            value={form.slug ?? ""}
            disabled={lockedByPolicy}
            onChange={(e) => {
              setSlugTouched(true);
              set("slug", e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "-").replace(/-+/g, "-"));
            }}
            placeholder="post-slug"
            aria-label="Slug"
            className="h-6 min-w-0 flex-1 border-none bg-transparent px-1 font-mono text-xs text-muted-foreground shadow-none focus-visible:ring-0"
          />
          {slugState === "checking" && <span className="shrink-0 text-[10px] text-muted-foreground">Checking…</span>}
          {slugState === "ok" && <span className="shrink-0 text-[10px] text-emerald-600 dark:text-emerald-400">Available</span>}
          {slugState === "taken" && <span className="shrink-0 text-[10px] text-amber-600 dark:text-amber-400">In use — a suffix will be added</span>}
        </div>

        <span className="h-4 w-px bg-border" />

        <div className="flex items-center gap-1.5">
          <FolderOpen className="h-3.5 w-3.5 text-muted-foreground" />
          <Select value={form.categories[0] ?? "__none__"} disabled={lockedByPolicy} onValueChange={(v) => set("categories", v === "__none__" ? [] : [v])}>
            <SelectTrigger className="h-6 w-40 border-none bg-transparent px-1 text-xs shadow-none">
              <SelectValue placeholder="Category" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">No category</SelectItem>
              {categories.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {" ".repeat(c.depth * 2)}
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {selectedCategoryCount > 1 && <span className="text-[10px] text-muted-foreground">+{selectedCategoryCount - 1}</span>}
        </div>

        <span className="h-4 w-px bg-border" />

        <button
          type="button"
          disabled={lockedByPolicy}
          onClick={() => void pickCover()}
          className="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
        >
          <ImageIcon className="h-3.5 w-3.5" />
          {form.coverImageUrl ? "Change cover" : "Add cover"}
        </button>

        <span className="h-4 w-px bg-border" />

        <div className="flex flex-wrap items-center gap-1.5">
          <TagIcon className="h-3.5 w-3.5 text-muted-foreground" />
          {form.tags.map((t) => (
            <Badge key={t} variant="secondary" className="gap-1 text-xs font-normal">
              {t}
              {!lockedByPolicy && (
                <button type="button" onClick={() => set("tags", form.tags.filter((x) => x !== t))} aria-label={`Remove tag ${t}`} className="hover:text-destructive">
                  <X className="h-3 w-3" />
                </button>
              )}
            </Badge>
          ))}
          <Input
            value={tagDraft}
            disabled={lockedByPolicy}
            onChange={(e) => setTagDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" || e.key === ",") {
                e.preventDefault();
                addTag(tagDraft);
              } else if (e.key === "Backspace" && tagDraft === "" && form.tags.length > 0) {
                set("tags", form.tags.slice(0, -1));
              }
            }}
            onBlur={() => tagDraft && addTag(tagDraft)}
            placeholder="Add tag…"
            className="h-6 w-24 border-none bg-transparent px-1 text-xs shadow-none focus-visible:ring-0"
          />
        </div>
      </div>
      <Field k="slug" />
      <Field k="tags" />

      {/* Cover hero (flat, no overlay) */}
      {form.coverImageUrl && (
        <div className="overflow-hidden rounded-lg border border-border">
          <img
            src={form.coverImageUrl}
            alt={form.coverImageAlt ?? ""}
            className="h-48 w-full object-cover sm:h-60"
            onError={(e) => (e.currentTarget.style.display = "none")}
          />
          <div className="flex items-center justify-between gap-2 border-t border-border bg-muted/40 px-3 py-1.5">
            <span className="text-xs text-muted-foreground">{form.coverImageAlt || "Cover image"}</span>
            {!lockedByPolicy && (
              <div className="flex gap-2">
                <button type="button" onClick={() => void pickCover()} className="text-xs text-muted-foreground hover:text-foreground">
                  Change
                </button>
                <button type="button" onClick={() => set("coverImageUrl", "")} className="text-xs text-destructive hover:underline">
                  Remove
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Subtitle + excerpt */}
      <div className="space-y-1">
        <Input
          value={form.subtitle ?? ""}
          disabled={lockedByPolicy}
          onChange={(e) => set("subtitle", e.target.value)}
          placeholder="Subtitle (optional)"
          aria-label="Subtitle"
          className="h-auto border-none bg-transparent px-0 text-base font-medium shadow-none focus-visible:ring-0"
        />
        <Textarea
          value={form.excerpt ?? ""}
          disabled={lockedByPolicy}
          onChange={(e) => set("excerpt", e.target.value)}
          placeholder="Write a short summary or excerpt…"
          aria-label="Excerpt"
          rows={2}
          className="resize-none border-none bg-transparent px-0 text-sm italic text-muted-foreground shadow-none focus-visible:ring-0"
        />
      </div>

      {/* Writing surface */}
      <div>
        <div className="mb-2 flex items-center justify-between">
          <span className="text-xs text-muted-foreground">
            {wordCount} {wordCount === 1 ? "word" : "words"} · {readingTime} min read
          </span>
          <Select value={form.bodyFormat} disabled={lockedByPolicy} onValueChange={(v) => set("bodyFormat", v as BodyFormat)}>
            <SelectTrigger className="h-7 w-32 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {BODY_FORMATS.map((f) => (
                <SelectItem key={f.value} value={f.value}>
                  {f.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        {form.bodyFormat === "html" ? (
          <RichTextEditor value={form.body ?? ""} disabled={lockedByPolicy} onChange={(html) => set("body", html)} rows={20} pickImage={pickImage} />
        ) : (
          <Textarea
            rows={24}
            value={form.body ?? ""}
            disabled={lockedByPolicy}
            onChange={(e) => set("body", e.target.value)}
            className="font-mono text-sm"
            placeholder="Write the post body…"
          />
        )}
        <Field k="body" />
        <p className="mt-2 text-center text-xs text-muted-foreground">⌘S / Ctrl+S saves</p>
      </div>

      {/* Settings drawer */}
      <DetailDrawer open={settingsOpen} onClose={() => setSettingsOpen(false)} title="Post settings" width="sm:max-w-md">
        <div className="space-y-6">
          {/* At-a-glance status */}
          <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-muted/30 p-3">
            <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${STATUS_PILL[post?.status ?? (form.published ? "published" : "draft")] ?? STATUS_PILL.draft}`}>
              {post?.status ?? (form.published ? "published" : "draft")}
            </span>
            {post && <span className="text-xs text-muted-foreground">{post.viewCount} views</span>}
            {post && <span className="text-xs text-muted-foreground">Updated {new Date(post.updatedAt).toLocaleDateString()}</span>}
          </div>

          <Section icon={CheckCircle2} title="Publishing" hint="Whether this post is live, and when.">
            <div className="flex items-center justify-between">
              <Label htmlFor="published">Published</Label>
              <Switch id="published" checked={form.published} disabled={lockedByPolicy || !canPublish} onCheckedChange={(v) => set("published", v)} />
            </div>
            {canPublish && (
              <div>
                <Label htmlFor="publishedAt" className="flex items-center gap-1.5">
                  Publish date
                  <FieldHelp title="Publish date" rules={["In the future: schedules the post.", "In the past or now: publishes immediately."]} />
                </Label>
                <Input id="publishedAt" type="datetime-local" disabled={lockedByPolicy} value={toLocalInputValue(form.publishedAt)} onChange={(e) => set("publishedAt", fromLocalInputValue(e.target.value))} />
                <Field k="publishedAt" />
              </div>
            )}
            <div className="flex items-center justify-between">
              <Label htmlFor="featured">Featured</Label>
              <Switch id="featured" checked={form.featured} disabled={lockedByPolicy} onCheckedChange={(v) => set("featured", v)} />
            </div>
            <div className="flex items-center justify-between">
              <Label htmlFor="comments">Comments enabled</Label>
              <Switch id="comments" checked={form.commentsEnabled} disabled={lockedByPolicy} onCheckedChange={(v) => set("commentsEnabled", v)} />
            </div>
          </Section>

          <Section icon={User} title="Author" hint="Set automatically to you — whoever creates the post.">
            <div className="flex items-center gap-2 rounded-md border border-dashed border-border bg-muted/40 px-3 py-2">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/15 text-xs font-semibold text-primary">
                {authorDisplay.slice(0, 1).toUpperCase()}
              </span>
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{authorDisplay}</p>
                <p className="text-xs text-muted-foreground">Byline on the published post</p>
              </div>
            </div>
            {canEditAuthorId && (
              <Collapsible open={bylineOpen} onOpenChange={setBylineOpen}>
                <CollapsibleTrigger className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                  <ChevronDown className={`h-3.5 w-3.5 transition-transform ${bylineOpen ? "rotate-180" : ""}`} />
                  Byline to someone else
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-3 pt-3">
                  <div>
                    <Label htmlFor="authorName">Author name</Label>
                    <Input id="authorName" value={form.authorName ?? ""} disabled={lockedByPolicy} onChange={(e) => set("authorName", e.target.value)} placeholder={capabilities.userName || "Author name"} />
                    <Field k="authorName" />
                  </div>
                  <div>
                    <Label htmlFor="authorId" className="flex items-center gap-1.5">
                      Author id
                      <FieldHelp title="Author id" description="A manager can byline a post to another user by their id. Leave blank to keep yourself as the author." />
                    </Label>
                    <Input id="authorId" value={form.authorId ?? ""} disabled={lockedByPolicy} onChange={(e) => set("authorId", e.target.value)} />
                    <Field k="authorId" />
                  </div>
                </CollapsibleContent>
              </Collapsible>
            )}
          </Section>

          <Section icon={ImageIcon} title="Cover image" hint="Shown at the top of the post and in shares.">
            {form.coverImageUrl && (
              <img
                src={form.coverImageUrl}
                alt={form.coverImageAlt ?? ""}
                className="h-28 w-full rounded-md border border-border object-cover"
                onError={(e) => (e.currentTarget.style.display = "none")}
              />
            )}
            {pickImage && (
              <Button type="button" variant="outline" size="sm" disabled={lockedByPolicy} onClick={() => void pickCover()} className="w-full">
                <ImagePlus className="mr-1.5 h-4 w-4" />
                {form.coverImageUrl ? "Change — upload or pick from gallery" : "Upload or pick from gallery"}
              </Button>
            )}
            <div>
              <Label htmlFor="coverImageUrl">{pickImage ? "…or paste an image URL" : "Image URL"}</Label>
              <Input id="coverImageUrl" value={form.coverImageUrl ?? ""} disabled={lockedByPolicy} onChange={(e) => set("coverImageUrl", e.target.value)} placeholder="https://…" />
              <Field k="coverImageUrl" />
            </div>
            <div>
              <Label htmlFor="coverImageAlt">Alt text</Label>
              <Input id="coverImageAlt" value={form.coverImageAlt ?? ""} disabled={lockedByPolicy} onChange={(e) => set("coverImageAlt", e.target.value)} placeholder="Describe the image" />
            </div>
            {form.coverImageUrl && (
              <button type="button" disabled={lockedByPolicy} onClick={() => set("coverImageUrl", "")} className="text-xs text-destructive hover:underline disabled:opacity-50">
                Remove cover image
              </button>
            )}
          </Section>

          <Section icon={FolderOpen} title="Categories" hint={`${selectedCategoryCount}/5 selected`}>
            <div className="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-border p-2">
              {categories.length === 0 && <p className="p-2 text-sm text-muted-foreground">No categories yet.</p>}
              {categories.map((c) => {
                const checked = form.categories.includes(c.id);
                return (
                  <label key={c.id} style={{ paddingLeft: c.depth * 16 }} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted">
                    <input
                      type="checkbox"
                      checked={checked}
                      disabled={lockedByPolicy || (!checked && selectedCategoryCount >= 5)}
                      onChange={(e) => set("categories", e.target.checked ? [...form.categories, c.id] : form.categories.filter((id) => id !== c.id))}
                    />
                    {c.name}
                  </label>
                );
              })}
            </div>
            <Field k="categories" />
          </Section>

          {form.kind === "press" && (
            <Section icon={Megaphone} title="Press release">
              <div>
                <Label htmlFor="dateline">Dateline</Label>
                <Input id="dateline" value={form.dateline ?? ""} disabled={lockedByPolicy} onChange={(e) => set("dateline", e.target.value)} placeholder="KINSHASA, DRC — " />
                <Field k="dateline" />
              </div>
              <div>
                <Label htmlFor="contact">Media contact</Label>
                <Input id="contact" value={form.contact ?? ""} disabled={lockedByPolicy} onChange={(e) => set("contact", e.target.value)} />
                <Field k="contact" />
              </div>
              <div>
                <Label htmlFor="sourceName">Source name</Label>
                <Input id="sourceName" value={form.sourceName ?? ""} disabled={lockedByPolicy} onChange={(e) => set("sourceName", e.target.value)} />
              </div>
              <div>
                <Label htmlFor="sourceUrl">Source URL</Label>
                <Input id="sourceUrl" value={form.sourceUrl ?? ""} disabled={lockedByPolicy} onChange={(e) => set("sourceUrl", e.target.value)} />
                <Field k="sourceUrl" />
              </div>
            </Section>
          )}

          <Section icon={Search} title="Search & sharing" hint="How this post looks in Google and when shared.">
            <AutoField
              label="Public address"
              value={publicUrl}
              hint="Filled in for you — the site address plus the post's slug."
              onCopy={() => void copyToClipboard(publicUrl)}
            />
            <div>
              <Label htmlFor="metaTitle">SEO title ({(form.metaTitle ?? "").length}/160)</Label>
              <Input id="metaTitle" maxLength={160} value={form.metaTitle ?? ""} disabled={lockedByPolicy} onChange={(e) => set("metaTitle", e.target.value)} placeholder={form.title || "Defaults to the post title"} />
              <Field k="metaTitle" />
            </div>
            <div>
              <Label htmlFor="metaDescription">SEO description ({(form.metaDescription ?? "").length}/320)</Label>
              <Textarea id="metaDescription" rows={2} maxLength={320} value={form.metaDescription ?? ""} disabled={lockedByPolicy} onChange={(e) => set("metaDescription", e.target.value)} placeholder={form.excerpt || "Defaults to the excerpt"} />
              <Field k="metaDescription" />
            </div>
            <div className="rounded-lg border border-border p-3">
              <p className="truncate text-xs text-emerald-700 dark:text-emerald-400">{publicUrl}</p>
              <p className="truncate text-base text-blue-700 dark:text-blue-400">{form.metaTitle || form.title || "Untitled"}</p>
              <p className="line-clamp-2 text-sm text-muted-foreground">{form.metaDescription || form.excerpt || "No description yet."}</p>
            </div>
          </Section>

          <Collapsible open={advancedOpen} onOpenChange={setAdvancedOpen}>
            <CollapsibleTrigger className="flex w-full items-center gap-2 border-t border-border pt-4 text-sm font-medium text-muted-foreground hover:text-foreground">
              <SlidersHorizontal className="h-4 w-4" />
              Advanced
              <ChevronDown className={`ml-auto h-4 w-4 transition-transform ${advancedOpen ? "rotate-180" : ""}`} />
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-4 pt-4">
              <div>
                <Label htmlFor="locale">Language (locale)</Label>
                <Input id="locale" value={form.locale ?? ""} disabled={lockedByPolicy} onChange={(e) => set("locale", e.target.value)} placeholder="en" />
                <Field k="locale" />
              </div>
              {!postId && (
                <div>
                  <Label htmlFor="translationOf" className="flex items-center gap-1.5">
                    <Languages className="h-3.5 w-3.5" /> Translation of (post id)
                  </Label>
                  <Input id="translationOf" value={form.translationOf ?? ""} onChange={(e) => set("translationOf", e.target.value || undefined)} placeholder="Optional — links this as a translation" />
                </div>
              )}
              <div>
                <Label htmlFor="canonicalUrl">Canonical URL</Label>
                <Input id="canonicalUrl" value={form.canonicalUrl ?? ""} disabled={lockedByPolicy} onChange={(e) => set("canonicalUrl", e.target.value)} placeholder={publicUrl} />
                <p className="mt-1 text-xs text-muted-foreground">Leave blank to use the public address above. Set it only to point search engines at a different URL.</p>
                <Field k="canonicalUrl" />
              </div>
              <label className="flex items-center gap-2 text-sm">
                <Switch checked={form.noindex} disabled={lockedByPolicy} onCheckedChange={(v) => set("noindex", v)} />
                Hide from search engines (noindex)
              </label>
              {postId && (
                <div>
                  <Label htmlFor="revisionNote">Revision note</Label>
                  <Input id="revisionNote" value={revisionNote} onChange={(e) => setRevisionNote(e.target.value)} maxLength={200} placeholder="What changed? (optional)" />
                </div>
              )}
            </CollapsibleContent>
          </Collapsible>

          {translations && translations.length > 0 && (
            <Section icon={Globe} title="Translations">
              <div className="flex flex-wrap gap-2">
                {translations.map((t) => (
                  <Link key={t.id} href={`${base}/${t.id}/edit`} className="inline-flex">
                    <Badge variant="outline" className="gap-1">
                      {t.locale} — {t.title}
                    </Badge>
                  </Link>
                ))}
              </div>
            </Section>
          )}
        </div>
      </DetailDrawer>

      {/* Preview drawer */}
      <DetailDrawer open={previewOpen} onClose={() => setPreviewOpen(false)} title="Preview" width="sm:max-w-2xl">
        <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
          <Eye className="h-3.5 w-3.5" /> Rendered exactly as it will publish {previewLoading && <Loader2 className="h-3 w-3 animate-spin" />}
        </div>
        <article className="prose prose-sm mt-3 max-w-none dark:prose-invert">
          {form.coverImageUrl && <img src={form.coverImageUrl} alt={form.coverImageAlt ?? ""} className="mb-4 w-full rounded-lg object-cover" />}
          <h1>{form.title || "Untitled"}</h1>
          {form.subtitle && <p className="lead">{form.subtitle}</p>}
          <div
            // Safe: preview.html comes from the server's commonmark + sanitizer
            // pipeline (see API/DTO/PostView.php). Never raw form.body here.
            dangerouslySetInnerHTML={{ __html: preview?.html ?? "<p class='text-muted-foreground'>Nothing to preview yet.</p>" }}
          />
        </article>
        {preview && preview.toc.length > 0 && (
          <div className="mt-4 rounded-lg border border-border p-3 text-xs">
            <p className="mb-1 font-medium text-muted-foreground">Table of contents</p>
            <ul className="space-y-0.5">
              {preview.toc.map((t) => (
                <li key={t.id} style={{ paddingLeft: (t.level - 2) * 12 }} className="truncate text-foreground/80">
                  {t.text}
                </li>
              ))}
            </ul>
          </div>
        )}
      </DetailDrawer>

      {/* Revisions drawer */}
      <DetailDrawer open={revisionsOpen} onClose={() => setRevisionsOpen(false)} title="Revisions" width="sm:max-w-xl">
        {revisionsLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
        {!revisionsLoading && revisions && revisions.length === 0 && <p className="text-sm text-muted-foreground">No revisions yet.</p>}
        {!revisionsLoading && revisions && revisions.length > 0 && (
          <ul className="space-y-2">
            {revisions.map((r) => (
              <li key={r.number} className="rounded-lg border border-border p-3">
                <div className="flex items-center justify-between gap-2">
                  <div>
                    <p className="text-sm font-medium">
                      #{r.number} — {r.title}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {new Date(r.createdAt).toLocaleString()} {r.createdBy ? `· ${r.createdBy}` : ""}
                      {r.note ? ` · ${r.note}` : ""}
                    </p>
                  </div>
                  <div className="flex shrink-0 gap-1">
                    <Button size="sm" variant="outline" onClick={() => void viewRevision(r.number)}>
                      View
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => void restoreRevision(r.number)}>
                      Restore
                    </Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}

        {openRevision && (
          <div className="mt-4 space-y-4">
            <DrawerSection title={`Revision #${openRevision.number}`}>
              <DrawerField label="Title" span2>
                {String(openRevision.snapshot?.title ?? openRevision.title)}
              </DrawerField>
              <DrawerField label="Excerpt" span2>
                {String(openRevision.snapshot?.excerpt ?? "—")}
              </DrawerField>
              <DrawerField label="Published">{String(openRevision.snapshot?.published ?? "—")}</DrawerField>
              <DrawerField label="Published at">{String(openRevision.snapshot?.publishedAt ?? "—")}</DrawerField>
            </DrawerSection>
          </div>
        )}
      </DetailDrawer>

      {/* Share preview dialog */}
      <Dialog open={shareOpen} onOpenChange={setShareOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Share2 className="h-4 w-4" /> Share preview link
            </DialogTitle>
          </DialogHeader>
          {shareLoading && <p className="text-sm text-muted-foreground">Creating a link…</p>}
          {!shareLoading && shareUrl && (
            <div className="space-y-2">
              <Input readOnly value={shareUrl} onFocus={(e) => e.currentTarget.select()} />
              <p className="text-xs text-muted-foreground">Anyone with this link can read the draft until it expires.</p>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShareOpen(false)}>
              Close
            </Button>
            {shareUrl && <Button onClick={() => void copyToClipboard(shareUrl)}>Copy link</Button>}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

PostsEditor.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
