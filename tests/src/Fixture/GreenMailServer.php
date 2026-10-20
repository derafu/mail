<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Email Sending and Receiving Library.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsMail\Fixture;

use RuntimeException;

/**
 * A real mail server (SMTP and IMAP) in a Docker container.
 *
 * It uses GreenMail, a server for tests: the mails that it receives by SMTP
 * can be read by IMAP, and nothing leaves the container. The users `ana` and
 * `beto` exist, with the password `secret`.
 */
final class GreenMailServer
{
    public const IMAGE = 'greenmail/standalone:2.1.8';

    public const PASSWORD = 'secret';

    private function __construct(
        private readonly string $container,
        public readonly int $smtpPort,
        public readonly int $imapPort,
        private readonly int $apiPort
    ) {
    }

    /**
     * Starts the server and waits until it is ready.
     */
    public static function start(): self
    {
        [$smtpPort, $imapPort, $apiPort] = self::freePorts(3);

        $command = sprintf(
            'docker run -d --rm -p 127.0.0.1:%d:3025 -p 127.0.0.1:%d:3143 -p 127.0.0.1:%d:8080 '
                . '-e GREENMAIL_OPTS=%s %s 2>&1',
            $smtpPort,
            $imapPort,
            $apiPort,
            escapeshellarg(
                '-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 '
                . '-Dgreenmail.users=ana:' . self::PASSWORD . '@example.com,beto:' . self::PASSWORD . '@example.com'
            ),
            self::IMAGE
        );
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException("Docker could not start GreenMail:\n" . implode("\n", $output));
        }

        $server = new self(trim((string) end($output)), $smtpPort, $imapPort, $apiPort);

        for ($attempt = 0; $attempt < 120; $attempt++) {
            if ($server->isReady()) {
                return $server;
            }
            usleep(500_000);
        }

        exec('docker stop -t 1 ' . escapeshellarg($server->container) . ' 2>&1');
        throw new RuntimeException('GreenMail did not start in one minute.');
    }

    /**
     * Options of the transport to send mails as a user of the server.
     *
     * @return array<string, mixed>
     */
    public function smtpTransport(string $user = 'ana'): array
    {
        return [
            'username' => $user,
            'password' => self::PASSWORD,
            'host' => '127.0.0.1',
            'port' => $this->smtpPort,
            'encryption' => null,
            'verify_peer' => false,
        ];
    }

    /**
     * Options of the transport to read the mails of a user of the server.
     *
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public function imapTransport(string $user = 'beto', array $search = []): array
    {
        return [
            'username' => $user,
            'password' => self::PASSWORD,
            'host' => '127.0.0.1',
            'port' => $this->imapPort,
            'encryption' => null,
            'verify_peer' => false,
            'search' => $search,
        ];
    }

    /**
     * DSN of the IMAP server for a folder.
     */
    public function imapDsn(string $folder = 'INBOX'): string
    {
        return '{127.0.0.1:' . $this->imapPort . '/imap/novalidate-cert}' . $folder;
    }

    /**
     * Deletes all the mails of all the users.
     */
    public function purge(): void
    {
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10]]);
        if (@file_get_contents($this->apiUrl('/api/mail/purge'), false, $context) === false) {
            throw new RuntimeException('GreenMail could not delete the mails.');
        }
    }

    /**
     * Stops the server a few seconds later, in the background.
     *
     * The IMAP connections close (with a `LOGOUT`) when PHP destroys their
     * objects, and some of them live until PHP ends. If the server was already
     * gone, each one would end with a notice about a broken pipe.
     */
    public function stop(): void
    {
        exec(sprintf(
            '(sleep 5; docker stop -t 1 %s) > /dev/null 2>&1 &',
            escapeshellarg($this->container)
        ));
    }

    private function isReady(): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        if (@file_get_contents($this->apiUrl('/api/configuration'), false, $context) === false) {
            return false;
        }

        foreach ([$this->smtpPort, $this->imapPort] as $port) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 1);
            if ($socket === false) {
                return false;
            }
            fclose($socket);
        }

        return true;
    }

    private function apiUrl(string $path): string
    {
        return 'http://127.0.0.1:' . $this->apiPort . $path;
    }

    /**
     * @return list<int>
     */
    private static function freePorts(int $count): array
    {
        $sockets = [];
        $ports = [];
        for ($i = 0; $i < $count; $i++) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($socket === false) {
                throw new RuntimeException('No free port: ' . $error);
            }
            $name = (string) stream_socket_get_name($socket, false);
            $ports[] = (int) substr((string) strrchr($name, ':'), 1);
            $sockets[] = $socket;
        }
        foreach ($sockets as $socket) {
            fclose($socket);
        }

        return $ports;
    }
}
