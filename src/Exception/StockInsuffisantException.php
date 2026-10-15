<?php

namespace App\Exception;

/**
 * Levée quand la quantité demandée dans le panier dépasse le stock du produit.
 */
class StockInsuffisantException extends \DomainException
{
}
