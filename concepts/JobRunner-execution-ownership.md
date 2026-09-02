# CitOmni JobRunner Execution Ownership Architecture - Token/push and trusted/pull execution

**Package:** `citomni/jobrunner`  
**Document type:** Architecture and implementation guide  
**Status:** Implemented baseline  
**Applies to:** Token/push and trusted/pull execution modes

---

## Abstract

`citomni/jobrunner` provides durable execution of long-running CitOmni jobs with explicit lifecycle state, logging, progress, heartbeat, cancellation and guarded terminal transitions.

The original execution model is **token/push**:

```text
enqueue
-> mint worker token
-> persist token hash
-> launch dedicated worker
-> worker atomically claims queued job
-> execute handler
-> persist terminal state
```

This model is intentionally retained.

A second execution model, **trusted/pull**, extends JobRunner for environments where the process that enqueues work must not itself determine or inherit the final execution identity. In trusted/pull mode, enqueue and execution are separated:

```text
enqueue trusted job
-> persistent trusted supervisor discovers queued work
-> supervisor prepares an ephemeral handoff capability
-> supervisor starts one fresh PHP child
-> child atomically claims queued job using the handoff capability
-> child executes the same shared lifecycle as token workers
-> child exits
-> supervisor continues
```

The central architectural property is that the persistent supervisor **does not execute application handlers in-process**. Every actual job still runs in a fresh PHP process. This preserves per-job runtime isolation while allowing long-lived trusted queue supervision.

The design deliberately keeps the two ownership protocols disjoint, keeps the actual `queued -> running` transition in the concrete child process, and retains capability-based worker ownership in both modes.

The result is a small extension of JobRunner rather than a transformation into a general-purpose queue, daemon framework or distributed worker system.

---

# 1. Scope

This document describes the trusted/pull execution model added to `citomni/jobrunner`, together with the architectural relationship to the existing token/push model.

It covers:

- ownership and claim semantics;
- schema requirements;
- trusted queue discovery;
- ephemeral handoff capabilities;
- supervisor behavior;
- fresh child-process execution;
- shared execution lifecycle;
- cancellation races;
- dispatch races;
- crash recovery before claim;
- process isolation;
- queue ordering;
- security boundaries;
- payload trust;
- polling behavior;
- implementation layering;
- practical integration;
- testing and verification.

This document does **not** define:

- application-specific deployment behavior;
- credential storage formats;
- operating-system scheduler configuration;
- Git, SSH or remote transport policy;
- generic retry semantics;
- parallel worker pools;
- distributed leases;
- multi-host queue coordination.

Those concerns may motivate trusted execution, but they are outside JobRunner's core contract.

---

# 2. Design goals

The trusted/pull model was introduced under a set of strict requirements.

## 2.1 Preserve the existing token/push model

Existing consumers must continue to be able to execute:

```text
StartJob
-> JobLauncher
-> job:run
-> token claim
-> handler
-> terminal state
```

Trusted execution is additive.

It must not weaken or overload the existing token ownership gate.

---

## 2.2 Separate enqueue identity from execution identity

The process that creates a job may be different from the process that should execute it.

JobRunner therefore needs a mode in which:

```text
producer
```

can create durable work without immediately spawning the final executor.

Execution is delegated to a separately running trusted supervisor.

---

## 2.3 Preserve fresh-process isolation per job

A persistent worker loop must not silently change the effective runtime contract from:

```text
fresh PHP process per job
```

to:

```text
one PHP process executes jobs indefinitely
```

Application handlers may indirectly depend on process-local isolation through:

- memoized services;
- static state;
- mutable handler state;
- DB connection/session state;
- third-party library globals;
- filesystem/process environment mutations;
- runtime caches.

The trusted supervisor therefore performs queue orchestration only.

Actual handler execution remains disposable.

---

## 2.4 Preserve capability-based execution ownership

Trusted mode must not mean:

> Any process may execute any trusted job.

A concrete child must still prove that it has been authorized for the current dispatch.

The system therefore retains an opaque per-dispatch capability.

---

## 2.5 Keep lifecycle timestamps semantically accurate

`started_at` should mean:

> A concrete execution process successfully acquired the job and began its execution lifecycle.

`attempts` should mean:

> Number of successful execution claims.

They must not count:

- queue observation;
- dispatch preparation;
- stale token attempts;
- supervisor intent;
- failed pre-claim launches.

---

## 2.6 Keep V1 operationally small

The model assumes one trusted supervisor.

Therefore V1 intentionally avoids:

- `SELECT ... FOR UPDATE SKIP LOCKED`;
- distributed leases;
- reservation daemons;
- multiple-worker fairness;
- retry backoff frameworks;
- worker heartbeats separate from job heartbeats;
- automatic parallelism.

The architecture should remain easy to reason about.

---

# 3. Existing token/push model

The original JobRunner flow is capability-oriented.

A job is created in token claim mode.

Conceptually:

```text
producer
   │
   ▼
StartJob
   │
   ├── validate job type
   ├── encode payload
   ├── enforce active lock semantics
   ├── create UUID
   ├── mint raw worker token
   ├── persist SHA-256(token)
   └── launch fresh job:run child
                       │
                       ▼
                RunQueuedJob
                       │
                       ▼
                 claimQueued()
                       │
                       ▼
                 queued -> running
                       │
                       ▼
             shared execution lifecycle
```

The raw worker token is never persisted.

Only:

```text
SHA-256(raw token)
```

is stored.

The child must present the matching token before it can move the job out of `queued`.

This is an ownership gate, not merely an authentication decoration.

---

# 4. Why trusted/pull is a separate mode

A simplistic extension would be to add a repository method such as:

```text
claimNextQueued()
```

and let a persistent worker claim arbitrary queued rows.

That would be unsafe because token-mode jobs are specifically created for a dedicated worker.

A pull worker must never steal a token-mode job while that job is waiting for its intended child.

The queue therefore needs a persisted ownership discriminator.

The adopted model is:

```text
claim_mode = token
```

or:

```text
claim_mode = trusted
```

This is not merely descriptive metadata.

It is part of the claim predicate itself.

---

# 5. Persisted claim mode

The bounded claim modes are represented by:

```php
enum ClaimMode: string {
	case TOKEN = 'token';
	case TRUSTED = 'trusted';
}
```

The intended semantics are:

## 5.1 Token mode

```text
claim_mode = token
worker_token_hash = SHA-256(raw worker token)
```

The dedicated push child may claim only if it presents the matching token.

## 5.2 Trusted mode before dispatch

```text
claim_mode = trusted
worker_token_hash = NULL
```

The job is eligible for discovery by the trusted supervisor.

It has not yet been assigned a concrete execution child.

## 5.3 Trusted mode after dispatch preparation

```text
claim_mode = trusted
worker_token_hash = SHA-256(ephemeral handoff token)
```

The job remains `queued`.

The stored hash now identifies the currently authorized child capability.

The concrete child still has to win the atomic claim.

---

# 6. Why `worker_token_hash` is nullable

Trusted jobs do not possess a child capability when first enqueued.

Using an artificial placeholder such as:

```text
000000...
```

would be semantically misleading.

The correct representation is:

```text
NULL
```

until dispatch preparation.

However, `NULL` is **not** itself the trusted discriminator.

That distinction matters because after preparation a trusted queued row carries a real hash.

Therefore:

```text
claim_mode
```

answers:

> Which ownership protocol governs this row?

while:

```text
worker_token_hash
```

answers:

> Which concrete worker capability, if any, is currently authorized?

---

# 7. Schema model

The relevant job schema contains:

```text
status
claim_mode
worker_token_hash
created_at
queued_at
started_at
heartbeat_at
finished_at
attempts
```

The trusted addition requires:

```text
claim_mode         NOT NULL
worker_token_hash  NULLABLE
```

The bounded discriminator should be constrained to:

```text
token
trusted
```

A useful database invariant is:

```text
claim_mode = token
-> worker_token_hash IS NOT NULL
```

The inverse must **not** be enforced:

```text
claim_mode = trusted
-> worker_token_hash IS NULL
```

because a trusted job receives a temporary hash during dispatch preparation while remaining queued.

---

# 8. Trusted queue index

Trusted discovery is deterministic:

```text
WHERE claim_mode = 'trusted'
  AND status = 'queued'
ORDER BY created_at ASC, id ASC
LIMIT 1
```

The index therefore follows the lookup shape:

```text
(claim_mode, status, created_at, id)
```

The `id` tie-breaker matters because multiple rows can theoretically share the same microsecond timestamp.

The ordering contract is therefore:

```text
oldest created_at first
then lowest id
```

This provides deterministic FIFO behavior without introducing a separate sequence column.

---

# 9. Migration strategy

For an existing JobRunner installation, the safe migration order is:

1. add `claim_mode` with temporary default `token`;
2. let existing rows become token-mode rows;
3. make `worker_token_hash` nullable;
4. add claim-mode constraint;
5. add token-hash constraint;
6. add trusted queue index;
7. remove the temporary default from `claim_mode`.

The canonical fresh-install schema should require explicit claim mode.

That keeps ownership semantics visible at the call site.

---

# 10. Shared enqueue primitive

Token/push and trusted/pull jobs share substantial creation logic:

- job-type validation;
- handler existence validation;
- title validation;
- lock-key validation;
- active-lock fast path;
- payload encoding;
- UUID generation;
- duplicate active-lock race handling;
- durable queued-row creation.

Duplicating this logic would create two subtly diverging queue semantics.

A shared enqueue operation therefore owns common creation behavior.

Conceptually:

```text
EnqueueJob
    │
    ├── validate
    ├── handler check
    ├── active-lock check
    ├── encode payload
    ├── UUID
    ├── create queued row
    └── normalize result
```

Token/push adds:

```text
mint worker token
-> EnqueueJob(TOKEN, token hash)
-> detached launch
```

Trusted/pull adds:

```text
EnqueueJob(TRUSTED, NULL)
-> no launch
```

This preserves one definition of what it means to create a valid JobRunner job.

---

# 11. Active-lock semantics

The active-lock design remains unchanged.

A friendly pre-check may observe:

```text
active job already exists for lock_key
```

and return an already-active result.

This pre-check is not authoritative.

The generated unique active-lock key remains the race guard.

Two concurrent producers may both pass the pre-check.

The database unique constraint resolves the race.

The loser then re-reads the active row and returns the deterministic already-active result when appropriate.

Trusted execution does not weaken this invariant.

---

# 12. Trusted enqueue semantics

A trusted enqueue must create a row equivalent to:

```text
status = queued
claim_mode = trusted
worker_token_hash = NULL
attempts = 0
started_at = NULL
heartbeat_at = NULL
finished_at = NULL
```

No worker is launched.

No execution attempt has occurred.

No child capability exists yet.

That state is intentional and complete.

A trusted queued job is durable work waiting for a trusted dispatcher.

---

# 13. Persistent supervisor model

The trusted supervisor runs through:

```text
job:work
```

Its responsibility is intentionally narrow.

It performs:

```text
find
-> prepare
-> launch
-> wait
-> repeat
```

It does **not**:

- execute handlers;
- decode payloads;
- build `JobContext`;
- choose terminal state;
- contain SQL;
- persist application results;
- perform arbitrary process execution.

The supervisor is therefore long-lived without becoming an application-runtime container.

---

# 14. Why the supervisor is persistent

A persistent supervisor avoids coupling queue producers to process launch.

The producer only needs to persist work.

The supervisor remains available and periodically checks for trusted jobs.

This separation provides:

- simpler producers;
- no per-job scheduler triggering protocol;
- no process-launch identity inheritance from producer;
- deterministic single-worker queue draining;
- immediate continuation while the queue is non-empty.

The persistent process is cheap because idle behavior is:

```text
SELECT next trusted queued job
-> none
-> sleep
```

---

# 15. Idle polling policy

V1 uses a fixed idle sleep:

```text
3 seconds
```

The rule is:

```text
queue empty
-> sleep(3)
```

After a child exits:

```text
check queue immediately
```

There is no intentional delay between queued jobs.

This produces two desirable properties:

1. idle overhead is negligible;
2. burst workloads drain without artificial three-second gaps.

No adaptive backoff is required.

The local cost of one indexed queue lookup every few seconds is insignificant.

---

# 16. Why V1 uses one trusted supervisor

Single-worker execution makes the correctness model dramatically simpler.

There is no competition between trusted supervisors for queue order.

The remaining races are with lifecycle events such as cancellation and stale dispatch.

Those are already handled through guarded row transitions.

Parallel workers would introduce additional concerns:

- contention;
- fairness;
- cross-worker queue selection;
- lock ordering;
- project-level serialization;
- possible `SKIP LOCKED` strategy.

None is needed for the current requirement.

---

# 17. The rejected in-process execution model

A tempting design is:

```text
job:work
-> find
-> claim
-> execute handler in same process
-> repeat
```

This is compact but changes the runtime isolation contract.

A persistent PHP process retains:

- the `App` object;
- memoized services;
- `JobRegistry` cache;
- DB connection;
- static variables;
- third-party process state;
- environment changes;
- mutable handler fields;
- global extension state.

Resetting one handler object is not enough.

A fresh handler does not reset:

```text
App services
DB session
statics
libraries
process environment
```

The architecture therefore rejects in-process application execution.

---

# 18. Supervisor + fresh child

The accepted model is:

```text
persistent supervisor
    │
    ├── queue orchestration only
    │
    └── fresh child for each real job
```

Each child receives:

- fresh PHP runtime;
- fresh `App`;
- fresh services;
- fresh `JobRegistry`;
- fresh handler instance;
- fresh process-local state.

The child terminates after the job reaches its terminal outcome.

This is process isolation by construction rather than convention.

---

# 19. Why the supervisor does not claim the job

A superficially convenient design would be:

```text
supervisor
-> queued -> running
-> spawn child
```

This was rejected.

The transition:

```text
queued -> running
```

must remain the moment actual execution ownership is acquired.

If the supervisor marked a job running before child creation, then:

```text
started_at
```

would no longer mean actual worker start.

Likewise:

```text
attempts++
```

would count dispatch attempts rather than execution attempts.

That would degrade observability and complicate crash recovery.

---

# 20. Child-owned claim invariant

The adopted invariant is:

> The process that will execute the handler must itself win `queued -> running`.

The supervisor may prepare authorization.

It may not fabricate execution state.

Therefore the trusted flow is:

```text
supervisor observes queued
-> supervisor prepares handoff hash
-> row remains queued
-> child starts
-> child presents raw handoff token
-> child atomically claims
-> only then status becomes running
```

This preserves semantic parity with the existing token worker.

---

# 21. Ephemeral handoff capability

Trusted mode still uses capability-based ownership.

The difference from token/push is temporal.

## Token/push

The capability is minted at enqueue.

## Trusted/pull

The capability is minted at dispatch.

The supervisor generates a cryptographically random raw handoff token.

It persists only:

```text
SHA-256(handoff token)
```

The raw token is passed only to the fresh trusted child.

The token is:

- not an application credential;
- not a remote credential;
- not a long-lived secret;
- not stored in plaintext;
- not returned to the caller;
- not logged.

Its only purpose is to prove that the child was authorized for this dispatch.

---

# 22. Dispatch preparation

The supervisor prepares a trusted dispatch with a guarded write:

```text
id = selected job
status = queued
claim_mode = trusted
```

It writes:

```text
worker_token_hash = SHA-256(handoff token)
updated_at = now
```

It deliberately does **not** modify:

```text
status
started_at
heartbeat_at
attempts
```

A successful preparation means:

> This queued trusted job now authorizes a specific fresh child capability.

It does not mean:

> Execution has begun.

---

# 23. Why prepared trusted rows remain discoverable

Queue discovery intentionally does **not** require:

```text
worker_token_hash IS NULL
```

A trusted queued row may contain a stale handoff hash after:

```text
prepare
-> supervisor crash
```

If discovery filtered only null hashes, such a row could become permanently stranded.

The correct eligibility predicate remains:

```text
status = queued
claim_mode = trusted
```

A future supervisor can safely overwrite the old handoff hash.

---

# 24. Handoff replacement as fencing

Suppose:

```text
dispatch A
-> token A prepared
-> supervisor crashes
```

The row remains queued.

Later:

```text
dispatch B
-> token B prepared
```

The database now contains:

```text
SHA-256(token B)
```

The old token A is invalid.

If an old delayed child later starts with A:

```text
claimTrustedQueued()
```

fails.

This is a lightweight fencing mechanism.

It solves the pre-claim crash window without introducing:

- leases;
- reservation expiry;
- worker ids;
- separate lock tables.

The newest prepared capability wins while the job remains queued.

---

# 25. Trusted atomic claim

The concrete trusted child hashes its raw handoff token and performs a guarded update.

Conceptually:

```sql
UPDATE jobrun_jobs
SET
	status = 'running',
	started_at = :now,
	heartbeat_at = :now,
	attempts = attempts + 1,
	updated_at = :now
WHERE
	id = :job_id
	AND status = 'queued'
	AND claim_mode = 'trusted'
	AND worker_token_hash = :handoff_hash
```

The child may execute the handler only if:

```text
affected rows = 1
```

This is the trusted ownership gate.

---

# 26. Exactly-one winning claim

The guarded transition has several useful properties.

## 26.1 Two children with the same valid token

Both may race.

Only one sees:

```text
status = queued
```

and changes it to:

```text
running
```

The second update affects zero rows.

Only the winner executes.

## 26.2 Old child with stale token

Hash mismatch.

No claim.

## 26.3 Replay after completion

Status is no longer queued.

No claim.

## 26.4 Token worker against trusted job

`claim_mode = token` predicate fails.

No claim.

## 26.5 Trusted worker against token job

`claim_mode = trusted` predicate fails.

No claim.

The two ownership protocols are therefore hard-separated at persistence level.

---

# 27. Exactly-once claim is not universal exactly-once side effects

It is important to state the guarantee precisely.

JobRunner provides:

> Exactly one successful ownership transition from `queued` to `running` for a given job attempt.

It does not magically provide distributed exactly-once semantics for arbitrary external side effects.

Once a handler has begun:

```text
remote deploy
API call
filesystem mutation
email
external process
```

a process crash may occur after the external side effect but before terminal persistence.

Application handlers must therefore still be designed with appropriate idempotency or reconciliation where required.

The claim protocol prevents duplicate execution starts caused by competing workers.

It cannot make arbitrary external systems transactional.

---

# 28. Separate trusted child command

Trusted execution uses its own command:

```text
job:run-trusted
```

The existing:

```text
job:run
```

retains the token/push contract.

This is preferable to:

```text
job:run --trusted
```

or:

```text
job:run --claimed
```

because the security contracts remain explicit.

`job:run` means:

```text
claim token-mode queued job using worker token
```

`job:run-trusted` means:

```text
claim trusted-mode queued job using prepared handoff token
```

Neither command contains an "ownership bypass".

---

# 29. Shared execution lifecycle

After a successful claim, token and trusted workers should behave identically.

The architecture therefore separates:

```text
ownership acquisition
```

from:

```text
job execution lifecycle
```

Conceptually:

```text
RunQueuedJob
    │
    ├── token validation
    ├── token claim
    └───────────────┐
                    ▼
             ExecuteRunningJob
                    ▲
    ┌───────────────┘
    │
RunTrustedJob
    │
    ├── handoff validation
    └── trusted claim
```

This avoids lifecycle duplication.

---

# 30. `ExecuteRunningJob` responsibilities

The shared execution component owns the post-claim worker boundary.

Its responsibilities include:

1. load the claimed job;
2. capture job identity;
3. write start log;
4. decode payload;
5. resolve registered handler;
6. build `JobContext`;
7. run handler;
8. inspect cooperative cancellation state;
9. encode handler result;
10. classify failure;
11. persist terminal transition;
12. write terminal log;
13. return domain-shaped outcome.

It is the only place that needs the broad worker-boundary exception classification behavior.

Ownership-specific operations remain small.

---

# 31. Why terminal persistence occurs outside the broad catch

JobRunner distinguishes:

```text
handler/application failure
```

from:

```text
failure to persist a terminal state
```

The worker boundary catches handler/runtime failures in order to classify them into a stable job outcome.

But a failure such as:

```text
markFailed() could not apply
```

must not be silently reclassified as the original job failure.

Terminal write anomalies therefore surface explicitly.

This preserves durable lifecycle integrity.

---

# 32. Cancellation semantics

Cancellation has two important races.

## 32.1 Cancellation before claim

A queued job can transition directly:

```text
queued -> cancelled
```

If cancellation wins first, the child later tries:

```text
WHERE status = queued
```

and fails to claim.

The handler does not execute.

## 32.2 Claim before cancellation

The child wins:

```text
queued -> running
```

A cancellation request then transitions:

```text
running -> cancel_requested
```

The handler cooperatively observes cancellation through `JobContext`.

The terminal path becomes:

```text
cancel_requested -> cancelled
```

The architecture therefore reuses the existing cancellation state machine.

---

# 33. Success/cancellation race

After a handler returns successfully, the worker re-reads job status.

If:

```text
cancel_requested
```

is already present, cancellation wins.

If success persistence races with a cancellation request and the guarded success transition fails, the worker re-checks status.

When the state is:

```text
cancel_requested
```

the worker completes cancellation.

Other unexpected transition misses are treated as lifecycle anomalies.

This preserves deterministic terminal behavior.

---

# 34. Supervisor launch behavior

The trusted supervisor launches a fresh child synchronously.

Conceptually:

```text
JobLauncher::runTrusted(jobId, rawHandoffToken)
```

starts:

```text
php bin/citomni job:run-trusted <job-id> --token=<handoff-token>
```

and waits for that child to exit.

The supervisor does not start the next job while the current child is still executing.

This implements one-at-a-time trusted execution.

---

# 35. Why trusted launch is synchronous

Synchronous launch gives the supervisor a very simple invariant:

```text
at most one trusted child exists because of this supervisor
```

After the child exits:

```text
check queue again immediately
```

There is no need for:

- child process registries;
- supervisor-side active job maps;
- concurrent result polling;
- worker slots.

This is intentionally conservative.

---

# 36. Why `JobLauncher` owns process creation

OS-specific process creation belongs in the existing JobRunner launcher service rather than in the queue orchestration operation.

The boundary is:

```text
WorkTrustedQueue
-> decides which job should be dispatched

JobLauncher
-> knows how to start the fixed internal child process
```

This preserves separation between:

```text
workflow orchestration
```

and:

```text
process mechanics
```

`JobLauncher` is still not a general shell runner.

It only knows fixed JobRunner worker entrypoints.

---

# 37. Trusted child uses argv-based process creation

The trusted synchronous path uses `proc_open()` with an argument vector rather than a shell command string.

Conceptually:

```php
[
	$phpBinary,
	$entrypoint,
	'job:run-trusted',
	(string)$jobId,
	'--token=' . $handoffToken,
]
```

Benefits include:

- no shell parsing of dynamic values;
- reduced quoting complexity;
- predictable Windows path handling;
- no arbitrary shell command surface;
- direct wait on the intended PHP child.

The trusted handoff token remains an argument to the fixed internal child command, but it is never logged or returned.

---

# 38. Child stdout/stderr policy

Trusted child process stdout/stderr are discarded by the supervisor launch path.

Durable execution output belongs in:

```text
JobLogger
```

not supervisor process pipes.

This keeps the execution contract consistent with JobRunner's observable database-backed logging model.

CLI output from direct manual invocation of `job:run-trusted` remains useful for debugging, but supervisor operation does not depend on it.

---

# 39. Meaning of trusted child exit code

A non-zero trusted child process exit code is not necessarily a supervisor error.

Examples:

```text
handler failed
-> child correctly persisted FAILED
-> child exits non-zero
```

or:

```text
claim failed
-> no handler executed
-> child exits non-zero
```

Therefore:

```text
child exit code != 0
```

does not imply:

```text
stop job:work
```

The supervisor continues.

This is a critical distinction between:

```text
job outcome
```

and:

```text
supervisor availability
```

---

# 40. Spawn failure before claim

Because the row remains queued until the child claims, failure to start the child does not fabricate a running execution.

The row remains eligible for later dispatch.

A subsequent supervisor iteration may:

```text
select same queued job
-> mint new token
-> replace old hash
-> launch another child
```

The previous prepared capability is fenced out.

This is one of the principal advantages of child-owned claim.

---

# 41. Supervisor crash before launch

State:

```text
status = queued
claim_mode = trusted
worker_token_hash = prepared hash
```

A later supervisor discovers the row because discovery does not require a null hash.

It prepares a new token and overwrites the old hash.

No special recovery table is required.

---

# 42. Supervisor crash while child is running

Once the child has successfully claimed:

```text
status = running
```

the job is no longer eligible for trusted queue discovery.

The child owns the lifecycle independently of the supervisor.

If the supervisor process dies while the child continues, the child can still finish and persist its terminal state.

The supervisor is therefore not the execution authority after claim.

---

# 43. Child crash after claim

If the child crashes after:

```text
queued -> running
```

but before terminal persistence, the row remains active.

This is not a new trusted-mode problem; it is a general worker-failure problem.

Heartbeat/stale-job handling remains the appropriate mechanism for detecting abandoned running jobs.

The trusted dispatcher does not redispatch a `running` row.

That avoids accidental duplicate side effects.

---

# 44. Queue trust boundary

Trusted/pull changes the trust model.

In token/push mode, possession of the worker capability is central.

In trusted/pull mode, the trusted supervisor is authorized to dispatch rows written to the trusted queue.

Therefore the queue itself becomes part of the trusted computing base.

The effective privilege boundary includes:

```text
who may create trusted rows
which job types are registered
what payloads those jobs accept
how handlers resolve execution details
```

This must be understood explicitly.

---

# 45. Trusted does not mean arbitrary

A trusted job should represent a bounded application action.

A good payload describes intent.

Example:

```json
{
	"project_id": 17,
	"environment": "prod"
}
```

The handler resolves authoritative execution details itself.

A dangerous payload would expose arbitrary mechanism:

```json
{
	"host": "example",
	"command": "arbitrary command",
	"private_key": "...",
	"destination": "..."
}
```

The latter effectively turns the trusted worker into a generic privileged execution proxy.

JobRunner cannot solve unsafe application handler contracts.

It can only provide a safe execution envelope.

---

# 46. Secrets must not be queued

The following must not be placed in:

```text
payload_json
result_json
job logs
error messages
CLI diagnostics
```

unless the application has an explicit and justified reason:

```text
passwords
API tokens
private keys
remote credentials
```

Trusted jobs should carry stable references or identifiers.

The child may resolve the actual credential inside the trusted execution context.

This keeps JobRunner persistence useful for observability without making it a credential store.

---

# 47. Architectural layering

The trusted model follows CitOmni's normal boundaries.

## Command

`WorkJobsCommand` owns:

- CLI adapter concerns;
- start message;
- exit code.

It does not contain SQL or queue orchestration.

## Operation

`WorkTrustedQueue` owns:

- queue polling decisions;
- handoff generation;
- dispatch sequencing;
- idle sleep policy.

It is SQL-free.

## Repository

`JobRepository` owns:

- trusted queue lookup;
- preparation write;
- token claim;
- trusted claim;
- state transitions.

All SQL remains here.

## Service

`JobLauncher` owns:

- process construction;
- OS-specific launch mechanics;
- synchronous trusted child wait.

## Execution operations

`RunQueuedJob` and `RunTrustedJob` own their respective ownership gates.

`ExecuteRunningJob` owns shared post-claim execution.

---

# 48. Repository trusted primitives

The trusted model is built around three persistence operations.

## 48.1 `findNextTrustedQueuedId()`

Returns the oldest eligible trusted queued job id.

No state is changed.

## 48.2 `prepareTrustedDispatch()`

Stores/replaces the handoff hash while preserving queued state.

## 48.3 `claimTrustedQueued()`

Atomically transitions:

```text
queued -> running
```

when:

```text
id
claim_mode
status
handoff hash
```

all match.

These three methods deliberately separate:

```text
discovery
authorization preparation
execution ownership
```

---

# 49. Why discovery and claim are not one repository method

With one supervisor, a single atomic "claim next trusted" method would be technically possible.

It was not chosen because trusted execution needs a fresh child to own the claim.

If the supervisor claimed immediately, lifecycle semantics would be degraded.

Therefore discovery cannot also mean execution ownership.

The intermediate dispatch preparation state is intentional.

---

# 50. Token reuse in trusted mode

Reusing the `worker_token_hash` column is appropriate because both modes use the value for the same abstract purpose:

> Hash of the opaque capability required by the concrete execution worker.

The difference is issuance time:

```text
token mode   -> enqueue time
trusted mode -> dispatch time
```

This avoids adding a second conceptually identical hash column.

The persisted `claim_mode` removes ambiguity.

---

# 51. Capability lifetime

The raw trusted handoff token should have an extremely short lifetime.

Typical lifecycle:

```text
mint
-> hash
-> persist hash
-> pass raw token to child
-> child claims
-> raw token no longer needed
```

Once the row leaves `queued`, the token cannot be used to claim again because the status guard fails.

No explicit token-expiry column is needed for V1.

The queue state itself constrains its usefulness.

---

# 52. Why token hashing is sufficient here

The worker token is a uniformly random high-entropy capability.

It is not a human password.

Therefore:

```text
SHA-256(token)
```

is appropriate.

There is no need for:

- bcrypt;
- Argon2;
- PBKDF2;
- salts aimed at low-entropy password defense.

The threat model is not offline guessing of a human-selected secret.

A 256-bit random token is already infeasible to guess.

---

# 53. No raw token persistence

Neither token mode nor trusted mode should persist the raw capability.

This prevents:

- accidental exposure through normal job reads;
- dumps revealing directly usable child credentials;
- application code receiving ownership tokens through repository normalization.

Repository read shapes therefore deliberately omit:

```text
worker_token_hash
```

where ordinary callers do not need it.

The raw value lives only in the process that needs to transfer it to the intended child.

---

# 54. Normalized repository rows

`JobRepository` returns typed normalized arrays.

The ownership secret hash is deliberately excluded from ordinary job reads.

This supports a useful principle:

> Job status inspection should not expose worker ownership material.

The supervisor does not need to read the current hash in order to replace it.

It simply writes a new one through the guarded preparation method.

---

# 55. Deterministic queue order

Trusted selection is oldest-first.

The ordering rule is explicit:

```text
created_at ASC
id ASC
```

This is preferable to relying on physical table order.

It also makes smoke testing deterministic.

No fairness abstraction is needed beyond this single queue order in V1.

---

# 56. No sleep between jobs

Suppose the queue contains:

```text
A
B
C
```

The intended supervisor timing is:

```text
A child
-> A exits
-> immediately find B
-> B child
-> B exits
-> immediately find C
```

Only when no trusted queued job exists does the supervisor sleep.

This provides good responsiveness without busy waiting.

---

# 57. Failure does not poison the queue

A failed handler is a job outcome, not a worker-system failure.

Sequence:

```text
A succeeds
B fails
C succeeds
```

must be valid.

`job:work` must continue after B's child exits non-zero.

This behavior is part of the supervisor contract and has been verified by integration smoke testing.

---

# 58. Token/push regression requirement

Because shared enqueue logic was extracted, the trusted feature must not alter token/push behavior.

Regression coverage therefore includes:

```text
StartJob
-> token-mode queued job
-> detached job:run child
-> atomic token claim
-> shared execution
-> succeeded
```

and:

```text
active lock already exists
-> StartJob returns already_active
```

The trusted implementation is considered incomplete if the original execution mode regresses.

---

# 59. Trusted child smoke test

The child execution path was tested before the supervisor was introduced.

A dedicated smoke sequence verified:

1. trusted job begins queued;
2. no attempts recorded;
3. no `started_at`;
4. first handoff preparation succeeds;
5. second preparation replaces the first;
6. stale token cannot claim;
7. stale token does not increment attempts;
8. current token starts a fresh trusted child;
9. job reaches `succeeded`;
10. execution timestamps are recorded;
11. result is persisted;
12. replay cannot execute the job again.

The test passed all assertions.

This demonstrates that the core trusted ownership protocol works independently of queue polling.

---

# 60. Supervisor smoke test

The supervisor path was then tested end-to-end.

The test used multiple trusted jobs including a deliberate failure.

Verified behavior included:

- deterministic trusted enqueue;
- unclaimed initial state;
- FIFO processing;
- sequential fresh children;
- exactly one execution attempt per job;
- failure persistence;
- continuation after failed child;
- exactly one handler execution marker;
- idle pickup;
- pickup within the configured polling window;
- empty trusted queue after drain;
- token/push regression;
- active-lock regression.

All assertions passed.

This validates both the new trusted path and compatibility with the existing token path.

---

# 61. Observed lifecycle semantics

A successfully executed trusted job ends with:

```text
claim_mode = trusted
attempts = 1
started_at != NULL
heartbeat_at != NULL
finished_at != NULL
status = succeeded | failed | cancelled
```

A stale trusted child attempt leaves:

```text
status = queued
attempts = 0
started_at = NULL
```

This distinction is important evidence that dispatch attempts are not being confused with execution attempts.

---

# 62. Security property: ownership before handler execution

No handler code may run before ownership is acquired.

The execution operation must therefore be called only after:

```text
claimQueued()
```

or:

```text
claimTrustedQueued()
```

returns true.

This is a hard architectural invariant.

Any future refactor that allows handler resolution/execution before the guarded claim would weaken JobRunner's safety model.

---

# 63. Security property: claim paths are disjoint

Token worker predicate includes:

```text
claim_mode = token
```

Trusted child predicate includes:

```text
claim_mode = trusted
```

Neither path should rely merely on caller convention.

The database condition itself enforces separation.

This protects against accidental cross-mode execution after future code changes.

---

# 64. Security property: no "already running therefore executable" path

A dangerous design would permit:

```text
job:run-trusted <id>
```

to execute any row that is:

```text
status = running
claim_mode = trusted
```

That would allow duplicate execution if multiple child processes were started.

The accepted design has no such entrypoint.

Execution authority is established only by the atomic queued claim with matching handoff capability.

---

# 65. Security property: queue is privileged intent

The trusted supervisor trusts the persisted trusted queue as a source of allowed application intent.

Therefore callers capable of enqueueing trusted jobs can influence privileged execution within the registered handler surface.

This is not equivalent to exposing raw credentials, but it is still a meaningful trust relationship.

Application integration must therefore use:

- explicit job types;
- strict payload schemas;
- server-side authoritative configuration;
- narrow operation semantics.

---

# 66. Crash-window taxonomy

It is useful to classify failure windows precisely.

## Window A - before enqueue

No durable job exists.

Caller failure is ordinary request/application failure.

## Window B - after trusted enqueue, before supervisor discovery

Job remains queued.

No execution metadata exists.

## Window C - after supervisor discovery, before preparation

Job remains queued.

Another iteration can select it.

## Window D - after preparation, before child launch

Job remains queued with a handoff hash.

Future dispatch may replace it.

## Window E - child launched, before claim

Job remains queued.

A stale or failed child has not yet created an execution attempt.

## Window F - after claim

Job is running.

The child owns execution.

Supervisor must not redispatch it.

## Window G - after side effect, before terminal write

This is application execution territory.

Idempotency/reconciliation may be required depending on handler semantics.

This taxonomy is useful when evaluating future retries.

---

# 67. Why automatic retry is absent

A generic retry policy is unsafe without understanding side effects.

Consider:

```text
remote deploy completed
-> PHP child crashes before markSucceeded
```

Automatically starting the job again may duplicate external work.

Therefore V1 does not infer retryability from:

```text
failed
```

or:

```text
stale heartbeat
```

A future retry design must distinguish at least:

- pre-claim launch failure;
- pre-side-effect transient failure;
- idempotent handler failure;
- partial external side effect;
- unknown completion state.

Retry policy belongs to an explicit future requirement.

---

# 68. Why no trusted lease exists

A lease would usually answer:

> Which worker currently owns a queued/reserved item, and when may another worker take it over?

The current design does not need one because:

- the row remains queued before claim;
- handoff replacement fences stale children;
- the concrete child atomically claims running;
- one supervisor is assumed.

A lease would add state without adding useful correctness in V1.

---

# 69. Why no reservation status exists

A separate status such as:

```text
reserved
```

was also unnecessary.

Dispatch preparation is represented by:

```text
status = queued
claim_mode = trusted
worker_token_hash != NULL
```

The row is still not executing.

Keeping the existing lifecycle status vocabulary avoids complicating:

- UI status;
- active-lock behavior;
- cancellation;
- stale-job logic.

Ownership preparation is orthogonal to execution status.

---

# 70. Why `queued` remains cancellable after preparation

Because preparation does not mean execution has started, the job remains a normal queued job for cancellation purposes.

If cancellation wins:

```text
queued -> cancelled
```

the prepared child cannot later claim because its status predicate fails.

This is exactly the desired behavior.

---

# 71. Heartbeat semantics

Heartbeat belongs to the concrete running worker.

The supervisor does not heartbeat a queued prepared job.

This preserves:

```text
heartbeat_at
```

as evidence of active execution rather than queue supervision.

The child initializes heartbeat at claim and may refresh it through `JobContext`.

---

# 72. `attempts` semantics

`attempts` increments only in the successful claim update.

Therefore:

```text
stale token attempt
```

does not count.

```text
failed proc_open before child
```

does not count.

```text
supervisor redispatch before any claim
```

does not count.

A successful child claim counts exactly once.

This gives `attempts` a clean operational meaning.

---

# 73. `started_at` semantics

`started_at` is written in the same guarded update that establishes ownership.

It therefore answers:

> When did an actual worker acquire execution ownership?

It does not answer:

> When did a supervisor first notice the job?

Those are deliberately different events.

---

# 74. `finished_at` semantics

Terminal transitions write `finished_at`.

The supervisor does not write this field.

The child execution lifecycle owns terminal job completion.

This keeps all execution timestamps inside one lifecycle authority.

---

# 75. Worker-boundary exception handling

Broad `Throwable` catching is justified only at the worker boundary where JobRunner must convert arbitrary handler failure into a durable failed job.

This is not a general exception-handling style for services or commands.

The purpose is deterministic lifecycle completion.

Known job failures are persisted with:

- error class;
- reason code when available;
- normalized message.

The worker then returns a stable failure outcome.

---

# 76. Error-message normalization

Handler exceptions may contain:

- invalid UTF-8 from external processes;
- very long stderr;
- platform-specific byte sequences.

Persisted `error_message` must fit the database column and remain valid UTF-8.

The execution lifecycle therefore normalizes the persisted version while allowing fuller diagnostic detail to go through JobLogger's own sanitization/chunking path.

This is independent of trusted mode but is intentionally shared by both worker types.

---

# 77. Result encoding

Handler result data must be JSON-encodable.

Invalid result encoding is treated as a job failure.

JobRunner does not silently alter structured result semantics.

This is important because terminal success should mean:

```text
handler completed
AND result contract was persistable
```

not merely:

```text
handler returned something
```

---

# 78. Payload encoding

Likewise, queued payloads are structured domain input.

Invalid UTF-8 or non-encodable values fail at enqueue.

JobRunner does not substitute malformed structured input merely to make storage succeed.

This keeps the job contract explicit.

---

# 79. Performance characteristics

Trusted supervision is intentionally cheap.

## Idle

Approximately:

```text
one indexed queue lookup per idle interval
```

with:

```text
sleep(3)
```

between empty checks.

CPU use while sleeping is effectively negligible.

## Busy

For each job:

```text
queue lookup
handoff write
PHP child startup
handler execution
terminal persistence
```

The PHP process startup cost is small relative to the kinds of jobs JobRunner is intended to run.

Preserving process isolation is therefore a better trade than optimizing away subprocess creation.

---

# 80. Memory characteristics

The persistent supervisor retains:

- `App`;
- repository/service state needed for supervision;
- DB connection;
- launcher service.

It does not accumulate application handler state because handlers run elsewhere.

The child process dies after each job, naturally releasing:

- handler objects;
- service graph;
- temporary buffers;
- external library state.

This reduces the need for explicit long-running-process hygiene inside ordinary application handlers.

---

# 81. Operational guide

A typical trusted execution deployment requires:

1. JobRunner schema supporting `claim_mode`;
2. trusted queue index;
3. `job:run-trusted` command registered;
4. `job:work` command registered;
5. a long-lived process invoking `job:work`;
6. application code using trusted enqueue for the relevant jobs;
7. registered handlers for those job types.

Once the supervisor is running, producers only enqueue.

No per-job launch integration is required on the producer side.

---

# 82. Trusted job creation example

Conceptually:

```php
$result = (new EnqueueTrustedJob($this->app))->execute(
	'package.action',
	[
		'entity_id' => 42,
	],
	'package.action:42',
	'Run package action'
);
```

The result identifies the queued job.

It does not contain:

- worker token;
- handoff token;
- secret credential.

The supervisor later assigns the child capability.

---

# 83. Token job creation remains available

Existing consumers continue to use:

```php
$result = (new StartJob($this->app))->execute(
	'package.action',
	[
		'entity_id' => 42,
	],
	'package.action:42',
	'Run package action'
);
```

That path still:

- mints token;
- enqueues token mode;
- launches detached worker.

The choice between `StartJob` and `EnqueueTrustedJob` is a choice of execution ownership model.

---

# 84. Choosing between token and trusted mode

Use token/push when:

- the producer should immediately launch the job;
- the launched child inherits an acceptable execution environment;
- no separate trusted dispatcher is required.

Use trusted/pull when:

- enqueue and execution identity must be separated;
- work should be executed by a pre-established trusted supervisor;
- producer process launch inheritance is undesirable;
- jobs should wait durably until the trusted supervisor is available.

This is an application architecture decision.

JobRunner supports both.

---

# 85. Supervisor availability

A trusted job remains queued if no supervisor is running.

This is correct behavior.

Trusted enqueue means:

> Work has been durably accepted.

It does not mean:

> Execution has already started.

Applications should expose queued state honestly.

No fallback to token/push should happen implicitly.

---

# 86. Supervisor restart

Because trusted jobs are durable, restarting the supervisor is straightforward.

After restart:

```text
find oldest queued trusted job
```

will recover unclaimed work.

Prepared-but-unclaimed rows are also eligible.

Running rows are not redispatched.

This makes the supervisor itself relatively stateless.

---

# 87. Multiple supervisor warning

V1 assumes one trusted supervisor.

The guarded child claim still prevents two children from both acquiring the same job, so accidental duplicate supervisors do not automatically produce duplicate handler execution.

However, multiple supervisors may:

- repeatedly replace one another's prepared handoff hash;
- cause unnecessary child launches;
- reduce operational clarity.

Therefore V1 should run exactly one trusted supervisor.

Parallel trusted supervision should be designed explicitly if ever required.

---

# 88. Cancellation while supervisor sleeps

Cancellation operates directly on durable job state.

The supervisor does not need to be awake for a queued cancellation to succeed.

When it wakes:

```text
cancelled rows
```

are not eligible for trusted queue selection.

This is another advantage of persistence-driven orchestration.

---

# 89. Queue producer crash

Once trusted enqueue commits successfully, producer lifetime is irrelevant.

The producer may terminate immediately.

The supervisor will later discover the row.

This is one of the core decoupling benefits of trusted/pull.

---

# 90. Queue producer security responsibilities

Because the trusted queue represents privileged intent, producers must not be treated as unconstrained data sources.

A producer should:

- authorize action;
- validate bounded payload;
- use registered job type;
- avoid secrets;
- avoid arbitrary executable parameters.

JobRunner guarantees execution ownership and lifecycle integrity.

It does not replace application authorization.

---

# 91. Handler security responsibilities

A trusted handler should:

- validate required payload fields;
- resolve authoritative configuration internally;
- avoid accepting arbitrary commands when a bounded operation is possible;
- keep credentials out of logs;
- use `JobContext` for progress/cancellation;
- produce JSON-encodable results;
- understand external side-effect idempotency.

Trusted mode gives the handler a trusted process environment.

It does not make unsafe handler logic safe.

---

# 92. Observability

The trusted model preserves all ordinary JobRunner observability.

Consumers can continue to inspect:

- job status;
- current step;
- heartbeat;
- result;
- error metadata;
- logs;
- timestamps;
- attempts.

No special UI semantics are required merely because a job is trusted.

`claim_mode` may be useful diagnostically, but the lifecycle remains the same.

---

# 93. State-machine compatibility

Trusted mode does not add a new execution status.

The normal lifecycle remains:

```text
queued
-> running
-> succeeded
```

or:

```text
queued
-> running
-> failed
```

or:

```text
queued
-> cancelled
```

or:

```text
queued
-> running
-> cancel_requested
-> cancelled
```

Trusted dispatch preparation occurs inside `queued`.

This is intentionally orthogonal to lifecycle state.

---

# 94. Why this is not a generic queue

Although `job:work` polls queued rows, JobRunner still has a deliberately narrow contract.

It is not intended to provide:

- arbitrary message consumers;
- topic subscriptions;
- delayed scheduling;
- distributed consumer groups;
- visibility timeouts;
- generic acknowledgment protocols;
- arbitrary payload routing.

Jobs are registered application handlers executed through a defined lifecycle.

Trusted/pull is an execution ownership mode, not a redefinition of JobRunner as a message broker.

---

# 95. Why this is not a daemon framework

The persistent supervisor is one package command with one purpose:

```text
work trusted queued JobRunner jobs
```

JobRunner does not attempt to manage:

- service installation;
- OS startup policy;
- restart supervision;
- user switching;
- process watchdogs.

The host environment decides how `job:work` is kept running.

This preserves OS independence.

---

# 96. Why this is not a scheduler

`job:work` executes jobs that are already queued.

It does not decide when application jobs should be created.

Cron-like schedules, calendar semantics and delayed jobs are outside this design.

A separate scheduler could enqueue trusted jobs in the future without changing the claim protocol.

---

# 97. Why process identity is outside JobRunner

JobRunner cares that a trusted supervisor exists.

It does not care how the operating system gives that process its identity.

This is a deliberate portability boundary.

Examples of host-specific bootstrap mechanisms might include:

- Windows Task Scheduler;
- systemd;
- launchd;
- manual terminal;
- container supervisor.

None belongs in `citomni/jobrunner` core.

---

# 98. Implementation map

The trusted architecture is represented by the following package elements.

## Claim discriminator

```text
src/Enum/ClaimMode.php
```

## Shared queue creation

```text
src/Operation/EnqueueJob.php
```

## Trusted enqueue

```text
src/Operation/EnqueueTrustedJob.php
```

## Existing push entry

```text
src/Operation/StartJob.php
```

## Token worker ownership

```text
src/Operation/RunQueuedJob.php
```

## Trusted worker ownership

```text
src/Operation/RunTrustedJob.php
```

## Shared post-claim lifecycle

```text
src/Operation/ExecuteRunningJob.php
```

## Trusted supervisor

```text
src/Operation/WorkTrustedQueue.php
```

## CLI adapters

```text
src/Command/RunJobCommand.php
src/Command/RunTrustedJobCommand.php
src/Command/WorkJobsCommand.php
```

## Process launch

```text
src/Service/JobLauncher.php
```

## Persistence

```text
src/Repository/JobRepository.php
```

## Registry

```text
src/Boot/Registry.php
```

---

# 99. Core invariants

The architecture should be considered correct only while these invariants hold.

## I1 - Claim mode is explicit

Every job row identifies its ownership protocol.

## I2 - Claim paths are disjoint

Token workers cannot claim trusted jobs.

Trusted workers cannot claim token jobs.

## I3 - Raw worker capabilities are not persisted

Only hashes are stored.

## I4 - Trusted jobs have no capability at enqueue

The child capability is minted at dispatch.

## I5 - Dispatch preparation does not start execution

Prepared trusted rows remain queued.

## I6 - The concrete child owns `queued -> running`

The supervisor never marks a job running.

## I7 - `attempts` increments only on successful claim

Failed ownership attempts do not count.

## I8 - `started_at` is written only on successful claim

It represents actual execution ownership.

## I9 - Stale trusted capability can be fenced

A new preparation replaces the old hash while queued.

## I10 - Running jobs are not redispatched

Trusted discovery only selects queued rows.

## I11 - Application handlers run in fresh PHP processes

The supervisor never runs them in-process.

## I12 - Token and trusted workers share one post-claim lifecycle

Result, failure, logging and cancellation semantics must not diverge.

## I13 - A failed child does not terminate the supervisor

Job failure and supervisor failure are separate concepts.

## I14 - Queue ordering is deterministic

`created_at`, then `id`.

## I15 - Idle sleep happens only when no trusted queued job exists

Busy queues drain immediately.

## I16 - Secrets do not belong in JobRunner persistence

Payloads describe intent.

---

# 100. Review checklist for future changes

Any future change to trusted execution should be reviewed against the following questions.

### Ownership

- Can two children ever both execute the same queued attempt?
- Can a token worker claim a trusted row?
- Can a trusted worker claim a token row?
- Can a stale handoff token regain validity?

### Lifecycle

- Does `started_at` still mean actual execution start?
- Does `attempts` still count successful claims?
- Can a row become `running` without an actual child?
- Are terminal transitions still guarded?

### Crash recovery

- What happens if the supervisor dies before launch?
- What happens if the child dies before claim?
- What happens if the child dies after claim?
- Can recovery introduce duplicate side effects?

### Isolation

- Does any application handler begin running inside the persistent supervisor?
- Are per-job PHP processes still fresh?

### Security

- Does any new API expose ownership tokens?
- Does any payload contain raw credentials?
- Does a handler accept arbitrary execution details unnecessarily?
- Has the queue's trust boundary widened?

### Performance

- Does the change add polling or write amplification without concrete value?
- Is parallelism being added before it is required?

If a change weakens one of the established invariants, it should be treated as an architectural change, not a minor implementation detail.

---

# 101. Summary

The trusted/pull extension is built around a small number of deliberate ideas.

First, queue creation and execution ownership are separate concerns.

Second, trusted mode is explicit in persistence rather than implied by a missing token or caller convention.

Third, the persistent supervisor is not an application worker. It only discovers work, prepares a one-time child capability, starts a fresh process and waits.

Fourth, the actual child owns the atomic `queued -> running` transition.

Fifth, both execution modes retain capability-based ownership:

```text
token mode
-> capability minted at enqueue

trusted mode
-> capability minted at dispatch
```

Sixth, after ownership is acquired, both modes converge on one shared execution lifecycle.

Finally, the design remains intentionally small:

```text
one supervisor
one trusted child at a time
deterministic FIFO
three-second idle sleep
fresh PHP process per job
no distributed queue machinery
```

This gives `citomni/jobrunner` a second execution ownership model without sacrificing the properties that made the original token/push design robust: explicit ownership, guarded transitions, durable state, deterministic failure semantics and process isolation.
