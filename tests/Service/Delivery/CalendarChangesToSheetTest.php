<?php

namespace App\Tests\Service\Delivery;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\NodeDeliverySheet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Equivalencia calendario → PDF de reparto: comprueba que los cambios del
 * calendario de recogida (no-recoge, cambio de modalidad, mover a otra semana)
 * se reflejan FIELMENTE en el listado imprimible que produce
 * {@see NodeDeliverySheet} —la fuente del PDF—, no solo en la pantalla v2.
 *
 * Cubre la deuda calendario-vs-listado-verificacion: la web puede cuadrar en
 * pantalla y el PDF no (precedente: el bug de huevos-en-viernes-equivocado
 * estaba en la pantalla v2 pero le faltaba el PDF). Aquí se asierta sobre la
 * salida de NodeDeliverySheet::build, que lee el modelo MATERIALIZADO (la
 * "piedra") igual que el PDF en producción.
 *
 * Las fixtures (db_test) crean un socix quincenal con cesta materializada en una
 * semana, pero NO enlazan los grupos de recogida a un nodo (en producción sí lo
 * están). Como findForNodeAndBasket filtra por wbg.node, el test enlaza el grupo
 * del socix a un nodo en su preparación y lo restaura al final, para ejercitar el
 * camino real query → shaper. Cada mutación se deshace en tearDown porque db_test
 * no se resetea por transacción entre tests.
 */
class CalendarChangesToSheetTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NodeDeliverySheet $sheet;
    private Partner $partner;
    private Node $node;
    private Basket $week;
    private Basket $followingWeek;
    /** Modalidad original del socix, para restaurar. */
    private BasketShare $originalShare;
    /** Solo restauramos en tearDown si la preparación llegó hasta el final. */
    private bool $ready = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->sheet = static::getContainer()->get(NodeDeliverySheet::class);

        $partner = $this->em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        if ($partner === null) {
            $this->markTestSkipped('Fixtures sin el socix de prueba.');
        }
        $this->partner = $partner;

        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner]);
        if ($wb === null || $wb->getWeeklyBasketGroup() === null || $wb->getBasket() === null || $wb->getBasketShare() === null) {
            $this->markTestSkipped('Fixtures sin cesta materializada del socix.');
        }
        $this->week = $wb->getBasket();
        $this->originalShare = $wb->getBasketShare();

        // Enlaza el grupo del socix a un nodo (las fixtures dejan los grupos sin
        // nodo) para que el listado por nodo lo encuentre. Se usa el nodo 2
        // (Cascorro, quincenal) por coherencia con el socix quincenal; se
        // restaura en tearDown. findForNodeAndBasket no filtra por cadencia, así
        // que el caso sigue siendo válido, pero los datos quedan realistas.
        $this->node = $this->em->getRepository(Node::class)->find(2);
        $this->partner->getWeeklyBasketGroup()->setNode($this->node);

        // Otra semana a la que "mover" la cesta. La siguiente futura distinta.
        $following = $this->em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.date > :d')
            ->setParameter('d', $this->week->getDate()->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if ($following === null) {
            $this->markTestSkipped('Fixtures sin una segunda semana a la que mover.');
        }
        $this->followingWeek = $following;

        $this->em->flush();
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        // Restaura el estado tocado para no contaminar otros tests (db_test no
        // se resetea entre ejecuciones).
        if ($this->ready) {
            $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $this->partner]);
            if ($wb !== null) {
                $wb->setWeeklyBasketStatus($this->em->getRepository(WeeklyBasketStatus::class)->find(1));
                $wb->setBasket($this->week);
                $wb->setBasketShare($this->originalShare);
            }
            $this->partner->getWeeklyBasketGroup()?->setNode(null);
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testElSocixAparaceEnElListadoConSuModalidad(): void
    {
        $rows = $this->rowsByName($this->sheet->build($this->node, $this->week));

        $this->assertArrayHasKey($this->partnerName(), $rows, 'El socix debe salir en el listado de su nodo.');
        $this->assertSame('Q', $rows[$this->partnerName()]['code'], 'Quincenal sin huevos → código Q.');
    }

    public function testNoRecogeDesapareceDelListado(): void
    {
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $this->partner, 'basket' => $this->week]);
        $wb->setWeeklyBasketStatus($this->em->getRepository(WeeklyBasketStatus::class)->find(2)); // No la recoge
        $this->em->flush();

        $rows = $this->rowsByName($this->sheet->build($this->node, $this->week));

        $this->assertArrayNotHasKey(
            $this->partnerName(),
            $rows,
            'Quien marcó "no recoge" (status 2) NO debe salir en el PDF de reparto.',
        );
    }

    public function testCambioDeModalidadCambiaElCodigoEnElListado(): void
    {
        $semanal = $this->em->getRepository(BasketShare::class)->find(1); // 1 = Semanal (CatalogFixtures)
        $this->assertNotNull($semanal, 'Catálogo sin la modalidad Semanal.');

        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $this->partner, 'basket' => $this->week]);
        $wb->setBasketShare($semanal);
        $this->em->flush();

        $rows = $this->rowsByName($this->sheet->build($this->node, $this->week));

        $this->assertArrayHasKey($this->partnerName(), $rows);
        $this->assertSame('S', $rows[$this->partnerName()]['code'], 'Tras pasar a Semanal → código S en el PDF.');
    }

    public function testMoverAOtraSemanaTrasladaLaFilaEnElListado(): void
    {
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $this->partner, 'basket' => $this->week]);
        $wb->setBasket($this->followingWeek);
        $this->em->flush();

        $origin = $this->rowsByName($this->sheet->build($this->node, $this->week));
        $destination = $this->rowsByName($this->sheet->build($this->node, $this->followingWeek));

        $this->assertArrayNotHasKey($this->partnerName(), $origin, 'Ya no recoge en la semana de origen.');
        $this->assertArrayHasKey($this->partnerName(), $destination, 'Recoge en la semana de destino.');
    }

    // --------------------------------------------------------------------- //

    private function partnerName(): string
    {
        return $this->partner->getNameForDelivery();
    }

    /**
     * Aplana todas las filas del listado (grupos × modalidades + compartidas)
     * indexadas por nombre de reparto, para buscar a un socix concreto.
     *
     * @param array<string,mixed> $sheet Salida de NodeDeliverySheet::build.
     * @return array<string, array<string,mixed>> nombre → fila
     */
    private function rowsByName(array $sheet): array
    {
        $rows = [];
        foreach ($sheet['groups'] as $group) {
            foreach ($group['modalities'] as $modality) {
                foreach ($modality['rows'] as $row) {
                    $rows[$row['name']] = $row;
                }
            }
        }
        foreach ($sheet['shared']['rows'] as $row) {
            $rows[$row['name']] = $row;
        }

        return $rows;
    }
}
