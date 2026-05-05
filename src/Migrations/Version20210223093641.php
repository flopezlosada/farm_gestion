<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210223093641 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE partner_egg_share DROP FOREIGN KEY FK_9BE940FD63DF2FD0');
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, begin_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, title VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('DROP TABLE egg_share');
        $this->addSql('DROP TABLE partner_egg_share');
        $this->addSql('DROP TABLE patch_crop_working');
        $this->addSql('DROP TABLE peliculas');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE egg_share (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created DATETIME NOT NULL, updated DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE partner_egg_share (id INT AUTO_INCREMENT NOT NULL, partner_id INT DEFAULT NULL, egg_share_id INT DEFAULT NULL, title VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created DATETIME NOT NULL, updated DATETIME NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, month_price NUMERIC(8, 2) NOT NULL, INDEX IDX_9BE940FD63DF2FD0 (egg_share_id), INDEX IDX_9BE940FD9393F8FE (partner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE patch_crop_working (cropworking_id INT NOT NULL, patch_id INT NOT NULL, INDEX IDX_E6679E6599C8A130 (cropworking_id), INDEX IDX_E6679E65CD00882C (patch_id), PRIMARY KEY(cropworking_id, patch_id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE peliculas (id INT NOT NULL, title TEXT CHARACTER SET utf8 NOT NULL COLLATE `utf8_general_ci`, genre TEXT CHARACTER SET utf8 NOT NULL COLLATE `utf8_general_ci`, rating DOUBLE PRECISION NOT NULL, synopsis LONGTEXT CHARACTER SET utf8 NOT NULL COLLATE `utf8_general_ci`, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = MyISAM COMMENT = \'\' ');
        $this->addSql('ALTER TABLE partner_egg_share ADD CONSTRAINT FK_9BE940FD63DF2FD0 FOREIGN KEY (egg_share_id) REFERENCES egg_share (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE partner_egg_share ADD CONSTRAINT FK_9BE940FD9393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE patch_crop_working ADD CONSTRAINT FK_E6679E6599C8A130 FOREIGN KEY (cropworking_id) REFERENCES crop_working (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE patch_crop_working ADD CONSTRAINT FK_E6679E65CD00882C FOREIGN KEY (patch_id) REFERENCES patch (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('DROP TABLE booking');
    }
}
