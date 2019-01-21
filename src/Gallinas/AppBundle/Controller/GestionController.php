<?php

namespace Gallinas\AppBundle\Controller;



use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\HttpFoundation\Request;

class GestionController extends Controller
{


    public function indexAction()
    {
        $form = $this->createCreateForm();

        return $this->render('AppBundle:Default:index.html.twig', array(
            'form' => $form->createView(),
        ));
    }

    public function gestionAction()
    {
        return $this->redirect($this->generateUrl('dashboard'));
    }

    public function contactedAction(Request $request)
    {
        $form = $this->createCreateForm();
        $form->handleRequest($request);

        if ($form->isValid()) {
            $message = \Swift_Message::newInstance()
                ->setSubject('Contacto desde http://csavegadejarama.org')
                ->setFrom('flopezlosada@gmail.com')
                ->setTo('info@csavegadejarama.org')
                ->setBody(
                    $this->renderView(
                        'AppBundle:Default:contact_email.html.twig',
                        array('name' => $request->get("name"), 'subject' => $request->get("subject"), 'body' => $request->get("body"), 'email' => $request->get('email'))
                    )
                );
            $this->get('mailer')->send($message);

            $this->get('session')->getFlashBag()->add(
                'notice',
                'El mensaje se ha enviado correctamente'
            );
            return new RedirectResponse($this->generateUrl("landing_contact"));
        }
        else{
            $response = new Response();
            $response->setStatusCode(500);
            return $response;
        }

    }

    private function createCreateForm()
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('app_contact'))
            ->add('name', 'text', array('attr' => array('placeholder' => 'Nombre...', 'class' => 'form-control'), 'label' => 'Nombre',
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica tu nombre")),
                )
            ))
            ->add('subject', 'text', array('attr' => array('placeholder' => 'Asunto...', 'class' => 'form-control'), 'label' => "Asunto",
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica un asunto")),
                )
            ))
            ->add('email', 'email', array('attr' => array('placeholder' => 'Email...', 'class' => 'form-control'), 'label' => 'Email',
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica un email")),
                    new Email(array("message" => "Tu email parece incorrecto")),
                )
            ))
            ->add('body', 'textarea', array('attr' => array('placeholder' => 'Mensaje...', 'class' => 'form-control'), 'label' => 'Mensaje',
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica aquí tu mensaje")),
                )
            ))
            ->add('captcha', 'genemu_captcha', array('attr' => array('placeholder' => 'Indica el texto de la figura', 'class' => 'form-control')))
             ->add('submit', 'submit', array('label' => 'Enviar','attr'=>array('class'=>'btn btn-blue btn-effect')))

            ->getForm();


    }
}
