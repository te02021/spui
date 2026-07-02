<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\Contenido;
use SPUI\Enum\EstadoContenido;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class AlertaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label'      => 'Título',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'maxlength' => 200, 'placeholder' => 'Ej: Evacuación zona norte'],
                'constraints' => [new NotBlank()],
            ])
            ->add('mensaje', TextareaType::class, [
                'label'      => 'Mensaje',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'rows' => 4, 'placeholder' => 'Instrucciones o información de emergencia...'],
                'constraints' => [new NotBlank()],
            ])
            ->add('prioridad', IntegerType::class, [
                'label'      => 'Prioridad',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'min' => 1, 'max' => 100, 'style' => 'max-width:8rem'],
                'constraints' => [new Range(min: 1, max: 100)],
            ])
            ->add('expiraEn', DateTimeType::class, [
                'label'      => 'Expira en (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
            ])
            ->add('contenido', EntityType::class, [
                'label'         => 'Contenido multimedia (opcional)',
                'label_attr'    => ['class' => 'unraf-form-label'],
                'class'         => Contenido::class,
                'em'            => 'SPUI',
                'query_builder' => fn($er) => $er->createQueryBuilder('c')
                    ->where('c.estado = :estado')
                    ->setParameter('estado', EstadoContenido::Publicado)
                    ->orderBy('c.titulo', 'ASC'),
                'choice_label'  => fn(Contenido $c) => $c->getTitulo() . ' (' . $c->getTipo()->value . ')',
                'placeholder'   => '— Sin contenido asociado —',
                'required'      => false,
                'attr'          => ['class' => 'unraf-form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AlertaEmergencia::class]);
    }
}
