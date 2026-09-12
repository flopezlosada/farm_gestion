<?php

namespace App\Command;

use App\Service\News\NewsAnnouncer;
use App\Service\News\NewsEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manda las novedades de la web que administración ha pedido contar.
 *
 * NO DECIDE QUÉ NI CUÁNDO: eso lo decide una persona pulsando el botón de
 * /gestion/novedades, que deja anotado hasta dónde quiere contar. Esta tarea
 * sólo mira si hay algo pedido sin mandar y lo manda. La mayoría de las veces no
 * hay nada y sale en verde sin hacer nada, que es lo normal en una tarea que
 * vigila una decisión humana.
 *
 * POR QUÉ EL ENVÍO ESTÁ AQUÍ Y NO EN EL BOTÓN. Son ~130 direcciones y cada
 * correo es una transacción SMTP: dentro de una petición web, en un hosting
 * compartido, el proceso se muere a mitad de lote por tiempo de ejecución y
 * quien pulsó ve un error sin saber a quién le llegó. Es la regla que
 * {@see \App\Service\Push\PushSender} ya deja escrita para el push y que cumple
 * el resto de envíos del proyecto.
 *
 * CADA HORA Y NO A UNA HORA FIJA: lo que dispara esto es que alguien pulse un
 * botón, y hacerle esperar hasta el día siguiente convertiría "contarlo" en algo
 * que no se sabe cuándo pasa. Con el tick horario, sale como mucho una hora
 * después.
 *
 * SIN `requires`: el aviso sale por tres vías (correo, bandeja y móvil) y ahí un
 * interruptor de entrega inhibiría la tarea ENTERA, dejando sin nada a quien lo
 * quiere por otra. El corte general del correo se comprueba dentro, donde sólo
 * afecta al correo.
 */
#[AsCommand(
    name: 'app:announce-news',
    description: 'Manda las novedades de la web que administración ha pedido contar.',
)]
class AnnounceNewsCommand extends AbstractCronCommand
{
    public function __construct(private readonly NewsAnnouncer $announcer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            // Repite SÓLO el correo, que es lo único que se rescata así: la copia
            // de la bandeja duplicada son dos filas idénticas, y repetir el push
            // gasta un canal que no vuelve. Sirve para el caso real —un lote de
            // correo cortado a mitad—; para repetir una tanda ya cerrada hay que
            // bajar a mano el puntero `news.announced_through`.
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite el correo a quien ya constaba avisadx (sólo el correo, no el móvil ni la bandeja)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que saldría, sin enviar ni registrar nada');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entries = $this->announcer->inFlight();

        if ([] === $entries) {
            return $this->nothingToDo('sin novedades pedidas');
        }

        if ($input->getOption('dry-run')) {
            $io->section(sprintf('Saldrían %d novedades', \count($entries)));
            $io->listing(array_map(static fn (NewsEntry $entry): string => $entry->title, $entries));

            return self::SUCCESS;
        }

        $result = $this->announcer->deliver((bool) $input->getOption('resend'));

        $io->success(sprintf(
            '%d novedades contadas: %d por correo, %d en la bandeja y %d al móvil.',
            \count($result['entries']),
            $result['email'],
            $result['inbox'],
            $result['push'],
        ));

        // Las tres cifras en el resumen de la ejecución, no sólo el total: se
        // leen desde /gestion/settings y del histórico, y son tres alcances muy
        // distintos. Un "N avisos" haría pensar que llegó a más gente de la que
        // llegó.
        return $this->didWork(sprintf(
            '%d novedades · %d correos · %d bandeja · %d móvil',
            \count($result['entries']),
            $result['email'],
            $result['inbox'],
            $result['push'],
        ));
    }
}
