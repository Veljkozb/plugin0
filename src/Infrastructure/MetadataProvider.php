<?php

namespace Plugin0\Infrastructure;

use Context;
use Module;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Metadata\Metadata;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Metadata\MetadataProviderInterface;

/**
 * Podaci o platformi koje SDK šalje Worldline-u u headeru X-GCS-ServerMetaInfo.
 */
class MetadataProvider implements MetadataProviderInterface
{
    public function getMetadata(): Metadata
    {
        $module = Module::getInstanceByName('plugin0');
        $shop = Context::getContext()->shop;

        return new Metadata(
            'PrestaShop',
            _PS_VERSION_,
            '',
            $module instanceof Module ? $module->version : '',
            $shop ? $shop->getBaseURL(true) : ''
        );
    }
}