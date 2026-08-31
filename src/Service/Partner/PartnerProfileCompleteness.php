<?php

namespace App\Service\Partner;

use App\Entity\Partner;

/**
 * Qué le falta a la ficha de un socix. LA ÚNICA definición de "estar completo".
 *
 * La usan cuatro sitios que tenían que estar de acuerdo o el aviso sería
 * inservible: el aviso al socix, el aviso a quien coordina socixs, el listado de
 * fichas incompletas de gestión y la propia pantalla de "Mis datos". Si cada uno
 * decidiera por su cuenta qué es importante, un socix arreglaría lo que su
 * pantalla le pide y seguiría saliendo en la lista de la coordinadora.
 *
 * EL CATÁLOGO DISTINGUE QUIÉN PUEDE ARREGLAR CADA COSA, y es la decisión que hace
 * que el aviso sirva. Al socix sólo se le pide lo que su propio formulario
 * ({@see \App\Form\PartnerProfileType}) le deja tocar: el teléfono y el correo los
 * cambia administración, así que pedírselos sería un aviso que no puede cerrar y
 * que le volvería cada semana para siempre. A quien coordina se le enseña todo,
 * porque parte de lo que falta es suyo de arreglar.
 *
 * QUÉ NO ESTÁ AQUÍ, y por qué:
 *  - el IBAN: es dato bancario y de tesorería, no personal, y su hueco se
 *    persigue en la remesa y no con un aviso al socix, que no puede tocarlo;
 *  - la periodicidad de pago (`share_payment`): casi ninguna ficha la tiene
 *    informada, así que el aviso saldría a casi todo el mundo y enseñaría a
 *    ignorarlo — que es la única forma de romper un aviso de verdad;
 *  - el nombre: es NotBlank en los dos formularios, no puede faltar.
 */
class PartnerProfileCompleteness
{
    /**
     * Los datos que se consideran importantes, en el orden en que se piden.
     *
     * Cada entrada: la etiqueta con la que se nombra el dato (la misma que lleva
     * el campo en el formulario, para que quien lo lee sepa dónde ir), el getter
     * del que se lee, y si el socix puede arreglarlo por su cuenta.
     *
     * @var list<array{label: string, getter: string, selfService: bool}>
     */
    private const FIELDS = [
        ['label' => 'apellidos', 'getter' => 'getSurname', 'selfService' => true],
        ['label' => 'DNI', 'getter' => 'getDNI', 'selfService' => true],
        ['label' => 'dirección', 'getter' => 'getAddress', 'selfService' => true],
        ['label' => 'provincia', 'getter' => 'getState', 'selfService' => true],
        ['label' => 'municipio', 'getter' => 'getCity', 'selfService' => true],
        // Los cambia administración: son la credencial de acceso (el correo) y el
        // factor de recuperación del enlace de entrada (el teléfono).
        ['label' => 'teléfono', 'getter' => 'getcelular', 'selfService' => false],
        ['label' => 'correo electrónico', 'getter' => 'getemail', 'selfService' => false],
    ];

    /**
     * Lo que le falta a esta ficha, con la etiqueta de cada dato.
     *
     * @param Partner $partner el socix
     *
     * @return list<string> las etiquetas de lo que falta; vacío si está completa
     */
    public function missing(Partner $partner): array
    {
        return $this->collect($partner, null);
    }

    /**
     * Lo que le falta Y puede arreglar el socix desde su pantalla de datos.
     *
     * Es lo que se le pide a él. Una ficha a la que sólo le falta el teléfono
     * devuelve una lista vacía por aquí: no hay nada que él pueda hacer, y el
     * aviso iría a quien sí puede.
     *
     * @param Partner $partner el socix
     *
     * @return list<string> las etiquetas de lo que falta y él puede rellenar
     */
    public function missingSelfService(Partner $partner): array
    {
        return $this->collect($partner, true);
    }

    /**
     * Si a esta ficha le falta algo importante, sea de quien sea arreglarlo.
     *
     * @param Partner $partner el socix
     *
     * @return bool true si falta algo
     */
    public function isIncomplete(Partner $partner): bool
    {
        return [] !== $this->missing($partner);
    }

    /**
     * A quién tiene sentido perseguirle la ficha.
     *
     * SÓLO SOCIXS ACTIVOS: a quien está de baja o en pausa no se le pide que
     * complete nada, y en la lista de la coordinadora serían ruido permanente que
     * nadie va a resolver.
     *
     * Y SÓLO LA CABEZA DE UNA FAMILIA (sin `parent`): las fichas de familiares
     * comparten dirección, teléfono y correo con la principal y por diseño los
     * llevan vacíos ({@see \App\Entity\Partner::getParent()}). Contarlas como
     * incompletas señalaría como problema justo lo que el modelo hace a propósito.
     *
     * @param Partner $partner el socix
     *
     * @return bool true si se le puede perseguir la ficha
     */
    public function applies(Partner $partner): bool
    {
        return Partner::STATUS_ACTIVO === $partner->getStatus() && null === $partner->getParent();
    }

    /**
     * Lo que falta, filtrando opcionalmente por quién lo arregla.
     *
     * Un dato se considera ausente cuando está a null o en blanco. `trim()` sobre
     * la cadena a propósito: el dump de producción trae campos con un espacio
     * suelto, y un espacio no es un DNI.
     *
     * @param Partner   $partner     el socix
     * @param bool|null $selfService null = todo; true = sólo lo que arregla el socix
     *
     * @return list<string> las etiquetas de lo que falta
     */
    private function collect(Partner $partner, ?bool $selfService): array
    {
        $missing = [];

        foreach (self::FIELDS as $field) {
            if (null !== $selfService && $field['selfService'] !== $selfService) {
                continue;
            }

            $value = $partner->{$field['getter']}();
            $empty = null === $value || (\is_string($value) && '' === trim($value));

            if ($empty) {
                $missing[] = $field['label'];
            }
        }

        return $missing;
    }
}
