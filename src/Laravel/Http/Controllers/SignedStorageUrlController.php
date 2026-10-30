<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Storage;
use Illuminate\Contracts\Validation\Factory as Validation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Signs a direct upload, so a browser can send a file straight to the bucket
 * instead of through the application.
 *
 * It answers with a URL the browser PUTs the file to, the headers to send with
 * it, and the tmp/ key the application later moves the file from. The disk does
 * the signing, so the bridge never touches the AWS SDK; the application's s3
 * disk brings it.
 *
 * No ACL is ever signed: the bucket stays private, so `visibility` accepts only
 * `private`. And `bucket` may only name the disk's own bucket, rather than any
 * bucket the role can write to.
 */
final readonly class SignedStorageUrlController
{
    public function __construct(
        private Config $config,
        private Gate $gate,
        private Storage $storage,
        private Validation $validation,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $diskName = $this->string('ske.uploads.disk', 's3');
        $bucket = $this->config->get("filesystems.disks.{$diskName}.bucket");
        $bucket = is_string($bucket) ? $bucket : null;

        // Clients may send an empty string for every field they leave unset,
        // which the validator skips like a missing one.
        $input = $this->validation->make($request->all(), [
            'bucket' => ['nullable', 'string', $this->sameBucket($bucket)],
            'content_type' => ['nullable', 'string'],
            'cache_control' => ['nullable', 'string'],
            'expires' => ['nullable', 'string'],
            'visibility' => ['nullable', 'string', 'in:private'],
        ])->validate();

        // The uploading user and the bucket, so the ability can be a
        // UserPolicy::uploadFiles() method or a Gate::define('uploadFiles', …).
        $this->gate->authorize($this->string('ske.uploads.gate', 'uploadFiles'), [$request->user(), $bucket]);

        $uuid = (string) Str::uuid();
        $key = "tmp/{$uuid}";
        $contentType = $this->filled($input, 'content_type') ?? 'application/octet-stream';

        $signed = $this->disk($diskName)->temporaryUploadUrl(
            $key,
            Carbon::now()->addMinutes($this->minutes()),
            array_filter([
                'ContentType' => $contentType,
                'CacheControl' => $this->filled($input, 'cache_control'),
                'Expires' => $this->filled($input, 'expires'),
            ], fn (?string $value): bool => $value !== null),
        );

        return new JsonResponse([
            'uuid' => $uuid,
            'bucket' => $bucket,
            'key' => $key,
            'url' => $signed['url'],
            'headers' => $this->withContentType($signed['headers'] ?? [], $contentType),
        ], 201);
    }

    /**
     * The browser PUTs exactly the headers it is given. The SDK does not always
     * sign Content-Type, and without it the object would be stored with the
     * browser's guess, so it is added with the requested type.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function withContentType(array $headers, string $contentType): array
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                return $headers;
            }
        }

        return $headers + ['Content-Type' => $contentType];
    }

    /**
     * Only the disk's own bucket: anything else would let a caller aim the
     * application's credentials at another bucket the role can write to.
     */
    private function sameBucket(?string $bucket): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($bucket): void {
            if ($value !== $bucket) {
                $fail("The {$attribute} must be the upload disk's bucket.");
            }
        };
    }

    private function disk(string $name): FilesystemAdapter
    {
        $disk = $this->storage->disk($name);

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException("The [{$name}] disk does not support creating temporary upload URLs.");
        }

        return $disk;
    }

    /** @param  array<array-key, mixed>  $input */
    private function filled(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function minutes(): int
    {
        $value = $this->config->get('ske.uploads.expires_after');

        return is_numeric($value) ? (int) $value : 5;
    }
}
