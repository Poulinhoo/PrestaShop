<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Repository;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;

/**
 * Reads and writes extra property definitions in the extra_property_definition registry table.
 *
 * This implementation does not add any caching; wrap with Definition\CachedExtraPropertyDefinitionRepository
 * for production use.
 *
 * All public read methods return typed ExtraPropertyDefinitionInfo value objects or collections.
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
        return new ExtraPropertyDefinitionCollection($this->getByEntityName($entityName));
    }

    /**
     * {@inheritdoc}
     *
     * Uses JSON_SEARCH to find entries matching "gridId" or "gridId.*" across all entities,
     * so a definition registered under entity_name='product' is correctly found when querying
     * for grid_id='product' (the real grid ID), even if the module used a column-qualified
     * entry such as 'product.reference:after'.
     */
    public function getDefinitionCollectionByGridId(string $gridId): ExtraPropertyDefinitionCollection
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('eef.*')
            ->from($table, 'eef')
            ->where('eef.associated_grids IS NOT NULL')
            ->andWhere(
                $qb->expr()->or(
                    'JSON_SEARCH(eef.associated_grids, \'one\', :exactGridId) IS NOT NULL',
                    'JSON_SEARCH(eef.associated_grids, \'one\', :prefixGridId) IS NOT NULL'
                )
            )

            ->setParameter('exactGridId', $gridId)
            ->setParameter('prefixGridId', $gridId . '.%')
            ->orderBy('eef.id_extra_property_definition', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative() ?: [];

        return new ExtraPropertyDefinitionCollection(array_values(array_map(
            static fn (array $row): ExtraPropertyDefinitionInfo => ExtraPropertyDefinitionInfo::fromRow($row),
            $rows
        )));
    }

    /**
     * {@inheritdoc}
     */
    public function findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinitionInfo
    {
        $normalizedModule = (null === $moduleName || '' === $moduleName) ? null : $moduleName;

        foreach ($this->getByEntityName($entityName) as $definition) {
            if (
                $definition->getModuleName() === $normalizedModule
                && $definition->getPropertyName() === $fieldName
                && $definition->getFieldScope() === $fieldScope
            ) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
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
            'associated_grids' => !empty($options->associatedGrids) ? json_encode(array_values($options->associatedGrids)) : null,
            'display_front' => (int) $options->displayFront,
            'label_wording' => $options->labelWording,
            'label_domain' => $options->labelDomain,
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
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        $table = $this->prefix . 'extra_property_definition';

        return (bool) $this->connection->delete($table, ['id_extra_property_definition' => $id]);
    }

    /**
     * Returns all definitions for an entity across all scopes.
     *
     * Validates the entity name; returns an empty array when invalid.
     *
     * @param string $entityName Entity table name (e.g. 'product')
     *
     * @return list<ExtraPropertyDefinitionInfo>
     */
    protected function getByEntityName(string $entityName): array
    {
        if ('' === $entityName || !preg_match('/^[a-zA-Z0-9_-]+$/', $entityName)) {
            return [];
        }

        $registryTable = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('eef.*')
            ->from($registryTable, 'eef')
            ->where('eef.entity_name = :entityName')
            ->setParameter('entityName', $entityName)
            ->orderBy('eef.id_extra_property_definition', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative() ?: [];

        return array_values(array_map(
            static fn (array $row): ExtraPropertyDefinitionInfo => ExtraPropertyDefinitionInfo::fromRow($row),
            $rows
        ));
    }
}
