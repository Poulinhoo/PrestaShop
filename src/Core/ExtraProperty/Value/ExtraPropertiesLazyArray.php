<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Value;

use Context;
use ObjectModel;
use PrestaShop\PrestaShop\Adapter\ContainerFinder;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use Throwable;

/**
 * Resolves extra field values (grouped by module) for an entity instance.
 *
 * This is not an AbstractLazyArray: it only encapsulates the call to
 * ExtraPropertyReaderInterface::getExtraProperties() using entity metadata
 * from ObjectModel definitions (table + primary key) and the current context (lang / shop).
 *
 * Must be instantiated through one of the two static factories:
 * {@see self::fromObjectModel()} or {@see self::fromObjectModelClass()}.
 * Both factories set provider to null when the state is invalid, making getValues() a no-op.
 */
final class ExtraPropertiesLazyArray
{
    /**
     * @param ExtraPropertyReaderInterface|null $provider Null = no-op sentinel; set by factories on invalid state
     * @param ExtraPropertyDefinitionRepositoryInterface|null $repository For display_front filtering; null = no filter
     * @param string $entityTable Registry entity name (ObjectModel definition `table`)
     * @param string $primaryKeyName Primary column name (ObjectModel definition `primary`)
     * @param int $entityId Entity row id
     * @param int $langId Language id for lang-scoped fields
     * @param ShopConstraint $shopConstraint Shop constraint for shop / lang multishop resolution
     * @param bool $isLangMultishop Whether lang-scoped fields should also be filtered by shop (FO multishop pattern)
     */
    private function __construct(
        private readonly ?ExtraPropertyReaderInterface $provider,
        private readonly ?ExtraPropertyDefinitionRepositoryInterface $repository,
        private readonly string $entityTable,
        private readonly string $primaryKeyName,
        private readonly int $entityId,
        private readonly int $langId,
        private readonly ShopConstraint $shopConstraint,
        private readonly bool $isLangMultishop = false,
    ) {
    }

    /**
     * Builds a resolver from a loaded ObjectModel instance (uses $object->def and $object->id).
     *
     * Returns a no-op instance (provider = null) when the object is not yet persisted ($id <= 0).
     *
     * @param ObjectModel $object
     */
    public static function fromObjectModel(ObjectModel $object): self
    {
        if ((int) $object->id <= 0) {
            return new self(null, null, '', '', 0, 0, ShopConstraint::allShops());
        }

        $provider = null;
        $repository = null;
        try {
            $containerFinder = new ContainerFinder(Context::getContext());
            $container = $containerFinder->getContainer();
            /** @var ExtraPropertyReaderInterface $provider */
            $provider = $container->get(ExtraPropertyReaderInterface::class);
            /** @var ExtraPropertyDefinitionRepositoryInterface $repository */
            $repository = $container->get(ExtraPropertyDefinitionRepositoryInterface::class);
        } catch (Throwable) {
        }

        /** @var array<string, mixed> $def */
        $def = ObjectModel::getDefinition($object);
        $context = Context::getContext();

        return new self(
            $provider,
            $repository,
            (string) ($def['table'] ?? ''),
            (string) ($def['primary'] ?? ''),
            (int) $object->id,
            (int) $context->language->id,
            $context->getShopConstraint(),
            (bool) $object->isLangMultishop(),
        );
    }

    /**
     * Builds a resolver from an ObjectModel class name and a row id (e.g. product array in presenters).
     *
     * Returns a no-op instance (provider = null) when the class or definition is invalid.
     *
     * @param class-string<ObjectModel> $objectModelClass
     * @param int $entityId
     */
    public static function fromObjectModelClass(string $objectModelClass, int $entityId): self
    {
        if (!class_exists($objectModelClass) || !is_subclass_of($objectModelClass, ObjectModel::class)) {
            return new self(null, null, '', '', 0, 0, ShopConstraint::allShops());
        }

        $def = ObjectModel::getDefinition($objectModelClass);
        if (!is_array($def) || empty($def['table']) || empty($def['primary'])) {
            return new self(null, null, '', '', 0, 0, ShopConstraint::allShops());
        }

        $provider = null;
        $repository = null;
        try {
            $containerFinder = new ContainerFinder(Context::getContext());
            $container = $containerFinder->getContainer();
            /** @var ExtraPropertyReaderInterface $provider */
            $provider = $container->get(ExtraPropertyReaderInterface::class);
            /** @var ExtraPropertyDefinitionRepositoryInterface $repository */
            $repository = $container->get(ExtraPropertyDefinitionRepositoryInterface::class);
        } catch (Throwable) {
        }

        $context = Context::getContext();
        $isLangMultishop = !empty($def['multilang']) && !empty($def['multilang_shop']);

        return new self(
            $provider,
            $repository,
            (string) $def['table'],
            (string) $def['primary'],
            $entityId,
            (int) $context->language->id,
            $context->getShopConstraint(),
            $isLangMultishop,
        );
    }

    /**
     * Returns extra fields grouped by module for front-office display.
     *
     * Only fields with display_front=true are returned. Returns an empty array when
     * provider or repository are null (invalid/unresolvable state), or when no
     * front-office fields are registered for this entity.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getValues(): array
    {
        if (null === $this->provider) {
            return [];
        }

        // X2: skip DB read entirely when no FO fields are registered.
        $frontOfficeDefinitions = null !== $this->repository
            ? $this->repository->getAllDefinitions()->filterByEntity($this->entityTable)->filterForFrontOffice()
            : ExtraPropertyDefinitionCollection::empty();

        if ($frontOfficeDefinitions->isEmpty()) {
            return [];
        }

        $allValues = $this->provider->getExtraProperties(
            $this->entityTable,
            $this->primaryKeyName,
            $this->entityId,
            $this->langId,
            $this->shopConstraint,
            $this->isLangMultishop
        );

        // X2: strip fields that are not in the FO whitelist (display_front = false).
        return $this->filterToFrontOfficeDefinitions($allValues, $frontOfficeDefinitions);
    }

    /**
     * Keeps only fields present in the FO-allowed definitions collection.
     *
     * @param array<string, array<string, mixed>> $allValues
     *
     * @return array<string, array<string, mixed>>
     */
    private function filterToFrontOfficeDefinitions(array $allValues, ExtraPropertyDefinitionCollection $frontOfficeDefinitions): array
    {
        $whitelist = [];
        foreach ($frontOfficeDefinitions as $definition) {
            $moduleKey = $definition->getDisplayModuleKey();
            $fieldName = $definition->getPropertyName();
            if ('' !== $fieldName) {
                $whitelist[$moduleKey][$fieldName] = true;
            }
        }

        $result = [];
        foreach ($allValues as $moduleKey => $fields) {
            foreach ($fields as $fieldName => $value) {
                if (!empty($whitelist[$moduleKey][$fieldName])) {
                    $result[$moduleKey][$fieldName] = $value;
                }
            }
        }

        return $result;
    }
}
