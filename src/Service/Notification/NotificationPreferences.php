<?php

namespace App\Service\Notification;

use App\Entity\NotificationOptOut;
use App\Entity\Partner;
use App\Repository\NotificationOptOutRepository;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La única respuesta a «¿quiere esta persona este aviso por este canal?».
 *
 * EXISTE PORQUE LA PREGUNTA NO SE HACÍA. El recordatorio de la cesta se mandaba
 * por correo a todo quincenal y mensual con email, sin consultar nada: no había
 * forma de dejar de recibirlo salvo pidiéndoselo a administración. Y el
 * voluntariado sí miraba su opt-out, pero metido dentro de los tres finders de
 * audiencia de {@see \App\Repository\PartnerRepository}, donde no lo encuentra
 * quien busca "dónde se decide a quién se avisa".
 *
 * EL TEMA MANDA SOBRE LA PREFERENCIA. Si el módulo de un tema está apagado, no
 * se avisa aunque el socix lo tenga marcado: la preferencia dice qué quiere
 * recibir, no qué existe. Al revés también: reencender el módulo devuelve los
 * avisos a quien no los había silenciado, sin tener que tocar nada.
 *
 * SIN FILA = LO QUIERE. La tabla guarda sólo lo apagado ({@see NotificationOptOut}),
 * así que quien nunca ha entrado en la pantalla de avisos recibe todo, que es lo
 * que pasa hoy y lo que no debe cambiar por desplegar esto.
 */
class NotificationPreferences
{
    public function __construct(
        private readonly NotificationOptOutRepository $optOuts,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Si hay que avisar a este socix de este tema por este canal.
     *
     * @param Partner $partner el socix
     * @param string  $topic   clave de {@see NotificationTopic::TOPICS}
     * @param string  $channel uno de NotificationTopic::CHANNEL_*
     *
     * @return bool true si se le puede avisar
     */
    public function wants(Partner $partner, string $topic, string $channel): bool
    {
        if (!$this->available($topic, $channel)) {
            return false;
        }

        return !isset($this->optOuts->silencedFor($partner)[$topic . ':' . $channel]);
    }

    /**
     * Lo mismo para una lista, en UNA consulta.
     *
     * Es lo que usan los envíos masivos: llamar a {@see wants()} dentro del
     * bucle serían tantas consultas como socixs, justo en la tarea que más
     * gente toca.
     *
     * @param list<Partner> $partners lxs candidatxs
     * @param string        $topic    clave del tema
     * @param string        $channel  uno de CHANNEL_*
     *
     * @return list<Partner> lxs que quieren ese aviso por ese canal
     */
    public function filter(array $partners, string $topic, string $channel): array
    {
        if (!$this->available($topic, $channel) || [] === $partners) {
            return [];
        }

        $silenced = $this->optOuts->silencedForMany($partners);
        $key = $topic . ':' . $channel;

        return array_values(array_filter(
            $partners,
            static fn (Partner $partner): bool => !isset($silenced[(int) $partner->getId()][$key])
        ));
    }

    /**
     * Guarda de golpe lo que el socix ha marcado en la pantalla de avisos.
     *
     * Recibe lo que SÍ quiere y deduce lo apagado, en vez de recibir lo apagado:
     * el formulario manda las casillas marcadas, y una casilla que no viaja
     * —desmarcada— es indistinguible de una que no existe. Así desmarcar todo
     * funciona igual de bien que marcar.
     *
     * Sólo toca los temas y canales realmente disponibles: una casilla de un
     * módulo apagado no puede llegar aquí desde la pantalla, y si llegara
     * (formulario manipulado) se ignora en vez de guardar basura.
     *
     * @param Partner                      $partner el socix
     * @param array<string, list<string>>  $wanted  tema => canales que quiere
     */
    public function save(Partner $partner, array $wanted): void
    {
        $current = [];
        foreach ($this->optOuts->findBy(['partner' => $partner]) as $optOut) {
            $current[$optOut->getTopic() . ':' . $optOut->getChannel()] = $optOut;
        }

        foreach (NotificationTopic::TOPICS as $topic => $meta) {
            foreach ($meta['channels'] as $channel) {
                if (!$this->available($topic, $channel)) {
                    continue;
                }

                $key = $topic . ':' . $channel;
                $quiere = \in_array($channel, $wanted[$topic] ?? [], true);
                $apagado = $current[$key] ?? null;

                if ($quiere && null !== $apagado) {
                    $this->entityManager->remove($apagado);
                } elseif (!$quiere && null === $apagado) {
                    $this->entityManager->persist(
                        (new NotificationOptOut())
                            ->setPartner($partner)
                            ->setTopic($topic)
                            ->setChannel($channel)
                    );
                }
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Los temas que este socix puede configurar ahora mismo, ya resueltos: sin
     * los de módulos apagados y con el estado de cada canal.
     *
     * Lo arma el servicio y no la plantilla para que la pantalla no tenga que
     * saber qué feature gobierna cada tema ni cómo se leen los opt-outs.
     *
     * @param Partner $partner el socix
     *
     * @return list<array{key: string, label: string, help: string, channels: array<string, bool>}>
     */
    public function forPartner(Partner $partner): array
    {
        $silenced = $this->optOuts->silencedFor($partner);

        $topics = [];
        foreach (NotificationTopic::TOPICS as $topic => $meta) {
            if (null !== $meta['feature'] && !$this->settings->getBool($meta['feature'])) {
                continue;
            }

            $channels = [];
            foreach ($meta['channels'] as $channel) {
                $channels[$channel] = !isset($silenced[$topic . ':' . $channel]);
            }

            $topics[] = [
                'key' => $topic,
                'label' => $meta['label'],
                'help' => $meta['help'],
                'channels' => $channels,
            ];
        }

        return $topics;
    }

    /**
     * Si ese tema existe, manda por ese canal y su módulo está encendido.
     *
     * @param string $topic   clave del tema
     * @param string $channel uno de CHANNEL_*
     */
    private function available(string $topic, string $channel): bool
    {
        $meta = NotificationTopic::TOPICS[$topic] ?? null;
        if (null === $meta || !NotificationTopic::uses($topic, $channel)) {
            return false;
        }

        // El feature-flag se lee de los ajustes y no vía FeatureVoter porque
        // esto corre también en los comandos del planificador, donde no hay
        // usuario autenticado contra el que votar.
        return null === $meta['feature'] || $this->settings->getBool($meta['feature']);
    }
}
