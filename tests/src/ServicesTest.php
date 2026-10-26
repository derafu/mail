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

use Derafu\Mail\Component\Exchange\Contract\ExchangeComponentInterface;
use Derafu\Mail\Component\Exchange\Contract\ReceiverWorkerInterface;
use Derafu\Mail\Component\Exchange\Contract\SenderWorkerInterface;
use Derafu\Mail\Component\Exchange\ExchangeComponent;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Handler\ReceiveHandler;
use Derafu\Mail\Component\Exchange\Worker\Receiver\Strategy\ImapStrategy;
use Derafu\Mail\Component\Exchange\Worker\ReceiverWorker;
use Derafu\Mail\Component\Exchange\Worker\Sender\Handler\SendHandler;
use Derafu\Mail\Component\Exchange\Worker\Sender\Strategy\SmtpStrategy;
use Derafu\Mail\Component\Exchange\Worker\SenderWorker;
use Derafu\Mail\Contract\MailPackageInterface;
use Derafu\Mail\Exception\MailException;
use Derafu\Mail\MailPackage;
use Derafu\Mail\Model\Postman;
use Derafu\Mail\Translation\MailTranslationResourceProvider;
use Derafu\TestsMail\Fixture\MailContainer;
use Derafu\Translation\TranslatorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Translation\Translator;

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

    private function completeContainer(): ContainerBuilder
    {
        $container = MailContainer::load();
        $container->register('app.translator', Translator::class)
            ->setFactory([TranslatorFactory::class, 'create'])
            ->setArguments(['es', ['en'], new TaggedIteratorArgument('derafu_translation.resource_provider')]);
        foreach ($container->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }
        foreach ($container->getAliases() as $alias) {
            $alias->setPublic(true);
        }
        $container->compile();

        return $container;
    }

    #[Test]
    public function allDeclaredServicesAreConstructedAndSharedThroughoutThePackage(): void
    {
        $container = $this->completeContainer();
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'Derafu\\Mail\\')) {
                continue;
            }
            $class = $definition->getClass();
            $this->assertNotNull($class);
            $this->assertTrue(class_exists($class) || interface_exists($class));
            $this->assertInstanceOf($class, $container->get($id), $id);
        }

        $package = $container->get(MailPackageInterface::class);
        $this->assertInstanceOf(MailPackageInterface::class, $package);
        $exchange = $package->getExchangeComponent();
        $this->assertSame($container->get(ExchangeComponentInterface::class), $exchange);
        $receiver = $exchange->getReceiverWorker();
        $sender = $exchange->getSenderWorker();
        $this->assertSame($container->get(ReceiverWorkerInterface::class), $receiver);
        $this->assertSame($container->get(SenderWorkerInterface::class), $sender);
        $this->assertInstanceOf(ReceiverWorker::class, $receiver);
        $this->assertInstanceOf(SenderWorker::class, $sender);
        $receive = $receiver->getHandler('receive');
        $send = $sender->getHandler('send');
        $this->assertSame($container->get(ReceiveHandler::class), $receive);
        $this->assertSame($container->get(SendHandler::class), $send);
        $this->assertInstanceOf(ReceiveHandler::class, $receive);
        $this->assertInstanceOf(SendHandler::class, $send);
        $this->assertSame($container->get(ImapStrategy::class), $receive->getStrategy('imap'));
        $this->assertSame($container->get(SmtpStrategy::class), $send->getStrategy('smtp'));
    }

    #[Test]
    public function theConsumerTranslatesARealStrategyExceptionUsingTaggedResources(): void
    {
        $container = $this->completeContainer();
        $translator = $container->get('app.translator');
        $this->assertInstanceOf(Translator::class, $translator);
        $strategy = $container->get(ImapStrategy::class);
        $this->assertInstanceOf(ImapStrategy::class, $strategy);

        try {
            $strategy->receive(new Postman(['transport' => [
                'dsn' => 'invalid-dsn',
                'username' => 'user',
                'password' => 'secret',
            ]]));
            $this->fail('An invalid IMAP DSN was accepted.');
        } catch (MailException $exception) {
            $this->assertSame(
                'The IMAP DSN invalid-dsn is not valid; the format is {host:port/flags}folder.',
                $exception->getMessage()
            );
            $this->assertSame(
                'El DSN IMAP invalid-dsn no es válido; el formato es {host:port/flags}folder.',
                $exception->trans($translator)
            );
        }
    }
}
