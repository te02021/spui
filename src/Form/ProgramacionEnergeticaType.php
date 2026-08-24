<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\ProgramacionEnergetica;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Horario de encendido/apagado y brillo de una pantalla para un día concreto.
 *
 * El día no se edita acá: viene fijado por la fila de la grilla semanal desde
 * la que se abre el formulario (una regla por pantalla y día, ver el unique
 * constraint uq_pantalla_dia).
 */
class ProgramacionEnergeticaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('horaEncendido', TimeType::class, [
                'label'       => 'Hora de encendido',
                'label_attr'  => ['class' => 'unraf-form-label'],
                'widget'      => 'single_text',
                'input'       => 'datetime_immutable',
                'attr'        => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá la hora de encendido.')],
            ])
            ->add('horaApagado', TimeType::class, [
                'label'       => 'Hora de apagado',
                'label_attr'  => ['class' => 'unraf-form-label'],
                'widget'      => 'single_text',
                'input'       => 'datetime_immutable',
                'attr'        => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá la hora de apagado.')],
            ])
            // RangeType renderiza <input type="range">: una barra de volumen en vez
            // de una caja de números. El rango y el paso son los mismos de antes; el
            // valor se muestra al costado y lo actualiza range-input.js en vivo.
            ->add('nivelBrillo', RangeType::class, [
                'label'       => 'Nivel de brillo (%)',
                'label_attr'  => ['class' => 'unraf-form-label'],
                'attr'        => [
                    'class'           => 'spui-range',
                    'min'             => 0,
                    'max'             => 100,
                    'step'            => 5,
                    'data-spui-range' => '1',
                ],
                'constraints' => [new Range(notInRangeMessage: 'El brillo debe estar entre {{ min }} y {{ max }}.', min: 0, max: 100)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProgramacionEnergetica::class,
        ]);
    }
}
