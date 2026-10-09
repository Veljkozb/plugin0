<?php

namespace Plugin0\Infrastructure;

use Db;
use Plugin0\Infrastructure\Repository\BaseRepository;

/**
 * Pravi i briše tabele koje core koristi preko RepositoryRegistry.
 * Poziva se iz Plugin0::install() i Plugin0::uninstall().
 */
class Installer
{
    public static function createTables(): bool
    {
        $indexColumns = '';
        for ($i = 1; $i <= BaseRepository::INDEX_COLUMNS; $i++) {
            $indexColumns .= '`index_' . $i . '` VARCHAR(255) NULL,' . "\n";
        }

        $sql = 'CREATE TABLE IF NOT EXISTS `' . bqSQL(_DB_PREFIX_ . BaseRepository::TABLE_NAME) . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(128) NOT NULL,
            ' . $indexColumns . '
            `data` LONGTEXT NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_type_index_1` (`type`, `index_1`),
            KEY `idx_type_index_2` (`type`, `index_2`),
            KEY `idx_type_index_3` (`type`, `index_3`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return Db::getInstance()->execute($sql);
    }

    public static function dropTables(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . bqSQL(_DB_PREFIX_ . BaseRepository::TABLE_NAME) . '`'
        );
    }

}