<?php

namespace App\Tests\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests del predicado "¿este socix puede entrar (o llegar a entrar) a la web?",
 * que decide tanto el gate de provisioning como si los emails pintan enlaces
 * de acción que exigen login.
 */
class PartnerAccessPolicyTest extends TestCase
{
    /**
     * Construye la política con un lookup de User fijo y el toggle de alta fijo.
     *
     * @param User|null $account lo que devuelve el lookup por email canónico
     * @param bool      $registrationOpen valor del toggle de alta
     */
    private function policy(?User $account, bool $registrationOpen): PartnerAccessPolicy
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('loadUserByIdentifier')->willReturn($account);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($registrationOpen);

        return new PartnerAccessPolicy($userRepository, $settings);
    }

    /**
     * El email canónico es trim + minúsculas; sin email (o en blanco) es null.
     */
    public function testCanonicalEmail(): void
    {
        $policy = $this->policy(null, false);

        $this->assertSame('ana@example.com', $policy->canonicalEmailOf((new Partner())->setEmail('  Ana@Example.COM ')));
        $this->assertNull($policy->canonicalEmailOf(new Partner()));
        $this->assertNull($policy->canonicalEmailOf((new Partner())->setEmail('   ')));
    }

    /**
     * Con cuenta habilitada hay enlaces de acción, esté el alta como esté.
     */
    public function testCanUseActionLinksWithEnabledAccount(): void
    {
        $account = (new User())->setEnabled(true);
        $partner = (new Partner())->setEmail('socia@example.com');

        $this->assertTrue($this->policy($account, false)->canUseActionLinks($partner));
    }

    /**
     * Una cuenta bloqueada (enabled=false) no recibe enlaces de acción aunque
     * el alta esté abierta: bloqueada significa que NO debe entrar.
     */
    public function testCanUseActionLinksWithDisabledAccount(): void
    {
        $account = (new User())->setEnabled(false);
        $partner = (new Partner())->setEmail('bloqueada@example.com');

        $this->assertFalse($this->policy($account, true)->canUseActionLinks($partner));
    }

    /**
     * Sin cuenta: depende del alta. Abierta ⇒ puede creársela (enlaces sí);
     * cerrada ⇒ no puede entrar (enlaces no).
     */
    public function testCanUseActionLinksWithoutAccountDependsOnRegistration(): void
    {
        $partner = (new Partner())->setEmail('sin.cuenta@example.com');

        $this->assertTrue($this->policy(null, true)->canUseActionLinks($partner));
        $this->assertFalse($this->policy(null, false)->canUseActionLinks($partner));
    }

    /**
     * Sin email no hay acceso posible, ni con el alta abierta.
     */
    public function testCanUseActionLinksWithoutEmail(): void
    {
        $this->assertFalse($this->policy(null, true)->canUseActionLinks(new Partner()));
    }
}
