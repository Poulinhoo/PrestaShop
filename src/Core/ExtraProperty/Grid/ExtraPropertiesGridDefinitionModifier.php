<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Grid;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollectionInterface;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnInterface;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DateTimeColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ToggleColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinition;
use PrestaShop\PrestaShop\Core\Grid\Exception\ColumnNotFoundException;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShopBundle\Form\Admin\Type\YesAndNoChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds extra properties columns and filters into BO Symfony grids.
 */
class ExtraPropertiesGridDefinitionModifier
{
    public function __construct(
        protected readonly ExtraPropertiesGridDefinitionProvider $definitionProvider,
        protected readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param GridDefinition $definition
     * @param string $gridId Grid identifier (usually equals entity table name, e.g. "product")
     */
    public function apply(GridDefinition $definition, string $gridId): void
    {
        $definitions = $this->definitionProvider->getDefinitionsForGrid($gridId);
        if ($definitions->isEmpty()) {
            return;
        }

        $columns = $definition->getColumns();
        $filters = $definition->getFilters();

        foreach ($definitions as $extraDefinition) {
            $fieldName = $extraDefinition->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $moduleName = ExtraPropertyNaming::displayModuleKey($extraDefinition->getModuleName());
            $scope = $extraDefinition->getFieldScope();

            $columnId = ExtraPropertyNaming::formFieldName($moduleName, $fieldName, $scope);
            if ($this->hasColumnId($columns, $columnId)) {
                continue;
            }

            $defaultLabel = $this->translator->trans(ucfirst(str_replace('_', ' ', $fieldName)), [], 'Admin.Global');
            $label = $this->translateLabel(
                $extraDefinition->getTitleWording(),
                $extraDefinition->getTitleDomain(),
                $defaultLabel
            );

            $column = $this->buildColumn($gridId, $columnId, $label, $extraDefinition);

            $positionRef = trim($extraDefinition->getGridPosition() ?? '');
            if ('' !== $positionRef) {
                try {
                    $columns->addAfter($positionRef, $column);
                } catch (ColumnNotFoundException) {
                    $this->addBeforeActionsOrAtEnd($columns, $column);
                }
            } else {
                $this->addBeforeActionsOrAtEnd($columns, $column);
            }

            [$filterType, $filterOptions] = $this->resolveFilterTypeAndOptions($extraDefinition);
            $filters->add(
                (new Filter($columnId, $filterType))
                    ->setAssociatedColumn($columnId)
                    ->setTypeOptions($filterOptions)
            );
        }
    }

    protected function buildColumn(string $gridId, string $columnId, string $label, ExtraPropertyDefinitionInfo $definition): ColumnInterface
    {
        $declaredType = $definition->getFormFieldType();
        $scope = $definition->getFieldScope();
        $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());
        $fieldName = $definition->getPropertyName();

        if (CheckboxType::class === $declaredType && '' !== $fieldName) {
            $primaryField = 'id_' . $gridId;
            $legacyController = $this->guessLegacyController($gridId);
            $entityName = $definition->getEntityName();

            return (new ToggleColumn($columnId))
                ->setName($label)
                ->setOptions([
                    'field' => $columnId,
                    'primary_field' => $primaryField,
                    'route' => 'admin_common_extra_properties_toggle',
                    'route_param_name' => 'entityId',
                    'extra_route_params' => [
                        'entityName' => $entityName,
                        'moduleName' => $moduleName,
                        'propertyName' => $fieldName,
                        'scope' => $scope,
                        'shopId' => 'id_shop_default',
                        '_legacy_controller' => $legacyController,
                    ],
                ]);
        }

        if (\Symfony\Component\Form\Extension\Core\Type\DateTimeType::class === $declaredType) {
            return (new DateTimeColumn($columnId))
                ->setName($label)
                ->setOptions([
                    'field' => $columnId,
                    'sortable' => true,
                    'clickable' => false,
                ]);
        }

        return (new DataColumn($columnId))
            ->setName($label)
            ->setOptions([
                'field' => $columnId,
                'sortable' => true,
                'clickable' => false,
            ]);
    }

    protected function guessLegacyController(string $entityName): string
    {
        return 'Admin' . ucfirst($entityName) . 's';
    }

    /**
     * @return array{0: class-string, 1: array<string, mixed>}
     */
    protected function resolveFilterTypeAndOptions(ExtraPropertyDefinitionInfo $definition): array
    {
        if (CheckboxType::class === $definition->getFormFieldType()) {
            return [YesAndNoChoiceType::class, ['required' => false]];
        }

        return [TextType::class, ['required' => false]];
    }

    protected function addBeforeActionsOrAtEnd(ColumnCollectionInterface $columns, ColumnInterface $column): void
    {
        if ($this->hasColumnId($columns, 'actions')) {
            $columns->addBefore('actions', $column);

            return;
        }

        $columns->add($column);
    }

    protected function hasColumnId(ColumnCollectionInterface $columns, string $id): bool
    {
        foreach ($columns as $column) {
            if ($column instanceof ColumnInterface && $id === $column->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translates a wording/domain pair from a definition, falling back to $default.
     */
    protected function translateLabel(?string $wording, ?string $domain, string $default): string
    {
        if (null === $wording || '' === trim($wording)) {
            return $default;
        }

        return $this->translator->trans($wording, [], $domain ?? 'Admin.Global');
    }
}
