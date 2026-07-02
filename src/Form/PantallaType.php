<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Reproductor;
use SPUI\Entity\Ubicacion;
use SPUI\Enum\EstadoPantalla;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class PantallaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, [
                'label'      => 'Nombre',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 150, 'placeholder' => 'Ej: Pantalla Pasillo A'],
                'constraints' => [new NotBlank()],
            ])
            ->add('ubicacion', EntityType::class, [
                'label'        => 'Ubicación',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Ubicacion::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Ubicacion $u) => $u->getEdificio()->getNombre() . ($u->getSector() ? ' — ' . $u->getSector() : ''),
                'placeholder'  => '— seleccionar ubicación —',
                'attr'         => ['class' => 'unraf-form-select'],
                'constraints'  => [new NotBlank(message: 'Seleccioná una ubicación.')],
            ])
            ->add('reproductor', EntityType::class, [
                'label'        => 'Reproductor (Raspberry Pi)',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Reproductor::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Reproductor $r) => $r->getHostname(),
                'placeholder'  => '— sin asignar —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('playlistFallback', EntityType::class, [
                'label'        => 'Playlist de fallback (cuando no hay programación activa)',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Playlist::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Playlist $p) => $p->getNombre(),
                'placeholder'  => '— sin fallback (pantalla en negro) —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('macAddress', TextType::class, [
                'label'      => 'Dirección MAC',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 17, 'placeholder' => 'aa:bb:cc:dd:ee:ff'],
            ])
            ->add('ipAddress', TextType::class, [
                'label'      => 'Dirección IP (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 45, 'placeholder' => '192.168.1.x'],
            ])
            ->add('resolucionAncho', IntegerType::class, [
                'label'      => 'Ancho (px)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'style' => 'max-width:9rem'],
                'constraints' => [new Range(min: 320, max: 7680)],
            ])
            ->add('resolucionAlto', IntegerType::class, [
                'label'      => 'Alto (px)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'style' => 'max-width:9rem'],
                'constraints' => [new Range(min: 240, max: 4320)],
            ])
            ->add('estado', EnumType::class, [
                'label'        => 'Estado',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => EstadoPantalla::class,
                'choice_label' => fn(EstadoPantalla $e) => ucfirst($e->value),
                'attr'         => ['class' => 'unraf-form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Pantalla::class]);
    }
}
