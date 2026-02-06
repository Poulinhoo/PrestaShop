<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShopBundle\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * BusinessEntityCustomerB2b.
 *
 * @ORM\Table(
 *     indexes={
 *
 *         @ORM\Index(name="business_entity_customer_b2b_be_idx", columns={"id_business_entity"}),
 *         @ORM\Index(name="business_entity_customer_b2b_customer_idx", columns={"id_customer_b2b"}),
 *         @ORM\Index(name="business_entity_customer_b2b_role_idx", columns={"id_role_b2b"}),
 *
 *     @ORM\UniqueConstraint(name="uniq_be_customer", columns={"id_business_entity", "id_customer_b2b"})
 *     }
 *  )
 *
 * @ORM\HasLifecycleCallbacks
 *
 * @ORM\Entity()
 */
class BusinessEntityCustomerB2b
{
    /**
     * @ORM\Id
     *
     * @ORM\Column(name="id_business_entity_customer_b2b", type="integer", options={"unsigned"=true})
     *
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $idBusinessEntityCustomerB2b = null;

    /**
     * @ORM\ManyToOne(targetEntity="PrestaShopBundle\Entity\BusinessEntity", inversedBy="businessEntityCustomerB2bs")
     *
     * @ORM\JoinColumn(name="id_business_entity", referencedColumnName="id_business_entity", nullable=false)
     */
    private BusinessEntity $businessEntity;

    /**
     * @ORM\Column(name="id_customer_b2b", type="integer", options={"unsigned"=true})
     */
    private int $idCustomerB2b;

    /**
     * @ORM\ManyToOne(targetEntity="PrestaShopBundle\Entity\B2bRole", inversedBy="businessEntityCustomerB2bs")
     *
     * @ORM\JoinColumn(name="id_role_b2b", referencedColumnName="id_role", nullable=false)
     */
    private B2bRole $b2bRole;

    /**
     * @ORM\Column(name="is_default", type="boolean", options={"default"=false})
     */
    private bool $isDefault = false;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private DateTime $createdAt;

    public function getIdBusinessEntityCustomerB2b(): int
    {
        return $this->idBusinessEntityCustomerB2b;
    }

    public function getBusinessEntity(): BusinessEntity
    {
        return $this->businessEntity;
    }

    public function setBusinessEntity(BusinessEntity $businessEntity): self
    {
        $this->businessEntity = $businessEntity;

        return $this;
    }

    public function getCustomerB2b(): int
    {
        return $this->idCustomerB2b;
    }

    public function setCustomerB2b(int $idCustomerB2b): self
    {
        $this->idCustomerB2b = $idCustomerB2b;

        return $this;
    }

    public function getB2bRole(): B2bRole
    {
        return $this->b2bRole;
    }

    public function setB2bRole(B2bRole $b2bRole): self
    {
        $this->b2bRole = $b2bRole;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @ORM\PrePersist
     */
    public function setTimestampsOnCreate(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new DateTime();
        }
    }
}
