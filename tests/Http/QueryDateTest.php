<?php

namespace App\Tests\Http;

use App\Http\QueryDate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

/** Una fecha de la URL: o es un día real en `Y-m-d`, o no hay fecha. */
class QueryDateTest extends TestCase
{
    public function testValidDate(): void
    {
        $this->assertSame('2026-11-09', QueryDate::from(new InputBag(['start' => '2026-11-09']), 'start')?->format('Y-m-d'));
    }

    /**
     * @dataProvider noDate
     */
    public function testAnythingElseIsNoDate(array $query): void
    {
        $this->assertNull(QueryDate::from(new InputBag($query), 'start'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function noDate(): iterable
    {
        yield 'ausente' => [[]];
        yield 'vacía' => [['start' => '']];
        yield 'basura' => [['start' => 'mañana']];
        yield 'otro formato' => [['start' => '09/11/2026']];
        yield 'día imposible' => [['start' => '2026-02-31']];
        yield 'array' => [['start' => ['2026-11-09']]];
    }
}
