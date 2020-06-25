<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\UploadGameTask;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * UploadGameType.
 */
class UploadGameType extends AbstractType
{
    /** {@inheritDoc} */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('regular', TextareaType::class, [
                'label' => 'Paste your game, or...',
                'attr' => ['rows' => 10],
                'required' => false,
            ])
            ->add('file', FileType::class, [
                'label' => 'Upload PGN file',
                'required' => false,
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) {
                $form = $event->getForm();

                $regular = $form->get('regular');
                $file = $form->get('file');

                if ($regular->isEmpty() && $file->isEmpty()) {
                    $form->addError(new FormError('One of the fields should be sent'));
                    return;
                }
            })
        ;
    }

    /** {@inheritDoc} */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UploadGameTask::class,
        ]);
    }
}
