<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\Form\Admin\Configure\AdvancedParameters\ExtraPropertyDefinition;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertySqlIndex;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form type for creating and editing an extra property definition.
 *
 * Structural fields (entity_name, property_name, type, scope, sql_index, size, default_value)
 * are always included but rendered as disabled attributes in edit mode so they display
 * their values without allowing modification. Only label, display, and advanced metadata
 * fields are editable when is_edit is true.
 */
class ExtraPropertyDefinitionType extends TranslatorAwareType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $disabledAttr = $isEdit ? ['disabled' => true] : [];

        // ── Structural fields — creation only (disabled in edit mode) ──────────────

        $builder
            ->add('entity_name', TextType::class, [
                'label' => $this->trans('Entity name', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('ObjectModel table name (e.g. product, customer, order).', 'Admin.Advparameters.Help'),
                'required' => !$isEdit,
                'disabled' => $isEdit,
                'attr' => $disabledAttr,
                'constraints' => $isEdit ? [] : [new NotBlank()],
            ])
            ->add('property_name', TextType::class, [
                'label' => $this->trans('Property name', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Identifier used as the column name (e.g. internal_code). Only letters, digits, and underscores.', 'Admin.Advparameters.Help'),
                'required' => !$isEdit,
                'disabled' => $isEdit,
                'attr' => $disabledAttr,
                'constraints' => $isEdit ? [] : [new NotBlank()],
            ])
            ->add('type', ChoiceType::class, [
                'label' => $this->trans('Field type', 'Admin.Advparameters.Feature'),
                'choices' => $this->buildTypeChoices(),
                'required' => !$isEdit,
                'disabled' => $isEdit,
                'attr' => $disabledAttr,
            ])
            ->add('scope', ChoiceType::class, [
                'label' => $this->trans('Scope', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('common: one value per entity. lang: one value per entity × language. shop: one value per entity × shop.', 'Admin.Advparameters.Help'),
                'choices' => $this->buildScopeChoices(),
                'required' => !$isEdit,
                'disabled' => $isEdit,
                'attr' => $disabledAttr,
            ])
            ->add('sql_index', ChoiceType::class, [
                'label' => $this->trans('SQL index', 'Admin.Advparameters.Feature'),
                'choices' => $this->buildSqlIndexChoices(),
                'required' => !$isEdit,
                'disabled' => $isEdit,
                'attr' => $disabledAttr,
            ])
            ->add('size', IntegerType::class, [
                'label' => $this->trans('Size', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('VARCHAR size for string type only (defaults to 255). Ignored for other types.', 'Admin.Advparameters.Help'),
                'required' => false,
                'disabled' => $isEdit,
                'attr' => array_merge($disabledAttr, ['min' => 1, 'max' => 16383]),
            ])
            ->add('default_value', TextType::class, [
                'label' => $this->trans('Default value', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('SQL DEFAULT clause value. Leave empty for no default.', 'Admin.Advparameters.Help'),
                'required' => false,
                'disabled' => $isEdit,
                'attr' => $disabledAttr,
            ]);

        // ── Display flags ──────────────────────────────────────────────────────────

        $builder
            ->add('display_front', CheckboxType::class, [
                'label' => $this->trans('Display in front-office', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Expose this field in front-office presenters (Smarty templates).', 'Admin.Advparameters.Help'),
                'required' => false,
            ])
            ->add('display_api', CheckboxType::class, [
                'label' => $this->trans('Display in API', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Include this field in Admin API endpoints.', 'Admin.Advparameters.Help'),
                'required' => false,
            ])
            ->add('form_required', CheckboxType::class, [
                'label' => $this->trans('Required in form', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Mark the BO form field as required.', 'Admin.Advparameters.Help'),
                'required' => false,
            ]);

        // ── Label / description i18n ───────────────────────────────────────────────

        $builder
            ->add('label_wording', TextType::class, [
                'label' => $this->trans('Label wording', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Translation wording shown as label in BO forms and grids (e.g. "Internal code").', 'Admin.Advparameters.Help'),
                'required' => false,
            ])
            ->add('label_domain', TextType::class, [
                'label' => $this->trans('Label domain', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Symfony translation domain for the label wording (e.g. "Admin.Catalog.Feature").', 'Admin.Advparameters.Help'),
                'required' => false,
            ])
            ->add('description_wording', TextType::class, [
                'label' => $this->trans('Description wording', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Translation wording shown as help text below the BO form field.', 'Admin.Advparameters.Help'),
                'required' => false,
            ])
            ->add('description_domain', TextType::class, [
                'label' => $this->trans('Description domain', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Symfony translation domain for the description wording.', 'Admin.Advparameters.Help'),
                'required' => false,
            ]);

        // ── Validation ─────────────────────────────────────────────────────────────

        $builder
            ->add('validator', TextType::class, [
                'label' => $this->trans('Validator', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('PrestaShop Validate:: method name applied before persistence (e.g. isUrl, isEmail, isAlphaNumeric). Leave empty to skip validation.', 'Admin.Advparameters.Help'),
                'required' => false,
            ]);

        // ── Advanced form integration ──────────────────────────────────────────────

        $builder
            ->add('form_field_type', TextType::class, [
                'label' => $this->trans('Symfony form type', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Fully-qualified Symfony form type class name to override the default type mapping (e.g. Symfony\Component\Form\Extension\Core\Type\UrlType).', 'Admin.Advparameters.Help'),
                'required' => false,
            ])
            ->add('form_options', TextareaType::class, [
                'label' => $this->trans('Form options (JSON)', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('Extra options merged into the Symfony form type constructor, as a JSON object. Example: {"attr": {"class": "my-class"}, "row_attr": {"data-toggle": "tooltip"}}', 'Admin.Advparameters.Help'),
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('associated_forms', TextareaType::class, [
                'label' => $this->trans('Associated forms (JSON)', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('JSON array of form placement entries. Each entry: "formId", "formId.path", "formId.path:before", or "formId.path:after". Example: ["category.options.extra_properties"].', 'Admin.Advparameters.Help'),
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('associated_grids', TextareaType::class, [
                'label' => $this->trans('Associated grids (JSON)', 'Admin.Advparameters.Feature'),
                'help' => $this->trans('JSON array of grid placement entries. Each entry: "gridId", "gridId.columnId", "gridId.columnId:before", or "gridId.columnId:after". Example: ["product.reference:after"].', 'Admin.Advparameters.Help'),
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'is_edit' => false,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }

    /**
     * Builds the choices for the type field from ExtraPropertyType enum values.
     *
     * @return array<string, string>
     */
    protected function buildTypeChoices(): array
    {
        $choices = [];
        foreach (ExtraPropertyType::cases() as $case) {
            $choices[$case->value] = $case->value;
        }

        return $choices;
    }

    /**
     * Builds the choices for the scope field from ExtraPropertyScope enum values.
     *
     * @return array<string, string>
     */
    protected function buildScopeChoices(): array
    {
        $choices = [];
        foreach (ExtraPropertyScope::cases() as $case) {
            $choices[$case->value] = $case->value;
        }

        return $choices;
    }

    /**
     * Builds the choices for the sql_index field from ExtraPropertySqlIndex enum values.
     *
     * @return array<string, string>
     */
    protected function buildSqlIndexChoices(): array
    {
        $choices = [];
        foreach (ExtraPropertySqlIndex::cases() as $case) {
            $choices[$case->value] = $case->value;
        }

        return $choices;
    }
}
