<?php

namespace App\Security;

use App\Service\AppSettings;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Traduce los feature-flags de {@see AppSettings} a atributos de seguridad, de
 * modo que un toggle apagado se comporta como un permiso denegado: tanto en
 * controllers ({@see \Symfony\Component\Security\Http\Attribute\IsGranted})
 * como en plantillas ({@see is_granted('FEATURE_…')}) se usa el mismo mecanismo
 * que para los roles, sin repetir la lectura del ajuste por todo el código.
 *
 * El flag de acceso de socixs ({@see AppSettings::FEATURE_PARTNER_LOGIN}) NO
 * vive aquí: gobierna a quien aún no se ha autenticado y se resuelve en
 * {@see UserChecker}.
 */
class FeatureVoter extends Voter
{
    /**
     * Atributo de seguridad => clave booleana de {@see AppSettings}. Añadir un
     * feature-flag gobernable por permiso es añadir una entrada aquí.
     */
    private const FEATURES = [
        'FEATURE_PARTNER_SELFSERVICE' => AppSettings::FEATURE_PARTNER_SELFSERVICE,
        'FEATURE_SURVEYS' => AppSettings::FEATURE_SURVEYS,
    ];

    public function __construct(private readonly AppSettings $settings)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::FEATURES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return $this->settings->getBool(self::FEATURES[$attribute]);
    }
}
