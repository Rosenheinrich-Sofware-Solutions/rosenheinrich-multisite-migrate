<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rmmigrate_Archive_Encryption
{
    const EXT = '.venc';

    /** Plaintext bytes read per encrypt_slice chunk; also used for plain_offset math. */
    const PLAIN_CHUNK_SIZE = 1048576;

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
        $iterations = Rmmigrate_Crypto_Core::seal_v2_iterations(Rmmigrate_Crypto_Core::V2_ITERATIONS);
        $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), $salt, $iterations);

        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            return false;
        }
        if (Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
        }
        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($out === false) {
            $in->close();
            return false;
        }
        $out->write(Rmmigrate_Crypto_Core::v2_header($salt, $iterations));

        $chunk_index = 0;
        while (!$in->eof()) {
            $chunk = $in->read(self::PLAIN_CHUNK_SIZE);
            if ($chunk === false) {
                $in->close();
                $out->close();
                return false;
            }
            if ($chunk === '') {
                break;
            }
            $aad = Rmmigrate_Crypto_Core::v2_chunk_aad(
                $chunk_index,
                0,
                $chunk_index === 0 ? $salt : '',
                $chunk_index === 0 ? $iterations : 0
            );
            $blob = Rmmigrate_Crypto_Core::v2_seal($chunk, $key, $aad);
            if ($blob === '') {
                $in->close();
                $out->close();
                return false;
            }
            $out->write(pack('N', strlen($blob)));
            $out->write($blob);
            $chunk_index++;
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
            $iterations = Rmmigrate_Crypto_Core::seal_v2_iterations(Rmmigrate_Crypto_Core::V2_ITERATIONS);
            $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), $salt, $iterations);
            $out = Rmmigrate_Filesystem::open($dest, 'ab');
            if ($out === false) {
                return null;
            }
            $out->write(Rmmigrate_Crypto_Core::v2_header($salt, $iterations));
            $chunk_index = 0;
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
                ? (int) unpack('N', $iter_header)[1]
                : Rmmigrate_Crypto_Core::V2_ITERATIONS;
            if (!Rmmigrate_Crypto_Core::iterations_in_range($iterations)) {
                return null;
            }
            $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), (string) $salt, $iterations);
            $expected_offset = self::v2_offset_after_n_chunks(
                $dest,
                self::expected_encrypt_chunk_count($plain_offset)
            );
            $dest_size = (int) Rmmigrate_Filesystem::filesize($dest);
            $tail_meta = self::v2_chunks_before_offset($dest, $dest_size);
            $truncate_to = min($expected_offset, $tail_meta['offset']);
            if ($truncate_to < $dest_size) {
                RMMIGRATE_IO::truncate_file($dest, $truncate_to);
            }
            $out = Rmmigrate_Filesystem::open($dest, 'ab');
            if ($out === false) {
                return null;
            }
            $chunk_index = self::count_v2_chunks_before_offset($dest, $truncate_to);
            $plain_offset = $chunk_index * self::PLAIN_CHUNK_SIZE;
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
            $chunk = $in->read(self::PLAIN_CHUNK_SIZE);
            if ($chunk === false) {
                $in->close();
                $out->close();
                return null;
            }
            if ($chunk === '') {
                break;
            }
            $aad = Rmmigrate_Crypto_Core::v2_chunk_aad(
                $chunk_index,
                0,
                $chunk_index === 0 ? (string) $salt : '',
                $chunk_index === 0 ? (int) $iterations : 0
            );
            $blob = Rmmigrate_Crypto_Core::v2_seal($chunk, $key, $aad);
            if ($blob === '') {
                $in->close();
                $out->close();
                return null;
            }
            $out->write(pack('N', strlen($blob)));
            $out->write($blob);
            $plain_offset = (int) $in->tell();
            $chunk_index++;
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
     * @return array{done:bool,byte_offset:int,plain_bytes:int}|null
     */
    public static function decrypt_slice(
        string $source,
        string $dest,
        int $byte_offset,
        int $budget_sec,
        int $plain_bytes = -1
    ): ?array {
        self::load_crypto_core();
        $probe = Rmmigrate_Filesystem::open($source, 'rb');
        if ($probe === false) {
            return null;
        }
        $magic = $probe->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
        $probe->close();

        if ($magic === Rmmigrate_Crypto_Core::MAGIC_V2) {
            return self::decrypt_slice_v2($source, $dest, $byte_offset, $budget_sec, $plain_bytes);
        }

        return self::decrypt_slice_v1($source, $dest, $byte_offset, $budget_sec);
    }

    /**
     * Resumable v2 decrypt. v2 chunks are self-contained (per-chunk nonce + GCM
     * tag), so a slice can resume at any chunk boundary; the salt/key are always
     * re-derived from the header.
     *
     * @return array{done:bool,byte_offset:int,plain_bytes:int}|null
     */
    private static function decrypt_slice_v2(
        string $source,
        string $dest,
        int $byte_offset,
        int $budget_sec,
        int $plain_bytes = -1
    ): ?array {
        if ($byte_offset === 0 && Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
            $plain_bytes = -1;
        }

        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            return null;
        }
        $in->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
        $salt = $in->read(Rmmigrate_Crypto_Core::V2_SALT_LEN);
        $iter_header = $in->read(4);
        $iterations = (strlen((string) $iter_header) === 4) ? (int) unpack('N', $iter_header)[1] : Rmmigrate_Crypto_Core::V2_ITERATIONS;
        if (!Rmmigrate_Crypto_Core::iterations_in_range($iterations)) {
            $in->close();
            return null;
        }
        $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), (string) $salt, $iterations);

        $header_size = Rmmigrate_Crypto_Core::header_size_v2();
        if ($byte_offset < $header_size) {
            $byte_offset = $header_size;
        }

        if ($byte_offset > $header_size && Rmmigrate_Filesystem::exists($dest)) {
            $expected_plain = $plain_bytes >= 0
                ? $plain_bytes
                : self::v2_plain_bytes_before_source_offset(
                    $source,
                    $byte_offset,
                    $key,
                    (string) $salt,
                    (int) $iterations
                );
            if ($expected_plain === null) {
                $in->close();
                return null;
            }
            $dest_size = (int) Rmmigrate_Filesystem::filesize($dest);
            if ($dest_size > $expected_plain) {
                RMMIGRATE_IO::truncate_file($dest, $expected_plain);
            }
        }

        $in->seek($byte_offset);

        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($out === false) {
            $in->close();
            return null;
        }

        $start = microtime(true);
        $failed = false;
        $chunk_index = self::count_v2_chunks_before_offset($source, $byte_offset);
        $first_pass = true;
        while ($first_pass || (microtime(true) - $start) < $budget_sec) {
            $first_pass = false;
            $len_header = $in->read(4);
            if ($len_header === false || $len_header === '') {
                break;
            }
            if (strlen($len_header) < 4) {
                $failed = true;
                break;
            }
            $len = unpack('N', $len_header)[1];
            $blob = $in->read($len);
            if ($blob === false || strlen($blob) < $len) {
                $failed = true;
                break;
            }
            $aad = Rmmigrate_Crypto_Core::v2_chunk_aad(
                $chunk_index,
                0,
                $chunk_index === 0 ? (string) $salt : '',
                $chunk_index === 0 ? (int) $iterations : 0
            );
            $plain = Rmmigrate_Crypto_Core::v2_open_with_legacy($blob, $key, $aad);
            if ($plain === false) {
                $failed = true;
                break;
            }
            $out->write($plain);
            $byte_offset = (int) $in->tell();
            $chunk_index++;
        }

        $complete = !$failed && ($in->eof() || $byte_offset >= Rmmigrate_Filesystem::filesize($source));
        $in->close();
        $out->close();

        if ($failed) {
            return null;
        }

        $plain_size = Rmmigrate_Filesystem::exists($dest) ? (int) Rmmigrate_Filesystem::filesize($dest) : 0;

        return array(
            'done'        => $complete,
            'byte_offset' => $byte_offset,
            'plain_bytes' => $plain_size,
        );
    }

    /**
     * Legacy resumable v1 decrypt (AES-256-CBC, IV-chained).
     *
     * @return array{done:bool,byte_offset:int,plain_bytes:int}|null
     */
    private static function decrypt_slice_v1(string $source, string $dest, int $byte_offset, int $budget_sec): ?array
    {
        unset($budget_sec);
        if ($byte_offset > 0) {
            return null;
        }
        if (!self::decrypt_file($source, $dest)) {
            return null;
        }
        $size = Rmmigrate_Filesystem::filesize($source);

        return array(
            'done'        => true,
            'byte_offset' => $size > 0 ? $size : 0,
            'plain_bytes' => Rmmigrate_Filesystem::exists($dest) ? (int) Rmmigrate_Filesystem::filesize($dest) : 0,
        );
    }

    public static function decrypt_file(string $source, string $dest): bool
    {
        self::load_crypto_core();
        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            return false;
        }
        if (Rmmigrate_Filesystem::exists($dest)) {
            Rmmigrate_Filesystem::delete($dest);
        }
        $out = Rmmigrate_Filesystem::open($dest, 'ab');
        if ($out === false) {
            $in->close();
            return false;
        }

        $magic = $in->read(strlen(Rmmigrate_Crypto_Core::MAGIC_V2));
        if ($magic === Rmmigrate_Crypto_Core::MAGIC_V2) {
            $salt = $in->read(Rmmigrate_Crypto_Core::V2_SALT_LEN);
            $iter_header = $in->read(4);
            $iterations = (strlen((string) $iter_header) === 4) ? (int) unpack('N', $iter_header)[1] : Rmmigrate_Crypto_Core::V2_ITERATIONS;
            if (!Rmmigrate_Crypto_Core::iterations_in_range($iterations)) {
                $in->close();
                $out->close();
                return false;
            }
            $key = Rmmigrate_Crypto_Core::derive_v2_key(self::archive_secret(), (string) $salt, $iterations);
            $ok = self::stream_decrypt_v2($in, $out, $key, 0, (string) $salt, $iterations);
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
     * means run to completion. Returns true on full success; false on failure or budget expiry.
     *
     * @param Rmmigrate_Filesystem_Stream $in
     * @param Rmmigrate_Filesystem_Stream $out
     */
    private static function stream_decrypt_v2($in, $out, string $key, int $budget_sec, string $salt = '', int $iterations = 0, int $chunk_index = 0): bool
    {
        $start = microtime(true);
        while (!$in->eof()) {
            if ($budget_sec > 0 && (microtime(true) - $start) >= $budget_sec) {
                return false;
            }
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                return $in->eof();
            }
            $len = unpack('N', $len_header)[1];
            $blob = $in->read($len);
            if ($blob === false || strlen($blob) < $len) {
                return false;
            }
            $aad = Rmmigrate_Crypto_Core::v2_chunk_aad(
                $chunk_index,
                0,
                $chunk_index === 0 ? $salt : '',
                $chunk_index === 0 ? $iterations : 0
            );
            $plain = Rmmigrate_Crypto_Core::v2_open_with_legacy($blob, $key, $aad);
            if ($plain === false) {
                return false;
            }
            $out->write($plain);
            $chunk_index++;
        }
        return true;
    }

    private static function expected_encrypt_chunk_count(int $plain_offset): int
    {
        if ($plain_offset <= 0) {
            return 0;
        }

        $count = 0;
        $remaining = $plain_offset;
        while ($remaining > 0) {
            $remaining -= min(self::PLAIN_CHUNK_SIZE, $remaining);
            $count++;
        }

        return $count;
    }

    private static function v2_offset_after_n_chunks(string $path, int $chunk_count): int
    {
        $header_size = Rmmigrate_Crypto_Core::header_size_v2();
        if ($chunk_count <= 0) {
            return $header_size;
        }

        $in = Rmmigrate_Filesystem::open($path, 'rb');
        if ($in === false) {
            return $header_size;
        }
        $in->seek($header_size);

        $offset = $header_size;
        $count = 0;
        while ($count < $chunk_count && !$in->eof()) {
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                break;
            }
            $len = (int) unpack('N', $len_header)[1];
            $in->seek($len, SEEK_CUR);
            $offset = (int) $in->tell();
            $count++;
        }
        $in->close();

        return $offset;
    }

    /**
     * @return int|null Plain bytes confirmed before $byte_offset, or null on failure.
     */
    private static function v2_plain_bytes_before_source_offset(
        string $source,
        int $byte_offset,
        string $key,
        string $salt,
        int $iterations
    ): ?int {
        $header_size = Rmmigrate_Crypto_Core::header_size_v2();
        if ($byte_offset <= $header_size) {
            return 0;
        }

        $in = Rmmigrate_Filesystem::open($source, 'rb');
        if ($in === false) {
            return null;
        }
        $in->seek($header_size);

        $plain_bytes = 0;
        $chunk_index = 0;
        $offset = $header_size;
        while ($offset < $byte_offset) {
            $chunk_start = $offset;
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                $in->close();
                return null;
            }
            $len = (int) unpack('N', $len_header)[1];
            if ($chunk_start + 4 + $len > $byte_offset) {
                break;
            }
            $blob = $in->read($len);
            if ($blob === false || strlen($blob) < $len) {
                $in->close();
                return null;
            }
            $aad = Rmmigrate_Crypto_Core::v2_chunk_aad(
                $chunk_index,
                0,
                $chunk_index === 0 ? $salt : '',
                $chunk_index === 0 ? $iterations : 0
            );
            $plain = Rmmigrate_Crypto_Core::v2_open_with_legacy($blob, $key, $aad);
            if ($plain === false) {
                $in->close();
                return null;
            }
            $plain_bytes += strlen($plain);
            $offset = $chunk_start + 4 + $len;
            $chunk_index++;
        }
        $in->close();

        return $plain_bytes;
    }

    /**
     * @return array{count:int,offset:int}
     */
    private static function v2_chunks_before_offset(string $path, int $byte_offset): array
    {
        $header_size = Rmmigrate_Crypto_Core::header_size_v2();
        if ($byte_offset <= $header_size) {
            return array('count' => 0, 'offset' => $header_size);
        }

        $in = Rmmigrate_Filesystem::open($path, 'rb');
        if ($in === false) {
            return array('count' => 0, 'offset' => $header_size);
        }
        $in->seek($header_size);

        $count = 0;
        $offset = $header_size;
        while ($offset < $byte_offset && !$in->eof()) {
            $chunk_start = $offset;
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                break;
            }
            $len = (int) unpack('N', $len_header)[1];
            $chunk_end = $chunk_start + 4 + $len;
            if ($chunk_end > $byte_offset) {
                break;
            }
            $in->seek($len, SEEK_CUR);
            $offset = $chunk_end;
            $count++;
        }
        $in->close();

        return array('count' => $count, 'offset' => $offset);
    }

    private static function count_v2_chunks_before_offset(string $path, int $byte_offset): int
    {
        return self::v2_chunks_before_offset($path, $byte_offset)['count'];
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
        if ($iv === false || strlen($iv) < 16) {
            return false;
        }
        while (!$in->eof()) {
            $len_header = $in->read(4);
            if ($len_header === false || strlen($len_header) < 4) {
                return $in->eof();
            }
            $len = unpack('N', $len_header)[1];
            $encrypted = $in->read($len);
            if ($encrypted === false || strlen($encrypted) < $len) {
                return false;
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
