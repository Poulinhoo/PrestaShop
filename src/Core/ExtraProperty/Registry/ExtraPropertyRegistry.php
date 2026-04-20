<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Registry;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\CachedExtraPropertyDefinitionRepository;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionWriterInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ColumnDefinitionMapper;
use PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ExtraPropertySchemaManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;
use Validate;

/**
 * Write-only registry implementation: register/unregister extra property definitions.
 *
 * Orchestrates:
 *   - ExtraPropertyDefinitionRepositoryInterface (read) for pre-flight existence checks
 *   - ExtraPropertyDefinitionWriterInterface for definition persistence (save/delete/normalize)
 *   - ExtraPropertySchemaManagerInterface for DDL on *_extra / *_extra_lang / *_extra_shop tables
 *   - Cache pools for invalidation after successful writes (same key as CachedExtraPropertyDefinitionRepository)
 */
class ExtraPropertyRegistry implements ExtraPropertyRegistryInterface
{
    protected readonly LoggerInterface $logger;

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $readRepository,
        protected readonly ExtraPropertyDefinitionWriterInterface $writeRepository,
        protected readonly ExtraPropertySchemaManagerInterface $schemaManager,
        protected readonly ?CacheInterface $cacheApp,
        protected readonly CacheInterface $filesystemDefinitionCache,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $entityName, string $propertyName, ExtraPropertyOptions $options): bool
    {
        if (!Validate::isTableOrIdentifier($propertyName)) {
            return false;
        }

        // Resolve module name: from options (explicit override) or null (core property).
        // '_core' is a display-only sentinel — never stored in DB; treat it as no module.
        $moduleName = (null !== $options->moduleName && '' !== $options->moduleName && ExtraPropertyNaming::CORE_MODULE_KEY !== $options->moduleName) ? $options->moduleName : null;
        if (null !== $moduleName && !Validate::isModuleName($moduleName)) {
            return false;
        }

        // Normalize entity name and scope against DB-backed list of known entities.
        $fieldScope = $options->scope->value;
        [$normalizedEntityName, $normalizedFieldScope] = $this->writeRepository->normalizeEntityNameAndFieldScope($entityName, $fieldScope);
        if (null === $normalizedEntityName || null === $normalizedFieldScope) {
            return false;
        }

        $storageColumnName = ExtraPropertyNaming::storageColumnName($moduleName ?? '', $propertyName);
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

        $savedId = $this->writeRepository->save(
            $options,
            $normalizedEntityName,
            $propertyName,
            $moduleName,
            $normalizedFieldScope,
            null !== $existingDefinition ? $existingDefinition->getId() : null
        );

        if (false === $savedId) {
            return false;
        }

        $this->invalidateEntityCache($normalizedEntityName);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(string $entityName, string $propertyName, ?string $moduleName, ExtraPropertyScope $fieldScope = ExtraPropertyScope::Common, bool $dropColumn = false): bool
    {
        if (!Validate::isTableOrIdentifier($propertyName)) {
            return false;
        }

        [$normalizedEntityName, $normalizedFieldScope] = $this->writeRepository->normalizeEntityNameAndFieldScope($entityName, $fieldScope->value);
        if (null === $normalizedEntityName || null === $normalizedFieldScope) {
            return false;
        }

        $existingDefinition = $this->readRepository->findDefinitionByModuleAndField(
            $normalizedEntityName,
            $moduleName,
            $propertyName,
            $normalizedFieldScope
        );
        if (null === $existingDefinition) {
            return true;
        }

        return $this->unregisterById($existingDefinition->getId(), $dropColumn);
    }

    /**
     * {@inheritdoc}
     */
    public function unregisterById(int $idExtraPropertyDefinition, bool $dropColumn = false): bool
    {
        if ($idExtraPropertyDefinition <= 0) {
            return false;
        }

        // Load row to get entity/scope/column before deletion.
        $definition = $this->readRepository->getDefinitionById($idExtraPropertyDefinition);
        if (null === $definition) {
            return true;
        }

        if ($dropColumn) {
            [$normalizedEntityName, $normalizedFieldScope] = $this->writeRepository->normalizeEntityNameAndFieldScope(
                $definition->getEntityName(),
                $definition->getFieldScope()
            );
            if (null === $normalizedEntityName || null === $normalizedFieldScope) {
                return false;
            }

            $storageColumnName = ExtraPropertyNaming::storageColumnName(
                $definition->getModuleName() ?? '',
                $definition->getPropertyName()
            );

            try {
                $this->schemaManager->dropExtraColumnIfExists($normalizedEntityName, $normalizedFieldScope, $storageColumnName);
            } catch (Throwable $exception) {
                $this->logger->error(
                    'Failed to drop extra column: {message}',
                    ['message' => $exception->getMessage(), 'exception' => $exception]
                );

                return false;
            }
        }

        $deleted = $this->writeRepository->delete($idExtraPropertyDefinition);
        if ($deleted) {
            $this->invalidateEntityCache($definition->getEntityName());
        }

        return $deleted;
    }

    /**
     * Removes the entity key from both cache pools so the next read reloads from DB.
     * Delegates key computation to CachedExtraPropertyDefinitionRepository to avoid duplication.
     *
     * @param string $entityName
     */
    protected function invalidateEntityCache(string $entityName): void
    {
        if ('' === $entityName) {
            return;
        }

        $cacheKey = CachedExtraPropertyDefinitionRepository::buildCacheKey($entityName);

        $this->filesystemDefinitionCache->delete($cacheKey);

        if (null !== $this->cacheApp) {
            $this->cacheApp->delete($cacheKey);
        }
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
