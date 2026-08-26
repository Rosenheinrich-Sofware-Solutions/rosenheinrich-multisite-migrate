<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Archive_Encryption
{
    const EXT = '.venc';

    /** @var string|null */
    private static $runtime_passphrase = null;

    public static function set_runtime_passphrase(?string $passphrase): void
    {
        self::$runtime_passphrase = $passphrase;
    }

    public static function clear_runtime_passphrase(): void
    {
        self::$runtime_passphrase = null;
    }
    const MAGIC = 'MMIGENC1';

    public static function is_enabled(): bool
    {
        $settings = Rmmigrate_Settings::get();
        return !empty($settings['encrypt_archives']);
    }

    public static function encrypted_path(string $plain_path): string
    {
        return $plain_path . self::EXT;
    }

    public static function encrypt_file(string $source, string $dest): bool
    {
        self::load_crypto_core();
        $salt = random_bytes(Rmmigrate_Crypto_Core::V2_SALT_LEN);
        $iterations = Rmmigrate_Crypto_Core::V2_ITERATIONS;
        $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), $salt, $iterations);

        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if (Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
        }
        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($in === false || $out === false) {
            return false;
        }
        $out->write(Rmmigrate_Crypto_Core::v2_header($salt, $iterations));

        while (!$in->eof()) {
            $chunk = $in->read(1048576);
            if ($chunk === false) {
                $in->close();
                $out->close();
                return false;
            }
            if ($chunk === '') {
                continue;
            }
            $blob = Rmmigrate_Crypto_Core::v2_seal($chunk, $key);
            if ($blob === '') {
                $in->close();
                $out->close();
                return false;
            }
            $out->write(pack('N', strlen($blob)));
            $out->write($blob);
        }

        $in->close();
        $out->close();
        return true;
    }

    /**
     * Resumable v2 encrypt. `$plain_offset` is the byte offset into the plaintext source.
     * On the first slice (`$plain_offset === 0`) the destination is truncated and a fresh header written.
     *
     * @return array{done:bool,plain_offset:int}|null Null on hard failure.
     */
    public static function encrypt_slice(string $source, string $dest, int $plain_offset, int $budget_sec): ?array
    {
        self::load_crypto_core();

        if ($plain_offset < 0) {
            $plain_offset = 0;
        }

        if ($plain_offset === 0) {
            if (Rmmigrate_Filesystem::exists($dest)) {
                Rmmigrate_Filesystem::delete($dest);
            }
            $salt = random_bytes(Rmmigrate_Crypto_Core::V2_SALT_LEN);
            $iterations = Rmmigrate_Crypto_Core::V2_ITERATIONS;
            $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), $salt, $iterations);
            $out = Rmmigrate_Filesystem::open($dest, 'ab');
            if ($out === false) {
                return null;
            }
            $out->write(Rmmigrate_Crypto_Core::v2_header($salt, $iterations));
        } else {
            $probe = Rmmigrate_Filesystem::open($dest, 'rb');
            if ($probe === false) {
                return null;
            }
            $magic = $probe->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
            $salt = $probe->read(Rmmigrate_Crypto_Core::V2_SALT_LEN);
            $iter_header = $probe->read(4);
            $probe->close();
            if ($magic !== Rmmigrate_Crypto_Core::MAGIC_V2 || strlen((string) $salt) !== Rmmigrate_Crypto_Core::V2_SALT_LEN) {
                return null;
            }
            $iterations = (strlen((string) $iter_header) === 4)
                ? unpack('N', $iter_header)[1]
                : Rmmigrate_Crypto_Core::V2_ITERATIONS;
            $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), (string) $salt, (int) $iterations);
            $out = Rmmigrate_Filesystem::open($dest, 'ab');
            if ($out === false) {
                return null;
            }
        }

        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            $out->close();
            return null;
        }
        if ($plain_offset > 0) {
            $in->seek($plain_offset);
        }

        $start = microtime(true);
        while (!$in->eof() && (microtime(true) - $start) < $budget_sec) {
            $chunk = $in->read(1048576);
            if ($chunk === false) {
                $in->close();
                $out->close();
                return null;
            }
            if ($chunk === '') {
                continue;
            }
            $blob = Rmmigrate_Crypto_Core::v2_seal($chunk, $key);
            if ($blob === '') {
                $in->close();
                $out->close();
                return null;
            }
            $out->write(pack('N', strlen($blob)));
            $out->write($blob);
            $plain_offset = (int) $in->tell();
        }

        $done = $in->eof();
        $in->close();
        $out->close();

        return array(
            'done'         => $done,
            'plain_offset' => $plain_offset,
        );
    }

    /**
     * Resumable decrypt slice. Returns null on hard failure.
     *
     * @return array{done:bool,byte_offset:int}|null
     */
    public static function decrypt_slice(string $source, string $dest, int $byte_offset, int $budget_sec): ?array
    {
        self::load_crypto_core();
        $probe = Rmmigrate_Filesystem::open($source, 'rb');
        if ($probe === false) {
            return null;
        }
        $magic = $probe->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
        $probe->close();

        if ($magic === Rmmigrate_Crypto_Core::MAGIC_V2) {
            return self::decrypt_slice_v2($source, $dest, $byte_offset, $budget_sec);
        }

        return self::decrypt_slice_v1($source, $dest, $byte_offset, $budget_sec);
    }

    /**
     * Resumable v2 decrypt. v2 chunks are self-contained (per-chunk nonce + GCM
     * tag), so a slice can resume at any chunk boundary; the salt/key are always
     * re-derived from the header.
     *
     * @return array{done:bool,byte_offset:int}|null
     */
    private static function decrypt_slice_v2(string $source, string $dest, int $byte_offset, int $budget_sec): ?array
    {
        if ($byte_offset === 0 && Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
        }

        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            return null;
        }
        $in->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
        $salt = $in->read(Rmmigrate_Crypto_Core::V2_SALT_LEN);
        $iter_header = $in->read(4);
        $iterations = (strlen((string) $iter_header) === 4) ? unpack('N', $iter_header)[1] : Rmmigrate_Crypto_Core::V2_ITERATIONS;
        $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), (string) $salt, (int) $iterations);

        $header_size = Rmmigrate_Crypto_Core::header_size_v2();
        if ($byte_offset < $header_size) {
            $byte_offset = $header_size;
        }
        $in->seek($byte_offset);

        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($out === false) {
            $in->close();
            return null;
        }

        $start = microtime(true);
        $failed = false;
        while ((microtime(true) - $start) < $budget_sec) {
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                break;
            }
            $len = unpack('N', $len_header)[1];
            $blob = $in->read($len);
            if ($blob === false || strlen($blob) < $len) {
                break;
            }
            $plain = Rmmigrate_Crypto_Core::v2_open($blob, $key);
            if ($plain === false) {
                $failed = true;
                break;
            }
            $out->write($plain);
            $byte_offset = (int) $in->tell();
        }

        $complete = !$failed && ($in->eof() || $byte_offset >= Rmmigrate_Filesystem::filesize($source));
        $in->close();
        $out->close();

        if ($failed) {
            return null;
        }

        return array(
            'done'        => $complete,
            'byte_offset' => $byte_offset,
        );
    }

    /**
     * Legacy resumable v1 decrypt (AES-256-CBC, IV-chained).
     *
     * @return array{done:bool,byte_offset:int}|null
     */
    private static function decrypt_slice_v1(string $source, string $dest, int $byte_offset, int $budget_sec): ?array
    {
        if ($byte_offset === 0 && Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
        }

        $key = self::archive_key_v1();
        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            return null;
        }

        if ($byte_offset === 0) {
            $magic_len = strlen(self::MAGIC);
            $magic = $in->read($magic_len);
            if ($magic !== self::MAGIC) {
                $in->close();
                return null;
            }
            $iv = $in->read(16);
            $byte_offset = $magic_len + 16;
        } else {
            $in->seek($byte_offset);
            $iv = $in->read(16);
        }

        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($out === false) {
            $in->close();
            return null;
        }

        $start = microtime(true);
        while ((microtime(true) - $start) < $budget_sec) {
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                break;
            }
            $len = unpack('N', $len_header)[1];
            $encrypted = $in->read($len);
            if ($encrypted === false || strlen($encrypted) < $len) {
                break;
            }
            $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($plain === false) {
                break;
            }
            $out->write($plain);
            $iv = substr(hash('sha256', $iv . $encrypted, true), 0, 16);
            $byte_offset = (int) $in->tell();
        }

        $complete = $in->eof() || $byte_offset >= Rmmigrate_Filesystem::filesize($source);
        $in->close();
        $out->close();

        return array(
            'done'        => $complete,
            'byte_offset' => $byte_offset,
        );
    }

    public static function decrypt_file(string $source, string $dest): bool
    {
        self::load_crypto_core();
        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if (Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
        }
        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($in === false || $out === false) {
            return false;
        }

        $magic = $in->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
        if ($magic === Rmmigrate_Crypto_Core::MAGIC_V2) {
            $salt = $in->read(Rmmigrate_Crypto_Core::V2_SALT_LEN);
            $iter_header = $in->read(4);
            $iterations = (strlen((string) $iter_header) === 4) ? unpack('N', $iter_header)[1] : Rmmigrate_Crypto_Core::V2_ITERATIONS;
            $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), (string) $salt, (int) $iterations);
            $ok = self::stream_decrypt_v2($in, $out, $key, 0);
            $in->close();
            $out->close();
            return $ok;
        }
        if ($magic === Rmmigrate_Crypto_Core::MAGIC_V1) {
            $ok = self::stream_decrypt_v1($in, $out, self::archive_key_v1());
            $in->close();
            $out->close();
            return $ok;
        }

        $in->close();
        $out->close();
        return false;
    }

    /**
     * v2 chunk loop. Reads from $in starting at the current position; budget 0
     * means run to completion. Returns true on success / clean completion.
     *
     * @param Rmmigrate_Filesystem_Stream $in
     * @param Rmmigrate_Filesystem_Stream $out
     */
    private static function stream_decrypt_v2($in, $out, string $key, int $budget_sec): bool
    {
        $start = microtime(true);
        while (!$in->eof()) {
            if ($budget_sec > 0 && (microtime(true) - $start) >= $budget_sec) {
                return true;
            }
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                break;
            }
            $len = unpack('N', $len_header)[1];
            $blob = $in->read($len);
            if ($blob === false || strlen($blob) < $len) {
                break;
            }
            $plain = Rmmigrate_Crypto_Core::v2_open($blob, $key);
            if ($plain === false) {
                return false;
            }
            $out->write($plain);
        }
        return true;
    }

    /**
     * Legacy v1 chunk loop (AES-256-CBC, IV-chained). $in is positioned right
     * after the 8-byte magic.
     *
     * @param Rmmigrate_Filesystem_Stream $in
     * @param Rmmigrate_Filesystem_Stream $out
     */
    private static function stream_decrypt_v1($in, $out, string $key): bool
    {
        $iv = $in->read(16);
        while (!$in->eof()) {
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                break;
            }
            $len = unpack('N', $len_header)[1];
            $encrypted = $in->read($len);
            if ($encrypted === false || strlen($encrypted) < $len) {
                break;
            }
            $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($plain === false) {
                return false;
            }
            $out->write($plain);
            $iv = substr(hash('sha256', $iv . $encrypted, true), 0, 16);
        }
        return true;
    }

    private static function load_crypto_core(): void
    {
        if (!class_exists('Rmmigrate_Crypto_Core')) {
            require_once __DIR__ . '/shared/crypto-core.php';
        }
    }

    /**
     * Raw key-derivation secret for v2. Empty passphrase => public constant
     * (see Rmmigrate_Crypto_Core::v2_secret).
     */
    private static function archive_secret(): string
    {
        self::load_crypto_core();
        $pass = self::$runtime_passphrase ?? '';
        if ($pass === '') {
            $pass = Rmmigrate_Settings::get_archive_passphrase();
        }
        return Rmmigrate_Crypto_Core::v2_secret((string) $pass);
    }

    /**
     * Legacy v1 key (decryption of pre-v2 archives only).
     */
    private static function archive_key_v1(): string
    {
        self::load_crypto_core();
        $pass = self::$runtime_passphrase ?? '';
        if ($pass === '') {
            $pass = Rmmigrate_Settings::get_archive_passphrase();
        }
        return Rmmigrate_Crypto_Core::archive_key((string) $pass);
    }
}
