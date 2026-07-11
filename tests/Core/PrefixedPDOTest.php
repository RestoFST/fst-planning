<?php

namespace Tests\Core;

use App\Core\PrefixedPDO;
use PHPUnit\Framework\TestCase;

class PrefixedPDOTest extends TestCase
{
    public function testPrefixQueryDoesNothingIfPrefixIsEmpty(): void
    {
        $pdo = $this->getMockBuilder(PrefixedPDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare', 'query', 'exec'])
            ->getMock();

        $ref = new \ReflectionProperty(PrefixedPDO::class, 'prefix');
        $ref->setAccessible(true);
        $ref->setValue($pdo, '');

        $sql = "SELECT * FROM services JOIN appoinment ON services.id = appoinment.sid";
        $this->assertSame($sql, $pdo->prefixQuery($sql));
    }

    public function testPrefixQueryAppliesPrefixToAllTables(): void
    {
        $pdo = $this->getMockBuilder(PrefixedPDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare', 'query', 'exec'])
            ->getMock();

        $ref = new \ReflectionProperty(PrefixedPDO::class, 'prefix');
        $ref->setAccessible(true);
        $ref->setValue($pdo, 'tst_');

        $sql = "SELECT s.*, a.date FROM services s JOIN appoinment a ON s.id = a.sid WHERE s.id = 1";
        $expected = "SELECT s.*, a.date FROM tst_services s JOIN tst_appoinment a ON s.id = a.sid WHERE s.id = 1";
        $this->assertSame($expected, $pdo->prefixQuery($sql));

        $sql2 = "INSERT INTO appointments_users (aid, uid) VALUES (1, 2)";
        $expected2 = "INSERT INTO tst_appointments_users (aid, uid) VALUES (1, 2)";
        $this->assertSame($expected2, $pdo->prefixQuery($sql2));

        $sql3 = "SELECT * FROM services_workdays JOIN services_holyday ON services_workdays.sid = services_holyday.sid";
        $expected3 = "SELECT * FROM tst_services_workdays JOIN tst_services_holyday ON tst_services_workdays.sid = tst_services_holyday.sid";
        $this->assertSame($expected3, $pdo->prefixQuery($sql3));
    }

    public function testPrefixQueryAvoidsDoublePrefixing(): void
    {
        $pdo = $this->getMockBuilder(PrefixedPDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare', 'query', 'exec'])
            ->getMock();

        $ref = new \ReflectionProperty(PrefixedPDO::class, 'prefix');
        $ref->setAccessible(true);
        $ref->setValue($pdo, 'tst_');

        $sql = "SELECT * FROM tst_services";
        $this->assertSame($sql, $pdo->prefixQuery($sql));
    }
}
