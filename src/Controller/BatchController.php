<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use App\Entity\Fowl;
use App\Form\BatchEditType;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Batch;
use App\Form\BatchType;
use Symfony\Component\Validator\Constraints\DateTime;

/**
 * Batch controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class BatchController extends AbstractAppController
{

    /**
     * Lists all Batch entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Batch::class)->findAll();

        return $this->render('Batch/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Batch entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Batch();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $entity->setPurchaseDate(new \DateTime($entity->getPurchaseDate()));
            $entity->setReceiptDate(new \DateTime($entity->getReceiptDate()));
            $batch_status = $em->getRepository(\App\Entity\BatchStatus::class)->find(1);
            $entity->setBatchStatus($batch_status);

            $fowl_status = $em->getRepository(\App\Entity\FowlStatus::class)->find(1);
            for ($i = 1; $i <= $entity->getAmount(); $i++) {
                $fowl = new Fowl();
                $fowl->setBatch($entity);
                $fowl->setFowlStatus($fowl_status);
                $em->persist($fowl);
            }
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $entity->getId())));
        }

        return $this->render('Batch/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Batch entity.
     *
     * @param Batch $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Batch $entity)
    {
        $form = $this->createForm(BatchType::class, $entity, array(
            'action' => $this->generateUrl('batch_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Batch entity.
     *
     */
    public function new()
    {
        $entity = new Batch();
        $form = $this->createCreateForm($entity);

        return $this->render('Batch/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Batch entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Batch::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }
        $week_lay = $em->getRepository(\App\Entity\Lay::class)->findWeekLay(null, null, $id);
        $highchart_week_lay = $em->getRepository(\App\Entity\Lay::class)->findHighchartWeekLay($id);
        $month_lay = $em->getRepository(\App\Entity\Lay::class)->findAllMonthLay($id);//devuelve las puestas por meses, en orden

        //gastos  e ingresos por mes
        $month_expenses = $em->getRepository(\App\Entity\Sack::class)->findAllMonthExpenses($id);
        $incoming_expenses=array();

        $wheat=$em->getRepository(\App\Entity\Product::class)->find(13);//trigo grano
        $feed=$em->getRepository(\App\Entity\Product::class)->find(4);//pienso ponedoras
        foreach ($month_expenses as $dates_expenses)
        {
            $data_incoming=$em->getRepository(\App\Entity\Lay::class)->findIncoming($id,$dates_expenses['year_date'],$dates_expenses['month']);//huevos
            $data_expenses = $em->getRepository(\App\Entity\Sack::class)->findMonthExpenses($id,$dates_expenses['year_date'],$dates_expenses['month']);//consumos
            if ($data_incoming==null)
            {
                $data_incoming=array("total"=>0,"month"=>$data_expenses["month"],"year_date"=>$data_expenses["year_date"]);
            }

            $wheat_amount=$em->getRepository(\App\Entity\Sack::class)->findFoodEatForBatch($id,$dates_expenses['year_date'],$dates_expenses['month'],$wheat);
            $feed_amount=$em->getRepository(\App\Entity\Sack::class)->findFoodEatForBatch($id,$dates_expenses['year_date'],$dates_expenses['month'],$feed);
            $incoming_expenses[]=array($data_expenses,$data_incoming,$wheat_amount, $feed_amount);
        }


        // gastos e ingresos relativos a la fecha de compra, para comparar entre lotes
        $relative_incoming_expenses=array();
        $days=10;//período de análisis
        $days_in_work=$entity->getProductionTime();
        $period_number=ceil($days_in_work/$days);//número de períodos a contabilizar. Son períodos de 30 días
        for ($i=1; $i<=$period_number;$i++)
        {
            $relative_incoming=$em->getRepository(\App\Entity\Lay::class)->findRelativeIncoming($entity,$i,$days);
            $relative_expenses=$em->getRepository(\App\Entity\Sack::class)->findRelativeExpenses($entity,$i,$days);
            $relative_wheat_amount=$em->getRepository(\App\Entity\Sack::class)->findRelativeFoodEatForBatch($entity,$i,$wheat,$days);
            $relative_feed_amount=$em->getRepository(\App\Entity\Sack::class)->findRelativeFoodEatForBatch($entity,$i,$feed,$days);

            $relative_incoming_expenses[]=array($relative_incoming, $relative_expenses,$relative_wheat_amount,$relative_feed_amount);
        }



        $start_time = 0;
        $start_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
        $end_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
        $consumption = array();//guarda todos los consumos diarios calculados (cada 14 días)
        while ($start_time <= $entity->getProductionTime()) {
            $end_time = $start_time + 14;
            //echo "tiempo de producción: " . $entity->getProductionTime()." start_time: ".$start_time."<br>";
            $start_date->add(date_interval_create_from_date_string($start_time . ' days'));
            $end_date->add(date_interval_create_from_date_string($end_time . ' days'));
            //echo $end_date->format("Y-m-d") . "<br>";
            $feed_amount = $em->getRepository(\App\Entity\Batch::class)->getFeedAmountInInterval($entity, $start_date, $end_date);//cantidad de pienso consumido en el intervalo
            $fowls = $em->getRepository(\App\Entity\Batch::class)->getFowlsAliveInInterval($entity, $end_date);//animales vivos en el intervalo.
            //echo "comida: " . $feed_amount . " y bichos: " . $fowls . "<br>";
            $consumption[] = $fowls > 0 ? $feed_amount / ($fowls * 14) : 0;
            $start_time += 14;
            $start_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
            $end_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
        }

        $movements = $em->getRepository(\App\Entity\Movement::class)->findMovements($id);


        $average_consumption = array_sum($consumption) * 1000 / count($consumption);

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Batch/show.html.twig', array(
            'entity' => $entity,
            'week_lay' => array_reverse($week_lay),
            'average_consumption' => $average_consumption,
            'delete_form' => $deleteForm->createView(),
            'highchart_week_lay' => $highchart_week_lay,
            'movements' => $movements,
            'month_lay' => $month_lay,
            "incoming_expenses"=>$incoming_expenses,
            'relative_incoming_expenses'=>$relative_incoming_expenses,
            'days'=>$days

        ));
    }

    /**
     * Displays a form to edit an existing Batch entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Batch::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }

        $entity->setPurchaseDate($entity->getPurchaseDate()->format('Y-m-d'));
        $entity->setReceiptDate($entity->getReceiptDate()->format('Y-m-d'));
        if ($entity->getBatchStatus()->getId() == 2) {
            $entity->setFinalizationDate($entity->getFinalizationDate()->format('Y-m-d'));
        }
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Batch/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }


    /**
     * Creates a form to edit a Batch entity.
     *
     * @param Batch $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Batch $entity)
    {
        $form = $this->createForm(BatchEditType::class, $entity, array(
            'action' => $this->generateUrl('batch_update', array('id' => $entity->getId())),
            'method' => 'PUT',
            'batch' => $entity
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Batch entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Batch::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }

        $entity->setPurchaseDate($entity->getPurchaseDate()->format('Y-m-d'));
        $entity->setReceiptDate($entity->getReceiptDate()->format('Y-m-d'));
        if ($entity->getBatchStatus()->getId() == 2) {
            $entity->setFinalizationDate($entity->getFinalizationDate()->format('Y-m-d'));
        }
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setPurchaseDate(new \DateTime($entity->getPurchaseDate()));
            $entity->setReceiptDate(new \DateTime($entity->getReceiptDate()));
            if ($entity->getBatchStatus()->getId() == 2) {
                $entity->setFinalizationDate(new \DateTime($entity->getFinalizationDate()));
            }
            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $id)));
        }

        return $this->render('Batch/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Batch entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Batch::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Batch entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('batch'));
    }

    /**
     * Creates a form to delete a Batch entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('batch_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }

    public function close($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Batch::class)->find($id);
        $batch_status = $em->getRepository(\App\Entity\BatchStatus::class)->find(2);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }


        $entity->setBatchStatus($batch_status);
        if ($entity->getFinalizationDate() == null) {
            $entity->setFinalizationDate(new \DateTime());
        }
        $em->persist($entity);
        $em->flush();

        $this->addFlash(
            'notice',
            'El lote se ha actualizado correctamente.'
        );

        return $this->redirect($this->generateUrl("batch_show", array('id' => $id)));
    }

    public function reactivate($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Batch::class)->find($id);
        $batch_status = $em->getRepository(\App\Entity\BatchStatus::class)->find(1);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }


        $entity->setBatchStatus($batch_status);
        $em->persist($entity);
        $em->flush();

        $this->addFlash(
            'notice',
            'El lote se ha actualizado correctamente.'
        );

        return $this->redirect($this->generateUrl("batch_show", array('id' => $id)));
    }


    public function analysesYear($product_id, $year)
    {
        $em = $this->getDoctrine()->getManager();


        if (!$year) {
            $year = date('Y');
        }

        $product = $em->getRepository(\App\Entity\Product::class)->find($product_id);//pollos o gallinas


        $batchs = $em->getRepository(\App\Entity\Batch::class)->findByProductYear($product_id, $year);


        foreach ($batchs as $batch) {
            $put_down_fowls = 0;//número de animales sacrificados
            $total_put_down_weight = 0;//peso vivo acumulado de todo el lote. Suma de pesos vivos individuales de animales sacrificados
            $total_carcass_weight = 0;//peso canal acumulado de todo el lote. Suma de pesos canal individuales de animales sacrificados
            foreach ($batch->getFowls() as $fowl) {
                if ($fowl->getFowlStatus()->getId() == 4) {
                    $put_down_fowls++;
                    $total_put_down_weight += $fowl->getPutDownWeight();
                    $total_carcass_weight += $fowl->getCarcassWeight();
                }
            }

            $batch->setPutDownTotal($put_down_fowls);
            $batch->setTotalPutDownWeight($total_put_down_weight);
            $batch->setAveragePutDownWeight($put_down_fowls > 0 ? $total_put_down_weight / $put_down_fowls : 0);
            $batch->setTotalCarcassWeight($total_carcass_weight);
            $batch->setAverageCarcassWeight($put_down_fowls > 0 ? $total_carcass_weight / $put_down_fowls : 0);
        }

        return $this->render('Batch/analyses_year.html.twig', array(
            'batchs' => $batchs,
            'product' => $product,
            'year' => $year
        ));
    }

    public function analyses($product_id)
    {
        $em = $this->getDoctrine()->getManager();
        $product = $em->getRepository(\App\Entity\Product::class)->find($product_id);//pollos o gallinas
        $years = $em->getRepository(\App\Entity\Batch::class)->findYearsInProduction($product_id);//devuelve los años en que se ha producido según el producto, ya sea gallinas o pollos

        return $this->render('Batch/analyses.html.twig', array(
            'years' => $years,
            'product' => $product,
        ));
    }

    public function hens_analyses()
    {
        $em = $this->getDoctrine()->getManager();
        $hens_batchs = $em->getRepository(\App\Entity\Batch::class)->findBatchs(6, 5);//devuelve lotes del tipo 6 y límite 4 lotes
        $batchs_week_lay = array();
        $batchs_month_lay = array();

        foreach ($hens_batchs as $batch) {
            $batchs_week_lay[] = array($em->getRepository(\App\Entity\Lay::class)->findHighchartWeekLay($batch->getId()), $batch);
            $batchs_month_lay[] = array($em->getRepository(\App\Entity\Lay::class)->findAllMonthLay($batch->getId()), $batch);
        }
        $weeks_in_production = $em->getRepository(\App\Entity\Lay::class)->findWeeksInProduction();

        $graph_lay_weeks = array();
        $i = 0;
        foreach ($hens_batchs as $batch) {
            $array = array();
            foreach ($weeks_in_production as $week) {
                if (!$em->getRepository(\App\Entity\Lay::class)->findLayInWeekYear($week['week'], $week['year_date'], $batch->getId())) {
                    $array[] = 0;
                } else {
                    $value = $em->getRepository(\App\Entity\Lay::class)->findLayInWeekYear($week['week'], $week['year_date'], $batch->getId());
                    $array[] = $value['total'];
                }


            }
            $graph_lay_weeks[$i] = $array;
            $i++;
        }

        //ladybug_dump($graph_lay_weeks);
        //$month_lay = //devuelve las puestas por meses, en orden
        return $this->render('Batch/hens_analyses.html.twig', array(
            'hens_batchs' => $hens_batchs,
            'batchs_week_lay' => $batchs_week_lay,
            'batchs_month_lay' => $batchs_month_lay,
            'weeks_in_production' => $weeks_in_production,
            'graph_lay_weeks' => $graph_lay_weeks
        ));
    }
}
