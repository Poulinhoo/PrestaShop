/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

import Router from '@js/components/router';

export default class ShipmentProducts {
  route = 'admin_shipments_products';

  router: Router = new Router();

  loading: HTMLElement;

  content: HTMLElement;

  tbody: HTMLElement;

  constructor() {
    this.loading = this.getContainer('.js-shipment-products-loading');
    this.content = this.getContainer('.js-shipment-products-content');
    this.tbody = this.getContainer('#shipmentProductsTableBody');

    document.addEventListener('click', this.loadProducts);
  }

  loadProducts = (e: MouseEvent): void => {
    const btn = (e.target as HTMLElement).closest<HTMLElement>('.js-view-shipment-products-btn');

    if (!btn) {
      return;
    }

    const {shipmentId} = btn.dataset;

    if (!shipmentId) {
      return;
    }

    this.fetchProducts(shipmentId);
  };

  async fetchProducts(shipmentId: string): Promise<void> {
    this.loading.classList.remove('d-none');
    this.content.classList.add('d-none');
    this.tbody.innerHTML = '';

    try {
      const url = this.router.generate(this.route, {shipmentId});
      const response = await fetch(url);

      this.loading.classList.add('d-none');
      this.tbody.innerHTML = await response.text();
      this.content.classList.remove('d-none');
    } catch {
    }
  }

  private getContainer(selector: string): HTMLElement {
    const el = document.querySelector<HTMLElement>(selector);

    if (!el) {
      throw new Error(`ShipmentProductsModal: element not found for selector "${selector}"`);
    }

    return el;
  }
}

$((): void => {
  // eslint-disable-next-line no-new
  new ShipmentProducts();
});
