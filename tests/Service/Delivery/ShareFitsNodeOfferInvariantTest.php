<?php

namespace App\Tests\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use App\Service\Delivery\Invariant\ShareFitsNodeOfferInvariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cubre la ley L30 ({@see ShareFitsNodeOfferInvariant}). La REGLA en sí ya la
 * prueba PartnerBasketShareNodeOfferTest sobre la entidad; aquí se prueba lo
 * que es propio del invariante y lo que de verdad puede romperse: que su
 * consulta corre contra el esquema real y que mira las suscripciones que toca
 * — las vigentes — y no el histórico ya cerrado.
 */
class ShareFitsNodeOfferInvariantTest extends KernelTestCase
{
    /**
     * Una cesta semanal viva en un punto que sólo abre cada quince días: el
     * socio no entra en ningún reparto y hasta ahora nada lo decía.
     */
    public function testCazaLaCestaVigenteQueNoCabeEnSuPunto(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $surname = 'Semanal Incoherente ' . uniqid();
        [$nodeId, $groupId, $partnerId, $shareId] = $this->seed($em, BasketShare::IDS_WEEKLY[0], $surname);

        $violations = $this->check();

        $this->assertNotEmpty(
            $this->matching($violations, $partnerId),
            'La ley debe reportar la cesta semanal viva en un punto quincenal.'
        );

        $this->cleanUp($em, $nodeId, $groupId, $partnerId, $shareId);
    }

    /**
     * El histórico no se juzga con las reglas de hoy: un tramo ya cerrado pudo
     * ser perfectamente válido con la cadencia que el punto tenía entonces.
     */
    public function testIgnoraElHistoricoYaCerrado(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $surname = 'Semanal Cerrado ' . uniqid();
        [$nodeId, $groupId, $partnerId, $shareId] = $this->seed($em, BasketShare::IDS_WEEKLY[0], $surname);

        $share = $em->getRepository(PartnerBasketShare::class)->find($shareId);
        $share->setIsActive(false);
        $share->setEndDate(new \DateTime('2020-01-31'));
        $em->flush();

        $this->assertSame(
            [],
            $this->matching($this->check(), $partnerId),
            'Un tramo cerrado del histórico no es una violación.'
        );

        $this->cleanUp($em, $nodeId, $groupId, $partnerId, $shareId);
    }

    /**
     * Y la cesta que sí cabe no genera ruido: en un punto quincenal el turno lo
     * fija el propio punto, así que una quincenal sin turno es correcta.
     */
    public function testNoReportaLaCestaQueCabe(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $surname = 'Quincenal Correcta ' . uniqid();
        [$nodeId, $groupId, $partnerId, $shareId] = $this->seed($em, BasketShare::ID_BIWEEKLY, $surname);

        $this->assertSame([], $this->matching($this->check(), $partnerId));

        $this->cleanUp($em, $nodeId, $groupId, $partnerId, $shareId);
    }

    /**
     * Violaciones de la ley sobre el estado actual. La ley se instancia a mano
     * (son dos dependencias) en vez de pedirla al contenedor: como servicio
     * sólo la consume el iterador de leyes por tag, y ahí el compilador puede
     * inlinearla y dejarla sin id que pedir. Que el cableado por tag funciona
     * lo cubre el smoke de VerifyDeliveryInvariantsCommandTest.
     *
     * @return list<string>
     */
    private function check(): array
    {
        $invariant = new ShareFitsNodeOfferInvariant(
            static::getContainer()->get('doctrine')->getManager(),
            static::getContainer()->get('validator'),
        );

        return $invariant->check(new \DateTimeImmutable('today'));
    }

    /**
     * Las violaciones que hablan del socio sembrado, para no depender de lo que
     * las fixtures tengan sucio por su cuenta.
     *
     * Se busca por ID y no por apellido: el nombre se pinta normalizado a title
     * case (Partner::getLegalName) y mb_convert_case trata los dígitos como
     * separadores de palabra, así que la caja del uniqid no es predecible.
     *
     * @param list<string> $violations
     * @return list<string>
     */
    private function matching(array $violations, int $partnerId): array
    {
        $needle = sprintf('(%d)', $partnerId);

        return array_values(array_filter(
            $violations,
            static fn (string $line): bool => str_contains($line, $needle)
        ));
    }

    /**
     * Punto quincenal + grupo + socio + cesta activa, todo nuevo y desechable.
     *
     * @return int[] [nodeId, groupId, partnerId, shareId]
     */
    private function seed(EntityManagerInterface $em, int $basketShareId, string $surname): array
    {
        $node = (new Node())
            ->setName('TEST Punto quincenal ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_BIWEEKLY)
            ->setAnchorDate(new \DateTimeImmutable('2026-09-04'));
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo ' . uniqid())
            ->setColor('#abcabc')
            ->setNode($node);
        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname($surname)
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($group);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find($basketShareId));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));

        $em->persist($node);
        $em->persist($group);
        $em->persist($partner);
        $em->persist($share);
        $em->flush();

        return [$node->getId(), $group->getId(), $partner->getId(), $share->getId()];
    }

    private function cleanUp(EntityManagerInterface $em, int $nodeId, int $groupId, int $partnerId, int $shareId): void
    {
        $em->remove($em->getRepository(PartnerBasketShare::class)->find($shareId));
        $em->flush();
        $em->remove($em->getRepository(Partner::class)->find($partnerId));
        $em->flush();
        $em->remove($em->getRepository(WeeklyBasketGroup::class)->find($groupId));
        $em->remove($em->getRepository(Node::class)->find($nodeId));
        $em->flush();
    }
}
