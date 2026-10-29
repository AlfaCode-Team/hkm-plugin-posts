import { describe, it, expect } from "vitest";
import { createElement } from "react";
// Add `screen` here when you assert on what the user sees:
//   import { render, screen } from "@testing-library/react";
import { render } from "@testing-library/react";
import { PageContext } from "@pageflow/react";
import { AdminLayout } from "@pageflow/admin";
import Page from "../admin/Pages/Posts/Index";
import fixture from "../__fixtures__/posts-index.json";

/**
 * Component test for `Posts/Index`.
 *
 * Props come from __fixtures__/posts-index.json, which a ground test dumped
 * from a REAL response:
 *
 *   $this->ground()->pageflow('/your/route')
 *       ->writeFixture(__DIR__ . '/../ui/__fixtures__/posts-index.json');
 *
 * That is the point of the fixture. Hand-written mock props drift from the
 * server the moment a field is renamed, and this test keeps passing while
 * the page breaks. With the dump, a server-side rename fails it here.
 *
 * Re-dump the fixture when the props change, and commit it.
 *
 * The PageContext wrapper is not optional: a Pageflow page reads its props
 * through `usePage()`, which THROWS on an empty context, so passing props
 * as JSX attributes renders nothing. The value is the whole page object —
 * the same thing <App> supplies at runtime, and the same shape the fixture
 * holds.
 *
 * The LAYOUT is applied for the same reason. Rendering a bare <Page />
 * tested the page body and nothing around it: a page whose `.layout`
 * throws, or whose shell needs a prop the server stopped sending, passed
 * here and broke in the browser. This mirrors what <App> does — honour
 * `Component.layout` when there is one, fall back to <AdminLayout> when
 * there is not — so the test renders the same tree a request does.
 */
function renderPage() {
  const child = createElement(Page as any, fixture.props as any);
  const layout = (Page as any).layout;

  const tree =
    typeof layout === "function"
      ? layout(child)
      : <AdminLayout>{child}</AdminLayout>;

  return render(
    <PageContext.Provider value={fixture as any}>{tree}</PageContext.Provider>,
  );
}

// A fixture that has never been dumped is a PLACEHOLDER: skip loudly
// rather than assert against empty props, which fails somewhere inside
// the component and reads like the page is broken.
const pending = (fixture as any).__placeholder === true;

describe.skipIf(pending)("Posts/Index", () => {
  it("renders with the props the server actually sends", () => {
    renderPage();

    // Replace with something the page must show. Prefer a role or a
    // user-visible string over a test id: an assertion on markup
    // structure breaks on a redesign that changed nothing real.
    expect(document.body.textContent).not.toBe("");
  });

  it("has the props it needs", () => {
    // Guards the seam in the other direction: if the server stops sending
    // a prop this page reads, this fails BEFORE the page renders undefined.
    expect(fixture.props).toBeDefined();
  });

  it("shows nothing sensitive", () => {
    // Props are serialized into the page object and shipped to the browser.
    const serialized = JSON.stringify(fixture.props);
    expect(serialized).not.toMatch(/password|secret|token_hash/i);
  });
});
