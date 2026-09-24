<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\AlarmBildirimi;
use App\Models\AlarmKurali;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * e-Fatura alarm maili (EFAT-13). Kuyruk işi (AlarmBildirimiGonder) senkron
 * gönderir; mailable kendisi kuyruklanmaz.
 *
 * İçerikte fatura satırı, unvan ya da VKN yoktur: yalnız adet, tutar toplamı,
 * tarih ve hata kodu. Ayrıntı için yetkili ekrana bağlantı verilir. Konu ve
 * gövde hesabın ortamını (Test/Canlı) taşır.
 */
final class AlarmMaili extends Mailable
{
    /** @var list<string> */
    public array $satirlar;

    public string $baslik;

    public string $baglanti;

    public string $baglantiMetni;

    public function __construct(
        AlarmBildirimi $bildirim,
        string $kuralTuru,
        public readonly string $ortam,
    ) {
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');
        $ayrinti = $bildirim->ayrinti;
        $zaman = fn (?string $iso): string => $iso === null
            ? __('mail.alarm.yok')
            : CarbonImmutable::parse($iso)->setTimezone($saatDilimi)->format('d.m.Y H:i');

        [$this->baslik, $this->satirlar, $yol] = match ($kuralTuru) {
            AlarmKurali::SENKRON_ARIZASI => [
                __('mail.alarm.senkron_'.$bildirim->tur.'_baslik', ['yon' => __('mail.alarm.yon_'.$ayrinti['yon'])]),
                $bildirim->tur === AlarmBildirimi::TUR_ACILDI
                    ? [
                        __('mail.alarm.senkron_ardisik', ['sayi' => $ayrinti['ardisik_hata']]),
                        __('mail.alarm.senkron_veri_zamani', ['zaman' => $zaman($ayrinti['veri_zamani'])]),
                        __('mail.alarm.senkron_son_hata', ['kod' => $ayrinti['son_hata'] ?? __('mail.alarm.yok')]),
                        __('mail.alarm.senkron_etki'),
                    ]
                    : [__('mail.alarm.senkron_cozuldu', ['zaman' => $zaman($ayrinti['veri_zamani'])])],
                '/efatura/'.$ayrinti['yon'],
            ],
            AlarmKurali::GUNLUK_OZET => [
                __('mail.alarm.ozet_baslik', ['gun' => CarbonImmutable::parse($ayrinti['gun'])->format('d.m.Y')]),
                $this->ozetSatirlari($ayrinti),
                '/efatura/gelen',
            ],
            default => [
                __('mail.alarm.erp_baslik', ['adet' => $ayrinti['yeni_adet']]),
                [
                    __('mail.alarm.erp_yeni', ['adet' => $ayrinti['yeni_adet'], 'gun' => $ayrinti['esik_gun']]),
                    __('mail.alarm.erp_acik', ['adet' => $ayrinti['acik_adet']]),
                    __('mail.alarm.erp_not'),
                ],
                '/efatura/gelen?erp_okundu=hayir',
            ],
        };

        $this->baglanti = rtrim((string) config('app.url'), '/').$yol;
        $this->baglantiMetni = __('mail.alarm.ekrani_ac');
    }

    public function envelope(): Envelope
    {
        $onEk = $this->ortam === 'test' ? '[TEST] ' : '';

        return new Envelope(subject: $onEk.'eMOR ERP — '.$this->baslik);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alarm');
    }

    /**
     * @param  array<string, mixed>  $ayrinti
     * @return list<string>
     */
    private function ozetSatirlari(array $ayrinti): array
    {
        $satirlar = ($ayrinti['ilk_tarama'] ?? false) === true ? [__('mail.alarm.ozet_ilk_tarama')] : [];

        /** @var array<string, array{adet: int, para_birimleri: list<array{para_birimi: string, adet: int, tutar: string}>, guncel: bool}> $yonler */
        $yonler = $ayrinti['yonler'];

        foreach ($yonler as $yon => $d) {
            $tutarlar = array_map(
                fn (array $p): string => $this->tutarGoster($p['tutar']).' '.$p['para_birimi'],
                $d['para_birimleri'],
            );
            $satirlar[] = __('mail.alarm.ozet_yon', [
                'yon' => __('mail.alarm.yon_'.$yon),
                'adet' => $d['adet'],
                'tutarlar' => $tutarlar === [] ? '—' : implode(', ', $tutarlar),
            ]);

            if (! $d['guncel']) {
                $satirlar[] = __('mail.alarm.ozet_guncel_degil', ['yon' => __('mail.alarm.yon_'.$yon)]);
            }
        }

        $satirlar[] = __('mail.alarm.ozet_not');

        return $satirlar;
    }

    /** PostgreSQL numeric toplamı para hassasiyetini kaybetmeden gösterir. */
    private function tutarGoster(string $tutar): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $tutar, $parca) !== 1) {
            return $tutar;
        }

        $kurus = str_pad(substr($parca[3] ?? '', 0, 2), 2, '0');
        $tam = ltrim($parca[2], '0');
        $tam = $tam === '' ? '0' : $tam;

        return $parca[1].preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $tam).','.$kurus;
    }
}
