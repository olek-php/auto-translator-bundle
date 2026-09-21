<?php

namespace TaxiAdmin\Bundle\AutoTranslatorBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use TaxiAdmin\Bundle\AutoTranslatorBundle\Command\AutoTranslatorCommand;

class AutoTranslatorExtension extends Extension
{

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container
            ->setDefinition(AutoTranslatorCommand::class, new Definition(AutoTranslatorCommand::class))
            ->addArgument(new Reference("translation.reader"))
            ->addArgument(new Reference("translation.writer"))
            ->addArgument(new Reference("http_client"))
            ->addArgument(new Parameter("kernel.default_locale"))
            ->addArgument(new Parameter("kernel.enabled_locales"))
            ->addArgument(new Parameter("translator.default_path"))
            ->addTag('console.command')
        ;
    }
}