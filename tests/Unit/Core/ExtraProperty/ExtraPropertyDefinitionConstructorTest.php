<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\ExtraProperty;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;

/**
 * Verifies that ExtraPropertyDefinition constructor rejects invalid inputs.
 *
 * Covers: empty entityName/propertyName, SQL-identifier violations, and the
 * associatedForms/associatedGrids format / labelWording requirement guards.
 */
class ExtraPropertyDefinitionConstructorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // entityName validation
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidSqlIdentifierProvider
     */
    public function testInvalidEntityNameThrows(string $invalidValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/entityName/');

        new ExtraPropertyDefinition(entityName: $invalidValue, propertyName: 'video_link');
    }

    // -------------------------------------------------------------------------
    // propertyName validation
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidSqlIdentifierProvider
     */
    public function testInvalidPropertyNameThrows(string $invalidValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/propertyName/');

        new ExtraPropertyDefinition(entityName: 'product', propertyName: $invalidValue);
    }

    // -------------------------------------------------------------------------
    // Valid identifiers must be accepted
    // -------------------------------------------------------------------------

    /**
     * @dataProvider validIdentifierProvider
     */
    public function testValidIdentifiersAccepted(string $entityName, string $propertyName): void
    {
        $definition = new ExtraPropertyDefinition(entityName: $entityName, propertyName: $propertyName);

        $this->assertSame($entityName, $definition->getEntityName());
        $this->assertSame($propertyName, $definition->getPropertyName());
    }

    // -------------------------------------------------------------------------
    // associatedForms / labelWording guards (pre-existing, not covered elsewhere)
    // -------------------------------------------------------------------------

    public function testAssociatedFormsRequiresLabelWording(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/labelWording is required/');

        new ExtraPropertyDefinition(
            entityName: 'product',
            propertyName: 'video_link',
            associatedForms: ['product'],
            // labelWording intentionally omitted
        );
    }

    public function testAssociatedGridsRequiresLabelWording(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/labelWording is required/');

        new ExtraPropertyDefinition(
            entityName: 'product',
            propertyName: 'video_link',
            associatedGrids: ['product'],
            // labelWording intentionally omitted
        );
    }

    public function testDuplicateFormIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate formId/');

        new ExtraPropertyDefinition(
            entityName: 'product',
            propertyName: 'video_link',
            associatedForms: ['product', 'product'],
            labelWording: 'Video link',
        );
    }

    public function testDuplicateGridIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate gridId/');

        new ExtraPropertyDefinition(
            entityName: 'product',
            propertyName: 'video_link',
            associatedGrids: ['product', 'product'],
            labelWording: 'Video link',
        );
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /**
     * Values that are empty or contain characters outside [a-zA-Z0-9_-] and therefore must be rejected.
     *
     * @return array<string, array{string}>
     */
    public static function invalidSqlIdentifierProvider(): array
    {
        return [
            'empty string' => [''],
            'space' => ['invalid name'],
            'dot' => ['entity.name'],
            'at sign' => ['entity@name'],
            'slash' => ['entity/name'],
            'parenthesis' => ['entity(name)'],
            'SQL injection attempt' => ["'; DROP TABLE product; --"],
        ];
    }

    /**
     * Values allowed by the [a-zA-Z0-9_-]+ pattern.
     *
     * @return array<string, array{string, string}>
     */
    public static function validIdentifierProvider(): array
    {
        return [
            'simple lowercase' => ['product', 'video_link'],
            'uppercase' => ['Product', 'VideoLink'],
            'hyphen in entity' => ['my-entity', 'field'],
            'hyphen in property' => ['product', 'my-field'],
            'alphanumeric with digits' => ['entity123', 'field456'],
            'underscore separators' => ['ps_product', 'extra_video_link'],
        ];
    }
}
