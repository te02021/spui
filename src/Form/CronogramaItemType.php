<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\CronogramaItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class CronogramaItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, [
                'label'      => 'Nombre',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 200, 'placeholder' => 'Ej: Análisis Matemático I'],
                'constraints' => [new NotBlank(message: 'El nombre de la materia o actividad es requerido.')],
            ])
            ->add('aula', TextType::class, [
                'label'      => 'Aula / Espacio (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 100, 'placeholder' => 'Ej: Aula 3 / Lab 2'],
            ])
            ->add('horaInicio', TimeType::class, [
                'label'      => 'Hora inicio',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá la hora de inicio.')],
            ])
            ->add('horaFin', TimeType::class, [
                'label'      => 'Hora fin',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá la hora de fin.')],
            ])
            ->add('diasSemana', ChoiceType::class, [
                'label'      => 'Días de la semana',
                'label_attr' => ['class' => 'unraf-form-label'],
                'mapped'     => false,
                'multiple'   => true,
                'expanded'   => true,
                'choices'    => ['Lun' => 0, 'Mar' => 1, 'Mié' => 2, 'Jue' => 3, 'Vie' => 4, 'Sáb' => 5, 'Dom' => 6],
                'data'       => $options['dias_semana_default'],
            ])
            ->add('orden', IntegerType::class, [
                'label'      => 'Orden',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'min' => 0, 'style' => 'max-width:8rem'],
            ])
            // label:false — el texto visible lo pone el <span class="spui-switch-status">
            // que actualiza switch-toggle.js. Con un label acá saldría dos veces.
            ->add('activo', CheckboxType::class, [
                'label'    => false,
                'required' => false,
                'data'     => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => CronogramaItem::class,
            'dias_semana_default' => [0, 1, 2, 3, 4],
        ]);
    }
}
