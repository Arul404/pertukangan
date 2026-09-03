<?php

namespace Modules\TukangKirim\Libraries;

/**
 * Pembaca placeholder bergaya [nama] di dalam body template.
 *
 * Placeholder `[password]` bersifat reserved: nilainya di-generate otomatis
 * oleh PasswordGenerator dan tidak pernah diminta ke operator. Placeholder
 * lain menjadi input manual yang dirender dinamis di form kirim.
 */
class PlaceholderParser
{
    /**
     * Placeholder yang diisi sistem, bukan oleh operator.
     *
     * @var list<string>
     */
    public const RESERVED = ['password'];

    /**
     * Pola nama placeholder yang dikenali: huruf, angka, underscore.
     */
    protected const PATTERN = '/\[([a-zA-Z0-9_]+)\]/';

    /**
     * Semua placeholder di dalam body, unik dan urut kemunculan.
     *
     * @return list<string>
     */
    public function extract(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        $names = array_map(static fn (string $n): string => strtolower($n), $matches[1] ?? []);

        return array_values(array_unique($names));
    }

    /**
     * Placeholder yang harus diisi manual oleh operator.
     *
     * @return list<string>
     */
    public function manual(string $body): array
    {
        return array_values(array_filter(
            $this->extract($body),
            fn (string $name): bool => ! $this->isReserved($name),
        ));
    }

    /**
     * Placeholder yang nilainya dibangkitkan sistem.
     *
     * @return list<string>
     */
    public function reserved(string $body): array
    {
        return array_values(array_filter(
            $this->extract($body),
            fn (string $name): bool => $this->isReserved($name),
        ));
    }

    public function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED, true);
    }

    public function hasPassword(string $body): bool
    {
        return in_array('password', $this->extract($body), true);
    }

    /**
     * Ganti setiap [nama] dengan nilainya. Placeholder yang tidak punya nilai
     * dibiarkan apa adanya agar kesalahan terlihat jelas saat preview.
     *
     * @param array<string, string> $values
     */
    public function render(string $body, array $values): string
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[strtolower($key)] = (string) $value;
        }

        return preg_replace_callback(
            self::PATTERN,
            static function (array $m) use ($normalized): string {
                $key = strtolower($m[1]);

                return array_key_exists($key, $normalized) ? $normalized[$key] : $m[0];
            },
            $body,
        ) ?? $body;
    }

    /**
     * Ubah nama placeholder menjadi label yang enak dibaca: nomor_hp -> Nomor Hp.
     */
    public function label(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
