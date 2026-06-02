<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use DateTimeInterface;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface;
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
 * - Write directly via ExtraPropertyWriterInterface.
 */
class ExtraPropertiesFormDataPersister
{
    private const DEFAULT_FALLBACK_TAB = 'extra_fields';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ExtraPropertyWriterInterface $writer,
        protected readonly ShopContext $shopContext,
    ) {
    }

    public function persist(FormInterface $form, string $entityName, int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }

        $definitions = $this->repository->getDefinitionCollectionByFormId($entityName);
        if ($definitions->isEmpty()) {
            return;
        }

        $shopConstraint = $this->shopContext->getShopConstraint();
        $hasShop = $shopConstraint->isSingleShopContext();

        $storageEntityName = $this->resolveStorageEntityName($entityName, $definitions->first());

        $entityValues = [];
        $langValuesByIdLang = [];
        $shopValues = [];

        foreach ($definitions as $definition) {
            $fieldName = $definition->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $columnName = $definition->getStorageColumnName();

            $formEntry = $definition->getFormEntry($entityName);
            $parsed = null !== $formEntry ? ExtraPropertyDefinition::parseFormEntry($formEntry) : null;
            $targetPath = $parsed['path'] ?? '';
            if ('' === $targetPath) {
                // Keep fallback placement consistent with ExtraPropertiesFormBuilderModifier.
                $targetPath = ($form->has(self::DEFAULT_FALLBACK_TAB) || $this->isNavigationTabForm($form))
                    ? self::DEFAULT_FALLBACK_TAB . '.' . ExtraPropertiesFormBuilderModifier::FALLBACK_FORM_SECTION
                    : '';
            }
            $formFieldName = $definition->getFormFieldName();

            $targetForm = $this->resolveTargetFormForExtraField($form, $targetPath, $formFieldName);
            if (null === $targetForm) {
                continue;
            }

            $submittedValue = $targetForm->get($formFieldName)->getData();
            $submittedValue = $this->normalizeSubmittedValueForStorage($definition, $submittedValue);

            $scope = $definition->getScope();
            if (ExtraPropertyScope::LANG === $scope) {
                if (!is_array($submittedValue) || !$hasShop) {
                    continue;
                }
                foreach ($submittedValue as $idLang => $value) {
                    $idLang = (int) $idLang;
                    if ($idLang <= 0) {
                        continue;
                    }
                    $langValuesByIdLang[$idLang][$columnName] = $this->normalizeSubmittedValueForStorage($definition, $value);
                }
            } elseif (ExtraPropertyScope::SHOP === $scope) {
                if (!$hasShop) {
                    continue;
                }
                $shopValues[$columnName] = $submittedValue;
            } else {
                $entityValues[$columnName] = $submittedValue;
            }
        }

        $this->writer->writeAll(
            $storageEntityName,
            'id_' . $storageEntityName,
            $entityId,
            $entityValues,
            $langValuesByIdLang,
            $shopValues,
            $shopConstraint
        );
    }

    protected function resolveStorageEntityName(string $fallbackEntityName, ?ExtraPropertyDefinition $firstDefinition): string
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
        // Strip :before/:after suffix — the extra field lives in the *parent* builder,
        // not in a child named "something:before". After stripping the suffix we also
        // drop the reference segment (the sibling used for positioning) so we navigate
        // to the form that actually owns the extra field.
        // e.g. "header.name:before" → strip ":before" → "header.name" → drop "name" → "header"
        // e.g. "name:before"        → strip ":before" → "name"         → drop "name" → "" (root)
        foreach ([':before', ':after'] as $suffix) {
            if (str_ends_with($path, $suffix)) {
                $path = substr($path, 0, -strlen($suffix));
                $lastDot = strrpos($path, '.');
                $path = false !== $lastDot ? substr($path, 0, $lastDot) : '';
                break;
            }
        }

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
    protected function normalizeSubmittedValueForStorage(ExtraPropertyDefinition $definition, $value)
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
