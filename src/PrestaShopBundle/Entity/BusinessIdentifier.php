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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * BusinessIdentifier.
 *
 * @ORM\Table()
 *
 * @ORM\Entity()
 */
class BusinessIdentifier
{
    /**
     * @ORM\Id
     *
     * @ORM\Column(name="id_business_identifier", type="integer", options={"unsigned"=true})
     *
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $idBusinessIdentifier;

    /**
     * @ORM\Column(name="unremovable", type="boolean", options={"default"=false})
     */
    private bool $unremovable = false;

    /**
     * @ORM\Column(name="deleted", type="boolean", options={"default"=false})
     */
    private bool $deleted = false;

    /**
     * @ORM\OneToMany(targetEntity="PrestaShopBundle\Entity\BusinessEntityIdentifier", mappedBy="businessIdentifier")
     */
    private Collection $businessEntityIdentifiers;

    public function __construct()
    {
        $this->businessEntityIdentifiers = new ArrayCollection();
    }

    public function getIdBusinessIdentifier(): int
    {
        return $this->idBusinessIdentifier;
    }

    public function getUnremovable(): bool
    {
        return $this->unremovable;
    }

    public function setUnremovable(bool $unremovable): self
    {
        $this->unremovable = $unremovable;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function getBusinessEntityIdentifiers(): Collection
    {
        return $this->businessEntityIdentifiers;
    }

    public function addBusinessEntityIdentifier(BusinessEntityIdentifier $businessEntityIdentifier): self
    {
        if (!$this->businessEntityIdentifiers->contains($businessEntityIdentifier)) {
            $this->businessEntityIdentifiers[] = $businessEntityIdentifier;
            $businessEntityIdentifier->setBusinessIdentifier($this);
        }

        return $this;
    }

    public function removeBusinessEntityIdentifier(BusinessEntityIdentifier $businessEntityIdentifier): self
    {
        $this->businessEntityIdentifiers->removeElement($businessEntityIdentifier);

        return $this;
    }
}
