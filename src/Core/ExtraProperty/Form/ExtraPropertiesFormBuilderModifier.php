<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use InvalidArgumentException;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidationInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyValueCaster;
use PrestaShopBundle\Form\Admin\Type\NavigationTabType;
use PrestaShopBundle\Form\Admin\Type\TranslatableType;
use PrestaShopBundle\Form\FormBuilderModifier;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds extra properties fields into an identifiable object form builder.
 *
 * Placement (associatedForms entry from the definition registry):
 * - null/empty        => dedicated 'extra_fields.extra_properties' section (created if missing); on simple
 *                        forms without tabs, fields are placed at root level instead.
 * - dot-path          => all segments except the last must exist (throws InvalidArgumentException
 *                        on missing intermediate segment); the last segment is the anchor field.
 *                        No mode suffix → treated as :after (default).
 * - dot-path:before   => navigate to the parent builder and insert the field BEFORE the last segment.
 * - dot-path:after    => navigate to the parent builder and insert the field AFTER the last segment.
 *
 * Data mapping:
 * - fields are added as unmapped; persistence reads submitted values from the FormInterface.
 */
class ExtraPropertiesFormBuilderModifier
{
    public const FALLBACK_FORM_SECTION = 'extra_properties';
    public const DEFAULT_FALLBACK_TAB = 'extra_fields';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ExtraPropertyReaderInterface $reader,
        protected readonly TranslatorInterface $translator,
        protected readonly ExtraPropertyValidationInterface $validatorAdapter,
        protected readonly ShopContext $shopContext,
        protected readonly FormBuilderModifier $formBuilderModifier,
        protected readonly ExtraPropertyValueCaster $caster,
    ) {
    }

    /**
     * @param string $formId Form identifier (equals form block_prefix, e.g. 'product', 'category')
     * @param int|null $entityId When null, no prefill is attempted (create form)
     */
    public function apply(FormBuilderInterface $formBuilder, string $formId, ?int $entityId): void
    {
        $definitions = $this->repository->getAllDefinitions()->filterByForm($formId);
        if ($definitions->isEmpty()) {
            return;
        }

        $existingValues = null;
        if (null !== $entityId && $entityId > 0) {
            $storageEntityName = $definitions->first()->getEntityName();
            $existingValues = $this->reader->getExtraProperties(
                $storageEntityName,
                'id_' . $storageEntityName,
                $entityId,
                null,
                $this->shopContext->getShopConstraint(),
                true
            );
        }

        foreach ($definitions as $definition) {
            $fieldName = $definition->getPropertyName();

            // getFormEntry() returns the already-parsed array — no need to re-parse.
            $parsed = $definition->getFormEntry($formId);
            $moduleFormPosition = '';
            if (null !== $parsed && null !== $parsed['path']) {
                $moduleFormPosition = $parsed['path'] . (null !== $parsed['mode'] ? ':' . $parsed['mode'] : '');
            }

            $formFieldName = $definition->getFormFieldName();

            [$type, $typeOptions] = $this->resolveFieldTypeAndOptions($definition);

            if (null !== $existingValues) {
                $rawValue = $this->resolveExistingValue($existingValues, $definition->getDisplayModuleKey(), $fieldName, $definition->getScope());
                $typeOptions['data'] = $this->caster->castFromDb($definition, $rawValue);
            }

            if ('' === $moduleFormPosition) {
                $targetBuilder = $this->resolveOrCreateFallbackPath($formBuilder);
                if (!$targetBuilder->has($formFieldName)) {
                    $targetBuilder->add($formFieldName, $type, $typeOptions);
                }
            } else {
                $this->addAtPosition($formBuilder, $moduleFormPosition, $formFieldName, $type, $typeOptions);
            }
        }
    }

    /**
     * @return array{0: class-string<FormTypeInterface>, 1: array<string, mixed>}
     */
    protected function resolveFieldTypeAndOptions(ExtraPropertyDefinition $definition): array
    {
        $declaredType = $definition->getFormFieldType();
        $validator = $definition->getValidator();
        $extraOptions = $definition->getFormOptions() ?? [];
        $constraints = [];

        $baseType = (null !== $declaredType && class_exists($declaredType)) ? $declaredType : TextType::class;

        // formRequired: true → automatically add NotBlank for real server-side enforcement.
        // The HTML required attribute alone is bypassed by AJAX form submissions in the BO.
        if ($definition->isFormRequired()) {
            $constraints[] = new Assert\NotBlank();
        }

        if (null !== $validator) {
            $message = $this->translator->trans('The field is invalid.', domain: 'Admin.Notifications.Error');
            $constraints[] = new Assert\Callback(
                function ($value, ExecutionContextInterface $context) use ($definition, $message): void {
                    if (true !== $this->validatorAdapter->validateValue($definition, $value)) {
                        $context->addViolation($message);
                    }
                }
            );
        }

        $label = $this->translateLabel($definition->getLabelWording(), $definition->getLabelDomain(), null);
        $help = $this->translateLabel($definition->getDescriptionWording(), $definition->getDescriptionDomain(), null);

        if (ExtraPropertyScope::LANG === $definition->getScope()) {
            // In BO, use TranslatableType (keys are id_lang) for lang-scoped fields.
            return [
                TranslatableType::class,
                [
                    'type' => $baseType,
                    'label' => $label,
                    'help' => $help,
                    'mapped' => false,
                    'required' => $definition->isFormRequired(),
                    'options' => array_merge($extraOptions, [
                        'required' => $definition->isFormRequired(),
                        'constraints' => $constraints,
                    ]),
                ],
            ];
        }

        return [
            $baseType,
            array_merge(
                [
                    'mapped' => false,
                    'required' => $definition->isFormRequired(),
                    'label' => $label,
                    'help' => $help,
                    'constraints' => $constraints,
                ],
                $extraOptions
            ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $existingValues
     *
     * @return mixed
     */
    protected function resolveExistingValue(array $existingValues, string $moduleName, string $fieldName, ExtraPropertyScope $scope): mixed
    {
        $value = $existingValues[$moduleName][$fieldName] ?? null;

        if (ExtraPropertyScope::LANG === $scope) {
            return is_array($value) ? $value : [];
        }

        return $value;
    }

    /**
     * Adds a field at the position described by $position (a dot-path with optional :before/:after suffix).
     *
     * - "a.b"        → navigate to builder a; add field AFTER b (default behaviour)
     * - "a.b:before" → navigate to builder at a, call FormBuilderModifier::addBefore(builder, 'b', field)
     * - "a.b:after"  → navigate to builder at a, call FormBuilderModifier::addAfter(builder, 'b', field)
     *
     * When no :before/:after suffix is given, the mode defaults to 'after'.
     *
     * @param class-string<FormTypeInterface> $type
     * @param array<string, mixed> $typeOptions
     *
     * @throws InvalidArgumentException when an intermediate path segment does not exist
     */
    protected function addAtPosition(
        FormBuilderInterface $rootBuilder,
        string $position,
        string $formFieldName,
        string $type,
        array $typeOptions,
    ): void {
        [$path, $mode] = $this->parsePosition($position);
        // Default mode is 'after' when not specified.
        $mode ??= 'after';

        // Split path into parent path + anchor field name.
        $lastDot = strrpos($path, '.');
        if (false === $lastDot) {
            $parentBuilder = $rootBuilder;
            $anchorField = $path;
        } else {
            $parentBuilder = $this->resolvePath($rootBuilder, substr($path, 0, $lastDot));
            $anchorField = substr($path, $lastDot + 1);
        }

        if ($parentBuilder->has($formFieldName)) {
            return;
        }

        if ('before' === $mode) {
            $this->formBuilderModifier->addBefore($parentBuilder, $anchorField, $formFieldName, $type, $typeOptions);
        } else {
            $this->formBuilderModifier->addAfter($parentBuilder, $anchorField, $formFieldName, $type, $typeOptions);
        }
    }

    /**
     * Parses a position string into a [path, mode] pair.
     *
     * Mode is 'before', 'after', or null (no suffix — caller should default to 'after').
     *
     * @return array{0: string, 1: 'before'|'after'|null}
     */
    protected function parsePosition(string $position): array
    {
        if (str_ends_with($position, ':before')) {
            return [substr($position, 0, -7), 'before'];
        }
        if (str_ends_with($position, ':after')) {
            return [substr($position, 0, -6), 'after'];
        }

        return [$position, null];
    }

    /**
     * Resolves (and creates if missing) the designated fallback area for extra fields.
     *
     * - Forms with tabs: resolves/creates 'extra_fields.extra_properties'.
     * - Simple forms (no tabs): returns the root builder (fields injected at root level).
     */
    protected function resolveOrCreateFallbackPath(FormBuilderInterface $rootBuilder): FormBuilderInterface
    {
        if (!$rootBuilder->has(self::DEFAULT_FALLBACK_TAB) && !$this->isNavigationTabForm($rootBuilder)) {
            return $rootBuilder;
        }

        if (!$rootBuilder->has(self::DEFAULT_FALLBACK_TAB)) {
            $rootBuilder->add(self::DEFAULT_FALLBACK_TAB, FormType::class, [
                'mapped' => false,
                'required' => false,
                'label' => $this->translator->trans('Extra fields', domain: 'Admin.Global'),
                'row_attr' => [],
            ]);
        }

        /** @var FormBuilderInterface $tabBuilder */
        $tabBuilder = $rootBuilder->get(self::DEFAULT_FALLBACK_TAB);

        if (!$tabBuilder->has(self::FALLBACK_FORM_SECTION)) {
            $tabBuilder->add(self::FALLBACK_FORM_SECTION, FormType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'row_attr' => [],
            ]);
        }

        /** @var FormBuilderInterface $sectionBuilder */
        $sectionBuilder = $tabBuilder->get(self::FALLBACK_FORM_SECTION);

        return $sectionBuilder;
    }

    /**
     * Strictly resolves a dot-separated path inside an existing form builder.
     *
     * @throws InvalidArgumentException when any segment of the path does not exist
     */
    protected function resolvePath(FormBuilderInterface $rootBuilder, string $path): FormBuilderInterface
    {
        $segments = array_values(array_filter(array_map('trim', explode('.', $path)), static fn (string $s): bool => '' !== $s));
        if (empty($segments)) {
            return $rootBuilder;
        }

        $builder = $rootBuilder;
        foreach ($segments as $segment) {
            if (!$builder->has($segment)) {
                throw new InvalidArgumentException(sprintf(
                    'Extra property associated_forms path "%s": intermediate segment "%s" does not exist in the form.',
                    $path,
                    $segment
                ));
            }
            /** @var FormBuilderInterface $builder */
            $builder = $builder->get($segment);
        }

        return $builder;
    }

    protected function isNavigationTabForm(FormBuilderInterface $formBuilder): bool
    {
        return $this->hasNavigationTabTypeInHierarchy($formBuilder->getType());
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
     * Translates a wording/domain pair from a definition, falling back to $default.
     */
    protected function translateLabel(?string $wording, ?string $domain, ?string $default): ?string
    {
        if (null === $wording || '' === trim($wording)) {
            return $default;
        }

        return $this->translator->trans($wording, [], $domain ?? 'Admin.Global');
    }
}
