<?php

namespace App\Controller;

use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use App\Entity\User;
use App\Http\BackToAgenda;
use App\Http\QueryId;
use App\Repository\AccountEntryRepository;
use App\Repository\BankStatementLineRepository;
use App\Repository\BankStatementRepository;
use App\Service\Accounting\Norma43\BankLineBooker;
use App\Service\Accounting\Norma43\BankLineMatcher;
use App\Service\Accounting\Norma43\BankStatementImporter;
use App\Service\Accounting\Norma43\BankStatementReader;
use App\Service\Accounting\Norma43\Norma43Exception;
use App\Service\Accounting\Norma43\StatementReviewer;
use App\Service\Accounting\Suggestion\CategorySuggester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los extractos del banco, en Norma 43 o en la hoja de Excel de la banca online: se suben, se guardan sus movimientos y se cuadran
 * con el libro. El banco es la fuente de los movimientos de una cuenta de banco; aquí se
 * ve qué movimientos le faltan al libro y qué apuntes no trae el banco.
 *
 * Se sube eligiendo primero la cuenta y luego el fichero. Al subirlo, lo que el libro ya tenía se liga a su apunte y lo que no deja duda lo
 * anota la app sola ({@see BankLineBooker}); el resto se anota desde el alta de
 * siempre (prerrellenada) o se liga al apunte que ya lo tenía. Nada se borra.
 */
#[Route('/gestion/accounting/bank-statements')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class BankStatementController extends AbstractController
{
    /** Tamaño máximo del fichero: un extracto de un mes son unas decenas de KB. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Los extractos subidos, los más recientes primero, y el sitio para subir otro. */
    #[Route('', name: 'accounting_bank_statements', methods: ['GET'])]
    public function index(BankStatementRepository $statements, BankStatementImporter $importer): Response
    {
        return $this->render('accounting/bank_statements.html.twig', [
            'statements' => $statements->findLatestFirst(),
            'unlinked' => $statements->unlinkedCounts(),
            'accounts' => $importer->uploadableAccounts(),
        ]);
    }

    /**
     * Subir un extracto a la cuenta que se eligió antes del fichero. Lo que comprueba
     * que el fichero es de esa cuenta lo hace {@see BankStatementImporter::import()}.
     */
    #[Route('/upload', name: 'accounting_bank_statement_upload', methods: ['POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function upload(Request $request, BankStatementReader $reader, BankStatementImporter $importer): Response
    {
        $back = $this->redirectToRoute('accounting_bank_statements');
        if (!$this->isCsrfTokenValid('bank_statement_upload', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }
        $accountId = QueryId::from($request->request, 'account');
        $account = array_values(array_filter($importer->uploadableAccounts(), static fn (FinancialAccount $a): bool => $a->getId() === $accountId))[0] ?? null;
        [$raw, $fileName, $problem] = self::uploaded($request);
        $problem ??= $account === null ? 'Elige primero de qué cuenta es el extracto.' : null;
        if ($problem !== null) {
            $this->addFlash('danger', $problem);

            return $back;
        }

        try {
            $import = $importer->import($reader->read($raw), $account, $fileName, $this->currentUser());
        } catch (Norma43Exception $e) {
            $this->addFlash('danger', 'No se ha guardado nada. '.$e->getMessage());

            return $back;
        }

        $this->addFlash('success', sprintf(
            'Extracto guardado: %d %s nuevos%s. %s',
            $import->added,
            $import->added === 1 ? 'movimiento' : 'movimientos',
            $import->alreadyThere > 0 ? sprintf(' (%d ya estaban de otro extracto)', $import->alreadyThere) : '',
            self::crossed($import->linked, $import->booked),
        ));
        foreach ($import->warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('accounting_bank_statement_show', ['id' => $import->statements[0]->getId()]);
    }

    /** El cuadre de un extracto con el libro, como está hoy. */
    #[Route('/{id}', name: 'accounting_bank_statement_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(BankStatement $statement, StatementReviewer $reviewer, CategorySuggester $suggester): Response
    {
        $review = $reviewer->review($statement);

        return $this->render('accounting/bank_statement_show.html.twig', [
            'review' => $review,
            // La partida que se propondrá al anotar cada movimiento, para verlo de un vistazo.
            'suggestions' => $suggester->forLines(array_column($review->unlinked, 'line')),
        ]);
    }

    /**
     * Volver a cruzar un extracto ya subido con el libro, sin subirlo otra vez: liga lo
     * que se anotó después y lo que el cruce de la subida no sabía juntar.
     */
    #[Route('/{id}/relink', name: 'accounting_bank_statement_relink', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function relink(Request $request, BankStatement $statement, BankStatementImporter $importer): Response
    {
        $back = $this->redirectToRoute('accounting_bank_statement_show', ['id' => $statement->getId(), '_fragment' => 'sin-apunte']);
        if (!$this->isCsrfTokenValid('bank_statement_relink_'.$statement->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }
        [$linked, $booked] = $importer->relink($statement, $this->currentUser());
        $this->addFlash($linked + $booked > 0 ? 'success' : 'info', $linked + $booked > 0
            ? self::crossed($linked, $booked)
            : 'Nada nuevo: lo que queda necesita que lo mires tú.');

        return $back;
    }

    /** Lo que hizo el cruce con el libro, en una o dos frases. */
    private static function crossed(int $linked, int $booked): string
    {
        $said = array_filter([
            $linked > 0 ? sprintf('%d %s al apunte que ya %s.', $linked, $linked === 1 ? 'ligado' : 'ligados', $linked === 1 ? 'lo tenía' : 'los tenía') : null,
            $booked > 0 ? sprintf('%d %s por la app: los tienes en «Anotados solos».', $booked, $booked === 1 ? 'anotado solo' : 'anotados solos') : null,
        ]);

        return $said !== [] ? implode(' ', $said) : 'Nada que ligar ni anotar solo.';
    }

    /**
     * Confirmar que un apunte propuesto anota un movimiento del banco. Se vuelve a
     * comprobar todo: la misma cuenta, el mismo importe exacto, a una semana como mucho,
     * y que ninguno de los dos esté ya ligado. El apunte no cambia.
     */
    #[Route('/lines/{id}/link', name: 'accounting_bank_line_link', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function link(Request $request, BankStatementLine $line, AccountEntryRepository $entries, BankStatementLineRepository $lines, EntityManagerInterface $em): Response
    {
        $statementId = QueryId::from($request->request, 'statement');
        $back = match (true) {
            BackToAgenda::requested($request) => $this->redirectToRoute(BackToAgenda::ROUTE),
            $statementId !== null => $this->redirectToRoute('accounting_bank_statement_show', ['id' => $statementId]),
            default => $this->redirectToRoute('accounting_bank_statements'),
        };
        if (!$this->isCsrfTokenValid('bank_line_link_'.$line->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }
        $entryId = QueryId::from($request->request, 'entry');
        $entry = $entryId !== null ? $entries->find($entryId) : null;
        if ($entry === null || !BankLineMatcher::isCandidate($line, $entry) || $lines->isLinked($entry)) {
            $this->addFlash('warning', 'Ese apunte ya no puede ser este movimiento: o se ligó a otro, o cambió de cuenta, de importe o de fecha.');

            return $back;
        }
        $line->linkTo($entry);
        $em->flush();
        $this->addFlash('success', sprintf('Ligado: el movimiento del %s es el apunte «%s».', $line->getOperationDate()->format('d/m/Y'), $entry->getConcept()));

        return $back;
    }

    /**
     * El contenido y el nombre del fichero subido. El tercer valor es el problema, si lo hay.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function uploaded(Request $request): array
    {
        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            return ['', '', 'Elige el fichero del extracto (Norma 43 o la hoja de Excel del banco).'];
        }
        if (!$upload->isValid() || $upload->getSize() > self::MAX_BYTES) {
            return ['', '', 'El fichero no ha llegado bien o pasa de 2 MB: un extracto de un mes ocupa mucho menos.'];
        }

        return [(string) file_get_contents($upload->getPathname()), $upload->getClientOriginalName(), null];
    }

    /** Quien sube el extracto, para dejar rastro. */
    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
