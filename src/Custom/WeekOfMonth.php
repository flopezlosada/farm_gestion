<?php
/**
 * Created by PhpStorm.
 * User: paco
 * Date: 27/01/20
 * Time: 16:12
 */

namespace App\Custom;


class WeekOfMonth
{

    /**
     * @param $date
     * @return string
     * devuelve el número de semana dentro de un mes dado
     */

    public static function numberOfWeek(\DateTime $date)
    {

        //generamos la fecha para el día 1 del mes y año especificado
        $month = $date->format('m');
        $year = $date->format('Y');
        $day = $date->format("d");
        $date_first_day_month = new \DateTime($year . "/" . $month . "/1");

        /**
         * se coge el número de semana anual de la fecha dada y se le resta el número de semana anual del primer
         * día del mes y se suma 1.
         */

        $numberOfWeek = $date->format("W") - $date_first_day_month->format("W") + 1;

        return $numberOfWeek;
    }

    /*
     * devuelve el orden el día de la semana dentro del mes. Es decir, si es el primer viernes, el segundo viernes, etc. del mes
     */
    public static function dayOfWeekInMonth($date)
    {
        $dayOfMonth = $date->format('d');
        if ($dayOfMonth <= 7)
        {
            return 1;
        }
        else if (8 <= $dayOfMonth && $dayOfMonth <= 14) {
            return 2;
        }
        else if (15 <= $dayOfMonth && $dayOfMonth <= 21) {
            return 3;
        }
        else if (22 <= $dayOfMonth && $dayOfMonth <= 28) {
            return 4;
        }

        return 5;
    }
}