<?php

namespace App\Tests\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupUnit;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Image;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ficha del producto (nueva): SIN precio destacado (vive en la ronda, no en
 * el catálogo) y con galería de fotos en PLURAL —el bug que se arregla aquí
 * es que antes subir una foto BORRABA la anterior en vez de añadirla.
 */
class ConsumerGroupProductShowTest extends AbstractAuthenticatedTest
{
    public function testFichaMuestraEstadisticasYSinFotosTodavia(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [, $naranjas] = $this->prepararRondaConfirmadaConPedido();

        $crawler = $client->request('GET', '/gestion/consumer-group/products/' . $naranjas->getId());

        self::assertResponseIsSuccessful();
        // 4 kg a 2,50 €/kg confirmados: 1 ronda, 4 kg, precio medio 2,50 €, 10,00 € movidos.
        $kpis = $crawler->filter('.cg-kpi__v')->each(static fn ($node) => trim($node->text()));
        self::assertSame(['1', '4,00', '2,50 €', '10,00 €'], $kpis);
        self::assertSelectorTextContains('body', 'Sin fotos todavía');
    }

    public function testSubirDosFotosNoSustituyeLaAnterior(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [, $naranjas] = $this->prepararRondaConfirmadaConPedido();
        $showUrl = '/gestion/consumer-group/products/' . $naranjas->getId();
        $uploadUrl = $showUrl . '/photo';

        $this->subirFoto($client, $showUrl, $uploadUrl, 'Foto de la naranja');
        $this->subirFoto($client, $showUrl, $uploadUrl, 'Otra foto más');

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $photos = $em->getRepository(Image::class)
            ->findForObject(ConsumerGroupProduct::OBJECT_CLASS, $naranjas->getId());

        self::assertCount(2, $photos, 'la segunda foto no debe borrar la primera: es una galería, no una miniatura única');
    }

    public function testBorrarUnaFotoNoBorraLasDemas(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        [, $naranjas] = $this->prepararRondaConfirmadaConPedido();
        $showUrl = '/gestion/consumer-group/products/' . $naranjas->getId();
        $uploadUrl = $showUrl . '/photo';

        $this->subirFoto($client, $showUrl, $uploadUrl, 'Foto que se queda');
        $this->subirFoto($client, $showUrl, $uploadUrl, 'Foto que se borra');

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $photos = $em->getRepository(Image::class)
            ->findForObject(ConsumerGroupProduct::OBJECT_CLASS, $naranjas->getId());
        self::assertCount(2, $photos);
        $aBorrar = $photos[0]->getTitle() === 'Foto que se borra' ? $photos[0] : $photos[1];
        $token = $this->tokenParaFoto($client, $showUrl, $aBorrar->getId());

        $client->request('POST', $showUrl . '/photo/' . $aBorrar->getId(), ['_token' => $token]);
        self::assertResponseRedirects($showUrl);

        $em->clear();
        $restantes = $em->getRepository(Image::class)
            ->findForObject(ConsumerGroupProduct::OBJECT_CLASS, $naranjas->getId());
        self::assertCount(1, $restantes);
        self::assertSame('Foto que se queda', $restantes[0]->getTitle());
    }

    private function subirFoto(KernelBrowser $client, string $showUrl, string $uploadUrl, string $titulo): void
    {
        $crawler = $client->request('GET', $showUrl);
        // El CSRF del formulario Symfony (ImageType) va anidado bajo el nombre del
        // form ("image[_token]"), a diferencia de los forms de borrado de abajo,
        // que son HTML a mano con "_token" suelto.
        $token = $crawler->filter('form[action="' . $uploadUrl . '"] input[name="image[_token]"]')->attr('value');

        $client->request(
            'POST',
            $uploadUrl,
            ['image' => ['title' => $titulo, '_token' => $token]],
            ['image' => ['file' => new UploadedFile($this->sampleImage(), 'foto.png', 'image/png', null, true)]],
        );
        self::assertResponseRedirects($showUrl);
        $client->followRedirect();
    }

    private function tokenParaFoto(KernelBrowser $client, string $showUrl, int $imageId): string
    {
        $crawler = $client->request('GET', $showUrl);

        return $crawler->filter('form[action="' . $showUrl . '/photo/' . $imageId . '"] input[name="_token"]')->attr('value');
    }

    /**
     * @return array{0: ConsumerGroupRound, 1: ConsumerGroupProduct}
     */
    private function prepararRondaConfirmadaConPedido(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $producer = (new Producer())->setName('Huerta Test ' . uniqid());
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit((new ConsumerGroupUnit())->setName('kg'));
        $producer->addProduct($naranjas);

        $round = new ConsumerGroupRound();
        $round->setTitle('Ronda de test')->setProducer($producer)->setOrdersCloseAt(new \DateTime('tomorrow'));
        $item = new ConsumerGroupRoundItem($round, $naranjas, '2.50');
        $round->addItem($item);
        $round->setConfirmed(true);

        $partner = (new Partner())->setname('Socia')->setSurname('Test');
        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, '4'));

        $em->persist($naranjas->getUnit());
        $em->persist($producer);
        $em->persist($naranjas);
        $em->persist($round);
        $em->persist($item);
        $em->persist($partner);
        $em->persist($order);
        $em->flush();

        return [$round, $naranjas];
    }

    /**
     * Un PNG mínimo de verdad en un fichero temporal (contenido real, no solo
     * la extensión): 1x1 píxel transparente.
     */
    private function sampleImage(): string
    {
        $path = sys_get_temp_dir() . '/cg-product-photo-' . bin2hex(random_bytes(4)) . '.png';
        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        file_put_contents($path, base64_decode($pngBase64));

        return $path;
    }
}
