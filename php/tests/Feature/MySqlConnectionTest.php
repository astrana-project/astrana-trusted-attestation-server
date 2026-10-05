<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * What the MySQL and MariaDB connections ask of the driver.
 *
 * The store's writes report how many rows they touched, and the service reads 0 as a row that vanished
 * between its lookup and the write, answered 404. MySQL by default reports the rows an UPDATE changed,
 * not the rows it matched, so saving the key a relationship already holds would report 0 and be answered
 * 404 where PostgreSQL and SQL Server, which report matched rows, answer 200. The connection asks for
 * matched rows instead. The connector is driven against a stand-in connection, so no MySQL server is
 * needed, and the options it would open the connection with are kept.
 */
final class MySqlConnectionTest extends TestCase
{
    #[Test]
    #[TestWith(['mysql'])]
    #[TestWith(['mariadb'])]
    public function the_connection_reports_the_rows_an_update_matched_not_only_those_it_changed(string $connection): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($this->createStub(PDOStatement::class));

        $connector = new class($pdo) extends MySqlConnector
        {
            /** @var array<int, mixed> */
            public array $opened = [];

            public function __construct(private readonly PDO $pdo) {}

            public function createConnection($dsn, array $config, array $options): PDO
            {
                $this->opened = $options;

                return $this->pdo;
            }
        };

        $connector->connect(config('database.connections.'.$connection));

        self::assertTrue($connector->opened[Mysql::ATTR_FOUND_ROWS] ?? false);
    }
}
