<?php

namespace App\Service\Accounting\OfficialNotice;

use App\Entity\OfficialNotice;
use App\Repository\OfficialNoticeRepository;
use DirectoryTree\ImapEngine\FileMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Un correo del buzón → si es el aviso de una notificación oficial, el aviso
 * urgente y la tarea en la portada de contabilidad.
 *
 * El remitente sólo dice quién DICE ser; lo que prueba que el correo es del
 * organismo es la firma DKIM de su dominio, tal como la certifica el servidor de
 * nuestro buzón en la cabecera Authentication-Results más alta (las de debajo las
 * pudo escribir cualquiera, {@see AuthenticationResults}). Sin esa firma no se avisa:
 * se deja en el log como sospechoso, porque un aviso falso que mande a la gente a
 * un enlace es justo el ataque que hay que evitar.
 *
 * Del mismo correo (mismo Message-ID) se avisa una vez.
 *
 * Como {@see \App\Service\Accounting\Invoice\MailedInvoiceIntake}, no sabe nada de
 * IMAP: recibe el correo en crudo.
 */
class OfficialNoticeIntake
{
    /** Cabecera con lo que el servidor comprobó del correo. */
    private const AUTH_RESULTS = 'Authentication-Results';

    public function __construct(
        private readonly OfficialSenders $senders,
        private readonly OfficialNoticeRepository $notices,
        private readonly OfficialNoticeAlert $alert,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Mira si el correo es el aviso de una notificación oficial y, si lo es y viene
     * firmado por su organismo, lo guarda y avisa.
     *
     * @param string                  $rawMessage Correo completo (cabeceras y cuerpo), tal cual llega.
     * @param \DateTimeImmutable|null $now        Ahora (para probarlo); null = el reloj.
     *
     * @return NoticeOutcome Qué ha pasado con él.
     */
    public function take(string $rawMessage, ?\DateTimeImmutable $now = null): NoticeOutcome
    {
        $message = new FileMessage($rawMessage);
        $from = $message->from()?->email();
        $sender = $this->senders->find($from);
        if ($sender === null) {
            return NoticeOutcome::NotOfficial;
        }

        $signedBy = AuthenticationResults::dkimPassDomains($message->header(self::AUTH_RESULTS)?->getRawValue());
        if (array_filter($signedBy, $sender->signs(...)) === []) {
            $this->logger->warning('Buzón: correo que dice venir de un organismo oficial sin su firma; no se avisa', [
                'from' => $from,
                'agency' => $sender->agency,
                'dkim_pass' => $signedBy,
                'expected' => $sender->dkimDomains,
            ]);

            return NoticeOutcome::Suspicious;
        }

        $key = OfficialNotice::keyFor($message->messageId(), $rawMessage);
        if ($this->notices->hasKey($key)) {
            return NoticeOutcome::Repeated;
        }

        $now ??= new \DateTimeImmutable();
        $notice = new OfficialNotice($key, $sender->agency, $sender->address, self::sentAt($message, $now));
        $this->em->persist($notice);
        // Guardado antes de avisar: si el aviso falla a medias, la próxima pasada lo
        // encuentra y no vuelve a avisar, y la tarea ya está en la portada.
        $this->em->flush();
        $this->alert->send($notice);
        $this->logger->info('Buzón: aviso de notificación oficial', ['agency' => $sender->agency, 'id' => $notice->getId()]);

        return NoticeOutcome::Alerted;
    }

    /**
     * Cuándo se mandó: la cabecera Date, que no puede ser posterior a ahora (un reloj
     * mal puesto en origen no debe dejar la tarea con fecha futura).
     *
     * @param FileMessage        $message El correo.
     * @param \DateTimeImmutable $now     Ahora.
     *
     * @return \DateTimeImmutable La fecha del correo, o ahora si no la trae o no se entiende.
     */
    private static function sentAt(FileMessage $message, \DateTimeImmutable $now): \DateTimeImmutable
    {
        try {
            $date = $message->date();
        } catch (\Throwable) {
            $date = null;
        }
        if ($date === null) {
            return $now;
        }
        $sent = \DateTimeImmutable::createFromInterface($date)->setTimezone($now->getTimezone());

        return min($sent, $now);
    }
}
