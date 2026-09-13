<?php

namespace App\Service\Partner;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;

/**
 * Lo que dejó un cambio de modalidad: la cesta que se cierra y, cuando la cesta es
 * compartida, la que se le abre al otro hogar.
 *
 * EXISTE PORQUE EL ARRASTRE ERA INVISIBLE. Cambiar la modalidad de una cesta
 * compartida cambia DOS suscripciones, pero quien llamaba al cambio sólo recibía la
 * vieja: no podía reconciliar las entregas ya generadas del otro hogar, ni avisarle,
 * ni decir en pantalla que su cesta también se había movido. Las tres cosas faltaban
 * a la vez, y no por descuido de tres sitios distintos sino porque el dato no salía
 * de aquí. Devolverlo lo arregla en el tipo en vez de pedir a cada caller que
 * adivine si hubo pareja.
 */
final readonly class ModalityChangeOutcome
{
    /**
     * @param PartnerBasketShare      $closed La cesta anterior, ya cerrada en la
     *                                        víspera (o retirada, si nunca llegó a
     *                                        entrar en vigor).
     * @param PartnerBasketShare|null $mate   La cesta nueva del otro hogar, o null si
     *                                        no comparte o si ya estaba como debía.
     */
    public function __construct(
        public PartnerBasketShare $closed,
        public ?PartnerBasketShare $mate = null,
    ) {
    }

    /**
     * El otro hogar al que arrastró el cambio, si lo hubo.
     *
     * @return Partner|null El socix del otro hogar.
     */
    public function matePartner(): ?Partner
    {
        return $this->mate?->getPartner();
    }
}
