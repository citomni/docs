# Admin URL Structure - CitOmni HTTP Runtime (v1.0)
*A deterministic routing convention for operator-facing administrative interfaces.*

---

**Document type:** Technical Architecture  
**Version:** 1.0  
**Applies to:** CitOmni ≥ 8.2  
**Audience:** Framework contributors, core developers, and integrators of admin UIs  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** © 2012-present CitOmni

---


## 1. Overview

The CitOmni admin routing model defines **a canonical, deterministic URL structure** for administrative HTML and JSON endpoints.  
It extends the principles of the [Routing Layer - CitOmni HTTP Runtime](routing-layer-http-runtime.md) with conventions tailored to back-office and operator-level interfaces.

Each admin route expresses:
- **Explicit content type** via suffix (`.html` for pages, `.json` for async data).  
- **Predictable semantics** for actions like `create`, `edit`, and `delete`.  
- **Consistent PRG workflow** and RoleGate enforcement.

These conventions ensure that every admin action remains **auditable, cache-friendly, and UX-safe** while preserving the framework's deterministic philosophy.


---

# Admin URL Structure (HTML & JSON)

> **Audience:** Framework contributors, core developers, and integrators of admin UIs  
> **Scope:** citomni/http (routing, controllers, templates), provider routes, admin UI  
> **Language level:** PHP ≥ 8.2

---

## 1. Introduction

This document defines **the canonical URL style for CitOmni admin screens**.  
Admin URLs return either **HTML** pages or **JSON** payloads, with **explicit suffixes** reflecting content type:

- `*.html` -> HTML pages (forms, lists, confirmations)  
- `*.json` -> JSON endpoints (XHR, grids, async actions)

The decision embraces CitOmni's **deterministic** and **no-magic** philosophy: Predictable inputs, predictable outputs, and minimal moving parts.
The approach aligns with our mode architecture and "last-wins" strategy (baseline -> providers -> app -> env) without introducing new abstractions.

---

## 2. Design Goals

1. **Determinism over trendiness**  
   URLs must be trivial to generate, parse, and log - no guessing based on `Accept` headers.

2. **Human-readable for editors**  
   Admin is a tool, not a public website. Action verbs like `edit`, `delete`, or `create` are acceptable and helpful.

3. **Zero-magic routing**  
   Avoid implicit verb routing, reflection, or namespace discovery. The router reads explicit arrays only - fast and cheap.

4. **Explicit content-type via suffix**  
   `.html` for human-facing pages, `.json` for programmatic endpoints. The suffix is part of the public contract.

5. **PRG-friendly**
   All mutating actions follow the **Post-Redirect-Get** pattern to avoid resubmission and improve UX.

6. **Provider-friendly**  
   Each provider contributes its own static route map, merged deterministically with app-level routes.

---

## 3. Chosen URL Pattern

### 3.1 Baseline pattern (recommended)

Action-based with query ID:

```

/admin/{entity}-{action}.html?id={id}

```

**Examples**

- List:  `/admin/page-list.html`
- Create: `/admin/page-create.html`
- Edit:   `/admin/page-edit.html?id=123`
- Delete: `/admin/page-delete.html?id=123`

**Rationale**

- Works for both **single** and **bulk** operations (`?ids=1,2,3`).
- Compatible with HTML forms (GET/POST only).
- Minimal router complexity: Requires no regex parsing or implicit mapping in the router.

> **Note:** Admin routes follow the same suffix contract as the general HTTP layer:
> `*.html` endpoints are **GET views** (read-only pages),
> and routes **without a suffix** are **POST actions** (mutating requests using PRG).
> This keeps admin routing fully aligned with CitOmni's deterministic HTTP semantics.

---

### 3.2 JSON twins (async/XHR)

For API-style access: Mirror the same actions with `.json`:

```

/admin/{entity}-list.json
/admin/{entity}-read.json?id=123
/admin/{entity}-update.json      (POST; body: id+fields)
/admin/{entity}-delete.json      (POST; body: id(s))

````

These endpoints are suitable for JavaScript tables, async deletes, or dashboards needing lightweight refreshes.

---

## 4. Semantics & Method Policy

| Content | GET                                             | POST (mutating)                                 |
|--------:|-------------------------------------------------|--------------------------------------------------|
| `.html` | Render list/form/confirm                        | Process create/update/delete, then **PRG**       |
| `.json` | Read/list (query params)                        | Write actions (body payload), CSRF as applicable |

- **Never** mutate on GET.  
- **Always** validate CSRF on POST.  
- Return **405** when a method is invalid; **404** when route not found.

---

## 5. Router Configuration (current implementation)

Since October 2025, CitOmni routes are defined **in dedicated route maps (not part of the config tree)** in dedicated **route maps**.  
Each layer contributes static arrays that the kernel merges deterministically.

### Merge order (HTTP mode)

Routing follows the deterministic *last-wins* model described in  
[Routing Layer - CitOmni HTTP Runtime (v1.0)](routing-layer-http-runtime.md).

| Priority | Source | Symbol |
|-----------|---------|--------|
| 1 | Vendor baseline | `\CitOmni\Http\Boot\Routes::MAP_HTTP` |
| 2 | Providers | `ROUTES_HTTP` |
| 3 | App base | `/config/citomni_http_routes.php` |
| 4 | Env overlay | `/config/citomni_http_routes.{ENV}.php` |

The merged result is compiled to `var/cache/routes.http.php` by `App::warmCache()`, alongside configuration (`cfg.http.php`) and services (`services.http.php`).

---

### 5.1 Example - app `/config/citomni_http_routes.php`

```php
<?php
declare(strict_types=1);

use Aserno\ByportalCore\Controller\Admin\PageController;
use Aserno\ByportalCore\Controller\Admin\Api\PageApiController;

return [
	// HTML
	'/admin/page-list.html' => [
		'controller'     => PageController::class,
		'action'         => 'adminPageList',
		'methods'        => ['GET'],
		'template_file'  => 'admin/admin_table.html',
		'template_layer' => 'citomni/admin',
	],
	'/admin/page-edit.html' => [
	  'controller'     => PageController::class,
	  'action'         => 'adminPageEditForm',  // expects ?id
	  'methods'        => ['GET'],
	  'template_file'  => 'admin/admin_page_edit.html',
	  'template_layer' => 'aserno/byportal-core',
	],
	'/admin/page-edit' => [
	  'controller' => PageController::class,
	  'action'     => 'adminPageEditPost',
	  'methods'    => ['POST'],
	],

	// JSON twins
	'/admin/page-list.json' => [
		'controller' => PageApiController::class,
		'action'     => 'adminPageListJson',
		'methods'    => ['GET'],
	],
	'/admin/page-delete.json' => [
		'controller' => PageApiController::class,
		'action'     => 'adminPageDeleteJson',
		'methods'    => ['POST'],
	],
];
````

### 5.2 Example - provider `Boot\Routes::MAP`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Admin\Boot;

final class Registry {
  public const ROUTES_HTTP = [
    '/admin/home.html' => [
      'controller'     => \CitOmni\Admin\Controller\DashboardController::class,
      'action'         => 'adminHome',
      'methods'        => ['GET'],
      'template_file'  => 'admin/admin_home.html',
      'template_layer' => 'citomni/admin',
    ],
  ];
}
```

> Note: Routes are merged by path key with last-wins semantics. Do not declare the same path key twice in a single map; combine GET/POST semantics via the suffix rule (.html for GET views, no suffix for POST actions) or move one entry to a downstream layer intended to override the former.

---

## 6. Controller Responsibilities

Admin controllers constitute the execution layer for the routes defined above.  
They are expected to follow a strict contract regarding authorization, validation, and side-effects.

**Responsibilities and behavioural guarantees:**

1. **Role enforcement**  
   Access control must be performed at entry using RoleGate:  
   ```php
   
		if (!$this->app->role->atLeast('operator')) {	
			$this->app->response->redirect('../login.html');
		}
   
	```

This ensures deterministic access validation, consistent with the CitOmni framework's RoleGate service and redirect semantics.

> **Note:** In admin controllers, `$this->app->userAccount` and `$this->app->role`
> are lazy-loaded singleton services. Avoid constructing new instances manually.


2. **CSRF validation**  
   All POST requests must verify a submitted token using the Security service:  
   ```php
   if (!empty($this->app->cfg->security->csrf_protection)) {
		$ok = $this->app->security->verifyCsrf(
			(string)($this->app->request->post($this->app->security->csrfFieldName()) ?? '')
		);

		if (!$ok) {
			$this->app->security->logFailedCsrf(__METHOD__, [
				'path' => $this->app->request->path(),
				'user' => $this->app->role->currentUserId(),
			]);
			http_response_code(403);
			return;
		}
   }
```

This must occur **before** any database mutation or file I/O.
In templates, CSRF fields are injected automatically via:

```CitOmniTemplateEngine
<form method="post" action="{{ $url('/login') }}">
	{{{ $csrfField() }}}
	<!-- additional fields -->
</form>
```

The TemplateEngine helper calls `$this->app->security->csrfToken()` internally, ensuring token issuance and reuse within the current session.

3. **Post-Redirect-Get (PRG)**
   All mutating actions (create, update, delete) must conclude with a redirect to a safe GET endpoint, accompanied by a flash message.

4. **Fail-fast validation**
   Controllers must validate IDs and input integrity at the earliest opportunity.
   Invalid or missing IDs must result in immediate termination with a clear diagnostic.

5. **Bulk operations**
   When handling multiple IDs (e.g., bulk delete), controllers must validate each element explicitly.
   Partial failures should result in partial feedback, not silent omission.

6. **Output responsibility**
   Controllers never emit raw output directly; rendering is delegated to TemplateEngine or JSON encoders.
   This separation preserves cacheability and consistent response semantics.

---

## 7. UX & Safety Considerations

The admin interface operates in a controlled environment, yet UX and safety remain primary concerns.

**Behavioural conventions:**

1. **Confirm before destruction**
   Destructive actions (delete, reset) must employ a two-phase interaction:

   * GET `*-delete.html?id=...` -> confirmation view.
   * POST `*-delete.html` -> irreversible execution.

2. **Bulk feedback**
   All multi-row operations must yield explicit user feedback indicating counts of success, skip, and error.
   Flash messages are the canonical vehicle for this communication.

3. **Canonical redirects**
   URLs *should* normalise to their .html form (via router policy or a lightweight canonicalization middleware).

   * `/admin/page-edit` -> 301 -> `/admin/page-edit.html`
   * `/admin/page-edit.html/` -> 301 -> `/admin/page-edit.html`

4. **Error hygiene**
   Controllers must respond with semantically correct status codes:

   * 403 for authorization failures.
   * 404 for missing resources.
   * 422 or structured flash feedback for validation errors.

5. **Predictable navigation**
   After any POST, the user must always end on a GET view (PRG).
   This guarantees safe page refreshes and bookmarkability.

---

## 8. Why Not "Pure REST" for Admin HTML?

REST - *Representational State Transfer* - is architecturally elegant for machine-to-machine APIs.
However, HTML-based admin interfaces operate under a different set of constraints and goals.

**Rationale for non-RESTful design:**

* Browsers natively support only **GET** and **POST**; forcing REST semantics would require artificial method overrides (`_method=PUT/DELETE`) that add complexity without functional benefit.
* Admin routes are **task-driven**, not resource-driven. Actions such as `edit`, `create`, or `delete` directly describe operator intent.
* Explicit action names in URLs improve auditability, transparency, and traceability in logs.
* REST is preserved where it *matters*: JSON twins (`*.json`) retain REST-like behaviour for programmatic consumers.

In short, CitOmni's admin URL model optimises for **clarity, operator efficiency, UX, and deterministic execution**, not theoretical purity.

---

## 9. Integration with CitOmni Kernel & Mode System

Routing integration follows the deterministic layer model described in [routing-layer-http-runtime.md](routing-layer-http-runtime.md).  
CitOmni's kernel merges baseline -> providers -> app -> env once per boot and caches the result to `/var/cache/routes.http.php`.
No reflection or dynamic lookup occurs at runtime.

**Outcome:** Predictable boot order, deterministic overrides per environment, and zero runtime discovery overhead.

---

## 10. Versioning & Migration

CitOmni's routing model is intentionally additive. New routes can coexist with existing ones without disruption.

**Policies:**

* Existing URLs remain valid indefinitely.
* Entity renames (`page` -> `article`) should ship with temporary compatibility routes and explicit deprecation logs.
* Cached route maps are regenerated automatically via `App::warmCache()`.

This approach preserves backward compatibility while allowing incremental evolution of the route namespace.

---

## 11. Security Posture

CitOmni treats the admin interface as a privileged execution surface. The following invariants are mandatory:

1. **Immutability of GET**
   No GET route may alter state. Any state change must occur via POST with CSRF validation.

2. **CSRF protection**
   Every POST request must carry a valid CSRF token pair (`name` + `value`).
   Tokens derive from the security service and are bound to session and origin.

3. **RoleGate enforcement**
   All admin controllers must call RoleGate early.
   Access is denied by default unless an explicit minimum role is defined.

4. **Auditable mutations**
   Mutating actions must log the following context: Timestamp, user ID, IP address, and user agent.
   Logs are written in structured JSON format to `var/logs/[filename].jsonl`.

5. **Input discipline**
   All IDs are cast to integer and validated for existence and ownership.
   No controller may trust raw request input without explicit sanitisation.

---

## 12. Performance Notes

The route subsystem is designed for **predictability and constant-time dispatch**.

* Static PHP arrays guarantee O(1) lookups with negligible overhead.
* Optional regex routes are explicitly declared and limited in scope.
* Pre-compiled cache files (`var/cache/routes.http.php`) eliminate the need to run the entire merge-logic on every request.
* No annotations, no reflection, and no filesystem scanning occur during runtime.
* The deterministic structure aligns perfectly with `opcache.validate_timestamps=0` deployments for maximal throughput.

---

## 13. Summary

| Aspect               | Decision / Policy                                        |
| -------------------- | -------------------------------------------------------- |
| Content suffix       | `.html` for pages, `.json` for async endpoints           |
| URL style            | Action-based: `/admin/{entity}-{action}.html`            |
| ID passing           | Query (`?id=123`) baseline, optional hyphen-ID routes    |
| Methods              | GET = read/confirm, POST = mutate (PRG enforced)         |
| Security             | CSRF + RoleGate, no state change on GET                  |
| Router complexity    | Low; static arrays + curated regex                       |
| Provider integration | Deterministic merge: baseline -> providers -> app -> env    |
| Migration policy     | Additive, backward-compatible, explicit deprecation logs |

**In essence:**

> CitOmni's admin routing model prioritises **determinism, safety, and developer ergonomics** over architectural purism.
> Action-based naming, explicit suffixes, and static route maps ensure transparent, cache-friendly, and maintainable execution across all environments.
