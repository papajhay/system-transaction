<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Controller\Admin\FeeCrudController;
use App\Entity\Fee;
use App\Enum\TypeFee;
use App\Tests\Feature\Transactions\TransactionTestCase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use PHPUnit\Framework\Attributes\Test;

final class FeeCrudTest extends TransactionTestCase
{
    #[Test]
    public function exposesFeeTypeAndPersistsFixedAmountWithoutResettingIt(): void
    {
        $controller = new FeeCrudController();
        $fields = array_map(
            static fn ($field) => $field->getAsDto(),
            iterator_to_array($controller->configureFields(Crud::PAGE_NEW)),
        );
        $typeField = array_values(array_filter(
            $fields,
            static fn ($field): bool => $field->getProperty() === 'type',
        ));

        self::assertCount(1, $typeField);
        self::assertSame(
            [
                'Fixed fee' => TypeFee::FEE_CHARGED_FIXED,
                'Rate fee' => TypeFee::FEE_CHARGED_RATE,
                'Free charged' => TypeFee::FREE_CHARGED,
            ],
            $typeField[0]->getCustomOption(ChoiceField::OPTION_CHOICES),
        );

        $fee = $controller->createEntity(Fee::class)
            ->setType(TypeFee::FEE_CHARGED_FIXED)
            ->setAmount(17.0);
        $this->entityManager->persist($fee);
        $this->entityManager->flush();
        $id = $fee->getId();

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Fee::class, $id);
        self::assertNotNull($stored);
        self::assertSame(TypeFee::FEE_CHARGED_FIXED, $stored->getType());
        self::assertSame('17.00', number_format($stored->getAmount(), 2, '.', ''));

        $stored->setAmount(27.0);
        $controller->updateEntity($this->entityManager, $stored);
        $this->entityManager->clear();
        $edited = $this->entityManager->find(Fee::class, $id);
        self::assertNotNull($edited);
        self::assertSame(TypeFee::FEE_CHARGED_FIXED, $edited->getType());
        self::assertSame('27.00', number_format($edited->getAmount(), 2, '.', ''));
    }
}
