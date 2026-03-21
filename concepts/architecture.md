# CitOmni Architecture

## Abstract
CitOmni is a PHP framework designed under a strict constraint set: Deterministic execution, ultra-low overhead, and consistently high performance. This document specifies the architectural contract for CitOmni-based applications and provider packages. The focus is not on maximal abstraction, but on minimal, explicit structure that yields predictable behavior, clear ownership of side effects, and simple mechanical sympathy with PHP runtimes.

The architecture is intentionally conservative. It prioritizes constraints over cleverness, explicit wiring over discovery, and stable mental models over fashionable layering. If a design choice is not measurably useful, it is considered technical debt in advance.

## Design Goals
CitOmni optimizes for the following properties, in this order.

1. Determinism
	- The same inputs should produce the same outputs.
	- Wiring is explicit, stable, and reviewable.
	- Configuration and service maps are merged in a deterministic order.

2. Low Overhead
	- Few runtime hops, few layers, and minimal indirection.
	- No namespace scanning, no reflection-driven dependency discovery, no implicit magic.

3. High Performance
	- Predictable data shapes (arrays with known keys and types).
	- Minimal allocations, minimal IO, and careful use of caching.
	- Fast failure with clear error surfaces.

4. Maintainable Separation Of Concerns
	- Transport concerns are isolated.
	- Persistence concerns have a hard boundary.
	- Side effects have explicit ownership and controlled entry points.

## Non-Goals
CitOmni explicitly does not aim to provide.

- A full-blown ORM abstraction layer.
- A dogmatic, purity-driven domain model that requires extensive dependency injection ceremony.
- Framework-level code generation or scaffolding as a core dependency.
- Implicit autowiring based on class names, directories, or annotations.

If a project requires these characteristics, it may still be built on CitOmni, but CitOmni will not enforce them nor pay their runtime costs by default.

## Conceptual Model
CitOmni structures the codebase into six primary categories, each with a strict contract.

- Adapters
	- Transport-specific entry points for HTTP and CLI.
	- They translate external inputs into internal data shapes, and internal results into transport outputs.

- Operation
	- Transport-agnostic orchestration and application-level decision logic.
	- Operations are SQL-free and do not shape transport output.
	- Operations may call services and repositories via the App, hence Operations are not academically side-effect-free.

- Repository
	- Persistence boundary, owning all SQL and datastore IO.
	- Repositories receive the App in order to share DB access and read relevant configuration without extra wiring.
	- Repository responsibility remains persistence, not general infrastructure orchestration.

- Service
	- Reusable, App-aware tools registered in the service map.
	- Services may perform side effects through explicitly defined infrastructure roles.

- Util
	- Pure functions with no state and no IO.
	- These are the only components that can be tested in complete isolation without stubs or environment setup.

- Exceptions
	- Domain and application exceptions.
	- Transport layers may translate exceptions into HTTP responses or CLI exit codes.

A practical reading is.

- Adapters speak protocols.
- Operations decide what happens.
- Repositories talk to storage.
- Services provide tools.
- Utils compute.
- Exceptions encode failure semantics.

## Directory Layout
The canonical directory structure for application and provider package code is.

- `src/Controller/`
	- HTTP adapters.
	- Route-specific logic, request parsing, session handling, CSRF validation, response shaping.

- `src/Command/`
	- CLI adapters.
	- Argument parsing, output formatting, exit codes.

- `src/Operation/`
	- The primary transport-agnostic orchestration layer.
	- Operations coordinate business actions and decision flow.
	- SQL-free and transport-agnostic.
	- Returns domain-shaped arrays rather than Response objects.
	- Operation classes extend `BaseOperation` and are instantiated explicitly by adapters (Controllers/Commands) using `new ...($this->app)`.

- `src/Repository/`
	- Persistence boundary.
	- All SQL resides here.
	- Repositories extend `BaseRepository` and receive `App`.
	- `App` is used to share DB access and read relevant configuration with minimal wiring overhead.

- `src/Service/`
	- App-local services.
	- Every class here extends `BaseService` and is registered in the service map.
	- Accessed as `$this->app->{serviceId}`.
	- Registration location depends on context:
		- Mode packages (`citomni/http`, `citomni/cli`): `<package-root>/src/Boot/Registry.php::MAP_HTTP` / `MAP_CLI`
		- Provider packages: `<package-root>/src/Boot/Registry.php::MAP_HTTP` / `MAP_CLI`
		- Application-local services: `<app-root>/config/services.php`

- `src/Util/`
	- Pure helpers.
	- Input to output, no App, no config, no logging, no caching, no IO.

- `src/Exception/`
	- Domain and application exceptions.

Additionally.

- `templates/`
	- TemplateEngine templates for HTML.
	- Common partitioning includes `public/`, `member/`, and `admin/`.

- `language/`
	- Language files used by the text service.

The legacy "Model layer" is split into two explicit concerns.

- Operation absorbs orchestration and application-level decision logic.
- Repository absorbs persistence and SQL.

### Complete Layout: Application Layer

/app-root									// Project root
	/src									// Application code (PSR-4: App\ -> src/)
		/Http								// HTTP delivery layer (web-only concerns)
			/Controller						// Thin controllers delegating to Services/Operations (no heavy business logic here)
			/Exception						// HTTP-specific exceptions (status mapping, user-facing error handling)
			// Optional as app grows:
			// /Middleware					// Request/response middleware (auth, rate-limit, etc.)
			// /Request						// Input DTOs/validators for HTTP endpoints
			// /Responder					// View models/emitters (HTML/JSON), formatting only

		/Cli								// CLI delivery layer (terminal-only concerns)
			/Command						// Thin commands delegating to Services/Operations
			/IO								// Helpers for prompts, tables, progress, etc.
			/Schedule						// Cron/recurring job descriptors (what runs, when, and with what args)
			/Exception						// CLI-specific exceptions (exit code mapping, CLI-friendly messages)

		/Service							// Application/domain services (shared tools registered in the service map)
											// Reusable, App-aware infrastructure and cross-cutting capabilities.

		/Repository							// Persistence layer (DB access, queries, mappers)
											// Everything that knows SQL/schema/resultsets; no HTTP/CLI concerns.

		/Operation							// Transport-agnostic orchestration layer
											// Explicitly instantiated units coordinating business actions and state transitions.

		/Exception							// Transport-agnostic app/domain exceptions
											// Shared across HTTP & CLI; thrown by Service/Repository/Operation as appropriate.


	/config									// Base config + env overlays + wiring points (providers/services/routes)
		/providers.php						// List of provider registries (Boot\Registry::class) loaded by the app
		/services.php						// App-local services registered in the service-map (HTTP/CLI as applicable)

		/citomni_http_cfg.php				// HTTP base config (non-env specific baseline)
		/citomni_http_cfg.dev.php			// HTTP dev overlay (last-wins merge)
		/citomni_http_cfg.stage.php		// HTTP stage overlay (last-wins merge)
		/citomni_http_cfg.prod.php			// HTTP prod overlay (last-wins merge)

		/citomni_http_routes.php			// HTTP base routes (non-env specific baseline)
		/citomni_http_routes.dev.php		// HTTP dev routes overlay (last-wins merge)
		/citomni_http_routes.stage.php		// HTTP stage routes overlay (last-wins merge)
		/citomni_http_routes.prod.php		// HTTP prod routes overlay (last-wins merge)

		/citomni_cli_cfg.php				// CLI base config
		/citomni_cli_cfg.dev.php			// CLI dev overlay (last-wins merge)
		/citomni_cli_cfg.stage.php			// CLI stage overlay (last-wins merge)
		/citomni_cli_cfg.prod.php			// CLI prod overlay (last-wins merge)

		/citomni_cli_routes.php				// CLI base routes (non-env specific baseline)
		/citomni_cli_routes.dev.php			// CLI dev routes overlay (last-wins merge)
		/citomni_cli_routes.stage.php		// CLI stage routes overlay (last-wins merge)
		/citomni_cli_routes.prod.php		// CLI prod routes overlay (last-wins merge)

		/README.md							// Config notes: precedence, patterns, and pitfalls (human documentation)
		/.htaccess							// Defense-in-depth: deny web access if misconfigured hosting exposes /config
		/tpl								// Templates used to generate deployment files (htaccess, index.php, robots, secrets, etc.)
			/*.tpl							// Environment-specific templates used during setup/deploy (not runtime)


	/language								// Translations (kept outside webroot)
		/en									// English strings (usually the "source language")
		/da									// Danish strings
		/.htaccess							// Defense-in-depth: deny web access if language folder becomes web-accessible


	/templates								// Templates/layouts/partials (no secrets here)
		/public								// Public pages/templates (landing pages, maintenance, hello world, etc.)
		/member								// Optional: member section templates (login-only area, account pages, etc.)
		/admin								// Optional: admin templates (backoffice, internal tools, moderation, etc.)
		/.htaccess							// Defense-in-depth: deny web access if templates folder becomes web-accessible


	/public									// Webroot (only public assets + front controller)
		/index.php							// HTTP front controller (bootstrap for web; keep minimal)
		/.htaccess							// Rewrite rules, caching headers, security headers (Apache deployments)
		/assets								// Built/static assets (no source files; safe to serve directly)
		/uploads								// Publicly served uploads (strictly whitelisted types)
			/.htaccess						// Defense-in-depth: deny script execution in uploads
			/u								// Per-user tokenized public roots (avatars/media), intentionally web-accessible
											// Only for non-confidential files. Private docs must go under /var or another private folder.

		/site.webmanifest.tpl				// Template for web manifest (usually deployed/compiled to site.webmanifest)


	/bin									// CLI entry point(s)
											// Typically contains console launcher(s); same boot principles as HTTP.


	/var									// Runtime writeable path (prod: only this is writeable)
		/backups							// App-level backup artifacts (db dumps, exported payloads, etc.)
			/flags							// Backed-up flag state (optional; depends on app needs)
		/cache								// Safe-to-purge caches (compiled config/routes/services, templates, etc.)
		/flags								// Maintenance/feature flags (file-based toggles, e.g. maintenance.php)
		/logs								// Application logs (rotate externally or in-app; keep machine-readable when possible)
		/nonces								// Short-lived nonce store (if file-backed; can be purged safely)
		/secrets							// Secrets (never webroot, never committed; env/deploy provides real files)
		/state								// PID/health/lock files, transient state (safe to purge when not running)
		/.htaccess							// Defense-in-depth: deny web access if /var becomes reachable via misconfig


	/tests									// Tests (unit/integration/e2e as you grow)
		/.htaccess							// Defense-in-depth on shared hosting


	/vendor									// Composer-managed dependencies (do not edit)
		/citomni								// CitOmni packages installed via Composer
			/http							// CitOmni HTTP (delivery+infra for web)
			/cli							// CitOmni CLI (delivery+infra for terminal)


	composer.json							// Dependencies & autoload (PSR-4: App\ -> src/)
	.gitignore								// VCS ignore rules (cache, secrets, local artifacts, etc.)
	LICENSE									// Project license
	NOTICE									// Third-party notices (if applicable)
	TRADEMARKS.md							// Trademark policy/notice
	README.md								// Project readme (how to run, deploy, conventions)
	.htaccess								// Root fallback for shared hosting (rewrite to /public)


### Complete Layout: Mode Packages

/citomni-mode-package-root						// Package root (Composer package) for citomni/http or citomni/cli
	/assets										// Package-owned static assets (optional)
		// (Optional: /css, /js, /images, /fonts, etc. if the package ships public assets)

	/composer.json								// Dependencies + autoload (PSR-4: CitOmni\Http\ or CitOmni\Cli\ -> src/)
	/CONVENTIONS.md								// Package-specific conventions (how it boots, extension points, routing/service rules)
	/LICENSE									// Package license
	/NOTICE										// Third-party notices (if applicable)
	/TRADEMARKS.md								// Trademark policy/notice (if relevant)
	/README.md									// Quickstart: install, wire into app, minimal usage

	/language									// Translations shipped by the package (optional)
		/da										// Danish strings
		/en										// English strings (often the source language)

	/sql										// SQL artifacts shipped by the package (optional)
		/install.sql								// Installation SQL (if the package needs DB tables)

	/src										// Package code (PSR-4 root)
		/Boot									// Boot metadata for mode packages
			/Registry.php						// Mode registry (MAP_HTTP/MAP_CLI/CFG_HTTP/CFG_CLI/ROUTES_HTTP/ROUTES_CLI)
												// Read directly by App::buildConfig(), App::buildRoutes(), and App::buildServices().

		/Kernel.php								// Central runtime kernel for the mode (boot + run loop)
												// HTTP: Handles request lifecycle, routing, controller dispatch, error handling, response emit
												// CLI: Handles argv parsing, command dispatch, exit codes, error handling

		/Controller								// (HTTP) Controllers shipped by the package (framework-level controllers if any)
		/Command									// (CLI) Commands shipped by the package (framework-level commands if any)

		/Operation								// Internal transport-agnostic orchestration units
												// Keep deterministic, SQL-free, and low-overhead.

		/Repository								// Persistence layer (optional; only if the mode package touches DB)
		/Service									// Services used by the mode package internally (routing, templating, csrf, etc.)
		/Util									// Small stateless helpers (pure functions / tiny utilities)
		/Exception								// Package exceptions (typically transport-aware for the mode)

	/templates									// Templates/layouts shipped by the mode package (optional)
		/public									// Public templates (HTTP-only, e.g. maintenance, error pages)
		/member									// Optional: member templates (if the mode package ships any shared member UI)
		/admin									// Optional: admin templates (if the mode package ships any shared admin UI)

	/tests										// Package tests (optional)

### Complete Layout: Provider Packages

/provider-package-root							// Package root (Composer package)
	/assets										// Package-owned static assets (optional)
		// (Optional: /css, /js, /images, /fonts, etc. if the package ships public assets)

	/composer.json								// Dependencies + autoload (PSR-4: Vendor\Package\ -> src/)
	/CONVENTIONS.md								// Package-specific conventions (how to extend/configure/use it)
	/LICENSE									// Package license
	/NOTICE										// Third-party notices (if applicable)
	/TRADEMARKS.md								// Trademark policy/notice (if relevant)
	/README.md									// Quickstart: install, enable provider, minimal usage

	/language									// Translations shipped by the package (optional)
		/da										// Danish strings
		/en										// English strings (often the source language)

	/sql										// SQL artifacts shipped by the package (optional)
		/install.sql								// Installation SQL (tables/indexes/seed, if the package needs it)
		// (Optional: /migrations, /uninstall.sql, /seed.sql, etc. if you standardize it later)

	/src										// Package code (PSR-4 root)
		/Boot									// Boot metadata for CitOmni provider loading
			/Registry.php							// Provider registry (MAP_HTTP/MAP_CLI/CFG_HTTP/CFG_CLI/ROUTES_HTTP/ROUTES_CLI)

		/Controller								// Optional: HTTP controllers shipped by the package
												// Only used if the package provides routes/controllers directly.
		/Command									// Optional: CLI commands shipped by the package
												// Only used if the package exposes CLI commands.

		/Operation								// Package transport-agnostic orchestration layer
												// Explicitly instantiated units coordinating business actions.

		/Repository								// Persistence layer (DB access, queries, mappers)
												// Everything that knows SQL/schema/resultsets; no HTTP/CLI concerns.

		/Service									// Reusable App-aware services registered in the service map
												// Infrastructure and cross-cutting capabilities.

		/Util									// Small stateless helpers (pure functions / tiny utilities)
												// Keep focused. If it grows state/IO, consider Operation or Service.

		/Exception								// Transport-agnostic package exceptions
												// Thrown by Service/Repository/Operation; mapped by delivery layer if needed.

	/templates									// Templates/layouts/partials shipped by the package (optional)
		/public									// Public templates (marketing pages, public endpoints)
		/member									// Optional: member templates (authenticated area)
		/admin									// Optional: admin templates (backoffice/internal tools)

	/tests										// Package tests (optional)


## Instantiation And Base Class Contracts
CitOmni uses explicit, deterministic wiring. Each category has a simple, reviewable instantiation contract.

- Controllers (`src/Controller/`)
	- Mode/Provider packages: `src/Controller/`
	- Application layer: `src/Http/Controller/`
	- Extend `BaseController`.
	- Instantiated by the router via the route map (controller FQCN + method/action).

- Commands (`src/Command/`)
	- Mode/Provider packages: `src/Command/`
	- Application layer: `src/Cli/Command/`
	- Extend `BaseCommand` (or the package-specific command base class).
	- Instantiated by the CLI runner/command dispatcher.

- Operations (`src/Operation/`)
	- Extend `BaseOperation`.
	- Instantiated explicitly by adapters using `new ...($this->app)`.

- Repositories (`src/Repository/`)
	- Extend `BaseRepository`.
	- Receive `App` in the constructor.
	- Use `App` for shared DB access and persistence-related configuration.

- Services (`src/Service/`)
	- Extend `BaseService`.
	- Must be registered in the service map.
	- Accessed via `$this->app->{serviceId}` (singleton per request/process).

- Utils (`src/Util/`)
	- No base class.
	- Pure functions only (no App, no IO).

## Adapters
### Responsibility
Adapters are transport-specific. Their responsibilities are precisely the things that cannot be reused across protocols.

For HTTP controllers.

- Parse and validate HTTP input.
- Perform session and CSRF checks.
- Decide whether the output is HTML or JSON.
- Translate domain results into HTTP responses.

For CLI commands.

- Parse CLI arguments.
- Format output for stdout or stderr.
- Choose exit codes.
- Translate domain results into CLI output.

### Prohibited Behavior
Adapters must not.

- Embed SQL or access the DB directly.
- Implement domain rules beyond immediate input normalization.
- Encode multi-step business workflows that are likely to appear in multiple adapters.

### Typical Shape
The canonical adapter flow is.

1. Read transport input.
2. Normalize into a known array shape.
3. Call Repository directly for trivial read or write.
4. Call Operation for non-trivial orchestration.
5. Translate results to output.

The contract is not "dumb adapters" as an insult. It is "pure adapters" as a boundary.

## Operation
### Responsibility
Operation contains the primary transport-agnostic orchestration logic for a package or application.

- Application-level decision flow.
- State transition logic.
- Validations that are not purely transport-specific.
- Orchestration across repositories and services.

Operation must not depend on any transport concepts.

- No HTTP request objects.
- No response shaping.
- No template rendering.
- No CLI output formatting.

Operation must not execute SQL directly.

- No DB adapter in Operation.
- No query strings in Operation.

### Side Effects In Operation
Operation is SQL-free, not side-effect-free.

Operation receives the App in order to access services and to avoid dependency injection ceremony that would contradict CitOmni's performance and simplicity objectives.

Therefore.

- Operation may call `$this->app->log`, `$this->app->txt`, `$this->app->mailer`, etc.
- Operation may call repositories, and repositories also receive `App` as part of CitOmni's explicit wiring model.

This is a deliberate design choice.

- CitOmni prefers low overhead and predictable wiring over dependency injection ceremony.
- The important boundary is responsibility, not artificial constructor purity.

Operation owns orchestration and application-level decision logic. 
Repository owns persistence and SQL.

### Output Contract
Operation returns domain-shaped arrays.

- It does not return HTTP Response objects.
- It does not return CLI exit codes.
- It does not return TemplateEngine artifacts.

Adapters translate domain-shaped arrays into transport-specific outputs.

This rule prevents accidental coupling between application logic and transport.

### Instantiation And Wiring
Operation is intentionally not a service-map category.

- Controllers and Commands instantiate Operations explicitly and deterministically.
- This avoids container complexity and keeps the call graph obvious in code reviews.

Contract:
- Every Operation class extends `BaseOperation`.
- The constructor signature is: `__construct(App $app)`.
- An Operation instance is created in the adapter using `new`, passing the current App.

Typical usage:
- In a Controller: `$operation = new \Vendor\Package\Operation\FinalizePayment($this->app);`
- In a Command: `$operation = new \Vendor\Package\Operation\FinalizePayment($this->app);`

Notes:
- Operation must not be stored as a long-lived singleton across requests.
- Operation objects are cheap and may be instantiated per call-site.

### When To Introduce An Operation Class
Operation should not be created by default. An Operation class exists only when there is a clear benefit relative to Controller to Repository calls.

Create an Operation class when at least one of the following holds.

- The same logic must be used by both Controller and Command.
- The same operation must be callable from multiple routes or multiple commands.
- The operation performs state change with meaningful rules and transitions.
- The operation includes multiple side effects, such as write plus log plus cache invalidation plus mail.
- The operation requires orchestration across multiple repositories.

If none of these holds, Controller to Repository is sufficient, and an Operation class is likely overengineering.

### Recommended Naming
To keep Operation from degenerating into an unstructured "miscellaneous" container, names must carry semantic load.

Good patterns.

- Verb-centric action classes.
	- `AuthenticateUser`
	- `FinalizePayment`
	- `UpgradeSubscription`
	- `PublishArticle`

Avoid names that express no domain meaning.

- `Manager`
- `Helper`
- `Common`
- `Utils`
- `Handler` as a generic bucket

Avoid suffix duplication inside `src/Operation/`.

Prefer:
- `AuthenticateUser`
- `ResetPassword`

Over:
- `AuthenticateUserOperation`
- `ResetPasswordOperation`

A class name that cannot be stated clearly as a concrete action is often a sign that the responsibility is not yet understood.

## Repository
### Responsibility
Repositories own persistence.

- All SQL is located here.
- All datastore IO is located here.
- Repositories do not shape transport output.
- Repositories do not contain business workflows.

Repositories return predictable array shapes.

- Arrays are preferred over magic objects.
- Shapes should be stable and documented.
- If a repository returns mixed shapes depending on conditions, the contract should be explicit.

### Dependencies
Repositories receive the App.

This keeps repository wiring explicit, deterministic, and low-overhead.

By receiving `App`, repositories gain:

- Shared access to the same DB connection without establishing it more than once per request/process.
- Access to relevant configuration without secondary injection plumbing.
- A constructor model aligned with the rest of CitOmni's base-class structure.

This does **not** make Repository a general-purpose service layer.

The rule is:

- Repository may use `App` for persistence-related needs such as DB access and relevant configuration.
- Repository must not take on transport logic, workflow orchestration, or unrelated infrastructure concerns.

The boundary is defined by responsibility, not by withholding `App`.

### App Access Discipline
Repository access to `App` is mechanical, not architectural sprawl.

Allowed in Repository:
- DB access.
- Persistence-related config reads.
- Small storage-adjacent helpers directly tied to persistence behavior.

Not appropriate in Repository:
- Transport shaping.
- Mail dispatch.
- User-facing text composition.
- General orchestration.
- Cross-cutting workflow logic better placed in Operation or Service.

### BaseRepository
Repositories extend `BaseRepository`, which standardizes DB access patterns while remaining minimal.

A BaseRepository is not a full abstraction. It is a thin structural anchor to ensure consistency.

The purpose is not to hide SQL. The purpose is to localize it.

## Service
### Responsibility
Services are reusable, App-aware tools.

- They extend `BaseService`.
- They are registered in the service map.
- They are accessed via `$this->app->{serviceId}`.

Services may perform side effects when they play an infrastructure role.

Typical examples.

- Logging.
- Mailing.
- Cache interactions.
- Text lookup for i18n.
- Formatting and parsing utilities that require config.

### Hard Rule For Service Directory
All classes in `src/Service/` are service-map services.

If a class.

- Does not extend `BaseService`.
- Is not registered in the service map.

Then it must not be placed in `src/Service/`.

This prevents a semantic split between "real services" and "service-like classes," which is a reliable source of long-term confusion.

### Service Map Registration
CitOmni services are instantiated via explicit service maps. Registration is deterministic and does not rely on scanning.

Registration locations:

- Mode packages:
	- `citomni/http` and `citomni/cli` register their core services in `<package-root>/src/Boot/Services.php::MAP`.

- Provider packages:
	- Register services in `<package-root>/src/Boot/Registry.php::MAP_HTTP` (HTTP mode) and optionally `MAP_CLI` (CLI mode).
	- Provider registries are listed in `<app-root>/config/providers.php`.

- Application-local services:
	- Register in `<app-root>/config/services.php`.

Contract:
- A service is considered "real" only if it is both:
	1) Implemented as a `BaseService` subclass, and
	2) Registered in the relevant service map for the active mode.

## Util
### Responsibility
Utils are pure functions.

- No App.
- No config reads.
- No text lookups.
- No logging.
- No caching.
- No filesystem.
- No network.
- No SQL.

They are the closest thing CitOmni has to "mathematical" code.

This category is the simplest to test and the easiest to reason about. It should remain strict.

## Exceptions
Exceptions encode failure semantics with explicit intent.

- Domain and application exceptions live in `src/Exception/`.
- Transport layers may wrap or translate exceptions into HTTP responses or CLI exit codes.

CitOmni prefers fail-fast behavior.

- Avoid catch-all exception handling in Operation and Repository.
- Catch only when there is a recoverable strategy with a documented fallback.

A well-named exception is a control surface, not decoration.

## Data Shapes And Contracts
CitOmni prefers arrays with known shapes over dynamic objects.

Rationale.

- Arrays are fast in PHP when used predictably.
- Shapes can be kept stable without magic getters or lazy-loading behavior.
- Reviewers can understand the contract without reverse-engineering object state.

Recommendations.

- Use associative arrays with documented keys.
- Avoid "sometimes present" keys without clear rules.
- Normalize types early, typically in adapters or in Operation validation.
- Keep repository return shapes consistent and minimal.

For complex structures, prefer documented shapes rather than deep nested ad hoc structures.

A shape that cannot be described clearly is often a sign that the responsibility boundary is unclear.

## Deterministic Wiring
CitOmni wiring is explicit.

- Providers are listed in `config/providers.php`.
- Providers contribute service maps, config overlays, and routes in a deterministic "last wins" merge.
- Services are accessed via `$this->app->{id}` and instantiated from the service map.

Service map sources depend on context:
- Mode packages (`citomni/http`, `citomni/cli`): `<package-root>/src/Boot/Registry.php::MAP_HTTP` / `MAP_CLI`
- Provider packages: `<package-root>/src/Boot/Registry.php::MAP_HTTP` / `MAP_CLI`
- Application-local services: `<app-root>/config/services.php`

Determinism is a requirement, not a preference.

- No scanning of namespaces to discover services.
- No implicit registration based on directory names.
- No magical conventions that change meaning when a file is moved.

Predictability is cheaper than debugging.

## App Helper Methods

The `App` exposes a few low-overhead helper methods for capability checks.

- `$app->hasService('id')`
	- Returns true if the service id exists in the resolved service map.

- `$app->hasAnyService('a', 'b')`
	- Returns true if any of the given service ids exist.

- `$app->hasPackage('vendor/package')`
	- Returns true if the resolved service map or route map references classes from that package.

- `$app->hasNamespace('\Vendor\Package\')`
	- Returns true if the resolved service map or route map references classes under that namespace prefix.

## Caching Strategy
CitOmni uses explicit caches when they yield measurable benefits.

- Configuration cache.
- Route cache.
- Service map cache.

Caches are precompiled into PHP files and can be warmed atomically.

The caching contract should remain explicit.

- No hidden caches.
- No caches whose invalidation depends on a guessed environment.

A cache is a performance tool, not a correctness mechanism.

## Practical Examples

### Example One
Trivial endpoint, no Operation.

- Controller parses input.
- Controller calls Repository.
- Controller renders template or returns JSON.

This is valid and preferred when the logic is route-specific and does not justify an additional orchestration unit.

### Example Two
Non-trivial action, Operation introduced.

- Controller parses input.
- Controller calls Operation.
- Operation applies rules, calls Repository, triggers infrastructure services.
- Operation returns domain-shaped array.
- Controller translates domain result to transport output.

The cost of Operation is justified by reuse, orchestration, or rule complexity.

### Example Three
Shared logic across HTTP and CLI.

- Controller and Command both call the same Operation.
- Transport-specific parsing and output remain in adapters.
- Operation implements the decision graph once.

This is the canonical reason to introduce Operation.

## Onboarding Notes
New developers should internalize three invariants.

1. SQL lives in Repository.
2. Transport shaping lives in adapters.
3. Operation owns the "what happens" decision graph and returns domain shapes.

Wiring overview:
- Controllers/Commands are instantiated by the transport runtime (router/CLI runner).
- Operations are instantiated explicitly via `new ...($this->app)`.
- Repositories are instantiated explicitly and receive `App`.
- Services are service-map singletons accessed as `$this->app->{serviceId}`.

If a change violates any invariant, it must be justified explicitly in review.

A small, consistent architecture beats a large, inconsistent one. Also, it is easier to debug at 3 AM, which is the only benchmark that matters if your app suddenly breaks down.

## Summary
CitOmni architecture is a constraint system.

- Minimal layers.
- Clear responsibility boundaries around persistence and transport.
- Explicit wiring and deterministic merges.
- Domain-shaped arrays as the lingua franca.
- Operation as the transport-agnostic orchestration layer, introduced only when it earns its existence.

This is not maximal purity. It is maximal predictability under performance constraints.