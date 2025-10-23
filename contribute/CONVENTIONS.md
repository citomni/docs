# CitOmni Coding & Documentation Conventions

These guidelines apply to all CitOmni packages and applications.

---

## Scope & Purpose

* Deliver **low-overhead**, **high-performance** PHP.
* Keep code **deterministic**, explicit, and **cache-friendly**.
* Make intent obvious with **clear PHPDoc** and **good inline comments**-in **English only**.

---

## Language & Runtime

* **Language:** English for *all* documentation-PHPDoc, inline comments, READMEs, commit messages, etc.
* **PHP Version:** **8.2+ only** (no compatibility for older versions).

---

## Code Style

* **Standards:** PSR-1 & PSR-4.
* **Naming:**

  * Classes: `PascalCase`
  * Methods/variables: `camelCase`
  * Constants: `UPPER_SNAKE_CASE`
* **Braces:** K&R style - opening brace on the **same** line.
* **Indentation:** **Tabs** (no spaces).
* **Files:** `declare(strict_types=1);` at the top of every PHP file.

Example:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Feature;

class FastThing { // prefer extensible (non-final) unless there is a strong reason
	/** @var int */
	private int $count = 0;

	public function increment(): void {
		$this->count++; // single-purpose, obvious side-effect
	}
}
```

---

## File Headers

All PHP source files must begin with the following structure:

1. `<?php`
2. `declare(strict_types=1);`
3. Lean license block (SPDX + copyright + short context)
4. `namespace`
5. `use` imports
6. Code (class, interface, function, etc.)

### License Block

The license header must always be short and deterministic.  
Use SPDX identifier, copyright year interval, project tagline, and repository link.

Example (CitOmni):

```php
<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni - High-performance PHP framework.
 * Source:  https://github.com/citomni/http
 * License: See the LICENSE file for full terms.
 */
```

---

## Extensibility (Design for Extension)

* Prefer **extensible** code by default.
* **Avoid `final`** on classes and methods **unless** you have a strong, documented reason (i.e. security, immutability, or strict invariants).
* Provide **clear extension points**:

  * Use **interfaces** for contracts.
  * Use **`protected`** visibility where subclassing needs hooks (without exposing unstable internals).
  * Keep **constructors stable** and minimize hidden side effects.
* If you mark something `final`, add a short PHPDoc rationale explaining **why extension is disallowed**.

---

## Project Structure & Autoloading

* **Autoloading:** PSR-4 (Composer).
* **Service constructors:** `new Service(App $app, array $options = [])`.
* **Fail fast:** Unknown service IDs or invalid definitions must throw `\RuntimeException`.

---

## Error Handling

* **Do not** catch errors or exceptions unless absolutely necessary.
* Let them bubble to the **global error handler** for logging.
* No "swallowing" exceptions; no silent fallbacks.

---

## Performance Principles

* Prefer **precompiled caches** where possible; avoid repeated merges/IO at runtime.
* Keep hot paths minimal (avoid unnecessary abstractions and allocations).
* Use constants/config for predictable branches; avoid global state.

---

## Documentation Rules

* **English only** (no exceptions).
* Every non-trivial method gets **PHPDoc** (see templates below).
* **Inline comments** are required where logic is non-obvious, performance-sensitive, or policy-driven.

### Good Inline Comments

* Explain **why**, not just **what**.
* Keep them short and exact; align with the line they justify.
* Avoid restating code; add **context, constraints, or trade-offs**.

Example:

```php
// Deliberately call guard() with no arguments.
// Rationale: maintenance policy is flag-first; passing args could override the flag and cause drift.
$app->maintenance->guard();
```

---

## Documentation Sanitization (Forbidden Characters & Style Consistency)

**Forbidden characters**
Always replace the following Unicode symbols with their plain-text ASCII equivalents:

| Symbol | Unicode     | Replace with |
| ------ | ----------- | ------------ |
| →      | U+2192      | `->`         |
| —      | U+2014      | `-`          |
| –      | U+2013      | `-`          |
| “ ”    | U+201C/201D | `"`          |
| ’      | U+2019      | `'`          |
| …      | U+2026      | `...`        |
| NBSP   | U+00A0      | (space)      |

**Style consistency**

* Always use correct orthography (spelling, capitalization, punctuation) across inline comments, PHPDoc, README, commit messages, etc.
* After a colon (`:`), start with a **capital letter** (e.g. `// Rationale: Maintenance policy` ✔, not `// Rationale: maintenance policy` ✘).
* Use consistent casing in section headers, comments, and examples.

---

## Tone of voice
Tone of voice defines the overall style and personality of our documentation and comments. Humor is allowed (or even encouraged), but it must never compromise clarity or correctness.

**Do**

* Use a light, professional humor to add warmth (short quips, dry notes, dry humor).
* Keep it brief and high-signal - the joke must not bury the meaning.
* Place it where it adds clarity or levity (e.g. "// No, you can't get negative seconds of downtime").
* Ensure it respects all sanitization and style rules (ASCII only, capitalization, punctuation).

**Don't**

* Write long "essays" of humor.
* Undermine clarity - the reader must never wonder if a comment is serious or sarcastic.
* Drift from reality - even funny comments must stay accurate.

---

## PHPDoc Templates

Copy these into your code. Adjust parameter/return/throws as needed.

### General Method

```php
/**
 * <One-line summary of what this method does.>
 *
 * <Optional longer description: context, intent, constraints.>
 *
 * Behavior:
 * - <Key step or guarantee>
 *   1) <sub-note #1>
 *   2) <sub-note #2>
 *   3) <sub-note #3>
 * - <Precedence/ordering rules, if any>
 * - <Side effects and outputs>
 * - <Thread-safety / reentrancy notes, if relevant>
 *
 * Notes:
 * - <Caveats, performance, determinism, environment assumptions>
 *
 * Typical usage:
 *   <When this method is expected to run / be called>.
 *
 * Examples:
 *
 *   // <Happy path in ≤ 6 lines>
 *   <mini-snippet or narrative, not both>
 *
 *   <Additional examples: Showing options/edge behavior>
 *
 * Failure:
 * - <How failure is exposed or contained>.
 *
 * @param <type> $<name> <Meaning, constraints (e.g., non-empty, absolute path)>.
 * @param <type> $<name> <...>
 * @return <type> <Units, shape, or contract; e.g., seconds >= 0 or array shape>.
 * @throws <\Fully\Qualified\Exception> <When and why this is thrown>.
 */
```

### Void Method

```php
/**
 * <Summary>.
 *
 * Behavior:
 * - <Key points>
 *
 * Notes:
 * - <Important caveats>
 *
 * Typical usage:
 *   <One-liner that shows when to call it>
 *
 * Examples:
 *   
 *   <Example that performs the void action and explains observable side effect>
 *   
 *   <Optional: Alternative path demonstrating idempotency or sequencing semantics>
 *
 * @return void
 * @throws <\Exception> <Reason>.
 */
```

### Getter

```php
/**
 * <Summary of the value being retrieved>.
 *
 * Notes:
 * - <Memoization/caching semantics, units, ranges>
 *
 * Examples:
 *   
 *   <Example: Reading the value and briefly asserting its shape/units>
 *
 * @return <type> <Description including units/shape>.
 */
```

### Class-Level (Recommended)

```php
/**
 * <ClassName>: <Short summary of responsibility and scope>.
 *
 * Responsibilities:
 * - <Responsibility 1>
 *   1) <sub-note #1>
 *   2) <sub-note #2>
 *   3) <sub-note #3>
 * - <Responsibility 2>
 *
 * Collaborators:
 * - <Service A> (read-only), <Service B> (writes), etc.
 *
 * Configuration keys:
 * - <cfg.path.to.key> (<type>) - <meaning / default>
 *
 * Error handling:
 * - <Fail-fast policy / surfaced exceptions>
 *
 * Typical usage:
 *
 *   <Small, realistic success path using this class>
 *
 * Examples:
 *   
 *   <Concise example showing correct usage with $this->app and expected result>
 *   
 *   <Additional examples: Showing options/edge behavior>
 *
 * Failure:
 *
 *   <Trigger a predictable failure; demonstrate that exception bubbles to global handler>
 *
 * Standalone (only if necessary):
 *
 *   <Optional: Minimal isolated demo for tutorials or sandboxing; include autoload/defines if required>
 */
```

---

## Inline Comment Patterns (Do / Don't)

**Do**

* Justify surprising behavior.
* Document security decisions and trust boundaries.
* Annotate performance-sensitive code with the reason for choices.
* Use "checkpoint style" comments in narrative flow: short, high-signal lines that describe *why this step exists* and *what it achieves*.
* Favor imperative mood ("Emit 503 response") for clarity and consistency.
* Where the rationale is important (e.g. security or architectural choices), include a brief justification - but keep it concise.

**Don't**

* Repeat the code verbatim ("increment count" above a `$count++`).
* Write essays - long background stories belong in README or design docs.
* Drift from reality - keep comments updated with code.
* Add fluff; comments must add meaning beyond the code.

---

## Example of Intent-Focused Comments

```php
// Deliberately call guard() with no arguments.
// Rationale: Maintenance policy (enabled, allowlist, retry_after) is owned by the flag file.
// The service itself falls back to cfg only if the flag is missing/incomplete.
// Supplying arguments here would override the flag and risk environment drift.
$app->maintenance->guard();
```

---

### Code Examples in Documentation

* All code examples in PHPDoc or READMEs must be syntactically valid PHP.  
* Inline comments inside examples must always use `//`, never `/* ... */`.  
  - Good: `if ($this->app->maintenance->isEnabled()) { // show admin banner }`  
  - Bad:  `if ($this->app->maintenance->isEnabled()) { /* show admin banner */ }`  
* Multiline comments are allowed only outside of code examples, never inline.  
* Examples should be copy-paste ready: they must not break when inserted in a real file.

---

## Commit Discipline

* Imperative, English commit subjects: "Add router warm cache", "Fix 503 headers".
* Reference tickets/issues when available.
* Keep diffs focused; avoid unrelated reformatting.
* Run the **documentation sanitizer** (above) before committing or in CI.

---

## Quick Checklist

* [ ] PHP 8.2+, `declare(strict_types=1);`
* [ ] Tabs, K&R, PSR-1/PSR-4
* [ ] **Design for extension**; avoid `final` without strong rationale
* [ ] Fail fast; no blanket `try/catch`
* [ ] Deterministic config; prefer caches
* [ ] English PHPDoc + inline comments (why > what)
* [ ] **Docs sanitized** (no forbidden chars; run `replaceForbidden`)
* [ ] Public methods documented using the templates above

---

These conventions are part of CitOmni's *performance-first, clarity-always, extensible by default.* philosophy. If you need to deviate, document **why** in code.