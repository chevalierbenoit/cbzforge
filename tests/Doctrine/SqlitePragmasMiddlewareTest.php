<?php

namespace App\Tests\Doctrine;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
class SqlitePragmasMiddlewareTest extends KernelTestCase
{
    public function testConnectionUsesWalAndBusyTimeout(): void
    {
        self::bootKernel();
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $journalMode = (string) $conn->executeQuery('PRAGMA journal_mode')->fetchOne();
        $this->assertSame('wal', strtolower($journalMode));

        $busyTimeout = (int) $conn->executeQuery('PRAGMA busy_timeout')->fetchOne();
        $this->assertSame(5000, $busyTimeout);
    }
}
