# CitOmni JobRunner - End-to-End Smoke Test

> **Small job. Full path. No theatre.**

This document explains how to run a minimal end-to-end smoke test for `citomni/jobrunner` from inside a real CitOmni application.

The test verifies that an application can create, launch, execute, observe, and cancel a background job through the normal JobRunner execution path.

The smoke handler described here is deliberately app-local. It is not part of the public `citomni/jobrunner` runtime API, and it must not be promoted into package source without an explicit API decision.

---

**Document type:** How-To Guide  
**Version:** 1.0  
**Applies to:** CitOmni PHP 8.2+, `citomni/jobrunner`  
**Audience:** Application and provider developers  
**Status:** Stable smoke-test procedure  
**Author:** CitOmni Core Team  
**Copyright:** Copyright (c) 2012-present CitOmni  

---

## 1) Purpose

The purpose of this smoke test is to prove that `citomni/jobrunner` can execute a real application-defined background job through the intended runtime path:

```text
App-local CLI command
  -> StartJob
  -> jobrun_jobs row
  -> detached CLI worker launch
  -> job:run
  -> app-registered handler
  -> JobContext
  -> logs, heartbeat, steps, result, terminal status
```

A successful test demonstrates that the package integration is alive across the application boundary.

That boundary is the important part. Testing only a handler method would be cheap, cheerful, and largely irrelevant. This test deliberately crosses the real integration path.

---

## 2) What this test verifies

This smoke test verifies that:

* A CLI command in the application can start a queued JobRunner job.
* `StartJob` can create the job record.
* JobRunner can launch the detached worker process.
* The detached worker can execute the package command `job:run`.
* The worker can resolve an app-registered job handler.
* The handler can use a no-argument constructor.
* `JobContext` can expose payload data to the handler.
* `JobContext` can write structured logs.
* `JobContext` can write stdout-style logs.
* `JobContext` can update step progress.
* `JobContext` can update heartbeat state.
* `JobContext` can expose the application instance.
* `JobContext` can observe cancellation requests.
* Active lock enforcement prevents duplicate active jobs.
* Jobs without a lock key can run in parallel.
* A running job can move through `cancel_requested` and terminate as `cancelled`.

This smoke test does not verify:

* Scheduler behavior.
* Retry policy.
* Stale-worker recovery.
* HTTP status endpoints.
* Browser polling.
* UI rendering.
* Production process supervision.
* Deployment-specific shell behavior.

Those concerns deserve their own tests. Nobody wins when one smoke test becomes a small civilization.

---

## 3) Design principle

The smoke job is implemented in the application namespace.

It must not be registered by `citomni/jobrunner` itself.

A reusable background-job package should not install a runnable demo job type into every consuming application. Doing so would add runtime surface area, create accidental API expectations, and make diagnostic code look like product behavior.

The test therefore uses:

```text
App\JobRunner\SleepJobHandler
App\Cli\Command\StartSleepJobCommand
```

The package remains generic. The application supplies the job.

---

## 4) Preconditions

The application must already have:

* `citomni/jobrunner` installed.
* `citomni/cli` installed and working.
* The JobRunner database tables installed.
* A working CLI entrypoint, normally `bin/citomni`.
* A configured PHP binary usable by JobRunner.
* A database connection available in CLI mode.
* The app namespace autoloaded by Composer.

The examples below assume:

```text
Application namespace: App
CLI entrypoint:        bin/citomni
Jobs table:            jobrun_jobs
Logs table:            jobrun_logs
```

---

## 5) Add the app-local sleep handler

Create this file:

```text
src/JobRunner/SleepJobHandler.php
```

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

	public function run(JobContext $context): array {
		$payload    = $context->payload();
		$seconds    = $this->payloadInt($payload, 'seconds', self::DEFAULT_SECONDS, self::MIN_SECONDS, self::MAX_SECONDS);
		$intervalMs = $this->payloadInt($payload, 'interval_ms', self::DEFAULT_INTERVAL_MS, self::MIN_INTERVAL_MS, self::MAX_INTERVAL_MS);

		$durationMs = $seconds * 1000;
		$totalSteps = \max(1, (int)\ceil($durationMs / $intervalMs));
		$appClass   = \get_class($context->app());

		$context->info('Sleep smoke job started.', [
			'seconds'     => $seconds,
			'interval_ms' => $intervalMs,
			'total_steps' => $totalSteps,
			'app_class'   => $appClass,
		]);

		$completed = 0;

		for ($step = 1; $step <= $totalSteps; $step++) {
			if ($context->isCancellationRequested()) {
				$context->warning('Sleep smoke job noticed cancellation request.', [
					'completed_steps' => $completed,
					'total_steps'     => $totalSteps,
				]);

				return [
					'cancelled'       => true,
					'completed_steps' => $completed,
					'total_steps'     => $totalSteps,
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
			'total_steps'     => $totalSteps,
		]);

		return [
			'cancelled'       => false,
			'completed_steps' => $completed,
			'total_steps'     => $totalSteps,
			'seconds'         => $seconds,
			'interval_ms'     => $intervalMs,
		];
	}

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

The handler has a no-argument constructor by omission.

JobRunner constructs the handler and passes a `JobContext` to `run()`.

---

## 6) Register the handler in dev config

Recommended file:

```text
config/citomni_cfg.dev.php
```

Add or merge this node:

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

If the file already contains other config, merge only the `jobrunner.handlers` part.

The handler should normally be registered in dev config only. It is diagnostic application code, not part of the product surface.

---

## 7) Add the smoke-start CLI command

Create this file:

```text
src/Cli/Command/StartSleepJobCommand.php
```

```php
<?php
declare(strict_types=1);

namespace App\Cli\Command;

use CitOmni\JobRunner\Operation\StartJob;
use CitOmni\Kernel\Command\BaseCommand;

/**
 * StartSleepJobCommand: Start an app-local JobRunner sleep smoke job.
 *
 * Behavior:
 * - Creates a queued job through StartJob.
 * - Lets JobRunner launch the detached CLI worker.
 * - Prints the job identity returned by StartJob.
 *
 * Notes:
 * - This command is for dev smoke testing only.
 * - Register it in the app dev command map, not in citomni/jobrunner.
 *
 * @internal App-local smoke test only.
 */
final class StartSleepJobCommand extends BaseCommand {
	private const JOB_TYPE = 'smoke.sleep';

	protected function signature(): array {
		return [
			'options' => [
				'seconds' => [
					'type'        => 'int',
					'description' => 'Number of seconds the smoke job should sleep',
					'default'     => 10,
				],
				'interval-ms' => [
					'type'        => 'int',
					'description' => 'Milliseconds between heartbeat updates',
					'default'     => 500,
				],
				'lock-key' => [
					'type'        => 'string',
					'description' => 'Optional active-job lock key. Use an empty value to disable locking',
					'default'     => 'smoke.sleep',
				],
			],
		];
	}

	protected function execute(): int {
		$seconds    = $this->boundedInt($this->getInt('seconds'), 1, 3600);
		$intervalMs = $this->boundedInt($this->getInt('interval-ms'), 100, 5000);
		$lockKey    = $this->normalizeLockKey((string)$this->opt('lock-key', 'smoke.sleep'));

		$result = (new StartJob($this->app))->execute(
			self::JOB_TYPE,
			[
				'seconds'     => $seconds,
				'interval_ms' => $intervalMs,
			],
			$lockKey,
			'Sleep smoke job'
		);

		$status = (string)($result['status'] ?? '');

		if ($status === StartJob::RESULT_STARTED) {
			$this->success('Sleep smoke job started.');
			$this->printJobResult($result);

			return self::SUCCESS;
		}

		if ($status === StartJob::RESULT_ALREADY_ACTIVE) {
			$this->warning('Sleep smoke job is already active.');
			$this->printJobResult($result);

			return self::SUCCESS;
		}

		if ($status === StartJob::RESULT_LAUNCH_FAILED) {
			$this->error('Sleep smoke job was queued, but the worker launch failed.');
			$this->printJobResult($result);

			return self::FAILURE;
		}

		$this->error('Sleep smoke job returned an unknown start status.');
		$this->stderr('  status: ' . $status);

		return self::FAILURE;
	}

	private function boundedInt(int $value, int $min, int $max): int {
		if ($value < $min) {
			return $min;
		}

		if ($value > $max) {
			return $max;
		}

		return $value;
	}

	private function normalizeLockKey(string $lockKey): ?string {
		$lockKey = \trim($lockKey);

		return $lockKey === '' ? null : $lockKey;
	}

	private function printJobResult(array $result): void {
		$jobId     = isset($result['job_id']) ? (int)$result['job_id'] : 0;
		$jobUuid   = isset($result['job_uuid']) ? (string)$result['job_uuid'] : '';
		$jobType   = isset($result['job_type']) ? (string)$result['job_type'] : '';
		$jobStatus = isset($result['job_status']) ? (string)$result['job_status'] : '';

		if ($jobId > 0) {
			$this->stdout('  job id: ' . $jobId);
		}

		if ($jobUuid !== '') {
			$this->stdout('  uuid: ' . $jobUuid);
		}

		if ($jobType !== '') {
			$this->stdout('  type: ' . $jobType);
		}

		if ($jobStatus !== '') {
			$this->stdout('  current status: ' . $jobStatus);
		}
	}
}
```

The command is an adapter. It owns CLI options, terminal output, and exit codes. It delegates the actual job creation workflow to `StartJob`.

---

## 8) Register the smoke-start command

Recommended file:

```text
config/citomni_cli_commands.dev.php
```

Add or merge this command:

```php
<?php
declare(strict_types=1);

return [
	'job:smoke:sleep' => [
		'command'     => \App\Cli\Command\StartSleepJobCommand::class,
		'description' => 'Start a local sleep smoke job through CitOmni JobRunner.',
	],
];
```

This command should normally remain dev-only.

---

## 9) Run a basic end-to-end job

From the application root:

```bat
php bin\citomni job:smoke:sleep --seconds=8 --interval-ms=500
```

Expected command output:

```text
Sleep smoke job started.
  job id: 1
  uuid: 83ffb819-a0ed-42b9-858a-efb38e3a4307
  type: smoke.sleep
```

The exact id and UUID will differ.

The command returning successfully means that the job was created and the detached worker launch was accepted. The actual end-to-end result must be verified in the database.

---

## 10) Verify the job record

Use the returned job id.

```sql
SELECT
	id,
	job_uuid,
	job_type,
	status,
	lock_key,
	title,
	current_step_key,
	current_step_label,
	step_index,
	step_total,
	started_at,
	heartbeat_at,
	finished_at,
	updated_at,
	error_class,
	error_reason_code,
	error_message,
	result_json
FROM jobrun_jobs
WHERE id = 1;
```

Expected terminal status:

```text
succeeded
```

Expected step state:

```text
current_step_key = done
step_index = 16
step_total = 16
```

For `--seconds=8 --interval-ms=500`, the expected number of steps is:

```text
8000 ms / 500 ms = 16 steps
```

---

## 11) Verify job logs

```sql
SELECT
	job_id,
	seq,
	created_at,
	level,
	stream,
	step_key,
	message,
	context_json
FROM jobrun_logs
WHERE job_id = 1
ORDER BY seq ASC;
```

Expected log shape:

```text
1   Job started.
2   Sleep smoke job started.
3   Sleeping step 1 of 16.
4   Sleeping step 2 of 16.
...
18  Sleeping step 16 of 16.
19  Sleep smoke job completed.
20  Job succeeded.
```

A successful run demonstrates that:

* The worker process actually ran.
* The handler was resolved and executed.
* `JobContext` logging works.
* Step updates work.
* Heartbeat updates work.
* The runner persisted the handler result.
* The job reached a terminal status.

---

## 12) Verify active lock behavior

Run a long job:

```bat
php bin\citomni job:smoke:sleep --seconds=30 --interval-ms=1000
```

Immediately run the same command again:

```bat
php bin\citomni job:smoke:sleep --seconds=30 --interval-ms=1000
```

Expected second output:

```text
Sleep smoke job is already active.
  job id: 2
  uuid: 426929a4-4f13-4e20-b675-cc45d4054820
  type: smoke.sleep
  current status: running
```

This verifies that the active lock prevents duplicate active jobs for the same lock key.

The smoke command's default lock key is:

```text
smoke.sleep
```

---

## 13) Verify parallel jobs without a lock

Run two jobs with an empty lock key:

```bat
php bin\citomni job:smoke:sleep --seconds=10 --interval-ms=1000 --lock-key=
php bin\citomni job:smoke:sleep --seconds=10 --interval-ms=1000 --lock-key=
```

Expected result:

```text
Sleep smoke job started.
  job id: 3
  uuid: 24e00e34-4e4e-46b4-b9ca-690018db1cc3
  type: smoke.sleep

Sleep smoke job started.
  job id: 4
  uuid: aee79b73-488b-4057-9442-c441f18d58cc
  type: smoke.sleep
```

This verifies that parallel jobs are allowed when no lock key is present.

---

## 14) Verify cancellation

Start a long-running job:

```bat
php bin\citomni job:smoke:sleep --seconds=120 --interval-ms=1000
```

Example output:

```text
Sleep smoke job started.
  job id: 6
  uuid: be08c493-118d-4e10-979d-d96432f8cc02
  type: smoke.sleep
```

While the job is still running, request cancellation.

For this smoke test, direct SQL is acceptable because the purpose is to verify worker behavior. Product code should use a repository, operation, CLI command, or HTTP endpoint instead.

```sql
UPDATE jobrun_jobs
SET
	status = 'cancel_requested',
	updated_at = CURRENT_TIMESTAMP(6)
WHERE id = 6
  AND status = 'running';
```

Expected affected rows:

```text
1
```

Then inspect the job:

```sql
SELECT
	id,
	job_uuid,
	job_type,
	status,
	lock_key,
	title,
	current_step_key,
	current_step_label,
	step_index,
	step_total,
	started_at,
	heartbeat_at,
	finished_at,
	updated_at,
	TIMESTAMPDIFF(SECOND, heartbeat_at, CURRENT_TIMESTAMP(6)) AS heartbeat_age_sec,
	error_class,
	error_reason_code,
	error_message,
	result_json
FROM jobrun_jobs
WHERE id = 6;
```

Expected terminal status:

```text
cancelled
```

Expected `finished_at`:

```text
not null
```

Inspect the logs:

```sql
SELECT
	job_id,
	seq,
	created_at,
	level,
	stream,
	step_key,
	message,
	context_json
FROM jobrun_logs
WHERE job_id = 6
ORDER BY seq ASC;
```

Expected log shape:

```text
1  Job started.
2  Sleep smoke job started.
3  Sleeping step 1 of 120.
4  Sleeping step 2 of 120.
...
7  Sleeping step 5 of 120.
8  Sleep smoke job noticed cancellation request.
9  Job cancelled.
```

This verifies the full cancellation path:

* The database status moves from `running` to `cancel_requested`.
* The handler observes the cancellation request through `JobContext`.
* The handler exits cooperatively.
* The runner marks the job as `cancelled`.
* The job receives a terminal timestamp.

---

## 15) Interpreting results

The smoke test is successful when all of the following are true:

* A normal job reaches `succeeded`.
* Logs are written in deterministic `seq` order per job.
* Step state reaches `done` for completed jobs.
* A second locked job returns `already_active`.
* Two unlocked jobs can run concurrently.
* A cancellation request results in terminal status `cancelled`.
* No job remains indefinitely in `queued`, `running`, or `cancel_requested`.

---

## 16) Failure modes

### Job remains queued

Likely causes:

* The detached worker did not launch.
* `jobrunner.php_binary` is wrong.
* `jobrunner.cli_entrypoint` is wrong.
* The worker process starts in the wrong working directory.
* The CLI entrypoint is not executable in the current environment.

### Job fails immediately

Inspect:

```sql
SELECT
	id,
	status,
	error_class,
	error_reason_code,
	error_message
FROM jobrun_jobs
WHERE id = <job_id>;
```

Likely causes:

* The handler type is not registered.
* The handler class cannot be autoloaded.
* The handler does not implement `JobHandlerInterface`.
* The handler constructor requires arguments.
* CLI config differs from the config used when the job was started.

### Logs stop while status remains running

Inspect heartbeat age:

```sql
SELECT
	id,
	status,
	heartbeat_at,
	TIMESTAMPDIFF(SECOND, heartbeat_at, CURRENT_TIMESTAMP(6)) AS heartbeat_age_sec
FROM jobrun_jobs
WHERE id = <job_id>;
```

Likely causes:

* The worker process crashed.
* The PHP process was terminated externally.
* The handler blocked without heartbeat.
* The database connection failed during execution.

### Cancellation leaves job in cancel requested

If logs show that the handler noticed cancellation, but the job remains `cancel_requested`, the runner is not converting the handler's cancellation result into a terminal cancelled state.

If logs do not show that the handler noticed cancellation, the worker may no longer be alive, or the handler may not be checking cancellation frequently enough.

---

## 17) Cleanup

After the smoke test, remove the app-local files and dev registrations unless the application should keep them as a local diagnostic tool.

Files:

```text
src/JobRunner/SleepJobHandler.php
src/Cli/Command/StartSleepJobCommand.php
```

Config registrations:

```text
config/citomni_cfg.dev.php
config/citomni_cli_commands.dev.php
```

Database rows may be retained as development evidence or removed from the local dev database.

Do not remove production job history casually. Logs are cheap until they are the only evidence left.

---

## 18) Checklist

Use this checklist when validating a new JobRunner installation:

* [ ] `job:smoke:sleep` starts a job.
* [ ] The job reaches `succeeded`.
* [ ] `jobrun_logs` contains ordered entries for the job.
* [ ] Step progress reaches `done`.
* [ ] `heartbeat_at` is updated while the job runs.
* [ ] A duplicate locked job returns `already_active`.
* [ ] Two jobs with `--lock-key=` can run in parallel.
* [ ] A running job can be moved to `cancel_requested`.
* [ ] The cancelled job reaches `cancelled`.
* [ ] `finished_at` is set for terminal states.

---

### Closing note

This smoke test is intentionally small, but it crosses the critical integration boundary.

An application starts a job. JobRunner persists and launches it. A detached CLI worker executes it. The handler interacts with `JobContext`. The final state is persisted for later observation.

That is the smallest useful proof that the mechanism is alive.
