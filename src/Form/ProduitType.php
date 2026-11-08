<?php

namespace App\Form;

use App\Entity\Produit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * Formulaire de création et de modification d'un produit.
 * Les règles sur nom, description, prix et stock sont portées par l'entité (#[Assert\...]).
 */
class ProduitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'produit.champ.nom'])
            ->add('description', TextareaType::class, ['label' => 'produit.champ.description'])
            ->add('prix', NumberType::class, [
                'label' => 'produit.champ.prix',
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01'],
            ])
            ->add('stock', IntegerType::class, [
                'label' => 'produit.champ.stock',
                'attr' => ['min' => 0],
            ])
            // Non mappé : le fichier est enregistré par ProduitPhotoUploader,
            // et seul son nom est stocké dans Produit::$photo
            ->add('photo', FileType::class, [
                'label' => 'produit.champ.photo',
                'help' => 'produit.champ.photo_aide',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produit::class,
        ]);
    }
}
