# CitOmni Coding & Documentation Conventions

CitOmni uses explicit, deterministic PHP architecture to keep applications maintainable, fast, and economical with CPU, memory, and I/O. These conventions cover source code, documentation, tests, and shared design expectations across framework packages, providers, and applications.

This is a **practical reference**, not a substitute for reading the code. The current implementation, the target repository's `composer.json`, and its documented public contracts govern actual behavior. Repository-specific requirements may add constraints; if older examples disagree with current source, correct the examples rather than adapting working code to outdated documentation.

These conventions accompany the [organization-wide contribution guide](https://github.com/citomni/.github/blob/main/CONTRIBUTING.md), the [Testing Guide](testing-guide.md), and the [CitOmni Architecture](../concepts/architecture.md).

---

## Guiding principles

- **Explicit over implicit.** Register services, providers, routes, and commands directly. Avoid reflective discovery, autowiring, and hidden execution paths.
- **Deterministic over surprising.** Make precedence, data shapes, failure behavior, and side effects predictable.
- **Performance first, with evidence.** Avoid wasteful work; measure significant changes under representative conditions.
- **Clear responsibility.** Keep transport, workflow orchestration, persistence, and reusable tooling in their appropriate layers.
- **Small public contracts.** Prefer the simplest stable interface that satisfies a real requirement.
- **Fail fast.** Do not silently substitute an undocumented behavior when a required dependency or invariant is missing.
- **Green by Design.** Lower CPU demand, unnecessary allocations, I/O, and infrastructure consumption where it is technically and economically meaningful.

Adding a layer, interface, cache, abstraction, or fallback is not automatically an improvement. Explain the problem it solves and the cost it introduces.

---

## Language and PHP requirements

- **PHP 8.5 or later** for current maintained CitOmni framework packages. Check each package's `composer.json` for its exact constraints and required extensions.
- **English** for README files, technical documentation, PHPDoc, inline comments, diagnostic text intended for maintainers, and Git commit messages.
- Application-facing translations, localized UI text, and external data may use the languages and character sets they require. Do not alter real multilingual content to enforce an English-only documentation convention.
- Begin every PHP source file with `<?php` followed immediately by `declare(strict_types=1);`, before its license notice, namespace, imports, and declarations.
- Use Composer dependencies and PSR-4 autoloading. CitOmni framework packages must be installed through Composer, not copied into an application's source tree.

There is no general requirement to support PHP 8.2, 8.3, or 8.4 just because older project documentation mentions those versions.

---

## PHP naming and formatting

| Element | Convention |
|---|---|
| Namespace and class names | PSR-4, `PascalCase` classes |
| Methods | `camelCase` |
| Variables and properties | `camelCase` |
| Constants and enum cases | Use established package conventions; constants normally use `UPPER_SNAKE_CASE` |
| Opening braces | K&R style, on the declaration or control-statement line |
| Indentation | Tabs, not spaces |
| Files | One clear responsibility; avoid unrelated declarations or side effects |
| Return values and parameters | Prefer explicit types when they communicate a meaningful contract |
| Strict types | `declare(strict_types=1);` immediately after `<?php` |

Illustrative pure utility:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Util;

final class TextNormalizer {
	/**
	 * Normalize surrounding whitespace in a value.
	 *
	 * @param string $value Input value.
	 * @return string Trimmed value.
	 */
	public static function normalize(string $value): string {
		return trim($value);
	}
}
```

Only use `final` when the class is intentionally closed to extension or when a concrete invariant requires it. Conversely, do not create `protected` extension hooks or interfaces speculatively. A public extension point must have a reason and a documented contract.

Do not reformat otherwise unrelated code in a functional change. A focused diff is easier to review and less likely to hide a regression.

### Source-file notices

Keep file headers consistent with the **actual** repository, license, rights holder, and existing file history.

A typical MIT-licensed CitOmni PHP source file begins with the PHP opening tag, strict types, and a short comment containing the appropriate copyright and SPDX notice. For example, a file **owned by the named rights holder** may include:

```php
<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (c) 2012-present Lars Grove Mortensen
 */

namespace CitOmni\Kernel;
```

This is not a universal copyright template. Do not paste a fixed name, year, or `MIT` identifier into a different repository without verifying its `LICENSE` and notices. Documentation-only repositories such as `citomni/docs` use **CC BY-SA 4.0** under their own license; their license does not automatically apply to source files in MIT packages.

See the [Licensing Overview](https://github.com/citomni/.github/blob/main/LICENSING.md) for the distinction between source-code licensing, documentation, third-party assets, and trademarks.

---

## CitOmni application layers

The application architecture uses focused responsibilities rather than a broad model layer or a general-purpose dependency injection container.

| Layer | Contract |
|---|---|
| HTTP Controller | HTTP transport adapter. Parse request input, handle CSRF/session and response shaping; no SQL or non-trivial business orchestration. |
| CLI Command | CLI transport adapter. Parse arguments, format output, return exit codes; no SQL or non-trivial business orchestration. |
| Operation | Transport-independent orchestration and decisions. May coordinate repositories and services; no SQL or transport response shaping. |
| Repository | Persistence boundary. Own SQL and datastore I/O; no transport, mail delivery, or user-facing workflow decisions. |
| Service | Reusable App-aware capability registered in the service map; no embedded domain SQL. |
| Contract | Small, stable public interface intended for real external extension or typing. |
| Policy | App-aware rules and policy-driven decisions. |
| State | App-aware state contracts, keys, TTLs, and transitions. |
| Support | Focused App-aware helper that is not a registered service or pure utility. |
| Enum | Stable, bounded vocabulary for statuses, modes, and other value sets. |
| Util | Pure computation, without App, config, mutable state, persistence, logging, network, or filesystem I/O. |
| Exception | Transport-independent domain or application failure semantics. |

**Architectural invariants**

1. SQL and domain datastore access belong in Repositories. The infrastructure database service provides execution mechanics; it does not relocate application query ownership into Controllers or ordinary Services.
2. HTTP and CLI transport concerns stay in Controllers and Commands.
3. Non-trivial, reusable orchestration belongs in Operations.
4. A Controller may delegate directly to a Repository for trivial CRUD when no Operation is justified.
5. Add a layer only when it creates a concrete boundary, reuse benefit, or significant clarity.

Operations are instantiated explicitly, for example `new SaveDocument($this->app)` inside a caller that owns the workflow; they are **not** registered as lazy App services merely because they use the App. A class under `src/Service/` must be an actual registered service, rather than an arbitrary place to put reusable code.

The `citomni/kernel` package provides the shared App and base-class contracts, while `citomni/http`, `citomni/cli`, and providers own their respective runtime behavior.

---

## Packages, providers, and registration

Reusable CitOmni functionality belongs in focused Composer packages with explicit dependencies. A package should not acquire HTTP, CLI, database, or other capabilities merely because a possible consumer might someday need them.

### Provider Registry

A provider contributes declarative arrays through `src/Boot/Registry.php`. The consuming application explicitly registers that class in `config/providers.php`.

Supported contribution constants are:

| Constant | Contribution |
|---|---|
| `MAP_COMMON` | Services shared by HTTP and CLI |
| `MAP_HTTP`, `MAP_CLI` | Mode-specific services |
| `CFG_COMMON` | Shared configuration defaults |
| `CFG_HTTP`, `CFG_CLI` | Mode-specific configuration defaults |
| `ROUTES_HTTP` | HTTP dispatch entries |
| `COMMANDS_CLI` | CLI command dispatch entries |

For a concrete new provider, declare only the constants it needs. A minimal illustrative registration looks like this:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Registry {
	public const array MAP_COMMON = [
		'exampleTool' => \Vendor\Package\Service\ExampleTool::class,
	];
}
```

The example describes a hypothetical provider; it does not assert that `exampleTool` is a standard CitOmni service ID.

Do not use obsolete provider patterns such as `Boot/Services.php`, provider-owned vendor baselines in `Boot/Config.php`, `Boot/Routes::MAP`, or CLI `ROUTES_CLI`. HTTP routes and CLI commands are **dispatch maps**, not configuration entries.

A Registry must have no side effects when loaded. Do not open database connections, fetch remote resources, scan folders, inspect the current HTTP request, or construct runtime services while declaring its contributions. Keep boot definitions stable and reviewable.

### Service definitions and construction

Service-map entries have exactly the following supported forms:

```php
'exampleTool' => \Vendor\Package\Service\ExampleTool::class
```

```php
'exampleTool' => [
	'class' => \Vendor\Package\Service\ExampleTool::class,
	'options' => ['enabled' => true],
]
```

A registered service is resolved lazily using `new Service($app)` or `new Service($app, $options)` and is memoized within that `App` instance. Keep constructors cheap. Defer expensive I/O until a method actually needs it, unless the service contract requires otherwise.

An unknown service ID or an invalid definition fails explicitly with `RuntimeException`. Do not implement parallel discovery, autowiring, or hidden fallback instantiation paths.

`$app->hasService('id')` checks service registration. `$app->hasPackage('vendor/name')` and `$app->hasNamespace('Vendor\\Package')` use CitOmni's registration-based detection and are **not** general-purpose Composer installation or autoloader probes.

### Composition and precedence

Configuration is assembled for the selected HTTP or CLI mode, from lowest to highest precedence:

1. The vendor runtime Registry baseline.
2. Provider configuration in `config/providers.php` order; within each provider, `CFG_COMMON` before `CFG_HTTP` or `CFG_CLI`.
3. App common configuration in `config/citomni_cfg.php`, then its mode-specific counterpart.
4. App common environment overlay, then its mode-specific environment overlay.

Associative config arrays are merged recursively and later values win. List arrays are replaced rather than concatenated. `$app->cfg` exposes the result as a read-only `Cfg` object, with nested associative arrays converted to `Cfg` and lists remaining arrays.

Unknown direct configuration property reads raise `OutOfBoundsException`; use `??` when a value is genuinely optional. Example using the existing locale key:

```php
$timezone = $this->app->cfg->locale->timezone ?? 'UTC';
```

Service precedence is separate and also deterministic. Newer layers replace earlier registrations for the same service ID:

1. Mode vendor baseline.
2. Providers, with `MAP_COMMON` then the mode-specific map for each provider; later providers override earlier ones.
3. `config/services.php`.
4. `config/services_http.php` or `config/services_cli.php`.

The implementation uses PHP array-union operations with the higher-precedence source on the left. The **left-hand value wins** for duplicate IDs; overall, the application has the final say.

Dispatch maps have their own merge pipeline. HTTP uses the vendor and provider `ROUTES_HTTP` entries followed by app base and environment route files. CLI uses `COMMANDS_CLI` followed by app base and environment command files. Do not put routes or commands into configuration or service definitions merely to make them discoverable.

When a contribution changes precedence, service IDs, config keys, routes, or command names, explain downstream impact and required migrations.

---

## Error handling and failure semantics

- Throw meaningful exceptions when input, configuration, capabilities, or required dependencies violate the contract.
- Let unrecoverable failures reach the runtime's established global error handler.
- Catch exceptions only when the failure is genuinely recoverable, when an explicit fallback exists, or when a boundary deliberately translates an exception into another documented failure type.
- Avoid broad `catch (\Throwable)` in ordinary services. It can hide failures and make debugging harder.
- Do not turn a broken dependency into a successful but incomplete result without making that behavior part of the public contract.
- HTTP Controllers and CLI Commands may translate domain exceptions into transport-specific status codes or exit codes. Domain Operations and Repositories should not return transport objects.
- Log useful context without recording secrets, access tokens, credentials, or personal data unnecessarily.

Failure paths are part of correctness and performance. Test invalid input, unavailable dependencies, write failures, and partial effects where relevant.

---

## Security and trust boundaries

Treat all external input, file content, database records, and webhook payloads as untrusted until validated for the specific operation.

- Validate input shape and types at the transport boundary, and enforce domain constraints where they are owned.
- Use bound SQL parameters for values; SQL identifiers and query structure require their own explicit allowlists.
- Apply authorization checks independently of input validation. A valid ID is not proof of permission.
- Use the HTTP layer's actual CSRF and session contracts. Do not rely on removed `security` or `view` service examples.
- Never log raw credentials, authentication tokens, passwords, private keys, or unredacted sensitive URLs.
- Keep application secrets out of normal configuration files and source control. Use the supported application secret facilities where provided.
- Treat temporary files, uploaded files, and filesystem paths as hostile inputs until validated and constrained.
- Do not run destructive test suites against production or irreplaceable data.
- Report suspected vulnerabilities privately through the [Security Policy](https://github.com/citomni/.github/blob/main/SECURITY.md).

Prefer explicit error reporting to unsafe silent recovery when a security-critical prerequisite is missing.

---

## Green coding and performance

CitOmni treats resource efficiency as a design requirement. Reducing unnecessary CPU work, memory allocations, filesystem activity, database round-trips, and external requests can improve latency, hosting capacity, and energy efficiency. **Claims about actual energy use or throughput still require appropriate measurement and context.**

### Practical choices

- **Keep hot paths short.** Avoid gratuitous object graphs, string copies, helper chains, repeated parsing, and generic abstraction layers without measurable value.
- **Prefer explicit registration.** Static Registry maps avoid runtime class discovery and autowiring overhead.
- **Use caching where useful.** Cache expensive deterministic setup when the invalidation rules are understood; a cache with unclear freshness behavior is not free performance.
- **Use OPcache effectively.** Favor simple PHP code and compiled configuration, service, and dispatch maps in deployments where they help.
- **Minimize I/O.** Avoid repeated reads, needless queries, N+1 patterns, repeated remote calls, and unnecessary write amplification.
- **Be deliberate with memory.** Avoid materializing large payloads repeatedly; release expensive buffers and native resources when no longer needed.
- **Respect batch behavior.** Where safe, reuse parsed or decoded inputs for related outputs instead of repeating the most expensive step.
- **Avoid speculative complexity.** A more generalized mechanism is not automatically faster or easier to maintain than a small, focused implementation.
- **Optimize whole workflows.** A micro-optimization that adds network traffic, lock contention, or repeated database work can make the application slower overall.

### Compiled maps and cache freshness

CitOmni's compiled runtime maps include:

| Runtime | Configuration | Dispatch | Services |
|---|---|---|---|
| HTTP | `var/cache/cfg.http.php` | `var/cache/routes.http.php` | `var/cache/services.http.php` |
| CLI | `var/cache/cfg.cli.php` | `var/cache/commands.cli.php` | `var/cache/services.cli.php` |

When relevant definitions change, rebuild the affected maps through the supported cache tooling. Deployments using OPcache must account for the active invalidation policy. Do not promise that a cache change is live without checking both the generated files and runtime behavior.

Compiled TemplateEngine output and other package-owned caches have separate lifecycle contracts. Do not silently treat every cache as one generic file to delete.

### Measuring changes

For changes to boot, routing, service resolution, dispatch, serialization, database access, or other frequently executed paths, provide a small performance note when practical:

1. Describe the operation and workload, including relevant input size.
2. Record PHP version, OS, runtime mode, extensions, configuration, and OPcache state when they affect the result.
3. Separate cold startup from warm-cache measurements.
4. Compare before and after using the same workload and environment.
5. Report suitable measurements such as p50/p95 wall time, CPU time, peak memory, request counts, database round-trips, bytes, or relevant filesystem I/O.
6. Explain tradeoffs, variance, and whether the difference is large enough to matter.

If a reliable measurement cannot be made, say so and provide a concrete reasoning-based performance assessment. Do not invent timings or infer universal capacity figures from an isolated benchmark.

There is **no single universal limit** for CPU milliseconds, RAM, file reads, or SQL queries that applies equally to a cached page, image conversion, database migration, and long-running worker. Budgets must be workload-specific and measurable.

The [historical capacity and Green by Design report](../reports/2025-10-02-capacity-and-green-by-design.md) documents a particular 2025 shared-hosting workload. Its measured values and queueing-based throughput estimates are **not** blanket guarantees for later CitOmni releases or arbitrary applications.

Green coding aims to reduce waste. It does not excuse weakened correctness, security, test coverage, or maintainability.

---

## PHPDoc and inline comments

The [CitOmni Code Documentation Guide](./code-documentation-guide.md) defines how PHPDoc and inline comments are written. It holds the templates for classes and methods, the rules for sections and tags, and a review checklist. The guide is authoritative for these rules; this section only summarizes them.

- **Document what the element's own code makes true.** If a diff that does not touch the element can make a statement false, remove the statement, rewrite it as a requirement, or point to the owner with `@see`. A stable contract may be referenced; another element's current state, such as a route, a cfg value or a template, may not.
- **The owner documents.** Routes are documented in route files, the meaning of a cfg key where the key is defined, and framework behavior in the framework's PHPDoc.
- **Contract in PHPDoc, rationale inline.** PHPDoc states what a caller can rely on. An inline comment explains why a specific line looks the way it does, and may state an assumption the code relies on.
- **Class PHPDoc** states the class's role, its purpose and scope, and rules that every present and future member must meet. It does not summarize or list members.
- **Method PHPDoc** states observable outcomes and guarantees, not steps. Every method has `@param` and `@return`, except that constructors and destructors have no `@return`; `@throws` lists contractual exceptions; `@see` replaces copied behavior.
- **Constants, enum cases and properties** each get a one-line PHPDoc that states what the value means. Every value key in a cfg baseline gets a comment on what it controls.
- **Section dividers** mark structure, such as groups of members or phases in a method, not length.

---

## Documentation style

Documentation is part of the interface. It should describe **implemented, verified behavior**, especially public methods, configuration, dependencies, failure modes, and operational caveats.

### Package READMEs

A package README should act as a **manual**, not a TODO list, changelog, or historical diary. Depending on the package, useful sections include:

- Purpose, highlights, and responsibility boundaries.
- Requirements, Composer installation, and provider registration where needed.
- A quick start that reflects actual APIs and application scaffolds.
- Public methods, configuration, validation, failure semantics, and examples.
- Security, runtime capabilities, deployment, caching, performance, and testing where relevant.
- Limitations, source layout, license, and references to further documentation.

Keep release announcements and migration history in release notes or migration documents. Avoid duplicating extensive material from another package when a precise reference will suffice.

### Code examples

- Use actual API names and verified configuration keys. Do not copy obsolete snippets merely because they appear in an older README.
- Prefer runnable, copyable examples. Mark pseudo-code or hypothetical provider examples explicitly.
- Use `php` code fences for complete PHP snippets; shell commands belong in `bash`, `powershell`, or another appropriate fence.
- Keep PHP examples consistent with PHP 8.5+, strict types, tabs, K&R, and English comments.
- Explain prerequisites such as required services, database tables, permissions, filesystem paths, or optional extensions.
- Keep code examples short enough that readers can identify the important behavior without reconstructing an entire application.
- Do not place working sample code under a blanket assumption that it is valid for all CitOmni packages.

### Markdown and typography

- Use descriptive headings and link text; keep section depth and terminology consistent.
- Normalize **typographic punctuation** in technical source comments and documentation. The project prefers plain ASCII punctuation for stable diffs and portable tooling.
- Use `->` instead of the Unicode right-arrow (U+2192); `-` instead of en/em dashes (U+2013/U+2014); straight quotes instead of curly quotes (U+2018/U+2019/U+201C/U+201D); `...` instead of a single ellipsis character (U+2026); and normal spaces instead of non-breaking spaces (U+00A0).
- Do **not** corrupt legitimate Unicode in localized examples, identifiers, language files, or quoted user-facing content to enforce a punctuation convention. Technical typography normalization is not a requirement to remove non-English characters from real content.
- Avoid stray non-breaking spaces, hidden control characters, misleading whitespace, and inconsistent line endings.
- In prose and comments, capitalize the first word after a colon when starting a new statement. Do not change API identifiers, literal keys, URLs, or code syntax to satisfy a typography rule.
- Keep Markdown links relative when referring to files in the **same repository**, and use stable repository URLs for cross-repository references.
- Validate that links exist and that examples match the source version being documented.

Humor is fine when short and accurate. Do not allow it to obscure a contract, failure case, or security warning.

---

## Testing and regression evidence

Use the current [CitOmni Testing Guide](testing-guide.md) for the detailed package testing contract. It describes standalone PHP suites rather than a mandatory PHPUnit umbrella runner.

For packages following that guide:

- `tests/run.php` discovers the package's suites and starts them in separate PHP processes.
- `tests/<suite>/run.php` contains isolated checks; `tests/<suite>/database.php` contains real MySQL/MariaDB integration checks.
- Tests report `PASS`, `FAIL`, and applicable `SKIP` checks and end with the guide's required summary format.
- Database integration suites use isolated, disposable databases, not application or production data.
- Optional multi-process checks follow the documented `CITOMNI_TEST_PARALLEL` opt-in.
- A regression test should demonstrably fail against the version without the fix it protects.

The exact repository's supported commands and test files still matter. Do not claim a suite passed unless it was actually run. When a test cannot be executed, document the constraint; do not convert a missing prerequisite into a fictitious success.

Useful checks vary by change. Cover invalid input, missing capabilities, compatibility, persistence constraints, security decisions, compiled maps, and concurrency where relevant. Keep fixtures predictable and run destructive tests only in disposable environments.

Documentation-only changes generally need Markdown, link, and example validation rather than artificial application tests.

---

## Public APIs and backward compatibility

Treat documented public behavior as an intentional contract, including:

- Class, method, argument, return, and exception semantics.
- Registered service IDs and accepted constructor options.
- Provider Registry contributions, precedence, and visibility by runtime mode.
- Configuration keys, defaults, and optional/missing-key behavior.
- HTTP routes, CLI commands, and transport shapes.
- Schema and persistence contracts, state transitions, and cache formats.

For a behavior change, identify real supported consumers and the target package's versioning policy before deciding between compatibility, deprecation, migration, or a deliberate breaking release. Never add a compatibility shim solely to preserve an undocumented development artifact.

Prefer explicit versioned migrations and clear failure behavior to speculative silent fallbacks. Tests and documentation should be updated in the same change as the contract they describe.

---

## Pull requests, commits, and review

- Use focused commits with **imperative English subjects**, such as `Fix HTTP route cache invalidation` or `Document service-map precedence`.
- Avoid unrelated reformatting and speculative features in an otherwise focused fix.
- Explain **why** the change exists, not only which files changed.
- Include reproducible verification evidence, and disclose checks that were not run.
- Document compatibility, security, deployment, and performance implications when material.
- Ensure new dependencies, embedded third-party assets, and notices are compatible with the target repository's license.
- Follow the organization-wide [Pull Request Template](https://github.com/citomni/.github/blob/main/PULL_REQUEST_TEMPLATE.md).
- Add a Developer Certificate of Origin (DCO) `Signed-off-by` trailer to commits using `git commit -s` as requested by the shared template. This is distinct from cryptographic signing with `git commit -S`.

For suspected vulnerabilities, use the private [Security Policy](https://github.com/citomni/.github/blob/main/SECURITY.md), not a public issue or pull request.

---

## Quick review checklist

- [ ] PHP version and extensions match the target repository's requirements.
- [ ] Code follows PSR-1/PSR-4, tabs, K&R, and strict types.
- [ ] Registry, config, services, routes, and commands use supported composition contracts.
- [ ] SQL and transport concerns stay in their appropriate layers.
- [ ] Error handling fails clearly without hiding unexpected failures.
- [ ] Security boundaries and secrets handling are addressed.
- [ ] Hot-path work and Green by Design implications are understood; measurements are supplied when useful.
- [ ] Tests, documentation, and examples match the actual change and were verified as reported.
- [ ] PHPDoc and inline comments follow the Code Documentation Guide.
- [ ] License, copyright, third-party notices, and trademark requirements are respected.
- [ ] Public contract and migration effects are documented where relevant.

---

## Related documents

- [Contributing to CitOmni](https://github.com/citomni/.github/blob/main/CONTRIBUTING.md)
- [CitOmni Testing Guide](testing-guide.md)
- [CitOmni Code Documentation Guide](./code-documentation-guide.md)
- [CitOmni Architecture](../concepts/architecture.md)
- [Provider Skeleton](https://github.com/citomni/provider-skeleton)
- [GitHub Pull Request Template](https://github.com/citomni/.github/blob/main/PULL_REQUEST_TEMPLATE.md)
- [Licensing Overview](https://github.com/citomni/.github/blob/main/LICENSING.md)
- [Security Policy](https://github.com/citomni/.github/blob/main/SECURITY.md)

---

CitOmni - low overhead, high performance, ready for anything.
