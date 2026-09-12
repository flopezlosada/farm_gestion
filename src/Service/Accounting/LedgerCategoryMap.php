<?php

namespace App\Service\Accounting;

/**
 * Traduce la etiqueta que lleva cada apunte en el libro de Excel (la columna que
 * allí se llama «CUENTA») a la partida normalizada del catálogo.
 *
 * Existe porque esa columna se teclea libre, así que en 2026 conviven 52 etiquetas
 * distintas para muchas menos cosas: erratas (`TRASPASO` y `TRAPASO`), la misma
 * cosa escrita de tres formas (`LA CERRADA`, `LA CEERADA`, `CERRADA`), proveedores
 * usados como partida (`HERMANOS SALAZAR`, `FERRETERIA`) y hasta un concepto entero
 * (`TRANSPORTE DAVID MAYO`).
 *
 * Algunas etiquetas son DOS partidas según el signo: bajo `FORMACION` conviven lo
 * que se cobra por los cursos y lo que se paga a quien los da, que el presupuesto
 * ya presenta por separado. Igual `HUEVOS` (vender docenas / comprar grano),
 * `GRUPO CONSUMO` (cobrar a las socias / pagar al productor) y `PRESTAMO` (recibirlo
 * / devolverlo).
 *
 * Sólo se usa al importar el histórico. A partir de ahí la partida se elige en el
 * formulario y esta clase deja de tener trabajo.
 */
class LedgerCategoryMap
{
    /**
     * Etiquetas cuya partida NO depende del signo. Valor: [grupo, partida].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FIXED = [
        'CUOTAS' => ['INGRESOS', 'Cuotas de socixs'],
        'ANUAL' => ['INGRESOS', 'Cuotas de socixs'],
        'SEMESTRAL' => ['INGRESOS', 'Cuota de mantenimiento'],
        'INSCRIPCIÓN' => ['INGRESOS', 'Inscripciones'],
        'INSCRIPCION' => ['INGRESOS', 'Inscripciones'],
        'DONACIÓN' => ['INGRESOS', 'Donaciones y subvenciones'],
        'DONACION' => ['INGRESOS', 'Donaciones y subvenciones'],
        'SUBVENCION' => ['INGRESOS', 'Donaciones y subvenciones'],
        'GC-CSA' => ['INGRESOS', 'Grupo de consumo en el local'],
        'ARTESANIA' => ['INGRESOS', 'Feria'],
        'PAELLA' => ['INGRESOS', 'Feria'],
        'ENCURTIDOS' => ['INGRESOS', 'Feria'],
        'BEBIDA FERIA' => ['INGRESOS', 'Feria'],
        'BOTE LOCAL' => ['INGRESOS', 'Varios'],
        'VISITA DIDÁCTICA' => ['INGRESOS', 'Varios'],
        'VISITA DIDACTICA' => ['INGRESOS', 'Varios'],

        'ADMINISTRACIÓN' => ['ADMINISTRACIÓN', 'Administración y gestoría'],
        'ADMINISTRACION' => ['ADMINISTRACIÓN', 'Administración y gestoría'],
        'COMISION' => ['ADMINISTRACIÓN', 'Comisiones bancarias'],
        'TELEFONO' => ['ADMINISTRACIÓN', 'Teléfono'],
        'LOCAL' => ['ADMINISTRACIÓN', 'Local'],
        'SEGURO' => ['ADMINISTRACIÓN', 'Seguros'],
        'SEGUROS' => ['ADMINISTRACIÓN', 'Seguros'],
        'IMPUESTOS' => ['ADMINISTRACIÓN', 'Impuestos'],
        'IVA' => ['ADMINISTRACIÓN', 'Impuestos'],
        'PRL' => ['ADMINISTRACIÓN', 'Prevención de riesgos'],

        'HUERTA' => ['HUERTA', 'Huerta'],
        'HERMANOS SALAZAR' => ['HUERTA', 'Huerta'],
        'FERRETERIA' => ['HUERTA', 'Huerta'],
        'LA CERRADA' => ['HUERTA', 'Huerta'],
        'LA CEERADA' => ['HUERTA', 'Huerta'],
        'CERRADA' => ['HUERTA', 'Huerta'],
        'PLANTON' => ['HUERTA', 'Semillas y plantel'],
        'GASOLINA' => ['HUERTA', 'Gasolina'],
        'REPARACIONES' => ['HUERTA', 'Mantenimiento y reparaciones'],
        'ITV' => ['HUERTA', 'Mantenimiento y reparaciones'],
        'BIOPREPARADOS' => ['HUERTA', 'Estiércol y biopreparados'],

        'NOMINA' => ['SUELDOS', 'Nóminas'],
        'SEG. SOCIAL' => ['SUELDOS', 'Seguridad Social'],
        'SEG.SOCIAL' => ['SUELDOS', 'Seguridad Social'],
        'IRPF' => ['SUELDOS', 'IRPF'],

        'TRANSPORTE' => ['VARIOS', 'Transporte'],
        'TRANSPORTE DAVID MAYO' => ['VARIOS', 'Transporte'],
        'PERROS' => ['VARIOS', 'Perros'],
        'WOOFER' => ['VARIOS', 'Voluntariado internacional'],
        'VARIOS' => ['VARIOS', 'Varios'],

        'INVERSION' => ['INVERSIÓN', 'Inversión'],
        'INVERSIÓN' => ['INVERSIÓN', 'Inversión'],

        'TRASPASO' => ['TRASPASOS', 'Traspaso entre cuentas'],
        'TRAPASO' => ['TRASPASOS', 'Traspaso entre cuentas'],
    ];

    /**
     * Etiquetas que son dos partidas distintas según entre o salga el dinero.
     * Valor: ['+' => [grupo, partida], '-' => [grupo, partida]].
     *
     * @var array<string, array{'+': array{0: string, 1: string}, '-': array{0: string, 1: string}}>
     */
    private const BY_SIGN = [
        'FORMACION' => [
            '+' => ['INGRESOS', 'Formación'],
            '-' => ['ADMINISTRACIÓN', 'Formación'],
        ],
        'GRUPO CONSUMO' => [
            '+' => ['INGRESOS', 'Grupo de consumo'],
            '-' => ['VARIOS', 'Grupo de consumo'],
        ],
        'GRUPO DE CONSUMO' => [
            '+' => ['INGRESOS', 'Grupo de consumo'],
            '-' => ['VARIOS', 'Grupo de consumo'],
        ],
        'HUEVOS' => [
            '+' => ['INGRESOS', 'Venta de huevos'],
            '-' => ['HUEVOS', 'Grano y huevos comprados'],
        ],
        'FERIA' => [
            '+' => ['INGRESOS', 'Feria'],
            '-' => ['VARIOS', 'Feria'],
        ],
        'TIERRA' => [
            '+' => ['INGRESOS', 'Venta de tierra'],
            '-' => ['HUERTA', 'Tierra'],
        ],
        'PRESTAMO' => [
            '+' => ['FINANCIACIÓN', 'Préstamos recibidos'],
            '-' => ['FINANCIACIÓN', 'Cuotas de préstamo'],
        ],
        'PRÉSTAMO' => [
            '+' => ['FINANCIACIÓN', 'Préstamos recibidos'],
            '-' => ['FINANCIACIÓN', 'Cuotas de préstamo'],
        ],
        'OTROS' => [
            '+' => ['INGRESOS', 'Varios'],
            '-' => ['VARIOS', 'Varios'],
        ],
        'PRODUCTORES' => [
            '+' => ['INGRESOS', 'Varios'],
            '-' => ['VARIOS', 'Varios'],
        ],
    ];

    /**
     * Grupo y partida para una etiqueta del libro y un importe con signo, o null si
     * la etiqueta no se reconoce (para que la importación la liste en vez de
     * inventarse una partida).
     *
     * @return array{0: string, 1: string}|null
     */
    public function resolve(string $label, float $amount): ?array
    {
        $key = $this->normalize($label);

        if (isset(self::FIXED[$key])) {
            return self::FIXED[$key];
        }

        if (isset(self::BY_SIGN[$key])) {
            return self::BY_SIGN[$key][$amount >= 0 ? '+' : '-'];
        }

        return null;
    }

    /** Etiquetas conocidas, para que la importación avise de las que no lo son. */
    public function knownLabels(): array
    {
        return array_merge(array_keys(self::FIXED), array_keys(self::BY_SIGN));
    }

    /** Mayúsculas y sin espacios de más: el Excel las trae de cualquier manera. */
    private function normalize(string $label): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $label)));
    }
}
