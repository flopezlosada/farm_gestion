<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Entity\Worker;
use App\Security\UserChecker;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * El UserChecker es el punto único donde se aplica el feature-flag de acceso de
 * socixs ({@see AppSettings::FEATURE_PARTNER_LOGIN}) sobre las tres vías de
 * login. Aquí se prueba la regla en aislamiento: socix puro vs. equipo, acceso
 * abierto vs. cerrado, y que el bloqueo de cuenta (enabled=false) manda siempre.
 */
class UserCheckerTest extends TestCase
{
    /**
     * AppSettings de pega: FEATURE_PARTNER_LOGIN toma el valor dado, el resto
     * de claves caen a false (no se consultan en este checker).
     */
    private function settings(bool $partnerLoginOpen): AppSettings
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturnCallback(
            static fn (string $key): bool => $key === AppSettings::FEATURE_PARTNER_LOGIN && $partnerLoginOpen
        );

        return $settings;
    }

    /**
     * Checker con la jerarquía real de las secciones partidas en lectura y
     * escritura que se prueban aquí: el `_EDIT` alcanza la lectura.
     */
    private function checker(bool $partnerLoginOpen): UserChecker
    {
        return new UserChecker($this->settings($partnerLoginOpen), new RoleHierarchy([
            'ROLE_GESTION_LABORAL_EDIT' => ['ROLE_GESTION_LABORAL'],
            'ROLE_GESTION_CONTABILIDAD_EDIT' => ['ROLE_GESTION_CONTABILIDAD'],
        ]));
    }

    private function socix(): User
    {
        return (new User())->setRoles(['ROLE_PARTNER'])->setEnabled(true);
    }

    private function teamMember(): User
    {
        return (new User())->setRoles(['ROLE_GESTION_REPARTO'])->setEnabled(true);
    }

    public function testSocixRechazadoConElAccesoCerrado(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $this->checker(false)->checkPreAuth($this->socix());
    }

    public function testSocixAdmitidoConElAccesoAbierto(): void
    {
        $this->checker(true)->checkPreAuth($this->socix());

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testElEquipoEntraAunqueElAccesoDeSocixsEsteCerrado(): void
    {
        $this->checker(false)->checkPreAuth($this->teamMember());

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testCuentaBloqueadaRechazadaAunqueElAccesoEsteAbierto(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $this->checker(true)->checkPreAuth($this->socix()->setEnabled(false));
    }

    public function testSocixConAccesoAnticipadoEntraAunqueElGrifoEsteCerrado(): void
    {
        $this->checker(false)->checkPreAuth($this->socix()->setEarlyAccess(true));

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testCuentaBloqueadaRechazadaAunqueTengaAccesoAnticipado(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $this->checker(false)
            ->checkPreAuth($this->socix()->setEarlyAccess(true)->setEnabled(false));
    }

    public function testTrabajadorEntraAunqueElAccesoDeSocixsEsteCerrado(): void
    {
        // Un trabajador puro (faceta laboral, sin rol de equipo ni acceso
        // anticipado) no depende del rollout de socixs: debe poder entrar a fichar.
        $worker = (new User())->setEnabled(true)->setWorker(new Worker());

        $this->checker(false)->checkPreAuth($worker);

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testTrabajadorBloqueadoSigueSinPoderEntrar(): void
    {
        // La exención laboral no salta el bloqueo de cuenta (enabled=false manda).
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $worker = (new User())->setEnabled(false)->setWorker(new Worker());

        $this->checker(false)->checkPreAuth($worker);
    }

    public function testQuienSoloEscribeEnUnaSeccionEntraComoEquipo(): void
    {
        // Al que escribe se le guarda sólo el _EDIT; la lectura (que es la que
        // abre /gestion) le llega por jerarquía. Sin expandirla, contabilidad se
        // quedaba fuera con el acceso de socixs cerrado.
        $this->checker(false)->checkPreAuth(
            (new User())->setRoles(['ROLE_GESTION_CONTABILIDAD_EDIT'])->setEnabled(true)
        );

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testRolDeGestionDeUnaSeccionNuevaEntraComoEquipo(): void
    {
        // Vencimientos no estaba en la lista copiada del checker: quien sólo
        // tenía ese rol era tratado como socix y rebotaba.
        $this->checker(false)->checkPreAuth(
            (new User())->setRoles(['ROLE_GESTION_VENCIMIENTOS'])->setEnabled(true)
        );

        $this->addToAssertionCount(1); // no lanzó: pasa
    }
}
