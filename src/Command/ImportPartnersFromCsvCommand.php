<?php

namespace App\Command;

use App\Entity\BasketShare;
use App\Entity\City;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Entity\PartnerMembershipPeriod;
use App\Entity\State;
use App\Entity\WeeklyBasketGroup;
use App\Service\Partner\PartnerShareEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa socixs desde el CSV consolidado del cruce COBROS + LISTADO +
 * PDFs de reparto (`docs/socios-import/listado_final.csv`).
 *
 * V1 cubre los 111 socixs cruzados (con `codigo_reparto` no vacío). Crea
 * el Partner y un PartnerBasketShare activo con los importes y la
 * frecuencia que dicta el cruce. Los 36 huérfanos (sin PDF de reparto)
 * se quedan fuera de esta tanda — entran cuando admin nos pase los
 * listados de los 8 grupos pendientes.
 *
 * Quedan fuera de V1 y se atacan después:
 *  - Egg amount y egg period (requiere parsear huevos_por_viernes).
 *  - Cestas compartidas (campo `share_partner`, vincular pareja_id).
 *  - Familia con dos miembros (parent_id) — hoy 1 Partner por familia.
 *  - Cohorte A/B quincenal (`delivery_group`) — task #4.
 *
 * Idempotente: si ya existe un Partner con ese DNI, se salta sin tocar.
 * Para reimportar limpio: vaciar partner + partner_basket_share antes.
 */
#[AsCommand(
    name: 'app:import-partners-from-csv',
    description: 'Importa socixs desde el CSV consolidado del cruce COBROS+LISTADO+PDFs.'
)]
class ImportPartnersFromCsvCommand extends Command
{
    /**
     * Mapeo del campo `codigo_reparto` del CSV al id de BasketShare.
     * El catálogo tiene 5 ids fijos (constantes del WeeklyBasketGenerator):
     *   1 = Semanal · 2 = Quincenal · 3 = Mensual · 4 = Semanal compartida
     *   5 = Solo huevos
     *
     * Las quincenales compartidas (QC/QCH) van con id=2 (Quincenal) porque
     * el código no tiene un SHARE_HALF_BIWEEKLY. La condición "compartida"
     * se modela aparte con Partner.share_partner cuando esté cableado.
     */
    private const CODIGO_TO_BASKET_ID = [
        'S'   => 1, 'SH'  => 1,
        'SC'  => 4, 'SCH' => 4,
        'Q'   => 2, 'QH'  => 2, 'QC' => 2, 'QCH' => 2,
        'M'   => 3, 'MH'  => 3,
        'H'   => 5,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerShareEventRecorder $shareEventRecorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'csv',
                InputArgument::REQUIRED,
                'Ruta al fichero listado_final.csv (relativa al directorio del proyecto).'
            )
            ->addOption(
                'bajas-csv',
                null,
                InputOption::VALUE_REQUIRED,
                'Ruta opcional a bajas.csv. Importa los socios históricos como status=BAJA.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'No persiste cambios. Sólo reporta lo que haría.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $csvPath = $input->getArgument('csv');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($csvPath) || !is_readable($csvPath)) {
            $io->error("CSV no legible: $csvPath");
            return Command::FAILURE;
        }

        $rows = $this->readCsv($csvPath);
        $io->note(sprintf('Filas en CSV: %d', count($rows)));

        $stats = [
            'cruzados'        => 0,
            'huerfanos'       => 0,
            'creados'         => 0,
            'saltados_dni'    => 0,
            'errores'         => 0,
        ];

        $groupsByName = $this->indexGroupsByCanonicalName();
        $basketsById  = $this->indexBasketsById();
        $citiesByName = $this->indexCitiesByName();
        $defaultState = $this->resolveOrCreateState('Madrid');

        // Tracking de Partners por DNI ya creados en esta ejecución.
        // Necesario porque:
        //  - en dry-run no se hace flush y findOneBy no encuentra entidades.
        //  - cuando el mismo DNI aparece en activos+bajas o varias veces en
        //    bajas (mismo socio con varios episodios alta/baja), queremos
        //    REUSAR el Partner y añadirle PartnerMembershipPeriod, no saltar.
        $partnersByDni = [];

        foreach ($rows as $i => $row) {
            $hasReparto = $row['codigo_reparto'] !== '';
            if ($hasReparto) {
                $stats['cruzados']++;
            } else {
                $stats['huerfanos']++;
            }

            try {
                $created = $this->importRow($row, $groupsByName, $basketsById, $citiesByName, $defaultState, $i, $hasReparto, $partnersByDni);
                if ($created === null) {
                    $stats['saltados_dni']++;
                } else {
                    $stats['creados']++;
                }
            } catch (\Throwable $e) {
                $stats['errores']++;
                $io->warning(sprintf(
                    'Fila %d (num_socio=%s, titular=%s): %s',
                    $i + 2,
                    $row['num_socio'] ?? '?',
                    $row['titular_legal'] ?: $row['familia_operativa'] ?? '?',
                    $e->getMessage()
                ));
            }
        }

        $bajasPath = $input->getOption('bajas-csv');
        if ($bajasPath !== null) {
            if (!is_file($bajasPath) || !is_readable($bajasPath)) {
                $io->error("bajas.csv no legible: $bajasPath");
                return Command::FAILURE;
            }
            $bajasRows = $this->readCsv($bajasPath);
            $io->note(sprintf('Filas en bajas.csv: %d', count($bajasRows)));
            $stats['bajas_leidas']    = count($bajasRows);
            $stats['bajas_creadas']   = 0;
            $stats['bajas_periodos_extra'] = 0;

            foreach ($bajasRows as $i => $row) {
                try {
                    $result = $this->importBajaRow($row, $citiesByName, $defaultState, $partnersByDni);
                    if ($result === 'created') {
                        $stats['bajas_creadas']++;
                    } elseif ($result === 'period_added') {
                        $stats['bajas_periodos_extra']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errores']++;
                    $io->warning(sprintf(
                        'Baja fila %d (num_socio=%s): %s',
                        $i + 2,
                        $row['num_socio'] ?? '?',
                        $e->getMessage()
                    ));
                }
            }
        }

        if ($dryRun) {
            $io->note('DRY-RUN: no se persisten cambios. Revirtiendo entityManager.');
            $this->em->clear();
        } else {
            $this->em->flush();
        }

        $io->success('Importación terminada.');
        $io->table(
            ['Métrica', 'Valor'],
            array_map(fn($k, $v) => [$k, $v], array_keys($stats), $stats),
        );

        return $stats['errores'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Importa una fila de bajas.csv como Partner con status=BAJA. Sin
     * PartnerBasketShare (la cesta histórica se considera cerrada al
     * darse de baja). Si el DNI ya existe (alguien que pasó de baja a
     * activo: hay 4 casos), salta — predomina el estado activo.
     *
     * @param array<string,string> $row
     * @param array<string,City>   $citiesByName  por referencia
     */
    private function importBajaRow(array $row, array &$citiesByName, State $defaultState, array &$partnersByDni): ?string
    {
        $dni = $this->sanitizeDni($row['dni'] ?? '');
        $startDate = $this->parseDateOrNull($row['fecha_alta_iso'] ?? '');
        $endDate   = $this->parseDateOrNull($row['fecha_baja_iso'] ?? '');

        // DNI ya existe: añadimos un período histórico al Partner existente
        // en vez de crear uno nuevo. Preserva episodios alta/baja repetidos.
        if ($dni !== '' && ($existing = $this->findPartnerByDni($dni, $partnersByDni))) {
            if ($startDate === null) {
                return null;
            }
            $period = new PartnerMembershipPeriod();
            $period->setPartner($existing);
            $period->setStartDate($startDate);
            $period->setEndDate($endDate);
            $period->setReason($row['observaciones'] ?: null);
            $existing->addMembershipPeriod($period);
            $this->em->persist($period);
            return 'period_added';
        }

        $nombre    = trim((string) ($row['nombre'] ?? ''));
        $apellidos = trim((string) ($row['apellidos'] ?? ''));
        if ($nombre === '' && $apellidos === '') {
            return null;
        }

        $partner = (new Partner())
            ->setName($nombre !== '' ? $nombre : '(sin nombre)')
            ->setSurname($apellidos !== '' ? $apellidos : null)
            ->setDNI($dni !== '' ? $dni : null)
            ->setAddress($row['direccion'] ?: null)
            ->setNotes($row['observaciones'] ?: null)
            ->setStatus(Partner::STATUS_BAJA)
            ->setIsActive(false)
            ->setState($defaultState)
            ->setCity($this->resolveOrCreateCity($row['poblacion'] ?: ($row['localidad'] ?: 'Sin población'), $defaultState, $citiesByName));

        if ($row['email']) {
            $partner->setemail($row['email']);
        }
        $partner->setcelular($this->parseTelefono($row['telefono'] ?? ''));
        if ($startDate) {
            $partner->setInscriptionDate($startDate);
        }
        if ($endDate) {
            $partner->setDemoteDate($endDate);
        }

        $this->em->persist($partner);

        if ($dni !== '') {
            $partnersByDni[strtoupper($dni)] = $partner;
        }

        if ($startDate !== null) {
            $period = new PartnerMembershipPeriod();
            $period->setPartner($partner);
            $period->setStartDate($startDate);
            $period->setEndDate($endDate);
            $period->setReason($row['observaciones'] ?: null);
            $partner->addMembershipPeriod($period);
            $this->em->persist($period);
        }

        return 'created';
    }

    private function parseDateOrNull(string $iso): ?\DateTime
    {
        if ($iso === '') {
            return null;
        }
        try {
            return new \DateTime($iso);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Busca un Partner por DNI primero en el cache de memoria (ejecución actual),
     * y si no, en BBDD persistida. Devuelve null si no existe.
     *
     * @param array<string,Partner> $partnersByDni
     */
    private function findPartnerByDni(string $dni, array $partnersByDni): ?Partner
    {
        $key = strtoupper(trim($dni));
        if (isset($partnersByDni[$key])) {
            return $partnersByDni[$key];
        }
        return $this->em->getRepository(Partner::class)->findOneBy(['DNI' => $dni]);
    }

    /**
     * Crea un Partner y su PartnerBasketShare activo. Devuelve el Partner
     * creado, o null si ya existía un Partner con ese DNI (caso idempotente).
     *
     * @param array<string,string>             $row
     * @param array<string,WeeklyBasketGroup>  $groupsByName
     * @param array<int,BasketShare>           $basketsById
     * @param array<string,City>               $citiesByName  pasa por referencia para cachear nuevas cities
     */
    private function importRow(
        array $row,
        array $groupsByName,
        array $basketsById,
        array &$citiesByName,
        State $defaultState,
        int $rowIndex,
        bool $hasReparto,
        array &$partnersByDni,
    ): ?Partner {
        $dni = $this->sanitizeDni($row['nif'] ?? '');
        if ($dni !== '' && $this->findPartnerByDni($dni, $partnersByDni)) {
            return null;
        }

        // grupo_canonico viene del cruce de población (COBROS). Cuando esa
        // celda está vacía pero el PDF de reparto sí tiene localidad
        // (reparto_localidad), caemos sobre ese fallback antes de fallar.
        // Si tampoco hay reparto_localidad (huérfano sin grupo), usamos
        // "Sin grupo" como fallback final.
        $grupoNombre = $row['grupo_canonico'] ?: $row['reparto_localidad'] ?: 'Sin grupo';
        $group = $groupsByName[$this->normaliseGroupName($grupoNombre)] ?? null;
        if ($group === null) {
            throw new \RuntimeException(sprintf(
                'grupo canónico no sembrado: %s (reparto_localidad=%s)',
                $row['grupo_canonico'] ?: '(vacío)',
                $row['reparto_localidad'] ?: '(vacío)'
            ));
        }

        $city = $this->resolveOrCreateCity($row['poblacion'] ?: 'Sin población', $defaultState, $citiesByName);

        // El titular_legal puede estar vacío en huérfanos (ej. Dámaso #127);
        // caemos sobre familia_operativa que para esos casos sí tiene texto.
        [$name, $surname] = $this->splitFullName($row['titular_legal'] ?: $row['familia_operativa']);

        $partner = (new Partner())
            ->setName($name)
            ->setSurname($surname)
            ->setDNI($dni !== '' ? $dni : null)
            ->setAddress($row['direccion'] ?: null)
            ->setIban($row['iban'] ?: null)
            ->setNotes($row['observaciones_cobros'] ?: null)
            ->setStatus(Partner::STATUS_ACTIVO)
            ->setWeeklyBasketGroup($group)
            ->setState($defaultState)
            ->setCity($city);

        $email = trim((string) $row['email']);
        if ($email !== '') {
            $partner->setemail($email);
        }
        $partner->setcelular($this->parseTelefono($row['telefono'] ?? ''));
        if ($row['fecha_alta_iso']) {
            try {
                $partner->setInscriptionDate(new \DateTime($row['fecha_alta_iso']));
            } catch (\Exception) {
                // fecha mal formada — se ignora silenciosamente
            }
        }

        $this->em->persist($partner);

        if ($dni !== '') {
            $partnersByDni[strtoupper($dni)] = $partner;
        }

        // Período de pertenencia activo (sin fecha de fin): el partner
        // está dado de alta a fecha de hoy. inscription_date puede estar
        // vacío para socios viejos sin alta registrada; en ese caso
        // usamos la fecha de hoy como aproximación.
        $period = new PartnerMembershipPeriod();
        $period->setPartner($partner);
        $period->setStartDate($partner->getInscriptionDate() ?: new \DateTime());
        $period->setEndDate(null);
        $partner->addMembershipPeriod($period);
        $this->em->persist($period);

        // Sólo creamos PartnerBasketShare cuando hay datos de reparto del
        // PDF (frecuencia + cuota). Los huérfanos (8 grupos sin PDF) entran
        // como Partner ACTIVO sin cesta; se completarán cuando admin pase
        // los listados pendientes.
        if (!$hasReparto) {
            return $partner;
        }

        $basketId = self::CODIGO_TO_BASKET_ID[$row['codigo_reparto']] ?? null;
        if ($basketId === null) {
            throw new \RuntimeException(sprintf('codigo_reparto desconocido: %s', $row['codigo_reparto']));
        }
        $basket = $basketsById[$basketId];

        // PartnerBasketShare mezcla setters void y self; no encadeno.
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($basket);
        $share->setMonthPrice($this->decimalOrZero($row['importe_cesta_eur']));
        $share->setEggMonthPrice($this->decimalOrZero($row['importe_huevos_eur']));
        $share->setTransportPrice(
            $row['importe_transp_eur'] !== '' && (float) $row['importe_transp_eur'] > 0
                ? number_format((float) $row['importe_transp_eur'], 2, '.', '')
                : null
        );
        // Si no hay fecha de alta conocida, usamos una fecha lejana del
        // pasado para que el socio aparezca en TODOS los Baskets actuales.
        // Las queries del WeeklyBasketGenerator filtran con
        // `start_date <= basket.date`, así que un new \DateTime() (hoy)
        // excluye al socio del basket de esta misma semana — bug detectado
        // con 16 socios que se quedaban fuera del reparto del 2026-05-22.
        $share->setStartDate($partner->getInscriptionDate() ?: new \DateTime('1970-01-01'));
        $share->setAmount(1);
        $share->setIsActive(true);

        // Cohorte A/B para quincenales. Se deriva del campo
        // cestas_por_viernes del CSV (ej. "1,0,1,0" → A, viernes 1+3;
        // "0,1,0,1" → B, viernes 2+4). Patrones raros (ej. "0,1,1,1")
        // dejan delivery_group=null para revisión manual.
        if ($basketId === 2 && $row['cestas_por_viernes']) {
            $share->setDeliveryGroup($this->derivarCohorte($row['cestas_por_viernes']));
        }

        $partner->addPartnerBasketShare($share);
        $this->em->persist($share);

        // Histórico inmutable: el alta operativa de la cesta queda registrada
        // con la fecha real del start_date (puede ser muy anterior a "hoy"
        // cuando se importan socixs ya activxs hace tiempo). Actor=cli para
        // distinguir importaciones de eventos generados desde admin web.
        $this->shareEventRecorder->recordStart(
            $share,
            $share->getStartDate(),
            PartnerEvent::ACTOR_CLI,
        );

        return $partner;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("No se pudo abrir CSV: $path");
        }
        $header = fgetcsv($handle);
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $cells);
        }
        fclose($handle);
        return $rows;
    }

    /**
     * @return array<string,WeeklyBasketGroup>
     */
    private function indexGroupsByCanonicalName(): array
    {
        $out = [];
        foreach ($this->em->getRepository(WeeklyBasketGroup::class)->findAll() as $g) {
            $out[$this->normaliseGroupName($g->getName())] = $g;
        }
        return $out;
    }

    /**
     * @return array<int,BasketShare>
     */
    private function indexBasketsById(): array
    {
        $out = [];
        foreach ($this->em->getRepository(BasketShare::class)->findAll() as $b) {
            $out[$b->getId()] = $b;
        }
        return $out;
    }

    /**
     * @return array<string,City>
     */
    private function indexCitiesByName(): array
    {
        $out = [];
        foreach ($this->em->getRepository(City::class)->findAll() as $c) {
            $out[$this->normaliseGroupName($c->getName())] = $c;
        }
        return $out;
    }

    /**
     * Normaliza un nombre para usar como índice: quita acentos, espacios
     * sobrantes, mayúsculas. Permite cruzar 'San Agustín de Guadalix' con
     * 'SAN AGUSTIN DE GUADALIX' sin falsos negativos.
     */
    private function normaliseGroupName(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        $s = mb_strtoupper($s, 'UTF-8');
        $s = strtr($s, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
        return $s;
    }

    /**
     * @param array<string,City> $cache  pasa por referencia
     */
    private function resolveOrCreateCity(string $name, State $state, array &$cache): City
    {
        $key = $this->normaliseGroupName($name);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $city = (new City())->setName($name)->setState($state);
        $this->em->persist($city);
        $cache[$key] = $city;
        return $city;
    }

    private function resolveOrCreateState(string $name): State
    {
        $existing = $this->em->getRepository(State::class)->findOneBy(['name' => $name]);
        if ($existing) {
            return $existing;
        }
        $state = (new State())->setName($name);
        $this->em->persist($state);
        return $state;
    }

    /**
     * Divide 'JUAN PÉREZ GARCÍA' en ['JUAN', 'PÉREZ GARCÍA']. Para los casos
     * familia tipo 'NELI Y PAOLO' devuelve ['NELI Y PAOLO', null].
     *
     * @return array{0:string,1:?string}
     */
    private function splitFullName(string $full): array
    {
        $full = trim($full);
        if ($full === '') {
            return ['(sin nombre)', null];
        }
        $parts = preg_split('/\s+/', $full);
        if (count($parts) === 1) {
            return [$parts[0], null];
        }
        return [$parts[0], implode(' ', array_slice($parts, 1))];
    }

    /**
     * Devuelve el DNI si parece plausible. Acepta hasta 20 chars
     * alfanuméricos (DNIs, NIEs, CIFs de asociaciones). Si contiene
     * espacios, símbolos raros o se sale del rango, devuelve string vacío
     * — bajas.csv tiene al menos una fila con la dirección colada en el
     * campo DNI por desplazamiento de columnas en el Excel original.
     */
    private function sanitizeDni(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '' || strlen($s) > 20) {
            return '';
        }
        // Sólo aceptamos alfanuméricos + guion (algunos NIEs vienen
        // con guion). Espacios o puntuación → descartar.
        if (!preg_match('/^[A-Z0-9\-]+$/', $s)) {
            return '';
        }
        return $s;
    }

    /**
     * Normaliza un teléfono al int que cabe en `partner.celular` (INT 32 bits).
     * Devuelve null si no se puede normalizar (vacío, internacional largo,
     * caracteres raros). Los teléfonos españoles de 9 dígitos siempre caben.
     */
    private function parseTelefono(string $raw): ?int
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || !ctype_digit($digits)) {
            return null;
        }
        // INT con signo en MySQL: hasta 2_147_483_647. Un teléfono español
        // de 9 dígitos como 612345678 cabe. Más de 10 dígitos se descarta
        // (probable prefijo internacional concatenado).
        if (strlen($digits) > 10) {
            return null;
        }
        $n = (int) $digits;
        return $n > 0 && $n <= 2147483647 ? $n : null;
    }

    /**
     * Deriva la cohorte A/B de un patrón "x,y,z,w" de cestas por viernes.
     * A = recibe en viernes 1 y 3 (índices 0 y 2).
     * B = recibe en viernes 2 y 4 (índices 1 y 3).
     * Cualquier otro patrón devuelve null (caso compartida o cambio de
     * cohorte intra-mes — requiere decisión manual).
     */
    private function derivarCohorte(string $patron): ?string
    {
        $v = array_map('intval', explode(',', $patron));
        if (count($v) !== 4) {
            return null;
        }
        if ($v === [1, 0, 1, 0]) return PartnerBasketShare::DELIVERY_GROUP_A;
        if ($v === [0, 1, 0, 1]) return PartnerBasketShare::DELIVERY_GROUP_B;
        return null;
    }

    private function decimalOrZero(string $s): string
    {
        $f = (float) str_replace(',', '.', $s ?: '0');
        return number_format($f, 2, '.', '');
    }

}
