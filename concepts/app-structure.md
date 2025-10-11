# CitOmni Application Folder Structure

This document describes the default folder layout for a CitOmni-based application.  
The structure is optimized for clarity, performance, and separation of concerns.

---

## Overview

```

/app-root						// Project root
	/src						// Application code (PSR-4: App\ → src/)
		/Http					// HTTP delivery layer (web-only concerns)
			/Controller			// Thin controllers delegating to Services
			/Middleware			// Request/response middleware (auth, rate-limit, etc.)
			/Request			// Input DTOs/validators for HTTP endpoints
			/Responder			// View models/emitters (HTML/JSON), formatting only
			/Exception			// HTTP-specific exceptions (status mapping)
		/Cli					// CLI delivery layer (terminal-only concerns)
			/Command			// Thin commands delegating to Services
			/IO					// Helpers for prompts, tables, progress, etc.
			/Schedule			// Cron/recurring job descriptors
			/Exception			// CLI-specific exceptions (exit code mapping)
		/Service				// Domain/application services (shared by HTTP & CLI)
		/Model					// Domain models/persistence (shared)
		/Exception				// Transport-agnostic app/domain exceptions
	/config						// Base config + (optionally) env overrides
	/language					// Translations (kept outside webroot)
		/en						// English strings
		/da						// Danish strings
	/templates					// Templates/layouts/partials (no secrets here)
	/public						// Webroot (only public assets + front controller)
		index.php				// HTTP front controller (bootstrap for web)
		.htaccess				// Fallback rewrite→/public (Apache only; shared hosting)
		/assets					// Built/static assets (no source files)
		/uploads				// Publicly served uploads (strictly whitelisted types)
			.htaccess			// Defense-in-depth (deny script execution)
			/u					// Per-user tokenized public roots (avatars/media), intentionally web-accessible. Safe only for non-confidential files. Private docs go under /var or somewhere private. See CitOmni_Public_Uploads.md for full policy.
	/bin						// CLI entry point(s) (e.g., single executable launcher)
	/var						// Runtime writeable path (prod: only this is writeable)
		/backups				// App-level backup artifacts
		/cache					// Safe-to-purge caches (compilation, views, etc.)
		/flags					// Maintenance/feature flags (file-based toggles)
		/logs					// Application logs (rotate externally or in-app)
		/nonces					// Short-lived nonce store (if file-backed)
		/state					// PID/health/lock files, transient state
	/tests						// Tests (unit/integration/e2e as you grow)
	/vendor						// Composer-managed dependencies (do not edit)
		/citomni				// CitOmni packages installed via Composer
			/http				// CitOmni HTTP (delivery+infra for web)
			/cli				// CitOmni CLI (delivery+infra for terminal)
	composer.json				// Dependencies & autoload (PSR-4 App\ → src/)
	.htaccess					// Root fallback for shared hosting (rewrite to /public)


```

---

## Directories

### `/src`
Application code (PSR-4: `App\ → src/`).

- **`Http/`**  
  Delivery layer for web requests.  
  Contains controllers, middleware, request/response DTOs, and HTTP-specific exceptions.  

- **`Cli/`**  
  Delivery layer for command line execution.  
  Contains commands, I/O helpers, schedulers, and CLI-specific exceptions.  

- **`Service/`**  
  Domain and application services. Shared between HTTP and CLI.  

- **`Model/`**  
  Domain models, persistence logic, and data access. Shared between HTTP and CLI.  

- **`Exception/`**  
  Transport-agnostic exceptions for domain/application logic.

---

### `/config`
Configuration files.  
Typically one base file plus optional environment overrides (`env/dev.php`, `env/prod.php`, or `config.local.php`).  

---

### `/language`
Translation strings (I18N).  
Subfolders represent locales (`en/`, `da/`).  
Keep feature-based subfolders if the language set grows.

---

### `/templates`
HTML templates, partials, and layouts.  
Stored outside of webroot for security.  

---

### `/public`
The only webroot.  
Contains the HTTP front controller and all public assets.

- **`index.php`**: Front controller for HTTP requests.  
- **`.htaccess`**: Optional rewrite rule (shared hosting only).  
- **`assets/`**: Built/static assets (not source files).  
- **`uploads/`**: Public-by-design uploads. Only whitelisted filetypes allowed.  
  - **`u/`**: Per-user public root, keyed by opaque tokens (see `docs/CitOmni_Public_Uploads.md`).  

---

### `/bin`
Entry point for CLI (`cli` launcher or equivalents).  

---

### `/var`
The only writeable directory in production.  
All other folders should be read-only.

- **`backups/`**: Application-level backup artifacts.  
- **`cache/`**: Safe-to-purge caches (compiled templates, query results, etc.).  
- **`flags/`**: File-based toggles (e.g., maintenance mode).  
- **`logs/`**: Application logs. Rotate externally or in-app.  
- **`nonces/`**: Short-lived, file-backed nonces.  
- **`state/`**: PID files, lock files, health/status information.  

---

### `/tests`
Automated tests.  
Recommended subdivision:  
- `unit/` (single class/methods),  
- `integration/` (database/service combinations),  
- `e2e/` (end-to-end: run the app as a user would).

---

### `/docs`
Project documentation, architecture notes, operational guides, and conventions.

---

### `/vendor`
Composer-managed dependencies.  
- `citomni/http`: HTTP infrastructure (error handling, request/response utilities).  
- `citomni/cli`: CLI infrastructure (commands, scheduling, error handling).  
- `citomni/support`: Optional shared infrastructure (logging, mailer, connection, etc.).

---

## Principles

1. **Delivery separation:**  
   HTTP and CLI code live in their own namespaces. Controllers and commands are kept thin.

2. **Shared domain:**  
   Services and models are shared between delivery layers.

3. **Single webroot:**  
   Only `/public` is exposed. Everything else stays outside webroot for security.

4. **Runtime hygiene:**  
   `/var` is the only directory that should be writeable in production. Everything else is read-only.

5. **Uploads policy:**  
   `/uploads/` is *public by design*. Sensitive files must be stored outside webroot (e.g., `storage/private/`) and served through a controller with access control.

6. **Extend, don’t break:**  
   The structure is designed to scale. Add feature folders (`src/Users/…`) or extra delivery layers (`src/Jobs/…`) without reorganizing the base.

