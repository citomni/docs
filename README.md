# CitOmni Documentation (Work in Progress)

This repository hosts the public documentation for the CitOmni Framework.

> **Heads up:** The documentation is a work in progress. If you're missing anything specific, please reach out at **support@citomni.com**.

## Available docs (auto-generated)

### concepts
- [concepts/README.md](./concepts/README.md)
- [concepts/admin-url-structure-http.md](./concepts/admin-url-structure-http.md)
- [concepts/app-structure.md](./concepts/app-structure.md)
- [concepts/maintenance-mode.md](./concepts/maintenance-mode.md)
- [concepts/maintenance-mode_dk.md](./concepts/maintenance-mode_dk.md)
- [concepts/routing-layer-http-runtime.md](./concepts/routing-layer-http-runtime.md)
- [concepts/runtime-modes.md](./concepts/runtime-modes.md)
- [concepts/services-and-providers.md](./concepts/services-and-providers.md)

### contribute
- [contribute/README.md](./contribute/README.md)
- [contribute/CONVENTIONS.md](./contribute/CONVENTIONS.md)
- [contribute/testing-guide.md](./contribute/testing-guide.md)

### cookbook
- [cookbook/README.md](./cookbook/README.md)

### get-started
- [get-started/README.md](./get-started/README.md)

### how-to
- [how-to/README.md](./how-to/README.md)
- [how-to/providers-build-a-provider.md](./how-to/providers-build-a-provider.md)
- [how-to/services-authoring-registration-usage.md](./how-to/services-authoring-registration-usage.md)
- [how-to/vars_providers-auto-injecting-template-variables.md](./how-to/vars_providers-auto-injecting-template-variables.md)

### legal
- [legal/README.md](./legal/README.md)

### packages
- [packages/README.md](./packages/README.md)

### policies
- [policies/README.md](./policies/README.md)
- [policies/public-uploads.md](./policies/public-uploads.md)

### programs
- [programs/README.md](./programs/README.md)

### reference
- [reference/README.md](./reference/README.md)
- [reference/flash-service.md](./reference/flash-service.md)

### release-notes
- [release-notes/README.md](./release-notes/README.md)

### reports
- [reports/README.md](./reports/README.md)
- [reports/2025-10-02-capacity-and-green-by-design.md](./reports/2025-10-02-capacity-and-green-by-design.md)

### troubleshooting
- [troubleshooting/README.md](./troubleshooting/README.md)
- [troubleshooting/providers-autoload.md](./troubleshooting/providers-autoload.md)
- [troubleshooting/providers-autoload_dk.md](./troubleshooting/providers-autoload_dk.md)

## Expected structure (reference)

Below is the planned layout. Some sections may be empty while we migrate content.

```
/README.md
/get-started/
  quickstart-http.md
  quickstart-cli.md
  skeletons.md
/concepts/
  runtime-modes.md
  config-merge.md          # deterministic "last-wins"
  services-and-providers.md
  caching-and-warmup.md
  error-handling.md
/how-to/
  build-a-provider.md
  routes-and-controllers.md
  csrf-cookies-sessions.md
  perf-budgets-and-metrics.md  # p95 TTFB, RSS, req/watt-notes
  deployment-one.com.md
/troubleshooting/
  providers-autoload.md
  routes-404.md
  csrf-token-mismatch.md
  caching-warmup.md
  composer-autoload.md
  http-500-during-boot.md
/policies/
  security.md
  public-uploads.md
  cache-and-retention.md
  backups.md
  data-protection.md
  error-pages.md
  maintenance.md
/reference/
  config-keys.md           # complete key list with examples
  cli-commands.md
  http-objects.md          # Request/Response/Cookie
  router-reference.md
/packages/
  kernel.md
  http.md
  cli.md
  auth.md
  testing.md
/cookbook/
  cms-starter.md
  commerce-notes.md
  admin-starter.md
/contribute/
  conventions.md           # coding/documentation conventions
  docs-style-guide.md      # tone, examples, code blocks
  issue-templates.md
/legal/
  licenses.md              # MIT/GPL explanations, SPDX
  trademark.md             # TM usage, "official", "certified"
  notice-template.md
/programs/
  certification.md         # criteria, green KPIs
  partners.md              # partner program guidelines
/release-notes/
  index.md                 # links to package CHANGELOGs
/assets/
  img/ css/ js/
```

---
_This README was generated automatically._  
_Last updated: 2025-11-03 19:19:42_
