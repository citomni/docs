# Capacity & "Green by Design" Test Report (CitOmni HTTP front page)

**System Under Test:** CitOmni HTTP framework boot + minimal front page (LiteView¹ + LiteTxt², no DB)

**Test Date:** 2 October 2025

**Author:** Internal QA, [Lars Grove Mortensen](https://github.com/LarsGMortensen), CitOmni.com

---

## Abstract

This report quantifies the **request throughput capacity** of CitOmni's HTTP framework on a minimal front page route (rendered via LiteView¹ with i18n via LiteTxt², no database), using a **real production baseline on low-cost shared hosting (one.com)**.

The aim is to evaluate whether CitOmni's **"Green by design"** claim holds in practice by translating an observed per-request service time into realistic **RPS** (requests per second) across **1, 2, 4, 8, and 16** PHP-FPM workers.

Instrumentation reports ~0.004 s server time, ~0.5 MB peak process memory, and 24 included files for the full request on one.com. Using queueing-theory-informed sizing (conservative 70% utilization to avoid latency blow-ups), we estimate ~175 RPS per worker, scaling nearly linearly with worker count for this I/O-free route. Thus, even with 2-4 workers (typical on shared plans), capacity reaches ~350-700 RPS (≈ 1.26-2.52 million requests/hour) for this page.

Results support the "Green by design" positioning by demonstrating extremely low CPU and memory footprint on commodity infrastructure.

---

## Background

CitOmni prioritizes **deterministic boot, minimal allocations, and low file counts**. Prior internal research-style reports set the house style for methodical, reproducible evaluations (cookie/session services). This study extends that approach to **throughput capacity**, focusing on the framework's core execution path rather than application-specific logic.

We explicitly exclude app-layer variability (e.g., database, external APIs) to isolate the framework's contribution.

**Why shared hosting?** To establish a **conservative floor** that avoids the "positive bias" of expensive, dedicated hardware. Shared hosting also embodies a sustainability angle akin to **carpooling**: shared resources reduce idle waste when individual sites are not under load.

---

## Methods

### Test Design

* **Objective:** Convert measured per-request service time for the CitOmni front page into **RPS** across various worker counts (W = 1,2,4,8,16), with both theoretical and conservative (70%) estimates.
* **Scope:** Framework only - front controller -> kernel (warm caches) -> maintenance check -> router -> controller -> LiteView¹ render (with LiteTxt²). **No DB**, no external I/O, no debug toolbars.
* **Out-of-scope:** How application code could degrade performance. This is a framework-level assessment.

### Environment

* **Hosting:** one.com (shared hosting).
* **Runtime:** PHP-FPM with OPcache enabled (`validate_timestamps=true` at the time of measurement).
* **Route:** Minimal "front page" with LiteView¹ + LiteTxt²; static assets not considered (edge/server can serve them without PHP).
* **Measurement:** CitOmni `?_perf` footer emitted:

  * Execution time (s) via `hrtime()` and `CITOMNI_START_NS`.
  * Memory (current/peak) via `memory_get_usage()` / `memory_get_peak_usage()`.
  * Included file count via `count(get_included_files())`.

**Observed (representative run):**

```
Execution time: 0.004 s
Current memory: 440,464 bytes
Peak memory:    526,152 bytes
Included files: 24
```

### Procedure & Calculus

Let:

* s = observed service time per request (seconds). Here, s = 0.004 s.
* W = number of concurrent PHP-FPM workers.

Formulas:

```
Per-worker theoretical RPS:
RPS_theory = 1 / s
           = 1 / 0.004
           = 250 RPS per worker
```

```
Per-worker conservative RPS (70% utilization):
RPS_stable = 0.7 * (1 / s)
           = 0.7 * 250
           = 175 RPS per worker
```

```
Aggregate conservative RPS with W workers:
RPS_stable(W) = W * 0.7 * (1 / s)
              = W * 175
```

Conversions:

```
Requests per minute (RPM) = RPS * 60
Requests per hour   (RPH) = RPS * 3600
```

---

## Results

### Capacity Table - Theoretical vs. Conservative (s = 0.004 s)

| Workers (W) | Theoretical RPS | Conservative RPS (≈70%) | Requests / minute (70%) | Requests / hour (70%) |
| ----------: | --------------: | ----------------------: | ----------------------: | --------------------: |
|           1 |             250 |                     175 |                  10,500 |               630,000 |
|           2 |             500 |                     350 |                  21,000 |             1,260,000 |
|           4 |           1,000 |                     700 |                  42,000 |             2,520,000 |
|           8 |           2,000 |                   1,400 |                  84,000 |             5,040,000 |
|          16 |           4,000 |                   2,800 |                 168,000 |            10,080,000 |

**Interpretation:** Even at conservative headroom, 2-4 workers (typical for shared hosting) accommodate ~350-700 RPS (≈ 1.26-2.52 million requests/hour) for this route.

---

## Discussion

### Does "Green by design" hold?

Empirically yes for the evaluated path:

* **CPU time:** ~4 ms per request implies extremely high throughput without resorting to worker/daemon models; PHP-FPM remains sufficient.
* **Memory:** ~0.5 MB peak for the request indicates very low pressure on RAM and cache hierarchies.
* **I/O footprint:** 24 files for a full framework request (including view render) is minimal; OPcache ensures parsing is amortized.

Together, these reduce **energy per request** and increase **requests per watt**, matching the "Green by design" claim for this scenario on modest hardware.

### Why shared hosting strengthens the claim

* It sets a **conservative floor**: if performance is excellent here, it will be at least as good on dedicated hardware.
* **Resource pooling** resembles carpooling: shared infrastructure avoids idle, underutilized machines - a pragmatic sustainability angle.

### On utilization headroom

The choice of 70% utilization as a "stable" operating point is grounded in queueing theory. In a simple M/M/1 model, as the arrival rate λ approaches the service rate μ (= 1/s), waiting times grow rapidly towards infinity. Targeting ~70% of μ (~175 RPS per worker in our case) is therefore a pragmatic balance between efficiency and predictable latency, consistent with common SRE guidelines.

### Fairness vs. other frameworks

Comparisons to other frameworks are outside the scope of this report. We restrict ourselves to absolute, reproducible measurements of CitOmni. Relative benchmarking belongs in separate studies.

### Limits & Threats to Validity

* **Route simplicity:** No DB, no external calls. Any app-level I/O will raise s and reduce RPS. This is by design (framework test, not app test).
* **Worker availability:** Shared hosts may cap workers; our W-scaling is illustrative. Real W should be measured empirically.
* **Burstiness:** RPS figures assume reasonably steady arrival. Violent micro-bursts can still queue; edge/reverse-proxy buffering mitigates this.
* **OPcache setting:** `validate_timestamps=true` was active; using `false` (with safe deploy invalidation) may shave micro-overhead.

These results measure the framework's boot and routing overhead. Any additional I/O (databases, external APIs, heavy templates) will add to service time s. Such effects are application-specific and deliberately excluded here.

---

## Recommendations

1. Publish the baseline in docs: observed 0.004 s, ~0.5 MB, 24 files; include this capacity table with the 70% headroom rationale.
2. Expose `Server-Timing` (`total;dur=...`) and `X-Perf-*` headers in non-prod but prod-like environments for reproducibility.
3. Guard against regressions: track `elapsed_ns`, `mem_peak`, and `files` over time (LiteLog JSONL) and set perf budgets (e.g., +0.2 MB / +5 files per feature).
4. Encourage edge caching: note that anons can be cached at edge/reverse proxy for orders-of-magnitude higher effective throughput.
5. Keep scope crisp in docs: reiterate that this report isolates framework overhead; app developers remain responsible for I/O discipline.

---

## Conclusion

On a minimal but representative front page, **CitOmni's framework path** demonstrates ~175 RPS per worker (conservative) on shared hosting, scaling to ~2,800 RPS at 16 workers. The corresponding hourly capacities are 0.63-10.08 million requests/hour, depending on W.

The combination of low memory footprint and short CPU time implies lower joules per request. Even without hardware energy instrumentation, lower allocations and cycles directly translate to lower energy use, which is the practical meaning of "Green by design".

These results validate that CitOmni's **"Green by design"** proposition is not merely aspirational: the framework imposes near-floor CPU and RAM costs per request on commodity infrastructure.

---

## Reproducibility Checklist

* **Hosting:** one.com (shared).
* **Route:** front page (LiteView¹ + LiteTxt²), no DB, no external calls.
* **Runtime:** PHP-FPM, OPcache enabled (`validate_timestamps=true` during measurement).
  * To ensure reproducibility, tests should be run with debug extensions (xdebug, toolbars) disabled and with OPcache enabled.

* **Instrumentation:**

  * `CITOMNI_START_NS = hrtime(true)` at entry.
  * Elapsed time via `hrtime(true) - CITOMNI_START_NS`.
  * `memory_get_usage()` / `memory_get_peak_usage()`.
  * `count(get_included_files())`.
  * Optional: `Server-Timing: total;dur=...`.
* **Observed metrics (representative):**

  * Execution time ≈ 0.004 s, Mem current ≈ 440,464 B, Mem peak ≈ 526,152 B, Files = 24.
* **Calculus:**

  ```
  RPS_theory = 1 / s
  RPS_stable = 0.7 * (1 / s)
  RPS_stable(W) = W * 0.7 * (1 / s)
  Requests per minute = RPS * 60
  Requests per hour   = RPS * 3600
  ```

---

## Appendix A - Derivations

### Definitions

```
s = service time per request (seconds)
W = number of concurrent PHP-FPM workers
RPS = requests per second
```

### Per-worker theoretical throughput

```
RPS_theory = 1 / s
Example with s = 0.004 s:
RPS_theory = 1 / 0.004 = 250 RPS per worker
```

### Per-worker conservative throughput (70% utilization headroom)

```
RPS_stable = 0.7 * (1 / s)
Example with s = 0.004 s:
RPS_stable = 0.7 * (1 / 0.004) = 0.7 * 250 = 175 RPS per worker
```

### Aggregate conservative throughput with W workers

```
RPS_stable(W) = W * 0.7 * (1 / s)
With s = 0.004 s:
RPS_stable(W) = W * 175
```

### Unit conversions

```
Requests per minute (RPM) = RPS * 60
Requests per hour   (RPH) = RPS * 3600
```

### Worked examples (s = 0.004 s)

```
W = 1:
  RPS = 1 * 175 = 175
  RPM = 175 * 60 = 10,500
  RPH = 175 * 3600 = 630,000

W = 2:
  RPS = 2 * 175 = 350
  RPM = 350 * 60 = 21,000
  RPH = 350 * 3600 = 1,260,000

W = 4:
  RPS = 4 * 175 = 700
  RPM = 700 * 60 = 42,000
  RPH = 700 * 3600 = 2,520,000

W = 8:
  RPS = 8 * 175 = 1,400
  RPM = 1,400 * 60 = 84,000
  RPH = 1,400 * 3600 = 5,040,000

W = 16:
  RPS = 16 * 175 = 2,800
  RPM = 2,800 * 60 = 168,000
  RPH = 2,800 * 3600 = 10,080,000
```


### Note on Queueing Theory:
These throughput calculations implicitly rely on queueing principles.

```
For a simple M/M/1 queue:
  - Service time  s = 0.004 s
  - Service rate μ = 1 / s = 250 rps
  
As arrival rate λ approaches μ, average waiting time W grows towards infinity.
Therefore, a stable operating point is set at ~70% of μ:
  λ ≈ 0.7 * μ = 175 rps per worker
```

This is consistent with Little's Law (L = λ * W) and common SRE guidelines
for preventing latency blow-ups under load.

---

## References
[1] LiteView - Minimal PHP template engine. https://github.com/larsgmortensen/liteview  
[2] LiteTxt - Lightweight i18n text service. https://github.com/larsgmortensen/litetxt
