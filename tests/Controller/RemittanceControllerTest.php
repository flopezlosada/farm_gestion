<?php

namespace App\Tests\Controller;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\Setting;
use App\Service\AppSettings;
use App\Service\Accounting\Remittance\RemittanceSheet;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Las remesas por HTTP: quién entra, y el circuito entero de un mes — importar la
 * remesa enviada, preparar la siguiente, ver lo que falta, arreglarla, descargar el
 * Excel, darla por enviada y que ya no se pueda tocar.
 *
 * Los periodos van en 2096 para no cruzarse con nada de la base de tests.
 */
class RemittanceControllerTest extends AbstractAuthenticatedTest
{
    /** Un IBAN de ejemplo válido y otro con el dígito de control mal. */
    private const GOOD_IBAN = 'ES9121000418450200051332';
    private const BAD_IBAN = 'ES9121000418450200051333';

    private ?string $file = null;

    /** El socix al que un test le pone mandato, para quitárselo al acabar. */
    private ?int $mandatePartnerId = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Remittance::class)->findAll() as $remittance) {
            if ($remittance->getPeriod()->format('Y') === '2096') {
                $em->remove($remittance);
            }
        }
        if ($this->mandatePartnerId !== null) {
            $em->find(Partner::class, $this->mandatePartnerId)?->setIban(null)->setMandateReference(null)->setMandateSignedOn(null);
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em;
    }

    private function remittance(string $period): ?Remittance
    {
        return $this->em()->getRepository(Remittance::class)->findOneBy(['kind' => Remittance::KIND_MEMBERSHIP, 'period' => new \DateTimeImmutable($period . '-01')]);
    }

    /** Importa el Excel de agosto como remesa de cuota de socix enviada. */
    private function importAugust(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/gestion/accounting/remittances/import');
        $form = $crawler->filter('form[name="remittance_import"]')->form();
        $form['remittance_import[kind]'] = Remittance::KIND_MEMBERSHIP;
        $upload = ['remittance_import' => ['file' => new UploadedFile($this->augustExcel(), '08. Recibos AGOSTO 2096.xlsx', null, null, true)]];
        $client->request('POST', '/gestion/accounting/remittances/import', $form->getPhpValues(), $upload);
    }

    /** El Excel de agosto como se hace a mano: tres recibos, uno con la cuenta mal. */
    private function augustExcel(): string
    {
        if ($this->file !== null) {
            return $this->file;
        }
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            RemittanceSheet::COLUMNS,
            ['ZZTEST ANA', self::GOOD_IBAN, 'CUOTA MENSUAL 900', 44896, 'AGOSTO 2096 900', 'FRST', 65],
            ['ZZTEST LUIS', self::BAD_IBAN, 'CUOTA MENSUAL 901', '01/11/2022', 'AGOSTO 2096 901', 'FRST', '43,5'],
            ['ZZTEST SOL', self::GOOD_IBAN, 'CUOTA MENSUAL 902', 44896, 'AGOSTO 2096 902', 'RCUR', 30],
        ], null, 'A1', true);
        $this->file = tempnam(sys_get_temp_dir(), 'remesa') . '.xlsx';
        (new Xlsx($book))->save($this->file);

        return $this->file;
    }

    public function testConElFlagApagadoNoExiste(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/accounting/remittances');

        $this->assertResponseStatusCodeSame(403);
    }

    /** Con lectura se ven; importar o preparar pide el permiso de edición. */
    public function testConLecturaSeVenPeroNoSeTocan(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD']));
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/remittances');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/gestion/accounting/remittances/import"]');

        $client->request('GET', '/gestion/accounting/remittances/import');
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST', '/gestion/accounting/remittances/prepare', ['kind' => 'basket', 'period' => '2096-09']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testElCircuitoDeUnMes(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        // 1. Importar agosto: el mes y el nombre salen del nombre del fichero.
        $this->importAugust($client);
        $this->assertResponseRedirects();
        $august = $this->remittance('2096-08');
        $this->assertNotNull($august, 'El mes sale del nombre del fichero.');
        $this->assertSame('Recibos AGOSTO 2096', $august->getLabel());
        $this->assertTrue($august->isSent());
        $this->assertSame('138.50', $august->getTotal());

        // Importar otra vez el mismo mes no duplica.
        $this->importAugust($client);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ya hay una remesa');

        // 2. Preparar septiembre desde el listado.
        $crawler = $client->request('GET', '/gestion/accounting/remittances');
        $prepare = $crawler->filter('button[formaction="/gestion/accounting/remittances/prepare"]')->first()->form();
        $prepare['period'] = '2096-09';
        $client->submit($prepare);
        $this->assertResponseRedirects();
        $september = $this->remittance('2096-09');
        $this->assertTrue($september->isDraft());
        $this->assertSame('Recibos SEPTIEMBRE 2096', $september->getLabel());
        $this->assertCount(3, $september->getLines());

        // 3. La ficha dice lo que impide enviarla y no deja enviarla.
        $crawler = $client->request('GET', '/gestion/accounting/remittances/' . $september->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ZZTEST LUIS: esa cuenta no es un IBAN válido.');
        $this->assertSelectorTextContains('body', 'Ninguno: se cobra lo mismo a las mismas personas.');
        $this->assertNotNull($crawler->filter('form[action$="/send"] button')->attr('disabled'));
        $client->submit($crawler->filter('form[action$="/send"]')->form());
        $this->assertTrue($this->remittance('2096-09')->isDraft(), 'Con problemas no se da por enviada.');

        // 4. Arreglar la cuenta del recibo.
        $luis = $this->remittance('2096-09')->getLines()->filter(static fn ($l) => $l->getDebtorName() === 'ZZTEST LUIS')->first();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/lines/%d/edit', $luis->getId()));
        $client->submit($crawler->filter('form[name="remittance_line"]')->form(['remittance_line[iban]' => 'ES79 2100 0813 6101 2345 6789', 'remittance_line[amount]' => '55']));
        $this->assertResponseRedirects();

        // 5. El Excel, con las columnas de siempre y el recibo arreglado.
        $client->request('GET', sprintf('/gestion/accounting/remittances/%d/xlsx', $september->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('09. Recibos SEPTIEMBRE 2096.xlsx', rawurldecode((string) $client->getResponse()->headers->get('Content-Disposition')));

        // 6. Enviarla: los cambios respecto a agosto, y ya no se toca.
        $crawler = $client->request('GET', '/gestion/accounting/remittances/' . $september->getId());
        $this->assertSelectorTextContains('body', 'Cambia');
        $client->submit($crawler->filter('form[action$="/send"]')->form());
        $september = $this->remittance('2096-09');
        $this->assertTrue($september->isSent());
        $this->assertSame('150.00', $september->getTotal());

        $client->request('GET', sprintf('/gestion/accounting/remittances/lines/%d/edit', $luis->getId()));
        $this->assertResponseRedirects('/gestion/accounting/remittances/' . $september->getId());
        $client->request('POST', sprintf('/gestion/accounting/remittances/%d/delete', $september->getId()), ['_token' => 'x']);
        $this->assertNotNull($this->remittance('2096-09'), 'Una enviada desde aquí no se tira.');
    }

    /** Un recibo nuevo propone el siguiente número de mandato libre y FRST. */
    public function testUnReciboNuevoProponeElSiguienteMandato(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->importAugust($client);
        $crawler = $client->request('GET', '/gestion/accounting/remittances');
        $prepare = $crawler->filter('button[formaction="/gestion/accounting/remittances/prepare"]')->first()->form();

        $prepare['period'] = '2096-10';
        $client->submit($prepare);
        $draft = $this->remittance('2096-10');
        $this->assertNotNull($draft);

        $crawler = $client->request('GET', sprintf('/gestion/accounting/remittances/%d/lines/new', $draft->getId()));
        $this->assertResponseIsSuccessful();
        $mandate = (string) $crawler->filter('#remittance_line_mandateReference')->attr('value');
        $this->assertMatchesRegularExpression('/^\S.* \d+$/', $mandate);
        $this->assertSame('FRST', $crawler->filter('#remittance_line_sequenceType option[selected]')->attr('value'));
        $this->assertStringEndsWith(' 209610', (string) $crawler->filter('#remittance_line_debitReference')->attr('value'));
    }

    /**
     * La de cuotas de cesta no se copia de la anterior: la lista sólo ofrece
     * prepararla desde las cuotas, y la acción de copiar la rechaza aunque se
     * le mande a mano.
     */
    public function testLaDeCuotasDeCestaNoSeCopiaDeLaAnterior(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->importAugust($client);

        $crawler = $client->request('GET', '/gestion/accounting/remittances');
        $this->assertCount(1, $crawler->filter('form[action="/gestion/accounting/remittances/from-fees"]'));
        $this->assertCount(0, $crawler->filter('form[action="/gestion/accounting/remittances/from-fees"] button[formaction]'));

        $prepare = $crawler->filter('button[formaction="/gestion/accounting/remittances/prepare"]')->first()->form();
        $prepare['kind']->setValue(Remittance::KIND_BASKET);
        $prepare['period'] = '2096-09';
        $client->submit($prepare);
        $this->assertResponseRedirects('/gestion/accounting/remittances');
        $this->assertNull(
            $this->em()->getRepository(Remittance::class)->findOneBy(['kind' => Remittance::KIND_BASKET, 'period' => new \DateTimeImmutable('2096-09-01')]),
            'No nace una remesa de cesta copiada.'
        );
    }

    /**
     * Una derrama para todxs lxs socixs: con un importe que no es dinero no nace nada;
     * con uno bueno sale el borrador con un recibo por quien tiene mandato, a ese importe.
     */
    public function testUnaDerramaParaTodxsLxsSocixs(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $member = $this->em()->getRepository(Partner::class)->findOneBy(['status' => Partner::STATUS_ACTIVO]);
        $this->assertNotNull($member, 'La base de tests trae socixs activxs.');
        $this->mandatePartnerId = $member->getId();
        $member->setIban(self::GOOD_IBAN)->setMandateReference('ZZTEST DERRAMA 1')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/gestion/accounting/remittances');
        $flat = $crawler->filterXPath('//form[@action="/gestion/accounting/remittances/flat"][.//input[@name="kind"][@value="other"]]')->form();
        $flat['period'] = '2096-11';
        $flat['base'] = 'members';
        $flat['amount'] = 'veinte';
        $client->submit($flat);
        $this->assertResponseRedirects('/gestion/accounting/remittances');
        $this->assertNull($this->em()->getRepository(Remittance::class)->findOneBy(['kind' => Remittance::KIND_OTHER, 'period' => new \DateTimeImmutable('2096-11-01')]));

        $flat['amount'] = '12,50';
        $flat['label'] = 'Derrama tractor';
        $client->submit($flat);
        $this->assertResponseRedirects();
        $derrama = $this->em()->getRepository(Remittance::class)->findOneBy(['kind' => Remittance::KIND_OTHER, 'period' => new \DateTimeImmutable('2096-11-01')]);
        $this->assertNotNull($derrama);
        $this->assertTrue($derrama->isDraft());
        $this->assertSame('Derrama tractor', $derrama->getLabel());
        $line = $derrama->getLines()->filter(static fn ($l) => $l->getMandateReference() === 'ZZTEST DERRAMA 1')->first();
        $this->assertNotFalse($line, 'Quien tiene mandato entra en la derrama.');
        $this->assertSame('12.50', $line->getAmount());
        $this->assertSame('ZZTEST DERRAMA 1 209611EX', $line->getDebitReference());
    }

    /**
     * Una remesa enviada enseña, recibo a recibo, los que no casan con su ficha, y cada
     * uno se resuelve a mano: enlazarlo, poner en la ficha lo del recibo, dejarlo como
     * está y volver a abrirlo. Ninguna acción toca otro recibo.
     */
    public function testLosRecibosQueNoCasanSeResuelvenUnoAUno(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $em = $this->em();
        $member = $em->getRepository(Partner::class)->findOneBy(['status' => Partner::STATUS_ACTIVO]);
        $this->mandatePartnerId = $member->getId();
        $member->setIban(null)->setMandateReference(null)->setMandateSignedOn(null);
        $em->flush();
        $this->importAugust($client);
        $august = $this->remittance('2096-08');
        $line = static fn (string $name) => $august->getLines()->filter(static fn ($l) => $l->getDebtorName() === $name)->first();
        $ana = $line('ZZTEST ANA');
        $sol = $line('ZZTEST SOL');

        $crawler = $client->request('GET', '/gestion/accounting/remittances/' . $august->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#casos', 'ZZTEST ANA');
        $this->assertSelectorTextContains('#casos', 'No hay ninguna ficha con este nombre');
        $this->assertSelectorNotExists('form[action$="/to-partners"]', 'Nada de un botón que lo arregla todo.');
        $token = $crawler->filter(sprintf('form[action$="/lines/%d/resolve"] input[name="_token"]', $ana->getId()))->attr('value');
        $resolve = fn (int $id, string $do, array $extra = []) => $client->request('POST', sprintf('/gestion/accounting/remittances/lines/%d/resolve', $id), ['_token' => $id === $ana->getId() ? $token : $crawler->filter(sprintf('form[action$="/lines/%d/resolve"] input[name="_token"]', $id))->attr('value'), 'do' => $do] + $extra);

        // Enlazar: el recibo pasa a ser de la ficha elegida, y su caso cambia a lo que
        // le falta a esa ficha.
        $resolve($ana->getId(), 'link', ['partner' => $member->getId()]);
        $this->assertResponseRedirects('/gestion/accounting/remittances/' . $august->getId() . '#casos');
        $this->assertSame($member->getId(), $this->em()->find(RemittanceLine::class, $ana->getId())->getPartner()?->getId());
        $client->request('GET', '/gestion/accounting/remittances/' . $august->getId());
        $this->assertSelectorTextContains('#casos', 'le falta la cuenta y el mandato');

        // Poner en la ficha lo del recibo.
        $resolve($ana->getId(), 'take');
        $member = $this->em()->find(Partner::class, $member->getId());
        $this->assertSame(self::GOOD_IBAN, $member->getIban());
        $this->assertSame('CUOTA MENSUAL 900', $member->getMandateReference());

        // Dejar otro como está, y volver a abrirlo.
        $resolve($sol->getId(), 'keep');
        $this->assertNotNull($this->em()->find(RemittanceLine::class, $sol->getId())->getReviewedAt());
        $client->request('GET', '/gestion/accounting/remittances/' . $august->getId());
        $this->assertSelectorTextContains('.rem-reviewed', '1 recibo revisado');
        $resolve($sol->getId(), 'reopen');
        $this->assertNull($this->em()->find(RemittanceLine::class, $sol->getId())->getReviewedAt());
        $this->assertNull($this->em()->find(RemittanceLine::class, $sol->getId())->getPartner(), 'Lo decidido con un recibo no toca otro.');
    }

    /** Resolver casos pide edición de contabilidad; poner datos en una ficha, además, la de socixs. */
    public function testResolverPideSusPermisos(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->importAugust($client);
        $august = $this->remittance('2096-08');
        $ana = $august->getLines()->filter(static fn ($l) => $l->getDebtorName() === 'ZZTEST ANA')->first();

        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD']));
        $client->request('POST', sprintf('/gestion/accounting/remittances/lines/%d/resolve', $ana->getId()), ['do' => 'keep']);
        $this->assertResponseStatusCodeSame(403);

        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD', 'ROLE_GESTION_CONTABILIDAD_EDIT']));
        $crawler = $client->request('GET', '/gestion/accounting/remittances/' . $august->getId());
        $token = $crawler->filter(sprintf('form[action$="/lines/%d/resolve"] input[name="_token"]', $ana->getId()))->attr('value');
        $this->assertSelectorNotExists('form[action$="/resolve"] input[name="do"][value="take"]', 'Sin permiso de socixs no se ofrece cambiar la ficha.');
        $client->request('POST', sprintf('/gestion/accounting/remittances/lines/%d/resolve', $ana->getId()), ['_token' => $token, 'do' => 'take']);
        $client->followRedirect();
        $this->assertSelectorTextContains('.csa-alerts', 'pide permiso de edición de socixs');
    }
}
