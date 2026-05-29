<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\ExtraProperty\QueryHandler;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsQueryHandler;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\ExtraPropertyDefinitionNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Query\GetExtraPropertyDefinitionForEditing;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryHandler\GetExtraPropertyDefinitionForEditingHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\EditableExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\ValueObject\ExtraPropertyDefinitionId;

/**
 * Loads all fields of one extra property definition for the BO edit form.
 *
 * Queries the DB directly (bypassing the cached repository) to guarantee
 * fresh data immediately after a write.
 */
#[AsQueryHandler]
final class GetExtraPropertyDefinitionForEditingHandler implements GetExtraPropertyDefinitionForEditingHandlerInterface
{
    /**
     * @param Connection $connection
     * @param string $prefix
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @throws ExtraPropertyDefinitionNotFoundException
     */
    public function handle(GetExtraPropertyDefinitionForEditing $query): EditableExtraPropertyDefinition
    {
        $id = $query->getId()->getValue();
        $table = $this->prefix . 'extra_property_definition';

        $row = $this->connection->createQueryBuilder()
            ->select('eef.*')
            ->from($table, 'eef')
            ->where('eef.id_extra_property_definition = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            throw new ExtraPropertyDefinitionNotFoundException(
                sprintf('Extra property definition with id %d was not found.', $id)
            );
        }

        $formOptionsRaw = isset($row['form_options']) && '' !== $row['form_options'] ? $row['form_options'] : null;
        $associatedFormsRaw = isset($row['associated_forms']) && '' !== $row['associated_forms'] ? $row['associated_forms'] : null;
        $associatedGridsRaw = isset($row['associated_grids']) && '' !== $row['associated_grids'] ? $row['associated_grids'] : null;

        return new EditableExtraPropertyDefinition(
            id: new ExtraPropertyDefinitionId($id),
            entityName: (string) $row['entity_name'],
            moduleName: isset($row['module_name']) && '' !== $row['module_name'] ? (string) $row['module_name'] : null,
            propertyName: (string) $row['property_name'],
            fieldType: (string) $row['type'],
            fieldScope: (string) $row['scope'],
            sqlIndex: (string) ($row['sql_index'] ?? 'none'),
            size: isset($row['size']) && '' !== $row['size'] ? (int) $row['size'] : null,
            defaultValue: isset($row['default_value']) && '' !== $row['default_value'] ? (string) $row['default_value'] : null,
            displayApi: !empty($row['display_api']),
            displayFront: !empty($row['display_front']),
            formRequired: !empty($row['form_required']),
            labelWording: isset($row['label_wording']) && '' !== $row['label_wording'] ? (string) $row['label_wording'] : null,
            labelDomain: isset($row['label_domain']) && '' !== $row['label_domain'] ? (string) $row['label_domain'] : null,
            descriptionWording: isset($row['description_wording']) && '' !== $row['description_wording'] ? (string) $row['description_wording'] : null,
            descriptionDomain: isset($row['description_domain']) && '' !== $row['description_domain'] ? (string) $row['description_domain'] : null,
            validator: isset($row['validator']) && '' !== $row['validator'] ? (string) $row['validator'] : null,
            formFieldType: isset($row['form_field_type']) && '' !== $row['form_field_type'] ? (string) $row['form_field_type'] : null,
            formOptions: $formOptionsRaw,
            associatedForms: $associatedFormsRaw,
            associatedGrids: $associatedGridsRaw,
        );
    }
}
