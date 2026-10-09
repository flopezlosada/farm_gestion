<?php

namespace App\Tests\Controller;

use App\Entity\TelegramLink;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La pantalla de quién usa el bot: sólo para administración, invitar deja una
 * invitación vigente, y quitar o reenviar exigen el token del formulario.
 *
 * En los tests no hay bot configurado (TELEGRAM_BOT_TOKEN vacío), así que la
 * pantalla tiene que decirlo y la invitación queda preparada sin poder mandarse.
 */
class TelegramControllerTest extends AbstractAuthenticatedTest
{
    /** @var list<int> Personas creadas por el test, para borrarlas (con su vínculo). */
    private array $users = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->users as $id) {
            $user = $em->find(User::class, $id);
            if ($user === null) {
                continue;
            }
            foreach ($em->getRepository(TelegramLink::class)->findBy(['user' => $user]) as $link) {
                $em->remove($link);
            }
            $em->remove($user);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testSinAdministracionNoSeEntra(): void
    {
        $client = static::createClient();
        $client->loginUser($this->person(['ROLE_GESTION_CONTABILIDAD_EDIT']));

        $client->request('GET', '/gestion/telegram');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSinBotConfiguradoLaPantallaLoDice(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/telegram');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-callout', 'El bot está apagado');
    }

    public function testInvitarDejaUnaInvitacionVigente(): void
    {
        $client = $this->createAuthenticatedClient();
        $invited = $this->person([]);
        $crawler = $client->request('GET', '/gestion/telegram');

        $form = $crawler->selectButton('Invitar')->form();
        $form['telegram_invite[user]'] = (string) $invited->getId();
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/telegram');
        $link = static::getContainer()->get(TelegramLinkRepository::class)->findForUser($invited);
        $this->assertNotNull($link);
        $this->assertTrue($link->isInvitationValid(new \DateTimeImmutable()));
        $this->assertFalse($link->isLinked());
    }

    /** Sin el token del formulario, quitar a alguien no hace nada. */
    public function testQuitarSinTokenNoQuitaANadie(): void
    {
        $client = $this->createAuthenticatedClient();
        $link = $this->linked($this->person([]));

        $client->request('POST', '/gestion/telegram/' . $link->getId() . '/remove', ['_token' => 'falso']);

        $this->assertResponseRedirects('/gestion/telegram');
        $this->assertNotNull(static::getContainer()->get(EntityManagerInterface::class)->find(TelegramLink::class, $link->getId()));
    }

    public function testQuitarConTokenCortaElAcceso(): void
    {
        $client = $this->createAuthenticatedClient();
        $link = $this->linked($this->person([]));
        $id = $link->getId();
        $crawler = $client->request('GET', '/gestion/telegram');

        $client->submit($crawler->filter(sprintf('form[action="/gestion/telegram/%d/remove"]', $id))->form());

        $this->assertResponseRedirects('/gestion/telegram');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $this->assertNull($em->find(TelegramLink::class, $id));
    }

    /** Invitar no se hace con un GET (un enlace o una imagen no deben poder disparar nada). */
    public function testInvitarSoloPorPost(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/telegram/invite');

        $this->assertResponseStatusCodeSame(405);
    }

    /**
     * @param list<string> $roles
     */
    private function person(array $roles): User
    {
        $user = $this->createUserWithRoles($roles);
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function linked(User $user): TelegramLink
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $link = new TelegramLink($em->find(User::class, $user->getId()));
        $link->link((string) random_int(100000000, 999999999), 'Prueba', new \DateTimeImmutable());
        $em->persist($link);
        $em->flush();

        return $link;
    }
}
