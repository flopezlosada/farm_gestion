<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\Setting;
use App\Entity\SharedBasketChangeRequest;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La pantalla de la cesta compartida ante un cambio de modalidad acordado.
 *
 * EL FALLO QUE CUBRE: un cambio de modalidad se aplica semanas antes de entrar en
 * vigor (entra a principio de mes), y la pantalla preguntaba por la modalidad que el
 * hogar recoge HOY. Resultado: administración lo aplicaba, los dos hogares seguían
 * leyendo «falta que administración lo aplique» durante semanas y, de propina, no
 * podían pedir ningún otro cambio. Lo que decide si un acuerdo sigue vivo es la
 * ÚLTIMA cesta contratada, no la que se recoge esta semana.
 */
class PanelSharedBasketAgreedTest extends AbstractPartnerAuthenticatedTest
{
    private const PEER_EMAIL = 'peer@csavega.local';

    public function testAcuerdoSinAplicarSigueEsperandoAAdministracion(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->enableSelfService($client);
        $this->pairHouseholds($client);
        $this->agreeOnMonthly($client);

        $client->request('GET', '/panel/shared-basket');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Acordado, en manos de administración', $html);
        // Y mientras tanto no se ofrece proponer otro cambio encima.
        $this->assertStringNotContainsString('Cambiar la modalidad de la cesta', $html);
    }

    public function testUnaVezAplicadoElCartelDesapareceYDiceDesdeCuando(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->enableSelfService($client);
        $this->pairHouseholds($client);
        $this->agreeOnMonthly($client);
        $this->applyMonthlyFrom($client, new \DateTime('+20 days'));

        $client->request('GET', '/panel/shared-basket');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringNotContainsString(
            'Acordado, en manos de administración',
            $html,
            'Aplicado con fecha futura sigue siendo aplicado: el acuerdo ya no está en manos de nadie.',
        );
        $this->assertStringContainsString('Vuestra cesta cambia el', $html);
        $this->assertStringContainsString('Mensual compartida', $html);
        // Y vuelven a poder proponer.
        $this->assertStringContainsString('Cambiar la modalidad de la cesta', $html);
        // Un `{#` mal cerrado en la plantilla se cuela como texto: sólo se ve por este
        // camino, con el acuerdo ya resuelto, que es justo el que nadie miraba.
        $this->assertStringNotContainsString('#}', $html);
    }

    /**
     * Empareja al socix de las fixtures con el otro partner y les pone a los dos la
     * misma modalidad compartida, que es como viven las parejas reales.
     *
     * @param KernelBrowser $client Cliente con sesión abierta.
     */
    private function pairHouseholds(KernelBrowser $client): void
    {
        $em = $this->em($client);
        $shared = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY_SHARED);

        [$socix, $peer] = $this->households($client);
        $socix->setSharePartner($peer);
        $peer->setSharePartner($socix);

        foreach ([$socix, $peer] as $partner) {
            $share = $this->currentShare($client, $partner);
            $share->setBasketShare($shared);
            $share->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_A);
        }

        $em->flush();
    }

    /**
     * Deja una petición de modalidad ACEPTADA entre los dos hogares: han dicho que sí
     * a la mensual compartida y esperan a administración.
     *
     * @param KernelBrowser $client Cliente con sesión abierta.
     */
    private function agreeOnMonthly(KernelBrowser $client): void
    {
        $em = $this->em($client);
        [$socix, $peer] = $this->households($client);

        $request = new SharedBasketChangeRequest($peer, $socix, SharedBasketChangeRequest::KIND_MODALITY);
        $request->setPayload(['basket_share_id' => BasketShare::ID_MONTHLY_SHARED]);
        $request->accept();

        $em->persist($request);
        $em->flush();
    }

    /**
     * Hace lo que haría administración: cierra la cesta vigente de los dos hogares y
     * les abre la mensual compartida a partir de una fecha futura.
     *
     * @param KernelBrowser      $client Cliente con sesión abierta.
     * @param \DateTimeInterface $from   Fecha efectiva del cambio.
     */
    private function applyMonthlyFrom(KernelBrowser $client, \DateTimeInterface $from): void
    {
        $em = $this->em($client);
        $monthly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_MONTHLY_SHARED);

        foreach ($this->households($client) as $partner) {
            $old = $this->currentShare($client, $partner);
            $old->setEndDate(\DateTime::createFromInterface($from)->modify('-1 day'));

            $new = new PartnerBasketShare();
            $new->setPartner($partner);
            $new->setBasketShare($monthly);
            $new->setIsActive(true);
            $new->setAmount(1);
            $new->setVegetablesBasketAmount(1);
            $new->setMonthPrice($monthly->getMonthPrice());
            $new->setEggMonthPrice('0.00');
            $new->setDayMonthOrder(1);
            $new->setStartDate(\DateTime::createFromInterface($from));
            $em->persist($new);
        }

        $em->flush();
    }

    /**
     * Los dos hogares: el socix con cuenta de las fixtures y su pareja.
     *
     * @param KernelBrowser $client Cliente con sesión abierta.
     *
     * @return array{0: Partner, 1: Partner} Socix y pareja.
     */
    private function households(KernelBrowser $client): array
    {
        $partners = $this->em($client)->getRepository(Partner::class);

        return [
            $partners->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]),
            $partners->findOneBy(['email' => self::PEER_EMAIL]),
        ];
    }

    /**
     * La cesta que ese hogar tiene hoy en vigor.
     *
     * @param KernelBrowser $client  Cliente con sesión abierta.
     * @param Partner       $partner Hogar.
     *
     * @return PartnerBasketShare Su cesta vigente.
     */
    private function currentShare(KernelBrowser $client, Partner $partner): PartnerBasketShare
    {
        return $this->em($client)->getRepository(PartnerBasketShare::class)
            ->findOneBy(['partner' => $partner, 'end_date' => null], ['id' => 'ASC']);
    }

    /**
     * @param KernelBrowser $client Cliente con sesión abierta.
     *
     * @return EntityManagerInterface El gestor de entidades del contenedor del test.
     */
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * El panel del socix vive tras un feature-flag apagado por defecto.
     *
     * @param KernelBrowser $client Cliente con sesión abierta.
     */
    private function enableSelfService(KernelBrowser $client): void
    {
        $client->getContainer()->get(AppSettings::class)
            ->setBool(AppSettings::FEATURE_PARTNER_SELFSERVICE, true);
    }

    /**
     * Deshace el escenario: el emparejamiento, las cestas nuevas y las peticiones son
     * de este test y ningún otro cuenta con ellos.
     */
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $emails = ['socix' => PartnerUserFixtures::USER_SOCIX_EMAIL, 'peer' => self::PEER_EMAIL];

        $conn->executeStatement('DELETE FROM shared_basket_change_request');
        $conn->executeStatement(
            'DELETE pbs FROM partner_basket_share pbs
                INNER JOIN partner p ON p.id = pbs.partner_id
                WHERE p.email IN (:socix, :peer) AND pbs.start_date > CURDATE()',
            $emails,
        );
        $conn->executeStatement(
            'UPDATE partner_basket_share pbs
                INNER JOIN partner p ON p.id = pbs.partner_id
                SET pbs.end_date = NULL, pbs.basket_share_id = :biweekly
                WHERE p.email IN (:socix, :peer)',
            $emails + ['biweekly' => BasketShare::ID_BIWEEKLY],
        );
        $conn->executeStatement(
            'UPDATE partner SET share_partner_id = NULL WHERE email IN (:socix, :peer)',
            $emails,
        );

        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }
}
