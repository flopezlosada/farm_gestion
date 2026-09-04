<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una franja horaria de una tarea de voluntariado: de una hora a otra.
 *
 * Es cada entrada de la colección `repeatTimes` de {@see VolunteerOfferType}.
 * La tarea guarda las franjas como pares `[inicio, fin]` en "HH:MM" —así las
 * lee el generador de turnos— y el formulario las pide como dos campos de hora;
 * el transformer de aquí traduce en los dos sentidos, y así ni la entidad ni la
 * plantilla saben del otro formato.
 *
 * NINGUNA DE LAS DOS HORAS ES OBLIGATORIA. Hay trabajo sin franja ("antes del
 * día 20"): sin ninguna, el turno es de todo el día y las pantallas lo enseñan
 * sin hora. Una franja con las dos horas vacías se descarta al guardar
 * (`delete_empty` en la colección); una con fin y sin inicio NO, para poder
 * decir que está a medias.
 */
class VolunteerTimeSlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('start', TimeType::class, [
                'label' => 'De',
                'widget' => 'single_text',
                'input' => 'string',
                'required' => false,
            ])
            ->add('end', TimeType::class, [
                'label' => 'a',
                'widget' => 'single_text',
                'input' => 'string',
                'required' => false,
            ]);

        $builder->addModelTransformer(new CallbackTransformer(
            // Modelo → campos: "HH:MM" a "HH:MM:SS", que es lo que espera un
            // TimeType con `input: string`.
            static fn (?array $slot): array => [
                'start' => self::withSeconds($slot[0] ?? null),
                'end' => self::withSeconds($slot[1] ?? null),
            ],
            // Campos → modelo. Sin nada, null: la colección la descarta.
            static function (?array $fields): ?array {
                $start = self::hourMinute($fields['start'] ?? null);
                $end = self::hourMinute($fields['end'] ?? null);

                return null === $start && null === $end ? null : [$start, $end];
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Los errores de una franja se cuelgan de la colección, que es lo
            // que la plantilla pinta: una franja es una fila y no tiene sitio
            // para un párrafo debajo.
            'error_bubbling' => true,
        ]);
    }

    /**
     * "HH:MM" a partir de lo que trae un TimeType ("HH:MM:SS"), o null sin hora.
     *
     * @param mixed $value lo que trae el campo
     *
     * @return string|null la hora en "HH:MM", o null
     */
    private static function hourMinute(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        $value = trim((string) $value);

        return '' === $value ? null : substr($value, 0, 5);
    }

    /**
     * "HH:MM" a "HH:MM:00", o null sin hora.
     *
     * @param string|null $value la hora guardada
     *
     * @return string|null la hora con segundos, o null
     */
    private static function withSeconds(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : substr($value.':00', 0, 8);
    }
}
