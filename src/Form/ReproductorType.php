<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Reproductor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ReproductorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('hostname', TextType::class, [
                'label'      => 'Hostname',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 100, 'placeholder' => 'spui-pi-01'],
                'constraints' => [new NotBlank()],
            ])
            ->add('versionFirmware', TextType::class, [
                'label'      => 'Versión firmware (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 50, 'placeholder' => 'v1.0.0'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Reproductor::class]);
    }
}
