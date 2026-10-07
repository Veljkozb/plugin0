<?php

namespace Plugin0\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use WOP\OnlinePayments\Core\Bootstrap\Configuration\Configuration;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;

class CoreInfoController extends FrameworkBundleAdminController
{
    public function indexAction(): JsonResponse
    {
        /** @var Configuration $configuration */
        $configuration = ServiceRegister::getService(Configuration::CLASS_NAME);

        return new JsonResponse([
            'integrationName' => $configuration->getIntegrationName(),
            'integrationVersion' => $configuration->getIntegrationVersion(),
            'pluginName' => $configuration->getPluginName(),
            'pluginVersion' => $configuration->getPluginVersion(),
        ]);
    }
}
