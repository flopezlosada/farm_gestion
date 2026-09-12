<?php
/**
 * Genera imágenes de MARCADOR para los productos de prueba del grupo de consumo.
 *
 * No son fotos reales a propósito: se ve de un vistazo que son de prueba, y aun
 * así sirven para juzgar el diseño (recorte, tamaño, alineación de la ficha).
 */

$dir = $argv[1] ?? '/var/www/html/public/uploads/gallery/images';

$productos = [
    ['aceite',    'Aceite',    [124, 132, 56],  [201, 209, 122]],
    ['aceitunas', 'Aceitunas', [86, 104, 62],   [150, 172, 108]],
    ['garbanzo',  'Garbanzos', [178, 144, 92],  [225, 200, 156]],
    ['lenteja',   'Lentejas',  [122, 92, 70],   [176, 142, 112]],
    ['naranja',   'Naranjas',  [206, 122, 46],  [245, 179, 96]],
    ['miel',      'Miel',      [182, 130, 40],  [236, 188, 92]],
];

foreach ($productos as [$slug, $label, $fondo, $forma]) {
    $size = 800;
    $img = imagecreatetruecolor($size, $size);

    $bg = imagecolorallocate($img, ...$fondo);
    imagefilledrectangle($img, 0, 0, $size, $size, $bg);

    // Forma central: un círculo en tono claro, que da profundidad sin pretender
    // ser una foto.
    $fg = imagecolorallocate($img, ...$forma);
    imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2) - 40, 420, 420, $fg);

    $sombra = imagecolorallocatealpha($img, 0, 0, 0, 90);
    imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2) + 190, 380, 70, $sombra);

    $blanco = imagecolorallocate($img, 255, 255, 255);
    $fuente = 5;
    $ancho = imagefontwidth($fuente) * strlen($label);
    imagestring($img, $fuente, (int) (($size - $ancho) / 2), $size - 120, $label, $blanco);
    imagestring($img, 3, (int) (($size - imagefontwidth(3) * 22) / 2), $size - 90, 'foto de prueba', $blanco);

    $nombre = sprintf('%s/prueba-gc-%s.png', $dir, $slug);
    imagepng($img, $nombre);
    imagedestroy($img);

    echo basename($nombre), "\n";
}
