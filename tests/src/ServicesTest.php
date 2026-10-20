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

use Derafu\Mail\Component\Exchange\ExchangeComponent;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Handler\ReceiveHandler;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\ImapStrategy;
use Derafu\Mail\Component\Exchange\Worker\ReceiverWorker;
use Derafu\Mail\Component\Exchange\Worker\Sender\Handler\SendHandler;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\SmtpStrategy;
use Derafu\Mail\Component\Exchange\Worker\SenderWorker;
use Derafu\Mail\MailPackage;
use Derafu\Mail\Translation\MailTranslationResourceProvider;
use Derafu\TestsMail\Fixture\MailContainer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wiring that the package ships for `symfony/dependency-injection`.
 */
#[CoversNothing]
final class ServicesTest extends TestCase
{
    #[Test]
    public function theContainerBuildsTheWholePackage(): void
    {
        $package = MailContainer::package();

        $this->assertInstanceOf(MailPackage::class, $package);
        $component = $package->getExchangeComponent();
        $this->assertInstanceOf(ExchangeComponent::class, $component);
        $this->assertInstanceOf(ReceiverWorker::class, $component->getReceiverWorker());
        $this->assertInstanceOf(SenderWorker::class, $component->getSenderWorker());
    }

    #[Test]
    public function theHandlersAreRegisteredByTheirNameInTheirWorker(): void
    {
        $component = MailContainer::package()->getExchangeComponent();
        $receiver = $component->getReceiverWorker();
        $sender = $component->getSenderWorker();
        $this->assertInstanceOf(ReceiverWorker::class, $receiver);
        $this->assertInstanceOf(SenderWorker::class, $sender);

        $this->assertInstanceOf(ReceiveHandler::class, $receiver->getHandler('receive'));
        $this->assertInstanceOf(SendHandler::class, $sender->getHandler('send'));
    }

    #[Test]
    public function theStrategiesAreRegisteredByTheirNameInTheirHandler(): void
    {
        $component = MailContainer::package()->getExchangeComponent();
        $receiver = $component->getReceiverWorker();
        $sender = $component->getSenderWorker();
        $this->assertInstanceOf(ReceiverWorker::class, $receiver);
        $this->assertInstanceOf(SenderWorker::class, $sender);
        $receive = $receiver->getHandler('receive');
        $send = $sender->getHandler('send');
        $this->assertInstanceOf(ReceiveHandler::class, $receive);
        $this->assertInstanceOf(SendHandler::class, $send);

        $this->assertInstanceOf(ImapStrategy::class, $receive->getStrategy('imap'));
        $this->assertInstanceOf(SmtpStrategy::class, $send->getStrategy('smtp'));
    }

    #[Test]
    public function theTranslationsOfThePackageAreTaggedForTheTranslator(): void
    {
        $definition = MailContainer::load()->getDefinition(MailTranslationResourceProvider::class);

        $this->assertArrayHasKey('derafu_translation.resource_provider', $definition->getTags());
    }
}
