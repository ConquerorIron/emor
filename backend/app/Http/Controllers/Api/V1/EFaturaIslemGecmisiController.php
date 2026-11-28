<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaIslemGecmisiRequest;
use App\Http\Resources\EFaturaIslemiResource;
use App\Models\EFaturaIslemi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * İşlem Geçmişi ekranı (EK-03): e-Fatura ekranlarında yapılan elle işlemler
 * (RED, İşlendi, gizleme, içe aktarım), en yeni üstte. Tarih aralığı İstanbul
 * gününe göredir (kayıt zamanı UTC saklanır).
 */
final class EFaturaIslemGecmisiController extends Controller
{
    public function index(EFaturaIslemGecmisiRequest $request): JsonResponse
    {
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');
        $baslangic = CarbonImmutable::parse((string) $request->validated('baslangic'), $saatDilimi)->startOfDay()->utc();
        $bitis = CarbonImmutable::parse((string) $request->validated('bitis'), $saatDilimi)->endOfDay()->utc();

        $sorgu = EFaturaIslemi::query()
            ->whereBetween('created_at', [$baslangic, $bitis])
            ->when($request->validated('kullanici_id') !== null, fn (Builder $q) => $q->where('kullanici_id', $request->integer('kullanici_id')))
            ->when($request->validated('islem') !== null, fn (Builder $q) => $q->where('islem', $request->validated('islem')))
            ->when($request->validated('saglayici') !== null, fn (Builder $q) => $q->where('saglayici', $request->validated('saglayici')))
            ->when(trim((string) $request->validated('ara')) !== '', function (Builder $q) use ($request): void {
                $desen = '%'.trim((string) $request->validated('ara')).'%';
                // Belge kimliğinde aranmaz: eski e-Arşiv / e-SMM kayıtlarında kimlik no var (inceleme B-12)
                $q->where(fn (Builder $q) => $q->whereLike('belge_no', $desen)->orWhereLike('neden', $desen));
            });

        $sayfa = $sorgu->clone()->orderByDesc('created_at')->orderByDesc('id')->paginate($request->sayfaBoyutu());

        return EFaturaIslemiResource::collection($sayfa)
            ->additional([
                // Kullanıcı süzgecinin seçenekleri: geçmişte işlem yapmış kullanıcılar
                'secenekler' => [
                    'kullanicilar' => EFaturaIslemi::query()
                        ->whereNotNull('kullanici_id')
                        ->selectRaw('kullanici_id, max(kullanici_adi) as kullanici_adi')
                        ->groupBy('kullanici_id')
                        ->orderBy('kullanici_adi')
                        ->toBase()
                        ->get()
                        ->map(fn (object $s): array => ['id' => (int) $s->kullanici_id, 'ad' => (string) $s->kullanici_adi])
                        ->all(),
                ],
            ])
            ->response();
    }
}
