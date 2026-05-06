<?php
namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Persistence\ObjectManager;


class EntityToIntTransformer implements DataTransformerInterface
{
  /**
   * @var \Doctrine\Persistence\ObjectManager
   */
  private $om;
  private $entityClass;
  private $entityType;
  private $entityRepository;

  /**
   * @param ObjectManager $om
   */
  public function __construct(ObjectManager $om)
  {
    $this->om = $om;
  }

  public function transform(mixed $entity): mixed
  {
    if (null === $entity || !$entity instanceof $this->entityClass) {
      throw new TransformationFailedException("$this->entityType object must be provided");
    }

    return $entity->getId();
  }


  public function reverseTransform(mixed $id): mixed
  {
    if (!$id) {
      throw new TransformationFailedException("No $this->entityType id was submitted");
    }

    $entity = $this->om->getRepository($this->entityRepository)->findOneBy(array("id" => $id));

    if (null === $entity) {
      throw new TransformationFailedException(sprintf(
          'A %s with id "%s" does not exist!',
          $this->entityType,
          $id
      ));
    }

    return $entity;
  }

  public function setEntityType($entityType)
  {
    $this->entityType = $entityType;
  }

  public function setEntityClass($entityClass)
  {
    $this->entityClass = $entityClass;
  }

  public function setEntityRepository($entityRepository)
  {
    $this->entityRepository = $entityRepository;
  }
}