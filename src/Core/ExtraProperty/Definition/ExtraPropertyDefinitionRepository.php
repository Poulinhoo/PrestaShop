<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use Doctrine\DBAL\Connection;

/**
 * Reads and writes extra property definitions in the extra_property_definition registry table.
 *
 * This implementation does not add any caching; wrap with Definition\CachedExtraPropertyDefinitionRepository
 * for production use.
 *
 * All public read methods return typed ExtraPropertyDefinition value objects or collections.
 */
class ExtraPropertyDefinitionRepository implements ExtraPropertyDefinitionRepositoryInterface, ExtraPropertyDefinitionWriterInterface
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getAllDefinitions(): ExtraPropertyDefinitionCollection
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('eef.*')
            ->from($table, 'eef')
            ->orderBy('eef.id_extra_property_definition', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative() ?: [];

        return new ExtraPropertyDefinitionCollection(array_values(array_map(
            static fn (array $row): ExtraPropertyDefinition => ExtraPropertyDefinition::fromRow($row),
            $rows
        )));
    }

    /**
     * {@inheritdoc}
     */
    public function findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinition
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('eef.*')
            ->from($table, 'eef')
            ->where('eef.entity_name = :entityName')
            ->andWhere('eef.property_name = :fieldName')
            ->andWhere('eef.scope = :fieldScope')
            ->setParameter('entityName', $entityName)
            ->setParameter('fieldName', $fieldName)
            ->setParameter('fieldScope', $fieldScope);

        if (null !== $moduleName && '' !== $moduleName) {
            $qb->andWhere('eef.module_name = :moduleName')->setParameter('moduleName', $moduleName);
        } else {
            $qb->andWhere('eef.module_name IS NULL');
        }

        $row = $qb->executeQuery()->fetchAssociative();

        return is_array($row) ? ExtraPropertyDefinition::fromRow($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        ExtraPropertyDefinition $options,
        string $entityName,
        string $propertyName,
        ?string $normalizedModuleName,
        string $normalizedScope,
    ): int|false {
        $table = $this->prefix . 'extra_property_definition';

        $data = [
            'module_name' => $normalizedModuleName,
            'scope' => $normalizedScope,
            'type' => $options->getType()->value,
            'size' => $options->getSize(),
            'form_required' => (int) $options->isFormRequired(),
            'default_value' => null !== $options->getDefaultValue() ? (string) $options->getDefaultValue() : null,
            'form_field_type' => $options->getFormFieldType(),
            'form_options' => null !== $options->getFormOptions() ? json_encode($options->getFormOptions()) : null,
            'sql_index' => $options->getSqlIndex()->value,
            'validator' => $options->getValidator(),
            'display_api' => (int) $options->isDisplayApi(),
            'associated_forms' => !empty($options->getAssociatedForms()) ? json_encode(array_values($options->getAssociatedForms())) : null,
            'associated_grids' => !empty($options->getAssociatedGrids()) ? json_encode(array_values($options->getAssociatedGrids())) : null,
            'display_front' => (int) $options->isDisplayFront(),
            'label_wording' => $options->getLabelWording(),
            'label_domain' => $options->getLabelDomain(),
            'description_wording' => $options->getDescriptionWording(),
            'description_domain' => $options->getDescriptionDomain(),
        ];

        // Resolve existing row ID from the unique key to decide INSERT vs UPDATE.
        $existingId = $this->findIdByUniqueKey($entityName, $normalizedModuleName, $propertyName, $normalizedScope);

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
     * {@inheritdoc}
     */
    public function deleteByDefinition(ExtraPropertyDefinition $definition): bool
    {
        $table = $this->prefix . 'extra_property_definition';

        $criteria = [
            'entity_name' => $definition->getEntityName(),
            'property_name' => $definition->getPropertyName(),
            'scope' => $definition->getScope()->value,
        ];
        if (null !== $definition->getModuleName()) {
            $criteria['module_name'] = $definition->getModuleName();
        } else {
            // Core fields have NULL module_name — DBAL delete() cannot match NULL with =, use raw SQL.
            $qb = $this->connection->createQueryBuilder();
            $qb->delete($table)
                ->where('entity_name = :entityName')
                ->andWhere('property_name = :propertyName')
                ->andWhere('scope = :scope')
                ->andWhere('module_name IS NULL')
                ->setParameter('entityName', $criteria['entity_name'])
                ->setParameter('propertyName', $criteria['property_name'])
                ->setParameter('scope', $criteria['scope']);

            return (bool) $qb->executeStatement();
        }

        return (bool) $this->connection->delete($table, $criteria);
    }

    /**
     * Looks up the primary key for a definition identified by its unique key.
     *
     * Returns null when no matching row exists.
     */
    protected function findIdByUniqueKey(string $entityName, ?string $moduleName, string $propertyName, string $scope): ?int
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb->select('id_extra_property_definition')
            ->from($table)
            ->where('entity_name = :entityName')
            ->andWhere('property_name = :propertyName')
            ->andWhere('scope = :scope')
            ->setParameter('entityName', $entityName)
            ->setParameter('propertyName', $propertyName)
            ->setParameter('scope', $scope);

        if (null !== $moduleName) {
            $qb->andWhere('module_name = :moduleName')->setParameter('moduleName', $moduleName);
        } else {
            $qb->andWhere('module_name IS NULL');
        }

        $id = $qb->executeQuery()->fetchOne();

        return false !== $id && null !== $id ? (int) $id : null;
    }
}
