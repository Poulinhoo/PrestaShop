<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Repository;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use Validate;

/**
 * Reads and writes extra property definitions in the extra_property_definition registry table.
 *
 * This implementation does not add any caching; wrap with CachedExtraPropertyDefinitionRepository
 * for production use. All entity/scope validation is centralized in normalizeEntityNameAndFieldScope().
 *
 * All public read methods return typed ExtraPropertyDefinitionInfo value objects.
 */
class ExtraPropertyDefinitionRepository implements ExtraPropertyDefinitionRepositoryInterface, ExtraPropertyDefinitionWriterInterface
{
    public const FIELD_SCOPE_COMMON = 'common';
    public const FIELD_SCOPE_LANG = 'lang';
    public const FIELD_SCOPE_SHOP = 'shop';

    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinitionCollection(string $entityName): ExtraPropertyDefinitionCollection
    {
        return new ExtraPropertyDefinitionCollection($this->getByEntityNameAllScopes($entityName));
    }

    /**
     * {@inheritdoc}
     */
    public function getByEntityNameAllScopes(string $entityName): array
    {
        [$normalizedEntityName] = $this->normalizeEntityNameAndFieldScope($entityName, self::FIELD_SCOPE_COMMON);
        if (null === $normalizedEntityName) {
            return [];
        }

        $registryTable = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select([
                'eef.id_extra_property_definition',
                'eef.entity_name',
                'eef.scope',
                'eef.module_name',
                'eef.property_name',
                'eef.type',
                'eef.size',
                'eef.form_required',
                'eef.default_value',
                'eef.form_field_type',
                'eef.form_options',
                'eef.form_position',
                'eef.sql_index',
                'eef.validator',
                'eef.display_api',
                'eef.display_form',
                'eef.display_grid',
                'eef.grid_position',
                'eef.title_wording',
                'eef.title_domain',
                'eef.description_wording',
                'eef.description_domain',
            ])
            ->from($registryTable, 'eef')
            ->where('eef.entity_name = :entityName')
            ->setParameter('entityName', $normalizedEntityName)
            ->orderBy('eef.id_extra_property_definition', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative() ?: [];

        return array_values(array_map(
            static fn (array $row): ExtraPropertyDefinitionInfo => ExtraPropertyDefinitionInfo::fromRow($row),
            $rows
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getByEntityName(string $entityName, string $fieldScope = self::FIELD_SCOPE_COMMON): array
    {
        [$normalizedEntityName, $normalizedFieldScope] = $this->normalizeEntityNameAndFieldScope($entityName, $fieldScope);
        if (null === $normalizedEntityName || null === $normalizedFieldScope) {
            return [];
        }

        return array_values(array_filter(
            $this->getByEntityNameAllScopes($normalizedEntityName),
            static fn (ExtraPropertyDefinitionInfo $d): bool => $d->getFieldScope() === $normalizedFieldScope
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getByEntityAndPropertyName(string $entityName, string $propertyName, string $fieldScope = self::FIELD_SCOPE_COMMON): ?ExtraPropertyDefinitionInfo
    {
        if (!Validate::isTableOrIdentifier($propertyName)) {
            return null;
        }
        [$normalizedEntityName, $normalizedFieldScope] = $this->normalizeEntityNameAndFieldScope($entityName, $fieldScope);
        if (null === $normalizedEntityName || null === $normalizedFieldScope) {
            return null;
        }

        foreach ($this->getByEntityNameAllScopes($normalizedEntityName) as $definition) {
            if (
                $definition->getPropertyName() === $propertyName
                && $definition->getFieldScope() === $normalizedFieldScope
            ) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasExtraProperties(string $entityName): bool
    {
        return !empty($this->getByEntityNameAllScopes($entityName));
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinitionById(int $id): ?ExtraPropertyDefinitionInfo
    {
        $row = $this->findById($id);

        return null !== $row ? ExtraPropertyDefinitionInfo::fromRow($row) : null;
    }

    /**
     * Finds one registry definition matching (entity_name, module_name, property_name, scope).
     * Uses the all-scopes retrieval internally.
     *
     * @param string $entityName normalized entity name
     * @param string|null $moduleName module technical name, or null for core fields
     * @param string $fieldName
     * @param string $fieldScope
     *
     * @return ExtraPropertyDefinitionInfo|null
     */
    public function findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinitionInfo
    {
        // Normalize to null for core fields (module_name IS NULL in DB).
        $normalizedModule = (null === $moduleName || '' === $moduleName) ? null : $moduleName;

        foreach ($this->getByEntityNameAllScopes($entityName) as $definition) {
            $defModule = $definition->getModuleName();

            if (
                $defModule === $normalizedModule
                && $definition->getPropertyName() === $fieldName
                && $definition->getFieldScope() === $fieldScope
            ) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Loads one raw definition row directly by primary key (bypasses internal all-scopes cache).
     * Returns the raw array row, or null when not found.
     *
     * @param int $id
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('*')
            ->from($this->prefix . 'extra_property_definition', 'eef')
            ->where('eef.id_extra_property_definition = :id')
            ->setParameter('id', $id);

        $row = $qb->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Saves (insert or update) one definition row from typed parameters.
     *
     * @param ExtraPropertyOptions $options Typed options as declared by the module
     * @param string $entityName Normalized entity name (e.g. 'product')
     * @param string $propertyName Property name as declared (e.g. 'is_dangerous')
     * @param string|null $normalizedModuleName Module name (null for core fields)
     * @param string $normalizedScope Normalized scope value ('common', 'lang', 'shop')
     * @param int|null $existingId When provided, performs an UPDATE; otherwise INSERT
     *
     * @return int|false Returns the id on success, false on failure
     */
    public function save(
        ExtraPropertyOptions $options,
        string $entityName,
        string $propertyName,
        ?string $normalizedModuleName,
        string $normalizedScope,
        ?int $existingId = null
    ): int|false {
        $table = $this->prefix . 'extra_property_definition';

        $data = [
            'module_name' => $normalizedModuleName,
            'scope' => $normalizedScope,
            'type' => $options->type->value,
            'size' => $options->size,
            'form_required' => (int) $options->formRequired,
            'default_value' => null !== $options->defaultValue ? (string) $options->defaultValue : null,
            'form_field_type' => $options->formFieldType,
            'form_options' => null !== $options->formOptions ? json_encode($options->formOptions) : null,
            'form_position' => $options->formPosition,
            'sql_index' => $options->sqlIndex->value,
            'validator' => $options->validator,
            'display_api' => (int) $options->displayApi,
            'display_form' => (int) $options->displayForm,
            'display_grid' => (int) $options->displayGrid,
            'grid_position' => null !== $options->gridPosition ? (string) $options->gridPosition : null,
            'title_wording' => $options->titleWording,
            'title_domain' => $options->titleDomain,
            'description_wording' => $options->descriptionWording,
            'description_domain' => $options->descriptionDomain,
        ];

        if (null !== $existingId) {
            $saved = (bool) $this->connection->update($table, $data, ['id_extra_property_definition' => $existingId]);

            return $saved ? $existingId : false;
        }

        $data['entity_name'] = $entityName;
        $data['property_name'] = $propertyName;

        $saved = (bool) $this->connection->insert($table, $data);
        if (!$saved) {
            return false;
        }

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Deletes one definition row by primary key.
     *
     * @param int $id
     *
     * @return bool
     */
    public function delete(int $id): bool
    {
        $table = $this->prefix . 'extra_property_definition';

        return (bool) $this->connection->delete($table, ['id_extra_property_definition' => $id]);
    }

    /**
     * Normalizes a legacy entity name suffix (product_lang → entity=product, scope=lang).
     *
     * @param string $entityName
     * @param string $fieldScope
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function normalizeEntityNameAndFieldScope(string $entityName, string $fieldScope): array
    {
        $normalizedScope = strtolower(trim($fieldScope));
        $normalizedEntityName = $entityName;

        if (str_ends_with($normalizedEntityName, '_lang')) {
            $normalizedEntityName = substr($normalizedEntityName, 0, -5);
            if (self::FIELD_SCOPE_COMMON === $normalizedScope) {
                $normalizedScope = self::FIELD_SCOPE_LANG;
            } elseif (self::FIELD_SCOPE_LANG !== $normalizedScope) {
                return [null, null];
            }
        } elseif (str_ends_with($normalizedEntityName, '_shop')) {
            $normalizedEntityName = substr($normalizedEntityName, 0, -5);
            if (self::FIELD_SCOPE_COMMON === $normalizedScope) {
                $normalizedScope = self::FIELD_SCOPE_SHOP;
            } elseif (self::FIELD_SCOPE_SHOP !== $normalizedScope) {
                return [null, null];
            }
        }

        if (!in_array($normalizedScope, ExtraPropertyScope::values(), true)) {
            return [null, null];
        }
        if ('' === $normalizedEntityName || !Validate::isTableOrIdentifier($normalizedEntityName)) {
            return [null, null];
        }

        return [$normalizedEntityName, $normalizedScope];
    }
}
