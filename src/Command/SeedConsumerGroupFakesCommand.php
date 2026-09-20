<?php

namespace App\Command;

use App\Entity\ConsumerGroupCategory;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupUnit;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\User;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\RoundItemEditor;
use App\Service\Delivery\PartnerMonthProjection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Siembra datos fake del grupo de consumo (categorías, productores con catálogo,
 * pedidos con pedidos) para poder ver y probar el módulo en local. Idempotente:
 * borra lo que sembró antes y lo vuelve a crear. SOLO para desarrollo local.
 *
 * Además enciende el feature-flag y resetea la contraseña de un socio de prueba
 * para poder entrar al panel.
 */
#[AsCommand(name: 'app:seed-consumer-group-fakes', description: 'Siembra datos fake del grupo de consumo (solo dev)')]
class SeedConsumerGroupFakesCommand extends Command
{
    /** @var array<string, ConsumerGroupUnit> */
    private array $unitCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoundItemEditor $roundItemEditor,
        private readonly AppSettings $settings,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PartnerMonthProjection $projection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1) Limpiar lo sembrado antes (el DELETE de pedidos cascada a items/pedidos/
        // líneas; el de productores cascada a productos; orden por las FK RESTRICT).
        $this->em->createQuery('DELETE App\Entity\ConsumerGroupRound r')->execute();
        $this->em->createQuery('DELETE App\Entity\Producer p')->execute();
        $this->em->createQuery('DELETE App\Entity\ConsumerGroupCategory c')->execute();
        $io->text('Datos previos del grupo de consumo borrados.');

        // 2) Categorías.
        $cats = [];
        foreach (['Verdura', 'Fruta', 'Aceite', 'Legumbre'] as $i => $name) {
            $c = (new ConsumerGroupCategory())->setName($name)->setSortOrder($i);
            $this->em->persist($c);
            $cats[$name] = $c;
        }

        // 3) Productores con catálogo. El precio ya no vive en el catálogo (se
        // quitó: nadie lo mantenía al día); se fija abajo, ronda a ronda, como
        // en el flujo real.
        $huerta = $this->producer('Huerta La Vega', 'Marisa', 'huerta@example.org', false);
        $this->product($huerta, $cats['Verdura'], 'Acelgas', 'manojo');
        $this->product($huerta, $cats['Verdura'], 'Tomate de temporada', 'kg');
        $this->product($huerta, $cats['Verdura'], 'Calabacín', 'kg');
        $this->product($huerta, $cats['Fruta'], 'Naranjas de zumo', 'kg');

        $almazara = $this->producer('Almazara El Olivar', 'Paco (socio)', 'olivar@example.org', true);
        $this->product($almazara, $cats['Aceite'], 'AOVE 5 L', 'garrafa');
        $this->product($almazara, $cats['Aceite'], 'AOVE 3 L', 'garrafa');

        $legumbres = $this->producer('Legumbres del Páramo', 'Cooperativa', 'legumbres@example.org', false);
        $this->product($legumbres, $cats['Legumbre'], 'Lenteja pardina', 'kg');
        $this->product($legumbres, $cats['Legumbre'], 'Garbanzo', 'kg');

        $this->em->flush();

        // 4) Socios activos para los pedidos fake.
        /** @var Partner[] $partners */
        $partners = $this->em->getRepository(Partner::class)->createQueryBuilder('p')
            ->where("p.status = 'ACTIVO'")
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(8)
            ->getQuery()->getResult();

        if (count($partners) < 3) {
            $io->warning('Pocos socios activos en la BBDD; los pedidos fake serán mínimos.');
        }

        // 5) Pedido ABIERTA (Huerta) con un par de apuntes.
        $abierto = $this->round($huerta, 'Verdura de temporada — julio', ConsumerGroupRound::STATUS_OPEN, '+5 days', '+7 days');
        $this->roundItemEditor->seedFromCatalog($abierto);
        $this->setPrices($abierto, [
            'Acelgas' => '1.80',
            'Tomate de temporada' => '2.60',
            'Calabacín' => '1.90',
            'Naranjas de zumo' => '1.40',
        ]);
        $this->em->flush(); // para que los items tengan id
        $this->orderFor($abierto, $partners[0] ?? null, [0 => '2', 1 => '3']);
        $this->orderFor($abierto, $partners[1] ?? null, [1 => '1.5']);

        // 6) Pedido CONFIRMADO pero aún ABIERTO (demuestra el flujo nuevo: alcanzado
        // el mínimo, la gente paga y puede seguir apuntándose hasta el cierre).
        $confirmado = $this->round($almazara, 'Aceite nuevo — cosecha', ConsumerGroupRound::STATUS_OPEN, '+8 days', '+12 days');
        $confirmado->setConfirmed(true);
        $confirmado->setConfirmedAt(new \DateTime('-1 day'));
        $confirmado->setProviderNote('Pasar el pedido agregado a la almazara el lunes.');

        // Alinear la entrega con un día de cesta real de una socia (para demostrar que
        // sale en el panel "qué se lleva ese día"). La fecha sale de la PROYECCIÓN del
        // calendario (las semanas futuras se dibujan del patrón; no hay filas
        // materializadas). Si ninguna socia tiene cesta próxima, se queda con la fecha
        // por defecto (saldrá como aviso de "punto de recogida").
        $cestaPartner = null;
        foreach ($partners as $candidate) {
            $cestaDate = $this->nextProjectedDeliveryDate($candidate);
            if ($cestaDate !== null) {
                $confirmado->setDeliveryDate(\DateTime::createFromInterface($cestaDate));
                $cestaPartner = $candidate;
                break;
            }
        }

        $this->roundItemEditor->seedFromCatalog($confirmado);
        $this->setPrices($confirmado, [
            'AOVE 5 L' => '38.00',
            'AOVE 3 L' => '24.00',
        ]);
        $this->em->flush();
        foreach ($partners as $i => $partner) {
            // Cada socio pide 1-2 garrafas de 5 L (item 0) alternando algo de 3 L.
            $lines = [0 => (string) (1 + ($i % 2))];
            if ($i % 3 === 0) {
                $lines[1] = '1';
            }
            // Pedido confirmado: alterna pagados/pendientes para ver el seguimiento.
            $this->orderFor($confirmado, $partner, $lines, $i % 2 === 0);
        }
        // Asegurar que la socia con cesta ese día tiene su apunte PAGADO (para el demo
        // del panel "qué se lleva ese día"). Si ya tenía apunte, lo marca pagado; si no,
        // lo crea pagado.
        if ($cestaPartner !== null) {
            $existing = null;
            foreach ($confirmado->getOrders() as $o) {
                if ($o->getPartner() === $cestaPartner) {
                    $existing = $o;
                    break;
                }
            }
            if ($existing !== null) {
                $existing->setPaid(true);
                $existing->setPaidAt(new \DateTime('-1 day'));
            } else {
                $this->orderFor($confirmado, $cestaPartner, [0 => '1'], true);
            }
        }

        $this->em->flush();

        // 7) Encender los feature-flags para poder verlo y entrar como socio (LOCAL).
        //    FEATURE_PARTNER_LOGIN es imprescindible: sin él, UserChecker bloquea el
        //    login de los socixs y no se puede probar el panel.
        $this->settings->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
        $this->settings->setBool(AppSettings::FEATURE_PARTNER_LOGIN, true);

        // 8) Socio de prueba para el panel: resetear contraseña de un socio ya vinculado.
        $socio = $this->em->getRepository(User::class)->findOneBy(['username' => 'ines']);
        if ($socio !== null) {
            // Limpiar el salt legacy de FOSUser: con salt no-nulo, la verificación
            // del hash bcrypt falla (credenciales inválidas). passwordSet=true para
            // no forzar el cambio de contraseña al entrar.
            $socio->setSalt(null);
            $socio->setPasswordSet(true);
            $socio->setPassword($this->hasher->hashPassword($socio, 'cgtest2026'));
            // Asegurar que ese socio tiene un apunte en el pedido abierto.
            if ($socio->getPartner() !== null) {
                $this->orderFor($abierto, $socio->getPartner(), [0 => '1', 2 => '2']);
            }
            $this->em->flush();
            $io->text('Socio de prueba: usuario "ines" / contraseña "cgtest2026".');
        }

        $io->success('Grupo de consumo sembrado: 4 categorías, 3 productores con catálogo, 1 pedido abierto y 1 confirmado con pedidos. Feature-flag ENCENDIDO.');

        return Command::SUCCESS;
    }

    /**
     * Primera fecha física de cesta FUTURA del socio según la proyección del
     * calendario (dibujo del patrón), buscando en los próximos meses. Null si no
     * proyecta ninguna entrega (socio de baja o sin patrón).
     */
    private function nextProjectedDeliveryDate(Partner $partner): ?\DateTimeInterface
    {
        $today = new \DateTime('today');
        $cursor = new \DateTime('first day of this month');
        for ($i = 0; $i < 3; ++$i) {
            foreach ($this->projection->projectMonth($partner, (int) $cursor->format('Y'), (int) $cursor->format('n')) as $slot) {
                if (!empty($slot['skipped'])) {
                    continue;
                }
                $date = $slot['date'] ?? null;
                if ($date instanceof \DateTimeInterface && $date > $today) {
                    return $date;
                }
            }
            $cursor->modify('+1 month');
        }

        return null;
    }

    private function producer(string $name, string $contact, string $email, bool $selfManaged): Producer
    {
        $p = (new Producer())
            ->setName($name)->setContactName($contact)->setEmail($email)
            ->setSelfManaged($selfManaged)->setActive(true);
        $this->em->persist($p);
        return $p;
    }

    private function product(Producer $producer, ConsumerGroupCategory $cat, string $name, string $unitName): void
    {
        $prod = (new ConsumerGroupProduct())
            ->setProducer($producer)->setCategory($cat)
            ->setName($name)->setUnit($this->unit($unitName))
            ->setActive(true)->setSortOrder($producer->getProducts()->count());
        $producer->addProduct($prod);
        $this->em->persist($prod);
    }

    /**
     * Unidad GLOBAL por nombre: la reutiliza si ya existe (de una siembra
     * anterior o de datos reales), la crea si no. A diferencia de las
     * categorías, las unidades NO se borran al resembrar.
     */
    private function unit(string $name): ConsumerGroupUnit
    {
        if (isset($this->unitCache[$name])) {
            return $this->unitCache[$name];
        }

        $unit = $this->em->getRepository(ConsumerGroupUnit::class)->findOneBy(['name' => $name]);
        if ($unit === null) {
            $unit = (new ConsumerGroupUnit())->setName($name);
            $this->em->persist($unit);
        }

        return $this->unitCache[$name] = $unit;
    }

    /**
     * Fija el precio de los items ya sembrados por nombre de producto: el
     * catálogo no lleva precio, así que el de cada ronda fake se pone aquí,
     * como haría la comisión en la pantalla de "Productos del pedido".
     *
     * @param array<string, string> $byProductName
     */
    private function setPrices(ConsumerGroupRound $round, array $byProductName): void
    {
        foreach ($round->getItems() as $item) {
            $name = $item->getProduct()?->getName();
            if ($name !== null && isset($byProductName[$name])) {
                $item->setPrice($byProductName[$name]);
            }
        }
    }

    private function round(Producer $producer, string $title, int $status, string $close, string $delivery): ConsumerGroupRound
    {
        $r = (new ConsumerGroupRound())
            ->setProducer($producer)->setTitle($title)->setStatus($status)
            ->setOrdersCloseAt(new \DateTime($close))
            ->setDeliveryDate(new \DateTime($delivery));
        $this->em->persist($r);
        return $r;
    }

    /**
     * Crea un pedido para un socio con líneas por índice de item del pedido.
     *
     * @param array<int, string> $linesByItemIndex índice de item => cantidad
     */
    private function orderFor(ConsumerGroupRound $round, ?Partner $partner, array $linesByItemIndex, bool $paid = false): void
    {
        if ($partner === null) {
            return;
        }
        $items = $round->getItems()->getValues();
        $order = new ConsumerGroupOrder($round, $partner);
        foreach ($linesByItemIndex as $idx => $qty) {
            if (isset($items[$idx])) {
                $order->addLine(new ConsumerGroupOrderLine($order, $items[$idx], $qty));
            }
        }
        if ($paid) {
            $order->setPaid(true);
            $order->setPaidAt(new \DateTime('-3 days'));
        }
        $round->addOrder($order);
        $this->em->persist($order);
    }
}
