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
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertySqlIndex;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyType;

/**
 * Provides shared helpers for extra property definition command handlers.
 *
 * Centralises:
 *  - raw row look-up by primary key (bypasses the cached read repository so
 *    handlers always see fresh data right after a write)
 *  - guard that rejects module-owned definitions from BO mutations
 *  - ExtraPropertyOptions reconstruction from a raw DB row
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
     * Builds an ExtraPropertyOptions object from a raw DB row, optionally overriding editable fields.
     *
     * Structural fields (type, scope, size, sql_index, defaultValue) always come from the row.
     * The $overrides array may contain any ExtraPropertyOptions constructor parameter name as key.
     *
     * @param array<string, mixed> $row Raw row from extra_property_definition
     * @param array<string, mixed> $overrides Editable field overrides keyed by constructor param name
     *
     * @return ExtraPropertyOptions
     */
    protected function buildOptionsFromRow(array $row, array $overrides = []): ExtraPropertyOptions
    {
        $formOptionsRaw = $row['form_options'] ?? null;
        $formOptions = (is_string($formOptionsRaw) && '' !== $formOptionsRaw)
            ? json_decode($formOptionsRaw, true)
            : null;

        $associatedFormsRaw = $row['associated_forms'] ?? null;
        $associatedForms = (is_string($associatedFormsRaw) && '' !== $associatedFormsRaw)
            ? (array_values(array_filter((array) json_decode($associatedFormsRaw, true), static fn (mixed $v): bool => is_string($v) && '' !== $v)) ?: null)
            : null;

        $associatedGridsRaw = $row['associated_grids'] ?? null;
        $associatedGrids = (is_string($associatedGridsRaw) && '' !== $associatedGridsRaw)
            ? (array_values(array_filter((array) json_decode($associatedGridsRaw, true), static fn (mixed $v): bool => is_string($v) && '' !== $v)) ?: null)
            : null;

        return new ExtraPropertyOptions(
            type: ExtraPropertyType::from((string) $row['type']),
            scope: ExtraPropertyScope::from((string) $row['scope']),
            enumValues: null,
            defaultValue: '' !== ($row['default_value'] ?? '') ? $row['default_value'] : null,
            nullable: true,
            size: isset($row['size']) && '' !== $row['size'] ? (int) $row['size'] : null,
            sqlIndex: ExtraPropertySqlIndex::from((string) ($row['sql_index'] ?? 'none')),
            moduleName: null,
            formRequired: array_key_exists('formRequired', $overrides)
                ? (bool) $overrides['formRequired']
                : !empty($row['form_required']),
            labelWording: array_key_exists('labelWording', $overrides)
                ? $overrides['labelWording']
                : ('' !== ($row['label_wording'] ?? '') ? $row['label_wording'] : null),
            labelDomain: array_key_exists('labelDomain', $overrides)
                ? $overrides['labelDomain']
                : ('' !== ($row['label_domain'] ?? '') ? $row['label_domain'] : null),
            descriptionWording: array_key_exists('descriptionWording', $overrides)
                ? $overrides['descriptionWording']
                : ('' !== ($row['description_wording'] ?? '') ? $row['description_wording'] : null),
            descriptionDomain: array_key_exists('descriptionDomain', $overrides)
                ? $overrides['descriptionDomain']
                : ('' !== ($row['description_domain'] ?? '') ? $row['description_domain'] : null),
            formFieldType: array_key_exists('formFieldType', $overrides)
                ? $overrides['formFieldType']
                : ('' !== ($row['form_field_type'] ?? '') ? $row['form_field_type'] : null),
            formOptions: array_key_exists('formOptions', $overrides)
                ? $overrides['formOptions']
                : (is_array($formOptions) ? $formOptions : null),
            validator: array_key_exists('validator', $overrides)
                ? $overrides['validator']
                : ('' !== ($row['validator'] ?? '') ? $row['validator'] : null),
            displayApi: array_key_exists('displayApi', $overrides)
                ? (bool) $overrides['displayApi']
                : !empty($row['display_api']),
            associatedForms: array_key_exists('associatedForms', $overrides)
                ? $overrides['associatedForms']
                : $associatedForms,
            associatedGrids: array_key_exists('associatedGrids', $overrides)
                ? $overrides['associatedGrids']
                : $associatedGrids,
            displayFront: array_key_exists('displayFront', $overrides)
                ? (bool) $overrides['displayFront']
                : !empty($row['display_front']),
        );
    }
}
