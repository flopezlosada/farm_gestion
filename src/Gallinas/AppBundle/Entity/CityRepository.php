<?php

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * CityRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class CityRepository extends EntityRepository
{
  /**
   * Esta función es para el formulario de nuevo perfil, para que salga vacío al principio el campo ciudades.
   * si es un nuevo perfil se coge getNullCities en el UserProfileType, que devuelve vacío.
   * si es editar perfil se coge getAllCities()
   * @return \Doctrine\ORM\QueryBuilder
   */
  public function getNullCities()
  {
    $em=$this->getEntityManager();
    $qb = $this->_em->createQueryBuilder();
    $qb->select('u')
    ->from($this->_entityName, 'u')
    ->where('u.state =0');
  
    return $qb;
  }
  
  public function getAllCities($state)
  {
    $em=$this->getEntityManager();
    $qb = $this->_em->createQueryBuilder();
    $qb->select('u')
    ->from($this->_entityName, 'u');
  
    if ($state)
    {
      $qb->where('u.state = :state');
      $qb->setParameter("state", $state);
    }
    return $qb;
  }
}
