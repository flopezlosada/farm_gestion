<?php

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;

/**
 * Volcado SQL de la base, comprimido en gzip, hecho desde PHP.
 *
 * POR QUÉ NO `mysqldump`: el hosting no da consola, y desde PHP no hay binario
 * que lanzar. Lo que sale es lo mismo que `mysqldump --single-transaction`: se
 * restaura con `gunzip -c copia.sql.gz | mysql base`.
 *
 * - **Una foto coherente**: todo se lee dentro de una transacción con snapshot,
 *   así que una factura que entra a mitad del volcado o está entera o no está.
 * - **Las columnas generadas no se vuelcan**: MySQL no deja escribir en ellas y
 *   las recalcula al insertar. Se reconocen por su expresión y no por `EXTRA`,
 *   que también dice `DEFAULT_GENERATED` de una columna con un DEFAULT
 *   calculado: esa sí lleva datos que hay que guardar.
 * - **Los binarios van en hexadecimal**: entre comillas, unos bytes que no son
 *   UTF-8 no se restaurarían bajo `SET NAMES utf8mb4`.
 * - Sólo tablas: la base no tiene vistas, triggers ni rutinas. Si algún día los
 *   tiene, habrá que añadirlos aquí.
 * - **Se comprime sobre la marcha**: en memoria sólo vive el resultado
 *   comprimido y la tabla que se está leyendo.
 */
class DatabaseDumper
{
    /** Filas por INSERT: restaura rápido sin acercarse a `max_allowed_packet`. */
    private const ROWS_PER_INSERT = 200;

    /** Tipos de MySQL cuyo contenido son bytes y no texto. */
    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];

    /**
     * @param Connection $connection Conexión a la base que se vuelca.
     */
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Vuelca todas las tablas de la base.
     *
     * @return string El volcado en gzip.
     *
     * @throws \LogicException Si la conexión ya está dentro de una transacción.
     */
    public function dump(): string
    {
        // Abrir otra haría COMMIT de la que hay, que es de otro: mejor no volcar.
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('No se puede volcar la base desde dentro de una transacción.');
        }

        $gzip = deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 9]);
        $out = deflate_add($gzip, $this->header(), \ZLIB_NO_FLUSH);

        // Sin SESSION: vale sólo para la transacción siguiente, y la conexión la
        // comparten las demás tareas del tick.
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->connection->executeStatement('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            foreach ($this->dumpableColumnsByTable() as $table => $columns) {
                $out .= deflate_add($gzip, $this->tableDefinition($table), \ZLIB_NO_FLUSH);
                foreach ($this->insertsFor($table, $columns) as $insert) {
                    $out .= deflate_add($gzip, $insert, \ZLIB_NO_FLUSH);
                }
            }
        } finally {
            $this->connection->executeStatement('COMMIT');
        }

        return $out . deflate_add($gzip, "SET FOREIGN_KEY_CHECKS=1;\n", \ZLIB_FINISH);
    }

    /**
     * Las tablas de la base con las columnas que se vuelcan, en su orden, y si
     * cada una es binaria. Una sola consulta para todas, no una por tabla.
     *
     * @return array<string, array<string, bool>> Por tabla, si es binaria por columna.
     */
    private function dumpableColumnsByTable(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c, c.DATA_TYPE AS type
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES tb
                 ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE()
                AND tb.TABLE_TYPE = 'BASE TABLE'
                AND COALESCE(c.GENERATION_EXPRESSION, '') = ''
              ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['t']][(string) $row['c']] = \in_array(strtolower((string) $row['type']), self::BINARY_TYPES, true);
        }

        return $columns;
    }

    /** Lo que va antes de la primera tabla. */
    private function header(): string
    {
        return sprintf(
            "-- Volcado de %s generado el %s\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n",
            $this->connection->getDatabase(),
            (new \DateTimeImmutable())->format(\DATE_ATOM),
        );
    }

    /** El DROP y el CREATE de una tabla, tal como los da MySQL. */
    private function tableDefinition(string $table): string
    {
        $create = $this->connection->fetchNumeric('SHOW CREATE TABLE ' . $this->connection->quoteIdentifier($table));

        return sprintf(
            "DROP TABLE IF EXISTS %s;\n%s;\n\n",
            $this->connection->quoteIdentifier($table),
            (string) ($create[1] ?? ''),
        );
    }

    /**
     * Los INSERT con los datos de una tabla, de {@see self::ROWS_PER_INSERT} en
     * {@see self::ROWS_PER_INSERT} filas.
     *
     * @param string             $table   Tabla.
     * @param array<string, bool> $columns Columnas que se vuelcan y si son binarias.
     *
     * @return iterable<string>
     */
    private function insertsFor(string $table, array $columns): iterable
    {
        $quoted = implode(', ', array_map($this->connection->quoteIdentifier(...), array_keys($columns)));
        $binary = array_values($columns);
        $prefix = sprintf('INSERT INTO %s (%s) VALUES ', $this->connection->quoteIdentifier($table), $quoted);
        $values = [];

        foreach ($this->connection->iterateNumeric(sprintf('SELECT %s FROM %s', $quoted, $this->connection->quoteIdentifier($table))) as $row) {
            $values[] = '(' . implode(', ', array_map($this->literal(...), $row, $binary)) . ')';
            if (\count($values) === self::ROWS_PER_INSERT) {
                yield $prefix . implode(",\n", $values) . ";\n";
                $values = [];
            }
        }
        if ($values !== []) {
            yield $prefix . implode(",\n", $values) . ";\n";
        }
    }

    /** Un valor como literal SQL: hexadecimal si la columna es binaria. */
    private function literal(mixed $value, bool $binary): string
    {
        return match (true) {
            $value === null => 'NULL',
            $binary && $value === '' => "''",
            $binary => '0x' . bin2hex((string) $value),
            default => $this->connection->quote((string) $value),
        };
    }
}
