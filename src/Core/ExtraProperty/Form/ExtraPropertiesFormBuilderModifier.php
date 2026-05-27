<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidationInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShopBundle\Form\Admin\Type\NavigationTabType;
use PrestaShopBundle\Form\Admin\Type\TranslatableType;
use PrestaShopBundle\Form\FormBuilderModifier;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
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
 * Placement (form_position from the definition registry):
 * - null/empty        => dedicated 'extra_fields.extra_properties' section (created if missing); on simple
 *                        forms without tabs, fields are placed at root level instead.
 * - dot-path          => all segments except the last must exist (throws InvalidArgumentException
 *                        on missing intermediate segment); the last segment is created automatically
 *                        as an unmapped FormType container if it does not exist yet.
 * - dot-path:before   => navigate to the parent builder and insert the field BEFORE the last segment
 *                        (e.g. "header.name:before" inserts before the 'name' field inside 'header').
 * - dot-path:after    => navigate to the parent builder and insert the field AFTER the last segment.
 *
 * Data mapping:
 * - fields are added as unmapped; persistence reads submitted values from the FormInterface.
 */
class ExtraPropertiesFormBuilderModifier
{
    public const FALLBACK_FORM_SECTION = 'extra_properties';
    private const DEFAULT_FALLBACK_TAB = 'extra_fields';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ExtraPropertyReaderInterface $reader,
        protected readonly TranslatorInterface $translator,
        protected readonly ExtraPropertyValidationInterface $validatorAdapter,
        protected readonly ShopContext $shopContext,
        protected readonly FormBuilderModifier $formBuilderModifier,
    ) {
    }

    /**
     * @param string $entityName Entity table name (usually equals form block prefix, e.g. 'product')
     * @param int|null $entityId When null, no prefill is attempted (create form)
     */
    public function apply(FormBuilderInterface $formBuilder, string $entityName, ?int $entityId): void
    {
        $definitions = $this->repository->getDefinitionCollection($entityName)->filterByForm();
        if ($definitions->isEmpty()) {
            return;
        }

        $existingValues = null;
        if (null !== $entityId && $entityId > 0) {
            $storageEntityName = $definitions->first()?->getEntityName() ?: $entityName;
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
            if ('' === $fieldName) {
                continue;
            }

            $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());
            $scope = $definition->getFieldScope();

            $moduleFormPosition = trim($definition->getFormPosition() ?? '');

            $formFieldName = ExtraPropertyNaming::formFieldName($moduleName, $fieldName, $scope);

            [$type, $typeOptions] = $this->resolveFieldTypeAndOptions($definition);

            $typeOptions['mapped'] = false;
            $typeOptions['required'] = $definition->isFormRequired();
            $typeOptions['label'] = $this->translateLabel(
                $definition->getLabelWording(),
                $definition->getLabelDomain(),
                null
            );
            $typeOptions['help'] = $this->translateLabel(
                $definition->getDescriptionWording(),
                $definition->getDescriptionDomain(),
                null
            );

            if (null !== $existingValues) {
                $rawValue = $this->resolveExistingValue($existingValues, $moduleName, $fieldName, $scope);
                $typeOptions['data'] = $this->normalizeExistingValueForType($definition, $type, $rawValue);
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
    protected function resolveFieldTypeAndOptions(ExtraPropertyDefinitionInfo $definition): array
    {
        $scope = $definition->getFieldScope();
        $declaredType = $definition->getFormFieldType();
        $validator = $definition->getValidator();
        $extraOptions = $definition->getFormOptions() ?? [];

        $baseType = (null !== $declaredType && class_exists($declaredType)) ? $declaredType : TextType::class;

        $fieldConstraint = null;
        if (null !== $validator) {
            $message = $this->translator->trans('The field is invalid.', domain: 'Admin.Notifications.Error');
            $fieldConstraint = new Assert\Callback(
                function ($value, ExecutionContextInterface $context) use ($definition, $message): void {
                    if ($value instanceof DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i:s');
                    }

                    if (true !== $this->validatorAdapter->validateValue($definition, $value)) {
                        $context->addViolation($message);
                    }
                }
            );
        }

        if ('lang' === $scope) {
            // In BO, use TranslatableType (keys are id_lang) for lang-scoped fields.
            // $extraOptions are merged into the inner type options so they are forwarded to each language widget.
            return [
                TranslatableType::class,
                [
                    'type' => $baseType,
                    'options' => array_merge($extraOptions, [
                        'required' => $definition->isFormRequired(),
                        'constraints' => null !== $fieldConstraint ? [$fieldConstraint] : [],
                    ]),
                ],
            ];
        }

        // $extraOptions are merged last so developer-supplied values can override defaults.
        return [
            $baseType,
            array_merge(
                ['constraints' => null !== $fieldConstraint ? [$fieldConstraint] : []],
                $extraOptions
            ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $existingValues
     *
     * @return mixed
     */
    protected function resolveExistingValue(array $existingValues, string $moduleName, string $fieldName, string $scope)
    {
        $value = $existingValues[$moduleName][$fieldName] ?? null;

        if ('lang' === $scope) {
            return is_array($value) ? $value : [];
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function normalizeExistingValueForType(ExtraPropertyDefinitionInfo $definition, string $resolvedType, $value)
    {
        $scope = $definition->getFieldScope();
        $declaredType = $definition->getFormFieldType();

        if ('lang' === $scope) {
            // TranslatableType expects an array keyed by id_lang
            if (!is_array($value)) {
                $value = [];
            }
            if (CheckboxType::class === $declaredType) {
                foreach ($value as $idLang => $langVal) {
                    $value[$idLang] = (bool) (int) $langVal;
                }
            } elseif (DateTimeType::class === $declaredType) {
                foreach ($value as $idLang => $langVal) {
                    $value[$idLang] = $this->toDateTimeOrNull($langVal);
                }
            }

            return $value;
        }

        // Non-lang (scalar) fields
        if (CheckboxType::class === $resolvedType || CheckboxType::class === $declaredType) {
            return null === $value ? false : (bool) (int) $value;
        }

        if (DateTimeType::class === $resolvedType || DateTimeType::class === $declaredType) {
            return $this->toDateTimeOrNull($value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    protected function toDateTimeOrNull($value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Adds a field at the position described by $position (a dot-path with optional :before/:after suffix).
     *
     * - "a.b"        → navigate strictly to builder a; auto-create b if missing; add field inside b
     * - "a.b:before" → navigate to builder at a, call FormBuilderModifier::addBefore(builder, 'b', field)
     * - "a.b:after"  → navigate to builder at a, call FormBuilderModifier::addAfter(builder, 'b', field)
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

        if (null === $mode) {
            // Plain path: navigate to the parent strictly, auto-create the leaf if needed.
            $lastDot = strrpos($path, '.');
            if (false === $lastDot) {
                $parentBuilder = $rootBuilder;
                $leafSegment = $path;
            } else {
                $parentBuilder = $this->resolvePath($rootBuilder, substr($path, 0, $lastDot));
                $leafSegment = substr($path, $lastDot + 1);
            }

            if (!$parentBuilder->has($leafSegment)) {
                $parentBuilder->add($leafSegment, FormType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => false,
                    'row_attr' => [],
                ]);
            }

            /** @var FormBuilderInterface $targetBuilder */
            $targetBuilder = $parentBuilder->get($leafSegment);
            if (!$targetBuilder->has($formFieldName)) {
                $targetBuilder->add($formFieldName, $type, $typeOptions);
            }

            return;
        }

        // Relative mode: split path into parent path + anchor field name.
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
     * Mode is 'before', 'after', or null (plain path).
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
     * Used to navigate to a parent builder; all segments must already exist.
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
                    'Extra property form_position "%s": intermediate segment "%s" does not exist in the form.',
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
