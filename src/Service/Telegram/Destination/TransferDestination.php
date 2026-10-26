<?php

namespace App\Service\Telegram\Destination;

use App\Entity\PendingTransfer;
use App\Entity\User;
use App\Repository\FinancialAccountRepository;
use App\Service\Accounting\DictatedTransferReader;
use App\Service\Ai\GeminiException;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramInput;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dinero que pasa de una cuenta o caja propia a otra, contado por voz o por escrito
 * («he pasado 50 € de la caja del local a la de la huerta») → un traspaso POR CONFIRMAR.
 *
 * El bot no toca el libro: lo entendido de una nota de voz puede estar mal, y se revisa
 * y confirma en la web. Deshacer lo tira mientras siga sin confirmar; confirmado, ya es
 * un traspaso del libro y se borra desde la web.
 */
class TransferDestination implements TelegramDestination
{
    /**
     * @param DictatedTransferReader     $reader   Quien entiende lo dicho.
     * @param FinancialAccountRepository $accounts Cuentas entre las que elegir.
     * @param EntityManagerInterface     $em       Para guardar.
     * @param AppSettings                $settings Para saber si contabilidad está encendida.
     * @param LoggerInterface            $logger   Rastro de lo que falla.
     */
    public function __construct(
        private readonly DictatedTransferReader $reader,
        private readonly FinancialAccountRepository $accounts,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return 'transfer';
    }

    public function section(): string
    {
        return 'contabilidad';
    }

    public function label(): string
    {
        return 'Traspaso entre cajas';
    }

    public function description(): string
    {
        return 'Dinero que ha pasado de una caja o cuenta de la asociación a otra (de la caja del local a la caja de la huerta, del banco a la caja…), dicho por voz o escrito: cuánto y de dónde a dónde. No es un gasto, ni un cobro, ni una factura.';
    }

    public function accepts(TelegramInput $input, User $user): bool
    {
        return Dictation::isDictated($input)
            && $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD)
            && $this->reader->isAvailable();
    }

    public function help(User $user): ?string
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD) || !$this->reader->isAvailable()) {
            return null;
        }

        return 'Traspasos entre cajas: dime por voz o por escrito cuánto y de dónde a dónde («he pasado 50 € de la caja del local a la de la huerta») y lo dejo para confirmarlo en la web.';
    }

    public function commit(TelegramInput $input, User $user): TelegramOutcome
    {
        try {
            $transfer = $this->reader->read(
                Dictation::parts($input, $this->logger),
                $this->accounts->findActive(),
                new \DateTimeImmutable('today'),
                $user,
            );
        } catch (GeminiException $e) {
            $this->logger->warning('Telegram: no se pudo entender un traspaso', ['error' => $e->getMessage()]);
            throw new TelegramRejected('Ahora mismo no puedo entenderlo. Mándamelo otra vez en un rato, o anota el traspaso en la web.');
        }

        if ($transfer->getAmount() === null && $transfer->getFrom() === null && $transfer->getTo() === null) {
            throw new TelegramRejected('No he entendido ni cuánto ni de dónde a dónde. Dímelo así: «he pasado 50 € de la caja del local a la de la huerta».');
        }
        $this->em->persist($transfer);
        $this->em->flush();

        return new TelegramOutcome((string) $transfer->getId(), self::summarize($transfer));
    }

    public function undo(string $ref): ?string
    {
        // Con bloqueo: si en la web lo están confirmando ahora mismo, se espera a que
        // acabe y se ve ya en el libro, en vez de tirarlo con los apuntes hechos.
        $transfer = $this->em->find(PendingTransfer::class, (int) $ref, LockMode::PESSIMISTIC_WRITE);
        if ($transfer === null) {
            return null;
        }
        if ($transfer->isConfirmed()) {
            return 'Ese traspaso ya está anotado en el libro. Si no era, bórralo desde la web, en Contabilidad → Movimientos.';
        }
        $this->em->remove($transfer);
        $this->em->flush();

        return null;
    }

    /**
     * Lo entendido, en una frase: cuánto, de dónde a dónde, qué día y qué falta.
     *
     * @param PendingTransfer $transfer Recién guardado.
     */
    public static function summarize(PendingTransfer $transfer): string
    {
        $missing = array_keys(array_filter([
            'cuánto' => $transfer->getAmount() === null,
            'de dónde sale' => $transfer->getFrom() === null,
            'adónde va' => $transfer->getTo() === null,
        ]));

        $what = sprintf(
            'Traspaso de %s, de %s a %s, del %s.',
            $transfer->getAmount() !== null ? number_format((float) $transfer->getAmount(), 2, ',', '.') . ' €' : '¿?',
            $transfer->getFrom()?->getName() ?? '¿?',
            $transfer->getTo()?->getName() ?? '¿?',
            $transfer->getDate()->format('d/m/Y'),
        );
        if ($missing !== []) {
            $what .= sprintf(' No he entendido %s: complétalo al confirmarlo.', self::joined($missing));
        }

        return $what . ' Aún no está en el libro: confírmalo en la web, en Contabilidad → Traspasos por confirmar.';
    }

    /**
     * «a», «a y b», «a, b y c».
     *
     * @param list<string> $items
     */
    private static function joined(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . ' y ' . $last;
    }
}
