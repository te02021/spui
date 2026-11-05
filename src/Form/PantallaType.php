<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Reproductor;
use SPUI\Entity\Ubicacion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class PantallaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, [
                'label'      => 'Nombre',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 150, 'placeholder' => 'Ej: Pantalla Pasillo A'],
                'constraints' => [new NotBlank(message: 'El nombre de la pantalla es requerido.')],
            ])
            ->add('ubicacion', EntityType::class, [
                'label'        => 'Ubicación',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Ubicacion::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Ubicacion $u) => $u->getEdificio()->getNombre() . ($u->getSector() ? ' — ' . $u->getSector() : ''),
                'placeholder'  => '— elegí una ubicación —',
                'attr'         => ['class' => 'unraf-form-select'],
                'constraints'  => [new NotBlank(message: 'Seleccioná una ubicación.')],
            ])
            ->add('reproductor', EntityType::class, [
                'label'        => 'Reproductor (opcional)',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Reproductor::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Reproductor $r) => $r->getHostname(),
                'placeholder'  => '— sin asignar —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('playlistFallback', EntityType::class, [
                'label'        => 'Playlist de respaldo (opcional)',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Playlist::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Playlist $p) => $p->getNombre(),
                'placeholder'  => '— ninguna: muestra la pantalla de espera —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            // Switch Activa/Inactiva, como el resto del CMS (antes, un selector
            // con un tercer valor "mantenimiento" que no hacía nada distinto).
            // Lee y escribe por Pantalla::isActiva()/setActiva().
            ->add('activa', CheckboxType::class, [
                'label'    => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pantalla::class]);
    }
}
