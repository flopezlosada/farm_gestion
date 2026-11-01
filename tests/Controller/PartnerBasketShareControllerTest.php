<?php

namespace App\Tests\Controller;

use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;

/**
 * Smoke test del listado de cestas por socix y del cambio de cesta con histórico.
 */
class PartnerBasketShareControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/partner/basket/share/ con admin logueado devuelve 200.
     */
    public function testPartnerBasketShareListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/basket/share/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET de la pantalla de cambio de cesta (con histórico) de una cesta activa
     * devuelve 200 y muestra el copy "Cambiar la cesta" (no "modalidad").
     */
    public function testChangeBasketFormRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/partner/basket/share/' . $this->findActiveShareId() . '/change-modality');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Cambiar la cesta');
    }

    /**
     * GET del formulario de corrección in situ (edit) devuelve 200 — cubre el
     * wiring de la action (inyección de WeeklyBasketGenerator para la cascada
     * de reconciliación sobre semanas ya generadas).
     */
    public function testEditFormRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/partner/basket/share/' . $this->findActiveShareId() . '/edit');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET del listado de cestas activas devuelve 200 y pinta la cabecera con
     * el total — wiring de la action tras retirar los ifs con ids hardcodeados
     * que contaban mal mensual y compartidas. La aritmética del total la cubre
     * BasketShareEquivalenceTest (asertar aquí el número exacto recalculándolo
     * con el mismo código sería tautológico).
     */
    public function testActiveListShowsCatalogTotal(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/basket/share/status/1');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Total actual:');
    }

    /**
     * GET del formulario de finalizar cesta (al que ahora se llega por enlace
     * directo desde la ficha, sin diálogo intermedio) devuelve 200 y pide la
     * fecha de fin.
     */
    public function testFinalizeFormRenders(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/partner/basket/share/' . $this->findActiveShareId() . '/finalize');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSelectorTextContains('body', 'Fecha de fin');
    }

    /**
     * Cambiar la cesta de un «Solo huevos» con huevos QUINCENALES en un punto
     * semanal conserva su turno. Antes el turno sólo se guardaba si la
     * MODALIDAD lo usaba (quincenales y mensuales), así que el cambio lo
     * borraba; y como el motor de huevos decide los viernes por el turno, la
     * socia dejaba de tener huevos en todos los repartos sin ningún aviso.
     */
    public function testChangeModalityKeepsTurnForEggOnlyWithBiweeklyEggs(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $node = (new Node())
            ->setName('TEST Nodo Semanal ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo Semanal ' . uniqid())
            ->setColor('#cccccc')
            ->setNode($node);
        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname('Solo Huevos Quincenales ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($group);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->find(BasketShare::class, BasketShare::ID_ONLY_EGG));
        $share->setEggAmount($em->getRepository(EggAmount::class)->findOneBy([]));
        $share->setEggPeriod($em->find(EggPeriod::class, EggPeriod::ID_BIWEEKLY));
        $share->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_B);
        $share->setStartDate(new \DateTime('2026-01-02'));
        $share->setIsActive(true);
        $share->setAmount(1);

        foreach ([$node, $group, $partner, $share] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        [$partnerId, $groupId, $nodeId, $shareId] = [$partner->getId(), $group->getId(), $node->getId(), $share->getId()];

        try {
            $crawler = $client->request('GET', sprintf('/gestion/partner/basket/share/%d/change-modality', $shareId));
            $this->assertSame(200, $client->getResponse()->getStatusCode());

            $form = $crawler->selectButton('Aplicar cambio de cesta')->form();
            $this->assertSame(
                PartnerBasketShare::DELIVERY_GROUP_B,
                $form['partner_basket_share[deliveryGroup]']->getValue(),
                'El turno de la cesta vigente debe llegar precargado.'
            );
            $form['partner_basket_share[start_date]'] = '2030-01-04';
            $client->submit($form);
            $this->assertTrue($client->getResponse()->isRedirect(), 'El cambio válido debe volver a la ficha.');

            $em->clear();
            $latest = $em->getRepository(PartnerBasketShare::class)->findOneBy(
                ['partner' => $partnerId],
                ['id' => 'DESC'],
            );
            $this->assertNotSame($shareId, $latest->getId(), 'El cambio debe abrir una cesta nueva.');
            $this->assertSame(
                PartnerBasketShare::DELIVERY_GROUP_B,
                $latest->getDeliveryGroup(),
                'Con huevos quincenales en un punto semanal, el turno decide qué viernes van los huevos: no puede perderse.'
            );
        } finally {
            $conn = $em->getConnection();
            foreach (['partner_event', 'weekly_basket', 'partner_basket_share'] as $table) {
                $conn->executeStatement(sprintf('DELETE FROM %s WHERE partner_id = ?', $table), [$partnerId]);
            }
            $conn->executeStatement('DELETE FROM partner WHERE id = ?', [$partnerId]);
            $conn->executeStatement('DELETE FROM weekly_basket_group WHERE id = ?', [$groupId]);
            $conn->executeStatement('DELETE FROM node WHERE id = ?', [$nodeId]);
        }
    }

    /**
     * Id de una PBS activa cualquiera de las fixtures.
     *
     * @return int
     */
    private function findActiveShareId(): int
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        foreach ($em->getRepository(Partner::class)->findAll() as $partner) {
            if ($partner->hasActiveBasket()) {
                return $partner->getActiveBasket()->getId();
            }
        }

        $this->fail('Fixtures sin socix con cesta activa.');
    }
}
