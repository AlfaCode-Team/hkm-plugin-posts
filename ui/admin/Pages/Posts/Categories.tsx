import type { ReactNode } from "react";
import { useState } from "react";
import { AdminLayout, useSetPageHeader } from "@pageflow/admin";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Textarea } from "@ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@ui/select";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@ui/dialog";
import { FolderTree, MoreHorizontal, Pencil, Plus, Trash2 } from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@ui/dropdown-menu";
import { toast } from "sonner";
import { postCategoriesApi, PostsApiError } from "../../lib/api";
import type { CategoryView, EditorCapabilities } from "../../lib/types";

interface CategoriesProps {
  capabilities: EditorCapabilities;
  categories: CategoryView[];
  /** Where this admin UI is mounted (hkmvote serves it under /content/blog). */
  basePath?: string;
}

interface FormState {
  name: string;
  slug: string;
  description: string;
  parentId: string;
  position: string;
}

const EMPTY_FORM: FormState = { name: "", slug: "", description: "", parentId: "", position: "" };

/** This node and every descendant — never a legal `parentId` for itself. */
function descendantsOf(id: string, all: CategoryView[]): Set<string> {
  const childrenOf = new Map<string, string[]>();
  for (const c of all) {
    if (c.parentId) childrenOf.set(c.parentId, [...(childrenOf.get(c.parentId) ?? []), c.id]);
  }
  const result = new Set<string>([id]);
  const stack = [...(childrenOf.get(id) ?? [])];
  while (stack.length > 0) {
    const next = stack.pop();
    if (next === undefined || result.has(next)) continue;
    result.add(next);
    stack.push(...(childrenOf.get(next) ?? []));
  }
  return result;
}

export default function PostsCategories({ capabilities, categories: initial, basePath }: CategoriesProps) {
  const base = (basePath ?? "/admin/posts").replace(/\/+$/, "");
  const [categories, setCategories] = useState<CategoryView[]>(initial);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<CategoryView | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);

  useSetPageHeader(
    {
      title: "Categories",
      description: "Organise posts into a tree. Parents move with their children.",
      back: { href: base, label: "Back to posts" },
      actions: capabilities.canManage ? [{ label: "New category", onClick: () => openCreate() }] : [],
    },
    [capabilities.canManage],
  );

  function reload() {
    postCategoriesApi
      .list()
      .then(({ data }) => setCategories(data))
      .catch(() => toast.error("Could not reload categories."));
  }

  function openCreate(parentId?: string) {
    setEditing(null);
    setForm({ ...EMPTY_FORM, parentId: parentId ?? "" });
    setDialogOpen(true);
  }

  function openEdit(category: CategoryView) {
    setEditing(category);
    setForm({
      name: category.name,
      slug: category.slug,
      description: category.description ?? "",
      parentId: category.parentId ?? "",
      position: String(category.position ?? ""),
    });
    setDialogOpen(true);
  }

  async function handleSubmit() {
    if (!form.name.trim()) {
      toast.error("Name is required.");
      return;
    }
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim(),
        slug: form.slug.trim() || undefined,
        description: form.description.trim() || undefined,
        parentId: form.parentId || null,
        position: form.position.trim() ? Number(form.position) : undefined,
      };

      if (editing) {
        await postCategoriesApi.update(editing.id, payload);
        toast.success("Category updated");
      } else {
        await postCategoriesApi.create(payload);
        toast.success("Category created");
      }
      setDialogOpen(false);
      reload();
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "Could not save this category.");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(category: CategoryView) {
    if (!confirm(`Delete "${category.name}"? Its children move up a level and posts keep their other categories.`)) return;
    try {
      await postCategoriesApi.destroy(category.id);
      toast.success("Category deleted");
      reload();
    } catch (e) {
      toast.error(e instanceof PostsApiError ? e.message : "Could not delete this category.");
    }
  }

  const excluded = editing ? descendantsOf(editing.id, categories) : new Set<string>();
  const parentOptions = categories.filter((c) => !excluded.has(c.id));

  return (
    <div className="mx-auto max-w-3xl space-y-4 p-6">
      <div className="rounded-lg border border-border">
        {categories.length === 0 && (
          <div className="flex flex-col items-center gap-3 py-16 text-center">
            <FolderTree className="h-10 w-10 text-muted-foreground/60" />
            <p className="text-sm text-muted-foreground">No categories yet.</p>
            {capabilities.canManage && (
              <Button size="sm" onClick={() => openCreate()}>
                <Plus className="mr-1.5 h-4 w-4" /> New category
              </Button>
            )}
          </div>
        )}
        <ul className="divide-y divide-border">
          {categories.map((c) => (
            <li key={c.id} className="flex items-center justify-between gap-3 px-4 py-2.5" style={{ paddingLeft: 16 + c.depth * 20 }}>
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{c.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                  /{c.slug} · {c.postCount} post{c.postCount === 1 ? "" : "s"}
                  {c.ancestors.length > 0 ? ` · in ${c.ancestors.join(" / ")}` : ""}
                </p>
              </div>
              {capabilities.canManage && (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="h-8 w-8 shrink-0">
                      <MoreHorizontal className="h-4 w-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onClick={() => openEdit(c)}>
                      <Pencil className="mr-2 h-3.5 w-3.5" /> Edit / move
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => openCreate(c.id)}>
                      <Plus className="mr-2 h-3.5 w-3.5" /> Add subcategory
                    </DropdownMenuItem>
                    <DropdownMenuItem className="text-destructive" onClick={() => void handleDelete(c)}>
                      <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
            </li>
          ))}
        </ul>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? "Edit category" : "New category"}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div>
              <Label htmlFor="cat-name">Name</Label>
              <Input id="cat-name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} autoFocus />
            </div>
            <div>
              <Label htmlFor="cat-slug">Slug (optional)</Label>
              <Input id="cat-slug" value={form.slug} onChange={(e) => setForm((f) => ({ ...f, slug: e.target.value }))} placeholder="Derived from the name if left blank" />
            </div>
            <div>
              <Label htmlFor="cat-description">Description (optional)</Label>
              <Textarea id="cat-description" rows={2} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
            </div>
            <div>
              <Label htmlFor="cat-parent">Parent</Label>
              <Select value={form.parentId || "__root__"} onValueChange={(v) => setForm((f) => ({ ...f, parentId: v === "__root__" ? "" : v }))}>
                <SelectTrigger id="cat-parent">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__root__">No parent (top level)</SelectItem>
                  {parentOptions.map((c) => (
                    <SelectItem key={c.id} value={c.id}>
                      {" ".repeat(c.depth * 2)}
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label htmlFor="cat-position">Position (optional)</Label>
              <Input id="cat-position" type="number" value={form.position} onChange={(e) => setForm((f) => ({ ...f, position: e.target.value }))} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button disabled={saving} onClick={() => void handleSubmit()}>
              {editing ? "Save" : "Create"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

PostsCategories.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
