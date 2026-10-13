<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Reproductor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
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
                'constraints' => [new NotBlank(message: 'El hostname del reproductor es requerido.')],
            ])
            ->add('versionFirmware', TextType::class, [
                'label'      => 'Versión firmware (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 50, 'placeholder' => 'v1.0.0'],
            ])
            // En minutos enteros, 1 o más, sin tope práctico (decisión de Mateo,
            // 10/10/2026). El mínimo de 1 min respeta el límite del CMS de 2
            // syncs por minuto por reproductor, dejando lugar al botón
            // "Sincronizar ahora". data-spui-entero: validacion-vivo.js no deja
            // tipear decimales ni signos.
            ->add('intervaloSyncMin', IntegerType::class, [
                'label'           => 'Sincronizar cada',
                'label_attr'      => ['class' => 'unraf-form-label'],
                'invalid_message' => 'Ingresá una cantidad entera de minutos, sin decimales.',
                'attr'            => [
                    'class'           => 'unraf-form-control',
                    'min'             => 1,
                    'step'            => 1,
                    'inputmode'       => 'numeric',
                    'data-spui-entero' => '1',
                    'data-spui-minimo-mensaje' => 'El mínimo es 1 minuto.',
                    'data-spui-duracion-minutos' => '1',
                ],
                'constraints'     => [
                    new NotBlank(message: 'Indicá cada cuántos minutos sincroniza.'),
                    new GreaterThanOrEqual(1, message: 'El mínimo es 1 minuto.'),
                    new LessThanOrEqual(Reproductor::INTERVALO_SYNC_MAXIMO_MIN, message: 'El intervalo es demasiado grande.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Reproductor::class]);
    }
}
