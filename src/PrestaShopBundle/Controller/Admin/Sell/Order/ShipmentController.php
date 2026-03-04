<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShopBundle\Controller\Admin\Sell\Order;

use PrestaShop\PrestaShop\Core\Domain\Shipment\Exception\ShipmentNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Query\GetShipmentProducts;
use PrestaShop\PrestaShop\Core\Domain\Shipment\QueryResult\OrderShipmentProduct;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagSettings;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagStateCheckerInterface;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\ShipmentListGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\GridFactory;
use PrestaShop\PrestaShop\Core\Search\Filters\ShipmentListFilters;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ShipmentController extends PrestaShopAdminController
{
    // #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        Request $request,
        ShipmentListFilters $shipmentListFilters,
        #[Autowire(service: 'PrestaShop\PrestaShop\Core\Grid\Factory\ShipmentListFactory')]
        GridFactory $shipmentGridFactory,
        FeatureFlagStateCheckerInterface $featureFlagStateChecker,
    ): Response {
        if (!$featureFlagStateChecker->isEnabled(FeatureFlagSettings::FEATURE_FLAG_IMPROVED_SHIPMENT)) {
            return $this->redirectToRoute('admin_orders_index');
        }

        $shipmentGrid = $shipmentGridFactory->getGrid($shipmentListFilters);

        return $this->render('@PrestaShop/Admin/Sell/Order/Shipment/index.html.twig', [
            'enableSidebar' => true,
            'layoutTitle' => $this->trans('Shipments', [], 'Admin.Navigation.Menu'),
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
            'shipmentGrid' => $this->presentGrid($shipmentGrid),
        ]);
    }

    public function productsAction(int $shipmentId): Response
    {
        try {
            /** @var OrderShipmentProduct[] $products */
            $products = $this->dispatchQuery(new GetShipmentProducts($shipmentId));
        } catch (ShipmentNotFoundException $e) {
            throw new PrestaShopException('An error ocurred while fetching products', $e->getCode());
        }

        return $this->render('@PrestaShop/Admin/Sell/Order/Shipment/product_rows.html.twig', [
            'products' => array_map(fn (OrderShipmentProduct $p) => $p->toArray(), $products),
        ]);
    }

    public function searchAction(
        Request $request,
        #[Autowire(service: 'PrestaShop\PrestaShop\Core\Grid\Definition\Factory\ShipmentListGridDefinitionFactory')]
        ShipmentListGridDefinitionFactory $shipmentListGridDefinitionFactory,
    ): RedirectResponse {
        return $this->buildSearchResponse(
            $shipmentListGridDefinitionFactory,
            $request,
            ShipmentListGridDefinitionFactory::GRID_ID,
            'admin_shipments_index'
        );
    }
}
