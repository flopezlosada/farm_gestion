<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupProduct;
use App\Entity\Image;
use App\Repository\ImageRepository;
use App\Service\ConsumerGroup\ConsumerGroupEmailPhotos;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Miniaturas de producto incrustadas en los correos del grupo de consumo.
 */
class ConsumerGroupEmailPhotosTest extends TestCase
{
    /** Bytes de la miniatura simulada: lo que devolvería LiipImagine. */
    private const THUMB = 'jpeg-bytes';

    /**
     * Un producto con foto lleva su miniatura incrustada y su `src` apunta a
     * ella por nombre.
     */
    public function testIncrustaLaMiniaturaDelProductoConFoto(): void
    {
        $email = new Email();
        $sources = $this->service($this->repositoryWith([7 => $this->photo('a.png')]))
            ->embed($email, [$this->product(7)]);

        self::assertSame([7 => 'cid:producto-7.jpg'], $sources);
        $parts = $email->getAttachments();
        self::assertCount(1, $parts);
        self::assertSame('producto-7.jpg', $parts[0]->getName());
        self::assertSame(self::THUMB, $parts[0]->getBody());
        self::assertSame('inline', $parts[0]->getDisposition());
    }

    /**
     * Producto sin foto, producto nulo (línea sin catálogo) y producto aún sin
     * guardar no aportan nada ni rompen el correo.
     */
    public function testSinFotoNiProductoNoAdjuntaNada(): void
    {
        $email = new Email();
        $sources = $this->service($this->repositoryWith([]))
            ->embed($email, [$this->product(3), null, new ConsumerGroupProduct()]);

        self::assertSame([], $sources);
        self::assertSame([], $email->getAttachments());
    }

    /**
     * Una foto que LiipImagine no puede reducir (fichero borrado del disco,
     * formato raro) deja el correo sin esa foto, avisa en el log y no corta
     * el envío.
     */
    public function testFotoIlegibleSaleSinFotoYQuedaEnElLog(): void
    {
        $filters = $this->createMock(FilterManager::class);
        $filters->method('applyFilter')->willThrowException(new \RuntimeException('Source image not found'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $email = new Email();
        $sources = $this->service($this->repositoryWith([7 => $this->photo('borrada.png')]), $filters, $logger)
            ->embed($email, [$this->product(7)]);

        self::assertSame([], $sources);
        self::assertSame([], $email->getAttachments());
    }

    /**
     * Una Image sin fichero (fila huérfana, `image` a null) no se intenta reducir.
     */
    public function testImageSinFicheroNoSeReduce(): void
    {
        $filters = $this->createMock(FilterManager::class);
        $filters->expects(self::never())->method('applyFilter');

        $sources = $this->service($this->repositoryWith([7 => new Image()]), $filters)
            ->embed(new Email(), [$this->product(7)]);

        self::assertSame([], $sources);
    }

    /**
     * El aviso de apertura sale a muchas socias con los mismos productos: la
     * foto se busca y se reduce una sola vez, y cada correo lleva SU parte. El
     * mismo producto repetido en un correo se adjunta una vez.
     */
    public function testCadaMiniaturaSeCalculaUnaVezPorProceso(): void
    {
        $repository = $this->createMock(ImageRepository::class);
        $repository->expects(self::once())->method('findOneForObjects')
            ->with(ConsumerGroupProduct::OBJECT_CLASS, [7])
            ->willReturn([7 => $this->photo('a.png')]);
        $filters = $this->filters();
        $filters->expects(self::once())->method('applyFilter');

        $service = $this->service($repository, $filters);
        $first = new Email();
        $second = new Email();
        $service->embed($first, [$this->product(7), $this->product(7)]);
        $service->embed($second, [$this->product(7)]);

        self::assertCount(1, $first->getAttachments());
        self::assertCount(1, $second->getAttachments());
    }

    /**
     * Tras {@see ConsumerGroupEmailPhotos::reset()} se vuelve a leer: una foto
     * cambiada entre peticiones no se queda vieja.
     */
    public function testResetVuelveALeerLasFotos(): void
    {
        $repository = $this->createMock(ImageRepository::class);
        $repository->expects(self::exactly(2))->method('findOneForObjects')->willReturn([]);

        $service = $this->service($repository);
        $service->embed(new Email(), [$this->product(7)]);
        $service->reset();
        $service->embed(new Email(), [$this->product(7)]);
    }

    /**
     * Comprobación de lo que da sentido al servicio: Symfony empareja el
     * `cid:producto-7.jpg` del HTML con la parte de ese nombre y la manda como
     * imagen relacionada del HTML, no como adjunto suelto.
     */
    public function testElHtmlReferenciaLaParteComoImagenRelacionada(): void
    {
        $email = (new Email())->from('csa@example.org')->to('socia@example.org');
        $sources = $this->service($this->repositoryWith([7 => $this->photo('a.png')]))
            ->embed($email, [$this->product(7)]);
        $email->html(sprintf('<p><img src="%s" alt=""></p>', $sources[7]));

        $mime = $email->toString();

        self::assertStringContainsString('multipart/related', $mime);
        self::assertStringNotContainsString('cid:producto-7.jpg', $mime);
        self::assertStringNotContainsString('Content-Disposition: attachment', $mime);
        $part = $email->getAttachments()[0];
        self::assertInstanceOf(DataPart::class, $part);
        self::assertStringContainsString('cid:' . $part->getContentId(), $mime);
    }

    /**
     * @param ImageRepository    $repository fotos por id de producto
     * @param FilterManager|null $filters    reductor (por defecto, devuelve {@see self::THUMB})
     * @param LoggerInterface|null $logger   log
     */
    private function service(ImageRepository $repository, ?FilterManager $filters = null, ?LoggerInterface $logger = null): ConsumerGroupEmailPhotos
    {
        $data = $this->createMock(DataManager::class);
        $data->method('find')->willReturn(new Binary('png-original', 'image/png', 'png'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Image::class)->willReturn($repository);

        return new ConsumerGroupEmailPhotos(
            $entityManager,
            $data,
            $filters ?? $this->filters(),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Reductor que devuelve una miniatura JPEG.
     */
    private function filters(): FilterManager
    {
        $filters = $this->createMock(FilterManager::class);
        $filters->method('applyFilter')
            ->with(self::isInstanceOf(Binary::class), ConsumerGroupEmailPhotos::FILTER)
            ->willReturn(new Binary(self::THUMB, 'image/jpeg', 'jpeg'));

        return $filters;
    }

    /**
     * @param array<int, Image> $photos foto por id de producto
     */
    private function repositoryWith(array $photos): ImageRepository
    {
        $repository = $this->createMock(ImageRepository::class);
        $repository->method('findOneForObjects')->willReturn($photos);

        return $repository;
    }

    /**
     * Foto subida con el nombre de fichero dado.
     */
    private function photo(string $file): Image
    {
        return (new Image())
            ->setObjectClass(ConsumerGroupProduct::OBJECT_CLASS)
            ->setImage($file);
    }

    /**
     * Producto del catálogo ya guardado, con su id.
     */
    private function product(int $id): ConsumerGroupProduct
    {
        $product = (new ConsumerGroupProduct())->setName('Aceite');
        $property = new \ReflectionProperty(ConsumerGroupProduct::class, 'id');
        $property->setValue($product, $id);

        return $product;
    }
}
