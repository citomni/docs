# CitOmni – Maintenance Mode: Architecture, Performance, and Rationale

**Purpose:**
This report documents why CitOmni uses a small *flag file* (`maintenance.php` returning an array) as the single source of truth for maintenance mode, loaded once per request via OPcache.
It serves both as internal memory (for when the question inevitably resurfaces) and as a technical background document that can be shared.

---

## TL;DR

* **Chosen model:** Tiny PHP flag file, included by the kernel and stored in `$app->cfg->maintenance`. When toggled, the file is written atomically and `opcache_invalidate()` is called.
* **Performance (measured):** ~**0.000011–0.000013 s** per request (≈ **11–13 µs**) on shared hosting with OPcache (one.com / simply.com). p95 ~11–19 µs. Occasional outliers are OS jitter and irrelevant to steady-state.
* **Conclusion:** Overhead is effectively **“free.”** A constant in `cfg.php` would only be theoretically faster and practically worse (toggle friction, larger blast radius).
* **Operations:** Toggling is safe (atomic write, optional backup, OPcache invalidate). Works on standard shared hosting without special configuration.
* **Alternatives:** APCu can be used opportunistically as a bonus (if available) but is *not* required.

---

## 1) Background and Requirements

**Requirements for CitOmni:**

* Must run on **shared hosting** with no root access or special PHP extensions.
* **Maintenance OFF** must have negligible runtime cost.
* **Toggling** must be robust, idempotent, and instantly visible (no PHP-FPM/Apache restarts).
* **Audit & reliability:** Support for logging, simple backups, deterministic behavior.
* Compatible with **PHP 7.4+**, PSR-1/4, etc.

---

## 2) Selected Architecture (summary)

1. **Flag file** (e.g. `.../var/flags/maintenance.php`) returns an array:
   `['enabled' => bool, 'allowed_ips' => [], 'retry_after' => int]`.
2. **Kernel** includes the flag file → OPcache serves bytecode from shared memory.
3. **Guard** runs very early: If `enabled === true` and the client IP is not whitelisted → send 503 + Retry-After and exit. Otherwise continue normal boot.
4. **Toggling** (enable/disable): Writes new flag file **atomically** (tmp+rename), optional backup rotation, and calls `opcache_invalidate()` for immediate visibility.

**Characteristics:**

* Normal operation (OFF): one `include` of a tiny file (OPcache hit) + a boolean check.
* Maintenance ON: same check + a small `in_array()` on a short whitelist.
* Toggling: limited and controlled disk I/O.

---

## 3) Performance Model (theoretical cost)

* **OPcache** means: The flag file is parsed/compiled **once**. Afterwards bytecode is fetched from shared memory.
* **Stat checks:** With default `opcache.revalidate_freq` (typically 2 s), there is *no* `stat()` on every request.
* **Include overhead** is reduced to a hash lookup + pointer to existing opcodes.
* **Expectation:** 5–20 µs per include on modern shared hosting.

---

## 4) Benchmarks (measured in production)

**Setup:** Standalone micro-benchmark (`bench_maint_include.php`) that:

* Generates a minimal flag file identical to CitOmni’s.
* Measures `include $flagPath;` *N* times and prints mean/median/p95/min/max.
* *Normal mode* (as in production): OPcache is warm, **no** invalidation in the loop.

**Results (excerpt):**

* **Mean:** 0.000011–0.000013 s (11–13 µs)
* **Median:** ~0.000011 s
* **p95:** 0.000011–0.000019 s
* **Max:** Occasional spikes (0.00016–0.00065 s) — explained by OS scheduling/jitter/hypervisor and irrelevant for steady-state.

**Interpretation:**
The figures match expectations. In practice, overhead is **independent of app logic** and far below the noise level of DB, network, or templating operations.

---

## 5) Why not a constant in `cfg.php`?

**Theoretically** slightly cheaper (eliminates one `include`), but **practically worse**:

* **Toggle friction:** You’d have to write into `cfg.php` (larger file, larger blast radius, risk of VCS noise/conflicts).
* **Same OPcache requirement:** You still need to call `opcache_invalidate(cfg.php)` for immediate visibility.
* **Negligible gain:** The difference between ~11 µs and “~0 µs” is not measurable in the real world.

**Conclusion:**
The flag file offers the best balance of speed, isolation, and operational safety.

---

## 6) Alternative Models (evaluation)

**APCu (userland cache in RAM)**

* **Pro:** Nanosecond/microsecond lookups, zero disk I/O per request.
* **Con:** Not guaranteed on shared hosting; flushed on process restart; separated between web/CLI; still requires a persistent “truth” (the file).
* **Recommendation:** Use **opportunistically** if available, as a read-through cache. *Not required.*

**ENV/ini/Feature flag in server config**

* Often requires reload/restart to take effect → **poor DX** for frequent deploys.

**Database**

* Unnecessarily heavy; makes maintenance check depend on DB availability early in boot.

**.htaccess / webserver switch**

* Can be fast but less portable across hosts and harder to toggle atomically from PHP.

---

## 7) Operational Characteristics and Robustness

**Atomic write:**

* Write to `*.tmp` + `rename()` into place (with Windows fallback using `unlink()`).
* Guarantees consistent reads — either old or new file, never a half-written one.

**OPcache invalidation:**

* `opcache_invalidate($flagPath, true)` is called on toggle → **immediate visibility** across all workers, without increasing `revalidate_freq` or requiring server reload.

**Backups & retention:**

* Optional rotation (keep last N versions), useful for audit or rollback.

**Fault tolerance:**

* Missing or corrupt flag file → safe defaults (`enabled=false`, empty whitelist, retry_after from cfg).
* Exceptions are thrown only on programming/deploy errors during toggling (so the global error handler can log properly).

**Security:**

* IP whitelist (and in dev/stage optionally accepts literal `"unknown"` for local setups).
* 503 response includes `Retry-After`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`.

---

## 8) Shared-Hosting Compatibility

* **No** dependency on APCu/Redis/daemons.
* Only requirement: Standard OPcache (default configuration).
* `opcache_invalidate()` confirmed to work on both one.com and simply.com.
* No special ini settings needed; defaults are fine.

---

## 9) Best Practices (production)

* Keep the flag file **minimal** (as it is now).
* Use **absolute path** to the file.
* Keep the **early guard** in boot.
* When toggling: atomic write → `opcache_invalidate()`.
* (Optional) call `opcache_compile_file($flagPath)` after write to pre-warm opcodes.
* Consider an **opportunistic APCu read-through** (auto-detected, no hard dependency) if you want to shave off microseconds — it doesn’t change the architecture.

---

## 10) Decision (anchoring)

We retain **flag file + OPcache** as the official CitOmni model for maintenance mode.

* Rationale: **Measured** overhead ~**11–13 µs** per request; robust toggling; low complexity; shared-hosting-friendly; good audit trail.
* Any APCu integration is *optional* and must be non-intrusive and self-disabling if unavailable.

---

## 11) Future Improvements (optional)

* Small CLI command for toggling with human-friendly output and automatic log/audit.
* Optional short “reason” string in the flag file → displayed in the maintenance template (useful for long maintenance windows).
* Simple health checks warning if the flag file is older than X days (forgotten ON).

---

## Appendix A – Micro-benchmark (standalone)

> Can be uploaded as `bench_maint_include.php`.

```php
<?php
declare(strict_types=1);
/**
 * CitOmni — Maintenance flag include micro-benchmark (standalone).
 * (Shortened for readability – use the full version from the project thread if needed)
 */
// ... full version exists in the project’s discussion history.
```

---

## Appendix B – Example Flag File

```php
<?php
// Generated by the Maintenance service. Do not edit manually.
return [
	'enabled' => false,
	'allowed_ips' => [],
	'retry_after' => 600
];
```

---

## Short Notes for Commit / Changelog

* **Maintenance mode – documentation & benchmarks:** Added internal report explaining the flag-file + OPcache model. Real measurements on shared hosting show ~11–13 µs per include (p95 ≤ ~19 µs). Toggling is atomic with `opcache_invalidate()` for instant visibility. The solution is platform-agnostic and requires no special configuration.
