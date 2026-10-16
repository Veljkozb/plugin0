<?php

namespace Plugin0\Controller\Admin;

use Order;
use Plugin0\Bootstrap;
use Plugin0\Order\OperationValidator;
use Plugin0\Order\PaymentManager;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Validate;

/**
 * POST akcije panela za plaćanje sa admin stranice narudžbine (hook displayAdminOrderMain u plugin0.php):
 * capture, refund i cancel. Čita zahtev, zove PaymentManager i vraća trgovca na stranicu narudžbine sa
 * porukom (flash); pravila su u OperationValidator-u, ne ovde.
 *
 * Zaštita, ista kao kod PrestaShop-ovih akcija na narudžbini (Sell > Orders):
 * - prijava zaposlenog: PrestaShop\PrestaShop\Adapter\Security\Admin::onKernelRequest za svaku admin rutu;
 * - dozvola: anotacija AdminSecurity na svakoj akciji traži pravo "update" (Edit) na AdminOrders za profil
 *   zaposlenog (PageVoter); ko ga nema dobija 403 bez poziva Core-a. U ovom opisu se ime anotacije namerno piše
 *   bez znaka "@": Doctrine bi "@AdminSecurity" u komentaru klase pročitao kao anotaciju bez izraza, pa bi
 *   Symfony SecurityListener pao sa "Unexpected token end of expression" (desilo se 2026-10-15);
 * - CSRF: PrestaShop svaku admin adresu potpisuje _token-om zaposlenog (Service\Routing\Router dodaje, EventListener
 *   TokenizedUrlsListener proverava), a forma u panelu šalje baš takvu adresu (path() u Twig-u).
 */
class OrderPaymentController extends FrameworkBundleAdminController
{
    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message="You do not have permission to manage payments of orders.")
     */
    public function captureAction(Request $request, int $orderId): Response
    {
        return $this->operate(OperationValidator::CAPTURE, $request, $orderId);
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message="You do not have permission to manage payments of orders.")
     */
    public function refundAction(Request $request, int $orderId): Response
    {
        return $this->operate(OperationValidator::REFUND, $request, $orderId);
    }

    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message="You do not have permission to manage payments of orders.")
     */
    public function cancelAction(Request $request, int $orderId): Response
    {
        return $this->operate(OperationValidator::CANCEL, $request, $orderId);
    }

    private function operate(string $action, Request $request, int $orderId): Response
    {
        Bootstrap::boot();

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->addFlash('error', sprintf('Order %d does not exist.', $orderId));

            return $this->redirectToRoute('admin_orders_index');
        }

        if ($order->module !== 'plugin0') {
            $this->addFlash('error', 'This order was not paid through Worldline.');

            return $this->redirectToRoute('admin_orders_view', ['orderId' => $orderId]);
        }

        $amount = $request->request->get('amount');
        $result = (new PaymentManager((string) $this->getContext()->shop->id))->execute(
            $action,
            $order,
            is_scalar($amount) ? (string) $amount : '',
            (int) $this->getContext()->employee->id
        );

        $this->addFlash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirectToRoute('admin_orders_view', ['orderId' => $orderId]);
    }
}
