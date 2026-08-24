<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Edificio;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Programacion;
use SPUI\Entity\Ubicacion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class ProgramacionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('playlist', EntityType::class, [
                'label'        => 'Playlist',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Playlist::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Playlist $p) => $p->getNombre(),
                'placeholder'  => '— seleccionar —',
                'attr'         => ['class' => 'unraf-form-select'],
                'constraints'  => [new NotBlank(message: 'La playlist es requerida.')],
            ])
            ->add('pantalla', EntityType::class, [
                'label'        => 'Pantalla específica',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Pantalla::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Pantalla $p) => $p->getNombre() . ' — ' . $p->getUbicacion()->getEdificio()->getNombre(),
                'placeholder'  => '— todas (sin filtro) —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('ubicacion', EntityType::class, [
                'label'        => 'Por sector/ubicación',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Ubicacion::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Ubicacion $u) => $u->getEdificio()->getNombre() . ($u->getSector() ? ' — ' . $u->getSector() : ''),
                'placeholder'  => '— sin filtro —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('edificio', EntityType::class, [
                'label'        => 'Por edificio (todas sus pantallas)',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Edificio::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Edificio $e) => $e->getNombre(),
                'placeholder'  => '— sin filtro —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
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
            ->add('fechaInicio', DateType::class, [
                'label'      => 'Desde',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá desde qué fecha rige la regla.')],
            ])
            ->add('fechaFin', DateType::class, [
                'label'      => 'Hasta (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control'],
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
            ->add('repetirSemanal', CheckboxType::class, [
                'label'    => false,
                'required' => false,
                'data'     => true,
                'attr'     => ['class' => 'form-check-input'],
            ])
            ->add('prioridad', IntegerType::class, [
                'label'      => 'Prioridad',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'min' => 1, 'max' => 100, 'style' => 'max-width:8rem'],
                'constraints' => [new Range(
                    notInRangeMessage: 'La prioridad debe estar entre {{ min }} y {{ max }}.',
                    min: 1,
                    max: 100,
                )],
            ])
            ->add('activo', CheckboxType::class, [
                'label'    => false,
                'required' => false,
                'data'     => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => Programacion::class,
            'dias_semana_default' => [0, 1, 2, 3, 4],
        ]);
    }
}
