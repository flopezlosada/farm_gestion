<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un arqueo: alguien ha contado el dinero de una caja de efectivo un día y lo ha
 * mandado al bot («caja huerta 400,56»).
 *
 * NO es un apunte: no mueve el saldo ni entra en el libro, es una observación de
 * cuánto había. Por eso se registra aunque el trimestre esté entregado a la gestoría.
 *
 * Es inmutable. Si se contó mal, se manda otro: de un mismo día vale el último. El
 * saldo del libro y la diferencia que guarda son la foto del momento en que llegó;
 * la portada no se fía de ellos y recalcula en vivo, para que un ticket olvidado que
 * se anota después con fecha de ese día o anterior haga desaparecer el aviso.
 *
 * El `update_id` de Telegram es único: si Telegram repite la entrega del mensaje, no
 * sale un arqueo duplicado.
 *
 * @ORM\Table(name="cash_count")
 * @ORM\Entity(repositoryClass="App\Repository\CashCountRepository")
 */
class CashCount
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="FinancialAccount")
     * @ORM\JoinColumn(name="account_id", nullable=false)
     */
    private FinancialAccount $account;

    /**
     * El día del recuento. Se compara con el saldo al cierre de ese día.
     *
     * @ORM\Column(name="count_date", type="date_immutable")
     */
    private \DateTimeImmutable $countDate;

    /**
     * Lo contado, en positivo.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private string $counted;

    /**
     * El saldo del libro con el que se comparó al llegar: el que coincidía, si alguno
     * coincidía (antes o después de los apuntes de ese día); si no, el del cierre del día.
     *
     * @ORM\Column(name="book_balance", type="decimal", precision=10, scale=2)
     */
    private string $bookBalance;

    /**
     * Lo contado menos {@see $bookBalance}: negativo, falta dinero en la caja.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2)
     */
    private string $difference;

    /**
     * Quién lo contó. Si se borra la cuenta, el arqueo se queda: es un registro de caja.
     *
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="counted_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $countedBy;

    /**
     * Cuándo llegó.
     *
     * @ORM\Column(name="received_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $receivedAt;

    /**
     * El mensaje tal cual, por si hay que comprobar qué se entendió.
     *
     * @ORM\Column(name="message_text", type="string", length=255)
     */
    private string $messageText;

    /**
     * El `update_id` del mensaje de Telegram; null si se colocó desde la web.
     *
     * @ORM\Column(name="telegram_update_id", type="bigint", nullable=true, unique=true)
     */
    private ?string $telegramUpdateId;

    /**
     * @param FinancialAccount   $account          Caja contada.
     * @param \DateTimeImmutable $countDate        Día del recuento.
     * @param int                $countedCents     Lo contado, en céntimos.
     * @param int                $bookBalanceCents Saldo del libro con el que se comparó, en céntimos.
     * @param User|null          $countedBy        Quién lo contó.
     * @param \DateTimeImmutable $receivedAt       Cuándo llegó.
     * @param string             $messageText      El mensaje tal cual.
     * @param int|null           $telegramUpdateId Su `update_id`, si vino por Telegram.
     *
     * @throws \InvalidArgumentException Si lo contado es negativo: en una caja no puede haber menos de nada.
     */
    public function __construct(
        FinancialAccount $account,
        \DateTimeImmutable $countDate,
        int $countedCents,
        int $bookBalanceCents,
        ?User $countedBy,
        \DateTimeImmutable $receivedAt,
        string $messageText,
        ?int $telegramUpdateId,
    ) {
        if ($countedCents < 0) {
            throw new \InvalidArgumentException('Lo contado en una caja no puede ser negativo.');
        }
        $this->account = $account;
        $this->countDate = $countDate->setTime(0, 0);
        $this->counted = IssuedInvoice::fromCents($countedCents);
        $this->bookBalance = IssuedInvoice::fromCents($bookBalanceCents);
        $this->difference = IssuedInvoice::fromCents($countedCents - $bookBalanceCents);
        $this->countedBy = $countedBy;
        $this->receivedAt = $receivedAt;
        $this->messageText = mb_substr($messageText, 0, 255);
        $this->telegramUpdateId = $telegramUpdateId !== null ? (string) $telegramUpdateId : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): FinancialAccount
    {
        return $this->account;
    }

    public function getCountDate(): \DateTimeImmutable
    {
        return $this->countDate;
    }

    public function getCounted(): string
    {
        return $this->counted;
    }

    /** Lo contado, en céntimos, para comparar sin decimales flotantes. */
    public function getCountedCents(): int
    {
        return IssuedInvoice::toCents($this->counted);
    }

    public function getBookBalance(): string
    {
        return $this->bookBalance;
    }

    public function getDifference(): string
    {
        return $this->difference;
    }

    public function getCountedBy(): ?User
    {
        return $this->countedBy;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getMessageText(): string
    {
        return $this->messageText;
    }

    public function getTelegramUpdateId(): ?int
    {
        return $this->telegramUpdateId !== null ? (int) $this->telegramUpdateId : null;
    }
}
