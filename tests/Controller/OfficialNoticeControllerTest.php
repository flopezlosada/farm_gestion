<?php

namespace App\Tests\Controller;

use App\Entity\OfficialNotice;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La lista de notificaciones oficiales: la pendiente sale con su botón, «Revisada en
 * la sede» deja quién y cuándo, y marcarla otra vez no cambia a quien lo hizo.
 */
class OfficialNoticeControllerTest extends AbstractAuthenticatedTest
{
    /** @var list<int> Avisos creados, para borrarlos al acabar. */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ($this->created as $id) {
            if (($row = $em->find(OfficialNotice::class, $id)) !== null) {
                $em->remove($row);
            }
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    /** Pendiente → revisada por quien pulsa; la segunda vez no cambia nada. */
    public function testRevisadaEnLaSedeDejaQuienYCuando(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $notice = $this->notice();

        $crawler = $client->request('GET', '/gestion/accounting/official-notices/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'no uses el enlace del correo');
        $form = $crawler->filter(sprintf('form[action="/gestion/accounting/official-notices/%d/reviewed"]', $notice->getId()))->form();

        $client->submit($form);
        $this->assertResponseRedirects('/gestion/accounting/official-notices/');

        $this->em()->clear();
        $reviewed = $this->em()->find(OfficialNotice::class, $notice->getId());
        $this->assertTrue($reviewed->isReviewed());
        $this->assertSame('admin', $reviewed->getReviewedBy()?->getUsername());
        $at = $reviewed->getReviewedAt();

        $client->submit($form);
        $this->assertResponseRedirects('/gestion/accounting/official-notices/');
        $this->em()->clear();
        $this->assertEquals($at, $this->em()->find(OfficialNotice::class, $notice->getId())->getReviewedAt(), 'La segunda vez no cambia nada.');
    }

    /** Sin el token del formulario no se marca. */
    public function testSinTokenNoSeMarca(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $notice = $this->notice();

        $client->request('POST', sprintf('/gestion/accounting/official-notices/%d/reviewed', $notice->getId()), ['_token' => 'falso']);
        $this->assertResponseRedirects('/gestion/accounting/official-notices/');

        $this->em()->clear();
        $this->assertFalse($this->em()->find(OfficialNotice::class, $notice->getId())->isReviewed());
    }

    /** Un aviso de la DEHú sin revisar. */
    private function notice(): OfficialNotice
    {
        $notice = new OfficialNotice(hash('sha256', uniqid('test-', true)), 'DEHú', 'noreply.dehu@correo.gob.es', new \DateTimeImmutable('2026-10-14 09:12'));
        $this->em()->persist($notice);
        $this->em()->flush();
        $this->created[] = (int) $notice->getId();

        return $notice;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
