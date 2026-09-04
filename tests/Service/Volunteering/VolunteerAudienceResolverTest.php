<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\PartnerRepository;
use App\Service\Volunteering\VolunteerAudienceResolver;
use PHPUnit\Framework\TestCase;

/**
 * A quién llega cada aviso de voluntariado.
 *
 * Lo que se prueba aquí es sobre todo a quién NO llega: cada persona de más en
 * un aviso es una candidata a apagar las notificaciones, y el permiso del
 * navegador no se puede volver a pedir una vez denegado.
 */
class VolunteerAudienceResolverTest extends TestCase
{
    /**
     * Una tarea que no es para cualquiera no llega a quien no ha declarado
     * preferencias, por muchas horas que pasen. Es la regla que pidió el caso
     * de desbrozar.
     */
    public function testUnaTareaQueNoEsParaCualquieraNoLlegaAQuienNoHaDichoNada(): void
    {
        $shift = $this->shift(openToAnyone: false);
        $partners = $this->createMock(PartnerRepository::class);
        $partners->expects($this->never())->method('findActiveWithoutVolunteerPreferences');

        $resolver = new VolunteerAudienceResolver($partners);

        $this->assertSame([], $resolver->resolve($shift, VolunteerCall::SCOPE_UNSPECIFIED));
    }

    /**
     * Quien ya está apuntadx no recibe el aviso: pedirle que venga a algo a lo
     * que ya viene es la forma más rápida de que lo apague.
     */
    public function testQuienYaEstaApuntadoNoRecibeElAviso(): void
    {
        $apuntada = $this->partner(1);
        $libre = $this->partner(2);

        $shift = $this->shift();
        $shift->addSignup((new VolunteerSignup())->setPartner($apuntada));

        $resolver = new VolunteerAudienceResolver($this->repositoryReturning([$apuntada, $libre]));

        $this->assertSame([$libre], $resolver->resolve($shift, VolunteerCall::SCOPE_EVERYONE));
    }

    /**
     * Darse de baja vuelve a poner a esa persona entre lxs destinatarixs: si se
     * descolgó, hace falta gente otra vez y es la primera candidata.
     */
    public function testQuienSeDioDeBajaVuelveARecibirElAviso(): void
    {
        $bajada = $this->partner(1);

        $shift = $this->shift();
        $shift->addSignup((new VolunteerSignup())->setPartner($bajada)->cancel());

        $resolver = new VolunteerAudienceResolver($this->repositoryReturning([$bajada]));

        $this->assertSame([$bajada], $resolver->resolve($shift, VolunteerCall::SCOPE_EVERYONE));
    }

    /**
     * Un socix sin persistir (id null) apuntado al turno no puede dejar fuera
     * del aviso a nadie más. PHP convierte la clave null a cadena vacía, así que
     * indexarlo sin comprobar vaciaría la audiencia entera.
     */
    public function testUnSocixSinIdNoVaciaLaAudiencia(): void
    {
        $sinId = new Partner();
        $normal = $this->partner(7);

        $shift = $this->shift();
        $shift->addSignup((new VolunteerSignup())->setPartner($sinId));

        $resolver = new VolunteerAudienceResolver($this->repositoryReturning([$normal]));

        $this->assertSame([$normal], $resolver->resolve($shift, VolunteerCall::SCOPE_EVERYONE));
    }

    /**
     * Un alcance que no existe devuelve lista vacía en vez de reventar: la
     * pantalla pregunta por alcances para pintar números y una pregunta rara no
     * es un error de programación.
     */
    public function testUnAlcanceDesconocidoNoRevienta(): void
    {
        $resolver = new VolunteerAudienceResolver($this->createMock(PartnerRepository::class));

        $this->assertSame([], $resolver->resolve($this->shift(), 'inventado'));
    }

    /**
     * Un turno, con su tarea publicada detrás. Se devuelve el TURNO porque es lo
     * que recibe el resolver: las preferencias son del área —de la tarea— pero
     * quién ya viene depende del día.
     *
     * @param bool $openToAnyone si el aviso se puede ampliar
     */
    private function shift(bool $openToAnyone = true): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Plantar tomates')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setOpenToAnyone($openToAnyone);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('2099-04-01 10:00'));
        $offer->addShift($shift);

        return $shift;
    }

    /**
     * Un socix con id, como los que salen de la BBDD.
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

    /**
     * Un repositorio que devuelve la misma lista sea cual sea la consulta, para
     * que el test hable de la política de audiencia y no del SQL.
     *
     * @param list<Partner> $partners lxs socixs que devuelve
     */
    private function repositoryReturning(array $partners): PartnerRepository
    {
        $repository = $this->createMock(PartnerRepository::class);
        $repository->method('findAllActive')->willReturn($partners);
        $repository->method('findActiveWithoutVolunteerPreferences')->willReturn($partners);
        $repository->method('findActiveMatchingVolunteerCategories')->willReturn($partners);

        return $repository;
    }
}
