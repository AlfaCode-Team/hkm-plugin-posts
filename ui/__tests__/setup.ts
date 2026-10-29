import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Without this, a component left mounted by one test is still in the
// document for the next, and a passing assertion may be reading the
// previous test's render.
afterEach(() => cleanup());

// Node 24+ ships its OWN localStorage, which shadows the one jsdom
// provides and is `undefined` unless node was started with
// --localstorage-file. Any component reading a stored preference
// then dies on `localStorage.getItem` of undefined — the shared
// ThemeProvider does exactly that, so a page wrapped in it fails to
// render for a reason that has nothing to do with the page.
//
// In a browser localStorage always exists, so the honest stand-in is
// a working in-memory one, not a throwing stub.
if (typeof globalThis.localStorage === "undefined" || globalThis.localStorage === null) {
  const store = new Map<string, string>();

  Object.defineProperty(globalThis, "localStorage", {
    configurable: true,
    value: {
      getItem: (k: string) => (store.has(k) ? store.get(k)! : null),
      setItem: (k: string, v: string) => void store.set(k, String(v)),
      removeItem: (k: string) => void store.delete(k),
      clear: () => store.clear(),
      key: (i: number) => [...store.keys()][i] ?? null,
      get length() {
        return store.size;
      },
    },
  });
}

// jsdom implements no matchMedia AT ALL — it is not a stub that
// returns false, the property is simply absent. Every page rendered
// in the admin shell hits it on the first render (AdminLayout →
// useIsMobile → useMediaQuery), so without this the layout throws
// "window.matchMedia is not a function" and the failure names the
// shell rather than the page under test.
//
// It answers "not matching", i.e. the DESKTOP branch, because that
// is the layout a test asserting on a sidebar expects. Override it
// in a single test to render the mobile drawer instead.
if (typeof window !== "undefined" && typeof window.matchMedia !== "function") {
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    writable: true,
    value: (query: string): MediaQueryList =>
      ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
        // Deprecated, but still what some libraries reach for first.
        addListener: () => {},
        removeListener: () => {},
      }) as unknown as MediaQueryList,
  });
}

// Also absent from jsdom, and reached on MOUNT rather than on
// render: the shell's SidebarNav observes its own container to
// decide how many nav items fit. A constructor that does not exist
// throws from inside a passive effect, so the stack names React's
// commit phase and not the component — which is a long way from
// "jsdom has no ResizeObserver".
//
// The stub never fires. A test that needs the overflow branch has
// to drive it explicitly; one that does not gets a stable layout
// instead of a resize it did not ask for.
if (typeof globalThis.ResizeObserver === "undefined") {
  Object.defineProperty(globalThis, "ResizeObserver", {
    configurable: true,
    writable: true,
    value: class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  });
}