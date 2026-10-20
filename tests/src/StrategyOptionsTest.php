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

use Closure;
use Derafu\Config\Contract\OptionsInterface;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\Abstract\AbstractMailboxStrategy;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\ImapStrategy;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\Abstract\AbstractMailerStrategy;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\SmtpStrategy;
use Derafu\Mail\Exception\MailException;
use Derafu\Mail\Factory\EnvelopeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\UndefinedOptionsException;

#[CoversClass(SmtpStrategy::class)]
#[CoversClass(AbstractMailerStrategy::class)]
#[CoversClass(ImapStrategy::class)]
#[CoversClass(AbstractMailboxStrategy::class)]
final class StrategyOptionsTest extends TestCase
{
    /** @param array<string, mixed> $transport */
    private function smtpOptions(array $transport = []): OptionsInterface
    {
        return (new SmtpStrategy())->resolveOptions([
            'transport' => $transport + ['username' => 'user', 'password' => 'pass'],
        ]);
    }

    /** @param array<string, mixed> $transport */
    private function imapOptions(array $transport = []): OptionsInterface
    {
        return (new ImapStrategy(new EnvelopeFactory()))->resolveOptions([
            'transport' => $transport + ['username' => 'user', 'password' => 'pass'],
        ]);
    }

    private function call(object $strategy, string $method, OptionsInterface $options): string
    {
        $result = Closure::fromCallable(fn (): mixed => $this->$method($options))
            ->call($strategy);
        $this->assertIsString($result);

        return $result;
    }

    public function testSmtpDsnUsesTheDefaultsOfTheSchema(): void
    {
        $options = $this->smtpOptions();

        $this->assertSame(
            'smtp://user:pass@smtp.gmail.com:465?encryption=ssl&verify_peer=1',
            $this->call(new SmtpStrategy(), 'resolveDsn', $options)
        );
        $this->assertSame(
            'smtp://user:pass@smtp.gmail.com:465?encryption=ssl&verify_peer=1',
            $options->getString('transport.dsn')
        );
    }

    public function testSmtpDsnWithoutEncryptionAndWithoutPeerVerification(): void
    {
        $options = $this->smtpOptions([
            'host' => 'mail.example.com',
            'port' => 25,
            'encryption' => null,
            'verify_peer' => false,
        ]);

        $this->assertSame(
            'smtp://user:pass@mail.example.com:25?encryption=&verify_peer=0',
            $this->call(new SmtpStrategy(), 'resolveDsn', $options)
        );
    }

    public function testSmtpKeepsTheDsnThatWasGiven(): void
    {
        $options = $this->smtpOptions(['dsn' => 'smtp://other:1']);

        $this->assertSame('smtp://other:1', $this->call(new SmtpStrategy(), 'resolveDsn', $options));
    }

    public function testSmtpEndpointIsBuiltFromTheTransportOptions(): void
    {
        $options = $this->smtpOptions();

        $this->assertSame(
            'ssl://smtp.gmail.com:465',
            $this->call(new SmtpStrategy(), 'resolveEndpoint', $options)
        );
        $this->assertSame('ssl://smtp.gmail.com:465', $options->getString('transport.endpoint'));
    }

    public function testSmtpEndpointWithoutEncryptionAndWithoutPeerVerification(): void
    {
        $options = $this->smtpOptions([
            'host' => 'mail.example.com',
            'port' => 25,
            'encryption' => null,
            'verify_peer' => false,
        ]);

        $this->assertSame(
            'mail.example.com:25/novalidate-cert',
            $this->call(new SmtpStrategy(), 'resolveEndpoint', $options)
        );
    }

    public function testSmtpKeepsTheEndpointThatWasGiven(): void
    {
        $options = $this->smtpOptions(['endpoint' => 'custom']);

        $this->assertSame('custom', $this->call(new SmtpStrategy(), 'resolveEndpoint', $options));
    }

    public function testImapDsnUsesTheDefaultsOfTheSchema(): void
    {
        $options = $this->imapOptions();

        $this->assertSame(
            '{imap.gmail.com:993/imap/ssl}INBOX',
            $this->call(new ImapStrategy(new EnvelopeFactory()), 'resolveDsn', $options)
        );
        $this->assertSame('{imap.gmail.com:993/imap/ssl}INBOX', $options->getString('transport.dsn'));
    }

    public function testImapDsnWithoutEncryptionAndWithoutPeerVerification(): void
    {
        $options = $this->imapOptions([
            'encryption' => null,
            'verify_peer' => false,
            'mailbox' => 'Sent',
        ]);

        $this->assertSame(
            '{imap.gmail.com:993/imap/novalidate-cert}Sent',
            $this->call(new ImapStrategy(new EnvelopeFactory()), 'resolveDsn', $options)
        );
    }

    public function testImapKeepsTheDsnThatWasGiven(): void
    {
        $options = $this->imapOptions(['dsn' => '{x:1}Y']);

        $this->assertSame(
            '{x:1}Y',
            $this->call(new ImapStrategy(new EnvelopeFactory()), 'resolveDsn', $options)
        );
    }

    public function testImapEndpointIsTheDsn(): void
    {
        $options = $this->imapOptions();

        $this->assertSame(
            '{imap.gmail.com:993/imap/ssl}INBOX',
            $this->call(new ImapStrategy(new EnvelopeFactory()), 'resolveEndpoint', $options)
        );
        $this->assertSame('{imap.gmail.com:993/imap/ssl}INBOX', $options->getString('transport.endpoint'));
    }

    public function testImapKeepsTheEndpointThatWasGiven(): void
    {
        $options = $this->imapOptions(['endpoint' => 'custom']);

        $this->assertSame(
            'custom',
            $this->call(new ImapStrategy(new EnvelopeFactory()), 'resolveEndpoint', $options)
        );
    }

    public function testAttachmentFiltersHaveEmptyListsByDefault(): void
    {
        $filters = $this->imapOptions()->requireContainer('transport.search.attachmentFilters');

        $this->assertSame([], $filters->requireList('subtype', of: 'string'));
        $this->assertSame([], $filters->requireList('extension', of: 'string'));
    }

    public function testAttachmentFiltersAreLists(): void
    {
        $filters = $this->imapOptions([
            'search' => ['attachmentFilters' => ['subtype' => ['xml'], 'extension' => ['pdf', 'xml']]],
        ])->requireContainer('transport.search.attachmentFilters');

        $this->assertSame(['xml'], $filters->requireList('subtype', of: 'string'));
        $this->assertSame(['pdf', 'xml'], $filters->requireList('extension', of: 'string'));
    }

    public function testAttachmentFiltersRejectElementsThatAreNotStrings(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->imapOptions(['search' => ['attachmentFilters' => ['subtype' => [1]]]]);
    }

    public function testAttachmentFiltersRejectUnknownFilters(): void
    {
        $this->expectException(UndefinedOptionsException::class);
        $this->imapOptions(['search' => ['attachmentFilters' => ['subtipe' => ['xml']]]]);
    }

    public function testMailerBaseUsesTheDsnAndTheEndpointThatWereGiven(): void
    {
        $strategy = new class () extends AbstractMailerStrategy {
        };

        $options = $strategy->resolveOptions(['transport' => ['dsn' => 'smtp://one:1']]);
        $this->assertSame('smtp://one:1', $this->call($strategy, 'resolveDsn', $options));
        // Without an endpoint, the endpoint is the DSN, and it is kept.
        $this->assertSame('smtp://one:1', $this->call($strategy, 'resolveEndpoint', $options));
        $this->assertSame('smtp://one:1', $options->getString('transport.endpoint'));

        $options = $strategy->resolveOptions(['transport' => ['dsn' => 'smtp://one:1', 'endpoint' => 'custom']]);
        $this->assertSame('custom', $this->call($strategy, 'resolveEndpoint', $options));
    }

    public function testMailboxBaseUsesTheDsnThatWasGiven(): void
    {
        $strategy = new class (new EnvelopeFactory()) extends AbstractMailboxStrategy {
        };

        $options = $strategy->resolveOptions(['transport' => ['dsn' => '{one:1}INBOX']]);

        $this->assertSame('{one:1}INBOX', $this->call($strategy, 'resolveDsn', $options));
    }

    public function testMailerWithoutDsnIsRejected(): void
    {
        $strategy = new class () extends AbstractMailerStrategy {
        };

        $this->expectException(MailException::class);
        $this->call($strategy, 'resolveDsn', $strategy->resolveOptions(['transport' => []]));
    }

    public function testMailboxWithoutDsnIsRejected(): void
    {
        $strategy = new class (new EnvelopeFactory()) extends AbstractMailboxStrategy {
        };

        $this->expectException(MailException::class);
        $this->call($strategy, 'resolveDsn', $strategy->resolveOptions(['transport' => []]));
    }
}
