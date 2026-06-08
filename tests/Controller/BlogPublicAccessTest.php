<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test de regresión: el blog público es accesible para visitantes anónimos.
 *
 * Protege contra la regresión arreglada en 499586d: el #[IsGranted('ROLE_BLOG')]
 * estaba a nivel de clase en BlogController y bloqueaba también las acciones
 * públicas (frontend_index, show, show_category), mandando al login al entrar
 * en /blog y /blog/category/4 (Recetas).
 *
 * Recetas comparte exactamente la misma cadena de autorización que /blog, así
 * que verificar /blog basta para blindar el caso sin depender de que exista la
 * categoría 4 en la BBDD de test.
 */
class BlogPublicAccessTest extends WebTestCase
{
    /**
     * GET /blog en estado anónimo debe devolver 200, no redirigir al login.
     */
    public function testBlogIsPublicForAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/blog');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
