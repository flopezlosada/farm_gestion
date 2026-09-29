<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ayuda de uso del back-office ({@see /gestion/help}).
 *
 * Páginas estáticas para el equipo de administración: explican en lenguaje
 * llano las tareas del día a día (socios, reparto, cosechas) para que
 * cualquiera —independientemente de su soltura con el ordenador— pueda
 * resolver dudas sin depender de otra persona, y para que los traspasos de
 * conocimiento partan de una base escrita.
 *
 * Una portada con acceso por temas y una página por área (en vez de un único
 * scroll largo), para dar foco. No lleva {@see IsGranted} propio: el firewall
 * ^/gestion ya restringe el acceso a quien tiene algún rol de gestión, y la
 * ayuda es transversal a todas las áreas.
 */
class HelpController extends AbstractController
{
    /**
     * Metadatos de presentación de cada área de ayuda. Fuente única que
     * alimenta tanto las tarjetas de la portada como las pestañas y la
     * cabecera de cada página de área. La clave es el slug de la URL.
     *
     * @var array<string, array{label: string, icon: string, accent: string, lead: string, feature?: string}>
     */
    private const SECTIONS = [
        'socios' => [
            'label' => 'Socios',
            'icon' => 'fa-people-carry',
            'accent' => 'green',
            'lead' => 'Dar de alta, asignar cesta, editar la ficha y dar de baja.',
        ],
        'reparto' => [
            'label' => 'Reparto',
            'icon' => 'fa-cart-arrow-down',
            'accent' => 'sky',
            'lead' => 'Ver la semana, cambios puntuales, otros nodos y festivos.',
        ],
        'cosechas' => [
            'label' => 'Cosechas y huevos',
            'icon' => 'fa-egg',
            'accent' => 'ochre',
            'lead' => 'Registrar y consultar las puestas de huevos.',
        ],
        'encuestas' => [
            'label' => 'Encuestas',
            'icon' => 'fa-poll',
            'accent' => 'plum',
            'lead' => 'Preparar una encuesta, abrirla y avisar, y leer los resultados.',
            // Sólo con el módulo encendido: una guía de algo que no aparece
            // en el menú confunde más que ayuda.
            'feature' => 'FEATURE_SURVEYS',
        ],
    ];

    /**
     * Los slugs válidos, para el `requirements` de las rutas. Tiene que casar
     * con las claves de {@see SECTIONS}: un atributo PHP no puede leerlas.
     */
    private const SLUGS = 'socios|reparto|cosechas|encuestas';

    #[Route('/gestion/help', name: 'help_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help/index.html.twig', [
            'sections' => $this->visibleSections(),
        ]);
    }

    /**
     * Página de un área concreta. El slug se valida en el propio routing
     * (requirements), así que un valor fuera de la lista devuelve 404.
     */
    #[Route('/gestion/help/{section}', name: 'help_section', methods: ['GET'], requirements: ['section' => self::SLUGS])]
    public function section(string $section): Response
    {
        $this->denyUnlessVisible($section);

        return $this->render('help/section.html.twig', [
            'sections' => $this->visibleSections(),
            'current' => $section,
        ]);
    }

    /**
     * Devuelve solo las tarjetas de un área (sin el shell), para inyectarlas
     * en el modal de ayuda contextual que abren los «?». Mismo parcial que pinta
     * {@see section()}, así que el contenido vive en un único sitio: la guía y
     * el modal nunca se desincronizan.
     */
    #[Route('/gestion/help/{section}/fragment', name: 'help_fragment', methods: ['GET'], requirements: ['section' => self::SLUGS])]
    public function fragment(string $section): Response
    {
        $this->denyUnlessVisible($section);

        return $this->render('help/_' . $section . '.html.twig');
    }

    /**
     * Las áreas que se enseñan a quien mira: todas menos las de un módulo
     * apagado.
     *
     * @return array<string, array<string, string>>
     */
    private function visibleSections(): array
    {
        return array_filter(
            self::SECTIONS,
            fn (array $meta): bool => !isset($meta['feature']) || $this->isGranted($meta['feature'])
        );
    }

    /**
     * 404 para el área de un módulo apagado, igual que un slug que no existe.
     */
    private function denyUnlessVisible(string $section): void
    {
        if (!isset($this->visibleSections()[$section])) {
            throw $this->createNotFoundException();
        }
    }
}
