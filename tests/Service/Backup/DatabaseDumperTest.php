<?php

namespace App\Tests\Service\Backup;

use App\Service\Backup\DatabaseDumper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El volcado de la base contra MySQL de verdad, sobre una tabla propia del test
 * que tiene lo que rompe un volcado ingenuo: una columna generada, una con un
 * DEFAULT calculado (que MySQL también marca como "generated"), un binario, un
 * NULL y comillas dentro de un texto.
 */
class DatabaseDumperTest extends KernelTestCase
{
    private const TABLE = 'zz_backup_dump_probe';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (
            id INT NOT NULL PRIMARY KEY,
            name VARCHAR(40) NULL,
            name_key VARCHAR(40) GENERATED ALWAYS AS (COALESCE(name, \'\')) STORED,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payload BLOB NULL
        ) DEFAULT CHARSET=utf8mb4');
        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . " (id, name, created_at, payload) VALUES (1, 'O''Donnell \\\\ Peña', '2026-01-02 03:04:05', 0x00FF27), (2, NULL, '2026-01-02 03:04:05', NULL)");
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);

        parent::tearDown();
    }

    /**
     * La tabla sale con su definición y sus filas, la columna generada fuera del
     * INSERT (MySQL no deja escribir en ella), la del DEFAULT calculado dentro
     * (si no, al restaurar tomaría la fecha del día), el binario en hexadecimal
     * y los textos escapados.
     */
    public function testVuelcaDefinicionYFilasSinLaColumnaGenerada(): void
    {
        $sql = (string) gzdecode((new DatabaseDumper($this->connection))->dump());

        $this->assertStringContainsString("SET FOREIGN_KEY_CHECKS=0;\n", $sql);
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS=1;\n", $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `' . self::TABLE . "`;\nCREATE TABLE `" . self::TABLE . '`', $sql);
        $this->assertStringContainsString(
            'INSERT INTO `' . self::TABLE . "` (`id`, `name`, `created_at`, `payload`) VALUES ('1', 'O\\'Donnell \\\\ Peña', '2026-01-02 03:04:05', 0x00ff27),\n('2', NULL, '2026-01-02 03:04:05', NULL);\n",
            $sql,
        );
    }

    /**
     * Dentro de una transacción ajena no vuelca: abrir la suya haría COMMIT de
     * lo que la otra tuviera a medias.
     */
    public function testDentroDeUnaTransaccionAbiertaNoVuelca(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            (new DatabaseDumper($this->connection))->dump();
        } finally {
            $this->connection->rollBack();
        }
    }

    /** El volcado no deja abierta la transacción que usa para leer. */
    public function testNoDejaLaTransaccionAbierta(): void
    {
        (new DatabaseDumper($this->connection))->dump();

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()'));
    }
}
