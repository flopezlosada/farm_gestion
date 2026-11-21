<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * El desglose del precio del grupo de consumo (precio del productor + incremento de
 * gestión) solo lo conocen la comisión del grupo y contabilidad. Las socias ven el
 * precio final, en la web, en los correos y en las notificaciones.
 *
 * Este test fija DÓNDE puede aparecer el desglose: cualquier fichero nuevo de `src/` o
 * `templates/` que lo nombre hace fallar el test, y quien lo añada tiene que decidir a
 * conciencia que ese sitio es de gestión y apuntarlo aquí.
 */
class PriceBreakdownVisibilityTest extends TestCase
{
    /** Nombres que delatan el desglose, en PHP y en el formulario. */
    private const BREAKDOWN_TOKENS = [
        'producerPrice', 'producer_price', 'ProducerPrice',
        'markupType', 'markup_type', 'markupValue', 'markup_value', 'markupPendingReview', 'markup_pending_review',
        'getMarkup', 'getOwnMarkup', 'getEffectiveMarkup', 'setMarkup', 'setOwnMarkup',
        'round_markup',
    ];

    /** Ficheros de `src/` y `templates/` (relativos a la raíz) que pueden nombrar el desglose. */
    private const ALLOWED = [
        // Modelo y cálculo.
        'src/Entity/ConsumerGroupRound.php',
        'src/Entity/ConsumerGroupRoundItem.php',
        'src/Entity/MarkupType.php',
        'src/Repository/ConsumerGroupRoundItemRepository.php',
        'src/Service/ConsumerGroup/Markup.php',
        'src/Service/ConsumerGroup/RoundItemEditor.php',
        'src/Service/ConsumerGroup/RoundPricing.php',
        'src/Service/ConsumerGroup/RoundStateMachine.php',
        'src/Migrations/Version20261121100000.php',
        // Pantallas de gestión del grupo de consumo (ROLE_GESTION_GRUPO_CONSUMO).
        'src/Controller/ConsumerGroupController.php',
        'templates/consumer_group/items.html.twig',
        'templates/consumer_group/show.html.twig',
    ];

    public function testElDesgloseSoloApareceEnLosFicherosDeGestionPermitidos(): void
    {
        $root = \dirname(__DIR__, 2);
        $finder = (new Finder())->files()->in([$root . '/src', $root . '/templates'])->name(['*.php', '*.twig']);

        $leaks = [];
        foreach ($finder as $file) {
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (\in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            $content = $file->getContents();
            foreach (self::BREAKDOWN_TOKENS as $token) {
                if (str_contains($content, $token)) {
                    $leaks[] = $relative . ' → ' . $token;
                    break;
                }
            }
        }

        self::assertSame([], $leaks, "El desglose del precio no puede salir de las pantallas de gestión:\n" . implode("\n", $leaks));
    }

    public function testLasPlantillasDeGestionQueLoPintanEstanProtegidasPorRol(): void
    {
        $root = \dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/src/Controller/ConsumerGroupController.php');

        self::assertStringContainsString("#[IsGranted('ROLE_GESTION_GRUPO_CONSUMO')]", $controller, 'El controller que pinta el desglose exige el rol del grupo de consumo');

        $show = file_get_contents($root . '/templates/consumer_group/show.html.twig');
        self::assertMatchesRegularExpression(
            "/producerPrice is not null and \(is_granted\('ROLE_GESTION_GRUPO_CONSUMO'\) or is_granted\('ROLE_GESTION_CONTABILIDAD'\)\)/",
            $show,
            'El desglose de la ficha del pedido está además protegido en la propia plantilla',
        );
    }
}
