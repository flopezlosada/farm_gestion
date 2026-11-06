<?php

namespace App\Entity;

use App\Service\Accounting\Invoice\ExtractedInvoice;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Una factura o un ticket que alguien ha entregado a la asociación: el fichero y lo
 * que dice.
 *
 * Es a la vez la COLA y el ARCHIVO. Entra como fichero sin leer (pendiente), una
 * máquina la lee y la deja propuesta (leída), y quien lleva las cuentas la revisa y
 * la acepta (confirmada). Si la lectura no sale después de muchos intentos, se queda
 * como no leída y se completa a mano: el fichero nunca se pierde por un fallo de
 * lectura.
 *
 * Aceptarla y pagarla son cosas distintas. Pagada en caja o con tarjeta, se acepta
 * con su apunte. Pagada por banco, se acepta sin él: el pago lo trae el extracto, y
 * al anotar ese movimiento se engancha a ella ({@see paidBy()}). Mientras, está
 * aceptada y esperando el pago ({@see isAwaitingPayment()}).
 *
 * Los datos fiscales (CIF, número, bases, IVA y retención) viven aquí y no en el apunte porque
 * son de la factura, no del movimiento de dinero: un apunte de banco puede no tener
 * factura, y es esta tabla la que se entrega a la gestoría.
 *
 * @ORM\Table(name="received_invoice", indexes={
 *     @ORM\Index(name="idx_received_invoice_status", columns={"status", "next_attempt_at"}),
 *     @ORM\Index(name="idx_received_invoice_tax_id", columns={"provider_tax_id"}),
 *     @ORM\Index(name="idx_received_invoice_file_hash", columns={"file_hash"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\ReceivedInvoiceRepository")
 */
class ReceivedInvoice
{
    /** En cola: el fichero está guardado y falta leerlo. */
    public const STATUS_PENDING = 'pending';

    /** Leída: los datos están propuestos y esperan a que alguien los confirme. */
    public const STATUS_READ = 'read';

    /** La lectura automática se rindió: hay que completar los datos a mano. */
    public const STATUS_UNREADABLE = 'unreadable';

    /**
     * Aceptada: sus datos están revisados y va en el paquete de la gestoría. Con su
     * apunte, o esperando a que el pago llegue con el extracto del banco.
     */
    public const STATUS_CONFIRMED = 'confirmed';

    /** Descartada: duplicada, equivocada o no era una factura. */
    public const STATUS_DISCARDED = 'discarded';

    /** Los estados de lo que sigue en la bandeja, por resolver. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_READ, self::STATUS_UNREADABLE];

    /** Subida desde la web. */
    public const SOURCE_WEB = 'web';

    /** Mandada al bot de Telegram. */
    public const SOURCE_TELEGRAM = 'telegram';

    /** Adjunta a un correo al buzón de facturas. */
    public const SOURCE_EMAIL = 'email';

    public const PAYMENT_CARD = 'tarjeta';
    public const PAYMENT_CASH = 'efectivo';
    public const PAYMENT_TRANSFER = 'transferencia';
    public const PAYMENT_DIRECT_DEBIT = 'domiciliacion';
    public const PAYMENT_UNKNOWN = 'desconocida';

    public const PAYMENT_LABELS = [
        self::PAYMENT_CARD => 'Tarjeta',
        self::PAYMENT_CASH => 'Efectivo',
        self::PAYMENT_TRANSFER => 'Transferencia',
        self::PAYMENT_DIRECT_DEBIT => 'Domiciliación',
        self::PAYMENT_UNKNOWN => 'No consta',
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Nombre con el que quedó en el archivo privado, no el original.
     * @ORM\Column(name="file_name", type="string", length=255)
     */
    private string $fileName;

    /**
     * La huella SHA-256 del fichero: dos facturas con la misma son el mismo documento
     * subido dos veces (por la web y por el correo, o con otro nombre). Null en las que
     * entraron antes de guardarla.
     *
     * @ORM\Column(name="file_hash", type="string", length=64, nullable=true)
     */
    private ?string $fileHash = null;

    /**
     * El nombre que traía el fichero, para enseñarlo.
     * @ORM\Column(name="original_name", type="string", length=255)
     */
    private string $originalName;

    /**
     * @ORM\Column(name="mime_type", type="string", length=100)
     */
    private string $mimeType;

    /**
     * Por dónde entró: la web, Telegram o el buzón de facturas.
     * @ORM\Column(type="string", length=20)
     */
    private string $source;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="uploaded_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $uploadedBy = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status = self::STATUS_PENDING;

    /**
     * Veces que se ha intentado leer. Cuenta también los intentos fallidos, porque
     * el servicio gratuito los cobra igual contra su cupo.
     * @ORM\Column(type="smallint", options={"default": 0})
     */
    private int $attempts = 0;

    /**
     * Cuándo puede volver a intentarse. Sirve también de reserva: quien la coge para
     * leerla la aparta unos minutos hacia delante, así que dos lectores a la vez no
     * la leen dos veces. Null = en cuanto se pueda.
     * @ORM\Column(name="next_attempt_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $nextAttemptAt = null;

    /**
     * Por qué falló el último intento, en palabras que se puedan enseñar.
     * @ORM\Column(name="last_error", type="string", length=255, nullable=true)
     */
    private ?string $lastError = null;

    /**
     * @ORM\Column(name="read_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $readAt = null;

    /**
     * Qué modelo la leyó. Si un modelo empieza a leer mal, así se sabe cuáles revisar.
     * @ORM\Column(name="read_by_model", type="string", length=60, nullable=true)
     */
    private ?string $readByModel = null;

    /**
     * factura, factura simplificada, ticket u otro.
     * @ORM\Column(name="document_type", type="string", length=30, nullable=true)
     */
    private ?string $documentType = null;

    /**
     * @ORM\Column(name="invoice_date", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $invoiceDate = null;

    /**
     * El día en que se anotó una factura que llegó tarde, cuando el trimestre de su
     * fecha ya se había entregado a la gestoría. Null en las demás. Es lo que la pone
     * en el paquete del trimestre siguiente en vez de en el entregado: la recibida se
     * declara en el periodo en que se recibe (art. 99 LIVA, hasta 4 años) y la gestoría
     * no rehace un trimestre presentado.
     *
     * @ORM\Column(name="booked_on", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $bookedOn = null;

    /**
     * @ORM\Column(name="provider_name", type="string", length=150, nullable=true)
     */
    private ?string $providerName = null;

    /**
     * CIF o NIF de quien emite. Es la forma fiable de reconocer a un proveedor:
     * el nombre se escribe de mil maneras, el CIF no.
     * @ORM\Column(name="provider_tax_id", type="string", length=20, nullable=true)
     */
    private ?string $providerTaxId = null;

    /**
     * A nombre de quién dice la factura que está, tal como se leyó. Sólo para
     * avisar si no es la asociación: la factura que va a la gestoría tiene que
     * llevar sus datos.
     * @ORM\Column(name="customer_name", type="string", length=150, nullable=true)
     */
    private ?string $customerName = null;

    /**
     * CIF del cliente tal como se leyó; es lo que se compara con el de la
     * asociación (el nombre puede venir mal escrito y valer igual).
     * @ORM\Column(name="customer_tax_id", type="string", length=20, nullable=true)
     */
    private ?string $customerTaxId = null;

    /**
     * Dirección del proveedor tal como la imprime la factura. Se guarda aquí, y no
     * sólo en su ficha, porque es lo que dice ESTE papel; al confirmar completa los
     * huecos de la ficha.
     *
     * @ORM\Column(name="provider_address", type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $providerAddress = null;

    /**
     * @ORM\Column(name="provider_postal_code", type="string", length=10, nullable=true)
     */
    #[Assert\Regex(pattern: '/^\d{5}$/', message: 'El código postal son 5 cifras.')]
    private ?string $providerPostalCode = null;

    /**
     * @ORM\Column(name="provider_town", type="string", length=100, nullable=true)
     */
    #[Assert\Length(max: 100)]
    private ?string $providerTown = null;

    /**
     * @ORM\Column(name="provider_province", type="string", length=100, nullable=true)
     */
    #[Assert\Length(max: 100)]
    private ?string $providerProvince = null;

    /**
     * El proveedor reconocido por su CIF, al confirmar. Null mientras está en la
     * bandeja y en las que no traen CIF (un ticket de gasolinera): sin CIF no hay
     * forma fiable de saber quién es.
     *
     * @ORM\ManyToOne(targetEntity="Provider")
     * @ORM\JoinColumn(name="provider_id", nullable=true, onDelete="SET NULL")
     */
    private ?Provider $provider = null;

    /**
     * @ORM\Column(name="invoice_number", type="string", length=50, nullable=true)
     */
    private ?string $invoiceNumber = null;

    /**
     * Total con impuestos, en positivo. Negativo sólo en un abono.
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    private ?string $total = null;

    /**
     * Desglose por tipo de IVA.
     *
     * @var Collection<int, ReceivedInvoiceTaxLine>
     *
     * @ORM\OneToMany(targetEntity="ReceivedInvoiceTaxLine", mappedBy="invoice", cascade={"persist"}, orphanRemoval=true)
     * @ORM\OrderBy({"id" = "ASC"})
     */
    #[Assert\Valid]
    private Collection $taxLines;

    /**
     * Retención de IRPF, en positivo: lo que se descuenta del total porque se paga a
     * Hacienda en nombre del proveedor (un profesional, un alquiler). Null = no lleva.
     *
     * @ORM\Column(type="decimal", precision=10, scale=2, nullable=true)
     */
    #[Assert\PositiveOrZero]
    private ?string $withholding = null;

    /**
     * Tipo de la retención en %, si la factura lo dice (15, 19, 7…).
     *
     * @ORM\Column(name="withholding_rate", type="decimal", precision=5, scale=2, nullable=true)
     */
    #[Assert\Range(min: 0, max: 100)]
    private ?string $withholdingRate = null;

    /**
     * Qué se compró, en pocas palabras.
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $concept = null;

    /**
     * Cómo dice la factura que se pagó. Orientativo: quien manda es el banco.
     * @ORM\Column(name="payment_method", type="string", length=20, nullable=true)
     */
    private ?string $paymentMethod = null;

    /**
     * Su partida. Mientras está en la bandeja es la propuesta: por lo que se hizo otras
     * veces con este proveedor o, si es nuevo, por lo que propone quien la lee. Al
     * aceptarla, la elegida: la que lleva su apunte, o la que llevará el pago cuando
     * llegue por el banco. La columna conserva el nombre de cuando sólo era propuesta.
     *
     * @ORM\ManyToOne(targetEntity="BudgetCategory")
     * @ORM\JoinColumn(name="suggested_category_id", nullable=true, onDelete="SET NULL")
     */
    private ?BudgetCategory $category = null;

    /**
     * alta, media o baja: lo segura que dice estar la lectura.
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private ?string $confidence = null;

    /**
     * El apunte en que se convirtió, o el pago que la explica. Varias facturas pueden
     * ir a un mismo pago: un proveedor cobra de una vez lo de varias. SET NULL: si el
     * apunte se borra, la factura vuelve a quedar sin anotar en vez de desaparecer
     * con él.
     *
     * @ORM\ManyToOne(targetEntity="AccountEntry")
     * @ORM\JoinColumn(name="account_entry_id", nullable=true, onDelete="SET NULL")
     */
    private ?AccountEntry $accountEntry = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated_at", type="datetime")
     */
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * Una factura recién entregada: sólo el fichero, en cola para leerse.
     *
     * @param string    $fileName     Nombre en el archivo privado.
     * @param string    $originalName Nombre que traía el fichero.
     * @param string    $mimeType     Tipo de contenido real.
     * @param string    $source       Por dónde entró.
     * @param User|null $uploadedBy   Quién la entregó.
     */
    public function __construct(string $fileName, string $originalName, string $mimeType, string $source, ?User $uploadedBy)
    {
        $this->fileName = $fileName;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->source = $source;
        $this->uploadedBy = $uploadedBy;
        $this->taxLines = new ArrayCollection();
    }

    /**
     * Deja la factura leída con lo que se ha sacado de ella.
     *
     * @param ExtractedInvoice    $data     Lo leído.
     * @param string              $model    Modelo que la leyó.
     * @param BudgetCategory|null $category Partida propuesta.
     * @param \DateTimeImmutable  $now      Momento de la lectura.
     */
    public function markRead(ExtractedInvoice $data, string $model, ?BudgetCategory $category, \DateTimeImmutable $now): void
    {
        $this->documentType = $data->documentType;
        $this->invoiceDate = $data->date;
        $this->providerName = $data->providerName;
        $this->providerTaxId = $data->providerTaxId;
        $this->customerName = $data->customerName;
        $this->customerTaxId = $data->customerTaxId;
        $this->invoiceNumber = $data->invoiceNumber;
        $this->total = $data->total;
        $this->providerAddress = $data->providerAddress;
        $this->providerPostalCode = $data->providerPostalCode;
        $this->providerTown = $data->providerTown;
        $this->providerProvince = $data->providerProvince;
        $this->withholding = $data->withholding;
        $this->withholdingRate = $data->withholdingRate;
        $this->taxLines->clear();
        foreach ($data->taxLines as $line) {
            $this->addTaxLine(new ReceivedInvoiceTaxLine($line['base'], $line['rate'], $line['taxAmount']));
        }
        $this->concept = $data->concept;
        $this->paymentMethod = $data->paymentMethod;
        $this->confidence = $data->confidence;
        $this->category = $category;
        $this->readByModel = $model;
        $this->readAt = $now;
        $this->status = self::STATUS_READ;
        $this->nextAttemptAt = null;
        $this->lastError = null;
    }

    /**
     * Un intento fallido que merece repetirse más tarde (servicio saturado, sin cupo,
     * sin conexión).
     *
     * @param string             $reason Por qué falló, para enseñarlo.
     * @param \DateTimeImmutable $retry  Cuándo volver a intentarlo.
     */
    public function postpone(string $reason, \DateTimeImmutable $retry): void
    {
        $this->lastError = mb_substr($reason, 0, 255);
        $this->nextAttemptAt = $retry;
    }

    /**
     * La lectura automática se rinde con esta factura: se completará a mano.
     *
     * @param string $reason Por qué, para enseñarlo.
     */
    public function markUnreadable(string $reason): void
    {
        $this->status = self::STATUS_UNREADABLE;
        $this->lastError = mb_substr($reason, 0, 255);
        $this->nextAttemptAt = null;
    }

    /**
     * La factura ya es un apunte. Lo que se corrigió al confirmar (proveedor, número)
     * se queda también aquí, que es lo que verá la gestoría.
     *
     * @param AccountEntry  $entry    Apunte creado a partir de ella.
     * @param Provider|null $provider Proveedor reconocido por el CIF, si lo trae.
     */
    public function confirm(AccountEntry $entry, ?Provider $provider): void
    {
        $this->providerName = $entry->getProviderName() ?? $this->providerName;
        $this->invoiceNumber = $entry->getInvoiceNumber() ?? $this->invoiceNumber;
        $this->category = $entry->getCategory() ?? $this->category;
        $this->confirmAlongside($entry, $provider);
    }

    /**
     * Aceptada sin apunte: se paga por banco y el pago lo traerá el extracto. Se
     * guarda lo revisado (partida, importe, proveedor, número), que es lo que llevará
     * el apunte del pago cuando llegue ({@see paidBy()}).
     *
     * @param BudgetCategory $category      Partida que se le da.
     * @param string         $total         Lo que se paga: bases más cuotas menos retención. Un abono, en negativo.
     * @param Provider|null  $provider      Su ficha, si la tiene.
     * @param string|null    $providerName  Nombre del proveedor, como se corrigió.
     * @param string|null    $invoiceNumber Número de factura, como se corrigió.
     *
     * @throws \LogicException Si ya no está en la bandeja.
     */
    public function acceptUnpaid(BudgetCategory $category, string $total, ?Provider $provider, ?string $providerName, ?string $invoiceNumber): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException('Esta factura ya no está en la bandeja.');
        }
        $this->category = $category;
        $this->total = $total;
        $this->provider = $provider;
        $this->providerName = $providerName ?? $this->providerName;
        $this->invoiceNumber = $invoiceNumber ?? $this->invoiceNumber;
        $this->accountEntry = null;
        $this->status = self::STATUS_CONFIRMED;
    }

    /** Aceptada y esperando a que su pago llegue con el extracto del banco. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === self::STATUS_CONFIRMED && $this->accountEntry === null;
    }

    /**
     * Su pago ha llegado: el apunte del movimiento del banco. El apunte completa con
     * ella lo que le falte de lo que la factura sabe y el banco no (partida, proveedor
     * y número); lo que ya trae, lo eligió quien lo anotó y no se pisa.
     *
     * @param AccountEntry $payment Apunte del pago, del mismo importe.
     *
     * @throws \LogicException  Si no está esperando el pago.
     * @throws \DomainException Si el apunte no es de lo que se paga por ella.
     */
    public function paidBy(AccountEntry $payment): void
    {
        if (!$this->isAwaitingPayment()) {
            throw new \LogicException('Esta factura no está esperando su pago.');
        }
        if ($this->total === null || IssuedInvoice::toCents($payment->getAmount()) !== -IssuedInvoice::toCents($this->total)) {
            throw new \DomainException('El apunte no es del importe de la factura.');
        }
        $blank = static fn (?string $v): bool => $v === null || trim($v) === '';
        if ($payment->getCategory() === null) {
            $payment->setCategory($this->category);
        }
        if ($blank($payment->getInvoiceNumber())) {
            $payment->setInvoiceNumber($this->invoiceNumber);
        }
        if ($payment->getProvider() === null && $this->provider !== null) {
            $payment->setProvider($this->provider);
        } elseif ($blank($payment->getProviderName())) {
            $payment->setProviderName($this->providerName);
        }
        $this->accountEntry = $payment;
    }

    /**
     * Va en el pago de otra factura del mismo proveedor, que se cobró junto con ella.
     * A diferencia de {@see confirm()}, no toma nada del apunte: lo completó la otra, y
     * su número de factura pisaría el de ésta en el libro de facturas recibidas.
     */
    public function confirmAlongside(AccountEntry $payment, ?Provider $provider): void
    {
        $this->accountEntry = $payment;
        $this->provider = $provider;
        $this->status = self::STATUS_CONFIRMED;
    }

    /**
     * Su apunte se va a borrar. Si era un pago por banco, la factura sigue aceptada y
     * vuelve a esperar el pago: lo que se revisó sigue valiendo, sólo falta el
     * movimiento. Si era el apunte de caja o tarjeta creado al aceptarla, vuelve a la
     * bandeja ({@see reopen()}).
     *
     * @return bool True si vuelve a la bandeja; false si sigue aceptada esperando el pago.
     */
    public function losePayment(): bool
    {
        if ($this->accountEntry?->getAccount()?->isBank() === true) {
            $this->accountEntry = null;

            return false;
        }
        $this->reopen();

        return true;
    }

    /**
     * Su apunte se ha borrado: vuelve a la bandeja para anotarla otra vez (o
     * descartarla), con lo que se corrigió al confirmar. Si nunca llegó a leerse, se
     * queda como no leída, que es lo que es.
     */
    public function reopen(): void
    {
        $this->accountEntry = null;
        $this->bookedOn = null;
        $this->status = $this->readAt !== null ? self::STATUS_READ : self::STATUS_UNREADABLE;
    }

    /** Se aparta sin anotar: duplicada, equivocada o no era una factura. */
    public function discard(): void
    {
        $this->status = self::STATUS_DISCARDED;
        $this->nextAttemptAt = null;
    }

    /** Si todavía se puede confirmar o descartar. */
    public function isOpen(): bool
    {
        return \in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** La huella del fichero, o null si entró antes de guardarla. */
    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    /**
     * Guarda la huella del fichero al entrar. Sólo una vez: el fichero no cambia.
     *
     * @param string $sha256 Huella SHA-256 en hexadecimal.
     */
    public function fingerprint(string $sha256): void
    {
        $this->fileHash ??= $sha256;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /** Si el navegador la puede enseñar como imagen dentro de la página. */
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getReadByModel(): ?string
    {
        return $this->readByModel;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        return $this->invoiceDate;
    }

    /** Se corrige al revisarla, mirando el documento. */
    public function setInvoiceDate(?\DateTimeImmutable $invoiceDate): self
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }

    /** El día en que se anotó, si llegó tarde a un trimestre ya entregado; si no, null. */
    public function getBookedOn(): ?\DateTimeImmutable
    {
        return $this->bookedOn;
    }

    /**
     * La fecha que la sitúa en el paquete de la gestoría: la de anotación si llegó
     * tarde; si no, la de la factura o, sin ella, la de su apunte.
     */
    public function packageDate(): ?\DateTimeInterface
    {
        return $this->bookedOn ?? $this->invoiceDate ?? $this->accountEntry?->getDate();
    }

    /**
     * Llegó tarde: el trimestre de su fecha ya se entregó y va en el paquete del
     * trimestre de hoy. Sólo al confirmarla, que es cuando entra en un paquete.
     *
     * @param \DateTimeImmutable $on El día en que se anota.
     *
     * @throws \LogicException Si no está confirmada.
     */
    public function bookLate(\DateTimeImmutable $on): void
    {
        if ($this->status !== self::STATUS_CONFIRMED) {
            throw new \LogicException('Sólo una factura confirmada va en un paquete.');
        }
        $this->bookedOn = $on->setTime(0, 0);
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function getProviderTaxId(): ?string
    {
        return $this->providerTaxId;
    }

    /** A nombre de quién se leyó la factura. */
    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    /** CIF del cliente que se leyó en la factura. */
    public function getCustomerTaxId(): ?string
    {
        return $this->customerTaxId;
    }

    public function setProviderTaxId(?string $providerTaxId): self
    {
        $this->providerTaxId = Provider::normalizeTaxId($providerTaxId);

        return $this;
    }

    public function getProviderAddress(): ?string
    {
        return $this->providerAddress;
    }

    public function setProviderAddress(?string $providerAddress): self
    {
        $this->providerAddress = $providerAddress;

        return $this;
    }

    public function getProviderPostalCode(): ?string
    {
        return $this->providerPostalCode;
    }

    public function setProviderPostalCode(?string $providerPostalCode): self
    {
        $this->providerPostalCode = $providerPostalCode;

        return $this;
    }

    public function getProviderTown(): ?string
    {
        return $this->providerTown;
    }

    public function setProviderTown(?string $providerTown): self
    {
        $this->providerTown = $providerTown;

        return $this;
    }

    public function getProviderProvince(): ?string
    {
        return $this->providerProvince;
    }

    public function setProviderProvince(?string $providerProvince): self
    {
        $this->providerProvince = $providerProvince;

        return $this;
    }

    public function getProvider(): ?Provider
    {
        return $this->provider;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function getTotal(): ?string
    {
        return $this->total;
    }

    /** @return Collection<int, ReceivedInvoiceTaxLine> */
    public function getTaxLines(): Collection
    {
        return $this->taxLines;
    }

    public function addTaxLine(ReceivedInvoiceTaxLine $line): self
    {
        if (!$this->taxLines->contains($line)) {
            $line->setInvoice($this);
            $this->taxLines->add($line);
        }

        return $this;
    }

    public function removeTaxLine(ReceivedInvoiceTaxLine $line): self
    {
        $this->taxLines->removeElement($line);

        return $this;
    }

    public function getWithholding(): ?string
    {
        return $this->withholding;
    }

    public function setWithholding(?string $withholding): self
    {
        $this->withholding = $withholding;

        return $this;
    }

    public function getWithholdingRate(): ?string
    {
        return $this->withholdingRate;
    }

    public function setWithholdingRate(?string $withholdingRate): self
    {
        $this->withholdingRate = $withholdingRate;

        return $this;
    }

    /**
     * Lo que debería sumar la factura según su desglose: bases más cuotas, menos la
     * retención. Null si no hay desglose con que comprobarlo.
     */
    public function totalFromBreakdown(): ?float
    {
        if ($this->taxLines->isEmpty()) {
            return null;
        }

        $gross = array_sum($this->taxLines->map(static fn (ReceivedInvoiceTaxLine $l): float => $l->gross())->toArray());

        return round($gross - (float) $this->withholding, 2);
    }

    public function getConcept(): ?string
    {
        return $this->concept;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function getPaymentLabel(): string
    {
        return self::PAYMENT_LABELS[$this->paymentMethod ?? self::PAYMENT_UNKNOWN] ?? self::PAYMENT_LABELS[self::PAYMENT_UNKNOWN];
    }

    public function getCategory(): ?BudgetCategory
    {
        return $this->category;
    }

    /**
     * La partida con que va en el libro de facturas: la de su apunte, que es la que
     * cuenta en el libro, o la suya si aún espera el pago.
     */
    public function ledgerCategory(): ?BudgetCategory
    {
        return $this->accountEntry?->getCategory() ?? $this->category;
    }

    public function getConfidence(): ?string
    {
        return $this->confidence;
    }

    public function getAccountEntry(): ?AccountEntry
    {
        return $this->accountEntry;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
