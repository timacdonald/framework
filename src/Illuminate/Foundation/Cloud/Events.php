<?php

namespace Illuminate\Foundation\Cloud;

use Symfony\Component\Translation\Exception\RuntimeException;
use Throwable;

class Events
{
    /**
     * The socket connection timeout.
     */
    protected const int CONNECTION_TIMEOUT = 10;

    /**
     * The socket writing timeout.
     */
    protected const int TIMEOUT = 2;

    /**
     * The cloud socket.
     *
     * @var resource|null
     */
    protected $socket = null;

    /**
     * Emit an event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(array $payload): void
    {
        $this->emitMany([$payload]);
    }

    /**
     * Emit many events.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    public function emitMany(array $payloads): void
    {
        try {
            $this->ensureConnected();

            $this->write($this->format($payloads));
        } catch (Throwable $e) {
            // TODO $e
        }
    }

    /**
     * Write the payload to the socket.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function write(string $payload): void
    {
        $originalPayloadLength = strlen($payload);
        $written = 0;

        while (true) {
            $thisWrite = @fwrite($this->socket, $payload);

            if ($thisWrite === false) {
                $message = $this->withSocketMetaData('Unable to write to socket');

                $this->disconnect();

                throw new RuntimeException($message);
            }

            $written += $thisWrite;

            if ($written >= $originalPayloadLength) {
                return;
            }

            $payload = substr($payload, $thisWrite);
        }
    }

    /**
     * Format the payload.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function format(array $payloads): string
    {
        return array_reduce($payloads, function (string $carry, array $line) {
            if ($carry !== '') {
                $carry .= "\n";
            }

            return $carry .= json_encode($line, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        }, '')."\n";
    }

    /**
    * Ensure the socket is connected.
    */
    protected function ensureConnected(): void
    {
        if (! $this->connected()) {
            $this->connect();
        }
    }

    /**
    * Connect the socket.
    */
    protected function connect(): void
    {
        $socket = stream_socket_client(
            address: $_ENV['LARAVEL_CLOUD_LOG_SOCKET'] ?? $_SERVER['LARAVEL_CLOUD_LOG_SOCKET'] ?? 'unix:///tmp/cloud-init.sock',
            error_code: $errorCode,
            error_message: $errorMessage,
            timeout: self::CONNECTION_TIMEOUT,
            // TODO flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
        );

        if ($socket === false) {
            throw new RuntimeException("Failed connecting to the socket: {$errorMessage} [{$errorCode}]");
        }

        if (! stream_set_timeout($socket, self::TIMEOUT)) {
            $message = $this->withSocketMetaData("Failed configuring socket timeout");

            $this->disconnect();

            throw new RuntimeException($message);
        }

        $this->socket = $socket;
    }

    /**
    * Determine if the socket is connected.
    */
    protected function connected(): bool
    {
        if (gettype($this->socket) !== 'resource') {
            return false;
        }

        $meta = stream_get_meta_data($this->socket);

        return ! $meta['timed_out'] && ! $meta['eof'];
    }

    /**
    * Disconnect the socket.
    */
    protected function disconnect(): void
    {
        try {
            fclose($this->socket);
        } catch (Throwable) {
            //
        }

        $this->socket = null;
    }

    /**
    * Decorate the message with the socket's meta data.
    */
    protected function withSocketMetaData(string $message): string
    {
        $prefix = "{$message}\n---\n";

        if ($this->connected()) {
            return "{$prefix}closed: true";
        }

        $meta = stream_get_meta_data($this->socket);

        return $prefix.array_reduce(array_keys($meta), function ($carry, $key) use ($meta) {
            try {
                return $carry.$key.': '.match ($meta[$key]) {
                    true => 'true',
                    false => 'false',
                    default => $meta[$key],
                }."\n";
            } catch (Throwable) {
                return $carry;
            }
        }, '');
    }
}
