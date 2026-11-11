<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupProduct;
use App\Entity\Image;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Incrusta en un correo la miniatura de cada producto del grupo de consumo.
 *
 * INCRUSTADA, NO ENLAZADA: con una URL a la web, la mayoría de clientes de
 * correo esconde la imagen hasta que se pulsa «mostrar imágenes». Es el mismo
 * criterio que el logo de `email/_layout.html.twig`.
 *
 * MINIATURA, NO EL ORIGINAL: las fotos subidas pesan hasta varios MB; diez en
 * un correo lo harían impracticable. El filtro {@see self::FILTER} de
 * LiipImagine las deja en un JPEG pequeño.
 *
 * Cada miniatura se calcula UNA vez por proceso y producto: el aviso de
 * apertura sale a todas las socias con las mismas fotos. {@see reset()} vacía
 * esa memoria entre peticiones.
 *
 * Una foto que no se puede leer o reducir NO para el correo: sale sin ella y
 * queda en el log.
 */
class ConsumerGroupEmailPhotos implements ResetInterface
{
    /** Filtro de LiipImagine (`config/packages/liip_imagine.yaml`). */
    public const FILTER = 'consumer_group_email_thumb';

    /** @var array<int, array{body: string, mime: string}|null> miniatura por id de producto; null = no tiene */
    private array $thumbs = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataManager $dataManager,
        private readonly FilterManager $filterManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Adjunta al correo, como parte incrustada, la miniatura de cada producto
     * que tenga foto. Pasa SÓLO los productos que pinta la plantilla: una
     * parte que el HTML no referencia llega como adjunto suelto.
     *
     * @param Email                              $email    correo al que se adjuntan
     * @param iterable<ConsumerGroupProduct|null> $products productos que salen en el correo
     *
     * @return array<int, string> `src` de la imagen (`cid:…`) por id de producto; sin entrada = sin foto
     */
    public function embed(Email $email, iterable $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            $id = $product?->getId();
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        $this->load(array_values($ids));

        $sources = [];
        foreach ($ids as $id) {
            $thumb = $this->thumbs[$id];
            if ($thumb === null) {
                continue;
            }
            $name = sprintf('producto-%d.jpg', $id);
            $email->addPart((new DataPart($thumb['body'], $name, $thumb['mime']))->asInline());
            $sources[$id] = 'cid:' . $name;
        }

        return $sources;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->thumbs = [];
    }

    /**
     * Calcula las miniaturas que aún no están en memoria, con UNA consulta para
     * todas las fotos.
     *
     * @param list<int> $ids ids de producto
     */
    private function load(array $ids): void
    {
        $missing = array_values(array_filter($ids, fn (int $id): bool => !array_key_exists($id, $this->thumbs)));
        if ($missing === []) {
            return;
        }

        // ImageRepository no es un servicio (extiende EntityRepository a pelo):
        // se pide al EntityManager, como en los controladores.
        $photos = $this->entityManager->getRepository(Image::class)
            ->findOneForObjects(ConsumerGroupProduct::OBJECT_CLASS, $missing);
        foreach ($missing as $id) {
            $this->thumbs[$id] = isset($photos[$id]) ? $this->thumbnail($photos[$id]) : null;
        }
    }

    /**
     * Reduce la foto con el filtro de correo.
     *
     * @return array{body: string, mime: string}|null null si no hay fichero o falla
     */
    private function thumbnail(Image $photo): ?array
    {
        $path = $photo->getWebPath();
        if ($path === null) {
            return null;
        }

        try {
            $binary = $this->filterManager->applyFilter($this->dataManager->find(self::FILTER, $path), self::FILTER);

            return ['body' => $binary->getContent(), 'mime' => (string) $binary->getMimeType()];
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo reducir la foto de un producto del grupo de consumo para el correo.', [
                'image' => $photo->getId(),
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
