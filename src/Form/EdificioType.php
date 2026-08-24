<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Edificio;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class EdificioType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nombre', TextType::class, [
                'label'      => 'Nombre del edificio',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 150, 'placeholder' => 'Ej: Edificio Central'],
                'constraints' => [new NotBlank(message: 'El nombre del edificio es requerido.')],
            ])
            ->add('descripcion', TextareaType::class, [
                'label'      => 'Descripción (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'rows' => 3, 'placeholder' => 'Ej: Sede principal del campus, esquina Av. Roca y Paz'],
            ])
            ->add('activo', CheckboxType::class, [
                'label'    => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Edificio::class]);
    }
}
