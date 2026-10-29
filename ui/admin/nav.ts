import { Newspaper } from "lucide-react";
import { registerModule, registerFeature } from "@pageflow/admin";

registerFeature({
  id: "posts",
  label: "Posts, articles & press releases",
  description: "Blog posts, articles and press releases in one editorial workflow",
});

registerModule({
  id: "posts",
  sectionLabel: "Content",
  order: 30,
  features: ["posts"],
  items: [
    {
      id: "posts",
      label: "Posts",
      icon: Newspaper,
      path: "/admin/posts",
      parentPath: "/admin/posts",
      children: [
        { id: "posts-all", label: "All posts", path: "/admin/posts" },
        { id: "posts-create", label: "New post", path: "/admin/posts/create" },
        { id: "posts-categories", label: "Categories", path: "/admin/posts/categories" },
        // Hidden: participates in active-path matching so an open editor
        // highlights "Posts" in the sidebar without rendering its own row.
        { id: "posts-edit", label: "Edit post", path: "/admin/posts", hidden: true },
      ],
    },
  ],
});
