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
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShopBundle\Form\Admin\Type\NavigationTabType;
use PrestaShopBundle\Form\Admin\Type\TranslatableType;
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
use Validate;

/**
 * Adds extra properties fields into an identifiable object form builder.
 *
 * Placement:
 * - if registry form_position is empty => under a fallback container 'extra_properties'
 * - else => within the existing sub-form path when possible (dot-separated), otherwise created as nested FormType nodes.
 *
 * Data mapping:
 * - fields are added as unmapped; persistence reads submitted values from the FormInterface.
 */
class ExtraPropertiesFormBuilderModifier
{
    public const FALLBACK_FORM_SECTION = 'extra_properties';
    private const DEFAULT_FALLBACK_TAB = 'extra_fields';

    public function __construct(
        protected readonly ExtraPropertiesFormDefinitionProvider $definitionProvider,
        protected readonly ExtraPropertiesFormDataLoader $dataLoader,
        protected readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string $entityName Entity table name (usually equals form block prefix, e.g. 'product')
     * @param int|null $entityId When null, no prefill is attempted (create form)
     * @param int $shopId Current shop context ID (lang/shop scopes)
     */
    public function apply(FormBuilderInterface $formBuilder, string $entityName, ?int $entityId, int $shopId): void
    {
        $definitions = $this->definitionProvider->getDefinitionsForEntity($entityName);
        if ($definitions->isEmpty()) {
            return;
        }

        $existingValues = null;
        if (null !== $entityId && $entityId > 0) {
            $existingValues = $this->dataLoader->load($entityName, $entityId, $shopId, $definitions);
        }

        foreach ($definitions as $definition) {
            $fieldName = $definition->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());
            $scope = $definition->getFieldScope();

            $targetPath = trim($definition->getFormPosition() ?? '');
            if ('' === $targetPath) {
                // On forms with tabs (e.g. product), group all extra fields in one dedicated sub-section.
                // On simple forms (e.g. category), place fields at root level.
                $targetPath = ($formBuilder->has(self::DEFAULT_FALLBACK_TAB) || $this->isNavigationTabForm($formBuilder))
                    ? self::DEFAULT_FALLBACK_TAB . '.' . self::FALLBACK_FORM_SECTION
                    : '';
            }

            $targetBuilder = $this->resolveOrCreatePath($formBuilder, $targetPath);

            $formFieldName = ExtraPropertyNaming::formFieldName($moduleName, $fieldName, $scope);
            if ($targetBuilder->has($formFieldName)) {
                continue;
            }

            [$type, $typeOptions] = $this->resolveFieldTypeAndOptions($definition);

            $typeOptions['mapped'] = false;
            $typeOptions['required'] = $definition->isFormRequired();
            $defaultLabel = ucfirst(str_replace('_', ' ', $fieldName));
            $typeOptions['label'] = $this->translateLabel(
                $definition->getTitleWording(),
                $definition->getTitleDomain(),
                $defaultLabel
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

            $targetBuilder->add($formFieldName, $type, $typeOptions);
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
        if (null !== $validator && '' !== $validator && method_exists(Validate::class, $validator)) {
            $message = $this->translator->trans('The field is invalid.', domain: 'Admin.Notifications.Error');
            // Keep same behavior as in ObjectModel::validateField() for those two validators
            $isEmptyValidationMethod = 'isrequiredwhenactive' === strtolower($validator)
                || 'defaultlanguagerequiredwhenactive' === strtolower($validator);

            $fieldConstraint = new Assert\Callback(
                function ($value, ExecutionContextInterface $context) use ($validator, $message, $isEmptyValidationMethod): void {
                    if (null === $value || '' === $value) {
                        if (!$isEmptyValidationMethod) {
                            return;
                        }
                    }

                    if ($value instanceof DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i:s');
                    }

                    if (!call_user_func([Validate::class, $validator], $value)) {
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

    protected function resolveOrCreatePath(FormBuilderInterface $rootBuilder, string $path): FormBuilderInterface
    {
        $segments = array_values(array_filter(array_map('trim', explode('.', $path)), static fn (string $s): bool => '' !== $s));
        if (empty($segments)) {
            return $rootBuilder;
        }

        $builder = $rootBuilder;
        foreach ($segments as $i => $segment) {
            if ($builder->has($segment)) {
                $child = $builder->get($segment);
                if ($child instanceof FormBuilderInterface) {
                    $builder = $child;
                    continue;
                }
            }

            $isRoot = 0 === $i;
            $isExtraFieldsTab = $isRoot && self::DEFAULT_FALLBACK_TAB === $segment;

            // Create a container node when missing.
            $builder->add($segment, FormType::class, [
                'mapped' => false,
                'required' => false,
                'label' => $isExtraFieldsTab
                    ? $this->translator->trans('Extra fields', domain: 'Admin.Global')
                    : false,
                'row_attr' => [],
            ]);
            /** @var FormBuilderInterface $newChild */
            $newChild = $builder->get($segment);
            $builder = $newChild;
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
