## Symfony Docs Mate Extension

Prefer these capabilities over raw shell access when the user needs Symfony
documentation from either the extension's managed docs snapshot or an explicit
local `symfony-docs/` checkout.

| User intent | Prefer |
|---|---|
| Discover where a topic lives in the docs | `symfony-docs-catalog` |
| Search for a page, section, or label | `symfony-docs-search` |
| Inspect page structure before reading | `symfony-docs-page` |
| Read only the relevant excerpt | `symfony-docs-section` |
| Get reusable top-level discovery context | `symfony-docs://catalog` |

### Guidance

- Start with `symfony-docs-search` for targeted lookups.
- Use `symfony-docs-page` before `symfony-docs-section` when the exact section
  is unclear.
- Prefer bounded section reads over large page dumps.
- If an explicit docs checkout is not configured, the extension can manage a
  cached snapshot automatically.
- Hosts can prefetch that snapshot with `vendor/bin/symfony-docs-mate-sync`.
- Treat tool output as structured encoded text rather than assuming one exact
  wire format.
