<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\Shipment\Command;

use PrestaShop\PrestaShop\Core\Domain\Shipment\ValueObject\ShipmentId;

class FulfillShipmentCommand
{
    /**
     * @var ShipmentId
     */
    private $shipmentId;

    /**
     * @var string
     */
    private $trackingNumber;

    /**
     * @var \DateTimeInterface|null
     */
    private $packedAt;

    public function __construct(int $shipmentId, string $trackingNumber, ?\DateTimeInterface $packedAt = null)
    {
        $this->shipmentId = new ShipmentId($shipmentId);
        $this->trackingNumber = $trackingNumber;
        $this->packedAt = $packedAt;
    }

    public function getShipmentId(): ShipmentId
    {
        return $this->shipmentId;
    }

    public function getTrackingNumber(): string
    {
        return $this->trackingNumber;
    }

    public function getPackedAt(): ?\DateTimeInterface
    {
        return $this->packedAt;
    }
}
