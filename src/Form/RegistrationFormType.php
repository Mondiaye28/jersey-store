<?php

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire d'inscription.
 * Le mot de passe n'est pas mappé sur l'entité : il est haché dans le contrôleur.
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'form.nom'])
            ->add('prenom', TextType::class, ['label' => 'form.prenom'])
            ->add('email', EmailType::class, ['label' => 'form.email'])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'form.password',
                'constraints' => [
                    new NotBlank(),
                    // Mot de passe d'au moins 8 caractères
                    new Length(min: 8, max: 4096),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
        ]);
    }
}
