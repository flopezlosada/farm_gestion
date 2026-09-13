<?php

namespace App\Tests\Entity;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use PHPUnit\Framework\TestCase;

/**
 * La lógica que decide QUÉ FECHA se vigila. Es lo más delicado del modelo: de
 * elegir mal el periodo depende que el sistema avise de algo ya renovado o que
 * calle sobre algo caducado.
 */
class ObligationTest extends TestCase
{
    /**
     * Sin periodos no hay nada que vigilar, y eso NO es estar caducada: es una
     * ficha a medias. Pintarla en rojo junto a lo que sí ha caducado enseña a
     * no mirar los rojos.
     */
    public function testSinPeriodosNoTieneFechaYSuEstadoEsDesconocido(): void
    {
        $obligation = new Obligation();

        $this->assertNull($obligation->expiresOn());
        $this->assertNull($obligation->daysLeft());
        $this->assertSame(Obligation::STATE_UNKNOWN, $obligation->state(90));
    }

    /**
     * El caso que motiva todo el modelo: cuando la renovación se firma ANTES de
     * que caduque la anterior conviven dos periodos válidos, y el que manda es
     * el nuevo. Con la regla intuitiva ("el que contiene a hoy") el sistema
     * seguiría avisando del viejo y la renovación ya hecha no serviría de nada.
     */
    public function testConDosPeriodosVigentesManaElDeFechaMasLejana(): void
    {
        $obligation = new Obligation();
        $obligation->addTerm($this->term('2025-01-01', '2026-12-31'));
        $obligation->addTerm($this->term('2026-06-01', '2027-12-31'));

        $this->assertSame('2027-12-31', $obligation->expiresOn()->format('Y-m-d'));
    }

    /**
     * Y también cuando la renovación llega DESPUÉS de haber caducado, que es el
     * caso real de la junta directiva: el periodo nuevo manda aunque el viejo
     * se anotara más tarde.
     */
    public function testElOrdenDeAnotacionNoDecideCualManda(): void
    {
        $obligation = new Obligation();
        $obligation->addTerm($this->term('2024-01-01', '2027-01-01'));
        $obligation->addTerm($this->term('2021-01-01', '2024-01-01'));

        $this->assertSame('2027-01-01', $obligation->expiresOn()->format('Y-m-d'));
    }

    /**
     * Los días que quedan no dependen de la hora a la que corra la tarea: a las
     * 7:00 y a las 23:00 del mismo día tienen que dar lo mismo, o el escalón se
     * cruzaría dos veces o ninguna.
     */
    public function testLosDiasQueQuedanNoDependenDeLaHora(): void
    {
        $obligation = new Obligation();
        $obligation->addTerm($this->term(null, '2026-10-01'));

        $manana = new \DateTimeImmutable('2026-09-01 07:00:00');
        $noche = new \DateTimeImmutable('2026-09-01 23:30:00');

        $this->assertSame(30, $obligation->daysLeft($manana));
        $this->assertSame(30, $obligation->daysLeft($noche));
    }

    /**
     * El día del vencimiento quedan cero días —no es todavía "caducada"—, y el
     * día siguiente son -1.
     */
    public function testElDiaDelVencimientoQuedanCeroDias(): void
    {
        $obligation = new Obligation();
        $obligation->addTerm($this->term(null, '2026-09-12'));

        $this->assertSame(0, $obligation->daysLeft(new \DateTimeImmutable('2026-09-12')));
        $this->assertSame(-1, $obligation->daysLeft(new \DateTimeImmutable('2026-09-13')));
    }

    /**
     * Frontera del estado: justo en el plazo de aviso ya está "en aviso", y un
     * día antes de entrar todavía está en regla.
     */
    public function testLaFronteraDelPlazoDeAvisoEsInclusiva(): void
    {
        $obligation = new Obligation();
        $obligation->addTerm($this->term(null, '2026-12-01'));

        // 90 días justos antes.
        $this->assertSame(Obligation::STATE_DUE, $obligation->state(90, new \DateTimeImmutable('2026-09-02')));
        // 91 días antes: todavía no.
        $this->assertSame(Obligation::STATE_VALID, $obligation->state(90, new \DateTimeImmutable('2026-09-01')));
        // Pasada la fecha.
        $this->assertSame(Obligation::STATE_EXPIRED, $obligation->state(90, new \DateTimeImmutable('2026-12-02')));
    }

    /**
     * Con una antelación exigida por el documento, deja de estar «en regla»
     * mucho antes: a 200 días de un contrato con un año de preaviso ya hay que
     * ponerse, aunque el plazo general sean 90 días.
     */
    public function testLaAntelacionDelDocumentoAdelantaElEstado(): void
    {
        $obligation = new Obligation();
        $obligation->addTerm($this->term(null, '2027-04-20'));
        $hoy = new \DateTimeImmutable('2026-09-12');

        $this->assertSame(Obligation::STATE_VALID, $obligation->state(90, $hoy));

        $obligation->setLeadDays(365);
        $this->assertSame(Obligation::STATE_DUE, $obligation->state(90, $hoy));
    }

    /**
     * Construye un periodo de validez.
     *
     * @param string|null $startsOn Inicio en formato Y-m-d, o null si no consta.
     * @param string      $endsOn   Fin en formato Y-m-d.
     */
    private function term(?string $startsOn, string $endsOn): ObligationTerm
    {
        $term = new ObligationTerm();
        $term->setEndsOn(new \DateTimeImmutable($endsOn));

        if ($startsOn !== null) {
            $term->setStartsOn(new \DateTimeImmutable($startsOn));
        }

        return $term;
    }
}
