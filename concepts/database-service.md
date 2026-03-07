# Database Service - CitOmni Infrastructure (v1.0)
*A first-class, deterministic MySQLi service for explicit SQL execution in CitOmni.*

---

**Document type:** Technical Architecture  
**Version:** 1.0  
**Applies to:** CitOmni ≥ 8.2  
**Audience:** Framework developers, provider authors, and advanced application integrators  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** © 2012-present CitOmni

---

## Abstract

CitOmni's database layer is centered on a first-class framework service: `CitOmni\Infrastructure\Service\Db`. It is a deliberately narrow design. The service wraps MySQLi directly, exposes a small set of high-value query and transaction operations, and avoids the abstraction patterns that often dominate modern PHP database stacks: ORMs, query builders, active record layers, metadata-driven mappers, and indirect helper chains.

This design is not a rejection of abstraction in principle. It is a response to CitOmni's architectural priorities. The framework favors deterministic runtime behavior, low overhead, explicit contracts, and fail-fast semantics over flexible but harder-to-reason-about indirection. The database service therefore aims to solve the actual integration problem a framework owns: Establishing a correct connection, applying session policy, executing parameterized queries safely, reusing prepared statements prudently, and providing coherent transaction behavior across ordinary PHP request lifecycles.

The result is not a universal persistence framework. It is a production-oriented database service for applications that want direct SQL, predictable cost, and framework-owned operational semantics.

---

## Why a First-Class Database Service Exists in CitOmni

A framework that claims to be explicit and performance-conscious cannot treat database access as an afterthought. In practice, most PHP applications spend a significant share of their execution time and operational risk budget at the database boundary. That boundary therefore deserves a framework-owned contract rather than an ad hoc collection of external helpers, informal conventions, or package-local utility classes.

CitOmni introduces `Db` as a first-class service for four reasons.

### Architectural ownership

Database access is not merely a library concern. It is part of the framework's runtime contract. Connection lifecycle, session initialization, error classification, transaction handling, and parameter binding strategy all influence correctness and observability across the application. Leaving these concerns fragmented across helpers produces drift. Making them first-class allows CitOmni to define and preserve one coherent behavior model.

### Elimination of accidental complexity

A large part of the modern PHP ecosystem normalizes database stacks that are substantially more abstract than the underlying problem requires. ORMs, fluent builders, entity graphs, change tracking, hydration layers, and metadata caches are useful in some systems, but they also introduce more code paths, more runtime state, and more opportunities for hidden work. CitOmni does not prohibit higher-level patterns at the application level, but it does not build them into the core database layer.

The framework service solves the stable, mechanical problem directly: Connect, prepare, bind, execute, fetch, commit, roll back, and report failure clearly.

### Production realism

CitOmni explicitly targets ordinary production environments, including shared hosting and standard PHP request lifecycles. In such environments, the dominant engineering virtues are not elaborate persistence abstractions. They are operational simplicity, predictable memory use, minimal bootstrap cost, and straightforward debugging. A direct MySQLi service aligns with those realities more closely than a heavier persistence subsystem would.

### Framework consistency

CitOmni's general philosophy is that core facilities should be explicit, deterministic, and owned by the framework. A database layer that behaves like an external convenience wrapper would sit awkwardly beside that philosophy. `Db` is therefore not presented as a side utility. It is part of the framework's own service model and participates in the same expectation of strong contracts and bounded behavior.

---

## Scope and Non-Goals

The `Db` service is intentionally narrow in scope. It is important to understand both what it is designed to do and what it is deliberately not designed to become.

### Scope

The service provides:

* Direct MySQLi-based execution
* Lazy connection establishment
* Early configuration validation
* Prepared statement execution with automatic scalar type binding
* A bounded prepared statement cache
* Read helpers for common query patterns
* Write helpers for common insert, update, and delete patterns
* Raw-query escape hatches for cases where prepared helpers are not the right tool
* Explicit transaction primitives and transactional callbacks
* A dedicated exception hierarchy separating connection failures from query failures
* Support for both mysqlnd result handling and a no-mysqlnd fallback path

The service is therefore a practical execution layer for SQL-centric applications.

### Non-goals

The service does not aim to provide:

* An ORM
* An identity map
* Unit-of-work tracking
* A query builder DSL
* Schema modeling
* Migration orchestration
* Database portability across vendors
* Transparent relation loading
* Domain model hydration beyond simple associative arrays
* Automatic repository generation

These omissions are not incomplete future work. They are part of the design. CitOmni treats SQL as a first-class implementation language for persistence rather than something the framework must conceal.

---

## Architectural Position in CitOmni

`Db` lives in `citomni/infrastructure`. That placement is architecturally significant.

CitOmni distinguishes between core framework behavior, mode-specific runtime packages, and provider-level infrastructure. The database service belongs to infrastructure because it represents an external system boundary with operational semantics of its own. It is neither a transport concern nor merely an application-local helper. It is a reusable infrastructure contract owned by the framework.

As a first-class service, `Db` is intended to be resolved like other CitOmni services. This matters for two reasons.

First, it gives database access a stable and predictable place in the framework's runtime graph. Consumers do not need to invent local connection factories or package-specific wrappers just to establish ordinary SQL access.

Second, it allows the framework to preserve one consistent lifecycle model. Configuration is read once, validated once, and then treated as resolved runtime state. The service does not repeatedly re-interpret configuration during query execution. This is consistent with CitOmni's broader preference for deterministic startup and minimal runtime ambiguity.

The service also replaces the older LiteMySQLi-centered approach as the preferred CitOmni-native database layer. That replacement is not primarily about adding more features. It is about bringing database behavior under a clearer framework contract.

---

## Why MySQLi and Not a Higher-Level Abstraction

CitOmni's choice of a direct MySQLi service is deliberate and should be read as an architectural position, not merely a legacy preference.

### Alignment with actual target environments

CitOmni targets PHP 8.2+ applications running in ordinary web-hosting and server environments where MySQL-compatible databases are common and request lifecycles are short-lived. In that context, MySQLi is not an exotic low-level dependency. It is the native and practical interface to the actual database system being used.

### Predictable cost model

Every abstraction layer changes the cost model of database work. ORMs may introduce implicit queries, query builders may generate SQL whose final form is less obvious at the call site, and metadata-driven layers may add parsing, hydration, and caching overhead that is difficult to inspect from the application code alone.

A direct MySQLi wrapper keeps the cost surface near the SQL. The developer writes SQL, binds parameters, and receives rows or affected counts. The runtime work is correspondingly legible.

### Easier failure reasoning

Database failures are easier to reason about when the mapping between operation and executed SQL is close to one-to-one. When many framework layers intervene, error causality becomes diffuse. CitOmni instead favors a design in which connection errors remain connection errors, query errors remain query errors, and the executed SQL remains visible in failure context without exposing raw bound values.

### Avoidance of false generality

Database abstraction is often defended in the name of portability. In practice, many applications remain tied to one engine family for their entire operational life. Building a core database layer around hypothetical portability can therefore impose permanent complexity for a benefit that never materializes. CitOmni chooses to optimize for the concrete system it actually targets.

This does not mean higher-level abstractions are forbidden at the application level. It means the framework's owned database contract does not assume they are universally beneficial.

---

## Lifecycle and Initialization Model

The lifecycle semantics of `Db` are central to the design. The service is intentionally split into an initialization phase and an execution phase, with clear boundaries between them.

### Configuration validation in `init()`

The service reads and validates its configuration in `init()`. This is an eager step. The service resolves the database configuration node, applies service options with defined precedence, validates required fields, normalizes optional fields, and determines cache policy before any connection is opened.

This has several consequences.

* Configuration errors fail early.
* Query execution does not repeatedly inspect or reinterpret configuration.
* The runtime state after initialization is simpler because the service holds resolved scalar settings rather than a live dependency on configuration structure.
* Misconfiguration is classified as a connection-level concern rather than being deferred into unrelated query paths.

The password key is intentionally named `pass`. This is a small but important detail because it reflects the actual framework contract. Precision in naming matters when the framework aims to be explicit rather than conventionally vague.

### Lazy connection establishment

Although configuration is validated eagerly, the physical connection is established lazily. No connection is opened in `init()`. The connection is created only on first meaningful database use.

This lazy model avoids unnecessary work for requests that instantiate the service but never perform SQL operations. In ordinary PHP request lifecycles, that distinction is often materially useful. It reduces needless connection attempts, avoids startup latency for non-database code paths, and keeps the service cheap to construct.

Lazy connection, however, is not treated as a magical convenience. The service draws a strict distinction between operations that are meaningful first uses of the database and operations that are not.

A query, statement preparation, or explicit `beginTransaction()` is a meaningful first use. A `commit()` or `rollback()` is not.

### Atomic connection ownership

The service does not assign the connection to its internal state until the connection is fully initialized. This is an important semantic guarantee.

Connection establishment consists of more than calling `real_connect()`. The service must also set charset and apply session settings. Only after all of those steps succeed does the service adopt the connection as its owned active connection.

If any step fails, the temporary connection is closed best-effort and the service remains in the same state it was in before the attempt: No active connection. This prevents half-initialized runtime state. In other words, the service is designed to occupy one of two conditions only:

* No active connection
* A fully initialized connection

It must not remain in an indeterminate middle state.

This pattern reflects CitOmni's broader bias toward atomic state transitions and fail-fast semantics.

### Session setup behavior

After successful low-level connection establishment, the service applies session settings. Two kinds of session policy are supported conceptually:

* An explicit `sql_mode`, if configured
* A session `time_zone`

If an explicit framework configuration supplies a timezone, that value is applied. Otherwise, the service derives a UTC offset from PHP's active timezone and applies that derived offset to the session. This preserves coherence between PHP-side temporal behavior and database session behavior without requiring every application to duplicate the same logic at query sites.

The important design point is that session setup is part of connection initialization, not a scattered application concern. It therefore happens once per connection, under one owned framework contract.

---

## Read and Write Model

The surface API offered by `Db` is intentionally broad enough to cover common operational needs without drifting into a full persistence framework.

Read-side support includes:

* `fetchValue()`
* `fetchRow()`
* `fetchAll()`
* `countRows()`
* `exists()`
* `select()`
* `selectNoMysqlnd()`

Write-side support includes:

* `execute()`
* `executeMany()`
* `insert()`
* `insertBatch()`
* `update()`
* `delete()`

In addition, the service provides raw-query escape hatches:

* `queryRaw()`
* `queryRawMulti()`

This API shape is worth noting for what it implies. CitOmni does not attempt to hide SQL. Instead, it recognizes that application code commonly needs a small number of recurrent query patterns and that these patterns benefit from framework-owned correctness around parameter binding, statement reuse, identifier quoting in specific helper cases, and error translation.

The result is a service that is low-level in one sense, because it remains SQL-centric, but not primitive. It standardizes the mechanical parts that should not be reimplemented repeatedly across applications.

---

## mysqlnd and the No-mysqlnd Fallback

CitOmni acknowledges a practical deployment fact: Not all environments expose mysqlnd uniformly, yet a framework-level database service still has to remain usable across ordinary hosting conditions.

The primary `select()` path assumes mysqlnd-style result handling and returns a `mysqli_result`. That is the preferred mode because it is ergonomically straightforward and supports the familiar buffered result workflow.

At the same time, the service includes `selectNoMysqlnd()` as a fallback path for environments where that result model is unavailable. This fallback yields rows through a generator rather than presenting the same result interface.

Architecturally, this is a pragmatic compromise rather than an abstraction leak. CitOmni does not pretend the underlying runtime capabilities are identical when they are not. Instead, it exposes a second path that preserves service usefulness under constrained environments while keeping the preferred path simple.

This is consistent with the framework's general philosophy: Accept real platform differences, but handle them explicitly rather than obscuring them behind a misleadingly uniform abstraction.

---

## Statement Reuse and Cache Discipline

Prepared statements are central to both safety and performance. They reduce repeated SQL parsing cost on the server side and provide a structured boundary for parameterized execution. However, statement reuse is not free. Cached statements consume memory and must be managed carefully.

CitOmni therefore uses a bounded prepared statement cache.

### Why a bounded cache exists

In short-lived PHP request lifecycles, repeated preparation of identical statements can still be wasteful, especially in applications that execute the same few query templates several times within a request or process. Reusing prepared statements can reduce repeated driver and server work.

At the same time, an unbounded cache would be structurally irresponsible. It would turn statement reuse into a source of hidden memory growth and increasingly opaque runtime state. CitOmni does not accept that trade-off.

### Bounded reuse as policy

The service therefore adopts a cache with an explicit upper limit. The limit may be configured, and a limit of zero disables caching entirely. This makes statement reuse a policy decision with a defined ceiling rather than an uncontrolled side effect.

A bounded cache matches CitOmni's preference for explicit resource discipline. It allows the framework to capture the common performance benefit of reuse while preventing silent accumulation of statement objects.

### Eviction semantics

The cache uses a simple FIFO-style eviction discipline. That choice is conceptually significant. CitOmni does not attempt to implement a sophisticated adaptive cache policy at this layer. Doing so would increase code complexity, introduce more runtime bookkeeping, and likely produce marginal benefit in the intended short-lived execution model.

A simple bounded cache with predictable eviction behavior is easier to reason about than a more elaborate strategy. That is often the better engineering choice in framework core code.

### Statement state hygiene

Reusing prepared statements safely requires more than keeping them alive. Any residual result state from prior executions must be cleared before reuse. The service therefore treats statement reuse as an operation that includes state cleanup, not merely statement retrieval.

This matters because cached statements are live driver objects, not inert templates. A framework that caches them without disciplined cleanup risks subtle correctness failures. CitOmni explicitly incorporates that cleanup into the reuse model.

---

## Transaction Semantics

Transaction behavior is an area where loose framework semantics can easily produce misleading or dangerous behavior. CitOmni therefore makes transaction handling intentionally strict.

### Explicit primitives

The service exposes the usual explicit primitives:

* `beginTransaction()`
* `commit()`
* `rollback()`

It also provides:

* `transaction()`
* `easyTransaction()`

The callback form exists to standardize the common commit-on-success, rollback-on-failure pattern, but it does not replace the primitive operations. This reflects CitOmni's bias toward preserving explicit control while still offering a disciplined convenience for a common structure.

### Why `beginTransaction()` may open a lazy connection

Beginning a transaction is a meaningful first database operation. If no connection exists yet, opening one in order to start a transaction is coherent. The service therefore allows `beginTransaction()` to trigger lazy connection establishment.

This is not merely allowed; it is architecturally correct. A transaction cannot exist independently of a connection, and opening the connection is directly in service of the requested operation.

### Why `commit()` and `rollback()` must not open a lazy connection

The same logic does not apply to `commit()` and `rollback()`.

If no connection exists, then there is no existing transactional context to commit or roll back. Opening a brand new connection merely to perform `commit()` or `rollback()` would produce a formally valid method call but a semantically meaningless operation. Worse, it would hide a state error behind a superficially successful runtime path.

CitOmni rejects that behavior. `commit()` and `rollback()` therefore require an already active connection. Their strictness protects semantic clarity. The service refuses to pretend that a transaction exists when the runtime state shows that it does not.

This is a good example of CitOmni's fail-fast principle applied to a subtle lifecycle issue. The design prefers an explicit error over a no-op that masks a logic defect.

### Safe defaults before connection exists

Several metadata-oriented methods return safe neutral values when no connection has yet been opened. Examples include zero-like or null-like answers for last insert id, affected row count, and last error accessors.

This is appropriate because those methods are observational rather than state-changing. Returning neutral values in the absence of a connection is clearer than forcing connection establishment merely to answer a question about non-existent prior activity.

The contrast with `commit()` and `rollback()` is intentional. Observation may safely reflect the absence of activity. Transaction finalization may not invent the state it needs.

### Transaction callback behavior

The callback-based `transaction()` method is designed with explicit failure semantics. Success results in commit. Callback failure results in rollback attempt. If rollback itself fails, the service surfaces that compounded failure rather than silently discarding one error in favor of the other.

This matters because rollback failure is operationally significant. A framework that suppresses it in order to preserve a simpler control-flow story would make diagnosis harder precisely when the system is already in an error state. CitOmni instead preserves failure information explicitly.

### No hidden nested transaction model

The service does not claim to implement true nested transactions. This is a necessary constraint, especially given MySQL's transactional model. Where internal batching logic needs to own a transaction for correctness, the service treats nested transactional contexts as something to guard against rather than something to simulate loosely.

This is the right trade-off for a framework that values explicit behavior over attractive but misleading convenience.

---

## Exception Model

CitOmni's database service uses a dedicated exception hierarchy:

* `DbException`
* `DbConnectException`
* `DbQueryException`

This split is small but important.

### Why a dedicated hierarchy exists

A framework-owned database layer should not leak only generic driver exceptions into the rest of the application. Doing so would couple application code too directly to low-level extension behavior and would weaken the ability to reason about failures at the architectural level.

By defining a dedicated database exception family, CitOmni creates a stable framework contract for callers while still preserving chained underlying causes where relevant.

### Why connection and query failures are separated

Connection/session initialization failures and query execution failures are not the same class of problem.

A connection failure indicates that the database service could not establish or complete a usable session. Typical causes include unreachable host configuration, authentication failure, invalid session initialization commands, or related startup problems.

A query failure indicates that a usable connection existed or was meaningfully attempted, but a specific statement failed to prepare, bind, execute, or otherwise complete correctly.

This separation improves clarity in three ways.

First, it aligns exception type with operational phase.

Second, it allows higher-level code to distinguish infrastructure availability problems from SQL or data-shape problems when that distinction matters.

Third, it keeps failure classification faithful to the service lifecycle model. Because session setup is part of connection initialization, a failure in session setup is correctly treated as a connection-level error, not a query-level error.

### Error context without unsafe value leakage

The query exception model includes SQL context and parameter type information, but intentionally avoids embedding raw bound values. This is a subtle but important design choice. Runtime values may contain secrets, personal data, or other sensitive material that should not end up in logs or exception traces. Parameter types usually provide enough diagnostic value to understand binding mismatches and placeholder problems without disclosing the data itself.

This reflects a broader CitOmni pattern: Preserve diagnostic usefulness, but do not normalize unsafe verbosity.

---

## Relationship to LiteMySQLi

The new `Db` service replaces the older LiteMySQLi-centered approach as the preferred CitOmni-native database layer. That relationship should be understood conceptually rather than as a migration checklist.

LiteMySQLi represented a useful earlier approach to practical SQL access, especially in environments where small wrappers around MySQLi were common and often preferable to heavier libraries. The new `Db` service retains some of that pragmatic directness. It remains SQL-first, avoids ORM patterns, and values simple operational behavior.

The difference is that `Db` is now articulated as a framework-owned service with clearer lifecycle, error, and transaction semantics.

Several architectural improvements follow from that shift.

* Configuration is validated once in service initialization rather than being treated as a looser runtime concern.
* Lazy connection is retained, but with stricter semantics around when it may and may not occur.
* Statement reuse is formalized through a bounded cache rather than left as an incidental implementation detail.
* Exception classification is clearer and better aligned with service phases.
* Session setup and connection ownership are defined as atomic parts of service behavior.
* The service is positioned explicitly within `citomni/infrastructure`, making it part of the framework architecture rather than an external-style helper pattern.

The important point is not that the old approach was wrong. It is that the new service defines a more coherent contract for what CitOmni itself considers its native database layer.

---

## Performance Characteristics

CitOmni's database service is performance-conscious, but its performance claims should be understood in the sober way appropriate for framework architecture.

### What the design optimizes for

The service is designed to reduce avoidable overhead in several concrete ways:

* No connection is opened unless real database work occurs
* Configuration is resolved once, not repeatedly
* Prepared statements can be reused within bounded limits
* Query helpers avoid unnecessary layers between call site and driver
* Result handling remains close to MySQLi rather than passing through heavy hydration machinery
* Transaction helpers standardize a common pattern without requiring elaborate orchestration

These are meaningful optimizations because they remove unnecessary work from ordinary request execution.

### What the design does not claim

The service does not claim that MySQLi alone guarantees superior performance in every imaginable application. Performance depends on workload shape, query quality, indexing, network latency, result sizes, host configuration, and deployment environment.

CitOmni's position is narrower and more defensible: A direct MySQLi service with limited abstraction overhead gives the framework a simpler and more predictable runtime profile than heavier persistence stacks would typically provide for the same class of application.

### Shared hosting and ordinary request lifecycles

The intended operating environment matters. In short-lived request models, extreme internal sophistication in the persistence layer often yields diminishing returns. The dominant wins tend to come from avoiding needless work, limiting allocations, preventing redundant setup, and making failure diagnosis cheap.

The design of `Db` maps well to those realities. It does not try to win by being clever. It tries to win by remaining lean, explicit, and disciplined.

---

## Design Trade-Offs

No serious framework design is cost-free. CitOmni's database service makes several deliberate trade-offs.

### Benefits accepted by the design

* Lower abstraction overhead
* Clearer lifecycle semantics
* More direct control over SQL behavior
* Stronger correspondence between source code and runtime work
* Easier operational debugging
* Reduced risk of hidden queries or hidden persistence state

### Costs accepted by the design

* Less convenience for developers who prefer abstraction-heavy persistence APIs
* No built-in database-vendor portability story
* More SQL remains visible in application code
* Some differences in runtime capability, such as mysqlnd availability, remain explicit rather than fully abstracted away
* Higher-level repository or domain patterns must be built intentionally by the application rather than assumed from framework core

These are not accidental limitations. They are the predictable cost of optimizing for explicitness and determinism.

### Why those trade-offs are coherent for CitOmni

CitOmni is not attempting to be a maximal-framework ecosystem that supplies every style of persistence model equally well. It is a framework that prioritizes predictable behavior, low overhead, and framework-owned contracts. Within that philosophy, a narrow and disciplined database service is more coherent than a broader but less predictable abstraction stack.

The key architectural judgment is that a framework core should only abstract what it can own clearly. CitOmni can own connection lifecycle, session policy, statement reuse, query execution discipline, and transaction semantics clearly. It would own a full persistence abstraction less clearly, at higher cost, and with weaker alignment to its core principles.

---

## Summary

`CitOmni\Infrastructure\Service\Db` is a first-class database service designed around CitOmni's actual architectural priorities: Explicit contracts, deterministic behavior, low runtime overhead, fail-fast semantics, and production realism.

It exists because database access is too central to leave as an informal helper concern, yet too performance- and correctness-sensitive to bury under unnecessary abstraction. The service therefore adopts a direct MySQLi-based design, validates configuration eagerly, opens connections lazily, owns connection state atomically, applies session settings as part of initialization, reuses prepared statements within bounded limits, and enforces strict transaction semantics where semantic ambiguity would otherwise be easy to tolerate.

Its exception model distinguishes connection failures from query failures because those are different operational events. Its fallback support for non-mysqlnd environments reflects practical deployment constraints without pretending those constraints do not exist. Its relationship to LiteMySQLi is evolutionary: It preserves pragmatic directness while placing the database layer under a clearer framework-owned contract.

The service is not trying to be a universal persistence abstraction. It is trying to be a correct and efficient database boundary for the kinds of PHP applications CitOmni is built to serve. In that respect, its narrowness is not a deficiency. It is the design.

---

**In essence:**

> *Direct SQL, bounded behavior, framework-owned semantics.*
> CitOmni's database service does not abstract the database away. It defines the contract for meeting it correctly.