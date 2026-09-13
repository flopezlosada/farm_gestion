<?php

namespace App\Tests\Repository;

use App\Entity\NotificationLog;
use App\Repository\NotificationLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El recuento por resultado que alimenta las tarjetas de cabecera del registro
 * de avisos ({@see NotificationLogRepository::summary}).
 *
 * Lo que se fija aquí es que el recuento IGNORA el filtro de estado a
 * propósito, y no es un detalle: en la pantalla esas tarjetas son el mando con
 * el que se salta de "entregados" a "fallidos". Si el recuento heredara el
 * filtro, al pinchar una las otras tres se pondrían a cero y no habría por
 * dónde volver — y como la pantalla seguiría pintándose sin error, nadie se
 * enteraría hasta usarla.
 *
 * Autocontenido: se siembran los apuntes con una fecha imposible (2099) y una
 * dirección única, y el rango de la consulta es ese día, para no contar lo que
 * otros tests hayan dejado en db_test.
 */
class NotificationLogSummaryTest extends KernelTestCase
{
    private const DIA = '2099-04-17';

    public function testElRecuentoNoHeredaElFiltroDeEstado(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var NotificationLogRepository $repo */
        $repo = $em->getRepository(NotificationLog::class);

        $sufijo = uniqid();
        $ids = $this->sembrar($em, $sufijo, [
            NotificationLog::STATUS_SENT,
            NotificationLog::STATUS_SENT,
            NotificationLog::STATUS_FAILED,
            NotificationLog::STATUS_DISCARDED,
        ]);

        try {
            $filtros = $this->filtrosDelDia();

            $sinFiltrar = $repo->summary($filtros);
            $this->assertSame(4, $sinFiltrar['total']);
            $this->assertSame(2, $sinFiltrar['sent']);
            $this->assertSame(1, $sinFiltrar['failed']);
            $this->assertSame(1, $sinFiltrar['discarded']);

            // Mirando sólo los fallidos, el recuento sigue siendo el de TODOS:
            // es lo que permite volver a los entregados desde su tarjeta.
            $filtrandoFallidos = $repo->summary($filtros + ['status' => NotificationLog::STATUS_FAILED]);
            $this->assertSame($sinFiltrar, $filtrandoFallidos);
        } finally {
            $this->borrar($ids);
        }
    }

    /**
     * El resto de filtros SÍ los respeta: el recuento tiene que hablar del
     * periodo y del canal que estás mirando, o dejaría de cuadrar con la tabla
     * que hay justo debajo.
     */
    public function testElRecuentoSiRespetaElRestoDeFiltros(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var NotificationLogRepository $repo */
        $repo = $em->getRepository(NotificationLog::class);

        $sufijo = uniqid();
        $ids = $this->sembrar($em, $sufijo, [
            NotificationLog::STATUS_SENT,
            NotificationLog::STATUS_FAILED,
        ]);

        try {
            $porCanal = $repo->summary($this->filtrosDelDia() + ['channel' => NotificationLog::CHANNEL_PUSH]);

            // Todo lo sembrado es correo, así que por el canal del móvil no hay nada.
            $this->assertSame(0, $porCanal['total']);
        } finally {
            $this->borrar($ids);
        }
    }

    /**
     * Rango acotado al día imposible, en la forma que lo arma el controlador
     * (`until` es límite superior EXCLUSIVO).
     *
     * @return array<string, mixed>
     */
    private function filtrosDelDia(): array
    {
        $dia = new \DateTimeImmutable(self::DIA);

        return [
            'from' => $dia,
            'until' => $dia->modify('+1 day'),
        ];
    }

    /**
     * @param string[] $estados
     * @return int[] los ids sembrados
     */
    private function sembrar(EntityManagerInterface $em, string $sufijo, array $estados): array
    {
        $ids = [];
        foreach ($estados as $i => $estado) {
            $log = (new NotificationLog())
                ->setChannel(NotificationLog::CHANNEL_EMAIL)
                ->setKind('pickup_reminder')
                ->setSubject('Recuento')
                ->setTarget(sprintf('recuento-%s-%d@test.org', $sufijo, $i))
                ->setStatus($estado)
                ->setSentAt(new \DateTimeImmutable(self::DIA . ' 10:00:00'));
            $em->persist($log);
            $ids[] = $log;
        }
        $em->flush();

        return array_map(static fn (NotificationLog $l): ?int => $l->getId(), $ids);
    }

    /** @param int[] $ids */
    private function borrar(array $ids): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($ids as $id) {
            $log = $em->getRepository(NotificationLog::class)->find($id);
            if ($log !== null) {
                $em->remove($log);
            }
        }
        $em->flush();
    }
}
