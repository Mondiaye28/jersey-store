<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la date d'inscription des utilisateurs.
 */
final class Version20261026202556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute utilisateur.date_inscription (date et heure d\'inscription)';
    }

    public function up(Schema $schema): void
    {
        // 1. La valeur par défaut remplit la colonne pour les comptes déjà existants
        //    (sinon NOT NULL échouerait ou donnerait 0000-00-00 00:00:00)
        $this->addSql('ALTER TABLE utilisateur ADD date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        // 2. Les dates écrites par PHP sont en UTC (date.timezone = UTC) : on aligne
        //    les comptes existants sur la même convention, CURRENT_TIMESTAMP étant à l'heure du serveur MariaDB
        $this->addSql('UPDATE utilisateur SET date_inscription = UTC_TIMESTAMP()');
        // 3. Le défaut est retiré : la date est toujours fournie par l'entité (constructeur),
        //    et le schéma reste identique au mapping (doctrine:schema:validate).
        //    CHANGE et non « ALTER ... DROP DEFAULT », que MariaDB 10.4 ignore pour current_timestamp()
        $this->addSql('ALTER TABLE utilisateur CHANGE date_inscription date_inscription DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP date_inscription');
    }
}
