<?php

namespace App\Security;

/**
 * Roles que abren la zona de gestión (`/gestion`). Fuente única: la usan el
 * access_control de `^/gestion` en security.yaml (vía `!php/const`), el
 * dispatcher de después del login y el {@see UserChecker}.
 *
 * Se listan sólo los roles de LECTURA de cada sección: el `_EDIT` llega a ellos
 * por la jerarquía de security.yaml, así que quien compruebe contra esta lista
 * debe hacerlo con la jerarquía resuelta (`isGranted()` o
 * `RoleHierarchyInterface`), nunca contra los roles almacenados en el User.
 *
 * Al añadir una sección de gestión nueva, su rol de lectura va aquí: antes
 * vivía copiada en cuatro sitios y tres se quedaron atrás, con lo que quien
 * sólo tenía, p. ej., ROLE_GESTION_LABORAL acababa en un 403 tras el login.
 */
final class ManagementRoles
{
    public const ALL = [
        'ROLE_ADMIN',
        'ROLE_GESTION_GRANJA',
        'ROLE_GESTION_SOCIXS',
        'ROLE_GESTION_REPARTO',
        'ROLE_BLOG',
        'ROLE_GESTION_ENCUESTAS',
        'ROLE_GESTION_ALBERGUE',
        'ROLE_GESTION_LABORAL',
        'ROLE_GESTION_LAR',
        'ROLE_GESTION_VOLUNTARIADO',
        'ROLE_GESTION_GRUPO_CONSUMO',
        'ROLE_GESTION_CONTABILIDAD',
        'ROLE_GESTION_VENCIMIENTOS',
    ];

    private function __construct()
    {
    }
}
