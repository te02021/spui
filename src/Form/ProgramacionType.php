<?php

declare(strict_types=1);

namespace SPUI\Form;

use SPUI\Entity\Edificio;
use SPUI\Entity\Pantalla;
use SPUI\Entity\Playlist;
use SPUI\Entity\Programacion;
use SPUI\Entity\Ubicacion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class ProgramacionType extends AbstractType
{
    /** Mismo texto en el servidor, en el alta manual del cronograma y en la validación en vivo. */
    public const MSG_DIAS_REQUERIDOS = 'Elegí al menos un día.';

    /** Lo que pide cada alcance. "todas" no pide nada: la regla queda global. */
    private const MSG_ALCANCE = [
        'edificio'  => 'Elegí el edificio.',
        'ubicacion' => 'Elegí la ubicación.',
        'pantalla'  => 'Elegí la pantalla.',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('playlist', EntityType::class, [
                'label'        => 'Playlist',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Playlist::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Playlist $p) => $p->getNombre(),
                'placeholder'  => '— elegí una playlist —',
                'attr'         => ['class' => 'unraf-form-select'],
                'constraints'  => [new NotBlank(message: 'La playlist es requerida.')],
            ])
            // Una sola decisión en vez de tres listas sueltas (05/11/2026). No se
            // guarda: sale de qué destino tiene la regla, ver los listeners de abajo.
            ->add('alcance', ChoiceType::class, [
                'label'    => false,
                'mapped'   => false,
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choices'  => ['Todas' => 'todas', 'Un edificio' => 'edificio', 'Una ubicación' => 'ubicacion', 'Una pantalla' => 'pantalla'],
            ])
            ->add('pantalla', EntityType::class, [
                'label'        => 'Pantalla',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Pantalla::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Pantalla $p) => $p->getNombre() . ' — ' . $p->getUbicacion()->getEdificio()->getNombre(),
                'placeholder'  => '— elegí una pantalla —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('ubicacion', EntityType::class, [
                'label'        => 'Ubicación',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Ubicacion::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Ubicacion $u) => $u->getEdificio()->getNombre() . ($u->getSector() ? ' — ' . $u->getSector() : ''),
                'placeholder'  => '— elegí una ubicación —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('edificio', EntityType::class, [
                'label'        => 'Edificio',
                'label_attr'   => ['class' => 'unraf-form-label'],
                'class'        => Edificio::class,
                'em'           => 'SPUI',
                'choice_label' => fn(Edificio $e) => $e->getNombre(),
                'placeholder'  => '— elegí un edificio —',
                'required'     => false,
                'attr'         => ['class' => 'unraf-form-select'],
            ])
            ->add('horaInicio', TimeType::class, [
                'label'      => 'Hora de inicio',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá la hora de inicio.')],
            ])
            ->add('horaFin', TimeType::class, [
                'label'      => 'Hora de fin',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá la hora de fin.')],
            ])
            ->add('fechaInicio', DateType::class, [
                'label'      => 'Vigente desde',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'attr'       => ['class' => 'unraf-form-control'],
                'constraints' => [new NotBlank(message: 'Indicá desde qué fecha rige la regla.')],
            ])
            ->add('fechaFin', DateType::class, [
                'label'      => 'Hasta (opcional)',
                'label_attr' => ['class' => 'unraf-form-label'],
                'widget'     => 'single_text',
                'input'      => 'datetime_immutable',
                'required'   => false,
                'attr'       => ['class' => 'unraf-form-control'],
            ])
            ->add('diasSemana', ChoiceType::class, [
                'label'      => 'Días de la semana',
                'label_attr' => ['class' => 'unraf-form-label'],
                'mapped'     => false,
                'multiple'   => true,
                'expanded'   => true,
                'choices'    => ['Lun' => 0, 'Mar' => 1, 'Mié' => 2, 'Jue' => 3, 'Vie' => 4, 'Sáb' => 5, 'Dom' => 6],
                'data'       => $options['dias_semana_default'],
                // Sin ningún día la regla se guardaba como "todos los días" sin
                // avisar. Ahora se exige al menos uno (decisión de Mateo, 10/10/2026).
                'constraints' => [new Count(min: 1, minMessage: ProgramacionType::MSG_DIAS_REQUERIDOS)],
            ])
            ->add('repetirSemanal', CheckboxType::class, [
                'label'    => false,
                'required' => false,
                // Sin 'data' => true: forzaba "Sí" al editar y pisaba lo guardado.
                // El valor inicial del alta lo da la entidad.
                'attr'     => ['class' => 'form-check-input'],
            ])
            ->add('prioridad', IntegerType::class, [
                'label'      => 'Prioridad',
                'label_attr' => ['class' => 'unraf-form-label'],
                'attr'       => ['class' => 'unraf-form-control', 'min' => 1, 'max' => 100],
                'constraints' => [new NotBlank(message: 'La prioridad es obligatoria.'), new Range(
                    notInRangeMessage: 'La prioridad debe estar entre {{ min }} y {{ max }}.',
                    min: 1,
                    max: 100,
                )],
            ])
            // Sin 'data' => true: esa opción forzaba el switch encendido
            // SIEMPRE, ignorando lo guardado. En una edición mostraba "activa"
            // una regla desactivada desde el listado, y guardar la reactivaba
            // sin querer. El valor inicial del alta ya lo da la entidad
            // (Programacion::$activo = true).
            ->add('activo', CheckboxType::class, [
                'label'    => false,
                'required' => false,
            ]);

        // Al abrir, el alcance sale del destino que tiene la regla.
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $e): void {
            $prog = $e->getData();
            $alcance = 'todas';
            if ($prog instanceof Programacion) {
                $alcance = $prog->getPantalla() ? 'pantalla' : ($prog->getUbicacion() ? 'ubicacion' : ($prog->getEdificio() ? 'edificio' : 'todas'));
            }
            $e->getForm()->get('alcance')->setData($alcance);
        });

        // Al guardar queda sólo el destino del alcance elegido, y es obligatorio:
        // "Un edificio" sin edificio se habría guardado como una regla global.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $e): void {
            $form = $e->getForm();
            $prog = $e->getData();
            $alcance = $form->get('alcance')->getData();
            if (!$prog instanceof Programacion || !is_string($alcance)) {
                return;
            }
            if (isset(self::MSG_ALCANCE[$alcance]) && $form->get($alcance)->getData() === null) {
                $form->get($alcance)->addError(new FormError(self::MSG_ALCANCE[$alcance]));
            }
            if ($alcance !== 'pantalla') {
                $prog->setPantalla(null);
            }
            if ($alcance !== 'ubicacion') {
                $prog->setUbicacion(null);
            }
            if ($alcance !== 'edificio') {
                $prog->setEdificio(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => Programacion::class,
            'dias_semana_default' => [0, 1, 2, 3, 4],
        ]);
    }
}
