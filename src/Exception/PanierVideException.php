<?php

namespace App\Exception;

/**
 * Levée quand on tente d'acheter alors qu'il n'y a pas de panier en cours ou qu'il est vide.
 */
class PanierVideException extends \DomainException
{
}
