<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Cubre {@see PartnerBasketShare::validateAgainstNodeOffer}: una cesta sólo
 * puede pedir lo que su punto de recogida ofrece.
 *
 * Regresión del caso El Berrueco (2026-08-26): dos socios dados de alta con
 * cesta mensual y la posición del mes en blanco quedaron INVISIBLES en el
 * listado de reparto desde el primer día, porque la query de mensuales filtra
 * por `day_month_order IN (...)` y NULL no casa con nada. El formulario lo
 * permitía y nadie se enteró hasta que administración echó de menos el listado.
 */
class PartnerBasketShareNodeOfferTest extends TestCase
{
    public function testMensualSinPosicionDelMesViola(): void
    {
        $share = $this->share(BasketShare::ID_MONTHLY, $this->node(Node::CADENCE_BIWEEKLY));

        $this->assertSame(['dayMonthOrder'], $this->violationPaths($share));
    }

    public function testMensualSinPuntoDeRecogidaTambienExigePosicion(): void
    {
        // Socio legacy sin grupo: no hay punto contra el que contrastar, pero
        // una mensual sin posición sigue sin poder entrar en ningún reparto.
        $share = $this->share(BasketShare::ID_MONTHLY, null);

        $this->assertSame(['dayMonthOrder'], $this->violationPaths($share));
    }

    public function testMensualEnPosicionQueElPuntoQuincenalNoAbreSiempreViola(): void
    {
        // Un punto quincenal sólo abre tres veces en los meses largos: la "3ª
        // entrega" dejaría al socio sin cesta la mayoría de los meses.
        $share = $this->share(BasketShare::ID_MONTHLY, $this->node(Node::CADENCE_BIWEEKLY));
        $share->setDayMonthOrder(3);

        $this->assertSame(['dayMonthOrder'], $this->violationPaths($share));
    }

    public function testMensualEnPosicionQueElPuntoQuincenalSiAbreEsValida(): void
    {
        $share = $this->share(BasketShare::ID_MONTHLY, $this->node(Node::CADENCE_BIWEEKLY));
        $share->setDayMonthOrder(1);

        $this->assertSame([], $this->violationPaths($share));
    }

    public function testMensualEnTerceraEntregaEsValidaEnPuntoSemanal(): void
    {
        // El punto semanal sí tiene 3ª entrega todos los meses.
        $share = $this->share(BasketShare::ID_MONTHLY, $this->node(Node::CADENCE_WEEKLY));
        $share->setDayMonthOrder(3);

        $this->assertSame([], $this->violationPaths($share));
    }

    public function testUltimaEntregaEsValidaEnCualquierPunto(): void
    {
        $share = $this->share(BasketShare::ID_MONTHLY, $this->node(Node::CADENCE_BIWEEKLY));
        $share->setDayMonthOrder(PartnerBasketShare::DAY_MONTH_ORDER_LAST);

        $this->assertSame([], $this->violationPaths($share));
    }

    public function testCestaSemanalNoCabeEnPuntoQuincenal(): void
    {
        $share = $this->share(BasketShare::IDS_WEEKLY[0], $this->node(Node::CADENCE_BIWEEKLY));

        $this->assertSame(['basket_share'], $this->violationPaths($share));
    }

    public function testPuntoMensualSoloAdmiteCestasMensuales(): void
    {
        $node = $this->node(Node::CADENCE_MONTHLY);
        $node->setMonthlyWeek(2);
        $share = $this->share(BasketShare::ID_BIWEEKLY, $node);

        $this->assertContains('basket_share', $this->violationPaths($share));
    }

    public function testPuntoMensualExigeSuPropiaSemana(): void
    {
        $node = $this->node(Node::CADENCE_MONTHLY);
        $node->setMonthlyWeek(2);

        $enSuSemana = $this->share(BasketShare::ID_MONTHLY, $node);
        $enSuSemana->setDayMonthOrder(2);
        $this->assertSame([], $this->violationPaths($enSuSemana));

        $enOtra = $this->share(BasketShare::ID_MONTHLY, $node);
        $enOtra->setDayMonthOrder(1);
        $this->assertSame(['dayMonthOrder'], $this->violationPaths($enOtra));
    }

    public function testQuincenalSinTurnoVioloEnPuntoSemanal(): void
    {
        // Sin turno A/B una quincenal no entra en ninguna cohorte y cae de los
        // listados: el mismo fallo silencioso que la mensual sin posición.
        $share = $this->share(BasketShare::ID_BIWEEKLY, $this->node(Node::CADENCE_WEEKLY));

        $this->assertSame(['deliveryGroup'], $this->violationPaths($share));
    }

    public function testQuincenalConTurnoEsValidaEnPuntoSemanal(): void
    {
        $share = $this->share(BasketShare::ID_BIWEEKLY, $this->node(Node::CADENCE_WEEKLY));
        $share->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_A);

        $this->assertSame([], $this->violationPaths($share));
    }

    public function testQuincenalSinTurnoEsValidaEnPuntoQuincenal(): void
    {
        // Ahí el turno lo fija el propio punto (Cascorro, Midori): pedirlo
        // sobraría, y de hecho los controllers lo anulan.
        $share = $this->share(BasketShare::ID_BIWEEKLY, $this->node(Node::CADENCE_BIWEEKLY));

        $this->assertSame([], $this->violationPaths($share));
    }

    public function testSemanalNoNecesitaNiTurnoNiPosicion(): void
    {
        $share = $this->share(BasketShare::IDS_WEEKLY[0], $this->node(Node::CADENCE_WEEKLY));

        $this->assertSame([], $this->violationPaths($share));
    }

    /**
     * Rutas de las violaciones que emite la regla bajo prueba, en orden. Se
     * filtran las del resto de constraints de la entidad (amount, etc.) para
     * que el test hable sólo de lo suyo.
     *
     * @return string[]
     */
    private function violationPaths(PartnerBasketShare $share): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $paths = [];
        foreach ($validator->validate($share) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return array_values(array_intersect($paths, ['basket_share', 'dayMonthOrder', 'deliveryGroup']));
    }

    /**
     * @param string $cadence Una de Node::CADENCE_*.
     */
    private function node(string $cadence): Node
    {
        $node = (new Node())
            ->setName('Punto de prueba')
            ->setDeliveryWeekday(5)
            ->setCadence($cadence);

        if ($cadence === Node::CADENCE_BIWEEKLY) {
            $node->setAnchorDate(new \DateTimeImmutable('2026-09-04'));
        }

        return $node;
    }

    /**
     * Cesta de un socio del punto dado (o sin punto, si es null), con lo mínimo
     * para que el resto de constraints de la entidad no metan ruido.
     */
    private function share(int $basketShareId, ?Node $node): PartnerBasketShare
    {
        $basketShare = new BasketShare();
        $basketShare->setName('Modalidad ' . $basketShareId);
        $ref = new \ReflectionProperty(BasketShare::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($basketShare, $basketShareId);

        $partner = new Partner();
        if ($node !== null) {
            $group = (new WeeklyBasketGroup())->setName('Grupo de prueba')->setNode($node);
            $partner->setWeeklyBasketGroup($group);
        }

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($basketShare);
        $share->setAmount(1);

        return $share;
    }
}
