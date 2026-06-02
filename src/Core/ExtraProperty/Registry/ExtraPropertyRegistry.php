<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Registry;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionWriterInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ColumnDefinitionMapper;
use PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ExtraPropertySchemaManagerInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidationInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write-only registry implementation: register/unregister extra property definitions.
 *
 * Orchestrates:
 *   - ExtraPropertyDefinitionRepositoryInterface (read) for pre-flight existence checks
 *   - ExtraPropertyDefinitionWriterInterface for definition persistence (save/delete/normalize)
 *   - ExtraPropertySchemaManagerInterface for DDL on *_extra / *_extra_lang / *_extra_shop tables
 *
 * Does NOT handle cache invalidation: wrap with CachedExtraPropertyRegistry for that concern.
 */
class ExtraPropertyRegistry implements ExtraPropertyRegistryInterface
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $readRepository,
        protected readonly ExtraPropertyDefinitionWriterInterface $writeRepository,
        protected readonly ExtraPropertySchemaManagerInterface $schemaManager,
        protected readonly ExtraPropertyValidationInterface $validator,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $entityName, string $propertyName, ExtraPropertyDefinition $options): bool
    {
        if (!$this->validator->isTableOrIdentifier($propertyName)) {
            return false;
        }

        if (!$this->validator->isTableOrIdentifier($entityName)) {
            return false;
        }

        // Resolve module name: from options (explicit override) or null (core property).
        // '_core' is a display-only sentinel — never stored in DB; treat it as no module.
        $moduleName = (null !== $options->moduleName && '' !== $options->moduleName && ExtraPropertyDefinition::CORE_MODULE_KEY !== $options->moduleName) ? $options->moduleName : null;
        if (null !== $moduleName && !$this->validator->isModuleName($moduleName)) {
            return false;
        }

        $normalizedEntityName = $entityName;
        $normalizedFieldScope = $options->scope->value;

        // H6: label_wording is required whenever the field is visible in the BO (form or grid).
        if ((!empty($options->associatedForms) || !empty($options->associatedGrids))
            && (null === $options->labelWording || '' === trim($options->labelWording))
        ) {
            $this->logger->error(
                'Extra property {entity}.{field} must have a labelWording when associatedForms or associatedGrids is set.',
                ['entity' => $entityName, 'field' => $propertyName]
            );

            return false;
        }

        // Each formId must appear at most once in associatedForms.
        if (!empty($options->associatedForms)) {
            $seenFormIds = [];
            foreach ($options->associatedForms as $entry) {
                $formId = ExtraPropertyDefinition::parseFormEntry((string) $entry)['formId'];
                if (isset($seenFormIds[$formId])) {
                    $this->logger->error(
                        'Extra property {entity}.{field} has duplicate form ID "{formId}" in associatedForms.',
                        ['entity' => $entityName, 'field' => $propertyName, 'formId' => $formId]
                    );

                    return false;
                }
                $seenFormIds[$formId] = true;
            }
        }

        // Each gridId must appear at most once in associatedGrids.
        if (!empty($options->associatedGrids)) {
            $seenGridIds = [];
            foreach ($options->associatedGrids as $entry) {
                $gridId = ExtraPropertyDefinition::parseGridEntry((string) $entry)['gridId'];
                if (isset($seenGridIds[$gridId])) {
                    $this->logger->error(
                        'Extra property {entity}.{field} has duplicate grid ID "{gridId}" in associatedGrids.',
                        ['entity' => $entityName, 'field' => $propertyName, 'gridId' => $gridId]
                    );

                    return false;
                }
                $seenGridIds[$gridId] = true;
            }
        }

        $storageColumnName = ExtraPropertyDefinition::buildStorageColumnName($moduleName, $propertyName);
        if (!$this->isValidSqlIdentifier($storageColumnName)) {
            return false;
        }

        $sqlColumnDefinition = ColumnDefinitionMapper::getSqlDefinition($options);

        // 1. Ensure the *_extra table and column exist.
        try {
            $this->schemaManager->ensureExtraTableAndColumn(
                $normalizedEntityName,
                $normalizedFieldScope,
                $storageColumnName,
                $sqlColumnDefinition,
                $options->sqlIndex
            );
        } catch (Throwable $exception) {
            $this->logger->error(
                'Failed to create extra table/column: {message}',
                ['message' => $exception->getMessage(), 'exception' => $exception]
            );

            return false;
        }

        // 2. Insert or update the registry row.
        $existingDefinition = $this->readRepository->findDefinitionByModuleAndField(
            $normalizedEntityName,
            $moduleName,
            $propertyName,
            $normalizedFieldScope
        );

        // I4: block storage-critical changes on existing definitions to prevent destructive ALTER TABLE.
        if (null !== $existingDefinition && $this->hasStorageChanges($options, $existingDefinition)) {
            $this->logger->error(
                'Refusing to modify storage-critical fields (type/size) for existing extra property {entity}.{field}.',
                ['entity' => $normalizedEntityName, 'field' => $propertyName]
            );

            return false;
        }

        $savedId = $this->writeRepository->save(
            $options,
            $normalizedEntityName,
            $propertyName,
            $moduleName,
            $normalizedFieldScope
        );

        if (false === $savedId) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(string $entityName, string $propertyName, ?string $moduleName, ExtraPropertyScope $fieldScope = ExtraPropertyScope::COMMON, bool $dropColumn = false): bool
    {
        if (!$this->validator->isTableOrIdentifier($propertyName)) {
            return false;
        }

        if (!$this->validator->isTableOrIdentifier($entityName)) {
            return false;
        }

        $existingDefinition = $this->readRepository->findDefinitionByModuleAndField(
            $entityName,
            $moduleName,
            $propertyName,
            $fieldScope->value
        );
        if (null === $existingDefinition) {
            return true;
        }

        return $this->unregisterByDefinition($existingDefinition, $dropColumn);
    }

    /**
     * Unregisters one definition using its already-loaded value object.
     */
    protected function unregisterByDefinition(ExtraPropertyDefinition $definition, bool $dropColumn = false): bool
    {
        if ('' === $definition->getEntityName() || '' === $definition->getPropertyName()) {
            return false;
        }

        if ($dropColumn) {
            $storageColumnName = $definition->getStorageColumnName();

            try {
                $this->schemaManager->dropExtraColumnIfExists($definition->getEntityName(), $definition->getScope()->value, $storageColumnName);
            } catch (Throwable $exception) {
                $this->logger->error(
                    'Failed to drop extra column: {message}',
                    ['message' => $exception->getMessage(), 'exception' => $exception]
                );

                return false;
            }
        }

        return $this->writeRepository->deleteByDefinition($definition);
    }

    /**
     * Returns true when $options would change a storage-critical field on an existing definition.
     *
     * These fields affect the SQL column schema (ALTER TABLE) and are immutable once registered.
     * Display flags, labels, form options, positions, and index type can be updated freely.
     *
     * Note: `nullable` and `enumValues` are not persisted in the definition registry and
     * therefore cannot be compared here; they are applied only at initial column creation.
     */
    protected function hasStorageChanges(ExtraPropertyDefinition $options, ExtraPropertyDefinition $existing): bool
    {
        return $options->type !== $existing->type
            || $options->scope !== $existing->scope
            || $options->size !== $existing->getSize()
            || $options->defaultValue !== $existing->getDefaultValue();
    }

    /**
     * @param string $identifier
     *
     * @return bool
     */
    protected function isValidSqlIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $identifier);
    }
}
