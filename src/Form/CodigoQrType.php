<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\CodigoQr;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class CodigoQrType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('etiqueta', TextType::class, [
                'label'       => 'Etiqueta',
                'label_attr'  => ['class' => 'unraf-form-label'],
                'attr'        => [
                    'class'       => 'unraf-form-control',
                    'maxlength'   => 200,
                    'placeholder' => 'Ej: Encuesta de satisfacción 2026',
                ],
                'help'        => 'Nombre interno para reconocerlo en el listado. No se muestra en pantalla.',
                'constraints' => [new NotBlank(message: 'La etiqueta es requerida.'), new Length(max: 200, maxMessage: 'La etiqueta no puede superar los {{ limit }} caracteres.')],
            ])
            ->add('urlDestino', UrlType::class, [
                'label'         => 'URL destino',
                'label_attr'    => ['class' => 'unraf-form-label'],
                'default_protocol' => 'https',
                'attr'          => [
                    'class'       => 'unraf-form-control',
                    'maxlength'   => 500,
                    'placeholder' => 'https://…',
                ],
                'help'          => 'A dónde llega quien escanea. Se puede cambiar después sin reimprimir el QR.',
                'constraints'   => [new NotBlank(message: 'La URL de destino es requerida.'), new Url(message: 'Ingresá una URL válida (debe empezar con http:// o https://).'), new Length(max: 500, maxMessage: 'La URL no puede superar los {{ limit }} caracteres.')],
            ])
            ->add('expiraEn', DateTimeType::class, [
                'label'      => 'Expira el (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control'],
                'help'       => 'Pasada esta fecha el QR deja de redirigir. Vacío = sin vencimiento.',
            ])
            // label:false — el texto visible lo pone el <span class="spui-switch-status">
            // que actualiza switch-toggle.js. Con un label acá saldría dos veces.
            ->add('activo', CheckboxType::class, [
                'label'    => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CodigoQr::class,
        ]);
    }
}
