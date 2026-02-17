<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Builds query for catalog price rule list
 */
final class CartRuleQueryBuilder extends AbstractDoctrineQueryBuilder
{
    /**
     * @var DoctrineSearchCriteriaApplicatorInterface
     */
    private $searchCriteriaApplicator;

    /**
     * @var int
     */
    private $contextIdLang;

    /**
     * @param Connection $connection
     * @param string $dbPrefix
     * @param DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator
     * @param int $contextIdLang
     */
    public function __construct(
        Connection $connection,
        $dbPrefix,
        DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
        $contextIdLang
    ) {
        parent::__construct($connection, $dbPrefix);
        $this->searchCriteriaApplicator = $searchCriteriaApplicator;
        $this->contextIdLang = $contextIdLang;
    }

    /**
     * {@inheritdoc}
     */
    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getQueryBuilder($searchCriteria->getFilters())
            ->select(
                'cr.id_cart_rule,
            crl.name,
            cr.priority,
            cr.code,
            cr.quantity,
            cr.date_to,
            cr.active'
            );
        $this->searchCriteriaApplicator
            ->applyPagination($searchCriteria, $qb)
            ->applySorting($searchCriteria, $qb)
            ->applyDeterministicSorting($searchCriteria, $qb, 'cr', 'id_cart_rule')
        ;

        return $qb;
    }

    /**
     * {@inheritdoc}
     */
    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getQueryBuilder($searchCriteria->getFilters())
            ->select('COUNT(DISTINCT cr.`id_cart_rule`)')
        ;

        return $qb;
    }

    /**
     * Gets query builder with the common sql for catalog price rule listing.
     *
     * @param array $filters
     *
     * @return QueryBuilder
     */
    private function getQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->connection
            ->createQueryBuilder()
            ->from($this->dbPrefix . 'cart_rule', 'cr')
        ;

        $qb->leftJoin(
            'cr',
            $this->dbPrefix . 'cart_rule_lang',
            'crl',
            'cr.id_cart_rule = crl.id_cart_rule AND crl.id_lang = :contextLangId'
        );

        $this->applyFilters($qb, $filters);
        $qb->setParameter('contextLangId', $this->contextIdLang);

        return $qb;
    }

    /**
     * @param QueryBuilder $qb
     * @param array $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        $allowedFiltersAliasMap = [
            'id_cart_rule' => 'cr.id_cart_rule',
            'name' => 'crl.name',
            'priority' => 'cr.priority',
            'code' => 'cr.code',
            'quantity' => 'cr.quantity',
            'date_to' => 'cr.date_to',
            'active' => 'cr.active',
        ];

        $exactMatchFilters = ['id_cart_rule', 'quantity', 'priority', 'active'];

        foreach ($filters as $filterName => $value) {
            if (!array_key_exists($filterName, $allowedFiltersAliasMap)) {
                return;
            }

            if (in_array($filterName, $exactMatchFilters, true)) {
                $qb->andWhere($allowedFiltersAliasMap[$filterName] . ' = :' . $filterName);
                $qb->setParameter($filterName, $value);

                continue;
            }

            if ('date_to' === $filterName) {
                if (isset($value['from'])) {
                    $qb->andWhere($allowedFiltersAliasMap[$filterName] . ' >= :' . $filterName . '_from');
                    $qb->setParameter($filterName . '_from', $value['from']);
                }
                if (isset($value['to'])) {
                    $qb->andWhere($allowedFiltersAliasMap[$filterName] . ' <= :' . $filterName . '_to');
                    $qb->setParameter($filterName . '_to', $value['to']);
                }

                continue;
            }

            $qb->andWhere($allowedFiltersAliasMap[$filterName] . ' LIKE :' . $filterName);
            $qb->setParameter($filterName, "%$value%");
        }
    }
}
