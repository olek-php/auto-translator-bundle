<?php

namespace TaxiAdmin\Bundle\AutoTranslatorBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class AutoTranslatorBundle extends Bundle
{
    public function boot(): void
    {
        if ('prod' === $this->container->getParameter('kernel.environment')) {
            @trigger_error('Using TranslatorBundle in production is not supported and puts your project at risk, disable it.', \E_USER_WARNING);
        }
    }
}