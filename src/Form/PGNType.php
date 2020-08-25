<?php

// src/Form/PGNtype.php
namespace App\Form;

use App\Entity\PGN;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class PGNType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            // ...

            /* File uploads will be implemented later

            ->add('file', FileType::class, [
                'label' => 'Game PGN file',

                // unmapped means that this field is not associated to any entity property
                'mapped' => false,

                // make it optional so you don't have to re-upload the PDF file
                // everytime you edit the Product details
                'required' => true,

                // unmapped fields can't define their validation using annotations
                // in the associated entity, so you can use the PHP constraint classes
                'constraints' => [
                    new File([
                        'maxSize' => '1M',
                        'mimeTypes' => [
                            'text/plain',
			    'application/vnd.chess-pgn',
			    'application/x-chess-pgn',
                        ],
                        'mimeTypesMessage' => 'Please upload a valid PGN file',
                    ])
                ],
            ])
            */
            ->add('text', TextareaType::class, [
                'attr' => ['class' => 'tinymce'],
                'disabled' => true
                ])
            ->add('submit', SubmitType::class)
            // ...
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => PGN::class,
            'disabled' => true,
        ]);
    }
}

?>
