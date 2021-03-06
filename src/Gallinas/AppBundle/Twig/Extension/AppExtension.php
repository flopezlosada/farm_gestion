<?php
/**
 *
 * User: paco
 * Date: 2/09/14
 * Time: 17:05
 */

namespace Gallinas\AppBundle\Twig\Extension;


use Symfony\Bridge\Doctrine\RegistryInterface;

class AppExtension extends \Twig_Extension
{

    protected $doctrine;

    public function __construct(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;
    }


    public function getName()
    {
        return 'app_extension';
    }

    public function getFilters()
    {
        return array(
            'delete_image' => new \Twig_Filter_Method($this, 'deleteImage'),
            'get_class' => new \Twig_Filter_Method($this, 'getClassName'),
            'parseContentImageResponsive' => new \Twig_Filter_Method($this, 'parseContentImageResponsive'),
            'glossary' => new \Twig_Filter_Method($this, 'glossary'),
            'month_names' => new \Twig_Filter_Method($this, 'month_names'),
        );
    }

    public function getFunctions()
    {
        return array(
            'has_image' => new \Twig_Function_Method($this, 'hasImage'),
            'find_image' => new \Twig_Function_Method($this, 'findImage'),
            'searched' => new \Twig_Function_Method($this, 'searched'),
            'truncate_key' => new \Twig_Function_Method($this, 'truncate_key'),
            'strip_allow' => new \Twig_Function_Method($this, 'strip_allow'),
            'select_glossary_letters' => new \Twig_Function_Method($this, 'select_glossary_letters'),
            'insert_snippets' => new \Twig_Function_Method($this, 'insertSnippets'),
            'getDateFromWeek' => new \Twig_Function_Method($this, 'getDateFromWeek'),
        );
    }

    public function insertSnippets($object, $field)
    {
        preg_match_all('/\[\[insert_media_\w*\]\]/', $field, $abstract);
        $array_medias = $abstract[0];

        foreach ($array_medias as $media) {
            $media = str_ireplace("[[insert_media_", "", $media);
            $media = str_ireplace("]]", "", $media);
            $media_type[] = preg_split('/_/', $media);
        }


        $text = preg_split('/\[\[insert_media_\w*\]\]/', $field);

        $result = array();
        for ($i = 0; $i < count($text); $i++) {
            $result[] = $text[$i];
            if ($i < @count($media_type)) {
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
        $em = $this->doctrine->getManager();
        $words = $em->getRepository("AppBundle:Glossary")->getAllGlossaryWords();
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
        $em = $this->doctrine->getManager();

        return $em->getRepository("AppBundle:Glossary")->getId($word);
    }

    public function select_glossary_letters($letter)
    {
        $em = $this->doctrine->getManager();
        $words = $em->getRepository("AppBundle:Glossary")->getWordsFromInitLetter($letter);

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
}