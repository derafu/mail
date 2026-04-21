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
use Webklex\PHPIMAP\Address as ImapAddress;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Factory to create an Envelope object from an incoming IMAP message.
 */
class EnvelopeFactory
{
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

        $senderImapAddress = null;
        if ($senderAttr !== null && $senderAttr->count() > 0) {
            $senderImapAddress = $senderAttr->first();
        } elseif ($fromAttr !== null && $fromAttr->count() > 0) {
            $senderImapAddress = $fromAttr->first();
        }

        $senderAddress = $senderImapAddress?->mail ?? '';
        $senderName = $senderImapAddress?->personal ?? '';

        // Create the complete list of email recipients.
        $toAddresses = $mail->getTo()->all();
        $ccAddresses = $mail->getCc()->all();
        $bccAddresses = $mail->getBcc()->all();
        $allRecipients = array_merge($toAddresses, $ccAddresses, $bccAddresses);

        // Create the envelope.
        $envelope = new Envelope(
            new Address($senderAddress, $senderName),
            array_map(
                fn (ImapAddress $addr) => new Address($addr->mail ?? '', $addr->personal ?? ''),
                $allRecipients
            )
        );

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
        if ($dateAttr !== null && $dateAttr->count() > 0) {
            $message->date(DateTimeImmutable::createFromInterface($dateAttr->first()));
        }

        // Add the sender.
        $fromAttr = $mail->getFrom();
        if ($fromAttr !== null && $fromAttr->count() > 0) {
            $fromAddr = $fromAttr->first();
            $message->from(new Address($fromAddr->mail ?? '', $fromAddr->personal ?? ''));
        }

        // Add the main recipients (TO).
        $toAttr = $mail->getTo();
        if ($toAttr !== null && $toAttr->count() > 0) {
            $message->to(...array_map(
                fn (ImapAddress $addr) => new Address($addr->mail ?? '', $addr->personal ?? ''),
                $toAttr->all()
            ));
        }

        // Add the copy recipients (CC).
        $ccAttr = $mail->getCc();
        if ($ccAttr !== null && $ccAttr->count() > 0) {
            $message->cc(...array_map(
                fn (ImapAddress $addr) => new Address($addr->mail ?? '', $addr->personal ?? ''),
                $ccAttr->all()
            ));
        }

        // Add the hidden recipients (BCC).
        $bccAttr = $mail->getBcc();
        if ($bccAttr !== null && $bccAttr->count() > 0) {
            $message->bcc(...array_map(
                fn (ImapAddress $addr) => new Address($addr->mail ?? '', $addr->personal ?? ''),
                $bccAttr->all()
            ));
        }

        // Add the subject.
        $subjectAttr = $mail->getSubject();
        if ($subjectAttr !== null && $subjectAttr->count() > 0) {
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
