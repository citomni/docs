# CitOmni JobRunner - Usage, Execution Modes, Handlers, Status, and Cancellation (PHP 8.5+)

> **Run long jobs outside request handling. Keep execution observable. Choose the ownership model explicitly.**

This guide explains how to use `citomni/jobrunner` in a CitOmni application or provider package.

It covers:

- Package setup.
- Handler registration.
- Token/push jobs through `StartJob`.
- Trusted/pull jobs through `EnqueueTrustedJob`.
- The persistent `job:work` supervisor.
- Job handlers and `JobContext`.
- Status polling.
- Cooperative cancellation.
- Active lock keys.
- Logging.
- Security boundaries.
- Troubleshooting.

The current runtime supports two execution modes:

```text
TOKEN / PUSH

StartJob
  -> enqueue TOKEN-mode job
  -> launch fresh detached job:run child
  -> child claims queued job with worker token
  -> shared execution lifecycle
  -> terminal state
```

and:

```text
TRUSTED / PULL

EnqueueTrustedJob
  -> enqueue TRUSTED-mode job
  -> persistent job:work supervisor finds it
  -> supervisor prepares ephemeral handoff capability
  -> supervisor starts fresh job:run-trusted child
  -> child claims queued job with handoff capability
  -> shared execution lifecycle
  -> terminal state
```

Both modes execute the actual handler in a fresh PHP process.

---

**Document type:** Technical Guide  
**Applies to:** Current `citomni/jobrunner` token/push and trusted/pull runtime  
**Audience:** Application and provider developers  
**Status:** Current usage guide  
**Author:** CitOmni Core Team  
**Copyright:** Copyright (c) 2012-present CitOmni

---

## Architecture overview

`citomni/jobrunner` is a small database-backed lifecycle layer for explicit long-running jobs.

The package owns the generic execution envelope:

- Persist a durable job row.
- Enforce active lock semantics.
- Claim queued jobs atomically.
- Resolve a registered handler.
- Execute the handler in a fresh PHP worker process.
- Provide `JobContext`.
- Persist logs, steps, heartbeat, result, and errors.
- Support status reads.
- Support cooperative cancellation.
- Persist terminal state.

Consumer applications and packages own the actual workflow logic.

Examples of suitable job types:

```text
commerce.import_products
commerce.export_feed
deploy.publish_app
reports.build_monthly_summary
maintenance.rebuild_search_index
```

JobRunner knows how to execute a registered job.

It does not know what importing products, publishing an application, or building a report means.

That boundary is intentional.

For the deeper ownership and claim model, see the separate JobRunner execution-ownership architecture document in the `concepts` section of the CitOmni documentation.

---

## 1) What JobRunner is

A JobRunner job consists of:

- A row in `jobrun_jobs`.
- A registered `job_type`.
- A persisted `claim_mode`.
- Optional payload JSON.
- Optional active lock key.
- Optional human-facing title.
- A fresh worker process when execution begins.
- Persisted logs.
- Step progress.
- Heartbeat timestamps.
- A terminal result such as `succeeded`, `failed`, or `cancelled`.

The lifecycle status values are:

```text
queued
running
cancel_requested
succeeded
failed
cancelled
```

Active statuses are:

```text
queued
running
cancel_requested
```

Terminal statuses are:

```text
succeeded
failed
cancelled
```

---

## 2) What JobRunner is not

`citomni/jobrunner` is deliberately scoped.

It is not:

- A generic message queue.
- A Redis or AMQP abstraction.
- A generic daemon framework.
- A scheduler.
- A retry framework.
- A distributed worker farm.
- A generic process manager.
- A web-based shell.
- A domain workflow engine.
- A polished administration UI.

Trusted/pull mode does include a persistent supervisor:

```text
job:work
```

but that does not make JobRunner a general daemon framework.

The supervisor has one narrow purpose:

```text
Find trusted queued jobs
-> prepare handoff capability
-> start one fresh trusted child
-> wait for the child
-> repeat
```

Applications should expose registered job types, not arbitrary commands.

Good:

```text
Start registered job type: deploy.publish_app
```

Bad:

```text
POST arbitrary shell command and execute it through JobRunner
```

---

## 3) Package setup

Install JobRunner through Composer:

```bash
composer require citomni/jobrunner
```

Register the provider if the application uses explicit provider registration:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\JobRunner\Boot\Registry::class,
];
```

The package registry contributes:

```text
MAP_COMMON
  -> jobLogger
  -> jobRegistry
  -> jobLauncher

CFG_COMMON
  -> table names
  -> handler map
  -> PHP binary
  -> CLI entrypoint
  -> log chunk size
  -> trusted worker idle sleep

COMMANDS_CLI
  -> job:run
  -> job:run-trusted
  -> job:work
```

`job:run` and `job:run-trusted` are internal worker entrypoints.

`job:work` is the long-lived trusted queue supervisor.

---

## 4) Database setup

JobRunner uses:

```text
jobrun_jobs
jobrun_logs
```

The package schema lives in:

```text
sql/citomni_jobrunner.sql
```

Apply it through the normal database setup or migration process for the application.

Important job columns include:

```text
id
job_uuid
job_type
status
claim_mode
lock_key
title
payload_json
result_json
error_class
error_reason_code
error_message
current_step_key
current_step_label
step_index
step_total
worker_token_hash
attempts
created_at
queued_at
started_at
heartbeat_at
finished_at
updated_at
active_lock_key
```

Important log columns include:

```text
id
job_id
seq
created_at
level
stream
step_key
message
context_json
```

The current claim modes are:

```text
token
trusted
```

The database enforces:

```text
claim_mode IN ('token', 'trusted')
```

and token-mode jobs must have a worker-token hash.

Trusted jobs may begin with:

```text
worker_token_hash = NULL
```

because the trusted handoff capability is created later by the supervisor.

The trusted queue lookup is supported by:

```text
(claim_mode, status, created_at, id)
```

and selection order is:

```text
created_at ASC
id ASC
```

Do not write application SQL directly against JobRunner tables from controllers, commands, operations, services, or handlers.

Use JobRunner's operations and `JobContext`.

---

## 5) Runtime configuration

JobRunner configuration lives under:

```php
'jobrunner' => [
	'tables' => [
		'jobs' => 'jobrun_jobs',
		'logs' => 'jobrun_logs',
	],
	'handlers' => [
	],
	'php_binary' => 'php',
	'cli_entrypoint' => 'bin/citomni',
	'log_chunk_max_bytes' => 16384,
	'trusted_worker_idle_sleep_seconds' => 3,
],
```

### `jobrunner.tables.jobs`

Database table for jobs.

Default:

```text
jobrun_jobs
```

### `jobrunner.tables.logs`

Database table for job logs.

Default:

```text
jobrun_logs
```

### `jobrunner.handlers`

Map of:

```text
job type -> handler class
```

Example:

```php
'jobrunner' => [
	'handlers' => [
		'commerce.import_products' => \App\JobRunner\ImportProductsJobHandler::class,
	],
],
```

### `jobrunner.php_binary`

PHP executable used for worker processes.

Default:

```text
php
```

### `jobrunner.cli_entrypoint`

CitOmni CLI entrypoint.

Default:

```text
bin/citomni
```

Relative paths are resolved against the application root.

### `jobrunner.log_chunk_max_bytes`

Maximum byte size per persisted log chunk.

Default:

```text
16384
```

### `jobrunner.trusted_worker_idle_sleep_seconds`

Sleep interval used by `job:work` when no trusted queued job exists.

Default:

```text
3
```

The current implementation requires:

```text
>= 1
```

The supervisor checks the queue immediately again after a child exits. The idle sleep is not inserted between queued jobs.

---

## 6) Registering handlers

Application and provider code registers handlers through:

```text
jobrunner.handlers
```

Example application config:

```php
<?php
declare(strict_types=1);

return [
	'jobrunner' => [
		'handlers' => [
			'commerce.import_products' => \App\JobRunner\ImportProductsJobHandler::class,
			'commerce.export_feed' => \App\JobRunner\ExportFeedJobHandler::class,
		],
	],
];
```

A reusable provider can contribute handlers through its registry:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

class Registry {
	public const CFG_COMMON = [
		'jobrunner' => [
			'handlers' => [
				'vendor.rebuild_index' => \Vendor\Package\JobRunner\RebuildIndexJobHandler::class,
			],
		],
	];
}
```

Use namespaced job type strings:

```text
commerce.import_products
commerce.export_feed
deploy.publish_app
vendor.rebuild_index
```

Avoid generic keys such as:

```text
run
import
create
```

The registered class must:

- Exist.
- Implement `JobHandlerInterface`.
- Be instantiable without constructor arguments.

Handler instances are cached inside a `JobRegistry` service instance.

Actual job execution still receives fresh-process isolation because both token and trusted execution run handlers inside fresh worker PHP processes.

---

## 7) Handler contract

A handler implements:

```php
\CitOmni\JobRunner\Contract\JobHandlerInterface
```

Current contract:

```php
<?php
declare(strict_types=1);

namespace CitOmni\JobRunner\Contract;

use CitOmni\JobRunner\Value\JobContext;

interface JobHandlerInterface {
	public function run(JobContext $context): array;
}
```

The returned array must be JSON-encodable.

A handler should normally:

```text
1. Read and validate payload.
2. Resolve authoritative application configuration.
3. Log meaningful start information.
4. Set the current step.
5. Perform one bounded unit of work.
6. Update heartbeat when appropriate.
7. Check cancellation between bounded units.
8. Return domain-shaped result data.
```

Handlers must not write directly to JobRunner tables.

---

## 8) Writing a handler

Example:

```php
<?php
declare(strict_types=1);

namespace App\JobRunner;

use CitOmni\JobRunner\Contract\JobHandlerInterface;
use CitOmni\JobRunner\Value\JobContext;

class RebuildIndexJobHandler implements JobHandlerInterface {
	public function run(JobContext $context): array {
		$payload = $context->payload();

		$indexKey = (string)($payload['index_key'] ?? '');
		if ($indexKey === '') {
			throw new \InvalidArgumentException('Payload index_key is required.');
		}

		$context->info('Index rebuild started.', [
			'index_key' => $indexKey,
		]);

		$context->setStep('load', 'Loading source data.', 1, 3);

		if ($context->isCancellationRequested()) {
			return [
				'cancelled' => true,
			];
		}

		$app = $context->app();

		// Resolve repositories/services through the application as needed.
		// Perform the real workflow here.

		$context->setStep('build', 'Building index.', 2, 3);
		$context->heartbeat();

		if ($context->isCancellationRequested()) {
			return [
				'cancelled' => true,
			];
		}

		$context->setStep('done', 'Index rebuild completed.', 3, 3);
		$context->info('Index rebuild completed.');

		return [
			'cancelled' => false,
			'index_key' => $indexKey,
		];
	}
}
```

The handler owns domain workflow.

JobRunner owns execution lifecycle.

---

## 9) `JobContext`

A handler receives a `JobContext`.

Identity and input:

```php
$context->app();
$context->jobId();
$context->jobUuid();
$context->jobType();
$context->payload();
```

Structured logs:

```php
$context->info('Message.');
$context->warning('Message.');
$context->error('Message.');
$context->debug('Message.');
```

Logs with context:

```php
$context->info('Import batch completed.', [
	'rows' => 500,
]);
```

Stream-style logs:

```php
$context->stdout('Processed 500 rows.');
$context->stderr('Remote command returned a warning.');
```

Progress:

```php
$context->setStep(
	'import',
	'Importing products.',
	2,
	5
);
```

Heartbeat:

```php
$context->heartbeat();
```

Cancellation:

```php
if ($context->isCancellationRequested()) {
	return [
		'cancelled' => true,
	];
}
```

Cancellation is cooperative.

Handlers should check between bounded work units, not once at the beginning of a long workflow.

---

## 10) Choosing an execution mode

The main consumer decision is:

```text
StartJob
```

or:

```text
EnqueueTrustedJob
```

Use token/push when the process that starts the job should immediately spawn the dedicated worker and the child will inherit the correct execution environment.

Use trusted/pull when queue production and final execution must be separated and a pre-established trusted supervisor should own dispatch.

### Token/push

Use:

```php
\CitOmni\JobRunner\Operation\StartJob
```

Runtime:

```text
caller
-> StartJob
-> TOKEN-mode queued row
-> detached job:run child
-> token claim
-> handler
```

### Trusted/pull

Use:

```php
\CitOmni\JobRunner\Operation\EnqueueTrustedJob
```

Runtime:

```text
caller
-> EnqueueTrustedJob
-> TRUSTED-mode queued row
-> job:work
-> prepared handoff
-> fresh job:run-trusted child
-> trusted claim
-> handler
```

Trusted mode requires a running `job:work` supervisor.

There is no implicit fallback from trusted/pull to token/push.

If no supervisor is running, the trusted job remains queued.

---

## 11) Starting a token/push job

Use:

```php
\CitOmni\JobRunner\Operation\StartJob
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(
	'commerce.import_products',
	[
		'source_key' => 'supplier-a',
	],
	'commerce.import_products:supplier-a',
	'Import supplier A products'
);
```

Possible result statuses:

```text
started
already_active
launch_failed
```

Successful start:

```php
[
	'status' => 'started',
	'job_id' => 123,
	'job_uuid' => '...',
	'job_type' => 'commerce.import_products',
]
```

Already active:

```php
[
	'status' => 'already_active',
	'job_id' => 122,
	'job_uuid' => '...',
	'job_type' => 'commerce.import_products',
	'job_status' => 'running',
]
```

Launch submission failure:

```php
[
	'status' => 'launch_failed',
	'job_id' => 123,
	'job_uuid' => '...',
	'job_type' => 'commerce.import_products',
	'error_reason_code' => 'launch_failed',
]
```

`StartJob`:

1. Mints a random raw worker token.
2. Stores only its SHA-256 hash through the shared enqueue workflow.
3. Creates the row in `token` claim mode.
4. Launches the detached `job:run` worker.
5. Clears the local raw token after launch submission.

The job becomes `running` only when the child successfully claims it.

---

## 12) Enqueueing a trusted/pull job

Use:

```php
\CitOmni\JobRunner\Operation\EnqueueTrustedJob
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\EnqueueTrustedJob($this->app))->execute(
	'deploy.publish_app',
	[
		'app_id' => 42,
		'environment' => 'production',
	],
	'deploy.publish_app:42:production',
	'Publish application'
);
```

Possible result statuses:

```text
queued
already_active
```

Successful enqueue:

```php
[
	'status' => 'queued',
	'job_id' => 201,
	'job_uuid' => '...',
	'job_type' => 'deploy.publish_app',
]
```

Already active:

```php
[
	'status' => 'already_active',
	'job_id' => 198,
	'job_uuid' => '...',
	'job_type' => 'deploy.publish_app',
	'job_status' => 'running',
]
```

A newly queued trusted job begins conceptually as:

```text
status = queued
claim_mode = trusted
worker_token_hash = NULL
attempts = 0
started_at = NULL
```

`EnqueueTrustedJob` does not launch a worker.

Execution begins only when `job:work` dispatches it and a fresh trusted child successfully claims it.

---

## 13) Do not call `EnqueueJob` directly in normal consumer code

`EnqueueJob` is the shared creation primitive used by:

```text
StartJob
EnqueueTrustedJob
```

It accepts the explicit claim mode and worker-token hash.

Normal application code should usually choose the higher-level operation:

```text
StartJob
```

or:

```text
EnqueueTrustedJob
```

That keeps execution ownership decisions explicit and avoids application code constructing internal claim state manually.

---

## 14) Running the trusted supervisor

Start:

```bash
php bin/citomni job:work
```

The command prints:

```text
Trusted JobRunner worker started.
```

and then enters the trusted queue loop.

Current behavior:

```text
find oldest trusted queued job

if none:
    sleep configured idle interval
    check again

if found:
    mint ephemeral handoff token
    persist/replace handoff hash while row stays queued
    start fresh job:run-trusted child synchronously
    wait for child
    check queue again immediately
```

Default idle sleep:

```text
3 seconds
```

The supervisor is intentionally persistent.

The actual handler is not.

Each trusted job receives a fresh PHP child process.

---

## 15) Run `job:work` under the intended execution environment

Trusted/pull exists specifically so queue production and execution can be separated.

Run `job:work` under the process identity and environment that should own trusted execution.

That process environment is inherited by the fresh `job:run-trusted` child.

JobRunner itself does not:

- Switch OS users.
- Install services.
- Configure schedulers.
- Manage host startup policy.

The host decides how `job:work` is started and kept running.

Examples may include:

- Manual CLI session.
- Operating-system service manager.
- Operating-system scheduler started at login or boot.
- Container/process supervisor.

Those mechanisms are outside JobRunner.

---

## 16) Trusted supervisor concurrency

The current trusted V1 model is designed around one supervisor processing one trusted child at a time.

Do not deliberately run several `job:work` supervisors unless you have reviewed the handoff behavior and intentionally designed for it.

The guarded child claim still prevents two children from both successfully changing the same job from `queued` to `running`.

However, multiple supervisors may repeatedly replace a queued job's prepared handoff hash and cause unnecessary child launches.

One trusted supervisor is the intended operating model.

---

## 17) Internal worker commands

JobRunner registers:

```text
job:run
job:run-trusted
job:work
```

### `job:run`

Internal token/push child:

```text
php bin/citomni job:run <job-id> --token=<worker-token>
```

Normal application code should not manually construct this command.

Use `StartJob`.

### `job:run-trusted`

Internal trusted child:

```text
php bin/citomni job:run-trusted <job-id> --token=<handoff-token>
```

Normal application code should not construct this command.

`job:work` creates and launches the trusted child.

### `job:work`

Public operational supervisor command:

```text
php bin/citomni job:work
```

This is the command normally run directly when using trusted/pull mode.

---

## 18) Worker ownership and capabilities

Both execution modes require the concrete worker to prove ownership before handler execution.

### Token/push

Capability is created at enqueue:

```text
raw worker token
-> SHA-256 hash persisted
-> fresh job:run child receives raw token
-> atomic token claim
```

### Trusted/pull

Capability is created at dispatch:

```text
trusted job is queued without token
-> supervisor creates raw handoff token
-> SHA-256 hash persisted/replaced
-> fresh job:run-trusted child receives raw token
-> atomic trusted claim
```

Raw worker and handoff tokens must not be:

- Logged.
- Returned to application callers.
- Persisted in plaintext.
- Exposed in UI.
- Stored in job payloads.

Only the hash is stored.

---

## 19) Shared execution lifecycle

After ownership claim, both modes use the same execution path.

Conceptually:

```text
RunQueuedJob
  -> token claim
  -> ExecuteRunningJob

RunTrustedJob
  -> trusted handoff claim
  -> ExecuteRunningJob
```

`ExecuteRunningJob` owns:

- Job loading.
- Start logging.
- Payload decoding.
- Handler resolution.
- `JobContext` construction.
- Handler execution.
- Cancellation resolution.
- Result encoding.
- Failure capture.
- Terminal transitions.
- Terminal logging.

This prevents token and trusted workers from drifting into separate job semantics.

---

## 20) Fresh process per job

`job:work` is long-lived, but it does not execute application handlers directly.

Every trusted job is executed in a fresh child process.

The existing token/push path also executes through a fresh child.

This means each actual job receives fresh process-local state, including:

- Fresh `App`.
- Fresh service instances.
- Fresh `JobRegistry`.
- Fresh handler instances.
- Fresh PHP runtime state.

This is important for long-running worker safety.

Application handlers do not need special reset hooks merely because trusted supervision is persistent.

---

## 21) Lock keys

A lock key prevents multiple active jobs from sharing the same logical lock.

Active statuses are:

```text
queued
running
cancel_requested
```

Terminal statuses release the generated active lock:

```text
succeeded
failed
cancelled
```

Examples:

```text
commerce.import_products:supplier-a
deploy.publish_app:42:production
reports.monthly:2026-09
```

Use a lock key when only one active instance should exist.

Use `null` when parallel jobs are valid.

The database unique `active_lock_key` is authoritative.

The application-level pre-check is only a friendly fast path.

---

## 22) Reading job status

Use:

```php
\CitOmni\JobRunner\Operation\GetJobStatus
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute(
	$jobId,
	$afterSeq,
	$logLimit
);
```

Arguments:

```text
jobId    >= 1
afterSeq >= 0
logLimit >= 1
```

`logLimit` is capped at:

```text
1000
```

When the job exists:

```php
[
	'status' => 'found',
	'job' => [
		'id' => 123,
		'uuid' => '...',
		'type' => 'commerce.import_products',
		'title' => 'Import products',
		'status' => 'running',
		'is_active' => true,
		'is_terminal' => false,
		'is_stale' => false,
		'current_step_key' => 'import',
		'current_step_label' => 'Importing products.',
		'step_index' => 2,
		'step_total' => 5,
		'created_at' => '...',
		'queued_at' => '...',
		'started_at' => '...',
		'heartbeat_at' => '...',
		'finished_at' => null,
		'updated_at' => '...',
	],
	'logs' => [
	],
	'last_log_seq' => 12,
	'result' => null,
	'error' => null,
]
```

When not found:

```php
[
	'status' => 'not_found',
	'job' => null,
	'logs' => [],
	'last_log_seq' => 0,
	'result' => null,
	'error' => null,
]
```

The current public status read model does not expose every persistence field.

For example, `claim_mode`, `attempts`, and `worker_token_hash` are not part of the current `GetJobStatus` job shape.

Do not build UI against repository internals when the operation already provides the intended read contract.

---

## 23) Incremental log polling

Use `last_log_seq` as the next cursor.

Initial poll:

```php
$status = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute(
	$jobId,
	0,
	200
);
```

Later:

```php
$status = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute(
	$jobId,
	$previousLastLogSeq,
	200
);
```

The incremental repository query uses:

```text
seq > afterSeq
```

Correct:

```text
next afterSeq = last_log_seq
```

Incorrect:

```text
next afterSeq = last_log_seq + 1
```

If no new logs are returned, `last_log_seq` remains the incoming cursor.

---

## 24) Recent logs vs. incremental logs

When:

```text
afterSeq = 0
```

the current status operation returns the most recent logs up to the requested limit, normalized into ascending sequence order.

When:

```text
afterSeq > 0
```

it returns logs after that sequence cursor.

This allows both:

- Initial status page hydration.
- Incremental polling.

---

## 25) Cancelling a job

Use:

```php
\CitOmni\JobRunner\Operation\CancelJob
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\CancelJob($this->app))->execute($jobId);
```

Current behavior:

```text
queued           -> cancelled
running          -> cancel_requested
cancel_requested -> already_cancel_requested
terminal         -> already_terminal
missing          -> not_found
```

Possible result statuses:

```text
cancelled
cancel_requested
already_cancel_requested
already_terminal
not_found
```

Queued job:

```text
queued -> cancelled
```

Running job:

```text
running -> cancel_requested
```

The handler observes cancellation:

```php
if ($context->isCancellationRequested()) {
	return [
		'cancelled' => true,
	];
}
```

After the handler returns, the worker completes:

```text
cancel_requested -> cancelled
```

Cancellation is cooperative.

JobRunner does not hard-kill arbitrary child processes in the current model.

---

## 26) Cancellation races

The guarded state transitions intentionally resolve common races.

### Cancellation wins before worker claim

```text
queued -> cancelled
```

The later worker claim requires:

```text
status = queued
```

and therefore fails.

No handler executes.

### Worker claim wins first

```text
queued -> running
```

Cancellation then becomes:

```text
running -> cancel_requested
```

The existing cooperative cancellation flow applies.

### Cancellation reaches the final success window

`markSucceeded()` accepts only:

```text
running -> succeeded
```

If cancellation has already changed the state to:

```text
cancel_requested
```

the success transition misses.

The worker re-checks state and completes cancellation instead.

---

## 27) Logging

Use JobRunner logs for durable job history.

Structured examples:

```php
$context->info('Import started.', [
	'source_key' => $sourceKey,
]);

$context->warning('Skipped row.', [
	'row' => $rowNumber,
]);

$context->error('Remote validation failed.');
```

Stream examples:

```php
$context->stdout('Processed 500 rows.');
$context->stderr('Remote tool returned a warning.');
```

Avoid logging:

- Worker tokens.
- Trusted handoff tokens.
- Passwords.
- API keys.
- Private keys.
- Full environment dumps.
- Giant unbounded output.

JobRunner normalizes UTF-8 and chunks long messages according to:

```text
jobrunner.log_chunk_max_bytes
```

That is a safety mechanism, not permission to log unlimited noise.

---

## 28) Error handling

Handlers should usually fail fast.

The shared worker boundary catches handler/runtime failures and persists:

```text
status = failed
error_class
error_reason_code
error_message
finished_at
```

`JobRunnerException` reason codes are preserved where available.

Example:

```text
result_not_encodable
invalid_payload_json
invalid_payload_shape
```

Do not wrap an entire handler in a broad catch merely to suppress failures.

Prefer:

```text
validate early
perform work
let unrecoverable failure bubble
```

Catch locally only when recovery is explicit and correct.

---

## 29) Result encoding

A handler must return an array that can be JSON-encoded.

If result encoding fails, the job is failed with:

```text
result_not_encodable
```

A handler that completes its domain work but returns non-encodable data has not satisfied the JobRunner result contract.

Keep results:

- Structured.
- Small enough to persist sensibly.
- Free of secrets.
- JSON-safe.

---

## 30) Payload encoding

Payload is JSON-encoded when the job is created.

An empty payload is stored as:

```text
NULL
```

Invalid structured payload data fails early rather than being silently altered.

The execution lifecycle decodes payload back into an array.

Handlers should validate their own domain fields.

JobRunner validates storage shape, not application meaning.

---

## 31) Security: registered intent, not arbitrary execution

JobRunner is safest when job payload describes an allowed application intent.

Good:

```php
[
	'app_id' => 42,
	'environment' => 'production',
]
```

The handler resolves:

- Authoritative target.
- Allowed host.
- Credentials.
- Paths.
- Tool options.

Dangerous:

```php
[
	'host' => $untrustedHost,
	'command' => $arbitraryCommand,
	'private_key' => $rawKey,
]
```

Trusted/pull mode separates producer and executor, but the trusted queue is still a security-sensitive bridge.

A caller that can enqueue a trusted registered job may be able to request the action represented by that job type.

Therefore:

- Authorize before enqueue.
- Validate bounded payload.
- Keep handler contracts narrow.
- Resolve privileged execution details from authoritative application data.
- Never treat JobRunner as an arbitrary shell transport.

---

## 32) Security: do not put secrets in JobRunner data

Do not store raw credentials in:

```text
payload_json
result_json
context_json
job logs
error_message
title
lock_key
```

Prefer stable identifiers or references.

Example:

```php
[
	'profile_id' => 3,
]
```

The actual trusted handler can resolve credentials through the appropriate application service inside the execution context.

JobRunner is an orchestration and lifecycle package, not a secret store.

---

## 33) Token/push security rules

For `StartJob`:

- Raw worker token is minted locally.
- Only the SHA-256 hash is persisted.
- Raw token is passed to the fixed internal child command.
- Raw token is not returned to the caller.
- Raw token is not logged.
- The token worker must satisfy `claim_mode = token`.
- The row must still be `queued`.
- The hash must match.

A failed token claim does not execute the handler.

---

## 34) Trusted/pull security rules

For `EnqueueTrustedJob` and `job:work`:

- Trusted job is enqueued without a worker token hash.
- Supervisor selects only `claim_mode = trusted` and `status = queued`.
- Supervisor generates an ephemeral handoff token.
- Only the SHA-256 hash is persisted.
- The row remains `queued` during preparation.
- The fresh trusted child must present the matching handoff token.
- The child itself performs the guarded `queued -> running` transition.
- A stale or replayed handoff token cannot execute the job after the row has changed state.

Trusted does not mean "unclaimed".

It means the trusted supervisor controls dispatch, while the concrete child still proves ownership.

---

## 35) Why trusted rows remain queued during dispatch preparation

The supervisor does not mark a job `running`.

That transition belongs to the actual child.

This preserves the meaning of:

```text
started_at
attempts
heartbeat_at
```

Before the child successfully claims:

```text
status = queued
attempts = 0
started_at = NULL
```

A failed or stale pre-claim child does not create a fake execution attempt.

---

## 36) Trusted handoff replacement

A trusted queued row may already contain a handoff hash.

The supervisor still considers it eligible.

This allows recovery from:

```text
handoff prepared
-> supervisor/process failure before successful child claim
```

A later supervisor iteration can replace the old hash with a new one.

That replacement fences the stale child capability.

Therefore trusted queue discovery intentionally does not require:

```text
worker_token_hash IS NULL
```

---

## 37) Trusted child exit codes

`job:work` starts `job:run-trusted` synchronously and waits for the child.

A non-zero child process exit code does not automatically stop the supervisor.

Examples include:

```text
handler failed
-> job persisted as failed
-> child exits non-zero
```

The supervisor continues to later jobs.

If the child exits non-zero and the job is still `queued`, the current supervisor sleeps for the configured idle interval before trying again.

This avoids a tight hot loop around a repeatedly unclaimed queued job.

---

## 38) Suggested HTTP integration

JobRunner does not need to own application-specific HTTP routes.

A consumer application may expose routes such as:

```text
POST /jobs/action
GET  /jobs/{id}
POST /jobs/{id}/cancel
```

Controller responsibilities:

- Parse request.
- Authenticate.
- Authorize.
- Verify CSRF where required.
- Validate application input.
- Choose `StartJob` or `EnqueueTrustedJob`.
- Call `GetJobStatus`.
- Call `CancelJob`.
- Shape HTTP response.

Operations remain transport-agnostic.

Do not let controllers update JobRunner tables directly.

---

## 39) Token/push HTTP example

```php
$result = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(
	'commerce.import_products',
	$payload,
	$lockKey,
	'Import products'
);
```

Use this when the HTTP-started detached child will inherit the intended execution environment.

The controller may then redirect to a status page or return the job id.

---

## 40) Trusted/pull HTTP example

```php
$result = (new \CitOmni\JobRunner\Operation\EnqueueTrustedJob($this->app))->execute(
	'deploy.publish_app',
	$payload,
	$lockKey,
	'Publish application'
);
```

The HTTP request returns after durable enqueue.

It does not launch the actual job child.

The separately running trusted supervisor handles execution later.

---

## 41) CLI application integration

Application CLI commands may use the same operations.

Example token/push producer:

```php
$result = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(...);
```

Example trusted producer:

```php
$result = (new \CitOmni\JobRunner\Operation\EnqueueTrustedJob($this->app))->execute(...);
```

The mode decision is about ownership and execution environment, not whether the producer happened to be HTTP or CLI.

---

## 42) Environment and hosting notes

### Token/push requirements

`StartJob` requires the producer environment to be able to submit a detached PHP CLI process.

If process launch is blocked:

```text
launch_failed
```

or a launch exception is surfaced.

Do not silently fall back to executing the job inside the request.

### Trusted/pull requirements

Trusted enqueue only needs:

- JobRunner persistence.
- Registered handler configuration.

Actual execution additionally requires a running `job:work` process with:

- Database access.
- PHP CLI access.
- Access to the configured CLI entrypoint.
- Whatever execution environment the trusted handler itself needs.

A trusted job may legitimately remain queued while no supervisor is available.

That is different from a token/push job whose detached child failed to launch.

---

## 43) Troubleshooting: token job stays queued

Likely causes:

- PHP binary is wrong.
- CLI entrypoint is wrong.
- Detached process submission failed.
- `job:run` is not registered.
- Worker token was not delivered correctly.
- Child process cannot start.

Check:

```text
jobrunner.php_binary
jobrunner.cli_entrypoint
COMMANDS_CLI contains job:run
```

A token/push job normally should not sit queued indefinitely after a successful `StartJob` launch.

---

## 44) Troubleshooting: trusted job stays queued

First question:

```text
Is job:work running?
```

If not, queued state is expected.

If it is running, check:

- The supervisor has database access.
- `job:work` is registered.
- `job:run-trusted` is registered.
- `jobrunner.php_binary` is correct.
- `jobrunner.cli_entrypoint` exists.
- The trusted child can be launched.
- The job type handler exists in the supervisor/child config.
- The row uses `claim_mode = trusted`.

Do not manually rewrite the row to `running`.

The child must own the claim.

---

## 45) Troubleshooting: `job:work` exits immediately

The current command calls an infinite supervisor operation.

An immediate exit normally indicates an exception before or inside the loop.

Check:

```text
jobrunner.trusted_worker_idle_sleep_seconds >= 1
database connectivity
configured table names
PHP binary / CLI entrypoint when dispatch occurs
```

Let the global CLI error handling surface the real exception.

---

## 46) Troubleshooting: trusted job repeatedly remains queued

If a trusted child exits non-zero while the row is still queued, the supervisor waits the idle interval before retrying.

Possible causes include:

- Trusted child cannot claim the prepared row.
- Child configuration differs from supervisor configuration.
- Job state changes before claim.
- Internal handoff delivery is failing.

Inspect persisted job state and application error handling.

Do not expose or print the raw handoff token as a debugging shortcut.

---

## 47) Troubleshooting: job fails immediately

Likely causes:

- Handler is not registered.
- Handler class does not exist.
- Handler does not implement `JobHandlerInterface`.
- Handler cannot be instantiated without arguments.
- Payload is invalid for the handler.
- Stored payload JSON is invalid.
- Handler result cannot be JSON-encoded.
- Handler throws.

Use `GetJobStatus` and inspect:

```text
error.class
error.reason_code
error.message
logs
```

---

## 48) Troubleshooting: duplicate job is not started

Likely cause:

```text
active lock is working
```

If parallel jobs are valid:

```text
use null lock key
```

If one active instance is intended:

```text
keep the lock key
```

Both token and trusted enqueue paths share the same active-lock semantics.

---

## 49) Troubleshooting: cancellation is slow

Likely cause:

```text
handler does not check isCancellationRequested() frequently enough
```

Cancellation is cooperative.

Split long workflows into bounded units and check between them.

---

## 50) Troubleshooting: logs repeat or skip

Use:

```text
next afterSeq = last_log_seq
```

Do not add one manually.

The repository already uses:

```text
seq > afterSeq
```

---

## 51) Layer boundaries

### Controllers

Own:

- HTTP parsing.
- CSRF.
- Authentication.
- Authorization.
- Response shaping.
- Redirects.
- Templates.

May call:

```php
new StartJob($this->app);
new EnqueueTrustedJob($this->app);
new GetJobStatus($this->app);
new CancelJob($this->app);
```

### Commands

Own:

- CLI arguments.
- CLI options.
- Output.
- Exit codes.

May call the same application-facing operations.

### Operations

Own transport-independent orchestration.

Examples:

```text
StartJob
EnqueueTrustedJob
GetJobStatus
CancelJob
WorkTrustedQueue
RunQueuedJob
RunTrustedJob
ExecuteRunningJob
```

### Repositories

Own all SQL and persistence.

### Services

Own reusable App-aware tools.

Examples:

```text
jobLogger
jobRegistry
jobLauncher
```

### Handlers

Own domain-specific long-running workflow logic.

---

## 52) Minimal checklist for a new token/push job type

1. Pick a namespaced job type.
2. Write a no-argument handler implementing `JobHandlerInterface`.
3. Register it under `jobrunner.handlers`.
4. Define a narrow payload contract.
5. Choose a lock key if duplicate active jobs should be blocked.
6. Start through `StartJob`.
7. Poll through `GetJobStatus`.
8. Cancel through `CancelJob`.
9. Check cancellation inside the handler.
10. Keep secrets out of payload/result/logs.
11. Keep SQL in repositories.
12. Keep transport formatting in adapters.

---

## 53) Minimal checklist for a new trusted/pull job type

1. Pick a namespaced job type.
2. Write a no-argument handler implementing `JobHandlerInterface`.
3. Register it under `jobrunner.handlers`.
4. Define a narrow intent-based payload.
5. Choose a lock key if duplicate active jobs should be blocked.
6. Ensure one trusted `job:work` supervisor is running.
7. Run the supervisor under the intended execution environment.
8. Enqueue through `EnqueueTrustedJob`.
9. Poll through `GetJobStatus`.
10. Cancel through `CancelJob`.
11. Keep secrets out of JobRunner persistence.
12. Let the handler resolve authoritative privileged details internally.
13. Do not manually launch `job:run-trusted`.
14. Do not mark rows `running` yourself.

---

## 54) Practical consumer API

The main consumer-facing JobRunner operations are:

```text
StartJob
  -> enqueue and immediately launch TOKEN-mode job

EnqueueTrustedJob
  -> enqueue TRUSTED-mode job for job:work

GetJobStatus
  -> read status, progress, logs, result, and errors

CancelJob
  -> cancel queued work or request cooperative cancellation
```

Operationally, trusted/pull additionally requires:

```text
job:work
```

running in the execution environment intended for trusted jobs.

Internal worker commands are:

```text
job:run
job:run-trusted
```

Application code normally does not invoke those directly.

---

## 55) Practical runtime summary

### Token/push

```text
Producer
  -> StartJob
  -> queued TOKEN row
  -> detached fresh child
  -> token claim
  -> ExecuteRunningJob
  -> terminal state
```

### Trusted/pull

```text
Producer
  -> EnqueueTrustedJob
  -> queued TRUSTED row

Persistent supervisor
  -> find oldest trusted queued row
  -> prepare ephemeral handoff
  -> start fresh trusted child synchronously

Trusted child
  -> trusted claim
  -> ExecuteRunningJob
  -> terminal state

Supervisor
  -> immediately check next job
  -> sleep only when queue is empty
```

Both modes preserve the same handler contract, status model, logging model, progress model, cancellation model, and terminal persistence semantics.

That is the practical contract consumer applications should build on.
