import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { createElement } from "react";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { PageContext } from "@pageflow/react";
import { AdminLayout } from "@pageflow/admin";
import Page from "../admin/Pages/Posts/Editor";
import fixture from "../__fixtures__/posts-editor.json";

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), message: vi.fn(), info: vi.fn() }));
vi.mock("sonner", () => ({ toast, Toaster: () => null }));

/**
 * Saving sends back the version the form was opened at, and a 409 (someone
 * else saved in between) is reported as such, with a way to reload, rather
 * than as a generic failure.
 */
const post = (fixture as any).props.post;
let sent: Record<string, unknown> | null = null;
let answer: { status: number; body: unknown };

beforeEach(() => {
  sent = null;
  toast.success.mockClear();
  toast.error.mockClear();
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    if ((init?.method ?? "GET") === "PUT") {
      sent = JSON.parse(String(init?.body));
      return new Response(JSON.stringify(answer.body), { status: answer.status, headers: { "Content-Type": "application/json" } });
    }
    // Revisions, slug suggestions, live preview… whatever the page asks for on its own.
    return new Response(JSON.stringify({ data: [] }), { status: 200, headers: { "Content-Type": "application/json" } });
  }));
});

afterEach(() => vi.unstubAllGlobals());

function renderPage() {
  const child = createElement(Page as any, (fixture as any).props);
  const layout = (Page as any).layout;
  const tree = typeof layout === "function" ? layout(child) : <AdminLayout>{child}</AdminLayout>;

  return render(<PageContext.Provider value={fixture as any}>{tree}</PageContext.Provider>);
}

function clickSave() {
  // The header and the sidebar both offer Save; either submits the same form.
  fireEvent.click(screen.getAllByRole("button", { name: "Save" })[0]);
}

describe("Posts/Editor saving", () => {
  it("sends the version it was opened at", async () => {
    answer = { status: 200, body: { data: { ...post, version: post.version + 1 } } };
    renderPage();

    clickSave();

    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("Post saved"));
    expect(sent?.version).toBe(post.version);
  });

  it("reports a concurrent save as a conflict with a reload action", async () => {
    answer = {
      status: 409,
      body: { error: { code: "post.edit_conflict", message: "Someone else saved this post after you opened it." } },
    };
    renderPage();

    clickSave();

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    const [message, options] = toast.error.mock.calls[0];
    expect(message).toBe("Someone else saved this post after you opened it.");
    expect(options?.action?.label).toBe("Reload");
    expect(toast.success).not.toHaveBeenCalled();
  });
});
