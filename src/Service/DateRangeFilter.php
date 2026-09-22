<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\DateTimeFilterType;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Contracts\Translation\TranslatableInterface;

final class DateRangeFilter implements FilterInterface
{
    use FilterTrait;

    /**
     * @param TranslatableInterface|string|false|null $label
     */
    public static function new(string $propertyName, $label = null): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(DateTimeFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle')
            ->setFormTypeOption('value_type', DateType::class)
            ->setFormTypeOption('value_type_options', [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $alias = $filterDataDto->getEntityAlias();
        $property = $filterDataDto->getProperty();
        $comparison = $filterDataDto->getComparison();
        $parameterName = $filterDataDto->getParameterName();
        $parameter2Name = $filterDataDto->getParameter2Name();
        $dateFrom = $this->createDate($filterDataDto->getValue(), false);
        $dateTo = $this->createDate($filterDataDto->getValue2(), true);
        $dateType = $property === 'rateDate'
            ? Types::DATE_IMMUTABLE
            : Types::DATETIME_IMMUTABLE;

        if (null === $dateFrom) {
            $queryBuilder->andWhere(sprintf('%s.%s %s', $alias, $property, $comparison));

            return;
        }

        if (ComparisonType::BETWEEN === $comparison) {
            if (null === $dateTo) {
                return;
            }

            if ($dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [
                    $dateTo->setTime(0, 0, 0),
                    $dateFrom->setTime(23, 59, 59),
                ];
            }

            $queryBuilder
                ->andWhere(sprintf('%s.%s BETWEEN :%s AND :%s', $alias, $property, $parameterName, $parameter2Name))
                ->setParameter($parameterName, $dateFrom, $dateType)
                ->setParameter($parameter2Name, $dateTo, $dateType);

            return;
        }

        if (ComparisonType::EQ === $comparison) {
            $queryBuilder
                ->andWhere(sprintf('%s.%s BETWEEN :%s AND :%s', $alias, $property, $parameterName, $parameter2Name))
                ->setParameter($parameterName, $dateFrom, $dateType)
                ->setParameter($parameter2Name, $dateFrom->setTime(23, 59, 59), $dateType);

            return;
        }

        if (ComparisonType::NEQ === $comparison) {
            $queryBuilder
                ->andWhere(sprintf('%s.%s NOT BETWEEN :%s AND :%s', $alias, $property, $parameterName, $parameter2Name))
                ->setParameter($parameterName, $dateFrom, $dateType)
                ->setParameter($parameter2Name, $dateFrom->setTime(23, 59, 59), $dateType);

            return;
        }

        $date = match ($comparison) {
            ComparisonType::GT => $dateFrom->setTime(23, 59, 59),
            ComparisonType::GTE => $dateFrom,
            ComparisonType::LT => $dateFrom,
            ComparisonType::LTE => $dateFrom->setTime(23, 59, 59),
            default => $dateFrom,
        };

        $queryBuilder
            ->andWhere(sprintf('%s.%s %s :%s', $alias, $property, $comparison, $parameterName))
            ->setParameter($parameterName, $date, $dateType);
    }

    private function createDate(mixed $value, bool $endOfDay): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);

        return $date->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, $endOfDay ? 59 : 0);
    }
}
