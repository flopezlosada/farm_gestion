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
            '-' => ['VARIOS', 'Tierra'],
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
     * Líneas del Excel del PRESUPUESTO. Es un vocabulario distinto del libro —más
     * grueso— y ésa es justamente la razón de que hoy el seguimiento haya que
     * teclearlo a mano: no hay puente automático entre las dos hojas.
     *
     * La clave lleva el bloque delante porque «Formación» y «Grupo de Consumo»
     * aparecen en ingresos y en gastos con el mismo nombre.
     *
     * Una simplificación consciente: los tres conceptos salariales del presupuesto
     * (salarios, temporeros y bonus) van todos a «Nóminas». El grupo, que es con lo
     * que se compara, sigue cuadrando.
     *
     * En cambio el suelo NO se simplifica: el alquiler de la finca va a
     * administración y la tierra a varios, cada uno al grupo donde lo pone el
     * presupuesto. Juntarlos movería 1.830 € de grupo y descuadraría la comparación
     * con los subtotales que la asociación conoce.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BUDGET_LINES = [
        'INGRESOS|CUOTA' => ['INGRESOS', 'Cuotas de socixs'],
        'INGRESOS|CUOTA - MANTENIMIENTO' => ['INGRESOS', 'Cuota de mantenimiento'],
        'INGRESOS|DONACIÓN7SUBVENCIÓN' => ['INGRESOS', 'Donaciones y subvenciones'],
        'INGRESOS|DONACIÓN/SUBVENCIÓN' => ['INGRESOS', 'Donaciones y subvenciones'],
        'INGRESOS|FORMACIÓN' => ['INGRESOS', 'Formación'],
        'INGRESOS|GRUPO DE CONSUMO' => ['INGRESOS', 'Grupo de consumo'],
        'INGRESOS|MANCOMUNIDAD' => ['INGRESOS', 'Varios'],
        'INGRESOS|DERRAMA' => ['INGRESOS', 'Varios'],
        'INGRESOS|VARIOS' => ['INGRESOS', 'Varios'],

        'GASTOS|ADMIN' => ['ADMINISTRACIÓN', 'Administración y gestoría'],
        'GASTOS|COMISIONES' => ['ADMINISTRACIÓN', 'Comisiones bancarias'],
        'GASTOS|TELÉFONO' => ['ADMINISTRACIÓN', 'Teléfono'],
        'GASTOS|LOCAL' => ['ADMINISTRACIÓN', 'Local'],
        'GASTOS|FORMACIÓN' => ['ADMINISTRACIÓN', 'Formación'],
        'GASTOS|IMPUESTOS' => ['ADMINISTRACIÓN', 'Impuestos'],
        'GASTOS|SEGUROS' => ['ADMINISTRACIÓN', 'Seguros'],
        'GASTOS|ALQUILER FINCA' => ['ADMINISTRACIÓN', 'Alquiler de la finca'],
        'GASTOS|TIERRA' => ['VARIOS', 'Tierra'],
        'GASTOS|HUERTA' => ['HUERTA', 'Huerta'],
        'GASTOS|SEMILLAS Y PLANTEL' => ['HUERTA', 'Semillas y plantel'],
        'GASTOS|GASOLINA' => ['HUERTA', 'Gasolina'],
        'GASTOS|ESTIERCOL + BIOLES' => ['HUERTA', 'Estiércol y biopreparados'],
        'GASTOS|MANTENIMIENTO MAQUINARIA Y TRANSPORTE' => ['HUERTA', 'Mantenimiento y reparaciones'],
        'GASTOS|SUELDOS - GASTOS SALARIALES' => ['SUELDOS', 'Nóminas'],
        'GASTOS|SUELDOS TEMPOREROS' => ['SUELDOS', 'Nóminas'],
        'GASTOS|BONUS' => ['SUELDOS', 'Nóminas'],
        'GASTOS|IRPF' => ['SUELDOS', 'IRPF'],
        'GASTOS|GRANO GALLINAS/HUEVOS COMPRADOS' => ['HUEVOS', 'Grano y huevos comprados'],
        'GASTOS|GRUPO DE CONSUMO' => ['VARIOS', 'Grupo de consumo'],
        'GASTOS|TRANSPORTE' => ['VARIOS', 'Transporte'],
        'GASTOS|VARIOS' => ['VARIOS', 'Varios'],

        'FINANCIACION|PRÉSTAMOS SOCIOS' => ['FINANCIACIÓN', 'Préstamos recibidos'],
        'FINANCIACION|CUOTAS PRÉSTAMO' => ['FINANCIACIÓN', 'Cuotas de préstamo'],
    ];

    /**
     * Grupo y partida para una línea del Excel del presupuesto. Todo lo que vaya en
     * el bloque de inversión cae en la única partida de inversión: allí cada línea
     * es una compra concreta (casetas, gallinas, tractor) y presupuestarlas por
     * separado sólo sirve para que la mayoría queden a cero todo el año.
     *
     * @return array{0: string, 1: string}|null
     */
    public function resolveBudgetLine(string $block, string $label): ?array
    {
        $blockKey = $this->normalize($block);
        if (str_starts_with($blockKey, 'INVERSI')) {
            return ['INVERSIÓN', 'Inversión'];
        }

        return self::BUDGET_LINES[$blockKey.'|'.$this->normalize($label)] ?? null;
    }

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
