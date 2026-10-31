<?php

namespace App\Service\Accounting\Norma43;

/**
 * Lee un extracto del banco venga como venga: el fichero Norma 43 (cuaderno 43) o la
 * hoja de movimientos en Excel que exporta la banca online. Los dos acaban en las
 * mismas piezas, así que lo que viene después (guardar, cuadrar, ligar) es uno solo.
 */
class BankStatementReader
{
    public function __construct(
        private readonly Norma43Reader $norma43,
        private readonly BankSpreadsheetReader $spreadsheet,
    ) {
    }

    /**
     * Lee el fichero según su contenido, no según su nombre.
     *
     * @param string $raw Contenido del fichero subido.
     *
     * @throws Norma43Exception Si no es un extracto válido en ninguno de los dos formatos.
     */
    public function read(string $raw): Norma43File
    {
        return BankSpreadsheetReader::looksLikeSpreadsheet($raw)
            ? $this->spreadsheet->read($raw)
            : $this->norma43->read($raw);
    }
}
