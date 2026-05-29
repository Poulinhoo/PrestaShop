<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyType;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\BulkActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;
use PrestaShop\PrestaShop\Core\Grid\Action\GridActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\ModalOptions;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\AccessibilityChecker\NonModuleExtraPropertyDefinitionAccessibilityChecker;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\SubmitRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Type\SimpleGridAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\BulkActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ToggleColumn;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Builds the grid definition for the BO extra property definition management page.
 *
 * The grid lists all entries in the extra_property_definition registry table.
 * Two ToggleColumns (display_api, display_front) allow inline AJAX toggling.
 * Edit and Delete actions are hidden for module-owned rows (module_name IS NOT NULL).
 */
final class ExtraPropertyDefinitionGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    use BulkDeleteActionTrait;
    use DeleteActionTrait;

    public const GRID_ID = 'extra_property_definition';

    public function __construct(
        HookDispatcherInterface $hookDispatcher,
        private readonly NonModuleExtraPropertyDefinitionAccessibilityChecker $accessibilityChecker,
    ) {
        parent::__construct($hookDispatcher);
    }

    /**
     * {@inheritdoc}
     */
    protected function getId(): string
    {
        return self::GRID_ID;
    }

    /**
     * {@inheritdoc}
     */
    protected function getName(): string
    {
        return $this->trans('Extra property definitions', [], 'Admin.Advparameters.Feature');
    }

    /**
     * {@inheritdoc}
     */
    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add(
                (new BulkActionColumn('bulk_action'))
                    ->setOptions([
                        'bulk_field' => 'id_extra_property_definition',
                    ])
            )
            ->add(
                (new DataColumn('id_extra_property_definition'))
                    ->setName($this->trans('ID', [], 'Admin.Global'))
                    ->setOptions([
                        'field' => 'id_extra_property_definition',
                    ])
            )
            ->add(
                (new DataColumn('entity_name'))
                    ->setName($this->trans('Entity', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'entity_name',
                    ])
            )
            ->add(
                (new DataColumn('module_name'))
                    ->setName($this->trans('Module', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'module_name',
                    ])
            )
            ->add(
                (new DataColumn('property_name'))
                    ->setName($this->trans('Property name', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'property_name',
                    ])
            )
            ->add(
                (new DataColumn('type'))
                    ->setName($this->trans('Type', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'type',
                    ])
            )
            ->add(
                (new DataColumn('scope'))
                    ->setName($this->trans('Scope', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'scope',
                    ])
            )
            ->add(
                (new ToggleColumn('display_api'))
                    ->setName($this->trans('API', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'display_api',
                        'primary_field' => 'id_extra_property_definition',
                        'route' => 'admin_extra_property_definitions_toggle_display_api',
                        'route_param_name' => 'extraPropertyDefinitionId',
                    ])
            )
            ->add(
                (new ToggleColumn('display_front'))
                    ->setName($this->trans('Front', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'field' => 'display_front',
                        'primary_field' => 'id_extra_property_definition',
                        'route' => 'admin_extra_property_definitions_toggle_display_front',
                        'route_param_name' => 'extraPropertyDefinitionId',
                    ])
            )
            ->add(
                (new ActionColumn('actions'))
                    ->setName($this->trans('Actions', [], 'Admin.Global'))
                    ->setOptions([
                        'actions' => (new RowActionCollection())
                            ->add(
                                (new LinkRowAction('edit'))
                                    ->setIcon('edit')
                                    ->setName($this->trans('Edit', [], 'Admin.Actions'))
                                    ->setOptions([
                                        'route' => 'admin_extra_property_definitions_edit',
                                        'route_param_name' => 'extraPropertyDefinitionId',
                                        'route_param_field' => 'id_extra_property_definition',
                                        'clickable_row' => true,
                                        'accessibility_checker' => $this->accessibilityChecker,
                                    ])
                            )
                            ->add(
                                $this->buildDeleteAction(
                                    'admin_extra_property_definitions_delete',
                                    'extraPropertyDefinitionId',
                                    'id_extra_property_definition',
                                    'POST',
                                    [],
                                    [
                                        'accessibility_checker' => $this->accessibilityChecker,
                                        'confirm_message' => $this->trans('Are you sure you want to delete the selected item(s)? The SQL column will be kept.', [], 'Admin.Advparameters.Feature'),
                                    ]
                                )
                            )
                            ->add(
                                (new SubmitRowAction('delete_drop_column'))
                                    ->setName($this->trans('Delete + drop column', [], 'Admin.Advparameters.Feature'))
                                    ->setIcon('delete_forever')
                                    ->setOptions([
                                        'route' => 'admin_extra_property_definitions_delete_drop_column',
                                        'route_param_name' => 'extraPropertyDefinitionId',
                                        'route_param_field' => 'id_extra_property_definition',
                                        'method' => 'POST',
                                        'confirm_message' => $this->trans('Are you sure? This will also permanently DROP the SQL column and all its data.', [], 'Admin.Advparameters.Feature'),
                                        'accessibility_checker' => $this->accessibilityChecker,
                                        'modal_options' => new ModalOptions([
                                            'title' => $this->trans('Delete + drop column', [], 'Admin.Actions'),
                                            'confirm_button_label' => $this->trans('Delete + drop', [], 'Admin.Actions'),
                                            'close_button_label' => $this->trans('Cancel', [], 'Admin.Actions'),
                                            'confirm_button_class' => 'btn-danger',
                                        ]),
                                    ])
                            ),
                    ])
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function getFilters()
    {
        $typeChoices = array_combine(
            ExtraPropertyType::values(),
            ExtraPropertyType::values()
        );

        $scopeChoices = array_combine(
            ExtraPropertyScope::values(),
            ExtraPropertyScope::values()
        );

        return (new FilterCollection())
            ->add(
                (new Filter('entity_name', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Search entity', [], 'Admin.Actions')],
                    ])
                    ->setAssociatedColumn('entity_name')
            )
            ->add(
                (new Filter('module_name', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Search module', [], 'Admin.Actions')],
                    ])
                    ->setAssociatedColumn('module_name')
            )
            ->add(
                (new Filter('property_name', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Search property', [], 'Admin.Actions')],
                    ])
                    ->setAssociatedColumn('property_name')
            )
            ->add(
                (new Filter('type', ChoiceType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'choices' => $typeChoices,
                        'placeholder' => $this->trans('-- Type --', [], 'Admin.Actions'),
                    ])
                    ->setAssociatedColumn('type')
            )
            ->add(
                (new Filter('scope', ChoiceType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'choices' => $scopeChoices,
                        'placeholder' => $this->trans('-- Scope --', [], 'Admin.Actions'),
                    ])
                    ->setAssociatedColumn('scope')
            )
            ->add(
                (new Filter('actions', SearchAndResetType::class))
                    ->setTypeOptions([
                        'reset_route' => 'admin_common_reset_search_by_filter_id',
                        'reset_route_params' => [
                            'filterId' => self::GRID_ID,
                        ],
                        'redirect_route' => 'admin_extra_property_definitions_index',
                    ])
                    ->setAssociatedColumn('actions')
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function getGridActions()
    {
        return (new GridActionCollection())
            ->add(
                (new SimpleGridAction('common_refresh_list'))
                    ->setName($this->trans('Refresh list', [], 'Admin.Advparameters.Feature'))
                    ->setIcon('refresh')
            )
            ->add(
                (new SimpleGridAction('common_show_query'))
                    ->setName($this->trans('Show SQL query', [], 'Admin.Actions'))
                    ->setIcon('code')
            )
            ->add(
                (new SimpleGridAction('common_export_sql_manager'))
                    ->setName($this->trans('Export to SQL Manager', [], 'Admin.Actions'))
                    ->setIcon('storage')
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function getBulkActions()
    {
        return (new BulkActionCollection())
            ->add(
                $this->buildBulkDeleteAction(
                    'admin_extra_property_definitions_bulk_delete',
                    [
                        'confirm_message' => $this->trans('Are you sure you want to delete the selected item(s)? The SQL columns will be kept.', [], 'Admin.Advparameters.Feature'),
                    ]
                )
            )
            ->add(
                (new SubmitBulkAction('delete_selection_drop_column'))
                    ->setName($this->trans('Delete selected + drop SQL columns', [], 'Admin.Advparameters.Feature'))
                    ->setOptions([
                        'submit_route' => 'admin_extra_property_definitions_bulk_delete_drop_column',
                        'confirm_message' => $this->trans('Are you sure? This will also permanently DROP the SQL columns and all their data.', [], 'Admin.Advparameters.Feature'),
                        'modal_options' => new ModalOptions([
                            'title' => $this->trans('Delete + drop columns', [], 'Admin.Actions'),
                            'confirm_button_label' => $this->trans('Delete + drop', [], 'Admin.Actions'),
                            'close_button_label' => $this->trans('Cancel', [], 'Admin.Actions'),
                            'confirm_button_class' => 'btn-danger',
                        ]),
                    ])
            );
    }
}
