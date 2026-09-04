/*
 * csa-fold — normaliza texto para comparar búsquedas escritas a mano.
 *
 * Vive aparte porque lo usan los dos controles que buscan sobre nombres de
 * personas (csa-dropdown y csa-check-filter) y la lista de casos raros sólo
 * crece: si mañana hay que tratar la "ñ" o los guiones de un apellido
 * compuesto, se arregla en un sitio y lo heredan los dos.
 */
'use strict';

/* Sin acentos y en minúsculas, para que "agustin" encuentre a "Agustín" y
   "jose" a "JOSÉ" — en la base de datos muchos nombres están en mayúsculas.
   El rango ̀-ͯ son las marcas diacríticas que deja NFD; se usa en vez
   de \p{Diacritic} porque no exige soporte de propiedades Unicode. */
module.exports = function fold(text) {
    return String(text)
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
};
