<?php

namespace App\Form;

use App\Dto\ProfilUtilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de modification du profil (page « Mon compte »).
 * Aucun champ de date : la date d'une commande n'est jamais saisie par l'utilisateur.
 */
class ProfilFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'form.nom'])
            ->add('prenom', TextType::class, ['label' => 'form.prenom'])
            ->add('email', EmailType::class, ['label' => 'form.email'])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'compte.nouveau_mot_de_passe',
                'help' => 'compte.mot_de_passe_aide',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfilUtilisateur::class,
        ]);
    }
}
