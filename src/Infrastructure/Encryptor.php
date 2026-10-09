<?php

namespace Plugin0\Infrastructure;

use PhpEncryption;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Encryption\Encryptor as EncryptorInterface;

/**
 * Šifruje tajne pre upisa u bazu PrestaShop-ovim ključem (_NEW_COOKIE_KEY_ iz app/config/parameters.php).
 */
class Encryptor implements EncryptorInterface
{
    private PhpEncryption $cipher;

    public function __construct()
    {
        $this->cipher = new PhpEncryption(_NEW_COOKIE_KEY_);
    }

    public function encrypt(string $data): string
    {
        return $this->cipher->encrypt($data);
    }

    public function decrypt(string $encryptedData): string
    {
        $plain = $this->cipher->decrypt($encryptedData);

        return $plain === false ? '' : $plain;
    }
}