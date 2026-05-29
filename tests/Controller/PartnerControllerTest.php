<?php

use App\Entity\Partner;

/**
 * Smoke test del listado y la edición de socixs.
 */
class PartnerControllerTest extends AbstractAuthenticatedTest
{
    /**
     * GET /gestion/partner/ con admin logueado devuelve 200.
     */
    public function testPartnerListReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * GET del edit de un socio SIN fecha de inscripción devuelve 200.
     *
     * Regresión: el edit hacía getInscriptionDate()->format() sin comprobar
     * null, y además metía un string en el DateType del form, así que petaba
     * con 500 tanto si la fecha era null como si no. Autocontenido: crea un
     * socio sin fecha, abre su edit y lo borra.
     */
    public function testEditPartnerWithoutInscriptionDateReturnsOk(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $partner = (new Partner())
            ->setname('TEST')
            ->setSurname('Sin Fecha ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $em->persist($partner);
        $em->flush();
        $partnerId = $partner->getId();

        $client->request('GET', sprintf('/gestion/partner/%d/edit', $partnerId));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->remove($em->getRepository(Partner::class)->find($partnerId));
        $em->flush();
    }
}
