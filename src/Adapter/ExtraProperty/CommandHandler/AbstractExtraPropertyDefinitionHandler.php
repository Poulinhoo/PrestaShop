<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\ExtraProperty\CommandHandler;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\CannotModifyModuleExtraPropertyDefinitionException;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\ExtraPropertyDefinitionNotFoundException;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;

/**
 * Provides shared helpers for extra property definition command handlers.
 *
 * Centralises:
 *  - raw row look-up by primary key (bypasses the cached read repository so
 *    handlers always see fresh data right after a write)
 *  - guard that rejects module-owned definitions from BO mutations
 *  - ExtraPropertyDefinition reconstruction from a raw DB row
 */
abstract class AbstractExtraPropertyDefinitionHandler
{
    /**
     * @param Connection $connection
     * @param string $prefix Database table prefix
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * Loads the raw DB row for the given definition id.
     *
     * @param int $id
     *
     * @return array<string, mixed>
     *
     * @throws ExtraPropertyDefinitionNotFoundException When no row is found
     */
    protected function findRawRowById(int $id): array
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
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

        return $row;
    }

    /**
     * Ensures the given raw DB row belongs to a core definition (module_name IS NULL).
     *
     * @param array<string, mixed> $row
     *
     * @throws CannotModifyModuleExtraPropertyDefinitionException When the definition is module-owned
     */
    protected function assertIsNotModuleOwned(array $row): void
    {
        if (!empty($row['module_name'])) {
            throw new CannotModifyModuleExtraPropertyDefinitionException(
                sprintf(
                    'Extra property definition "%s.%s" is owned by module "%s" and cannot be modified from the BO.',
                    $row['entity_name'],
                    $row['property_name'],
                    $row['module_name']
                )
            );
        }
    }

    /**
     * Builds an ExtraPropertyDefinition object from a raw DB row, optionally overriding editable fields.
     *
     * Structural fields (type, scope, size, sql_index, defaultValue) always come from the row.
     * The $overrides array may contain any ExtraPropertyDefinition constructor parameter name as key.
     *
     * @param array<string, mixed> $row Raw row from extra_property_definition
     * @param array<string, mixed> $overrides Editable field overrides keyed by constructor param name
     *
     * @return ExtraPropertyDefinition
     */
    protected function buildOptionsFromRow(array $row, array $overrides = []): ExtraPropertyDefinition
    {
        $base = ExtraPropertyDefinition::fromRow($row);

        if (empty($overrides)) {
            return $base;
        }

        return new ExtraPropertyDefinition(
            type: $base->type,
            scope: $base->scope,
            propertyName: $base->propertyName,
            entityName: $base->entityName,
            moduleName: $base->moduleName,
            enumValues: $base->enumValues,
            defaultValue: $base->defaultValue,
            nullable: $base->nullable,
            formRequired: array_key_exists('formRequired', $overrides) ? (bool) $overrides['formRequired'] : $base->formRequired,
            size: $base->size,
            sqlIndex: $base->sqlIndex,
            displayApi: array_key_exists('displayApi', $overrides) ? (bool) $overrides['displayApi'] : $base->displayApi,
            displayFront: array_key_exists('displayFront', $overrides) ? (bool) $overrides['displayFront'] : $base->displayFront,
            associatedForms: array_key_exists('associatedForms', $overrides) ? $overrides['associatedForms'] : $base->associatedForms,
            associatedGrids: array_key_exists('associatedGrids', $overrides) ? $overrides['associatedGrids'] : $base->associatedGrids,
            formFieldType: array_key_exists('formFieldType', $overrides) ? $overrides['formFieldType'] : $base->formFieldType,
            formOptions: array_key_exists('formOptions', $overrides) ? $overrides['formOptions'] : $base->formOptions,
            validator: array_key_exists('validator', $overrides) ? $overrides['validator'] : $base->validator,
            labelWording: array_key_exists('labelWording', $overrides) ? $overrides['labelWording'] : $base->labelWording,
            labelDomain: array_key_exists('labelDomain', $overrides) ? $overrides['labelDomain'] : $base->labelDomain,
            descriptionWording: array_key_exists('descriptionWording', $overrides) ? $overrides['descriptionWording'] : $base->descriptionWording,
            descriptionDomain: array_key_exists('descriptionDomain', $overrides) ? $overrides['descriptionDomain'] : $base->descriptionDomain,
        );
    }
}
