<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

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
 *   - ExtraPropertyDefinitionWriterInterface for definition persistence (save/delete)
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
     *
     * Constructor-level validations (entityName, propertyName, associatedForms/Grids format,
     * labelWording) are already enforced by ExtraPropertyDefinition itself.
     *
     * The registry additionally validates:
     * - module name format (regex)
     * - storage column name length (≤ 64 chars)
     * - immutability of storage-critical fields on already-registered definitions
     */
    public function register(ExtraPropertyDefinition $definition): bool
    {
        $entityName = $definition->getEntityName();
        $propertyName = $definition->getPropertyName();

        // Resolve module name: '_core' is a display-only sentinel — never stored in DB.
        $rawModuleName = $definition->getModuleName();
        $moduleName = (null !== $rawModuleName && '' !== $rawModuleName && ExtraPropertyDefinition::CORE_MODULE_KEY !== $rawModuleName)
            ? $rawModuleName
            : null;
        if (null !== $moduleName && !$this->validator::isModuleName($moduleName)) {
            return false;
        }

        $scope = $definition->getScope();
        $normalizedScope = $scope->value;

        // Inject the resolved module name into a working copy so that getStorageColumnName() is correct.
        $workingDefinition = null !== $moduleName
            ? $definition->withModuleName($moduleName)
            : $definition;

        $storageColumnName = $workingDefinition->getStorageColumnName();
        if (!$this->isValidSqlIdentifier($storageColumnName)) {
            return false;
        }

        $sqlColumnDefinition = ColumnDefinitionMapper::getSqlDefinition($workingDefinition);

        // 1. Ensure the *_extra table and column exist.
        try {
            $this->schemaManager->ensureExtraTableAndColumn(
                $entityName,
                $scope,
                $storageColumnName,
                $sqlColumnDefinition,
                $workingDefinition->getSqlIndex()
            );
        } catch (Throwable $exception) {
            $this->logger->error(
                'Failed to create extra table/column: {message}',
                ['message' => $exception->getMessage(), 'exception' => $exception]
            );

            return false;
        }

        // 2. Check for immutable storage-critical changes on an existing definition.
        $existingDefinition = $this->readRepository->findDefinitionByModuleAndField(
            $entityName,
            $moduleName,
            $propertyName,
            $normalizedScope
        );

        if (null !== $existingDefinition && $this->hasStorageChanges($workingDefinition, $existingDefinition)) {
            $this->logger->error(
                'Refusing to modify storage-critical fields (type/size/scope/defaultValue) for existing extra property {entity}.{field}.',
                ['entity' => $entityName, 'field' => $propertyName]
            );

            return false;
        }

        // 3. Insert or update the registry row.
        $savedId = $this->writeRepository->save(
            $workingDefinition,
            $entityName,
            $propertyName,
            $moduleName,
            $normalizedScope
        );

        return false !== $savedId;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(string $entityName, string $propertyName, ?string $moduleName, ExtraPropertyScope $fieldScope = ExtraPropertyScope::COMMON, bool $dropColumn = false): bool
    {
        if (!$this->validator::isTableOrIdentifier($propertyName)) {
            return false;
        }

        if (!$this->validator::isTableOrIdentifier($entityName)) {
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
        if ($dropColumn) {
            try {
                $this->schemaManager->dropExtraColumnIfExists(
                    $definition->getEntityName(),
                    $definition->getScope(),
                    $definition->getStorageColumnName()
                );
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
     * Returns true when $incoming would change a storage-critical field on $existing.
     *
     * These fields affect the SQL column schema (ALTER TABLE) and are immutable once registered.
     * Display flags, labels, form options, positions, and index type can be updated freely.
     *
     * Note: nullable and enumValues are not persisted in the registry and therefore cannot be
     * compared here; they are applied only at initial column creation.
     */
    protected function hasStorageChanges(ExtraPropertyDefinition $incoming, ExtraPropertyDefinition $existing): bool
    {
        return $incoming->getType() !== $existing->getType()
            || $incoming->getScope() !== $existing->getScope()
            || $incoming->getSize() !== $existing->getSize()
            || $incoming->getDefaultValue() !== $existing->getDefaultValue();
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
