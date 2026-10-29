// Public barrel for the Posts plugin's UI — reachable from any surface as
// `@posts` / `@posts/admin/lib/...` once `hkm ui sync` has federated it.
// The plugin exposes its admin API client + types here; its PAGES live under
// admin/Pages (globbed by the admin surface, not imported directly).
export * from "./admin/lib/types";
export * from "./admin/lib/api";
