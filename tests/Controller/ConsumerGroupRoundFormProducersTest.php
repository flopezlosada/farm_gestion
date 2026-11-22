<?php

namespace App\Tests\Controller;

use App\Entity\Producer;
use App\Service\AppSettings;

/**
 * El selector de productores del pedido tiene que seguir siendo usable con
 * decenas de productores: lo marca como lista filtrable en vez de una casilla
 * visible por cada uno.
 */
class ConsumerGroupRoundFormProducersTest extends AbstractAuthenticatedTest
{
    public function testLosProductoresSeOfrecenConBuscador(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $em = self::getContainer()->get('doctrine')->getManager();
        foreach (range(1, 15) as $n) {
            $em->persist((new Producer())->setName(sprintf('Productor filtrable %02d', $n)));
        }
        $em->flush();

        $crawler = $client->request('GET', '/gestion/consumer-group/new');

        self::assertResponseIsSuccessful();
        $filter = $crawler->filter('[data-csa-check-filter]');
        self::assertCount(1, $filter, 'La lista de productores se marca como filtrable.');
        self::assertGreaterThanOrEqual(15, $filter->filter('input[type="checkbox"][name$="[]"]')->count(), 'Los productores siguen siendo casillas del formulario, el buscador solo las filtra.');
        self::assertSame('Escribe el nombre de un productor…', $filter->attr('data-csa-check-filter-placeholder'));
    }
}
