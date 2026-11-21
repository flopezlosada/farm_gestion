<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use App\Service\Accounting\Norma43\Norma43Movement;
use App\Service\Accounting\Norma43\TransferPayer;
use PHPUnit\Framework\TestCase;

/**
 * Quién ordena una transferencia recibida, en los formatos que traen los extractos
 * reales de Fiare y La Caixa. Nombres inventados.
 */
class TransferPayerTest extends TestCase
{
    public function testFiareLoPoneEnLaPrimeraLinea(): void
    {
        $this->assertSame('LUCIA ORTEGA PEREZ', TransferPayer::fromDetails('TRF. LUCIA ORTEGA PEREZ'));
    }

    public function testLaCaixaLoPoneEnLaSegundaConLaEntidadDelante(): void
    {
        $this->assertSame('LUCIA ORTEGA PEREZ', TransferPayer::fromDetails("TRANSF. A SU FAVOR\n14650100-LUCIA ORTEGA PEREZ"));
    }

    /** La entidad también llega en dos grupos de cuatro cifras separados por un espacio. */
    public function testLaEntidadDeLaCaixaConEspacioNoSeQuedaPegadaAlNombre(): void
    {
        $this->assertSame('JOAQUIN MANUEL GRIJOTA', TransferPayer::fromDetails("TRANSF. A SU FAVOR\n1491 0001-JOAQUIN MANUEL GRIJOTA"));
        $this->assertSame('BAUTISTA CARRASCOSA IN', TransferPayer::fromDetails("TRANSF. A SU FAVOR\r\n0049 0054-BAUTISTA CARRASCOSA IN"));
    }

    /** Fiare escribe a veces el concepto en minúsculas, con el código de operación en la 2ª línea. */
    public function testFiareEnMinusculasTambienTieneOrdenante(): void
    {
        $this->assertSame('baonza del moral diego', TransferPayer::fromDetails("trf. baonza del moral diego\n8522"));
    }

    /** Los dobles espacios del banco se quedan en uno: es como se compara. */
    public function testLaTransferenciaInmediataDeLaCaixaNoLlevaEntidad(): void
    {
        $this->assertSame('DE LA ESPINOSA SALCEDO', TransferPayer::fromDetails("TRANSFER INMEDIATA\nDE LA  ESPINOSA SALCEDO"));
    }

    public function testLoQueNoEsUnaTransferenciaRecibidaNoTieneOrdenante(): void
    {
        $this->assertNull(TransferPayer::fromDetails('TRASPASO'));
        $this->assertNull(TransferPayer::fromDetails('AB.REM.20260013579'));
        $this->assertNull(TransferPayer::fromDetails("TRANSF. A SU FAVOR\n"), 'Sin nombre no hay ordenante.');
        $this->assertNull(TransferPayer::fromDetails(''));
    }

    /** Un cargo con el mismo texto no es una transferencia recibida. */
    public function testUnCargoNoTieneOrdenante(): void
    {
        $this->assertSame('LUCIA ORTEGA', TransferPayer::of($this->line(3000, ['TRF. LUCIA ORTEGA'])));
        $this->assertNull(TransferPayer::of($this->line(-3000, ['TRF. LUCIA ORTEGA'])));
        $this->assertNull(TransferPayer::of($this->line(3000, [])), 'Sin texto del banco.');
    }

    /**
     * @param list<string> $texts
     */
    private function line(int $cents, array $texts): BankStatementLine
    {
        $account = new FinancialAccount();
        (new \ReflectionProperty($account, 'id'))->setValue($account, 1);
        $day = new \DateTimeImmutable('2026-11-05');

        return new BankStatementLine($account, new Norma43Movement($day, $day, '02', '000', $cents, '', '', '', $texts), 1);
    }

    /**
     * El prefijo de cada formato es lo que la consulta usa para descartar movimientos:
     * todo detalle con ordenante tiene que contener alguno, o la consulta lo perdería.
     */
    public function testTodoDetalleConOrdenanteContieneAlgunPrefijoDeLaConsulta(): void
    {
        $details = [
            "TRF. LUCIA ORTEGA PEREZ",
            "TRANSF. A SU FAVOR\n2100-LUCIA ORTEGA PE",
            "TRANSFER INMEDIATA\nLUCIA ORTEGA PEREZ",
            "  TRF. CON ESPACIOS DELANTE",
        ];
        foreach ($details as $text) {
            $this->assertNotNull(TransferPayer::fromDetails($text), $text);
            $this->assertNotEmpty(array_filter(TransferPayer::detailPrefixes(), static fn (string $p): bool => stripos($text, $p) !== false), $text);
        }
    }
}
