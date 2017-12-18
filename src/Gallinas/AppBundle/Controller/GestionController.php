<?php

namespace Gallinas\AppBundle\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class GestionController extends Controller
{


    public function indexAction()
    {
        return $this->render('AppBundle:Default:index.html.twig', array());
    }

    public function gestionAction()
    {
        return $this->redirect($this->generateUrl('dashboard'));
    }

}
