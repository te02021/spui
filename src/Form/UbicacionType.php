<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Edificio;
use SPUI\Entity\Ubicacion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class UbicacionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('edificio', EntityType::class, [
                'label'        => 'Edificio',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Edificio::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Edificio $e) => $e->getNombre(),
                'placeholder'  => '— seleccionar edificio —',
                'attr'         => ['class' => 'unraf-form-select'],
                'constraints'  => [new NotBlank(message: 'El edificio es requerido.')],
            ])
            ->add('sector', TextType::class, [
                'label'      => 'Sector / Espacio (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 150, 'placeholder' => 'Ej: Hall de Entrada, Pasillo Planta Alta'],
            ])
            ->add('descripcion', TextareaType::class, [
                'label'      => 'Descripción',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'rows' => 3],
            ])
            ->add('activo', CheckboxType::class, [
                'label'    => 'Ubicación activa',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Ubicacion::class]);
    }
}
