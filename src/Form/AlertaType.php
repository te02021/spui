<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\AlertaEmergencia;
use SPUI\Entity\Contenido;
use SPUI\Entity\Pantalla;
use SPUI\Enum\EstadoContenido;
use SPUI\Enum\TipoContenido;
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
                'constraints' => [new NotBlank(message: 'El título de la alerta es requerido.')],
            ])
            ->add('mensaje', TextareaType::class, [
                'label'      => 'Mensaje',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'rows' => 4, 'placeholder' => 'Instrucciones o información de emergencia...'],
                'constraints' => [new NotBlank(message: 'El mensaje de la alerta es requerido.')],
            ])
            ->add('prioridad', IntegerType::class, [
                'label'      => 'Prioridad',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'min' => 1, 'max' => 100, 'style' => 'max-width:8rem'],
                'constraints' => [new Range(notInRangeMessage: 'La prioridad debe estar entre {{ min }} y {{ max }}.', min: 1, max: 100)],
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
                // Sólo imagen/video: es lo único que el Pi puede mostrar de
                // fondo detrás del texto de la alerta (ver Player.mostrar_alerta
                // del lado pi-client). Antes se podían elegir los seis tipos —
                // un cronograma o un QR "adjunto a una alerta" no tiene forma
                // de renderizarse ahí, quedaba seleccionable sin sentido.
                'query_builder' => fn($er) => $er->createQueryBuilder('c')
                    ->where('c.estado != :archivado')
                    ->andWhere('c.tipo IN (:tipos)')
                    ->setParameter('archivado', EstadoContenido::Archivado)
                    ->setParameter('tipos', [TipoContenido::Imagen, TipoContenido::Video])
                    ->orderBy('c.titulo', 'ASC'),
                'choice_label'  => fn(Contenido $c) => $c->getTitulo() . ' (' . $c->getTipo()->etiqueta() . ')',
                'placeholder'   => '— Sin contenido asociado —',
                'required'      => false,
                'attr'          => ['class' => 'unraf-form-select'],
            ])
            /**
             * Destinatarias de la alerta.
             * Sin ninguna elegida la alerta es global (va a todas), que es el
             * comportamiento histórico y el que conviene en una emergencia real.
             */
            ->add('pantallas', EntityType::class, [
                'label'         => 'Pantallas que la reciben',
                'label_attr'    => ['class' => 'unraf-form-label'],
                'class'         => Pantalla::class,
                'em'            => 'SPUI',
                'query_builder' => fn($er) => $er->createQueryBuilder('p')
                    ->leftJoin('p.ubicacion', 'u')
                    ->leftJoin('u.edificio', 'e')
                    ->orderBy('e.nombre', 'ASC')
                    ->addOrderBy('p.nombre', 'ASC'),
                'choice_label'  => fn(Pantalla $p) => $p->getNombre() . ' — ' . $p->getUbicacion()->getEdificio()->getNombre(),
                'multiple'      => true,
                'expanded'      => true,
                'required'      => false,
                'by_reference'  => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlertaEmergencia::class,
            // El campo de archivo de sonido no está mapeado al form (mismo
            // criterio que 'archivo' en ContenidoType): se lee directo de
            // $request->files en el controller. Sin este enctype el navegador
            // manda el formulario como texto plano y el archivo no llega.
            'attr'       => ['enctype' => 'multipart/form-data'],
        ]);
    }
}
