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

use Derafu\Backbone\DependencyInjection\ServiceProcessingCompilerPass;
use Derafu\Mail\Contract\MailPackageInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * The container of services of an application that uses the wiring that the
 * package ships (`resources/config/mail-services.yaml`).
 */
final class MailContainer
{
    /**
     * The container with the services loaded, before it is compiled.
     */
    public static function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 3) . '/resources/config')))
            ->load('mail-services.yaml');

        // The pass of Backbone that tags the services with what their
        // attributes say, before the arguments with tags are resolved.
        $container->addCompilerPass(new ServiceProcessingCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);

        // The entry point of the library, as an application would use it.
        $container->getDefinition(MailPackageInterface::class)->setPublic(true);

        return $container;
    }

    public static function build(): ContainerBuilder
    {
        $container = self::load();
        $container->compile();

        return $container;
    }

    public static function package(): MailPackageInterface
    {
        $package = self::build()->get(MailPackageInterface::class);
        assert($package instanceof MailPackageInterface);

        return $package;
    }
}
