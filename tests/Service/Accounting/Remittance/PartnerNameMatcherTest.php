<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Service\Accounting\Remittance\PartnerNameMatcher;
use PHPUnit\Framework\TestCase;

/**
 * La ficha de quien paga un recibo cuyo nombre no casa exacto: los casos que salen en
 * los Excel de las remesas (erratas, apellidos cortados por el banco, fichas a las
 * que les falta un nombre o lo tienen en los apellidos). Nombres inventados.
 */
class PartnerNameMatcherTest extends TestCase
{
    private static function partner(string $name, ?string $surname): Partner
    {
        return (new Partner())->setname($name)->setSurname($surname);
    }

    public function testUnaLetraDistintaEsLaMismaPersonaYNoSeTocaLaFicha(): void
    {
        $ficha = self::partner('Marta', 'Rodríguez Fernández');

        $found = PartnerNameMatcher::candidates('Marta Rodríguez Fernándes', [self::partner('Pablo', 'Ruiz Sanz'), $ficha]);

        $this->assertCount(1, $found);
        $this->assertSame($ficha, $found[0]['partner']);
        $this->assertSame(['«Fernándes» en el recibo y «Fernández» en la ficha: una letra distinta'], $found[0]['reasons']);
        $this->assertNull($found[0]['rename'], 'Con una errata no se sabe cuál de los dos está bien.');
    }

    /** El caso de la ficha con el segundo nombre en los apellidos y un apellido de menos. */
    public function testALaFichaLeFaltaUnApellidoYElNombreEstaMalPartido(): void
    {
        $ficha = self::partner('LUIS', 'ANTONIO PRIETO');

        $found = PartnerNameMatcher::candidates('LUIS ANTONIO PRIETO MORALES', [$ficha]);

        $this->assertSame(['a la ficha le falta «MORALES»'], $found[0]['reasons']);
        $this->assertSame(['name' => 'LUIS ANTONIO', 'surname' => 'PRIETO MORALES'], $found[0]['rename']);
    }

    /** El banco corta los nombres largos: la ficha conserva el apellido entero. */
    public function testElApellidoCortadoSeQuedaEnteroAlCorregir(): void
    {
        $ficha = self::partner('ROSA', 'ELENA ARANA ITURRIZ');

        $found = PartnerNameMatcher::candidates('ROSA  ELENA ARANA ITURRI', [$ficha]);

        $this->assertStringContainsString('«ITURRI» en el recibo y «ITURRIZ» en la ficha: uno de los dos está cortado', $found[0]['reasons'][0]);
        $this->assertStringContainsString('partidos de otra forma', $found[0]['reasons'][1]);
        $this->assertSame(['name' => 'ROSA ELENA', 'surname' => 'ARANA ITURRIZ'], $found[0]['rename']);
    }

    public function testElNombreCompuestoQueFaltaEnLaFicha(): void
    {
        $found = PartnerNameMatcher::candidates('Eva María Sola Gil', [self::partner('Eva', 'Sola Gil')]);

        $this->assertSame(['a la ficha le falta «María»'], $found[0]['reasons']);
        $this->assertSame(['name' => 'Eva María', 'surname' => 'Sola Gil'], $found[0]['rename']);
    }

    /** «De La Peña» es un apellido: las partículas van con el suyo. */
    public function testLasParticulasVanConSuApellido(): void
    {
        $found = PartnerNameMatcher::candidates('Inés Clara De La Peña Ruiz', [self::partner('Inés', 'De La Peña Ruiz')]);

        $this->assertSame(['name' => 'Inés Clara', 'surname' => 'De La Peña Ruiz'], $found[0]['rename']);
    }

    /** Hay Excel que escriben «APELLIDOS NOMBRE». */
    public function testElReciboEmpiezaPorLosApellidos(): void
    {
        $found = PartnerNameMatcher::candidates('PRIETO MORALES LUIS ANTONIO', [self::partner('LUIS', 'ANTONIO PRIETO')]);

        $this->assertSame(['name' => 'LUIS ANTONIO', 'surname' => 'PRIETO MORALES'], $found[0]['rename']);
    }

    public function testLaMismaFichaEnOtroOrdenNoSeCorrige(): void
    {
        $found = PartnerNameMatcher::candidates('SOLA GIL EVA', [self::partner('Eva', 'Sola Gil')]);

        $this->assertSame(['el mismo nombre escrito en otro orden'], $found[0]['reasons']);
        $this->assertNull($found[0]['rename']);
    }

    public function testSinNingunaPalabraEnComunNoHayCandidata(): void
    {
        $this->assertSame([], PartnerNameMatcher::candidates('Tomás Ibarra Lago', [self::partner('Eva', 'Sola Gil'), self::partner('Tomás', null)]));
    }

    /** Una ficha cuyas palabras no están todas en el recibo es otra persona. */
    public function testOtraPersonaConUnApellidoIgualNoEsCandidata(): void
    {
        $this->assertSame([], PartnerNameMatcher::candidates('Eva Sola Gil', [self::partner('Ana', 'Sola Gil')]));
    }

    /** Al recibo no le pueden sobrar más de dos palabras: sería casar por un apellido. */
    public function testDemasiadasPalabrasDeMasNoCasan(): void
    {
        $this->assertSame([], PartnerNameMatcher::candidates('Eva María Luisa Teresa Sola Gil', [self::partner('Eva', 'Sola')]));
    }

    public function testLaMasParecidaVaPrimero(): void
    {
        $exact = self::partner('Eva', 'Sola Garrido');
        $typo = self::partner('Eva', 'Sola Garrida');

        $found = PartnerNameMatcher::candidates('Eva Sola Garrido', [$typo, $exact]);

        $this->assertSame([$exact, $typo], array_column($found, 'partner'));
    }

    public function testUnaSolaPalabraNoBasta(): void
    {
        $this->assertSame([], PartnerNameMatcher::candidates('EVA', [self::partner('Eva', 'Sola Gil')]));
    }

    /** Una ficha sin apellidos (un nombre de una palabra) no se propone para nadie. */
    public function testUnaFichaDeUnaSolaPalabraNoSePropone(): void
    {
        $this->assertSame([], PartnerNameMatcher::candidates('Eva Sola Gil', [self::partner('Eva', null)]));
    }
}
