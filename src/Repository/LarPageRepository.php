<?php

namespace App\Repository;

use App\Entity\LarPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method LarPage|null find($id, $lockMode = null, $lockVersion = null)
 * @method LarPage|null findOneBy(array $criteria, array $orderBy = null)
 * @method LarPage[]    findAll()
 * @method LarPage[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LarPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LarPage::class);
    }

    /**
     * Devuelve la portada del LAR (singleton). Si la fila aún no existe, devuelve
     * una instancia nueva —SIN persistir— con el contenido de fábrica, para que
     * la web pública nunca salga vacía. Se persiste la primera vez que la
     * coordinación guarda cambios desde el panel.
     *
     * @return LarPage
     */
    public function get(): LarPage
    {
        return $this->findOneBy([]) ?? LarPage::createWithDefaults();
    }
}
