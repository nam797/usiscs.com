<?php

declare(strict_types=1);

namespace App\Admin\Filter;

use App\Distributor\Warehouse\WarehouseStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;

/**
 * Backoffice filter on a purchase order's WarehouseStatus, which isn't a
 * column. Also offers "not received yet": waiting, on its way or overdue.
 */
final class WarehouseStatusFilter implements FilterInterface
{
    use FilterTrait;

    public const NOT_RECEIVED = 'not_received';

    public static function new(string $propertyName = 'warehouseStatus', string $label = 'Warehouse status'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle')
            ->setFormTypeOption('value_type_options.choices', ['Not received yet' => self::NOT_RECEIVED] + WarehouseStatus::choices());
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $value = $filterDataDto->getValue();
        if (!is_string($value)) {
            return;
        }
        $alias = $filterDataDto->getEntityAlias();
        $negate = '!=' === $filterDataDto->getComparison();

        if (self::NOT_RECEIVED === $value) {
            $condition = sprintf('%1$s.receivedAt IS NULL AND %1$s.forwardedAt IS NULL', $alias);
            $queryBuilder->andWhere($negate ? sprintf('NOT (%s)', $condition) : $condition);

            return;
        }

        $status = WarehouseStatus::tryFrom($value);
        if (null === $status) {
            return;
        }

        $status->apply($queryBuilder, $alias, $filterDataDto->getParameterName().'_', new \DateTimeImmutable('today'), $negate);
    }
}
