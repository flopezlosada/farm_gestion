<?php

namespace App\Service\Volunteering;

use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Repository\UserRepository;
use App\Repository\VolunteerOfferRepository;
use App\Service\AppSettings;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * El "por dónde": convierte una decisión de avisar en avisos enviados y en un
 * {@see VolunteerCall} registrado.
 *
 * Junta las tres piezas y no decide ninguna: el "cuándo" es de
 * {@see VolunteerCallEscalator}, el "quiénes" de
 * {@see VolunteerAudienceResolver} y el envío de {@see PushSender}. Aquí sólo
 * se orquesta, se redacta el mensaje y se deja constancia.
 *
 * SÓLO PUSH, DE MOMENTO. Quien no tiene cuenta de acceso a la web no recibe
 * nada por aquí, y es una decisión consciente: el canal universal del módulo es
 * el panel, donde lo que hace falta está siempre a la vista. Añadir correo es un
 * bloque aparte con su propio toggle, y conviene ver antes si la gente se
 * apunta — si no se apunta, mandar lo mismo por dos canales no lo arregla.
 *
 * EL REGISTRO SE ESCRIBE ANTES DE ENVIAR. Si se escribiera después, un fallo a
 * mitad del lote dejaría el aviso mandado a media asociación y sin constancia,
 * y el siguiente tick lo repetiría desde cero. Con la fila escrita primero, el
 * UNIQUE (offer, scope) impide la repetición aunque el envío se tuerza: es
 * preferible un aviso que no salió a un aviso que salió dos veces.
 */
class VolunteerCallNotifier
{
    public function __construct(
        private readonly VolunteerOfferRepository $offers,
        private readonly UserRepository $users,
        private readonly VolunteerAudienceResolver $audience,
        private readonly VolunteerCallEscalator $escalator,
        private readonly PushSender $push,
        private readonly NotificationPreferences $preferences,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $settings,
        private readonly VolunteerOfferFormatter $formatter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Manda los avisos que toquen ahora mismo, recorriendo las ofertas abiertas.
     * Es lo que llama el planificador.
     *
     * @param \DateTimeImmutable $now momento de referencia
     *
     * @return int número de llamadas enviadas
     */
    public function dispatchDue(\DateTimeImmutable $now): int
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_VOLUNTEERING)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->offers->findUpcoming($now) as $offer) {
            $scope = $this->escalator->nextScope($offer, $now);
            if (null === $scope) {
                continue;
            }

            if (null !== $this->dispatch($offer, $scope, null, $now)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Manda UNA llamada de un alcance concreto y la registra.
     *
     * Devuelve null cuando no había a quien avisar: sin destinatarios no se
     * registra nada, para que el alcance siga disponible si más adelante entra
     * gente nueva que sí encaje.
     *
     * @param VolunteerOffer     $offer       la oferta por la que se pide gente
     * @param string             $scope       uno de VolunteerCall::SCOPE_*
     * @param User|null          $triggeredBy quién lo lanza; null si es automático
     * @param \DateTimeImmutable $now         momento de referencia
     *
     * @return VolunteerCall|null la llamada registrada, o null si no había a quien avisar
     */
    public function dispatch(
        VolunteerOffer $offer,
        string $scope,
        ?User $triggeredBy,
        \DateTimeImmutable $now,
    ): ?VolunteerCall {
        // Quiénes encajan con la tarea…
        $partners = $this->audience->resolve($offer, $scope);

        // …y de esos, quiénes quieren que se les avise por aquí. Son dos
        // preguntas distintas y conviene que se lean como tales: la audiencia
        // dice a quién le sirve la tarea, la preferencia si quiere enterarse.
        $partners = $this->preferences->filter(
            $partners,
            NotificationTopic::VOLUNTEERING,
            NotificationTopic::CHANNEL_PUSH,
        );

        if ([] === $partners) {
            return null;
        }

        $recipients = $this->users->findByPartners($partners);
        if ([] === $recipients) {
            return null;
        }

        $call = (new VolunteerCall())
            ->setOffer($offer)
            ->setScope($scope)
            ->setTriggeredBy($triggeredBy)
            ->setRecipients(\count($recipients));

        try {
            $this->entityManager->persist($call);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Otro tick, u otra pestaña de gestión, ganó la carrera y ya avisó
            // de esto. La constancia existe igual, así que no se manda nada:
            // repetir el aviso es exactamente lo que el UNIQUE evita.
            $this->logger->info('Aviso de voluntariado ya enviado por otra vía', [
                'offer' => $offer->getId(),
                'scope' => $scope,
            ]);

            return null;
        }

        $this->push->sendToMany(
            $recipients,
            $this->title($offer),
            $this->body($offer),
            '/panel/voluntariado'
        );

        return $call;
    }

    /**
     * El título del aviso: qué hace falta, en cinco palabras.
     *
     * @param VolunteerOffer $offer la oferta
     *
     * @return string el título
     */
    private function title(VolunteerOffer $offer): string
    {
        $remaining = $offer->getRemainingSlots();

        if (1 === $remaining) {
            return 'Falta una persona';
        }

        return null !== $remaining
            ? sprintf('Faltan %d personas', $remaining)
            : 'Hace falta gente';
    }

    /**
     * El cuerpo: qué, cuándo y dónde. En ese orden porque es el orden en que se
     * decide si puedes ir.
     *
     * @param VolunteerOffer $offer la oferta
     *
     * @return string el cuerpo del aviso
     */
    private function body(VolunteerOffer $offer): string
    {
        $parts = [$offer->getTitle(), $this->formatter->date($offer->getStartsAt())];

        $where = $this->formatter->place($offer);
        if (null !== $where) {
            $parts[] = $where;
        }

        return implode(' · ', $parts);
    }
}
