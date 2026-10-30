<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Octane;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

final class FramedRequest
{
    /** @return array<string|int, mixed> */
    public static function nativeFiles(Request $request): array
    {
        $files = [];

        foreach ($request->files->all() as $name => $file) {
            $files[$name] = self::fileData($file);
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private static function fileData(mixed $file): array
    {
        if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return ['name' => $file->getClientOriginalName(), 'type' => $file->getClientMimeType(),
                'tmp_name' => $file->getPathname(), 'error' => $file->getError(), 'size' => $file->getSize()];
        }

        $result = [];

        foreach (is_array($file) ? $file : [] as $key => $child) {
            foreach (self::fileData($child) as $property => $value) {
                $result[$property][$key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $envelope */
    public static function make(array $envelope): Request
    {
        if (($envelope['version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported worker protocol version.');
        }

        $body = base64_decode($envelope['body'] ?? '', true);

        if ($body === false) {
            throw new RuntimeException('Invalid request body encoding.');
        }

        $headers = array_change_key_case($envelope['headers'] ?? [], CASE_LOWER);
        $parameters = [];
        $files = [];
        $contentType = strtolower($headers['content-type'][0] ?? '');

        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $parameters);
        } elseif (str_starts_with($contentType, 'multipart/form-data')) {
            [$parameters, $files] = self::multipart($envelope['parts'] ?? []);
        }

        $cookies = [];

        foreach ($headers['cookie'] ?? [] as $header) {
            foreach (explode(';', $header) as $pair) {
                $cookie = explode('=', trim($pair), 2);

                if (count($cookie) === 2) {
                    $cookies[$cookie[0]] = rawurldecode($cookie[1]);
                }
            }
        }

        $server = ['REMOTE_ADDR' => parse_url('tcp://'.($envelope['remote_addr'] ?? ''), PHP_URL_HOST) ?: '127.0.0.1'];

        foreach ($headers as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) ? $key : 'HTTP_'.$key] = implode($name === 'cookie' ? '; ' : ', ', $values);
        }

        $server['REQUEST_TIME_FLOAT'] = microtime(true);
        $server['REQUEST_TIME'] = time();
        $host = (string) ($envelope['host'] ?? '');

        if ($host !== '' && ! self::isAuthority($host)) {
            throw new BadRequestException('Invalid Host header.');
        }

        // A request without a Host header, such as a warm-up ping, has no
        // authority of its own. A placeholder keeps a path that starts with
        // two slashes a path, rather than the host.
        $request = Request::create(
            $envelope['scheme'].'://'.($host === '' ? 'localhost' : $host).$envelope['uri'],
            $envelope['method'], $parameters, $cookies, $files, $server, $body,
        );
        $request->headers->add($headers);

        if ($host !== '') {
            $request->headers->set('host', $host);
        }

        return $request;
    }

    /** A host and optional port, with nothing that would change the URI around it. */
    private static function isAuthority(string $host): bool
    {
        $parts = parse_url('http://'.$host);

        return is_array($parts) && isset($parts['host']) && array_diff(array_keys($parts), ['scheme', 'host', 'port']) === [];
    }

    /**
     * Preserve wire order and let PHP interpret bracket names and collisions.
     * The adapter has already parsed boundaries and owns all temporary paths.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array{array<string|int, mixed>, array<string|int, mixed>}
     */
    private static function multipart(array $parts): array
    {
        $fields = [];
        $fileKeys = [];
        $uploads = [];

        foreach ($parts as $part) {
            $name = rawurlencode($part['name']);

            if (! array_key_exists('filename', $part)) {
                $value = base64_decode($part['value'] ?? '', true);

                if ($value === false) {
                    throw new RuntimeException('Invalid multipart field encoding.');
                }

                $fields[] = $name.'='.rawurlencode($value);

                continue;
            }

            $fileKeys[] = $name.'='.count($uploads);
            $uploads[] = new UploadedFile(
                $part['path'], $part['filename'], $part['content_type'] ?? null,
                $part['filename'] === '' ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK, true,
            );
        }

        parse_str(implode('&', $fields), $parameters);
        parse_str(implode('&', $fileKeys), $fileTree);

        return [$parameters, self::mapUploads($fileTree, $uploads)];
    }

    /** @param array<string|int, mixed> $tree
     * @param  list<UploadedFile>  $uploads
     * @return array<string|int, mixed>
     */
    private static function mapUploads(array $tree, array $uploads): array
    {
        foreach ($tree as $key => $value) {
            $tree[$key] = is_array($value) ? self::mapUploads($value, $uploads) : $uploads[(int) $value];
        }

        return $tree;
    }
}
