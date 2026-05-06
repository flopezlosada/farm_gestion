<?php

namespace App\Controller;

use App\Controller\AbstractAppController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\HttpFoundation\Request;

class GestionController extends AbstractAppController
{


    public function index()
    {
        $form = $this->createCreateForm();

        return $this->render('Default/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    public function gestion()
    {
        return $this->redirect($this->generateUrl('dashboard'));
    }

    public function contacted(Request $request, MailerInterface $mailer)
    {
        $form = $this->createCreateForm();
        $form->handleRequest($request);

        if ($form->isValid()) {
            $data = $form->getData();
            $email = (new MimeEmail())
                ->subject('Contacto desde http://csavegadejarama.org')
                ->from('flopezlosada@gmail.com')
                ->to('info@csavegadejarama.org')
                ->html(
                    $this->renderView(
                        'Default/contact_email.html.twig',
                        array('name' => $data["name"],
                            'subject' => $data["subject"],
                            'body' => $data["body"],
                            'email' => $data["email"],
                        )
                    ));
            $mailer->send($email);

            $this->addFlash(
                'notice',
                'El mensaje se ha enviado correctamente'
            );
            return $this->render('Default/landing_contacted.html.twig', array());
        }
        return $this->render('Default/landing_contact.html.twig', array(

            'form' => $form->createView(),
        ));


    }

    private function createCreateForm()
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('app_contact'))
            ->add('name', TextType::class, array('attr' => array('placeholder' => 'Nombre...', 'class' => 'form-control'), 'label' => 'Nombre',
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica tu nombre")),
                )
            ))
            ->add('subject', TextType::class, array('attr' => array('placeholder' => 'Asunto...', 'class' => 'form-control'), 'label' => "Asunto",
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica un asunto")),
                )
            ))
            ->add('email', EmailType::class, array('attr' => array('placeholder' => 'Email...', 'class' => 'form-control'), 'label' => 'Email',
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica un email")),
                    new Email(array("message" => "Tu email parece incorrecto")),
                )
            ))
            ->add('body', TextareaType::class, array('attr' => array('placeholder' => 'Mensaje...', 'class' => 'form-control'), 'label' => 'Mensaje',
                'constraints' => array(
                    new NotBlank(array("message" => "Por favor, indica aquí tu mensaje")),
                )
            ))
            //->add('captcha', 'genemu_captcha', array('attr' => array('placeholder' => 'Indica el texto de la figura', 'class' => 'form-control')))
            ->add('submit', SubmitType::class, array('label' => 'Enviar', 'attr' => array('class' => 'btn btn-blue btn-effect')))
            ->getForm();


    }
}
