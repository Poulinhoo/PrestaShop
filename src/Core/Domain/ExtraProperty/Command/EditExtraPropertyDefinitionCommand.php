<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\ValueObject\ExtraPropertyDefinitionId;

/**
 * Updates editable metadata for a core extra property definition.
 *
 * Structural fields (entity_name, property_name, type, scope, size, sql_index)
 * are intentionally absent — they cannot be changed without a DDL ALTER TABLE.
 * Use a builder pattern: construct with the id, then call setters as needed.
 */
class EditExtraPropertyDefinitionCommand
{
    protected ExtraPropertyDefinitionId $id;
    protected ?bool $displayApi = null;
    protected ?bool $displayFront = null;
    protected ?bool $formRequired = null;
    protected ?string $labelWording = null;
    protected ?string $labelDomain = null;
    protected ?string $descriptionWording = null;
    protected ?string $descriptionDomain = null;
    protected ?string $validator = null;
    protected ?string $formFieldType = null;
    protected ?string $formOptions = null;
    protected ?string $associatedForms = null;
    protected ?string $associatedGrids = null;

    public function __construct(int $id)
    {
        $this->id = new ExtraPropertyDefinitionId($id);
    }

    public function getId(): ExtraPropertyDefinitionId
    {
        return $this->id;
    }

    public function getDisplayApi(): ?bool
    {
        return $this->displayApi;
    }

    public function setDisplayApi(bool $displayApi): self
    {
        $this->displayApi = $displayApi;

        return $this;
    }

    public function getDisplayFront(): ?bool
    {
        return $this->displayFront;
    }

    public function setDisplayFront(bool $displayFront): self
    {
        $this->displayFront = $displayFront;

        return $this;
    }

    public function getFormRequired(): ?bool
    {
        return $this->formRequired;
    }

    public function setFormRequired(bool $formRequired): self
    {
        $this->formRequired = $formRequired;

        return $this;
    }

    public function getLabelWording(): ?string
    {
        return $this->labelWording;
    }

    public function setLabelWording(?string $labelWording): self
    {
        $this->labelWording = $labelWording;

        return $this;
    }

    public function getLabelDomain(): ?string
    {
        return $this->labelDomain;
    }

    public function setLabelDomain(?string $labelDomain): self
    {
        $this->labelDomain = $labelDomain;

        return $this;
    }

    public function getDescriptionWording(): ?string
    {
        return $this->descriptionWording;
    }

    public function setDescriptionWording(?string $descriptionWording): self
    {
        $this->descriptionWording = $descriptionWording;

        return $this;
    }

    public function getDescriptionDomain(): ?string
    {
        return $this->descriptionDomain;
    }

    public function setDescriptionDomain(?string $descriptionDomain): self
    {
        $this->descriptionDomain = $descriptionDomain;

        return $this;
    }

    public function getValidator(): ?string
    {
        return $this->validator;
    }

    public function setValidator(?string $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    public function getFormFieldType(): ?string
    {
        return $this->formFieldType;
    }

    public function setFormFieldType(?string $formFieldType): self
    {
        $this->formFieldType = $formFieldType;

        return $this;
    }

    public function getFormOptions(): ?string
    {
        return $this->formOptions;
    }

    public function setFormOptions(?string $formOptions): self
    {
        $this->formOptions = $formOptions;

        return $this;
    }

    public function getAssociatedForms(): ?string
    {
        return $this->associatedForms;
    }

    public function setAssociatedForms(?string $associatedForms): self
    {
        $this->associatedForms = $associatedForms;

        return $this;
    }

    public function getAssociatedGrids(): ?string
    {
        return $this->associatedGrids;
    }

    public function setAssociatedGrids(?string $associatedGrids): self
    {
        $this->associatedGrids = $associatedGrids;

        return $this;
    }
}
