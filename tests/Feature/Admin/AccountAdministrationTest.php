<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Controller\Admin\AccountCrudController;
use App\Controller\Admin\DashboardController;
use App\Entity\Account;
use App\Enum\StatusAccount;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AccountAdministrationTest extends TestCase
{
    public function testDeletingAnAccountSuspendsItWithoutRemovingIt(): void
    {
        $account = (new Account())
            ->setStatus(StatusAccount::ACTIVE)
            ->setUpdatedAt(new DateTimeImmutable('yesterday'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($account);
        $entityManager->expects(self::once())->method('flush');

        (new AccountCrudController())->deleteEntity($entityManager, $account);

        self::assertSame(StatusAccount::SUSPENDED, $account->getStatus());
        self::assertSame(StatusAccount::ACTIVE, $account->getPreviousStatus());
        self::assertGreaterThan(new DateTimeImmutable('yesterday'), $account->getUpdatedAt());
    }

    public function testRestoringAnAccountUsesItsPreviousStatusAndClearsIt(): void
    {
        $account = (new Account())
            ->setStatus(StatusAccount::SUSPENDED)
            ->setPreviousStatus(StatusAccount::CLOSED)
            ->setUpdatedAt(new DateTimeImmutable('yesterday'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($account);
        $entityManager->expects(self::once())->method('flush');

        (new AccountCrudController())->restoreEntity($entityManager, $account);

        self::assertSame(StatusAccount::CLOSED, $account->getStatus());
        self::assertNull($account->getPreviousStatus());
        self::assertGreaterThan(new DateTimeImmutable('yesterday'), $account->getUpdatedAt());
    }

    public function testRestoringAnAccountDefaultsToActiveWhenThereIsNoPreviousStatus(): void
    {
        $account = (new Account())->setStatus(StatusAccount::SUSPENDED);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($account);
        $entityManager->expects(self::once())->method('flush');

        (new AccountCrudController())->restoreEntity($entityManager, $account);

        self::assertSame(StatusAccount::ACTIVE, $account->getStatus());
        self::assertNull($account->getPreviousStatus());
    }

    public function testRestoringAnActiveAccountIsRejected(): void
    {
        $account = (new Account())->setStatus(StatusAccount::ACTIVE);

        $this->expectException(\LogicException::class);
        (new AccountCrudController())->restoreEntity($this->createMock(EntityManagerInterface::class), $account);
    }

    public function testAccountMenuContainsActiveAndSuspendedSections(): void
    {
        $items = iterator_to_array((new DashboardController())->configureMenuItems());
        $accountMenu = $items[1]->getAsDto();
        $subItems = $accountMenu->getSubItems();

        self::assertSame('Account', $accountMenu->getLabel());
        self::assertCount(2, $subItems);
        self::assertSame('Accounts', $subItems[0]->getLabel());
        self::assertSame('active', $subItems[0]->getRouteParameters()['accountView']);
        self::assertSame('Suspended Account', $subItems[1]->getLabel());
        self::assertSame('suspended', $subItems[1]->getRouteParameters()['accountView']);
    }
}
