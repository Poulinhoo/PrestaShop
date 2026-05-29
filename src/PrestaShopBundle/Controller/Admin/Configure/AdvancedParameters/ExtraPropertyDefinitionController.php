<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\Controller\Admin\Configure\AdvancedParameters;

use Exception;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\DeleteExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\EditExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\ToggleExtraPropertyDefinitionDisplayCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\CannotModifyModuleExtraPropertyDefinitionException;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\ExtraPropertyDefinitionNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Query\GetExtraPropertyDefinitionForEditing;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\EditableExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\ExtraPropertyDefinitionFilters;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Form\Admin\Configure\AdvancedParameters\ExtraPropertyDefinition\ExtraPropertyDefinitionType;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the "Configure > Advanced Parameters > Extra Property Definitions" page.
 *
 * Provides CRUD operations (index, create, edit, delete, bulk delete)
 * and AJAX toggle actions for the two display boolean flags
 * (display_api, display_front).
 *
 * All write operations reject module-owned definitions (module_name IS NOT NULL).
 */
class ExtraPropertyDefinitionController extends PrestaShopAdminController
{
    /**
     * Displays the extra property definition list grid.
     *
     * @param ExtraPropertyDefinitionFilters $filters
     * @param GridFactoryInterface $gridFactory
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        ExtraPropertyDefinitionFilters $filters,
        #[Autowire(service: 'prestashop.core.grid.factory.extra_property_definition')]
        GridFactoryInterface $gridFactory,
    ): Response {
        $grid = $gridFactory->getGrid($filters);

        return $this->render(
            '@PrestaShop/Admin/Configure/AdvancedParameters/ExtraPropertyDefinition/index.html.twig',
            [
                'grid' => $this->presentGrid($grid),
                'enableSidebar' => true,
                'help_link' => $this->generateSidebarLink('AdminExtraPropertyDefinitions'),
                'layoutTitle' => $this->trans('Extra property definitions', [], 'Admin.Advparameters.Feature'),
            ]
        );
    }

    /**
     * Displays and handles the extra property definition creation form.
     *
     * @param Request $request
     * @param FormBuilderInterface $formBuilder
     * @param FormHandlerInterface $formHandler
     *
     * @return Response|RedirectResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))")]
    public function createAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.form.builder.extra_property_definition_form_builder')]
        FormBuilderInterface $formBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.handler.extra_property_definition_form_handler')]
        FormHandlerInterface $formHandler,
    ): Response|RedirectResponse {
        $form = $formBuilder->getForm();
        $form->handleRequest($request);

        try {
            $result = $formHandler->handle($form);

            if (null !== $result->getIdentifiableObjectId()) {
                $this->addFlash('success', $this->trans('Successful creation.', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_extra_property_definitions_index');
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->render(
            '@PrestaShop/Admin/Configure/AdvancedParameters/ExtraPropertyDefinition/create.html.twig',
            [
                'extraPropertyDefinitionForm' => $form->createView(),
                'layoutTitle' => $this->trans('New extra property', [], 'Admin.Advparameters.Feature'),
                'enableSidebar' => true,
                'help_link' => $this->generateSidebarLink('AdminExtraPropertyDefinitions'),
            ]
        );
    }

    /**
     * Displays and handles the extra property definition edit form.
     *
     * Rejects editing of module-owned definitions (redirects with error flash).
     *
     * @param int $extraPropertyDefinitionId
     * @param Request $request
     *
     * @return Response|RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_extra_property_definitions_index')]
    public function editAction(
        int $extraPropertyDefinitionId,
        Request $request,
    ): Response|RedirectResponse {
        try {
            /** @var EditableExtraPropertyDefinition $definition */
            $definition = $this->dispatchQuery(new GetExtraPropertyDefinitionForEditing($extraPropertyDefinitionId));
        } catch (ExtraPropertyDefinitionNotFoundException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_extra_property_definitions_index');
        }

        if ($definition->isModuleOwned()) {
            $this->addFlash(
                'warning',
                $this->trans(
                    'This extra property is managed by module "%module%" and cannot be edited from the back office.',
                    ['%module%' => $definition->getModuleName()],
                    'Admin.Advparameters.Notification'
                )
            );

            return $this->redirectToRoute('admin_extra_property_definitions_index');
        }

        $formData = [
            'entity_name' => $definition->getEntityName(),
            'property_name' => $definition->getPropertyName(),
            'type' => $definition->getFieldType(),
            'scope' => $definition->getFieldScope(),
            'sql_index' => $definition->getSqlIndex(),
            'size' => $definition->getSize(),
            'default_value' => $definition->getDefaultValue(),
            'display_api' => $definition->isDisplayApi(),
            'display_front' => $definition->isDisplayFront(),
            'form_required' => $definition->isFormRequired(),
            'label_wording' => $definition->getLabelWording(),
            'label_domain' => $definition->getLabelDomain(),
            'description_wording' => $definition->getDescriptionWording(),
            'description_domain' => $definition->getDescriptionDomain(),
            'validator' => $definition->getValidator(),
            'form_field_type' => $definition->getFormFieldType(),
            'form_options' => $definition->getFormOptions(),
            'associated_forms' => $definition->getAssociatedForms(),
            'associated_grids' => $definition->getAssociatedGrids(),
        ];

        $form = $this->createForm(
            ExtraPropertyDefinitionType::class,
            $formData,
            ['is_edit' => true]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                $command = (new EditExtraPropertyDefinitionCommand($extraPropertyDefinitionId))
                    ->setDisplayApi((bool) ($data['display_api'] ?? false))
                    ->setDisplayFront((bool) ($data['display_front'] ?? false))
                    ->setFormRequired((bool) ($data['form_required'] ?? false))
                    ->setLabelWording($data['label_wording'] ?: null)
                    ->setLabelDomain($data['label_domain'] ?: null)
                    ->setDescriptionWording($data['description_wording'] ?: null)
                    ->setDescriptionDomain($data['description_domain'] ?: null)
                    ->setValidator($data['validator'] ?: null)
                    ->setFormFieldType($data['form_field_type'] ?: null)
                    ->setFormOptions($data['form_options'] ?: null)
                    ->setAssociatedForms($data['associated_forms'] ?: null)
                    ->setAssociatedGrids($data['associated_grids'] ?: null);

                $this->dispatchCommand($command);

                $this->addFlash('success', $this->trans('Successful update.', [], 'Admin.Notifications.Success'));

                return $this->redirectToRoute('admin_extra_property_definitions_index');
            } catch (Exception $e) {
                $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
            }
        }

        return $this->render(
            '@PrestaShop/Admin/Configure/AdvancedParameters/ExtraPropertyDefinition/edit.html.twig',
            [
                'extraPropertyDefinitionForm' => $form->createView(),
                'definition' => $definition,
                'layoutTitle' => $this->trans(
                    'Editing extra property "%name%"',
                    ['%name%' => $definition->getPropertyName()],
                    'Admin.Advparameters.Feature'
                ),
                'enableSidebar' => true,
                'help_link' => $this->generateSidebarLink('AdminExtraPropertyDefinitions'),
            ]
        );
    }

    /**
     * Deletes one extra property definition but keeps its physical SQL column.
     *
     * @param int $extraPropertyDefinitionId
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_extra_property_definitions_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteAction(int $extraPropertyDefinitionId): RedirectResponse
    {
        return $this->doDelete($extraPropertyDefinitionId, false);
    }

    /**
     * Deletes one extra property definition and also drops its physical SQL column.
     *
     * @param int $extraPropertyDefinitionId
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_extra_property_definitions_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteDropColumnAction(int $extraPropertyDefinitionId): RedirectResponse
    {
        return $this->doDelete($extraPropertyDefinitionId, true);
    }

    /**
     * @param int $extraPropertyDefinitionId
     * @param bool $dropColumn
     *
     * @return RedirectResponse
     */
    private function doDelete(int $extraPropertyDefinitionId, bool $dropColumn): RedirectResponse
    {
        try {
            $this->dispatchCommand(new DeleteExtraPropertyDefinitionCommand($extraPropertyDefinitionId, $dropColumn));
            $this->addFlash('success', $this->trans('Successful deletion.', [], 'Admin.Notifications.Success'));
        } catch (CannotModifyModuleExtraPropertyDefinitionException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        } catch (ExtraPropertyDefinitionNotFoundException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_extra_property_definitions_index');
    }

    /**
     * Deletes multiple extra property definitions but keeps their physical SQL columns.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_extra_property_definitions_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function bulkDeleteAction(Request $request): RedirectResponse
    {
        return $this->doBulkDelete($request, false);
    }

    /**
     * Deletes multiple extra property definitions and also drops their physical SQL columns.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_extra_property_definitions_index')]
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function bulkDeleteDropColumnAction(Request $request): RedirectResponse
    {
        return $this->doBulkDelete($request, true);
    }

    /**
     * @param Request $request
     * @param bool $dropColumn
     *
     * @return RedirectResponse
     */
    private function doBulkDelete(Request $request, bool $dropColumn): RedirectResponse
    {
        $ids = $request->request->all('extra_property_definition_bulk_action');

        if (empty($ids)) {
            return $this->redirectToRoute('admin_extra_property_definitions_index');
        }

        $deletedCount = 0;
        $skippedCount = 0;

        foreach (array_map('intval', $ids) as $id) {
            try {
                $this->dispatchCommand(new DeleteExtraPropertyDefinitionCommand($id, $dropColumn));
                ++$deletedCount;
            } catch (CannotModifyModuleExtraPropertyDefinitionException) {
                ++$skippedCount;
            } catch (Exception $e) {
                $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
            }
        }

        if ($deletedCount > 0) {
            $this->addFlash('success', $this->trans('The selection has been successfully deleted.', [], 'Admin.Notifications.Success'));
        }

        if ($skippedCount > 0) {
            $this->addFlash(
                'warning',
                $this->trans(
                    '%count% module-owned definition(s) were skipped and cannot be deleted from the back office.',
                    ['%count%' => $skippedCount],
                    'Admin.Advparameters.Notification'
                )
            );
        }

        return $this->redirectToRoute('admin_extra_property_definitions_index');
    }

    /**
     * Toggles the display_api flag for one definition (AJAX ToggleColumn action).
     *
     * @param int $extraPropertyDefinitionId
     *
     * @return JsonResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_extra_property_definitions_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to edit this.')]
    public function toggleDisplayApiAction(int $extraPropertyDefinitionId): JsonResponse
    {
        return $this->toggleDisplay($extraPropertyDefinitionId, ToggleExtraPropertyDefinitionDisplayCommand::DISPLAY_API);
    }

    /**
     * Toggles the display_front flag for one definition (AJAX ToggleColumn action).
     *
     * @param int $extraPropertyDefinitionId
     *
     * @return JsonResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_extra_property_definitions_index')]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to edit this.')]
    public function toggleDisplayFrontAction(int $extraPropertyDefinitionId): JsonResponse
    {
        return $this->toggleDisplay($extraPropertyDefinitionId, ToggleExtraPropertyDefinitionDisplayCommand::DISPLAY_FRONT);
    }

    /**
     * Dispatches ToggleExtraPropertyDefinitionDisplayCommand for the given field and returns a JSON response.
     *
     * @param int $id
     * @param string $displayField One of ToggleExtraPropertyDefinitionDisplayCommand::DISPLAY_* constants
     *
     * @return JsonResponse
     */
    protected function toggleDisplay(int $id, string $displayField): JsonResponse
    {
        try {
            $this->dispatchCommand(new ToggleExtraPropertyDefinitionDisplayCommand($id, $displayField));

            return $this->json([
                'status' => true,
                'message' => $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success'),
            ]);
        } catch (CannotModifyModuleExtraPropertyDefinitionException $e) {
            return $this->json([
                'status' => false,
                'message' => $this->trans(
                    'This extra property is managed by a module and cannot be modified from the back office.',
                    [],
                    'Admin.Advparameters.Notification'
                ),
            ]);
        } catch (Exception $e) {
            return $this->json([
                'status' => false,
                'message' => $this->getErrorMessageForException($e, $this->getErrorMessages()),
            ]);
        }
    }

    /**
     * Maps domain exceptions to human-readable error messages for flash display.
     *
     * @return array<string, string>
     */
    protected function getErrorMessages(): array
    {
        return [
            ExtraPropertyDefinitionNotFoundException::class => $this->trans(
                'The extra property definition was not found.',
                [],
                'Admin.Advparameters.Notification'
            ),
            CannotModifyModuleExtraPropertyDefinitionException::class => $this->trans(
                'This extra property is managed by a module and cannot be modified from the back office.',
                [],
                'Admin.Advparameters.Notification'
            ),
        ];
    }
}
