<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use DateTimeInterface;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\UpdateExtraPropertyValuesCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShopBundle\Form\Admin\Type\NavigationTabType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;

/**
 * Persists extra properties submitted in a Back Office form.
 *
 * Strategy:
 * - Read submitted values from form fields (unmapped) based on definitions.
 * - Collect all three scope payloads (common, lang, shop) from the form.
 * - Dispatch a single UpdateExtraPropertyValuesCommand via the command bus.
 */
class ExtraPropertiesFormDataPersister
{
    private const DEFAULT_FALLBACK_TAB = 'extra_fields';

    public function __construct(
        protected readonly ExtraPropertiesFormDefinitionProvider $definitionProvider,
        protected readonly CommandBusInterface $commandBus,
    ) {
    }

    public function persist(FormInterface $form, string $entityName, int $entityId, int $shopId): void
    {
        if ($entityId <= 0) {
            return;
        }

        $definitions = $this->definitionProvider->getDefinitionsForEntity($entityName);
        if ($definitions->isEmpty()) {
            return;
        }

        $storageEntityName = $this->resolveStorageEntityName($entityName, $definitions->first());

        $entityValues = [];
        $langValuesByIdLang = [];
        $shopValues = [];

        foreach ($definitions as $definition) {
            $fieldName = $definition->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());
            $scope = $definition->getFieldScope();
            $columnName = ExtraPropertyNaming::storageColumnName($definition->getModuleName() ?? '', $fieldName);

            $targetPath = trim($definition->getFormPosition() ?? '');
            if ('' === $targetPath) {
                // Keep fallback placement consistent with ExtraPropertiesFormBuilderModifier.
                $targetPath = ($form->has(self::DEFAULT_FALLBACK_TAB) || $this->isNavigationTabForm($form))
                    ? self::DEFAULT_FALLBACK_TAB . '.' . ExtraPropertiesFormBuilderModifier::FALLBACK_FORM_SECTION
                    : '';
            }
            $formFieldName = ExtraPropertyNaming::formFieldName($moduleName, $fieldName, $scope);

            $targetForm = $this->resolveTargetFormForExtraField($form, $targetPath, $formFieldName);
            if (null === $targetForm) {
                continue;
            }

            $submittedValue = $targetForm->get($formFieldName)->getData();
            $submittedValue = $this->normalizeSubmittedValueForStorage($definition, $submittedValue);

            if ('lang' === $scope) {
                if (!is_array($submittedValue) || $shopId <= 0) {
                    continue;
                }
                foreach ($submittedValue as $idLang => $value) {
                    $idLang = (int) $idLang;
                    if ($idLang <= 0) {
                        continue;
                    }
                    $langValuesByIdLang[$idLang][$columnName] = $this->normalizeSubmittedValueForStorage($definition, $value);
                }
            } elseif ('shop' === $scope) {
                if ($shopId <= 0) {
                    continue;
                }
                $shopValues[$columnName] = $submittedValue;
            } else {
                $entityValues[$columnName] = $submittedValue;
            }
        }

        // Build shop-scope payload: the BO form always writes for the current shop only
        $shopValuesByShopId = ($shopId > 0 && !empty($shopValues)) ? [$shopId => $shopValues] : [];

        $this->commandBus->handle(new UpdateExtraPropertyValuesCommand(
            $storageEntityName,
            'id_' . $storageEntityName,
            $entityId,
            $entityValues,
            $langValuesByIdLang,
            $shopValuesByShopId,
            $shopId > 0 ? $shopId : null
        ));
    }

    protected function resolveStorageEntityName(string $fallbackEntityName, ?ExtraPropertyDefinitionInfo $firstDefinition): string
    {
        if (null !== $firstDefinition && '' !== trim($firstDefinition->getEntityName())) {
            return trim($firstDefinition->getEntityName());
        }

        return $fallbackEntityName;
    }

    /**
     * Resolves the sub-form that holds the unmapped extra field, consistent with ExtraPropertiesFormBuilderModifier.
     * If the computed path has no field (e.g. extra_fields tab added after the modifier by a hook), falls back to root.
     */
    protected function resolveTargetFormForExtraField(FormInterface $rootForm, string $targetPath, string $formFieldName): ?FormInterface
    {
        $targetForm = $this->resolvePathForm($rootForm, $targetPath);
        if (null !== $targetForm && $targetForm->has($formFieldName)) {
            return $targetForm;
        }
        if ($rootForm->has($formFieldName)) {
            return $rootForm;
        }

        return null;
    }

    protected function resolvePathForm(FormInterface $rootForm, string $path): ?FormInterface
    {
        $segments = array_values(array_filter(array_map('trim', explode('.', $path)), static fn (string $s): bool => '' !== $s));
        if (empty($segments)) {
            return $rootForm;
        }

        $current = $rootForm;
        foreach ($segments as $segment) {
            if (!$current->has($segment)) {
                return null;
            }
            $current = $current->get($segment);
        }

        return $current;
    }

    protected function isNavigationTabForm(FormInterface $form): bool
    {
        return $this->hasNavigationTabTypeInHierarchy($form->getConfig()->getType());
    }

    protected function hasNavigationTabTypeInHierarchy(ResolvedFormTypeInterface $resolvedType): bool
    {
        $current = $resolvedType;
        while (null !== $current) {
            if ($current->getInnerType() instanceof NavigationTabType) {
                return true;
            }
            $current = $current->getParent();
        }

        return false;
    }

    /**
     * Normalizes submitted Symfony values into scalar DB-compatible values.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function normalizeSubmittedValueForStorage(ExtraPropertyDefinitionInfo $definition, $value)
    {
        $declaredType = $definition->getFormFieldType();
        if (CheckboxType::class === $declaredType) {
            return (int) (bool) $value;
        }

        if (DateTimeType::class === $declaredType) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
        }

        return $value;
    }
}
