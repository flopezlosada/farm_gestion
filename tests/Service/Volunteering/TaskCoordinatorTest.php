<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Service\Volunteering\TaskCoordinator;
use PHPUnit\Framework\TestCase;

/**
 * Quién coordina una tarea cuando no hace falta preguntarlo.
 *
 * La tarea guarda su coordinador en vez de mirar quién lleva el área AHORA, y
 * eso es lo que protege el histórico: la coordinación de un área cambia, y si
 * la ficha la leyera al vuelo, el día que alguien deje Reparto todas las tareas
 * del año pasado dirían que las coordinó quien acaba de entrar.
 *
 * Lo que se prueba aquí es cuándo se puede deducir sin preguntar y cuándo no.
 */
class TaskCoordinatorTest extends TestCase
{
    /**
     * El caso de hoy: un área, una persona coordinándola. No hay nada que
     * elegir, así que no se pregunta.
     */
    public function testConUnaSolaCoordinadoraSePoneSola(): void
    {
        $ana = $this->partner(1);
        $offer = $this->offer([$this->area([$this->userFor($ana)])]);

        (new TaskCoordinator())->assignIfObvious($offer);

        $this->assertSame($ana, $offer->getCoordinator());
    }

    /**
     * Con varias, sí hay algo que decidir: se deja vacío y lo dice quien crea la
     * tarea. Poner a una de ellas "porque sí" sería atribuirle un trabajo que
     * igual hace otra.
     */
    public function testConVariasNoSeInventaNada(): void
    {
        $offer = $this->offer([
            $this->area([$this->userFor($this->partner(1)), $this->userFor($this->partner(2))]),
        ]);

        $service = new TaskCoordinator();
        $service->assignIfObvious($offer);

        $this->assertNull($offer->getCoordinator());
        $this->assertTrue($service->needsChoosing($offer), 'El formulario tiene que preguntarlo.');
    }

    /**
     * La misma persona coordinando las dos áreas de una tarea es UNA candidata,
     * no dos: contarla dos veces dejaría el campo vacío sin motivo.
     */
    public function testLaMismaPersonaEnDosAreasSigueSiendoUnaSola(): void
    {
        $ana = $this->partner(1);
        $user = $this->userFor($ana);
        $offer = $this->offer([$this->area([$user]), $this->area([$user])]);

        $service = new TaskCoordinator();
        $service->assignIfObvious($offer);

        $this->assertSame($ana, $offer->getCoordinator());
        $this->assertFalse($service->needsChoosing($offer));
    }

    /**
     * Dos áreas con coordinadoras distintas tampoco están claras: la tarea es de
     * las dos y no se sabe quién la monta.
     */
    public function testDosAreasConCoordinadorasDistintasNoSeDeducen(): void
    {
        $offer = $this->offer([
            $this->area([$this->userFor($this->partner(1))]),
            $this->area([$this->userFor($this->partner(2))]),
        ]);

        (new TaskCoordinator())->assignIfObvious($offer);

        $this->assertNull($offer->getCoordinator());
    }

    /**
     * Lo elegido a mano manda. Volver a guardar la tarea no puede cambiarle el
     * coordinador por debajo a quien lo puso a propósito.
     */
    public function testNoPisaLoQueSeEligioAMano(): void
    {
        $elegida = $this->partner(9);
        $offer = $this->offer([$this->area([$this->userFor($this->partner(1))])]);
        $offer->setCoordinator($elegida);

        (new TaskCoordinator())->assignIfObvious($offer);

        $this->assertSame($elegida, $offer->getCoordinator());
    }

    /**
     * Quien coordina un área sin ser socix no se puede poner: la coordinación
     * cuelga de la cuenta y aquí hace falta un Partner, porque de lo que se
     * trata es de a quién se le atribuye el trabajo.
     */
    public function testQuienCoordinaSinSerSocixNoSePone(): void
    {
        $offer = $this->offer([$this->area([new User()])]);

        (new TaskCoordinator())->assignIfObvious($offer);

        $this->assertNull($offer->getCoordinator());
    }

    /**
     * Un área sin nadie coordinándola —tres de las cuatro, hoy— no deduce nada.
     */
    public function testSinCoordinadorasNoPasaNada(): void
    {
        $offer = $this->offer([$this->area([])]);

        (new TaskCoordinator())->assignIfObvious($offer);

        $this->assertNull($offer->getCoordinator());
    }

    /**
     * Lo que el formulario ofrece cuando pregunta: las que coordinan el área y
     * nadie más. El desplegable llegó a listar los 246 socixs, que es pedir que
     * se elija a dedo justo lo que el sistema ya sabe.
     */
    public function testSoloSeOfreceAQuienCoordinaElArea(): void
    {
        $ana = $this->partner(1);
        $berta = $this->partner(2);
        $offer = $this->offer([$this->area([$this->userFor($ana), $this->userFor($berta)])]);

        $candidates = (new TaskCoordinator())->candidatesFor($offer);

        $this->assertSame([$ana, $berta], array_values($candidates));
    }

    /**
     * Quien ya consta como coordinador sigue ofreciéndose aunque haya dejado el
     * área. Sin esto, editar cualquier otra cosa de la tarea —la hora, el sitio—
     * le borraría de ella en silencio, porque el desplegable no tendría su
     * opción; y con él se iría la única constancia de quién la llevó.
     */
    public function testQuienYaConstaSigueOfreciendoseAunqueHayaDejadoElArea(): void
    {
        $antigua = $this->partner(9);
        $nueva = $this->partner(1);
        $offer = $this->offer([$this->area([$this->userFor($nueva)])]);
        $offer->setCoordinator($antigua);

        $service = new TaskCoordinator();

        $this->assertSame([$nueva, $antigua], array_values($service->candidatesFor($offer)));
        $this->assertTrue($service->needsChoosing($offer), 'Hay dos opciones reales: se pregunta.');
    }

    /**
     * @param list<VolunteerCategory> $categories las áreas de la tarea
     */
    private function offer(array $categories): VolunteerOffer
    {
        $offer = (new VolunteerOffer())->setTitle('Descargar el reparto');

        foreach ($categories as $category) {
            $offer->addCategory($category);
        }

        return $offer;
    }

    /**
     * @param list<User> $coordinators quiénes la coordinan
     */
    private function area(array $coordinators): VolunteerCategory
    {
        $category = new VolunteerCategory();

        foreach ($coordinators as $user) {
            $category->addCoordinator($user);
        }

        return $category;
    }

    /**
     * Una cuenta con socix detrás, que es lo que hace falta para atribuirle el
     * trabajo.
     */
    private function userFor(Partner $partner): User
    {
        return (new User())->setPartner($partner);
    }

    /**
     * Un socix con id, como los que salen de la base de datos: el servicio
     * indexa las candidatas por id para no contar dos veces a la misma.
     *
     * @param int $id el identificador a forzar
     */
    private function partner(int $id): Partner
    {
        $partner = new Partner();

        $reflection = new \ReflectionProperty(Partner::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($partner, $id);

        return $partner;
    }
}
