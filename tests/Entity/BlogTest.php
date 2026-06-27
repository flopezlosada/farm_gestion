<?php

namespace App\Tests\Entity;

use App\Entity\Blog;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del contador de visitas de {@see Blog}. La entrada nace con 0
 * visitas y cada incremento suma una; el getter siempre devuelve un entero.
 */
class BlogTest extends TestCase
{
    public function testNaceConCeroVisitas(): void
    {
        $this->assertSame(0, (new Blog())->getViews());
    }

    public function testIncrementViewsSumaUna(): void
    {
        $blog = new Blog();
        $blog->incrementViews();
        $blog->incrementViews();

        $this->assertSame(2, $blog->getViews());
    }

    public function testSetViewsNormalizaAEntero(): void
    {
        $blog = (new Blog())->setViews('42');

        $this->assertSame(42, $blog->getViews());
    }
}
