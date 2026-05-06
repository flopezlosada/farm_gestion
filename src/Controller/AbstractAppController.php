<?php

namespace App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Reimplementa getDoctrine() eliminado en Symfony 6 para mantener
 * compatible el código legacy hasta que se migre controller a controller
 * a inyección de EntityManagerInterface por parámetro del action.
 *
 * Decisión KISS: 52 controllers x ~7 llamadas = 373 llamadas a getDoctrine().
 * Tocar 52 firmas + remover el helper se difiere a una fase posterior
 * (probablemente al rehacer auth en Fase 7, momento natural para reescribir
 * controllers).
 */
abstract class AbstractAppController extends AbstractController
{
    private ManagerRegistry $doctrine;

    #[Required]
    public function setDoctrineManagerRegistry(ManagerRegistry $doctrine): void
    {
        $this->doctrine = $doctrine;
    }

    protected function getDoctrine(): ManagerRegistry
    {
        return $this->doctrine;
    }
}
