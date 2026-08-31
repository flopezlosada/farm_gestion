<?php

namespace App\Tests\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notification\NotificationLink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * El destino de un aviso.
 *
 * ESTE TEST ES LA RAZÓN DE SER DE LA CLASE. La bandeja y el aviso del móvil del
 * mismo aviso salen de aquí, así que basta comprobar una vez que cada familia
 * lleva a su pantalla para saber que los dos canales concuerdan. Cuando el destino
 * se calculaba en cada sitio por su cuenta no había forma de comprobar eso sin
 * levantar los dos canales enteros.
 *
 * Y protege el `default`: un `kind` sin línea propia tiene que caer en la bandeja,
 * no en una excepción. Si no, añadir un aviso nuevo y olvidarse de este match
 * dejaría un error 500 al abrirlo, en vez de una pantalla que al menos lo enseña.
 */
class NotificationLinkTest extends TestCase
{
    /**
     * @dataProvider destinos
     */
    public function testCadaFamiliaDeAvisoLlevaASuPantalla(string $kind, string $expectedRoute): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with($expectedRoute)
            ->willReturn('/ruta-generada');

        $link = new NotificationLink($urlGenerator);

        self::assertSame('/ruta-generada', $link->pathForKind($kind));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function destinos(): iterable
    {
        yield 'la cesta lleva al panel' => [Notification::KIND_PICKUP_REMINDER, 'panel'];
        yield 'piden gente lleva a voluntariado' => [Notification::KIND_VOLUNTEERING_CALL, 'panel_volunteering'];
        yield 'te toca lleva a voluntariado' => [Notification::KIND_VOLUNTEERING_REMINDER, 'panel_volunteering'];
        // Un aviso de una familia que aún no existe: cae en la bandeja y no revienta.
        yield 'lo desconocido cae en la bandeja' => ['algo.que.nadie.ha.declarado', 'notification_inbox'];
    }

    public function testElDestinoDeUnAvisoGuardadoSaleDeSuKind(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('panel')->willReturn('/panel');

        $notification = new Notification(
            new User(),
            Notification::KIND_PICKUP_REMINDER,
            'Mañana recoges tu cesta',
        );

        self::assertSame('/panel', (new NotificationLink($urlGenerator))->pathFor($notification));
    }
}
