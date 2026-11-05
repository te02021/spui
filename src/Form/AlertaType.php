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
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
                'attr'       => ['class' => 'unraf-form-control', 'rows' => 4, 'placeholder' => 'Instrucciones o información para las pantallas...'],
                'constraints' => [new NotBlank(message: 'El mensaje de la alerta es requerido.')],
            ])
            ->add('prioridad', IntegerType::class, [
                'label'      => 'Prioridad',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'min' => 1, 'max' => 100],
                'constraints' => [
                    new NotBlank(message: 'La prioridad es obligatoria.'),
                    new Range(notInRangeMessage: 'La prioridad debe estar entre {{ min }} y {{ max }}.', min: 1, max: 100),
                ],
            ])
            ->add('expiraEn', DateTimeType::class, [
                'label'      => 'Vence el (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'required'   => false,
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
            ])
            ->add('contenido', EntityType::class, [
                'label'         => 'Contenido adjunto (opcional)',
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
                'placeholder'   => '— sin contenido adjunto —',
                'required'      => false,
                'attr'          => ['class' => 'unraf-form-select'],
            ])
            /**
             * Destinatarias de la alerta.
             * Sin ninguna elegida la alerta es global (va a todas). Ver 'alcance'.
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
            ])
            // A qué pantallas llega es una decisión explícita (05/11/2026). No se guarda:
            // la alerta es global cuando no tiene pantallas. En el alta no viene nada
            // elegido, porque olvidarse de marcar la mandaba a todo el campus.
            ->add('alcance', ChoiceType::class, [
                'label'       => false,
                'mapped'      => false,
                'expanded'    => true,
                'required'    => false,
                'placeholder' => false,
                'choices'     => ['Todas las pantallas' => 'todas', 'Elegir pantallas' => 'elegir'],
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $e): void {
            $alerta = $e->getData();
            if ($alerta instanceof AlertaEmergencia && $alerta->getId() !== null) {
                $e->getForm()->get('alcance')->setData($alerta->esGlobal() ? 'todas' : 'elegir');
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $e): void {
            $form = $e->getForm();
            $alerta = $e->getData();
            if (!$alerta instanceof AlertaEmergencia) {
                return;
            }
            $alcance = $form->get('alcance')->getData();
            if ($alcance === 'todas') {
                foreach ($alerta->getPantallas()->toArray() as $pantalla) {
                    $alerta->removePantalla($pantalla);
                }
            } elseif ($alcance === 'elegir') {
                if ($alerta->getPantallas()->isEmpty()) {
                    $form->get('pantallas')->addError(new FormError('Elegí al menos una pantalla.'));
                }
            } else {
                $form->get('alcance')->addError(new FormError('Elegí a qué pantallas llega la alerta.'));
            }
        });
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
