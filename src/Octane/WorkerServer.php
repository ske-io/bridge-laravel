<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Octane;

use RuntimeException;
use Throwable;

final class WorkerServer
{
    public function __construct(private readonly OctaneWorker $worker, private readonly string $socket) {}

    public function run(): void
    {
        $this->worker->boot();

        if (file_exists($this->socket) || is_link($this->socket)) {
            if (is_link($this->socket) || filetype($this->socket) !== 'socket') {
                throw new RuntimeException('Worker socket path is occupied by another file.');
            }

            // A runtime reset can preserve /tmp after killing the old worker.
            // Reclaim only a socket that explicitly refuses connections.
            $probe = @stream_socket_client('unix://'.$this->socket, $probeCode, $probeError, 0.1);

            if (is_resource($probe)) {
                fclose($probe);

                throw new RuntimeException('Worker socket already has a listener.');
            }

            if (! in_array($probeCode, [61, 111], true) || ! unlink($this->socket)) {
                throw new RuntimeException('Cannot reclaim worker socket: '.$probeError);
            }
        }

        $listener = stream_socket_server('unix://'.$this->socket, $errorCode, $error);

        if ($listener === false) {
            throw new RuntimeException("Cannot listen on worker socket: {$error}");
        }

        chmod($this->socket, 0600);

        try {
            while ($connection = stream_socket_accept($listener, -1)) {
                try {
                    // Readiness probes connect and close without sending a frame.
                    $prefix = $this->read($connection, 4, true);

                    if ($prefix === '') {
                        continue;
                    }

                    $prefixData = unpack('Nlength', $prefix);

                    if ($prefixData === false) {
                        throw new RuntimeException('Invalid worker frame prefix.');
                    }

                    $length = $prefixData['length'];
                    $maxBody = max(1, (int) (getenv('SKE_MAX_REQUEST_BODY') ?: 10485760));

                    // Raw bodies and multipart fields can each be base64 encoded.
                    if ($length < 1 || $length > $maxBody * 3 + 1048576) {
                        throw new RuntimeException('Request frame exceeds its limit.');
                    }

                    $envelope = json_decode($this->read($connection, $length), true, 512, JSON_THROW_ON_ERROR);
                    $response = $this->worker->handle($envelope);
                    $this->write($connection, $response);

                    if ($response['retire'] ?? false) {
                        // The adapter reports this invocation before stopping,
                        // even if its supervised worker has already exited.
                        return;
                    }
                } catch (Throwable $e) {
                    $this->worker->shutdown();
                    throw $e;
                } finally {
                    if (is_resource($connection)) {
                        fclose($connection);
                    }
                }
            }
        } finally {
            if (is_resource($listener)) {
                fclose($listener);
            }

            unlink($this->socket);
            $this->worker->shutdown();
        }
    }

    /** @param resource $stream */
    private function read($stream, int $length, bool $probe = false): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($stream, max(1, $length - strlen($data)));

            if ($chunk === false || $chunk === '') {
                if ($probe && $data === '' && feof($stream)) {
                    return '';
                }

                throw new RuntimeException('Incomplete worker frame.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    /** @param resource $stream
     * @param  array<string, mixed>  $response
     */
    private function write($stream, array $response): void
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $frame = pack('N', strlen($json)).$json;
        $offset = 0;

        while ($offset < strlen($frame)) {
            $written = fwrite($stream, substr($frame, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write worker response.');
            }

            $offset += $written;
        }
    }
}
