<?php

declare(strict_types=1);

/**
 * Derafu: Mail - Elegant orchestration of email communications for PHP.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Mail\Factory;

use DateTimeImmutable;
use Derafu\Mail\Contract\EnvelopeInterface;
use Derafu\Mail\Contract\MessageInterface;
use Derafu\Mail\Model\Envelope;
use Derafu\Mail\Model\Message;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Webklex\PHPIMAP\Address as ImapAddress;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Factory to create an Envelope object from an incoming IMAP message.
 */
class EnvelopeFactory
{
    /**
     * Sender of the envelope of a mail that has no valid sender.
     *
     * The domain `.invalid` is reserved (RFC 2606) and never exists, so it can
     * not be confused with a real address.
     */
    public const UNKNOWN_SENDER = 'unknown@invalid';

    /**
     * Recipient of the envelope of a mail that has no valid recipient (for
     * example, the one that has `undisclosed-recipients:;` or only hidden
     * copies).
     */
    public const UNDISCLOSED_RECIPIENTS = 'undisclosed-recipients@invalid';

    /**
     * Creates an envelope from the data of an incoming email.
     *
     * @param ImapMessage $mail
     * @param array $attachmentFilters
     * @return EnvelopeInterface
     */
    public function createFromIncomingMail(
        ImapMessage $mail,
        array $attachmentFilters = []
    ): EnvelopeInterface {
        // Determine who sent the email (Sender header takes precedence over From).
        $senderAttr = $mail->getSender();
        $fromAttr = $mail->getFrom();

        $sender = null;
        $senderSource = $senderAttr->count() > 0 ? $senderAttr : $fromAttr;
        if ($senderSource->count() > 0) {
            $sender = $this->createAddress($senderSource->first());
        }

        // An incoming mail can lack a valid sender, but the envelope needs one.
        $sender ??= new Address(self::UNKNOWN_SENDER);

        // Create the complete list of email recipients.
        $toAddresses = $mail->getTo()->all();
        $ccAddresses = $mail->getCc()->all();
        $bccAddresses = $mail->getBcc()->all();
        $allRecipients = array_merge($toAddresses, $ccAddresses, $bccAddresses);

        // The envelope needs at least one recipient, but an incoming mail can
        // lack valid ones (`undisclosed-recipients:;`, or only hidden copies).
        $recipients = $this->createAddresses($allRecipients)
            ?: [new Address(self::UNDISCLOSED_RECIPIENTS)]
        ;

        // Create the envelope.
        $envelope = new Envelope($sender, $recipients);

        // Create the message and add it to the envelope.
        $message = $this->createMessage($mail, $attachmentFilters);
        $envelope->addMessage($message);

        // Return the envelope with the message.
        return $envelope;
    }

    /**
     * Creates the message from the data of an incoming email.
     *
     * @param ImapMessage $mail
     * @param array $attachmentFilters
     * @return MessageInterface
     */
    private function createMessage(
        ImapMessage $mail,
        array $attachmentFilters = []
    ): MessageInterface {
        $message = new Message();

        // Add the message ID (the IMAP UID).
        $message->id($mail->getSequenceId());

        // Add the message date.
        $dateAttr = $mail->getDate();
        if ($dateAttr->count() > 0) {
            $message->date(DateTimeImmutable::createFromInterface($dateAttr->first()));
        }

        // Add the sender.
        $fromAttr = $mail->getFrom();
        if ($fromAttr->count() > 0) {
            $from = $this->createAddress($fromAttr->first());
            if ($from !== null) {
                $message->from($from);
            }
        }

        // Add the main recipients (TO).
        $toAttr = $mail->getTo();
        if ($toAttr->count() > 0) {
            $addresses = $this->createAddresses($toAttr->all());
            if ($addresses) {
                $message->to(...$addresses);
            }
        }

        // Add the copy recipients (CC).
        $ccAttr = $mail->getCc();
        if ($ccAttr->count() > 0) {
            $addresses = $this->createAddresses($ccAttr->all());
            if ($addresses) {
                $message->cc(...$addresses);
            }
        }

        // Add the hidden recipients (BCC).
        $bccAttr = $mail->getBcc();
        if ($bccAttr->count() > 0) {
            $addresses = $this->createAddresses($bccAttr->all());
            if ($addresses) {
                $message->bcc(...$addresses);
            }
        }

        // Add the subject.
        $subjectAttr = $mail->getSubject();
        if ($subjectAttr->count() > 0) {
            $subject = $subjectAttr->first();
            if (!empty($subject)) {
                $message->subject($subject);
            }
        }

        // Add the message body as plain text.
        if ($mail->hasTextBody()) {
            $message->text($mail->getTextBody());
        }

        // Add the message body as HTML.
        if ($mail->hasHTMLBody()) {
            $message->html($mail->getHTMLBody());
        }

        // Add the attachments if they exist.
        foreach ($mail->getAttachments() as $attachment) {
            if (
                !$attachmentFilters
                || $this->attachmentPassFilters($attachment, $attachmentFilters)
            ) {
                $message->attach(
                    $attachment->content,
                    $attachment->name,
                    $attachment->content_type
                );
            }
        }

        return $message;
    }

    /**
     * Creates an address from an address of an incoming mail.
     *
     * @param ImapAddress $address
     * @return Address|null `null` if it is not a valid address (for example
     * the `undisclosed-recipients` group, which has no host).
     */
    private function createAddress(ImapAddress $address): ?Address
    {
        try {
            return new Address($address->mail, $address->personal);
        } catch (RfcComplianceException) {
            return null;
        }
    }

    /**
     * Creates the addresses from the ones of an incoming mail, without the
     * ones that are not valid.
     *
     * @param ImapAddress[] $addresses
     * @return Address[]
     */
    private function createAddresses(array $addresses): array
    {
        return array_values(array_filter(array_map(
            $this->createAddress(...),
            $addresses
        )));
    }

    /**
     * Applies the filters to an attachment.
     *
     * @param Attachment $attachment
     * @param array $filters
     * @return bool `true` if the attachment passes the filters, `false` otherwise.
     */
    private function attachmentPassFilters(
        Attachment $attachment,
        array $filters
    ): bool {
        // Filter by: subtype (derived from the second segment of the MIME type).
        if (!empty($filters['subtype'])) {
            $contentType = $attachment->content_type ?? '';
            $subtype = strtoupper(explode('/', $contentType)[1] ?? '');
            $subtypes = array_map('strtoupper', $filters['subtype']);
            if (!in_array($subtype, $subtypes)) {
                return false;
            }
        }

        // Filter by: extension.
        if (!empty($filters['extension'])) {
            $extension = strtolower(pathinfo(
                $attachment->name ?? '',
                PATHINFO_EXTENSION
            ));
            $extensions = array_map('strtolower', $filters['extension']);
            if (!in_array($extension, $extensions)) {
                return false;
            }
        }

        return true;
    }
}
