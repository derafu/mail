<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Mail sending and receiving library.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsMail;

use Derafu\Mail\Factory\EnvelopeFactory;
use Derafu\Mail\Model\Envelope;
use Derafu\Mail\Model\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Envelopes created from real emails (`tests/fixtures/eml`), parsed with the
 * same parser that reads the mailbox, but without a server. The only thing
 * that the server would give and the file does not is the sequence id.
 */
#[CoversClass(EnvelopeFactory::class)]
#[CoversClass(Envelope::class)]
#[CoversClass(Message::class)]
final class EnvelopeFactoryTest extends TestCase
{
    /**
     * @param array<string, string[]> $attachmentFilters
     */
    private function envelopeFrom(string $name, array $attachmentFilters = []): Envelope
    {
        $mail = ImapMessage::fromString(
            file_get_contents(__DIR__ . '/../fixtures/eml/' . $name . '.eml')
        );
        $mail->__set('msgn', 1);
        $mail->__set('uid', 1);

        $envelope = (new EnvelopeFactory())->createFromIncomingMail($mail, $attachmentFilters);
        $this->assertInstanceOf(Envelope::class, $envelope);

        return $envelope;
    }

    private function messageOf(Envelope $envelope): Message
    {
        $message = $envelope->getMessages()[0];
        $this->assertInstanceOf(Message::class, $message);

        return $message;
    }

    /**
     * @return string[]
     */
    private function addresses(Envelope $envelope): array
    {
        return array_map(
            fn (Address $address) => $address->getAddress(),
            $envelope->getRecipients()
        );
    }

    #[Test]
    public function senderHeaderTakesPrecedenceOverFrom(): void
    {
        $envelope = $this->envelopeFrom('sender-and-from');

        $this->assertSame('secretaria@example.com', $envelope->getSender()->getAddress());
        $this->assertSame('Secretaria', $envelope->getSender()->getName());
        // The message keeps the From header as it came.
        $message = $this->messageOf($envelope);
        $this->assertSame('ana@example.com', $message->getFrom()[0]->getAddress());
        $this->assertSame('Sender and From', $message->getSubject());
    }

    #[Test]
    public function allTheRecipientsAreInTheEnvelope(): void
    {
        $envelope = $this->envelopeFrom('sender-and-from');

        $this->assertSame(
            ['beto@example.com', 'carla@example.com', 'dani@example.com', 'eva@example.com'],
            $this->addresses($envelope)
        );
    }

    #[Test]
    public function usesFromWhenThereIsNoSender(): void
    {
        $envelope = $this->envelopeFrom('only-from');

        $this->assertSame('ana@example.com', $envelope->getSender()->getAddress());
        $this->assertSame('Ana', $envelope->getSender()->getName());
        $this->assertSame(['beto@example.com'], $this->addresses($envelope));
    }

    #[Test]
    public function keepsTheAttachments(): void
    {
        $message = $this->messageOf($this->envelopeFrom('with-attachment'));

        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('dte.xml', $attachments[0]->getFilename());
    }

    #[Test]
    public function aMailWithoutSenderGetsTheUnknownSender(): void
    {
        $envelope = $this->envelopeFrom('no-sender');

        $this->assertSame('unknown@invalid', $envelope->getSender()->getAddress());
        // Nothing else of the mail is lost.
        $this->assertSame(['beto@example.com'], $this->addresses($envelope));
        $this->assertSame('No sender', $this->messageOf($envelope)->getSubject());
    }

    #[Test]
    public function aMailWithoutToUsesTheOtherRecipients(): void
    {
        $envelope = $this->envelopeFrom('no-to');

        $this->assertSame(['dani@example.com'], $this->addresses($envelope));
    }

    #[Test]
    public function undisclosedRecipientsIsReplacedByTheUndisclosedAddress(): void
    {
        $envelope = $this->envelopeFrom('undisclosed-recipients');

        $this->assertSame(['undisclosed-recipients@invalid'], $this->addresses($envelope));
        $this->assertSame('ana@example.com', $envelope->getSender()->getAddress());
        $this->assertSame('Undisclosed recipients', $this->messageOf($envelope)->getSubject());
    }

    #[Test]
    public function aMailWithoutAnyRecipientGetsTheUndisclosedRecipients(): void
    {
        $envelope = $this->envelopeFrom('no-recipients');

        $this->assertSame(['undisclosed-recipients@invalid'], $this->addresses($envelope));
        $this->assertSame('No recipients', $this->messageOf($envelope)->getSubject());
    }

    #[Test]
    public function aMailWithoutSubjectHasNoSubject(): void
    {
        $message = $this->messageOf($this->envelopeFrom('no-subject'));

        $this->assertNull($message->getSubject());
    }

    #[Test]
    public function decodesTheEncodedHeaders(): void
    {
        $envelope = $this->envelopeFrom('encoded-headers');
        $message = $this->messageOf($envelope);

        $this->assertSame('José Núñez', $envelope->getSender()->getName());
        $this->assertSame('Información del año', $message->getSubject());
        $this->assertSame('María Ñandú', $message->getTo()[0]->getName());
    }

    #[Test]
    public function keepsTheTextAndTheHtmlOfAnAlternativeMail(): void
    {
        $message = $this->messageOf($this->envelopeFrom('multipart-alternative'));

        $this->assertSame('Plain body', trim((string) $message->getTextBody()));
        $this->assertSame('<p>HTML body</p>', trim((string) $message->getHtmlBody()));
    }

    #[Test]
    public function anHtmlOnlyMailHasNoText(): void
    {
        $message = $this->messageOf($this->envelopeFrom('html-only'));

        $this->assertNull($message->getTextBody());
        $this->assertSame('<p>HTML body</p>', trim((string) $message->getHtmlBody()));
    }

    /**
     * @return array<string, array{array<string, string[]>, string[]}>
     */
    public static function attachmentFiltersProvider(): array
    {
        return [
            'without filters' => [[], ['dte.xml', 'BOLETA.PDF', 'notes.txt']],
            'empty filters' => [['subtype' => [], 'extension' => []], ['dte.xml', 'BOLETA.PDF', 'notes.txt']],
            'subtype' => [['subtype' => ['xml']], ['dte.xml']],
            'subtype ignores case' => [['subtype' => ['XML', 'Pdf']], ['dte.xml', 'BOLETA.PDF']],
            'extension' => [['extension' => ['pdf']], ['BOLETA.PDF']],
            'extension ignores case' => [['extension' => ['XML', 'TXT']], ['dte.xml', 'notes.txt']],
            'subtype and extension must both match' => [['subtype' => ['pdf'], 'extension' => ['pdf']], ['BOLETA.PDF']],
            'subtype and extension that do not match' => [['subtype' => ['pdf'], 'extension' => ['xml']], []],
        ];
    }

    /**
     * @param array<string, string[]> $filters
     * @param string[] $expected
     */
    #[Test]
    #[DataProvider('attachmentFiltersProvider')]
    public function filtersTheAttachments(array $filters, array $expected): void
    {
        $message = $this->messageOf($this->envelopeFrom('attachments-mixed', $filters));

        $names = array_map(
            fn ($attachment) => $attachment->getFilename(),
            $message->getAttachments()
        );
        $this->assertSame($expected, $names);
    }
}
