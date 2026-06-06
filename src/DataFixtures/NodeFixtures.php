<?php

namespace App\DataFixtures;

use App\Entity\Node;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Carga los 3 nodos físicos de reparto:
 * - Torremocha (semanal, viernes)
 * - Cascorro (quincenal, miércoles)
 * - Midori (quincenal, miércoles)
 *
 * IDs explícitos 1, 2, 3 para que los tests los localicen.
 * Dependencia de CatalogFixtures para asegurar orden de carga.
 */
class NodeFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Asignar IDs explícitos como en CatalogFixtures
        $metadata = $manager->getClassMetadata(Node::class);
        $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
        $metadata->setIdGenerator(new \Doctrine\ORM\Id\AssignedGenerator());
        $nodeIdProperty = new \ReflectionProperty(Node::class, 'id');

        // Torremocha: viernes (5), semanal
        $node1 = (new Node())
            ->setName('Torremocha')
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $nodeIdProperty->setValue($node1, 1);
        $manager->persist($node1);

        // Cascorro: miércoles (3), quincenal con anchor 2026-05-06
        $node2 = (new Node())
            ->setName('Cascorro')
            ->setDeliveryWeekday(3)
            ->setCadence(Node::CADENCE_BIWEEKLY)
            ->setAnchorDate(new \DateTime('2026-05-06'));
        $nodeIdProperty->setValue($node2, 2);
        $manager->persist($node2);

        // Midori: miércoles (3), quincenal con anchor 2026-05-06
        $node3 = (new Node())
            ->setName('Midori')
            ->setDeliveryWeekday(3)
            ->setCadence(Node::CADENCE_BIWEEKLY)
            ->setAnchorDate(new \DateTime('2026-05-06'));
        $nodeIdProperty->setValue($node3, 3);
        $manager->persist($node3);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [CatalogFixtures::class];
    }
}
