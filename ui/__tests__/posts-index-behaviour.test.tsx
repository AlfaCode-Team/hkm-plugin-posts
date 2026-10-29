import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { createElement } from "react";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { PageContext } from "@pageflow/react";
import { AdminLayout } from "@pageflow/admin";
import Page from "../admin/Pages/Posts/Index";
import fixture from "../__fixtures__/posts-index.json";

// The toast is the only thing a bulk action shows; record it instead of
// rendering it. Toaster stays a component so any shell that mounts one still
// renders.
const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), message: vi.fn(), info: vi.fn() }));
vi.mock("sonner", () => ({ toast, Toaster: () => null }));

/**
 * Behaviour, not just "it renders": these drive the page against a stubbed
 * admin API whose responses have the server's real envelope ({ data, meta }).
 * The render-only tests could not see that the bulk outcome was read without
 * unwrapping `data`, which made every bulk action report "1 [object Object]".
 */
const post = (fixture as any).props.initialPage.data[0];
let calls: string[] = [];

function respond(url: string, init?: RequestInit): unknown {
  if (url.includes("/counts")) return { data: { draft: 2, scheduled: 1, published: 7, trash: 3 } };
  if (url.includes("/bulk")) {
    const { ids } = JSON.parse(String(init?.body));
    return { data: Object.fromEntries(ids.map((id: string) => [id, "ok"])) };
  }
  return (fixture as any).props.initialPage;
}

beforeEach(() => {
  calls = [];
  toast.success.mockClear();
  toast.error.mockClear();
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    calls.push(`${init?.method ?? "GET"} ${url}`);
    return new Response(JSON.stringify(respond(url, init)), { status: 200, headers: { "Content-Type": "application/json" } });
  }));
  vi.stubGlobal("confirm", () => true);
});

afterEach(() => vi.unstubAllGlobals());

function renderPage() {
  const child = createElement(Page as any, (fixture as any).props);
  const layout = (Page as any).layout;
  const tree = typeof layout === "function" ? layout(child) : <AdminLayout>{child}</AdminLayout>;

  return render(<PageContext.Provider value={fixture as any}>{tree}</PageContext.Provider>);
}

describe("Posts/Index behaviour", () => {
  it("fills every status tab from ONE counts request", async () => {
    renderPage();

    await waitFor(() => expect(screen.getByRole("button", { name: /Published\s*7/ })).toBeInTheDocument());
    expect(calls.filter((c) => c.includes("/counts"))).toHaveLength(1);
    expect(calls.some((c) => c.includes("perPage=1"))).toBe(false);
    // "All" is the untrashed total, the same set the unfiltered list shows.
    expect(screen.getByRole("button", { name: /All\s*10/ })).toBeInTheDocument();
  });

  it("reports a bulk action's outcome per post", async () => {
    renderPage();

    fireEvent.click(screen.getByRole("checkbox", { name: `Select "${post.title}"` }));
    fireEvent.click(await screen.findByRole("button", { name: "Trash" }));

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("1 ok"));
    expect(toast.error).not.toHaveBeenCalled();
    expect(calls).toContain("POST /api/admin/posts/bulk");
  });
});
