<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una llamada ya enviada pidiendo gente para una oferta, y a qué alcance se
 * envió. Es el registro que hace posible el escalado del aviso y, sobre todo,
 * que no se repita.
 *
 * POR QUÉ EL AVISO SE ESCALA EN VEZ DE IR A TODO EL MUNDO. El permiso de
 * notificaciones del navegador se pierde UNA sola vez y para siempre: quien lo
 * deniega o lo apaga no vuelve a recibir nada, porque `requestPermission()` ya
 * no llega ni a enseñar el diálogo y hay que entrar a mano en los ajustes del
 * sitio. Avisar a 246 socixs de que hacen falta dos personas para desbrozar
 * molesta a 244, y a la tercera vez media asociación ha apagado el canal para
 * el día que de verdad haga falta. Por eso el aviso se abre por pasos:
 *
 *  1. {@see SCOPE_MATCHING} — quien tiene marcada alguna de las categorías de
 *     la oferta. Aquí el aviso es pertinente y no gasta nada.
 *  2. {@see SCOPE_UNSPECIFIED} — quien no ha marcado ninguna categoría, y sólo
 *     si la oferta está marcada como apta para cualquiera
 *     ({@see VolunteerOffer::$openToAnyone}). El silencio se puede interpretar.
 *  3. {@see SCOPE_EVERYONE} — todo el mundo, sin filtros. Nunca automático: lo
 *     lanza una persona que ha decidido que la cosa es seria.
 *
 * QUIEN DIJO QUE NO, NO ENTRA EN EL PASO 2. Alguien que marcó "huerta" y
 * "cocina" está diciendo activamente que de obras no le avisen. Colarle el
 * aviso porque "total, es fácil" convierte la ficha de preferencias en una
 * mentira, y entonces ya no la rellena nadie.
 *
 * UNICIDAD (offer, scope): cada alcance se envía una vez por oferta, garantizado
 * por la BBDD. Es lo que hace que el reintento del planificador —que reintenta
 * al siguiente tick cuando algo falla, por diseño— no mande el mismo aviso dos
 * veces, y lo que protege del doble clic en el botón de avisar. El precio es
 * que insistir a todo el mundo por segunda vez sobre la misma oferta no se
 * puede: hay que decidirlo así a propósito, y me parece el lado correcto en el
 * que equivocarse.
 *
 * @ORM\Table(name="volunteer_call", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_volunteer_call_scope", columns={"offer_id", "scope"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerCallRepository")
 */
class VolunteerCall
{
    /** A quien tiene marcada alguna categoría de la oferta. */
    public const SCOPE_MATCHING = 'matching';

    /** A quien no ha marcado ninguna categoría. Sólo si la oferta es apta para cualquiera. */
    public const SCOPE_UNSPECIFIED = 'unspecified';

    /** A todo el mundo. Sólo a mano. */
    public const SCOPE_EVERYONE = 'everyone';

    /**
     * Los alcances que el escalado automático puede abrir, en orden. El envío a
     * todo el mundo no está aquí a propósito: no se llega a él solo.
     */
    public const AUTOMATIC_SCOPES = [self::SCOPE_MATCHING, self::SCOPE_UNSPECIFIED];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerOffer")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?VolunteerOffer $offer = null;

    /**
     * @ORM\Column(type="string", length=16)
     */
    private string $scope = self::SCOPE_MATCHING;

    /**
     * A cuánta gente se le mandó. Se guarda el número y no la lista: sirve para
     * que quien coordina sepa el alcance real que tuvo el aviso, y guardar 246
     * filas por llamada no aporta nada que se vaya a mirar.
     *
     * @ORM\Column(type="integer", options={"default": 0})
     */
    private int $recipients = 0;

    /**
     * Quién lo lanzó, o null si lo abrió el escalado automático. Ese null es el
     * que distingue "lo decidió una persona" de "se abrió solo".
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="triggered_by_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?User $triggeredBy = null;

    /**
     * @ORM\Column(name="sent_at", type="datetime")
     */
    private \DateTimeInterface $sentAt;

    public function __construct()
    {
        $this->sentAt = new \DateTime();
    }

    /**
     * Si esta llamada la lanzó una persona en vez del escalado automático.
     *
     * @return bool true si la lanzó alguien a mano
     */
    public function isManual(): bool
    {
        return null !== $this->triggeredBy;
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return VolunteerOffer|null la oferta por la que se pide gente
     */
    public function getOffer(): ?VolunteerOffer
    {
        return $this->offer;
    }

    /**
     * @param VolunteerOffer|null $offer la oferta por la que se pide gente
     */
    public function setOffer(?VolunteerOffer $offer): self
    {
        $this->offer = $offer;

        return $this;
    }

    /**
     * @return string el alcance del envío
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * @param string $scope el alcance del envío
     */
    public function setScope(string $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    /**
     * @return int a cuánta gente se le mandó
     */
    public function getRecipients(): int
    {
        return $this->recipients;
    }

    /**
     * @param int $recipients a cuánta gente se le mandó
     */
    public function setRecipients(int $recipients): self
    {
        $this->recipients = $recipients;

        return $this;
    }

    /**
     * @return User|null quién lo lanzó, o null si fue automático
     */
    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    /**
     * @param User|null $triggeredBy quién lo lanza; null si es automático
     */
    public function setTriggeredBy(?User $triggeredBy): self
    {
        $this->triggeredBy = $triggeredBy;

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo se mandó
     */
    public function getSentAt(): \DateTimeInterface
    {
        return $this->sentAt;
    }
}
