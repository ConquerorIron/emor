# eFatura Modülü (İzibiz) — Görev Panosu

## Amaç

Firmamızın gelen ve giden faturalarını hem ERP'den hem entegratörden (İzibiz)
okuyup karşılaştırmak, görüntülemek, raporlamak ve tanımlı kurallara göre
mail alarmı göndermek.

Tam ve aynı kapsamdaki iki kaynak okumasından sonra mutabakat sonuçları:

| Sonuç | Anlamı | Örnek |
|---|---|---|
| Eşleşti | İki tarafta da var | Normal durum |
| Yalnız ERP'de | Doğrulanan sorgu kapsamında yalnız ERP'de bulundu | Gönderim gecikmesi veya kapsam farkı araştırılır |
| Yalnız entegratörde | Doğrulanan sorgu kapsamında yalnız entegratörde bulundu | ERP'ye işlenme gecikmesi araştırılır |
| Farklı (opsiyonel) | İki tarafta var ama tutar/tarih/VKN tutmuyor | Yanlış işlenmiş fatura |
| İnceleme gerekli | Kimlik eksik, mükerrer veya birden fazla eşleşme adayı var | Otomatik eşleşme yapılmaz |

API kesintisi, eksik sayfa veya kapsam uyuşmazlığı fatura kategorisi değildir:
çalışma `eksik/başarısız` olur ve yokluk alarmı üretmez. Entegratörde bulunmak,
GİB'e başarıyla iletilmek ve alıcı tarafından kabul edilmek ayrı durumlardır.
İlk sürüm listeleme, mutabakat, raporlama ve alarm kapsamındadır; fatura
gönderme, kabul/red, iptal ve ERP'ye kayıt işlemi eklenmez.

## Mimari özet

```
ERP (MSSQL, seçilen SQL tanımı)        İzibiz REST API (eşlenen entegratör tanımı)
  └─ fatura sorgusu (kullanıcıdan)        └─ POST /v1/auth/token → Bearer token
         │                                       │ gelen / giden fatura listeleri
         ▼                                       ▼
   ErpFaturaKaynagi                      IzibizIstemcisi (token önbelleği)
         └──────────────► Mutabakat motoru ◄─────┘
                               │
               ┌───────────────┼────────────────┐
               ▼               ▼                ▼
          eFatura ekranları   Raporlar     Alarm kuralları → Mail (kuyruk)
```

- Bağlantı tanımları `sql_baglantilari` kalıbını izler; şirket/hesap sayısı
  ve ortam eşlemesi EFAT-15'te kararlaştırılmadan benzersizlik kapsamı sabitlenmez.
  Şifre `encrypted` cast'le saklanır. Kısmi unique indeks en fazla bir aktif
  tanımı garanti eder; hiç aktif tanım olmaması ayrıca ele alınır.
- İzibiz'de her istek token gerektirir. Token `POST {api_url}/v1/auth/token`
  ile alınır. `validity` saat dilimi eki taşımayan **İstanbul saatiyle** bitiş
  tarihidir; token ömrü **12 saat**; kimlik hatası HTTP 401 + `"10004"`
  (2026-09-23 test hesabında doğrulandı — [API notları](api-notlari.md)).
- API adresi veritabanında tutulmaz; `config/entegrator.php`'de ortamdan türetilir.
- Test API adresi `https://apitest.izibiz.com.tr`, canlı adres
  `https://api.izibiz.com.tr`.
- 2026-09-23 (Claude): kayıtlı test tanımıyla gerçek test API'sinden token
  alındı (EFAT-04/06). Bu, fatura listeleme uçlarının çalıştığının kanıtı
  değildir; fatura okuma yetkisi EFAT-07'de sınanır.
- İnceleme (2026-09-23, Codex): yerel dosyalar, bağlantı kodu ve İzibiz'in
  açık Postman dokümanı incelendi. Ön bulgular [API notlarında](api-notlari.md).

## Çalışma kuralı

- Görevler sırayla, tek tek yapılır. Her görev dosyasının başındaki
  **Durum** alanı güncellenir: `Yapılacak` → `Devam` → `Bitti`.
  Dış girdi beklenirken durum `Bekliyor` olur.
- Bir görev bittiğinde kabul kriterleri işaretlenir, "Sonuç" bölümüne
  yapılan değişiklik ve çalıştırılan kontroller yazılır.
- Kod görevleri kök `AGENTS.md`'deki skill ve doğrulama kurallarına uyar.
- Pano ile görev dosyasının durum/bağımlılıkları birlikte güncellenir.
  Görev numarası uygulama sırası değildir; aşağıdaki bağımlılıklar izlenir.
- Bu revizyon plan incelemesidir. Uygulama, import, canlı sınama ve yayın
  görevleri tamamlanmış sayılmaz. Yeni öneriler kullanıcı kararı yerine geçmez.

## Pano

| ID | Başlık | Durum | Bağımlılık |
|---|---|---|---|
| [EFAT-01](gorevler/EFAT-01-gizli-bilgi-korumasi.md) | Gizli bilgilerin repoya girmesini önle | Bitti | — |
| [EFAT-02](gorevler/EFAT-02-izibiz-api-analizi.md) | İzibiz fatura API dokümanı ve analizi | Bitti (faz 1) | — |
| [EFAT-03](gorevler/EFAT-03-entegrator-baglantilari-backend.md) | Entegratör bağlantıları: veri modeli + API | Bitti (lokal) — yayın bekliyor | EFAT-01, EFAT-15 bağlantı kararları (verildi) |
| [EFAT-04](gorevler/EFAT-04-izibiz-istemcisi.md) | İzibiz istemcisi: token yönetimi + bağlantı sınama | Bitti (lokal) — yayın bekliyor | EFAT-03 |
| [EFAT-05](gorevler/EFAT-05-entegrator-baglantilari-ekrani.md) | Entegratör Bağlantıları ekranı | Bitti (lokal) — yayın bekliyor | EFAT-04 |
| [EFAT-06](gorevler/EFAT-06-ilk-veri-importu.md) | Test tanımının tek seferlik importu | Bitti (lokal) — sunucu importu EFAT-14 | EFAT-03, EFAT-04, EFAT-05 |
| [EFAT-07](gorevler/EFAT-07-entegrator-fatura-listeleri.md) | Entegratörden gelen/giden e-Fatura listeleri (faz 1) | Bitti (lokal) | EFAT-02, EFAT-04 |
| [EFAT-08](gorevler/EFAT-08-erp-fatura-sorgusu.md) | ERP fatura sorgusu | Bekliyor (sorgu) | EFAT-15 |
| [EFAT-09](gorevler/EFAT-09-mutabakat-motoru.md) | Mutabakat (eşleştirme) motoru | Bekliyor | EFAT-07, EFAT-08 |
| [EFAT-10](gorevler/EFAT-10-senkron-ve-saklama.md) | Zamanlanmış çekme ve fatura özetlerinin saklanması (faz 1: 01.01.2026'dan) | Faz 1 bitti (lokal) | EFAT-07; mutabakat alanları EFAT-09 |
| [EFAT-11](gorevler/EFAT-11-efatura-ekranlari.md) | eFatura ekranları: liste, tarih aralığı, PDF, Excel (faz 1) | Faz 1 bitti (lokal) | EFAT-10, EFAT-18; mutabakat görünümü EFAT-09 |
| [EFAT-12](gorevler/EFAT-12-mail-altyapisi.md) | Mail altyapısı + SMTP tanımlama ekranı | Bitti (lokal) — gerçek SMTP teslimatı şifre girilince | — |
| [EFAT-13](gorevler/EFAT-13-alarm-kurallari.md) | Alarm kuralları ve bildirimler | Faz 1 bitti (lokal) — ERP kuralları ERP fazında | EFAT-10, EFAT-12 |
| [EFAT-14](gorevler/EFAT-14-canliya-cikis.md) | Canlıya çıkış | Bekliyor | EFAT-01…13, EFAT-15…17 |
| [EFAT-15](gorevler/EFAT-15-kapsam-ve-veri-sozlesmesi.md) | Şirket, ortam, veri ve eşleştirme kararları | Devam (S5/S9 verildi; veri sözleşmesi bekliyor) | — |
| [EFAT-16](gorevler/EFAT-16-sql-baglantilari-yetkisi.md) | Mevcut SQL Bağlantıları yetki açığı (öncelik yüksek, eFatura'dan bağımsız) | Bitti (lokal, inceleme tamamlandı) — yayın bekliyor | — |
| [EFAT-17](gorevler/EFAT-17-test-kabulu.md) | Test ortamında uçtan uca kabul | Devam — faz 1 otomatik kabul bitti (PostgreSQL dahil); elle kabul bekliyor | EFAT-01…13, EFAT-15, EFAT-16 |
| [EFAT-18](gorevler/EFAT-18-kullanici-ve-yetki.md) | Kullanıcı oluşturma ve yetkilendirme (rol tabanlı + lokal kullanıcı) | Bitti (lokal) | EFAT-16 |

## Faz 1 — İzibiz'den faturaları çek ve görüntüle (2026-09-23 kararı)

ERP entegrasyonu (EFAT-08/09) sonraya bırakıldı. Faz 1 kapsamı: yalnız
e-Fatura (gelen + giden), ilk tarama 01.01.2026, ekranlarda başlangıç–bitiş
tarihi seçimi, fatura özetlerinin PostgreSQL'de saklanması, PDF görüntüleme,
Excel çıktısı, yetkiyle erişim. Sıra:

1. EFAT-02 listeleme/PDF uçları (Postman export bekleniyor — S1).
2. EFAT-12 SMTP tanımlama ekranı ve EFAT-18 kullanıcı/yetki (EFAT-02'den bağımsız, paralel).
3. EFAT-07 → EFAT-10 → EFAT-11.
4. EFAT-13 alarmları (senkron arızası, günlük özet, ERP okumadı — 2026-09-23 kararı).
5. EFAT-17 otomatik kabul → Codex incelemesi → EFAT-14 yayın.

Durum (2026-09-23): 1–4 lokalde bitti; EFAT-17'nin otomatik kısmı bitti.
Yayın yapılmadı.

## Önerilen uygulama sırası (tam kapsam)

1. EFAT-01: gizli dosyaların korunması. EFAT-16: mevcut yetki açığı.
2. EFAT-15'in şirket/ortam kararları; EFAT-02'de açık doküman incelemesi.
3. EFAT-03 → EFAT-04 → EFAT-05 → EFAT-06: ilk kullanılabilir bağlantı ekranı.
4. ERP sorgusu ve API örnekleriyle EFAT-15 veri sözleşmesini tamamla;
   EFAT-07 → EFAT-08 → EFAT-09: kaynaklar ve mutabakat.
5. EFAT-10 → EFAT-11: onaylanan saklama modeli, ekran ve raporlar.
6. EFAT-12 → EFAT-13: mail ve alarmlar. SMTP beklenirken fake ile geliştirme yapılabilir.
7. EFAT-17 kabul senaryoları → EFAT-14 kontrollü canlı geçiş.

## Açık sorular

Cevaplandıkça ilgili göreve karar olarak işlenir.

| # | Soru | Etkilediği görev |
|---|---|---|
| S1 | **Kapandı (2026-09-23):** kullanıcı `eFatura API.postman_collection.json` export'unu ekledi; uçlar test hesabında doğrulandı (api-notlari.md). | EFAT-02, 07 |
| S2 | **Karar (2026-09-23):** ilk faz yalnız e-Fatura (gelen + giden). e-Arşiv sonra. | EFAT-07, 09 |
| S3 | ERP'deki fatura kaydında ETTN (UUID) ve/veya GİB fatura numarası (ör. `ABC2026000000001`) tutuluyor mu? Eşleştirme anahtarı buna göre seçilir. | EFAT-08, 09 |
| S4 | **Karar (2026-09-23):** fatura özetleri (no, ETTN, tarih, VKN, tutar, durum) PostgreSQL'de tutulabilir — "ERP verisi bizde tutulmaz" kuralına onaylı istisna. Saklama süresi ayrıca netleşecek. | EFAT-10, 13, 15 |
| S5 | **Karar (2026-09-23):** entegratörün aktif ortamı SQL'den tamamen bağımsız seçilir; uyuşmazlıkta yalnız uyarı. Kabul edilen risk ve azaltımlar EFAT-15'te. | EFAT-03, 10, 15 |
| S6 | **Karar (2026-09-23):** SMTP ayarları tanımlama ekranından girilir; seeder şifresiz ilk kayıt açar, şifre ekrandan girilir; görünen ad "eMOR ERP" (EFAT-12). Alarm alıcıları ve kural örnekleri açık. | EFAT-12, 13 |
| S7 | **Karar (2026-09-23):** kullanıcı oluşturma ve yetkilendirme mekanizması kurulacak (EFAT-18); eFatura ekranlarına erişim yetkiyle verilir. | EFAT-11, 18 |
| S8 | **Karar (2026-09-23):** sunucu `api.izibiz.com.tr`'ye doğrudan çıkabiliyor. | EFAT-04, 14 |
| S9 | **Karar (2026-09-23):** tek şirket (tek VKN), tek İzibiz hesabı; ortam başına tek tanım. | EFAT-03, 15 |
| S10 | **Karar (2026-09-23):** fatura aslı (PDF) görüntüleme ve Excel çıktısı isteniyor. Karşılaştırılacak tutar alanı ERP fazında netleşecek. | EFAT-02, 11, 15 |
| S11 | **Karar (2026-09-23):** ilk tarama tarihi 01.01.2026; ekranlarda başlangıç–bitiş tarihi seçilebilir. Hacim, yenileme sıklığı ve bekleme süresi açık. | EFAT-07…10, 13, 15 |

## Doğrulanan mevcut bulgular

SQL Bağlantıları uçlarında (`/api/v1/ayarlar/sql-baglantilari*`) yetki
kontrolü yok. `SqlBaglantiGuncelleRequest::authorize()` true döndürüyor ve
menüde `yoneticiye` bayrağı da yok. Bu yüzden oturum açmış her kullanıcı
MSSQL bağlantı bilgilerini ve aktif ortamı değiştirebiliyor. Ayrıca `sina`
ucu kayıtlı şifreyi formdan gelen başka bir sunucuya gönderebiliyor ve
lokal admin `sistem_yoneticisi` olmadığı için yalnız yetki eklemek lokal
admini kilitler. Kanıt, düzeltme ve doğrulama
[EFAT-16](gorevler/EFAT-16-sql-baglantilari-yetkisi.md)'da. 2026-09-23'te
lokalde düzeltildi; sunucuya yayınlanana kadar canlıda açık devam ediyor.
Codex incelemesinde bağlantı dizesine seçenek ekleme, portun temizlenmesi ve
migration geri alma davranışındaki üç ek bulgu da düzeltildi; backend 74 test
geçti. Ayrıntılar ve doğrulanmayan noktalar görev dosyasında.

`mail.md` 2026-09-23'te kök `.gitignore`'a eklendi; `publish.sh:110`
bütün dosyaları stage ettiği için bu gerekliydi. Dosya Git tarafından
izlenmiyor ve dosya yolu için geçmiş kaydı yok. Test şifresi yerel Git
geçmişinin hiçbir commit'inde geçmiyor. Environment export'ları ve
Zone.Identifier artığı da ignore edildi; collection'daki token'lar İzibiz'in
süresi dolmuş örnekleri olduğu için repoda kalıyor (EFAT-01 bitti).
