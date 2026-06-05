<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyValueCaster;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface;
use PrestaShopBundle\Form\Admin\Type\NavigationTabType;
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
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ExtraPropertyWriterInterface $writer,
        protected readonly ShopContext $shopContext,
        protected readonly ExtraPropertyValueCaster $caster,
    ) {
    }

    public function persist(FormInterface $form, string $entityName, int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }

        $definitions = $this->repository->getAllDefinitions()->filterByForm($entityName);
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
            $columnName = $definition->getStorageColumnName();

            // getFormEntry() returns the already-parsed array — no need to re-parse.
            $formEntry = $definition->getFormEntry($entityName);
            $targetPath = $formEntry['path'] ?? '';
            if ('' === $targetPath) {
                // Keep fallback placement consistent with ExtraPropertiesFormBuilderModifier.
                $targetPath = ($form->has(ExtraPropertiesFormBuilderModifier::DEFAULT_FALLBACK_TAB) || $this->isNavigationTabForm($form))
                    ? ExtraPropertiesFormBuilderModifier::DEFAULT_FALLBACK_TAB . '.' . ExtraPropertiesFormBuilderModifier::FALLBACK_FORM_SECTION
                    : '';
            }
            $formFieldName = $definition->getFormFieldName();

            $targetForm = $this->resolveTargetFormForExtraField($form, $targetPath, $formFieldName);
            if (null === $targetForm) {
                continue;
            }

            $submittedValue = $targetForm->get($formFieldName)->getData();
            $submittedValue = $this->caster->castForDb($definition, $submittedValue);

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
                    $langValuesByIdLang[$idLang][$columnName] = $value;
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
        return null !== $firstDefinition ? $firstDefinition->getEntityName() : $fallbackEntityName;
    }

    /**
     * Resolves the sub-form that holds the unmapped extra field, consistent with ExtraPropertiesFormBuilderModifier.
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
        // Strip :before/:after suffix — the extra field lives in the *parent* builder.
        // e.g. "header.name:before" → strip ":before" → "header.name" → drop "name" → "header"
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
}
