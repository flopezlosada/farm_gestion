<?php

namespace App\Service\Survey;

use App\Entity\Partner;
use App\Entity\Survey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * El enlace personal con el que una socia responde una encuesta SIN ENTRAR en
 * la web.
 *
 * EXISTE POR LA COBERTURA. De las socias activas con correo, sólo una minoría
 * tiene cuenta para entrar; un correo que dijera "entra en tu panel a
 * responder" dejaría fuera a casi todo el mundo. El enlace lleva firmadas la
 * encuesta y la socia, así que quien lo abre responde como ella sin
 * contraseña.
 *
 * SÓLO SIRVE PARA ESO: no abre sesión, no enseña el panel y no vale para otra
 * encuesta ni para otra socia (cambiar un número rompe la firma).
 *
 * NO CADUCA POR SÍ MISMO, a propósito. Lo que decide si se puede responder es
 * la encuesta ({@see Survey::acceptsResponses()}). Si el enlace caducara con el
 * plazo y el equipo lo ampliara después, los enlaces ya enviados dejarían de
 * funcionar sin motivo aparente.
 *
 * EL RIESGO ASUMIDO: quien reciba el correo reenviado puede responder en nombre
 * de la socia, una sola vez. Es el mismo que ya tiene el propio correo, y para
 * una encuesta interna de la asociación se acepta.
 */
class SurveyLink
{
    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * La URL absoluta y firmada para que esta socia responda esta encuesta.
     *
     * @param Survey  $survey  la encuesta
     * @param Partner $partner la socia destinataria
     */
    public function forPartner(Survey $survey, Partner $partner): string
    {
        return $this->signer->sign($this->urls->generate(
            'survey_public_respond',
            ['id' => $survey->getId(), 'partner' => $partner->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }

    /**
     * ¿La petición trae una firma válida? Cubre la ruta entera, así que un id de
     * encuesta o de socia cambiado a mano no pasa.
     */
    public function isValid(Request $request): bool
    {
        return $this->signer->checkRequest($request);
    }
}
