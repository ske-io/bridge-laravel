<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Queue;

use BackedEnum;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Queue;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

/**
 * Sends jobs to Google Cloud Tasks, the GCP counterpart of the SQS queue.
 *
 * Every task goes to the Cloud Tasks queue `{prefix}{queue}`, which the platform
 * creates for each of the environment's queues and points at the Cloud
 * Run service that works it: the queue's own HTTP target replaces the scheme
 * and host of whatever URL a task names, and signs each push with an identity
 * token for the environment's service account. So a task only carries the job
 * payload — no service URL and no token of its own, which also means creating
 * one needs nothing beyond `cloudtasks.tasks.create`.
 *
 * The platform, in front of the queue service, recognises the push, verifies the
 * token and hands the job to QueueRunner the same way it hands over an SQS
 * message. Nothing is ever popped: the platform delivers.
 *
 * Calls the Cloud Tasks REST API with an access token from the metadata server,
 * which the service's own account provides, rather than through the Google
 * client libraries.
 */
final class CloudTasksQueue extends Queue implements QueueContract
{
    /**
     * Cloud Tasks schedules a task at most 30 days ahead. A longer delay is
     * refused rather than shortened: running a job early is a worse surprise
     * than failing to dispatch it.
     */
    public const int MAX_DELAY = 30 * 24 * 60 * 60;

    /**
     * The URL every task names. The queue's HTTP target overrides its scheme and
     * host, so only the path reaches the service, and the platform recognises a
     * push by its Cloud Tasks headers wherever it is sent. The path must not be
     * under /__ske/, which the platform refuses from outside.
     */
    public const string TASK_URL = 'https://cloudtasks.invalid/';

    /**
     * How long Cloud Tasks waits for a response before it counts the attempt as
     * failed: the most it allows. The queue service's own request timeout is
     * what bounds a job, and a shorter deadline here would retry a job that is
     * still running.
     */
    private const string DISPATCH_DEADLINE = '1800s';

    private const string API = 'https://cloudtasks.googleapis.com/v2';

    /** Milliseconds to wait before each retry of a failed API call. */
    private const array RETRY_BACKOFF = [200, 500];

    private const string TOKEN_URL = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $project,
        private readonly string $location,
        private readonly string $prefix,
        private readonly string $default = 'default',
        bool $dispatchAfterCommit = false,
    ) {
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Every task in the queue, scheduled ones included.
     *
     * @param  UnitEnum|string|null  $queue
     */
    public function size($queue = null): int
    {
        return (int) ($this->stats($queue)['tasksCount'] ?? 0);
    }

    /**
     * @param  UnitEnum|string|null  $queue
     */
    public function pendingSize($queue = null): int
    {
        $stats = $this->stats($queue);

        return max(0, (int) ($stats['tasksCount'] ?? 0) - (int) ($stats['concurrentDispatchesCount'] ?? 0));
    }

    /**
     * Cloud Tasks does not count scheduled tasks apart from the rest; they are
     * part of pendingSize().
     *
     * @param  UnitEnum|string|null  $queue
     */
    public function delayedSize($queue = null): int
    {
        return 0;
    }

    /**
     * Tasks being delivered right now.
     *
     * @param  UnitEnum|string|null  $queue
     */
    public function reservedSize($queue = null): int
    {
        return (int) ($this->stats($queue)['concurrentDispatchesCount'] ?? 0);
    }

    /**
     * Not reported by Cloud Tasks.
     *
     * @param  UnitEnum|string|null  $queue
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    /**
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  UnitEnum|string|null  $queue
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        $queue = $this->queueName($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            null,
            fn (string $payload, string $queue): string => $this->createTask($payload, $queue, 0),
        );
    }

    /**
     * @param  string  $payload
     * @param  UnitEnum|string|null  $queue
     * @param  array{delay?: \DateTimeInterface|\DateInterval|int}  $options
     */
    public function pushRaw($payload, $queue = null, array $options = []): string
    {
        return $this->createTask($payload, $queue, $this->secondsUntil($options['delay'] ?? 0));
    }

    /**
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  object|string  $job
     * @param  mixed  $data
     * @param  UnitEnum|string|null  $queue
     */
    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        $queue = $this->queueName($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data, $delay),
            $queue,
            $delay,
            fn (string $payload, string $queue, $delay): string => $this->createTask($payload, $queue, $this->secondsUntil($delay)),
        );
    }

    /**
     * The platform delivers each task to the queue service; there is nothing to
     * fetch.
     *
     * @param  UnitEnum|string|null  $queue
     */
    public function pop($queue = null): null
    {
        return null;
    }

    /**
     * The full resource name of a Cloud Tasks queue.
     *
     * @param  UnitEnum|string|null  $queue
     */
    public function queuePath($queue = null): string
    {
        return "projects/{$this->project}/locations/{$this->location}/queues/{$this->prefix}{$this->queueName($queue)}";
    }

    /**
     * Creates the task and returns its id, which is the message id the platform
     * reports when the task comes back as a queue record.
     *
     * @param  UnitEnum|string|null  $queue
     */
    private function createTask(string $payload, $queue, int $delay): string
    {
        if ($delay > self::MAX_DELAY) {
            throw new InvalidArgumentException(
                "Cloud Tasks schedules a job at most 30 days ahead; this one asks for {$delay} seconds.",
            );
        }

        $task = [
            'httpRequest' => [
                'httpMethod' => 'POST',
                'url' => self::TASK_URL,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => base64_encode($payload),
            ],
            'dispatchDeadline' => self::DISPATCH_DEADLINE,
        ];

        if ($delay > 0) {
            $task['scheduleTime'] = gmdate('Y-m-d\TH:i:s\Z', $this->availableAt($delay));
        }

        $response = $this->api()
            ->post(self::API.'/'.$this->queuePath($queue).'/tasks', ['task' => $task])
            ->throw();

        $name = (string) $response->json('name');

        return substr($name, (int) strrpos($name, '/') + 1);
    }

    /**
     * @param  UnitEnum|string|null  $queue
     * @return array<string, mixed>
     */
    private function stats($queue): array
    {
        $stats = $this->api()
            ->get(self::API.'/'.$this->queuePath($queue), ['readMask' => 'stats'])
            ->throw()
            ->json('stats');

        return is_array($stats) ? $stats : [];
    }

    /**
     * A request to the Cloud Tasks API. Throttling (429), a server error or a
     * dropped connection is tried again twice, 200 and 500 milliseconds apart,
     * before the dispatch fails.
     */
    private function api(): PendingRequest
    {
        return $this->http
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout(10)
            ->retry(self::RETRY_BACKOFF, when: fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())));
    }

    /**
     * An access token for the service's own account. This queue object keeps
     * it until a minute before it expires, but under php-fpm the object lives
     * for one request, so each request that dispatches asks the metadata
     * server once — a local call that takes about a millisecond.
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        $response = $this->http
            ->withHeaders(['Metadata-Flavor' => 'Google'])
            ->timeout(5)
            ->get(self::TOKEN_URL)
            ->throw();

        $this->accessToken = (string) $response->json('access_token');
        $this->accessTokenExpiresAt = time() + (int) $response->json('expires_in', 0) - 60;

        return $this->accessToken;
    }

    /**
     * @param  UnitEnum|string|null  $queue
     */
    private function queueName($queue): string
    {
        $name = match (true) {
            $queue instanceof BackedEnum => (string) $queue->value,
            $queue instanceof UnitEnum => $queue->name,
            default => (string) $queue,
        };

        return $name !== '' ? $name : $this->default;
    }
}
