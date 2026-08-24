<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Contenido;
use SPUI\Enum\TipoContenido;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class ContenidoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label'      => 'Título',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 200, 'placeholder' => 'Ej: Afiche inauguración'],
                'constraints' => [new NotBlank(message: 'El título es requerido.')],
            ])
            ->add('tipo', EnumType::class, [
                'label'        => 'Tipo',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => TipoContenido::class,
                'choice_label' => fn(TipoContenido $t) => ucfirst($t->value),
                'placeholder'  => '— seleccionar —',
                // El gancho para el JS va como data-*, no como 'id': Symfony ya
                // emite su propio id="contenido_tipo" y un id en 'attr' se
                // renderiza como un segundo atributo duplicado que el navegador
                // descarta (gana el primero), dejando el selector JS sin match.
                'attr'         => ['class' => 'unraf-form-select', 'data-spui-tipo-contenido' => '1'],
                'constraints'  => [new NotBlank(message: 'Seleccioná un tipo.')],
            ])
            ->add('duracionSegundos', IntegerType::class, [
                'label'      => 'Duración por defecto (segundos)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'attr'       => [
                    'class'       => 'unraf-form-control',
                    'min'         => 1,
                    'max'         => 3600,
                    'style'       => 'max-width:8rem',
                    'placeholder' => 'Vacío = permanente',
                ],
                'constraints' => [new Range(notInRangeMessage: 'La duración debe estar entre {{ min }} y {{ max }} segundos.', min: 1, max: 3600)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contenido::class,
            'attr'       => ['enctype' => 'multipart/form-data'],
        ]);
    }
}
