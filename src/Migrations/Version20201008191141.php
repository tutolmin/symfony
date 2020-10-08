<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201008191141 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql("INSERT INTO `user` (`id`, `email`, `roles`, `api_token`, `facebook_id`, `google_id`, `lichess_id`, `queue_limit`, `balance`, `created_date_time`, `can_upload`, `first_name`, `last_name`, `notification_type`)".
        " VALUES (1,	'support@chesscheat.com',	'[\"ROLE_ADMIN\"]',	NULL,	NULL,	NULL,	NULL,	NULL,	NULL,	'2020-03-20 09:39:48',	1,	'Name',	'Surname',	'instant')");
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql("DELETE FROM `user` WHERE ((`id` = '1'))");
    }
}
