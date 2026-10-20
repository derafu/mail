<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Email Sending and Receiving Library.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsMail;

use Derafu\Mail\Exception\MailException;
use Derafu\Mail\Model\Mailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The DSN is read when the mailbox is created, without connecting.
 */
#[CoversClass(Mailbox::class)]
final class MailboxTest extends TestCase
{
    /** @return array<string, array{string, string, int, string, bool}> */
    public static function validDsns(): array
    {
        return [
            'ssl' => ['{imap.example.com:993/imap/ssl}INBOX', 'imap.example.com', 993, 'ssl', true],
            'starttls' => ['{imap.example.com:143/imap/starttls}INBOX', 'imap.example.com', 143, 'starttls', true],
            'tls' => ['{imap.example.com:993/imap/tls}INBOX', 'imap.example.com', 993, 'tls', true],
            'without encryption' => ['{127.0.0.1:3143/imap}INBOX', '127.0.0.1', 3143, 'none', true],
            'without the flags' => ['{imap.example.com:993}INBOX', 'imap.example.com', 993, 'none', true],
            'without validating the certificate' => ['{imap.example.com:993/imap/ssl/novalidate-cert}Sent', 'imap.example.com', 993, 'ssl', false],
            'without folder' => ['{imap.example.com:993/imap/ssl}', 'imap.example.com', 993, 'ssl', true],
        ];
    }

    #[Test]
    #[DataProvider('validDsns')]
    public function theDsnConfiguresTheConnection(
        string $dsn,
        string $host,
        int $port,
        string $encryption,
        bool $validateCertificate
    ): void {
        $account = (new Mailbox($dsn, 'user', 'pass'))->getMailbox()->getAccountConfig();

        $this->assertSame($host, $account['host']);
        $this->assertSame($port, $account['port']);
        $this->assertSame($encryption, $account['encryption']);
        $this->assertSame($validateCertificate, $account['validate_cert']);
        $this->assertSame('user', $account['username']);
        $this->assertSame('pass', $account['password']);
    }

    /** @return array<string, array{string}> */
    public static function invalidDsns(): array
    {
        return [
            'without the port' => ['{imap.example.com}INBOX'],
            'without braces' => ['imap.example.com:993INBOX'],
            'without the host' => ['{:993}INBOX'],
            'a port that is not a number' => ['{imap.example.com:port}INBOX'],
            'text' => ['garbage'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidDsns')]
    public function aDsnThatIsNotValidIsRejectedWithItsFormat(string $dsn): void
    {
        try {
            new Mailbox($dsn, 'user', 'pass');
            $this->fail('A DSN that is not valid must be rejected.');
        } catch (MailException $exception) {
            $this->assertSame(
                'The IMAP DSN ' . $dsn . ' is not valid; the format is {host:port/flags}folder.',
                $exception->getMessage()
            );
        }
    }
}
