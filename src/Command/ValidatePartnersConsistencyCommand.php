<?php

namespace App\Command;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnóstico de coherencia del modelo de socios. SÓLO LECTURA: lista
 * incoherencias para que un humano las arregle; no toca datos.
 *
 * Pensado para correr antes de la importación real a producción y de tanto
 * en tanto sobre el snapshot, donde sabemos que hay datos cargados a mano
 * que nunca se ejercitaron contra flujos reales.
 *
 * Devuelve FAILURE si encuentra alguna incoherencia (útil para encadenarlo
 * en un pipeline), SUCCESS si todo cuadra.
 *
 * Sub-fase 8.8d (2026-05-27).
 */
#[AsCommand(
    name: 'app:validate-partners-consistency',
    description: 'Lista incoherencias en el modelo de socios (sólo lectura).'
)]
class ValidatePartnersConsistencyCommand extends Command
{
    /** ID de BasketShare semanal en el catálogo. */
    private const SHARE_WEEKLY = 1;
    /** ID de BasketShare quincenal. */
    private const SHARE_BIWEEKLY = 2;
    /** ID de BasketShare mensual. */
    private const SHARE_MONTHLY = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $partners = $this->em->getRepository(Partner::class)->findBy(['is_active' => 1]);
        $activeShares = $this->em->getRepository(PartnerBasketShare::class)->findBy(['is_active' => 1]);

        $groups = [
            'share_partner no recíproco' => $this->checkSharePartnerReciprocity($partners),
            'delivery_group en modalidad que no lo admite' => $this->checkDeliveryGroupModality($activeShares),
            'quincenal activo sin cohorte (delivery_group)' => $this->checkBiweeklyWithoutCohort($activeShares),
            'socio con más de un PBS activo' => $this->checkSingleActiveShare($activeShares),
            'socio activo sin nodo de reparto' => $this->checkPartnerWithoutNode($partners),
            'pagador (payer_partner) inactivo' => $this->checkPayerActive($activeShares),
        ];

        $total = 0;
        foreach ($groups as $title => $problems) {
            if (empty($problems)) {
                continue;
            }
            $total += count($problems);
            $io->section(sprintf('%s (%d)', $title, count($problems)));
            $io->listing($problems);
        }

        if ($total === 0) {
            $io->success('Sin incoherencias. El modelo de socios cuadra.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d incoherencia(s) detectada(s). Revísalas a mano.', $total));

        return Command::FAILURE;
    }

    /**
     * Comprueba que la compartición de cesta entre familias sea recíproca:
     * si A apunta a B como share_partner, B debe apuntar a A.
     *
     * @param Partner[] $partners Socios activos.
     * @return string[] Descripciones de los enlaces rotos.
     */
    private function checkSharePartnerReciprocity(array $partners): array
    {
        $problems = [];
        foreach ($partners as $partner) {
            $other = $partner->getSharePartner();
            if ($other === null) {
                continue;
            }
            if ($other->getSharePartner()?->getId() !== $partner->getId()) {
                $problems[] = sprintf(
                    '%s (id %d) → %s (id %d), pero la vuelta no coincide.',
                    $this->name($partner),
                    $partner->getId(),
                    $this->name($other),
                    $other->getId(),
                );
            }
        }

        return $problems;
    }

    /**
     * delivery_group (cohorte A/B) sólo tiene sentido en cestas quincenales.
     * Una semanal o mensual con cohorte asignada es incoherente.
     *
     * @param PartnerBasketShare[] $shares Suscripciones activas.
     * @return string[]
     */
    private function checkDeliveryGroupModality(array $shares): array
    {
        $problems = [];
        foreach ($shares as $share) {
            $modality = $share->getBasketShare()?->getId();
            if ($share->getDeliveryGroup() !== null
                && in_array($modality, [self::SHARE_WEEKLY, self::SHARE_MONTHLY], true)) {
                $problems[] = sprintf(
                    '%s (id %d): modalidad %s con cohorte "%s".',
                    $this->name($share->getPartner()),
                    $share->getPartner()->getId(),
                    $share->getBasketShare()?->getName() ?? '?',
                    $share->getDeliveryGroup(),
                );
            }
        }

        return $problems;
    }

    /**
     * Un PBS quincenal activo sin delivery_group no encaja en la
     * alternancia A/B y nunca recibiría cesta de forma estable.
     *
     * @param PartnerBasketShare[] $shares Suscripciones activas.
     * @return string[]
     */
    private function checkBiweeklyWithoutCohort(array $shares): array
    {
        $problems = [];
        foreach ($shares as $share) {
            if ($share->getBasketShare()?->getId() === self::SHARE_BIWEEKLY
                && $share->getDeliveryGroup() === null) {
                $problems[] = sprintf(
                    '%s (id %d): quincenal sin cohorte asignada.',
                    $this->name($share->getPartner()),
                    $share->getPartner()->getId(),
                );
            }
        }

        return $problems;
    }

    /**
     * Cada socio debería tener como mucho una suscripción activa.
     *
     * @param PartnerBasketShare[] $shares Suscripciones activas.
     * @return string[]
     */
    private function checkSingleActiveShare(array $shares): array
    {
        $countByPartner = [];
        $nameByPartner = [];
        foreach ($shares as $share) {
            $partner = $share->getPartner();
            $id = $partner->getId();
            $countByPartner[$id] = ($countByPartner[$id] ?? 0) + 1;
            $nameByPartner[$id] = $this->name($partner);
        }

        $problems = [];
        foreach ($countByPartner as $id => $count) {
            if ($count > 1) {
                $problems[] = sprintf('%s (id %d): %d PBS activos.', $nameByPartner[$id], $id, $count);
            }
        }

        return $problems;
    }

    /**
     * Un socio activo cuyo grupo de recogida no tiene nodo cae al fallback
     * del generador y NO respeta las excepciones de calendario.
     *
     * Se excluyen los familiares (parent != null): recogen con su socio
     * principal y por diseño no tienen grupo de recogida propio.
     *
     * @param Partner[] $partners Socios activos.
     * @return string[]
     */
    private function checkPartnerWithoutNode(array $partners): array
    {
        $problems = [];
        foreach ($partners as $partner) {
            if ($partner->getParent() !== null) {
                continue;
            }
            $group = $partner->getWeeklyBasketGroup();
            if ($group === null) {
                $problems[] = sprintf('%s (id %d): sin grupo de recogida.', $this->name($partner), $partner->getId());
            } elseif ($group->getNode() === null) {
                $problems[] = sprintf(
                    '%s (id %d): grupo "%s" sin nodo asignado.',
                    $this->name($partner),
                    $partner->getId(),
                    $group->getName() ?? '?',
                );
            }
        }

        return $problems;
    }

    /**
     * Si una suscripción delega el cobro en otro socio (payer_partner),
     * ese pagador debería seguir activo.
     *
     * @param PartnerBasketShare[] $shares Suscripciones activas.
     * @return string[]
     */
    private function checkPayerActive(array $shares): array
    {
        $problems = [];
        foreach ($shares as $share) {
            $payer = $share->getPayerPartner();
            if ($payer !== null && !$payer->getIsActive()) {
                $problems[] = sprintf(
                    '%s (id %d): paga %s (id %d), que está inactivo.',
                    $this->name($share->getPartner()),
                    $share->getPartner()->getId(),
                    $this->name($payer),
                    $payer->getId(),
                );
            }
        }

        return $problems;
    }

    /**
     * Nombre legible de un socio para los listados.
     *
     * @param Partner $partner
     * @return string
     */
    private function name(Partner $partner): string
    {
        return trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? '')) ?: '(sin nombre)';
    }
}
