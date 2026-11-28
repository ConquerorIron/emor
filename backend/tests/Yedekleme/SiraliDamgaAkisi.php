<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

/**
 * Yayın damgasını okuma sırasına göre değiştiren akış (`siralidamga://`):
 * n. okuma `$icerikler[n]`'yi döndürür, sonrakiler sonuncuyu. Okumalar arasına
 * kanca koymadan "yayın, damga okunduktan hemen sonra yeni damgayı yazdı"
 * sırasını kurar (Codex G1-8-R2).
 */
final class SiraliDamgaAkisi
{
    /** @var list<string> */
    public static array $icerikler = [];

    public static int $okuma = 0;

    /** @var resource|null PHP akış sarmalayıcısı sözleşmesi */
    public $context;

    private string $veri = '';

    private int $konum = 0;

    public static function kur(string ...$icerikler): string
    {
        self::$icerikler = array_values($icerikler);
        self::$okuma = 0;
        if (! in_array('siralidamga', stream_get_wrappers(), true)) {
            stream_wrapper_register('siralidamga', self::class);
        }

        return 'siralidamga://damga';
    }

    public static function kaldir(): void
    {
        if (in_array('siralidamga', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('siralidamga');
        }
    }

    public function stream_open(string $yol, string $kip, int $secenekler, ?string &$acilan): bool
    {
        $son = count(self::$icerikler) - 1;
        $this->veri = self::$icerikler[min(self::$okuma, $son)] ?? '';
        self::$okuma++;

        return true;
    }

    public function stream_read(int $adet): string
    {
        $parca = substr($this->veri, $this->konum, $adet);
        $this->konum += strlen($parca);

        return $parca;
    }

    public function stream_eof(): bool
    {
        return $this->konum >= strlen($this->veri);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->veri)];
    }

    public function stream_seek(int $konum, int $nereden): bool
    {
        $this->konum = $konum;

        return true;
    }
}
