<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Repository\PartnerRepository;
use App\Repository\VolunteerSignupRepository;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerSuggester;
use PHPUnit\Framework\TestCase;

/**
 * A quién pedirle que venga a una tarea concreta.
 *
 * Lo que se prueba es el CRITERIO, que es donde está la decisión: primero quien
 * menos ha echado una mano este año, para que la carga no recaiga siempre en las
 * mismas cuatro personas. La cifra de cada cual no sale de aquí a ninguna
 * pantalla — el orden basta para repartir, y publicar el contador convertiría
 * una herramienta de coordinación en un marcador.
 */
class VolunteerSuggesterTest extends TestCase
{
    /**
     * El orden es el punto entero: quien menos ha aportado, primero. Quien no
     * aparece en el mapa de participación no ha hecho nada este año, así que va
     * por delante de todo el mundo.
     */
    public function testPrimeroQuienMenosHaEchadoUnaMano(): void
    {
        $veterana = $this->partner(1, 'Ana', 'Lozano');
        $ocasional = $this->partner(2, 'Berta', 'Marín');
        $nueva = $this->partner(3, 'Clara', 'Nieto');

        $suggester = $this->suggester(
            candidates: [$veterana, $ocasional, $nueva],
            contributed: [
                1 => ['times' => 12, 'last' => '2026-08-01 10:00', 'minutes' => 720],
                2 => ['times' => 2, 'last' => '2026-03-01 10:00', 'minutes' => 60],
                // La 3 no aparece: cero.
            ]
        );

        $order = array_map(
            static fn (array $row): ?int => $row['partner']->getId(),
            $suggester->forShift($this->shift())
        );

        $this->assertSame([3, 2, 1], $order);
    }

    /**
     * Con dos personas a cero, el orden lo decide el nombre y no el azar. Una
     * lista que baila entre recargas sin que nada haya cambiado parece rota, y
     * quien coordina no sabe si le está proponiendo algo distinto.
     */
    public function testAIgualdadDeAportacionOrdenaPorNombre(): void
    {
        $suggester = $this->suggester(
            candidates: [$this->partner(1, 'Zoe', 'Abad'), $this->partner(2, 'Ana', 'Bravo')],
            contributed: []
        );

        $order = array_map(
            static fn (array $row): ?int => $row['partner']->getId(),
            $suggester->forShift($this->shift())
        );

        $this->assertSame([2, 1], $order, 'Ana antes que Zoe.');
    }

    /**
     * La lista se corta. Doscientos nombres no son una sugerencia, son la bolsa
     * de gente otra vez, y nadie la lee.
     */
    public function testLaListaSeCorta(): void
    {
        $candidates = [];
        for ($i = 1; $i <= 20; ++$i) {
            $candidates[] = $this->partner($i, 'Socia'.$i, 'Apellido');
        }

        $suggester = $this->suggester($candidates, []);

        $this->assertCount(3, $suggester->forShift($this->shift(), 3));
    }

    /**
     * Una tarea sin tipo de trabajo no tiene con qué casar a nadie: se devuelve
     * vacío y la pantalla lo dice, en vez de inventarse una lista de gente a la
     * que esa tarea no le encaja.
     */
    public function testSinCandidatosDevuelveVacio(): void
    {
        $suggester = $this->suggester([], []);

        $this->assertSame([], $suggester->forShift($this->shift()));
    }

    /**
     * Sale del ámbito MATCHING y no de toda la asociación: son quienes han dicho
     * que de esto sí se les avise. Sugerir a quien pidió que no le avisaran sería
     * saltarse su preferencia por la puerta de atrás.
     */
    public function testSoloSugiereAQuienHaMarcadoEsteTipoDeTrabajo(): void
    {
        $resolver = $this->createMock(VolunteerAudienceResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with($this->anything(), VolunteerCall::SCOPE_MATCHING)
            ->willReturn([]);

        $signups = $this->createMock(VolunteerSignupRepository::class);
        $suggester = new VolunteerSuggester($resolver, $signups);

        $suggester->forShift($this->shift());
    }

    /**
     * @param list<Partner>                                                    $candidates  lxs candidatxs que devuelve el resolver
     * @param array<int, array{times: int, last: string, minutes: int}> $contributed lo aportado este año
     */
    private function suggester(array $candidates, array $contributed): VolunteerSuggester
    {
        $partners = $this->createMock(PartnerRepository::class);
        $partners->method('findActiveMatchingVolunteerCategories')->willReturn($candidates);

        $signups = $this->createMock(VolunteerSignupRepository::class);
        $signups->method('participationByPartner')->willReturn($contributed);

        return new VolunteerSuggester(new VolunteerAudienceResolver($partners), $signups);
    }

    /**
     * Un turno con su tarea detrás: es lo que recibe el sugeridor, porque a
     * quien se le pide venir se le pide para un día concreto.
     */
    private function shift(): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('2026-09-07 18:00'));
        $offer->addShift($shift);

        return $shift;
    }

    /**
     * Un socix con id, como los que salen de la BBDD.
     *
     * @param int    $id      el identificador a forzar
     * @param string $name    su nombre
     * @param string $surname sus apellidos
     */
    private function partner(int $id, string $name, string $surname): Partner
    {
        $partner = (new Partner())->setName($name)->setSurname($surname);

        $reflection = new \ReflectionProperty(Partner::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($partner, $id);

        return $partner;
    }
}
