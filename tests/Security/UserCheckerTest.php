<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

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

        (new UserChecker($this->settings(false)))->checkPreAuth($this->socix());
    }

    public function testSocixAdmitidoConElAccesoAbierto(): void
    {
        (new UserChecker($this->settings(true)))->checkPreAuth($this->socix());

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testElEquipoEntraAunqueElAccesoDeSocixsEsteCerrado(): void
    {
        (new UserChecker($this->settings(false)))->checkPreAuth($this->teamMember());

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testCuentaBloqueadaRechazadaAunqueElAccesoEsteAbierto(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        (new UserChecker($this->settings(true)))->checkPreAuth($this->socix()->setEnabled(false));
    }

    public function testSocixConAccesoAnticipadoEntraAunqueElGrifoEsteCerrado(): void
    {
        (new UserChecker($this->settings(false)))->checkPreAuth($this->socix()->setEarlyAccess(true));

        $this->addToAssertionCount(1); // no lanzó: pasa
    }

    public function testCuentaBloqueadaRechazadaAunqueTengaAccesoAnticipado(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        (new UserChecker($this->settings(false)))
            ->checkPreAuth($this->socix()->setEarlyAccess(true)->setEnabled(false));
    }
}
