<?php

namespace App\Tests\Http;

use App\Http\QueryId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * El id de un filtro de la URL, tal como lo mandan los formularios GET.
 */
class QueryIdTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, ?int}>
     */
    public static function queries(): iterable
    {
        yield 'id' => [['category' => '12'], 12];
        yield 'opción «Todas» del desplegable' => [['category' => ''], null];
        yield 'sin el parámetro' => [[], null];
        yield 'cero' => [['category' => '0'], null];
        yield 'negativo' => [['category' => '-3'], null];
        yield 'texto' => [['category' => 'abc'], null];
        yield 'decimal' => [['category' => '1.5'], null];
        yield 'con espacios alrededor' => [['category' => ' 7 '], 7];
        yield 'varios valores (category[]=)' => [['category' => ['1', '2']], null];
    }

    /**
     * @dataProvider queries
     *
     * @param array<string, mixed> $query
     */
    public function testFrom(array $query, ?int $expected): void
    {
        $this->assertSame($expected, QueryId::from(new InputBag($query), 'category'));
    }
}
