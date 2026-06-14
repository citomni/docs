# CitOmni JobRunner - Usage, Handlers, Status, and Cancellation (PHP 8.2+)

> **Run long jobs outside HTTP. Keep the UI informed. Avoid manual SQL gymnastics.**

This document explains how to use `citomni/jobrunner` in a CitOmni application or provider package: What JobRunner is, how jobs are registered, how jobs are started, how handlers are written, how status polling works, how cancellation works, and what belongs outside the package.

It focuses on the practical V1 runtime contract:

```text
StartJob
  -> jobrun_jobs
  -> detached CLI worker
  -> job:run
  -> registered JobHandlerInterface handler
  -> JobContext
  -> logs, heartbeat, steps, result, terminal status
  -> GetJobStatus / CancelJob
```

---

**Document type:** Technical Guide  
**Version:** 1.0  
**Applies to:** CitOmni PHP 8.2+ and `citomni/jobrunner` V1  
**Audience:** Application and provider developers  
**Status:** Practical V1 guide  
**Author:** CitOmni Core Team  
**Copyright:** Copyright (c) 2012-present CitOmni

---

## Architecture overview

`citomni/jobrunner` is a provider package for explicit long-running jobs in CitOmni.

It exists for workflows that should be started from HTTP/UI but must not run inside the HTTP request. Examples include app creation, imports, exports, deploy steps, rebuilds, reports, and other long-running administration or developer tasks.

The package owns the generic lifecycle:

* Persist a job row.
* Launch a detached CLI worker.
* Claim a queued job atomically.
* Resolve a registered handler by job type.
* Provide a `JobContext` to the handler.
* Persist logs, steps, heartbeat, result, and error state.
* Support official status reads.
* Support cooperative cancellation.

Consumer applications and packages own the actual workflow logic.

Examples:

```text
citomni/devkit
  -> devkit.create_app

citomni/commerce
  -> commerce.import_products
  -> commerce.export_feed

citomni/deploy
  -> deploy.publish_app
```

`citomni/jobrunner` knows how to run a registered job. It does not know what creating an app, importing products, or deploying a site means. That boundary is the feature, not a paperwork exercise.

---

## 1) What JobRunner is

JobRunner is a small DB-backed lifecycle layer for explicit jobs.

A job is:

* A row in `jobrun_jobs`.
* A registered `job_type`.
* Optional payload JSON.
* Optional active lock key.
* A detached CLI worker execution.
* A stream of persisted logs.
* Step progress and heartbeat timestamps.
* A terminal status such as `succeeded`, `failed`, or `cancelled`.

Typical start flow:

```text
HTTP Controller / CLI Command
  -> validates app-level input
  -> calls StartJob
  -> returns job id / uuid to caller

Detached worker
  -> runs php bin/citomni job:run <job-id> --token=<token>
  -> claims queued job
  -> resolves handler
  -> executes workflow
  -> writes logs and status
```

Typical UI flow:

```text
Browser
  -> POST start action
  -> receives job id
  -> polls status endpoint
  -> displays status, steps, logs, result, or error
  -> may request cancellation
```

---

## 2) What JobRunner is not

`citomni/jobrunner` is deliberately scoped.

It is not:

* A generic queue framework.
* A scheduler.
* A permanent daemon.
* A Redis/AMQP abstraction.
* A web-based shell.
* A generic process manager.
* A polished administration UI.
* A retry framework.
* A domain workflow engine.

Users should not submit raw CLI commands through HTTP. Users start registered job types, and the real workflow lives in PHP handlers.

Good:

```text
Start registered job type: devkit.create_app
```

Bad:

```text
Run arbitrary shell command from a browser textarea.
```

The browser does not need a terminal with a fancy hat.

---

## 3) Package setup

Install the package as a Composer dependency. Do not copy package code into an app.

```bash
composer require citomni/jobrunner
```

Register the provider in the application provider config if the application uses explicit provider registration:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\JobRunner\Boot\Registry::class,
];
```

The package registry contributes its runtime defaults through normal CitOmni provider metadata.

The relevant concepts are:

```text
MAP_COMMON    -> jobRunner services shared by HTTP and CLI
CFG_COMMON    -> default tables, handlers map, launcher config, log limits
COMMANDS_CLI  -> job:run worker command
```

CLI commands are registered through `COMMANDS_CLI`, not routes.

---

## 4) Database setup

JobRunner uses two tables:

```text
jobrun_jobs
jobrun_logs
```

The package schema lives in:

```text
sql/citomni_jobrunner.sql
```

Apply it to the application database using your normal database migration/import process.

The important job columns are:

```text
id
job_uuid
job_type
status
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

The important log columns are:

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

Log ordering is deterministic per job:

```sql
ORDER BY seq ASC
```

Do not write application SQL directly against these tables from controllers, commands, operations, or handlers. Use JobRunner operations and `JobContext`.

---

## 5) Runtime configuration

JobRunner configuration lives under the package-owned config root:

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
],
```

Application and provider code usually only needs to register handlers:

```php
<?php
declare(strict_types=1);

return [
	'jobrunner' => [
		'handlers' => [
			'devkit.create_app' => \App\JobRunner\CreateAppJobHandler::class,
		],
	],
];
```

For development smoke tests, an app may register a local handler in `config/citomni_cfg.dev.php`:

```php
<?php
declare(strict_types=1);

return [
	'jobrunner' => [
		'handlers' => [
			'smoke.sleep' => \App\JobRunner\SleepJobHandler::class,
		],
	],
];
```

Handler keys should be namespaced strings:

```text
smoke.sleep
devkit.create_app
commerce.import_products
commerce.export_feed
deploy.publish_app
```

Avoid generic names such as:

```text
run
import
create
```

Those become ambiguous quickly. Ambiguity is a small tax that compounds monthly.

---

## 6) Provider package registration

A reusable package can register its own job handlers through `Registry::CFG_COMMON`.

Example provider registry:

```php
<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace Vendor\Package\Boot;

/**
 * Declare this provider package's boot contributions.
 *
 * Behavior:
 * - Registers package-owned JobRunner handlers.
 * - Keeps routes, commands, services, and cfg in their proper maps.
 *
 * Notes:
 * - Registries are declarative.
 * - No I/O, no runtime branching, no environment reads.
 */
final class Registry {
	public const CFG_COMMON = [
		'jobrunner' => [
			'handlers' => [
				'commerce.import_products' => \Vendor\Package\JobRunner\ImportProductsJobHandler::class,
			],
		],
	];
}
```

Important:

* Register handlers in config, not in a service map.
* Do not register operations as services.
* Do not register CLI commands under routes.
* Do not put routes or CLI commands inside `CFG_COMMON`.

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
	/**
	 * Run one job.
	 *
	 * @param JobContext $context Job context with payload, logging, steps, heartbeat, and cancellation checks.
	 * @return array<string, mixed> Domain-shaped result data.
	 */
	public function run(JobContext $context): array;
}
```

Handlers are currently instantiated without constructor arguments.

Use `JobContext::app()` when the handler needs application services, repositories, config, or package facilities.

Typical handler behavior:

```text
1. Read and validate payload.
2. Log start.
3. Set the current step.
4. Perform one bounded unit of work.
5. Write logs or stdout/stderr lines.
6. Update heartbeat.
7. Check cancellation before the next unit.
8. Return result array.
```

Handlers must not write directly to `jobrun_jobs` or `jobrun_logs`.

---

## 8) Writing a handler

Example app-local smoke handler:

```php
<?php
declare(strict_types=1);

namespace App\JobRunner;

use CitOmni\JobRunner\Contract\JobHandlerInterface;
use CitOmni\JobRunner\Value\JobContext;

/**
 * SleepJobHandler: App-local smoke handler for CitOmni JobRunner.
 *
 * Behavior:
 * - Reads runtime options from the job payload.
 * - Writes structured job logs and stdout lines.
 * - Updates step progress and heartbeat while running.
 * - Cooperates with cancellation requests.
 *
 * Notes:
 * - This is intentionally app-local smoke-test code.
 * - Do not move this into citomni/jobrunner without a deliberate API decision.
 *
 * @internal App-local smoke test only.
 */
final class SleepJobHandler implements JobHandlerInterface {
	private const DEFAULT_SECONDS = 10;
	private const DEFAULT_INTERVAL_MS = 500;
	private const MIN_SECONDS = 1;
	private const MAX_SECONDS = 3600;
	private const MIN_INTERVAL_MS = 100;
	private const MAX_INTERVAL_MS = 5000;

	/**
	 * Run the sleep smoke job.
	 *
	 * @param JobContext $context Job context.
	 * @return array<string, mixed> Result data.
	 */
	public function run(JobContext $context): array {
		$payload = $context->payload();
		$seconds = $this->payloadInt($payload, 'seconds', self::DEFAULT_SECONDS, self::MIN_SECONDS, self::MAX_SECONDS);
		$intervalMs = $this->payloadInt($payload, 'interval_ms', self::DEFAULT_INTERVAL_MS, self::MIN_INTERVAL_MS, self::MAX_INTERVAL_MS);

		$durationMs = $seconds * 1000;
		$totalSteps = \max(1, (int)\ceil($durationMs / $intervalMs));

		$context->info('Sleep smoke job started.', [
			'seconds' => $seconds,
			'interval_ms' => $intervalMs,
			'total_steps' => $totalSteps,
		]);

		$completed = 0;

		for ($step = 1; $step <= $totalSteps; $step++) {
			if ($context->isCancellationRequested()) {
				$context->warning('Sleep smoke job noticed cancellation request.', [
					'completed_steps' => $completed,
					'total_steps' => $totalSteps,
				]);

				return [
					'cancelled' => true,
					'completed_steps' => $completed,
					'total_steps' => $totalSteps,
				];
			}

			$context->setStep(
				'sleep-' . $step,
				'Sleep step ' . $step . ' of ' . $totalSteps,
				$step,
				$totalSteps
			);

			$context->stdout('Sleeping step ' . $step . ' of ' . $totalSteps . '.');
			$context->heartbeat();

			\usleep($intervalMs * 1000);

			$completed++;
		}

		$context->setStep('done', 'Sleep smoke job completed.', $totalSteps, $totalSteps);
		$context->heartbeat();
		$context->info('Sleep smoke job completed.', [
			'completed_steps' => $completed,
			'total_steps' => $totalSteps,
		]);

		return [
			'cancelled' => false,
			'completed_steps' => $completed,
			'total_steps' => $totalSteps,
			'seconds' => $seconds,
			'interval_ms' => $intervalMs,
		];
	}

	/**
	 * Read a bounded integer from payload.
	 *
	 * @param array<string, mixed> $payload Payload array.
	 * @param string $key Payload key.
	 * @param int $default Default value.
	 * @param int $min Minimum value.
	 * @param int $max Maximum value.
	 * @return int Bounded integer.
	 */
	private function payloadInt(array $payload, string $key, int $default, int $min, int $max): int {
		$value = $payload[$key] ?? $default;

		if (\is_int($value)) {
			$number = $value;
		} elseif (\is_string($value) && \preg_match('/^\d+$/', $value) === 1) {
			$number = (int)$value;
		} else {
			$number = $default;
		}

		if ($number < $min) {
			return $min;
		}

		if ($number > $max) {
			return $max;
		}

		return $number;
	}
}
```

---

## 9) JobContext API

A handler receives a `JobContext`.

Useful methods:

```php
$context->app();
$context->jobId();
$context->jobUuid();
$context->jobType();
$context->payload();

$context->info('Message.');
$context->warning('Message.');
$context->error('Message.');
$context->debug('Message.');

$context->stdout('Line for stdout-style output.');
$context->stderr('Line for stderr-style output.');

$context->setStep('step-key', 'Human-readable step label.', 1, 10);
$context->heartbeat();

if ($context->isCancellationRequested()) {
	// Return cleanly between bounded units of work.
}
```

Use logs for durable user-facing job history.

Use heartbeat to show that a running job is still alive.

Use steps to show progress.

Use cancellation checks between bounded units of work. Do not check cancellation only once at the beginning of a 30-minute method. That is not cancellation; that is optimism with a timestamp.

---

## 10) Starting a job

Use:

```php
\CitOmni\JobRunner\Operation\StartJob
```

Operations are instantiated explicitly:

```php
$result = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(
	'devkit.create_app',
	[
		'app_key' => 'example_app',
		'packages' => ['citomni/http', 'citomni/cli'],
	],
	'devkit.create_app:example_app',
	'Create example_app'
);
```

Expected result statuses:

```text
started
already_active
launch_failed
```

Example handling in an HTTP controller:

```php
$result = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(
	'devkit.create_app',
	$payload,
	$lockKey,
	'Create app'
);

if ($result['status'] === \CitOmni\JobRunner\Operation\StartJob::RESULT_STARTED) {
	// Adapter decides how to return JSON, redirect, or render a template.
}
```

The operation returns job identity when a job is started:

```php
[
	'status' => 'started',
	'job_id' => 123,
	'job_uuid' => '...',
	'job_type' => 'devkit.create_app',
]
```

If an active lock already exists:

```php
[
	'status' => 'already_active',
	'job_id' => 122,
	'job_uuid' => '...',
	'job_type' => 'devkit.create_app',
	'job_status' => 'running',
]
```

---

## 11) Lock keys

A lock key prevents duplicate active jobs.

Active statuses are:

```text
queued
running
cancel_requested
```

Terminal statuses do not hold the lock:

```text
succeeded
failed
cancelled
```

Typical lock key examples:

```text
devkit.create_app:example_app
commerce.import_products
commerce.export_feed:shop_dk
```

Use a lock key when only one active instance should exist.

Pass `null` or an empty value when parallel jobs are allowed.

Example:

```php
// One active create-app job per app key.
$lockKey = 'devkit.create_app:' . $appKey;

// Parallel report jobs allowed.
$lockKey = null;
```

The database enforces active locks through `active_lock_key`, so the lock is not just a friendly suggestion in a comment.

---

## 12) Worker execution

The detached worker is launched by JobRunner.

Conceptual command:

```text
php bin/citomni job:run <job-id> --token=<worker-token>
```

The raw worker token is only passed to the worker process.

The database stores only:

```text
hash('sha256', $workerToken)
```

Rules:

* Do not show worker tokens in UI.
* Do not log worker tokens.
* Do not manually run `job:run` unless you are debugging the worker contract.
* Normal application code starts jobs through `StartJob`.

The worker claims queued jobs atomically. A wrong token or non-queued status results in claim failure.

---

## 13) Reading job status

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

Suggested parameters:

```php
$afterSeq = 0;
$logLimit = 200;
```

When found, the operation returns a stable read model:

```php
[
	'status' => 'found',
	'job' => [
		'id' => 123,
		'uuid' => '...',
		'type' => 'devkit.create_app',
		'title' => 'Create app',
		'status' => 'running',
		'is_active' => true,
		'is_terminal' => false,
		'is_stale' => false,
		'current_step_key' => 'composer-create-project',
		'current_step_label' => 'Running composer create-project.',
		'step_index' => 2,
		'step_total' => 8,
		'created_at' => '2026-06-14 15:54:01.671897',
		'queued_at' => '2026-06-14 15:54:01.671897',
		'started_at' => '2026-06-14 15:54:02.013001',
		'heartbeat_at' => '2026-06-14 15:54:04.120111',
		'finished_at' => null,
		'updated_at' => '2026-06-14 15:54:04.120111',
	],
	'logs' => [
		[
			'seq' => 1,
			'created_at' => '2026-06-14 15:54:02.013001',
			'level' => 'info',
			'stream' => '',
			'step_key' => null,
			'message' => 'Job started.',
			'context' => null,
		],
	],
	'last_log_seq' => 1,
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

The read model is transport-agnostic. A controller may turn it into JSON. A CLI command may print it. A template may render it. The operation itself does none of those things.

---

## 14) Incremental polling

For UI polling, use `last_log_seq` as the next cursor.

Initial request:

```php
$status = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute($jobId, 0, 200);
```

Response:

```php
[
	'logs' => [
		['seq' => 1, 'message' => 'Job started.'],
		['seq' => 2, 'message' => 'Workflow started.'],
	],
	'last_log_seq' => 2,
]
```

Next request:

```php
$status = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute($jobId, 2, 200);
```

This returns logs where:

```text
seq > 2
```

Important cursor rule:

```text
last_log_seq = highest returned seq
```

If no new logs are returned, `last_log_seq` should remain the incoming cursor.

Do not treat `last_log_seq` as the next expected row number. It is the last row the caller has seen.

---

## 15) Cancelling a job

Use:

```php
\CitOmni\JobRunner\Operation\CancelJob
```

Example:

```php
$result = (new \CitOmni\JobRunner\Operation\CancelJob($this->app))->execute($jobId);
```

Cancellation is cooperative in V1.

Implemented behavior:

```text
queued           -> cancelled
running          -> cancel_requested
cancel_requested -> already_cancel_requested
succeeded        -> already_terminal
failed           -> already_terminal
cancelled        -> already_terminal
missing          -> not_found
```

A running job is not marked directly as `cancelled`.

Instead:

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

Then the worker completes the terminal transition:

```text
cancel_requested -> cancelled
```

Example result for a running job:

```php
[
	'status' => 'cancel_requested',
	'job_id' => 123,
	'job_uuid' => '...',
	'job_type' => 'devkit.create_app',
	'previous_status' => 'running',
	'new_status' => 'cancel_requested',
]
```

Example result for a terminal job:

```php
[
	'status' => 'already_terminal',
	'job_id' => 123,
	'job_uuid' => '...',
	'job_type' => 'devkit.create_app',
	'previous_status' => 'succeeded',
	'new_status' => 'succeeded',
]
```

Hard kill of active child processes is intentionally out of scope for V1.

---

## 16) Suggested HTTP endpoints

`citomni/jobrunner` does not need to own public HTTP routes in V1.

A consumer app or provider can expose endpoints using the operations:

```text
POST /_devkit/jobs              -> StartJob
GET  /_devkit/jobs/{id}         -> GetJobStatus
POST /_devkit/jobs/{id}/cancel  -> CancelJob
```

Controller responsibilities:

* Parse and validate HTTP input.
* Enforce authentication and authorization.
* Verify CSRF for state-changing requests.
* Call the relevant operation.
* Convert the domain-shaped result into JSON, redirect, flash message, or template data.

Operation responsibilities:

* Own transport-agnostic workflow decisions.
* Return stable arrays.
* Avoid HTTP concepts.

Do not let controllers perform SQL status updates. That was useful during early smoke testing. It is not the API.

---

## 17) App-local CLI smoke commands

During development, app-local CLI commands are useful for testing.

Example command map:

```php
<?php
declare(strict_types=1);

return [
	'job:smoke:sleep' => [
		'command' => \App\Cli\Command\StartSleepJobCommand::class,
		'description' => 'Start a local sleep smoke job through CitOmni JobRunner.',
	],
	'job:smoke:status' => [
		'command' => \App\Cli\Command\JobStatusSmokeCommand::class,
		'description' => 'Read a local JobRunner smoke job status.',
	],
	'job:smoke:cancel' => [
		'command' => \App\Cli\Command\JobCancelSmokeCommand::class,
		'description' => 'Request cancellation for a local JobRunner smoke job.',
	],
];
```

Basic smoke sequence:

```bash
php bin/citomni job:smoke:sleep --seconds=8 --interval-ms=500
php bin/citomni job:smoke:status <job-id> --after-seq=0 --limit=100
```

Cancellation smoke sequence:

```bash
php bin/citomni job:smoke:sleep --seconds=120 --interval-ms=1000
php bin/citomni job:smoke:cancel <job-id>
php bin/citomni job:smoke:status <job-id> --after-seq=0 --limit=100
```

Expected cancellation flow:

```text
running -> cancel_requested -> cancelled
```

Expected logs:

```text
Sleep smoke job noticed cancellation request.
Job cancelled.
```

Smoke commands are application test helpers. They do not belong in `citomni/jobrunner` unless the package deliberately decides to ship official testing commands.

---

## 18) Status lifecycle

V1 statuses:

```text
queued
running
cancel_requested
succeeded
failed
cancelled
```

Active statuses:

```text
queued
running
cancel_requested
```

Terminal statuses:

```text
succeeded
failed
cancelled
```

Expected transitions:

```text
queued -> running
queued -> cancelled
running -> succeeded
running -> failed
running -> cancel_requested
cancel_requested -> cancelled
cancel_requested -> failed
```

Important rule:

```text
markSucceeded accepts only running -> succeeded.
```

If `cancel_requested` wins the final race before success is persisted, `RunQueuedJob` completes the job as `cancelled`.

That protects users from seeing a job as successful after they successfully requested cancellation in the final window.

---

## 19) Error handling

The worker boundary records failures on the job.

A handler exception should result in:

```text
status = failed
error_class = <exception class>
error_reason_code = <package reason code when available>
error_message = <safe message>
```

Handler code should usually fail fast.

Avoid this pattern inside handlers:

```php
try {
	// Almost the whole workflow.
} catch (\Throwable $e) {
	// Swallow and pretend all is fine.
}
```

Prefer:

```php
// Validate input early.
// Let real failures bubble to the worker boundary.
```

Catch locally only when the failure is genuinely recoverable and the fallback is explicit.

---

## 20) Logging guidance

Use job logs for meaningful job history.

Good logs:

```php
$context->info('Import started.', ['source' => $sourceKey]);
$context->stdout('Processed 500 rows.');
$context->warning('Skipped row with missing SKU.', ['row' => $rowNumber]);
```

Avoid:

* Secrets.
* Raw tokens.
* Full environment dumps.
* Giant command output without chunking or caps.
* Logging every tiny inner-loop iteration when a summary would do.

JobRunner chunks long log messages according to package config. That is a safety net, not a target.

---

## 21) Security and authorization

JobRunner operations do not decide who is allowed to start, view, or cancel a job.

That belongs in the adapter/application layer.

An HTTP controller should decide:

* Is the user authenticated?
* Is the user allowed to start this job type?
* Is the user allowed to view this job?
* Is the user allowed to cancel this job?
* Is the request protected by CSRF where needed?

Do not expose arbitrary job type starts to untrusted users.

Good:

```text
Admin clicks "Create app" -> controller starts devkit.create_app with validated payload.
```

Bad:

```text
POST job_type=anything&payload={...}
```

Registered handlers reduce risk, but they do not replace authorization.

---

## 22) Environment and hosting notes

JobRunner requires PHP to be able to start a CLI worker process.

This is generally suitable for:

* Local development.
* Controlled servers.
* VPS/dedicated environments.
* Hosting where PHP process launch is allowed.

Restrictive shared hosting may block process launch. In that case `StartJob` should return or surface launch failure, not fall back to running the job inline in HTTP.

Do not convert a detached job into a synchronous HTTP job because the host is grumpy. That reintroduces the timeout problem JobRunner exists to avoid.

---

## 23) Troubleshooting

### Job stays queued

Likely causes:

* CLI entrypoint path is wrong.
* PHP binary config is wrong.
* Process launch is blocked.
* `job:run` command is not registered.
* Worker token was not passed correctly.

Check:

```text
jobrunner.php_binary
jobrunner.cli_entrypoint
COMMANDS_CLI contains job:run
```

### Job fails immediately

Likely causes:

* Handler class is missing.
* Handler does not implement `JobHandlerInterface`.
* Payload is invalid for the handler.
* Handler result cannot be JSON encoded.

Check `error_class`, `error_reason_code`, and `error_message` through `GetJobStatus`.

### Duplicate job is not started

Likely cause:

* Active lock is working.

If this is intended, use a different lock key or pass `null` when parallel jobs are allowed.

### Cancellation request does not stop the job quickly

Likely cause:

* Handler does not call `isCancellationRequested()` between bounded work units.

Fix handler structure. Cancellation is cooperative.

### Status polling repeats or skips logs

Use `last_log_seq` exactly as returned.

Correct:

```text
next afterSeq = last_log_seq
```

Incorrect:

```text
next afterSeq = last_log_seq + 1
```

`fetchLogsAfterSeq()` already uses `seq > afterSeq`.

---

## 24) Layer boundaries

### Controllers

Controllers own HTTP transport:

* Input parsing.
* CSRF.
* Auth checks.
* JSON responses.
* Redirects.
* Templates.

Controllers may call:

```php
new StartJob($this->app);
new GetJobStatus($this->app);
new CancelJob($this->app);
```

### Commands

Commands own CLI transport:

* Arguments.
* Options.
* Terminal output.
* Exit codes.

Commands may call the same operations.

### Operations

Operations own transport-agnostic decision graphs.

They are instantiated explicitly and are not services.

### Repositories

Repositories own SQL and persistence.

No SQL belongs in controllers, commands, operations, services, or handlers.

### Handlers

Handlers own domain-specific workflow execution.

Handlers use `JobContext` for job state, logs, steps, heartbeat, and cancellation checks.

---

## 25) Minimal implementation checklist

For a new job type:

1. Pick a namespaced job type.
2. Write a handler implementing `JobHandlerInterface`.
3. Register the handler in `jobrunner.handlers`.
4. Start jobs through `StartJob`.
5. Pass only validated payload.
6. Use a lock key when duplicate active jobs should be prevented.
7. Poll status through `GetJobStatus`.
8. Request cancellation through `CancelJob`.
9. Check cancellation inside the handler.
10. Keep SQL in repositories.
11. Keep HTTP/CLI formatting in adapters.

Example job type:

```text
devkit.create_app
```

Example handler:

```text
App\JobRunner\CreateAppJobHandler
```

Example operations:

```php
$start = (new \CitOmni\JobRunner\Operation\StartJob($this->app))->execute(...);
$status = (new \CitOmni\JobRunner\Operation\GetJobStatus($this->app))->execute($jobId, $afterSeq, 200);
$cancel = (new \CitOmni\JobRunner\Operation\CancelJob($this->app))->execute($jobId);
```

---

## 26) Practical V1 contract

A consumer app can now build a practical UI around three operations:

```text
StartJob       -> start a registered job
GetJobStatus   -> read progress, logs, result, and errors
CancelJob      -> request cooperative cancellation
```

That is the stable V1 foundation for flows such as DevKit Create App.

The next layer belongs outside `citomni/jobrunner`:

```text
DevKit UI / Controller
  -> StartJob
  -> GetJobStatus
  -> CancelJob
```

JobRunner should stay small, boring, and reliable.

Boring is easier to debug at 23:41.
