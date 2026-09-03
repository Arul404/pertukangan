<?php

namespace Modules\TukangKirim\Libraries;

use InvalidArgumentException;
use Modules\TukangKirim\Config\PasswordPolicy;

/**
 * Pembuat password acak untuk placeholder [password].
 *
 * Jaminan: panjang minimal 10 karakter (default 12) dan selalu mengandung
 * sedikitnya 1 huruf kapital, 1 huruf kecil, 1 angka, dan 1 karakter spesial.
 * Seluruh pengacakan memakai random_int() (CSPRNG), bukan rand()/shuffle().
 */
class PasswordGenerator
{
    protected PasswordPolicy $policy;

    public function __construct(?PasswordPolicy $policy = null)
    {
        $this->policy = $policy ?? config(PasswordPolicy::class);
    }

    /**
     * Hasilkan satu password acak.
     *
     * @param int|null $length Panjang yang diinginkan; null = ikut konfigurasi.
     */
    public function generate(?int $length = null): string
    {
        $length = $length ?? $this->policy->length;

        if ($length < PasswordPolicy::MIN_LENGTH) {
            $length = PasswordPolicy::MIN_LENGTH;
        }

        $sets = $this->sets();
        $all  = implode('', $sets);

        if ($all === '') {
            throw new InvalidArgumentException('PasswordPolicy tidak boleh memiliki set karakter kosong.');
        }

        // Satu karakter wajib dari tiap set, sisanya bebas dari gabungan set.
        $chars = [];

        foreach ($sets as $set) {
            $chars[] = $this->pick($set);
        }

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $this->pick($all);
        }

        return implode('', $this->shuffle($chars));
    }

    /**
     * Cek apakah sebuah password memenuhi seluruh syarat kebijakan.
     */
    public function validate(string $password): bool
    {
        if (strlen($password) < PasswordPolicy::MIN_LENGTH) {
            return false;
        }

        foreach ($this->sets() as $set) {
            if (strcspn($password, $set) === strlen($password)) {
                return false; // tidak ada satu pun karakter dari set ini
            }
        }

        return true;
    }

    /**
     * @return list<string> Set karakter wajib, masing-masing harus terwakili.
     */
    protected function sets(): array
    {
        return array_values(array_filter([
            $this->policy->upper,
            $this->policy->lower,
            $this->policy->digits,
            $this->policy->special,
        ], static fn (string $set): bool => $set !== ''));
    }

    /**
     * Ambil satu karakter acak dari sebuah set memakai CSPRNG.
     */
    protected function pick(string $set): string
    {
        return $set[random_int(0, strlen($set) - 1)];
    }

    /**
     * Fisher-Yates memakai random_int(); shuffle() bawaan PHP tidak aman kripto.
     *
     * @param list<string> $chars
     *
     * @return list<string>
     */
    protected function shuffle(array $chars): array
    {
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return $chars;
    }
}
