<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace Tests\Integration\Behaviour\Features\Context\Domain;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Currency;
use Language;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tests\Integration\Behaviour\Features\Context\AbstractPrestaShopFeatureContext;
use Tests\Integration\Behaviour\Features\Context\CommonFeatureContext;
use Tests\Integration\Behaviour\Features\Context\LastExceptionTrait;
use Tests\Resources\MailDevClient;

abstract class AbstractDomainFeatureContext extends AbstractPrestaShopFeatureContext implements Context
{
    use LastExceptionTrait;

    protected const JPG_IMAGE_TYPE = '.jpg';
    protected const JPG_IMAGE_STRING = 'iVBORw0KGgoAAAANSUhEUgAAABwAAAASCAMAAAB/2U7WAAAABl'
        . 'BMVEUAAAD///+l2Z/dAAAASUlEQVR4XqWQUQoAIAxC2/0vXZDr'
        . 'EX4IJTRkb7lobNUStXsB0jIXIAMSsQnWlsV+wULF4Avk9fLq2r'
        . '8a5HSE35Q3eO2XP1A1wQkZSgETvDtKdQAAAABJRU5ErkJggg==';

    /**
     * @return CommandBusInterface
     */
    protected function getCommandBus()
    {
        return CommonFeatureContext::getContainer()->get('prestashop.core.command_bus');
    }

    /**
     * @return CommandBusInterface
     */
    protected function getQueryBus()
    {
        return CommonFeatureContext::getContainer()->get('prestashop.core.query_bus');
    }

    protected function getContainer(): ContainerInterface
    {
        return CommonFeatureContext::getContainer();
    }

    protected function getMailDevClient(): MailDevClient
    {
        return $this->getContainer()->get(MailDevClient::class);
    }

    /**
     * @param string $references
     *
     * @return int[]
     */
    protected function referencesToIds(string $references): array
    {
        if (empty($references)) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $references) as $reference) {
            $reference = trim($reference);

            if (!$this->getSharedStorage()->exists($reference)) {
                throw new RuntimeException(sprintf('Reference %s does not exist in shared storage', $reference));
            }

            $ids[] = $this->getSharedStorage()->get($reference);
        }

        return $ids;
    }

    /**
     * @param string $reference
     *
     * @return int
     */
    protected function referenceToId(string $reference): int
    {
        if (!$this->getSharedStorage()->exists($reference)) {
            throw new RuntimeException(sprintf('Reference %s does not exist in shared storage', $reference));
        }

        return $this->getSharedStorage()->get($reference);
    }

    /**
     * @param TableNode $tableNode
     *
     * @return array
     */
    protected function localizeByRows(TableNode $tableNode): array
    {
        return $this->parseLocalizedRow($tableNode->getRowsHash());
    }

    /**
     * @param TableNode $table
     *
     * @return array
     */
    protected function localizeByColumns(TableNode $table): array
    {
        $rows = [];
        foreach ($table->getColumnsHash() as $key => $column) {
            $row = [];
            foreach ($column as $columnName => $value) {
                $row[$columnName] = $value;
            }

            $rows[] = $this->parseLocalizedRow($row);
        }

        return $rows;
    }

    /**
     * @param string $localizedValue
     *
     * @return array
     */
    protected function localizeByCell(string $localizedValue): array
    {
        $localizedValues = [];
        $valuesByLang = explode(';', $localizedValue);
        foreach ($valuesByLang as $valueByLang) {
            $value = explode(':', $valueByLang);
            $langId = (int) Language::getIdByLocale($value[0], true);
            $localizedValues[$langId] = $value[1];
        }

        return $localizedValues;
    }

    protected function getDefaultCurrencyId(): int
    {
        return Currency::getDefaultCurrencyId();
    }

    protected function getDefaultCurrencyIsoCode(): string
    {
        return Currency::getIsoCodeById($this->getDefaultCurrencyId());
    }

    /**
     * @param array $row
     *
     * @return array
     */
    protected function parseLocalizedRow(array $row): array
    {
        $parsedRow = [];
        foreach ($row as $key => $value) {
            $localeMatch = preg_match('/\[.*?\]/', $key, $matches) ? reset($matches) : null;

            if (!$localeMatch) {
                $parsedRow[$key] = $value;
                continue;
            }

            $propertyName = str_replace($localeMatch, '', $key);
            $locale = str_replace(['[', ']'], '', $localeMatch);

            $langId = (int) Language::getIdByLocale($locale, true);

            if (!$langId) {
                throw new RuntimeException(sprintf('Language by locale "%s" was not found', $locale));
            }

            $parsedRow[$propertyName][$langId] = $value;
        }

        return $parsedRow;
    }

    /**
     * @param string $dirImage
     * @param string $imageName
     * @param int $objectId
     *
     * @return string
     */
    protected function pretendImageUploaded(string $dirImage, string $imageName, int $objectId): string
    {
        // @todo: refactor CategoryCoverUploader. Move uploaded file in Form handler instead of Uploader and use the uploader here in tests
        $im = imagecreatefromstring(base64_decode(self::JPG_IMAGE_STRING));
        if ($im !== false) {
            header('Content-Type: image/jpg');
            imagejpeg(
                $im,
                $dirImage . $objectId . self::JPG_IMAGE_TYPE,
                0
            );
            imagedestroy($im);
        }

        return $imageName;
    }
}
