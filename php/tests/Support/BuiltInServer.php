<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * PHP's built-in web server, the one the container image serves with, started on a free local port with
 * given PHP settings and a one-file site, for a test that needs PHP itself to read a request.
 *
 * What PHP does before any script runs, such as reading a request body or raising a warning about it,
 * happens below the framework, where a Laravel test request never reaches. Only a real server shows it.
 */
final class BuiltInServer
{
    /** @param resource $process */
    private function __construct(
        private $process,
        private readonly int $port,
        private readonly string $folder,
    ) {}

    /**
     * Starts the server serving $script as the site's only page, with the $iniFiles together as its php.ini,
     * as the container image reads every file in its php.ini folder.
     */
    public static function start(string $script, string ...$iniFiles): self
    {
        $folder = sys_get_temp_dir().'/built-in-server-'.bin2hex(random_bytes(6));
        mkdir($folder);
        file_put_contents($folder.'/index.php', $script);
        file_put_contents($folder.'/php.ini', implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            $iniFiles,
        )));

        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-c', $folder.'/php.ini', '-S', '127.0.0.1:'.$port, '-t', $folder],
            [0 => ['pipe', 'r'], 1 => ['file', $folder.'/server.log', 'a'], 2 => ['file', $folder.'/server.log', 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('PHP\'s built-in server did not start.');
        }

        $server = new self($process, $port, $folder);
        $server->waitUntilListening();

        return $server;
    }

    /**
     * Sends a POST with $body, in chunks when $chunked is true, and answers the status line, the headers
     * and the body as one string.
     */
    public function post(string $contentType, string $body, bool $chunked = false): string
    {
        return $this->request('POST', '/', $contentType, $body, $chunked);
    }

    /**
     * Sends a request for $target, with $body unless $contentType is null, in chunks when $chunked is true,
     * and answers the status line, the headers and the body as one string.
     */
    public function request(string $method, string $target, ?string $contentType = null, string $body = '', bool $chunked = false): string
    {
        $connection = $this->connect();
        $framing = $chunked ? "Transfer-Encoding: chunked\r\n" : 'Content-Length: '.strlen($body)."\r\n";
        $head = $contentType === null ? '' : "Content-Type: {$contentType}\r\n{$framing}";
        fwrite($connection, "{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1\r\n{$head}Connection: close\r\n\r\n");

        foreach ($chunked ? str_split($body, 65_536) : [$body] as $part) {
            fwrite($connection, $chunked ? dechex(strlen($part))."\r\n{$part}\r\n" : $part);
        }

        if ($chunked) {
            fwrite($connection, "0\r\n\r\n");
        }

        $answer = (string) stream_get_contents($connection);
        fclose($connection);

        return $answer;
    }

    /** Everything the server has written to its log, the errors PHP logged among it. */
    public function log(): string
    {
        return (string) file_get_contents($this->folder.'/server.log');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);

        foreach ((array) glob($this->folder.'/*') as $file) {
            unlink((string) $file);
        }

        rmdir($this->folder);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('No free local port.');
        }

        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private function waitUntilListening(): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://127.0.0.1:'.$this->port, $code, $message, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException("PHP's built-in server did not listen on port {$this->port}.");
    }

    /** @return resource */
    private function connect()
    {
        $connection = stream_socket_client('tcp://127.0.0.1:'.$this->port, $code, $message, 5);

        if ($connection === false) {
            throw new RuntimeException("Could not reach PHP's built-in server: {$message}");
        }

        return $connection;
    }
}
