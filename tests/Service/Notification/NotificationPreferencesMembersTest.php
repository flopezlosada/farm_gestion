<?php

namespace App\Tests\Service\Notification;

use App\Entity\Partner;
use App\Repository\NotificationOptOutRepository;
use App\Service\AppSettings;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Los temas de socias (voluntariado, grupo de consumo, encuestas) no son para
 * quien recibe cesta sin ser socia; los de la cesta y las novedades, sí.
 *
 * Se mira en {@see NotificationPreferences} porque es por donde pasa todo envío:
 * así ninguno nuevo se lo salta. Módulos encendidos y nadie silenciado, para que
 * lo único que decida sea si es socia.
 */
class NotificationPreferencesMembersTest extends TestCase
{
    private function preferences(): NotificationPreferences
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn(true);

        $optOuts = $this->createMock(NotificationOptOutRepository::class);
        $optOuts->method('silencedFor')->willReturn([]);
        $optOuts->method('silencedForMany')->willReturn([]);

        return new NotificationPreferences($optOuts, $this->createMock(EntityManagerInterface::class), $settings);
    }

    /**
     * Una ficha con id, como las que salen de la base de datos.
     */
    private function partner(int $id, bool $member): Partner
    {
        $partner = (new Partner())->setName('Ficha')->setMember($member);
        $property = new \ReflectionProperty(Partner::class, 'id');
        $property->setAccessible(true);
        $property->setValue($partner, $id);

        return $partner;
    }

    public function testLaNoSociaRecibeLoDeSuCestaYLasNovedades(): void
    {
        $preferences = $this->preferences();
        $worker = $this->partner(1, false);

        $this->assertTrue($preferences->wants($worker, NotificationTopic::PICKUP, NotificationTopic::CHANNEL_EMAIL));
        $this->assertTrue($preferences->wants($worker, NotificationTopic::NEWS, NotificationTopic::CHANNEL_PUSH));
    }

    public function testLaNoSociaNoRecibeLoDeSocias(): void
    {
        $preferences = $this->preferences();
        $worker = $this->partner(1, false);

        foreach ([NotificationTopic::VOLUNTEERING, NotificationTopic::CONSUMER_GROUP, NotificationTopic::SURVEYS] as $topic) {
            $this->assertFalse($preferences->wants($worker, $topic, NotificationTopic::CHANNEL_EMAIL), $topic);
        }
    }

    public function testLaListaDeEnvioLaDejaFueraSoloEnLoDeSocias(): void
    {
        $preferences = $this->preferences();
        $member = $this->partner(1, true);
        $worker = $this->partner(2, false);

        $this->assertSame([$member], $preferences->filter([$member, $worker], NotificationTopic::SURVEYS, NotificationTopic::CHANNEL_EMAIL));
        $this->assertSame([$member, $worker], $preferences->filter([$member, $worker], NotificationTopic::PICKUP, NotificationTopic::CHANNEL_EMAIL));
    }

    /**
     * En su pantalla de avisos no se le ofrece lo que no le va a llegar.
     */
    public function testSuPantallaDeAvisosSoloEnseñaLoSuyo(): void
    {
        $keys = array_column($this->preferences()->forPartner($this->partner(1, false)), 'key');

        $this->assertSame([NotificationTopic::PICKUP, NotificationTopic::NEWS], $keys);
    }

    /**
     * Y a una socia, todo como siempre.
     */
    public function testLaSociaLoRecibeTodo(): void
    {
        $keys = array_column($this->preferences()->forPartner($this->partner(1, true)), 'key');

        $this->assertSame(array_keys(NotificationTopic::TOPICS), $keys);
    }
}
