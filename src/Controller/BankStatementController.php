<?php

namespace App\Controller;

use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\User;
use App\Http\QueryId;
use App\Repository\AccountEntryRepository;
use App\Repository\BankStatementLineRepository;
use App\Repository\BankStatementRepository;
use App\Service\Accounting\Norma43\BankLineMatcher;
use App\Service\Accounting\Norma43\BankStatementImporter;
use App\Service\Accounting\Norma43\Norma43Exception;
use App\Service\Accounting\Norma43\Norma43Reader;
use App\Service\Accounting\Norma43\StatementReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los extractos del banco en Norma 43: se suben, se guardan sus movimientos y se cuadran
 * con el libro. El banco es la fuente de los movimientos de una cuenta de banco; aquí se
 * ve qué movimientos le faltan al libro y qué apuntes no trae el banco.
 *
 * Nada de aquí crea ni borra apuntes por su cuenta: se anotan desde el alta de siempre
 * (prerrellenada) o se ligan al apunte que ya los tenía.
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
    public function index(BankStatementRepository $statements): Response
    {
        return $this->render('accounting/bank_statements.html.twig', [
            'statements' => $statements->findLatestFirst(),
            'unlinked' => $statements->unlinkedCounts(),
        ]);
    }

    /**
     * Subir un extracto. Si alguna cuenta del fichero no casa por IBAN con ninguna del
     * libro, se pide elegirla y el fichero viaja en el mismo formulario (se vuelve a leer
     * y validar entero al enviarlo: no se confía en nada de lo que vuelve).
     */
    #[Route('/upload', name: 'accounting_bank_statement_upload', methods: ['POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function upload(Request $request, Norma43Reader $reader, BankStatementImporter $importer): Response
    {
        $back = $this->redirectToRoute('accounting_bank_statements');
        if (!$this->isCsrfTokenValid('bank_statement_upload', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }
        [$raw, $fileName, $problem] = self::uploaded($request);
        if ($problem !== null) {
            $this->addFlash('danger', $problem);

            return $back;
        }

        try {
            $file = $reader->read($raw);
            $chosen = array_map('intval', array_filter($request->request->all('account'), 'is_numeric'));
            ['resolved' => $resolved, 'unresolved' => $unresolved] = $importer->resolve($file, $chosen);
            if ($unresolved !== []) {
                $choosable = $importer->choosableAccounts();
                if ($choosable === []) {
                    throw new Norma43Exception(sprintf('Ninguna cuenta del libro tiene el IBAN de la %s, y no hay cuentas de banco sin IBAN para elegir. Pon el IBAN en la ficha de su cuenta (Ajustes → Cuentas) y vuelve a subirlo.', $unresolved[0]->label()));
                }

                return $this->render('accounting/bank_statement_choose.html.twig', [
                    'unresolved' => $unresolved,
                    'accounts' => $choosable,
                    'content' => base64_encode($raw),
                    'fileName' => $fileName,
                    'chosen' => $chosen,
                ]);
            }
            $import = $importer->import($file, $resolved, $fileName, $this->currentUser());
        } catch (Norma43Exception $e) {
            $this->addFlash('danger', 'No se ha guardado nada. '.$e->getMessage());

            return $back;
        }

        $this->addFlash('success', sprintf(
            'Extracto guardado: %d %s nuevos%s, %d ligados solos a su apunte.',
            $import->added,
            $import->added === 1 ? 'movimiento' : 'movimientos',
            $import->alreadyThere > 0 ? sprintf(' (%d ya estaban de otro extracto)', $import->alreadyThere) : '',
            $import->linked,
        ));
        foreach ($import->warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        return \count($import->statements) === 1
            ? $this->redirectToRoute('accounting_bank_statement_show', ['id' => $import->statements[0]->getId()])
            : $back;
    }

    /** El cuadre de un extracto con el libro, como está hoy. */
    #[Route('/{id}', name: 'accounting_bank_statement_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(BankStatement $statement, StatementReviewer $reviewer): Response
    {
        return $this->render('accounting/bank_statement_show.html.twig', [
            'review' => $reviewer->review($statement),
        ]);
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
        $back = $statementId !== null
            ? $this->redirectToRoute('accounting_bank_statement_show', ['id' => $statementId])
            : $this->redirectToRoute('accounting_bank_statements');
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
     * El contenido y el nombre del fichero: de la subida, o del formulario de elegir
     * cuenta, que lo trae en base64. El tercer valor es el problema, si lo hay.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function uploaded(Request $request): array
    {
        $upload = $request->files->get('file');
        if ($upload instanceof UploadedFile) {
            if (!$upload->isValid() || $upload->getSize() > self::MAX_BYTES) {
                return ['', '', 'El fichero no ha llegado bien o pasa de 2 MB: un extracto de un mes ocupa mucho menos.'];
            }

            return [(string) file_get_contents($upload->getPathname()), $upload->getClientOriginalName(), null];
        }
        $content = (string) $request->request->get('content');
        $raw = $content !== '' ? base64_decode($content, true) : false;
        if ($raw === false || $raw === '' || \strlen($raw) > self::MAX_BYTES) {
            return ['', '', 'Elige el fichero del extracto (Norma 43 / cuaderno 43).'];
        }

        return [$raw, mb_substr((string) $request->request->get('file_name'), 0, 255), null];
    }

    /** Quien sube el extracto, para dejar rastro. */
    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
