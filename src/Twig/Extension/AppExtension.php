<?php
/**
 *
 * User: paco
 * Date: 2/09/14
 * Time: 17:05
 */

namespace App\Twig\Extension;


use App\Custom\WeekOfMonth;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Twig\TwigFilter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{

    protected $manager;

    public function __construct(EntityManagerInterface $manager)
    {
        $this->manager = $manager;
    }


    public function getName()
    {
        return 'app_extension';
    }

    public function getFilters()
    {
        return array(
            new TwigFilter('delete_image', array($this, 'deleteImage')),
            new TwigFilter('get_class', array($this, 'getClassName')),
            new TwigFilter('parseContentImageResponsive', array($this, 'parseContentImageResponsive')),
            new TwigFilter('glossary', array($this, 'glossary')),
            new TwigFilter('month_names', array($this, 'month_names')),
            new TwigFilter('excerpt', array($this, 'excerpt')),
            new TwigFilter('time_ago', array($this, 'timeAgo')),
        );
    }

    /**
     * Fecha humana en español: "hoy" / "ayer" / "hace 3 días" /
     * "hace 2 semanas" / "hace 4 meses". Para fechas > 1 año vuelve
     * al formato absoluto ("12 mayo 2024") para no decir "hace 14 meses",
     * que ya no aporta — a partir de cierta antigüedad lo que importa es
     * el año concreto.
     */
    public function timeAgo(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 0) {
            return $date->format('d/m/Y');
        }
        if ($diff < 60) {
            return 'hace un momento';
        }
        $minutes = intdiv($diff, 60);
        if ($minutes < 60) {
            return $minutes === 1 ? 'hace 1 minuto' : "hace $minutes minutos";
        }
        $hours = intdiv($diff, 3600);
        if ($hours < 24) {
            return $hours === 1 ? 'hace 1 hora' : "hace $hours horas";
        }
        $days = intdiv($diff, 86400);
        if ($days === 0) {
            return 'hoy';
        }
        if ($days === 1) {
            return 'ayer';
        }
        if ($days < 7) {
            return "hace $days días";
        }
        if ($days < 30) {
            $weeks = intdiv($days, 7);
            return $weeks === 1 ? 'hace 1 semana' : "hace $weeks semanas";
        }
        if ($days < 365) {
            $months = intdiv($days, 30);
            return $months === 1 ? 'hace 1 mes' : "hace $months meses";
        }
        $meses_ES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $date->format('j') . ' ' . $meses_ES[(int)$date->format('n') - 1] . ' ' . $date->format('Y');
    }

    /**
     * Genera un excerpt limpio a partir del HTML de un post:
     *   - retira los shortcodes [[insert_media_*]] que el editor incrusta
     *   - elimina tags HTML
     *   - decodifica entidades (&iacute; → í, &iexcl; → ¡)
     *   - colapsa whitespace
     *   - trunca a $length caracteres con elipsis si excede
     *
     * @param string|null $html    Contenido HTML del post
     * @param int         $length  Longitud máxima en caracteres
     * @return string
     */
    public function excerpt(?string $html, int $length = 160): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $clean = preg_replace('/\[\[insert_media_\w+\]\]/i', '', $html);
        $clean = strip_tags($clean);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        if (mb_strlen($clean) > $length) {
            return mb_substr($clean, 0, $length) . '…';
        }
        return $clean;
    }

    public function getFunctions()
    {
        return array(
            new TwigFunction('has_image', array($this, 'hasImage')),
            new TwigFunction('find_image', array($this, 'findImage')),
            new TwigFunction('searched', array($this, 'searched')),
            new TwigFunction('truncate_key', array($this, 'truncate_key')),
            new TwigFunction('strip_allow', array($this, 'strip_allow')),
            new TwigFunction('select_glossary_letters', array($this, 'select_glossary_letters')),
            new TwigFunction('insert_snippets', array($this, 'insertSnippets')),
            new TwigFunction('getDateFromWeek', array($this, 'getDateFromWeek')),
            new TwigFunction('getWeekOfMonth', array($this, 'getWeekOfMonth')),
            new TwigFunction('getDayOfWeekOfMonth', array($this, 'getDayOfWeekOfMonth')),
            new TwigFunction('randColor', array($this, 'rand_color')),
        );
    }

    public function getWeekOfMonth($date)
    {
        return WeekOfMonth::numberOfWeek($date);
    }


    public function getDayOfWeekOfMonth($date)
    {
        return WeekOfMonth::dayOfWeekInMonth($date);
    }

    public function insertSnippets($object, $field)
    {
        preg_match_all('/\[\[insert_media_\w*\]\]/', $field, $abstract);
        $array_medias = $abstract[0];
        $media_type = [];

        foreach ($array_medias as $media) {
            $media = str_ireplace("[[insert_media_", "", $media);
            $media = str_ireplace("]]", "", $media);
            $media_type[] = preg_split('/_/', $media);
        }


        $text = preg_split('/\[\[insert_media_\w*\]\]/', $field);

        $result = array();
        for ($i = 0; $i < count($text); $i++) {
            $result[] = $text[$i];
            if ($i < count($media_type)) {
                $result[] = $media_type[$i];
            }
        }

        return $result;
    }


    public function hasImage($media)
    {
        if ($media->hasImage()) {
            return true;
        }

        return false;
    }

    public function findImage($post, $class = null)
    {

        preg_match_all('/<img[^>]+>/i', $post->getContentParsed(), $abstract);

        if (count($abstract) > 0) {
            foreach ($abstract as $image) {
                if (count($image) > 0) {
                    return $this->parseImage($image[0], $class);
                }
            }
        }
        return "has no image";

    }

    public function parseImage($image, $class = null)
    {
        $parse_image = str_replace('src="images/', 'src="/images/', $image);
        $parse_image = preg_replace('/height=\"\d*\"\s/', "", $parse_image);

        $parse_image = preg_replace('/mce_style=\"[^"]*\"\s/', "", $parse_image);
        $parse_image = preg_replace('/mce_src=\"[^"]*\"\s/', "", $parse_image);
        $parse_image = preg_replace('/style=\"[^"]*\"\s/', "", $parse_image);
        $parse_image = preg_replace('/width=\"\d*\"/', "", $parse_image);

        $parse_image = preg_replace('/class=\"\d*\"/', "", $parse_image);

        if ($class) {

            $parse_image = preg_replace('/>/', 'class="' . $class . '" >', $parse_image);
        }

        return $parse_image;
    }

    public function deleteImage($value)
    {
        return preg_replace('/<img[^>]+>/i', "", $value);
    }


    public function getClassName($object)
    {
        return get_class($object);
    }

    public function parseContentImageResponsive($content)
    {
        return preg_replace('/<img /', '/<img class="img-responsive" /', $content);
    }

    public function searched($text, $key)
    {
        $text_replace = str_ireplace($key, "<mark>" . $key . "</mark>", $text);

        return $text_replace;
    }

    public function truncate_key($text, $key)
    {
        $position = strpos($text, $key);
        return substr($text, ($position - 100), 200);
    }

    public function strip_allow($text, $keys)
    {
        return strip_tags($text, $keys);
    }

    public function glossary($text)
    {
        $em = $this->manager;
        $words = $em->getRepository(\App\Entity\Glossary::class)->getAllGlossaryWords();
        $keys = array();
        foreach ($words as $word) {
            $keys[] = $word['name'];
        }
        for ($i = 0; $i < count($keys); $i++)
            $text = preg_replace("/(" . trim($keys[$i]) . ")/i", "<a target='_blank' href='show_glossary_word/" . $this->getGlossaryWordId($keys[$i]) . "'
            id='glossary_id_" . $this->getGlossaryWordId($keys[$i]) . "' >\\1</a>", $text);

        return $text;
    }

    public function getGlossaryWordId($word)
    {
        $em = $this->manager;

        return $em->getRepository(\App\Entity\Glossary::class)->getId($word);
    }

    public function select_glossary_letters($letter)
    {
        $em = $this->manager;
        $words = $em->getRepository(\App\Entity\Glossary::class)->getWordsFromInitLetter($letter);

        return $words;
    }

    public function getDateFromWeek($year, $week)
    {

        $monday = date('d F', strtotime($year . "W" . str_pad($week, 2, "0", STR_PAD_LEFT)));
        $friday = strtotime("+4 day", strtotime($monday));

        return $this->fechaCastellano(date("d F", $friday));

    }

    public function fechaCastellano($fecha)
    {

        $numeroDia = date('d', strtotime($fecha));
        $mes = date('F', strtotime($fecha));
        $meses_ES = array("Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre");
        $meses_EN = array("January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December");
        $nombreMes = str_replace($meses_EN, $meses_ES, $mes);
        return $numeroDia . " de " . $nombreMes;
    }

    public function mesCastellano($mes)
    {
        $meses_ES = array("Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre");
        $meses_EN = array("January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December");

        return $nombreMes = str_replace($meses_EN, $meses_ES, $mes);

    }

    public function month_names($month_number)
    {
        $fecha = \DateTime::createFromFormat('!m', $month_number);
        $mes = $fecha->format('F');

        return $this->mesCastellano($mes);
    }

    public function rand_color($limit = false)
    {
        if ($limit) {
            $hex = '#';

//Create a loop.
            foreach(array('r', 'g', 'b') as $color){
                //Random number between 0 and 255.
                $val = mt_rand(150, 255);
                //Convert the random number into a Hex value.
                $dechex = dechex($val);
                //Pad with a 0 if length is less than 2.
                if(strlen($dechex) < 2){
                    $dechex = "0" . $dechex;
                }
                //Concatenate
                $hex .= $dechex;
            }

            return $hex;
        }

        return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }
}