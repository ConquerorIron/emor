# Yedekleme ve Geri Yükleme Yönetimi — Tasarım Dokümanı

**Durum:** Taslak — henüz kod yazılmadı. Önce bu doküman üzerinde anlaşılacak,
sonra aşama aşama uygulanacak.
**Tarih:** 2026-11-16 · **Revizyon:** 2026-11-18 (Codex ve Claude web incelemeleri işlendi — §14)

## 1. Amaç

Uygulamanın kendi veritabanını (PostgreSQL) arayüzden yönetilen bir düzenle
yedeklemek ve gerektiğinde güvenle geri yüklemek:

- Zamanlanmış yedekler (saatlik / günlük / haftalık / aylık) ve elle yedek
- Eski yedeklerin kurala göre silinmesi
- Yedeğin doğrulanması (bozuk yedek, yedek değildir)
- Geri yükleme: **farklı bir veritabanı adıyla** ya da **mevcut veritabanının üstüne**
- Sunucu dışına kopya, bildirim, yetki ve denetim kaydı

### Kapsam

| Kapsamda | Kapsam dışı |
|---|---|
| Uygulamanın PostgreSQL veritabanı (`erp`) | ERP'nin MSSQL veritabanları (Hab_hom, FD_hom) — ERP tarafının sorumluluğu |
| Sunucudaki uygulama dosyaları (`storage/app`) | Uygulama kodu — zaten GitHub'da |
| Geri yükleme için gereken sırlar (`.env`, özellikle `APP_KEY`) | Sunucunun işletim sistemi / sanal makine yedeği (bu sistemin kapsamında değil) |

Neden önemli: Kesinti Yönetimi'nin bütün verisi (icmaller, yüklemeler,
tanımlar) yalnız bu veritabanında durur; ERP'de karşılığı yoktur. Kullanıcılar,
roller, ekran tasarımları, bağlantı tanımları ve e-Fatura kayıtları da burada.

## 2. Bugünkü durum (2026-11-16'da sunucuda ölçüldü)

- **Zamanlanmış yedek yok.** Yedek yalnız `deploy.sh` çalışınca alınıyor
  (`pg_dump --format=custom`, `/var/backups/emor/erp_predeploy_<tarih>.dump`).
- 5 Ağustos 2026'dan beri 159 yedek birikmiş, toplam 96 MB; bir yedek ~1,6 MB.
  **Hiçbiri silinmiyor.**
- Yedekler **aynı sunucuda, aynı diskte** (196 GB diskin %6'sı dolu).
- Bu yedeklerden **hiç geri yükleme denenmedi** — çalıştıkları kanıtlanmış değil.
- `storage/app` şu an fiilen boş (24 KB); dosya yedeği bugün acil değil ama
  ileride dosya saklayan bir özellik gelirse kapsam hazır olmalı.
- `.env` (ve içindeki `APP_KEY`) hiçbir yerde yedeklenmiyor.

## 3. Hedefler (kararlaştırılacak)

İki sayı bütün tasarımı belirler; öneriler aşağıda, karar kullanıcıda (bkz. §13):

- **En fazla ne kadar veri kaybı kabul edilir?** Öneri: mesai saatlerinde en
  fazla 1 saat (saatlik yedek), mesai dışında 1 gün.
- **Sistem en geç ne kadar sürede ayağa kalkmalı?** Öneri: aynı sunucuda
  30 dakika; sunucu tamamen kaybedilirse 1 iş günü.

Veritabanı çok küçük olduğu için (tam yedek 1 saniyenin altında, ~1,6 MB)
artımlı yedek ya da sürekli arşivleme gibi karmaşık yöntemlere gerek yok;
**her yedek tam yedek** olabilir. Daha sık yedek de aynı yolla alınabilir
(ör. 15 dakikada bir tam yedek). PostgreSQL'in sürekli arşivlemesi (WAL) ayrı
bir seçenektir: iki yedek arasındaki **herhangi bir ana** dönebilmeyi sağlar;
sunucu yöneticisi yetkisi ister ve bu dokümanın kapsamı dışındadır.

## 4. Özellikler

### 4.1 Yedek alma

- **Elle:** ekrandan "Şimdi Yedek Al"; isteğe bağlı açıklama notu.
- **Zamanlanmış:** planlara göre (§4.2).
- **Yayın öncesi:** `deploy.sh`'ın aldığı yedek de aynı sisteme kaydolur
  (aynı klasör, aynı liste, aynı saklama kuralları).
- **Geri yükleme öncesi:** üstüne geri yüklemeden hemen önce otomatik (§4.5).
- Her yedeğin **kaynağı** tutulur: elle / plan / yayın öncesi / geri yükleme öncesi.
- Biçim: `pg_dump --format=custom` (sıkıştırılmış, tek dosya, tablo seçerek
  geri yüklenebilir).
- **Geçici tabloların verisi yedeğe girmez** (şema girer): `sessions`, `cache`,
  `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. Gerekçe §7.1.
- Aynı anda tek yedek / geri yükleme / yayın işlemi çalışır (kilit — §5.2).
- Yedek önce geçici adla yazılır, bitince asıl adına taşınır — yarım dosya
  listede hiç görünmez.
- Tablo satır sayıları ve migration listesi, yedekle **aynı anlık görüntüden**
  alınır (§6.1). Sonradan canlıdan sayılırsa arada gelen kayıtlar sağlam yedeği
  bozuk gösterir.

### 4.2 Zamanlama (planlar)

- Birden çok plan tanımlanabilir. Her plan: ad, sıklık, saat, etkin/pasif,
  **hedef(ler)** ve o hedefteki saklama kuralı (kullanıcı kararı 2026-11-18).
  Plan "ne zaman, nereye, ne kadar süre" sorularının üçünü birlikte tanımlar.
- Örnek (kullanıcının tarifi): her gün yerel diske, 3 günde bir FTP'ye, haftada
  bir Google Drive'a. İstenirse her saat Google Drive'a da alınabilir; sıklık
  ile hedef arasında kısıt yoktur.

  | Plan | Sıklık | Hedef | Saklama |
  |---|---|---|---|
  | Günlük yerel | her gün 02:00 | Yerel disk | son 14 yedek |
  | FTP | 3 günde bir 02:00 | FTP | son 10 yedek |
  | Haftalık Drive | Pazar 03:00 | Google Drive | son 8 yedek |

- Aynı ana düşen planlar için **tek yedek** alınır ve planların hedeflerine
  birlikte kopyalanır; aynı anda üç farklı yere yedek gidebilir.
- Her yedek önce yerel diskte üretilir (doğrulama ve imza orada yapılır), sonra
  hedefe kopyalanır. Planın hedefleri arasında yerel disk yoksa yerel dosya,
  kopyalar başarıyla tamamlanınca silinir.
- Sıklıklar: N saatte bir (isteğe bağlı saat aralığı ve günler, ör. hafta içi
  08–19), her gün, N günde bir, haftalık (gün seçimi), aylık (ayın günü).
- Saatler **İstanbul saatiyle** tanımlanır ve gösterilir.
- Plan ekranında "sıradaki çalışma" ve "son çalışmanın sonucu" görünür.
- Plan kaydedilmeden önce sıradaki birkaç çalışma önizlenir; her ay bulunmayan
  günler (29–31) ve çakışan planlar açıkça gösterilir.
- Kaçırılan çalışma (sunucu kapalıyken): açılınca **bir kez** telafi edilir,
  birikmiş hepsi değil.
- Yayın (bakım modu) sırasında planlı yedek atlanmaz; yedek, uygulama
  kapalıyken de alınabilmelidir.
- Varsayılan plan önerisi: günlük 02:00 + hafta içi saatlik + haftalık Pazar.

### 4.3 Saklama ve temizlik

- **Saklama kuralı plan + hedef başınadır** (kullanıcı kararı 2026-11-18; "tek
  havuz" önerisi kabul edilmedi, çünkü her hedefe farklı sıklıkta yedek
  gönderilebilmesi isteniyor). Kural: "bu planın bu hedefteki yedeklerinden son
  N tanesini tut" ve / veya "N günden eskileri sil".
- Bir yedek birden çok planın hedefine gittiyse her hedefteki kopya kendi
  planının kuralıyla temizlenir; bir hedefteki silme diğerini etkilemez.
- Aynı hedefi paylaşan iki plan aynı yedeği aynı hedefe İKİ KEZ koymaz: hedefte
  tek dosya olur, iki plan ona bağlanır. Her plan kendi kuralıyla yalnız kendi
  bağını kaldırır; dosya, onu tutan plan kalmayınca silinir (Fable incelemesi
  F6; veri modeli §5.4).
- Zamanlanmamış yedeklerin kuralı ayrıdır:
  - yayın öncesi: son 20 yedek ya da 30 gün (öneri),
  - elle alınan: 30 gün (öneri); uzun kalacaksa sabitlenir,
  - geri yükleme öncesi: 30 gün ve ilgili `erp_onceki_*` silinene kadar (öneri).
- **Süre dolsa da en az sayı korunur.** Yedekler haftalarca başarısız olursa
  yalnız süreye bakan bir kural eldeki son yedekleri de siler. Temizlik, yeni
  başarılı yedek gelmedikçe silmez ve her hedefte en az N (öneri 3) başarılı
  yedeği tutar.
- **Koruma kuralları** (temizlik bunları asla silmez):
  - Son başarılı yedek — hiçbir koşulda.
  - "Sabitlenmiş" yedekler (kullanıcı elle işaretler, ör. yıl sonu).
  - Doğrulaması geçmiş en yeni yedek.
  - "Son başarılı" ve "doğrulaması geçmiş en yeni", bugün kullanılabilen
    yedekler arasından seçilir: yerel arşivi ya da `tamam` kopyası olan (F7:
    yerel dosyası silinmiş, uzak kopyalı yedek dahil). Yalnız yeri
    doğrulanamayan (`konum_belirsiz`) kopyası kalan yedek listede `tamam`
    görünür ve "son deneme başarılı" sayılır (temkin), ama korumayı daha eski
    kullanılabilir yedekten almaz (BY-028 beşinci tur notu).
  - O anda kopyalanan, doğrulanan ya da geri yüklenen yedek (işlem bitene kadar).
- Temizlik her yedekten sonra ve günde bir kez çalışır; neyi neden sildiği
  kaydedilir.
- **Uygulama kuralları (BY-010):**
  - Plan kuralında "son N ve / veya N gün": kopya, verilen ölçütlerden
    **herhangi birini** sağlıyorsa tutulur; ikisini de aşan bağ kalkar.
    Yayın öncesi yedeklerde de "son 20 ya da 30 gün" aynı okunur.
  - Gruplar: her hedef ayrı; ayrıca "yedek klasörü" (hiçbir hedefin
    kopyası olmayan yerel dosyalar). En az sayı her grupta ayrı uygulanır.
    Bir grubun en az sayısı ve yerel dosyanın korumaları (ör. "uzak kopya
    doğrulanmadı") yalnız o grubu süzer; uzak hedeflerin kararları grupların
    işlenme sırasına bağlı değildir (BY-010-R6).
  - Temkin: en son yedek denemesi başarısızsa ya da işlem durumu dosyası
    okunamıyorsa hiçbir şey silinmez.
  - Yerel dosya silinirken yalnız `.dump` gider; **imzalı manifest
    (`.json`) yerinde kalır** ve kopyaların kimliği / durumu olay günlüğünde
    durur (`kopya` olayları). Dizin yeniden kurulurken (BY-011) uzak kopyası
    olan yedek bu ikisinden bulunur (kullanıcı kararı F7).
  - Uzak kopyası başarısız ya da beklemede olan yedeğin yerel dosyası
    silinmez: yeniden denemenin kaynağıdır.
  - Planlı yedeğin yerel dosyası (§4.2: planlarında yerel disk yok) yalnız
    saklamada silinir (BY-010-R1): planın bütün hedeflerine kopya kaydı
    kopyalamadan önce açılır ve hepsi doğrulanmış olmalıdır; sabit ve
    kullanımdaki yedeğin dosyası kalır. "Son başarılı", "doğrulanmış en
    yeni" ve en az sayı yedeğin kendisini korur; yedek uzak hedefte durduğu
    için onun grubunda uygulanır.
  - **"Tamam" ile "kurtarmaya hazır" ayrıdır (BY-010-R2, BY-024-R3).** Uzak
    kopyanın `tamam` olması yalnız aktarımın (geri okuyup özetle) doğrulandığını
    söyler. Şifreli (sunucu dışı) kopya, o hedefe yedeğin imzalı manifestini
    taşıyan bir kurtarma paketi doğrulanınca **kurtarmaya hazır** olur
    (`yedek_kopyalari.kurtarma_hazir`; §9 "Kurtarma paketi"). Paketin
    manifest arşivi yedek klasöründeki bütün manifestleri taşır: sonraki
    başarılı paket, önceki paketi yarım kalmış kopyaları da hazır yapar.
    Hazırlık adın tutmasına değil arşive giren baytlara bağlıdır (BY-024-R5):
    manifestin imzası geçerli, yedek kimliği aynı ve özeti katalogdakiyle aynı
    olmalı. Dayanak hedefte KALAN tamam paket sürümleridir: yedeğin
    doğrulanmış manifestini taşıyan son sürüm dönüşümle silinince işaret geri
    alınır.
    Şifresiz kopya yalnız sunucu içi yerel disk hedefidir; kurtarma paketi ona
    gitmez, ekrandaki geri yükleme yedek klasöründe kalan manifestle yapılır:
    `tamam` olması yeter. Planlı yedeğin yerel dosyası, en az bir uzak kopyası
    kurtarmaya hazır olmadan silinmez (`kurtarma_paketi_eksik`). Dayanak bu
    temizlikten SONRA kalan kopyalardır: aynı turda silinecek hazır kopya
    sayılmaz; yedek bir hedefte saklanmaya devam ediyorsa kalanlardan biri
    hazır olmalı. Yeri doğrulanamayan (`konum_belirsiz`) kopya da kalan
    kopyadır (dosya bir yerde durabilir; `yedegiTutanlar`) ama dayanak
    değildir: yalnız o kalırsa yerel dosya kalır (G21). Saklama grupları ve en
    az sayı yine yalnız `tamam` kopyalardan kurulur.
    Hiçbir uzak kopya kalmıyorsa yedek her hedeften silinmiştir,
    saklama kararı geçerlidir ve yerel dosya da silinir. Önizleme bütün uzak
    silmelerin başarılı olacağını varsayar; uygulamada uzak silmeler ÖNCE
    yapılır, yerel dosya silinmeden hemen önce fiilen kalan kopyalar
    veritabanından yeniden okunur ve aynı eşik uygulanır: kısmi silme
    hatasında geride hazır olmayan kopya kalırsa yerel dosya korunur (denetim
    kaydı `basarisiz`, kod `kurtarma_paketi_eksik`; BY-010-R5). Yerel dosya
    planda ana klasör hedefi olarak yazılmışsa o
    hedefin kuralı da bu eşiğe tabidir (BY-010-R4); ekranda
    kopyanın yanında "kurtarma paketi bekleniyor" yazar, özetteki "son sunucu
    dışı kopya" yalnız hazır kopyaları sayar (uzak yaşam sinyaliyle aynı ölçüt).
    Bu koruma aktarım için silinen yerel dosyayadır; zamanlanmamış yedeğin
    süresi dolan yerel dosyası kendi kuralıyla silinir. Dizin yeniden
    kurulunca (BY-011) şifreli kopyalar hazır değil başlar, sonraki başarılı
    paket hazır yapar (güvenli yön).
  - **Depo işareti (BY-011-R3).** Yedek klasörünün kökünde
    `.emor-yedek-deposu` (tek satır, `emor-yedek-deposu <uuid>`) klasörün bu
    sistemin deposu olduğunu gösterir; kurulum betiği açar. Ayrı diske
    bağlanmış klasörün diski ayrılınca bağlama noktası boş ve okunabilir
    kalır: işaret görünmez, tarama dizine dokunmadan durur ("dosya yok"
    yapmaz), yedek alma kök diske yazmaz (`YEDEK_DEPO_ISARETI_YOK`); ölen
    süreçten kalan `calisiyor` kayıt da depo doğrulanmadan "yarıda kaldı"
    yapılmaz, disk geri gelince diskteki imzalı manifestiyle tamamlanır
    (BY-011-R4). İşaretsiz
    klasörü uygulama yalnız katalogda HİÇ yedek kaydı yoksa (ilk kurulum,
    tablolar boşaltılıp yeniden kurma; durumdan bağımsız: yarıda kalmış ilk
    yedek de sayılır — BY-011-R5) ya da katalogdaki bir kaydın imzası geçerli,
    kimliği ve dosya adı kayıtla aynı manifesti oradaysa (işaretten önce
    kurulmuş ya da işareti silinmiş depo) kendisi işaretler.
    Ayrıntı ve disk bağlama sırası: sunucu-kurulumu.md §2 "Depo işareti".
  - Yedek klasörünün kendisi olan yerel hedef yazımına göre değil gerçek
    konumuna (aygıt + düğüm) göre tanınır; başka bir hedefte yerel dosyanın
    kendisi bağımsız kopya sayılmaz (`HEDEF_ANA_DOSYA`; BY-008-R1).
  - **Hedefler fiziksel depoyla bağımsızdır, kayıt kimliğiyle değil
    (BY-010-R7, R8).** Aynı depoyu gösteren iki hedefte (aynı klasör; uzakta
    aynı sunucu + kullanıcı + klasör ya da aynı S3 uç noktası + kova + önek;
    §4.6) aynı yedeğin kopyaları AYNI dosyadır. Eskiden kalmış (ya da üstüne geri
    yüklemeyle geri gelmiş) böyle tanımlarda saklama, kuralın silmek
    istediği ortak dosyayı silmez: önizlemede koruma nedeni `ayni_depo`,
    uygulamada her turda denetim kaydı `saklama_sil` / `basarisiz`, kod
    `HEDEF_AYNI_DEPO` (+ diğer hedefler) ve `bilgi` satırı. Yalnız tek
    hedefte kopyası olan dosya o hedefin kuralıyla silinir. Çözüm: fazla
    hedefi planlardan çıkarıp pasife almak (konumu değişmeyen güncelleme
    serbesttir).
  - Hedef "silme yetkisi yok" derse (değiştirilemez saklama) kopya bizden
    çıkmış sayılır (`silindi`, kod `HEDEF_SILME_YETKISI_YOK`); eski kopyayı
    hedefin kendi kuralı temizler. Bu hata sayılmaz, bilgi olarak yazılır.
- Elle silme ayrı izinle ve onayla; sabitlenmiş yedek önce sabitten çıkarılır.
- Disk doluluk eşiği: boş alan belirlenen sınırın altına düşerse uyarı;
  yedek almak eski yedekleri **kural dışı** silmez (sessiz veri kaybı olmasın).
- Sunucu dışı kopyalar için saklama ayrı tanımlanabilir (genelde daha uzun).

### 4.4 Doğrulama

Üç düzey, artan maliyetle:

1. **Her yedekte:** dosya boyutu > 0, SHA-256 özeti, `pg_restore --list` ile
   beklenen tablolar var mı; ardından `pg_restore --file=/dev/null` ile yedeğin
   **bütün veri blokları** okunur. (`--list` yalnız içindekiler tablosunu okur;
   veri bozuksa fark etmez. İkinci komut veritabanı gerektirmez.)
2. **Sınama geri yüklemesi, her yedekten sonra** (en az günde bir): geçici bir
   veritabanına gerçekten geri yükle, satır sayılarını ve migration listesini
   manifestle karşılaştır, sonra geçici veritabanını sil. 1,6 MB'lık bir
   veritabanında maliyeti yok; haftayı beklemenin gerekçesi de yok. Servis
   hesabı gelene kadar yapılamaz (§5.2).
3. **Elle:** herhangi bir yedek için "Doğrula" düğmesi (2. düzeyi çalıştırır).

Listede her yedeğin doğrulama durumu görünür: doğrulanmadı / özet tamam /
sınama geri yüklemesi geçti / başarısız.

### 4.5 Geri yükleme

İki ayrı işlem; riskleri çok farklı olduğu için ekranda da ayrı tutulur.

**A. Farklı bir veritabanı adıyla (güvenli, canlıya dokunmaz)**

- Kullanım: yedeği incelemek, yanlışlıkla silinen kayıtları bulmak, üstüne
  geri yüklemeden önce prova.
- Hedef ad serbest metin **değildir**; sistem üretir (`erp_geri_<tarih>_<saat>`)
  ya da sıkı bir kalıba uyan ek alır. Mevcut bir veritabanının adı verilemez.
- Geri yüklenen kopyalar ekranda listelenir, boyutu görünür, silinebilir;
  öneri: 7 gün sonra otomatik silinir (uyarıyla). Otomatik silinme anı
  `bitti + kopya_bekleme_gun`'dür; `geri_yuklemeler.veritabani_silinme_zamani`
  veritabanının gerçekten silindiği andır (BY-026). Çalıştırıcı yalnız kendi
  kalıbındaki ve `geri_yuklemeler`'de kaydı olan kopyayı siler.
- Kopya **yalnız incelenir**: uygulama ona bağlanıp iş yapmaz, zamanlanmış
  görevler ona karşı çalışmaz (kopyada canlı bağlantı tanımları ve alarm
  kuralları vardır — §7.6).
- İlk sürümde kopyayı ekrandan gezme yok; inceleme veritabanı aracıyla yapılır.
  "Kopyadan tek tablo / tek kayıt kurtarma" sonraki aşama (§12).
- İnceleme, yalnız kopyalara bağlanabilen **salt okunur** ayrı bir veritabanı
  rolüyle yapılır; kimlerin kullanacağı tanımlanır (§13). Uygulamanın kendi
  kullanıcısıyla ya da servis hesabıyla incelenmez.

**B. Mevcut veritabanının üstüne (yıkıcı)**

- Yalnız sistem yöneticisi. **Ekrandan başlatılır ve ekrandan izlenir**
  (kullanıcı kararı 2026-11-18; "ilk sürümde yalnız komut satırı" önerisi kabul
  edilmedi). Komut satırı olağan yol değildir; yalnız ekranın açılamadığı
  durumlar için acil durum yolu olarak bulunur (§6.4).
- Onay adımları: veritabanı adını yazarak onay + neyin kaybedileceğinin açıkça
  gösterilmesi ("Bu yedek 14.11.2026 02:00'da alındı; o andan sonra girilen
  her şey silinecek").
- Akış (§6.4'te ayrıntılı): otomatik "geri yükleme öncesi" yedek → bakım modu →
  yeni veritabanına geri yükle → **orada** migration ve doğrulama →
  **ad değiştirerek yer değiştir** → yönetici onayı → bakım modundan çık.
- Eski veritabanı silinmez, `erp_onceki_<tarih>` adıyla bekletilir; sorun
  çıkarsa tek adımda geri dönülür. Belirli süre sonra (öneri 7 gün) silinir.
- Şema uyumu, yedeğin **uygulanmış migration listesinin tamamı** ile denetlenir
  (yalnız sonuncusuyla değil). Yedekte olup kodda bulunmayan migration varsa
  (kod geri alınmış ya da yedek farklı bir daldan alınmış) işlem başlamadan
  reddedilir. Kodda olup yedekte bulunmayanlar geri yüklemeden sonra çalıştırılır.
- Yalnız seçili tabloları geri yükleme: ilk sürümde yok (yabancı anahtar
  tutarlılığı kolayca bozulur) — §12.

**Dışarıdan yedek dosyası ve güven sınırı:** ekrandan yalnız **bu sistemin
ürettiği** yedekler geri yüklenebilir. Ayırt etmek için manifest imzalanır
(§5.3); "Klasörü Tara" imzası geçerli yedekleri listeye alır. İmzasız ya da
imzası tutmayan dosya listede "güvenilmeyen" olarak görünür ve geri yüklenemez.

Gerekçe: bir yedek geri yüklenirken içindeki tanımlar (fonksiyon, tetikleyici)
hedefte **kod çalıştırabilir**. Özet (SHA-256) yalnız dosyanın değişmediğini
gösterir, kaynağının güvenilir olduğunu değil. Aynı sunucuda farklı ada geri
yüklemek, güvenilmeyen bir dosya için yeterli yalıtım değildir; böyle bir dosya
ancak ayrı, yalıtılmış bir ortamda incelenir.

`deploy.sh`'ın bugüne kadar aldığı 159 imzasız yedek, bir kereye mahsus
yönetici komutuyla içeri alınır (YDK-09). Tarayıcıdan yedek yükleme yok
(sunucudaki yükleme sınırı da 2 MB — §9).

### 4.6 Sunucu dışına kopya

- Aynı diskteki yedek, disk ya da sunucu kaybına karşı korumaz. En az bir
  kopya başka bir makinede olmalı.
- Hedef türleri; birden çok hedef tanımlanabilir, plan hangisine kopyalanacağını
  seçer:

  | Hedef | Not |
  |---|---|
  | Yerel disk | Sunucunun kendi diski. Hızlı geri yükleme içindir; disk ya da sunucu kaybına karşı korumaz |
  | Ağ paylaşımı (NAS) | Şirket içinde kalır; ağda bir paylaşım (NAS) bulunması ve sunucuya bağlanması gerekir |
  | SFTP / SSH ile başka sunucu | Şirket içinde kalır; bağlantı şifreli |
  | FTP (düz) | Seçenek olarak sunulur (kullanıcı kararı 2026-11-18). Kullanıcı adı ve parola ağda açık gider; yedek dosyası şifreli olsa da hesap ele geçirilebilir. Hedef tanımında bu uyarı gösterilir; yalnız şirket içi ağda kullanılması önerilir |
  | FTPS (şifreli FTP) | Aynı FTP sunucusu şifreli bağlantıyı destekliyorsa tercih edilir; kullanıcı adı ve parola korunur |
  | S3 uyumlu depolama | Silinmeye karşı koruma (değiştirilemez saklama) sunabilen tek tür |
  | Google Drive | "Google ile bağlan" ile; hedefi tanımlayan kullanıcı kendi Google hesabıyla giriş yapıp izin verir (kullanıcı kararı 2026-11-18), ayrıntı aşağıda. Veri şirket dışına, yurt dışındaki bir buluta çıkar; şifreli gitse de bu bir şirket politikası kararıdır (§13) |

- Bulut hedefleri (Google Drive, S3) sunucunun internete çıkabilmesini ister;
  şirket içi hedefler istemez.
- Her hedef türü ayrı bir bağlayıcıdır; ilk sürümde hepsi yazılmaz, seçilen
  hedef(ler) yazılır. FTP/SFTP ve Google Drive için ek kütüphane gerekir
  (bağımlılık eklemek ayrıca onaylanır).
- Hedef tanımı Ayarlar'daki diğer bağlantı tanımlarının kalıbını izler; şifre
  şifreli saklanır, "Bağlantıyı Sına" düğmesi olur.
- **Bir fiziksel depo, tek hedef (BY-010-R7, R8).** Dosya sistemi hedeflerinde
  (Yerel disk, NAS) aynı klasörü gösteren ikinci hedef kaydedilmez: 409
  `HEDEF_AYNI_DEPO`. Karşılaştırma yazıma değil gerçek konuma göredir
  (`/x`, `/x/.`, `/x/../x`, sembolik bağlantı, bind mount; klasör henüz yoksa
  sadeleştirilmiş yazım) ve ana klasörü tanıyan ölçütün aynısıdır
  (G19); klasörü boş ikinci Yerel hedef ikinci ana klasör hedefidir, o da
  reddedilir. Yerel ↔ NAS aynı klasör de çakışmadır; alt klasör ayrı
  depodur. Aynı yeri birden çok plan kullanacaksa tek hedef birden çok plana
  eklenir (F6). Ekleme ve başka depoya taşıyan güncelleme tek kilit altında
  denetlenir (eşzamanlı iki kayıt aynı klasörü ikinci kez tanımlayamaz);
  konumu değişmeyen güncelleme (ad, aktiflik, parola) ve aynı depoda kalan
  konum değişikliği (eşdeğer yazım, S3 bölgesi / yol stili) eski çakışan
  hedefte de serbesttir. Eskiden kalmış çakışan hedefte kopyalayıcı, yedeğin
  kopyası diğer hedefte duruyorsa ikinci kopyayı yazmaz (`basarisiz`, kod
  `HEDEF_AYNI_DEPO`, yeniden denenmez); saklama ortak dosyayı silmez (§4.3).
  Uzak hedeflerde (BY-010-R8) aynı kural, bağlayıcının dosya adresini
  kurduğu alanlardan çıkan depo kimliğiyle uygulanır; aynı nesneye götüren
  farklar kimliğe girmez, şüphede "aynı" sayılır (fazla engelleme bir 409,
  eksik engelleme veri kaybıdır):
  - S3: uç nokta (alan adı harf duyarsız, `:443` ve sondaki "/" yok
    sayılır) + kova + önek (baştaki / sondaki "/" yok sayılır). Bölge,
    erişim anahtarı ve yol stili (`uc/kova/…` ↔ `kova.uc/…`) aynı nesneye
    gider, kimliğe girmez. Başka kova, önek (alt önek dahil), port ya da
    uç nokta ayrı depodur.
  - FTP / FTPS / SFTP tek ailedir: sunucu + kullanıcı adı + klasör; tür ve
    port kimliğe girmez (aynı hesap aynı klasöre üç protokolle de yazabilir).
    Kullanıcı adı ve klasör harf duyarsızdır (Windows / IIS FTP sunucuları);
    klasörün baştaki ve sondaki "/"'ı yok sayılır (chroot'lu hesapta `/x` =
    `x`, `/` = giriş klasörü). Başka sunucu, kullanıcı ya da alt klasör ayrı
    depodur.
  - Google Drive denetlenmez: her yükleme yeni dosya kimliği açar, okuma ve
    silme o kimlikle yapılır; iki hedefin kopyası iki ayrı dosyadır.
  - Bilinen sınır (kanıtlanamaz, ağ çağrısı yapılmaz): alan adı / IP takma
    adı, S3 uç nokta takma adı (`s3.amazonaws.com` ↔ bölgesel uç nokta),
    türler arası aynı yer (NAS bağlama noktası ↔ aynı sunucunun SFTP'si),
    chroot'u farklı iki hesabın aynı klasörü. Bunlarda aynı depoyu iki hedef
    olarak tanımlamamak kullanıcı sorumluluğundadır.
- Kopyalama yedekten sonra ayrı adımdır; başarısız olursa yedek yine geçerlidir,
  kopya yeniden denenir ve uyarı üretilir.
- Listede her yedek için "sunucu dışı kopya: var / yok / başarısız" görünür.
- "Bağlantıyı Sına" yalnız bağlanmayı değil **yaz – oku – doğrula – sil**
  döngüsünü sınar: hedefin gerçekten yedek saklayabildiği kanıtlanır.
- Hedef destekliyorsa **silinmeye karşı koruma** (değiştirilemez saklama) açılır;
  uygulama ya da sunucu ele geçirilse bile geçmiş yedekler topluca silinemez.
  Uygulamanın hedefte silme yetkisi olmaması tercih edilir; eski kopyaları
  hedefin kendi saklama kuralı temizler.
- Sunucu dışındaki yedekten geri yükleme: önce yerel klasöre indirilir, özet
  doğrulanır, sonra normal akış.
- Şifreli kopyanın özeti olay günlüğüne yazılır; uzak kopya ancak bununla
  doğrulanabilir (şifreli dosyanın özeti, şifresiz yedeğinkinden farklıdır).
- Yedeklerle birlikte olay günlüğü, denetim günlüğü ve ayar kopyası da sunucu
  dışına gider; yoksa sunucu kaybında yedekler kalır ama geçmişleri kaybolur.

**Google Drive bağlantısı (Google ile giriş)**

- Hedef tanımlanırken "Google ile bağlan" düğmesi Google'ın izin ekranını açar;
  giriş yapan hesap yedeklerin gideceği hesaptır. Ekranda bağlı hesabın e-posta
  adresi ve bağlanma tarihi görünür; "Bağlantıyı kes" ile kaldırılır. Hesabın
  e-postası okunamazsa bağlantı kaydedilmez, yeniden denenir (BY-021-R3).
  Kopyası olan hedef ancak aynı hesapla yeniden bağlanır; hesabı kayıtlı
  olmayan eski hedefte aynılık sahiplikle kanıtlanır: yeni hesap kayıtlı yedek
  klasörünün sahibi olmalı (kişisel Drive; çöpte değil, dosya eklenip
  listelenebiliyor) ve en son kayıtlı kopyanın da sahibi olup onu
  indirebilmeli. Erişim yetmez: paylaşılmış klasör başka hesaptan da görünür
  (BY-021-R4). Kanıtlanamazsa eski bağlantı aynen kalır; klasörün sahibi
  hesapla yeniden denenir ya da yeni hedef eklenip kopyalar elle uzlaştırılır.
  "Kopyası olan" dosyası durabilen kopyayı da kapsar: yeri doğrulanamayan
  (`konum_belirsiz`, ör. yükseltmede izsiz kalan eski) kopya konum kilidini
  tutmaz ama hesap değişikliğini engeller ve sahiplik kanıtında sorulur;
  yalnız konum izi yeni hesabı gösteren kopya engellemez (o hesaba dönmek
  kopyanın yerini düzeltir). Kanıt kayıttan önce kilit altında yeniden
  eşlenir: sorulan klasör ve kopya o an hâlâ kayıtlı klasör ve en son kopya
  olmalı (BY-021-R5).
- Yedekler gece kimse giriş yapmadan gideceği için uygulama, Google'dan kalıcı
  bir yenileme anahtarı alır ve şifreli saklar. Kullanıcının parolası uygulamaya
  hiç gelmez.
- **Klasör seçimi (2026-11-22, kullanıcı isteği):** bağlandıktan sonra
  Google'ın kendi klasör seçme penceresi (Google Picker) açılır; kullanıcı
  Drive'ındaki klasörlerinden birini seçer ya da uygulamaya Drive'ın köküne
  yeni klasör açtırır. Formda klasör adı sorulmaz; klasör adla aranmaz ya da
  kendiliğinden açılmaz. Seçilen klasör kaydedilmeden önce denenir: klasör
  türünde, çöpte değil, bağlı hesabın **kendi** klasörü (paylaşılmış klasör
  seçilemez) ve içine küçük bir deneme dosyası yazılıp silinebiliyor. Klasör
  silinirse ya da çöpe atılırsa kopya başka yere gitmez, "klasörü yeniden
  seçin" hatasıyla durur. Klasör konumdur: kopyası duran hedefte (dosyası
  durabilen `konum_belirsiz` dahil) başka klasöre geçilmez; iki hedef aynı
  klasörü gösteremez (`HEDEF_AYNI_DEPO`). Pencere tarayıcıda açılır ve Google'a
  bağlı hesabın kısa ömürlü (~1 saat) erişim anahtarıyla bağlanır; yenileme
  anahtarı tarayıcıya gitmez.
- İstenen yetki en dar olanıdır (`drive.file`): uygulama yalnız **kendi
  oluşturduğu dosyaları ve kullanıcının pencerede seçtiği klasörü** görür;
  hesabın diğer Drive dosyalarına erişemez. Google'ın belgesi, seçilen klasöre
  bu yetkiyle yazılabildiğini açıkça yazmıyor; bu yüzden seçimde yazma
  gerçekten denenir (gerçek hesapla doğrulanacak, BY-021).
- Bağlantı bir kişinin hesabına bağlıdır. O kişi parolasını değiştirir, izni
  kaldırır ya da şirketten ayrılırsa kopyalama durur. Bu durum "sunucu dışı
  kopya başarısız" uyarısı üretir ve hedef ekranında "yeniden bağlanın" olarak
  görünür. Kişisel hesap yerine ortak bir şirket hesabıyla bağlanmak bu riski azaltır.
- Hesabın depolama kotası dolarsa kopyalama başarısız olur; kalan alan hedef
  ekranında gösterilir.
- Ön hazırlık (bir kez): Google Cloud'da bir proje açılır; Google Drive API ve
  Google Picker API etkinleştirilir; "Web uygulaması" türünde OAuth istemcisi
  açılıp istemci kimliği ve gizli anahtarı alınır (yönlendirme adresi
  `https://<site>/ayarlar/yedek-hedefleri/google-donus`, JavaScript kaynağı
  `https://<site>`); seçim penceresi için Picker API'yle ve sitenin adresiyle
  sınırlanmış bir API anahtarı açılır; proje numarası not edilir. Dördü
  sunucunun `.env`'ine yazılır (`YEDEKLEME_GOOGLE_ISTEMCI_KIMLIGI`,
  `_ISTEMCI_SIRRI`, `_API_ANAHTARI`, `_PROJE_NUMARASI`). **Dikkat:** uygulama kaydı "deneme" durumunda bırakılırsa Google
  yenileme anahtarını yaklaşık 7 günde geçersiz kılar ve yedekler bir hafta
  sonra sessizce durur. Bağlanacak hesaplar şimdilik **kişisel Gmail hesapları**
  olacağı için (kullanıcı kararı 2026-11-18) kayıt "herkese açık" türde açılıp
  "yayında" durumuna alınmalıdır. İleride Google Workspace'e geçilirse "kurum
  içi" türü daha uygundur. (Kod aşamasında Google'ın güncel kurallarıyla
  doğrulanacak.)
- Kişisel hesabın riski: hesap kişiye aittir. Kişi ayrıldığında yedekler onun
  Drive'ında kalır ve bağlantı kesilir; ücretsiz kota da 15 GB ile sınırlıdır
  (bugünkü yedek boyutuyla yıllarca yeter). Yedekler şifreli gittiği için
  hesap sahibi içeriği açamaz; yine de ortak bir şirket hesabına geçmek hedeftir.

### 4.7 Şifreleme

- Yedek dosyası bütün veriyi içerir (kullanıcılar, firma VKN'leri, şifreli
  bağlantı parolaları). Sunucu dışına **şifrelenmeden çıkmamalı**.
- Öneri: sunucu dışına giden kopya `gpg` ile açık anahtarla şifrelenir
  (sunucuda `gpg` kurulu). Özel anahtar sunucuda **tutulmaz**; böylece sunucu
  ele geçirilse bile dışarıdaki yedekler açılamaz. Geri yükleme için özel
  anahtar gerektiğinde elle sağlanır.
- Yerel yedeklerin şifrelenmesi isteğe bağlı (aynı sunucudaki veritabanı zaten
  şifresiz); dosya izinleri sıkı tutulur.
- **Anahtar kaybı = yedek kaybı.** Özel anahtarın nerede, kimde saklanacağı
  yazılı olmalı (§13).

### 4.8 Bildirim ve izleme

- Mevcut mail altyapısı (Mail Ayarları + alarm kuralları) kullanılır.
- Bildirim üreten durumlar: yedek başarısız, sunucu dışı kopya başarısız,
  doğrulama başarısız, disk eşiği aşıldı, geri yükleme başladı / bitti /
  başarısız, temizlik beklenmedik sayıda yedek sildi.
- **En önemli alarm "sessizlik" alarmıdır:** son başarılı yedeğin üzerinden
  beklenenden uzun süre geçtiyse uyarı. Zamanlayıcı ya da kuyruk durursa hiçbir
  "başarısız" kaydı oluşmaz; yalnız bu alarm yakalar.
- Sessizlik alarmı **uygulamanın dışında** izlenir. Uygulamanın kendi içinde
  kalırsa işe yaramaz: zamanlayıcı durursa denetim çalışmaz, kuyruk durursa mail
  gitmez, sunucu kapalıysa ikisi de olmaz. Yöntem: her başarılı yedekten sonra
  dışarıdaki bir izleyiciye "yaşıyorum" sinyali gider; sinyal gecikirse uyarıyı
  **o** üretir. İzleyici adayları: şirketin izleme sistemi (varsa) ya da başka bir
  sunucumuzda çalışan küçük bir denetim (§13).
- **Üç ayrı yaşam sinyali** izlenir: yerel yedek, sunucu dışı kopya, sınama geri
  yüklemesi. Yalnız yerel yedeğin sinyali, kopyanın haftalardır gitmediğini ya
  da yedeklerin geri yüklenemediğini gizler.
- **Geri yükleme bildirimleri veritabanına dayanmaz.** Mail ayarları ve kuyruk
  veritabanındadır: geri yükleme sırasında kuyruk kapalıdır, sonrasında ayarlar
  eskidir. Çalıştırıcı maili doğrudan, dosyadaki ayar kopyasıyla gönderir.
- Ekranın üstündeki özette üç zaman **ayrı ayrı** gösterilir: son başarılı yerel
  yedeğin veri zamanı, son doğrulanmış sunucu dışı kopyanın veri zamanı, son
  başarılı geri yükleme provasının zamanı. Ayrıca sıradaki planlı yedek, toplam
  yedek sayısı ve boyutu, boş disk alanı.

### 4.9 Yetki ve denetim kaydı

- İzinler mevcut katalog kalıbıyla (`App\Yetki\Izin`), öneri:

  | İzin | Kapsam |
  |---|---|
  | `yedekler.goruntule` | Listeyi ve durumu görme |
  | `yedekler.al` | Elle yedek alma, doğrulama başlatma |
  | `yedekler.sil` | Yedek silme, sabitleme |
  | `yedekler.indir` | Yedek dosyasını tarayıcıya indirme (varsayılan kapalı, §8) |
  | `yedek_planlari.goruntule` | Planları, saklama kurallarını ve hedefleri görme |
  | `yedek_planlari.guncelle` | Plan, saklama ve hedef tanımları |
  | `geri_yukleme.kopya` | Farklı adla geri yükleme |
  | — | Üstüne geri yükleme izinle verilmez; yalnız sistem yöneticisi |

  Roller ekranında iki satır olarak görünür (BY-004): **Yedekler**
  (görüntüle = `yedekler.goruntule`, güncelle = `yedekler.al`; ek izinler
  `yedekler.sil`, `yedekler.indir`, `geri_yukleme.kopya`) ve **Yedek Planları
  ve Hedefleri** (`yedek_planlari.goruntule` / `.guncelle`). Katalog her ekrana
  bir görüntüleme izni ister; bu yüzden `yedek_planlari.goruntule` eklendi. Ek
  izin verilen role ekranın görüntüleme izni de eklenir (mevcut kural).

- Her işlem kaydedilir: kim, ne zaman, hangi yedek, sonuç, süre, hata.
- Denetim kaydı veritabanına **ve** ayrıca bir günlük dosyasına yazılır;
  veritabanı geri yüklenince içindeki kayıtlar da eski hâline döner (§7.3).

### 4.10 Ekranlar (Ayarlar grubunda)

1. **Yedekler:** özet şeridi + liste (tarih, kaynak, boyut, doğrulama, sunucu
   dışı kopya, sabit mi, not). Satır işlemleri: doğrula, sabitle, farklı adla
   geri yükle, üstüne geri yükle, indir, sil. "Şimdi Yedek Al", "Klasörü Tara".
2. **Yedek Planları:** plan listesi, plan formu (sıklık, saat, hedefler, hedef
   başına saklama kuralı); sıradaki çalışma ve temizlik önizlemesi.
3. **Geri Yüklemeler:** geçmiş işlemler ve durumları; bekleyen kopyalar
   (`erp_geri_*`, `erp_onceki_*`) ve silme.
4. **Yedek Hedefleri:** sunucu dışı kopya tanımları, bağlantı sınama.
5. **İşlem Günlüğü:** denetim kaydı (süzgeçli).

Uzun işlemlerde ekran durumu yoklayarak gösterir (kuyrukta / çalışıyor /
adım adım ilerleme / bitti / başarısız); kullanıcı sayfadan ayrılabilir.

## 5. Mimari

### 5.1 Bileşenler

```
Ayarlar ekranları ──► API (yalnız istek kaydeder, işlemi başlatmaz)
                          │
          ┌───────────────┴────────────────┐
          ▼                                ▼
   Zamanlayıcı (dakikada bir)        Elle istek
   "vadesi gelen plan var mı?"             │
          └───────────────┬────────────────┘
                          ▼
                 Yedekleme çalıştırıcısı (ayrı süreç, kilitli)
                    │ pg_dump / pg_restore / özet / temizlik
                    ▼
        Yedek klasörü: <yedek>.dump + <yedek>.json (manifest)
                    │
                    ├──► Sunucu dışı hedef (şifreli kopya)
                    └──► Bildirim (mail) + denetim günlüğü (dosya + veritabanı)
```

### 5.2 Temel kararlar

- **İşlem web isteğinde çalışmaz.** İstek yalnız kayıt açar; işi ayrı bir
  süreç yapar. Yedek bugün 1 saniye sürse de veri büyüdükçe uzar; geri yükleme
  ise zaten web isteğinin içinde yapılamaz (§7.2).
- **Yedeklemeye ayrı kuyruk / ayrı çalıştırıcı.** Bugün tek kuyruk işçisi var
  ve alarm mailleri de onu kullanıyor; uzun bir yedek mailleri bekletmemeli.
- **Geri yükleme kuyruktan çalışmaz** — kuyruk tablosu geri yüklenen
  veritabanının içinde (§7.2). Geri yükleme, durumu **dosyada** tutan bağımsız
  bir artisan komutuyla yürür.
- **Doğruluk kaynağı dosyalardır, veritabanı değil.** Her yedeğin yanında bir
  manifest dosyası durur. Veritabanındaki yedek listesi bir "dizin"dir;
  klasör taranarak her zaman yeniden kurulabilir (§7.3).
- **Kilit ve işlem durumu veritabanına dayanmaz.** Önbellek de veritabanında
  olduğu için uygulamanın olağan kilidi geri yükleme sırasında kaybolur. Tek
  sunuculu yapıda işletim sistemi düzeyinde dosya kilidi ve dosyada tutulan
  işlem durumu kullanılır. **`deploy.sh` yayın kilidini alır:** yayın ile
  üstüne geri yükleme aynı anda çalışmaz (uygulamadaki karar aşağıda).
- **Yedeği de geri yüklemeyi de web süreci değil, ayrı bir servis hesabı yapar.**
  Web sürecinin veritabanı kullanıcısına veritabanı oluşturma (`CREATEDB`)
  yetkisi verilmez. Çalıştırıcı kendi işletim sistemi kullanıcısı ve
  kendi veritabanı rolüyle çalışır; yalnız izinli işlemleri ve kendi oluşturduğu
  veritabanlarını (`erp_geri_*`, `erp_onceki_*`) yönetir. Web tarafı yalnız
  "istek" bırakır ve yedek klasörünü **yalnız okur** (yetki matrisi §5.5, §9).
- **İmza anahtarı yalnız servis hesabındadır.** Yedeği web süreci (`www-data`)
  alıp imzalasaydı, ele geçirilen bir web süreci zararlı bir dosyayı da
  imzalayabilir ve yedek dosyalarını doğrudan silebilirdi; imza hiçbir şey
  kanıtlamazdı. Ele geçirilen web süreci yedekleri **ekrandaki yetkili
  kullanıcının yapabildiği** yoldan (istek) sildirebilir — sınır aşağıdaki
  "Çalıştırıcı isteğe güvenmez" maddesinde, kullanıcı kararıyla.
- **Çalıştırıcı isteğe güvenmez.** İstek dosyasını web süreci yazar; çalıştırıcı
  isteği yapanın yetkisini ve (geri yüklemede) onayını kendisi yeniden doğrular.
  *Uygulamada verilen karar (BY-025-R1, R2):* göndereni, isteğin içeriğinden
  bağımsız olarak dosyanın çekirdekteki **sahibi** (uid) belirler: kullanıcılı
  istekler yalnız web kullanıcısının, kullanıcısız yayın öncesi yedek yalnız
  yayın kullanıcısının dosyasıysa işlenir (uid'ler servis tanımında, kurulum
  betiği yazar); tanınmayan sahip reddedilir ve denetim kaydına yazılır.
  İstekteki kullanıcı kimliği **web'in iddiasıdır**; yetkisi işlem anında
  veritabanından, son kez yedekleme kilidi altında işleyiciden hemen önce
  denetlenir. Tehdit modeli: ele geçirilmiş web hesabı başka bir kullanıcı adına
  istek yazabilir — ama veritabanına zaten yazabildiği için (kullanıcı ve rol
  tabloları dahil) sınır budur; artık yayın istisnasını taklit edemez.
  *Kullanıcı kararı (2026-11-21, BY-025-R4):* web'den istenen **yedek silme**
  ve **üstüne geri yükleme** için web'den bağımsız ikinci bir onay
  (sunucuda SSH ile onay ya da doğrulama uygulaması kodu) eklenmedi; risk
  kabul edildi. Anlamı: web hesabını ele geçiren, `yedekler.sil` /
  geri yükleme yetkili bir kullanıcının ekrandan yapabildiği her şeyi
  çalıştırıcıya yaptırabilir (imzalı yerel arşivi ve kopyalarını sildirmek,
  veritabanının üstüne eski bir yedeği yükletmek). Bunlara karşı kalan
  korumalar (sabitleme bunlardan biri değildir: sabitlemeyi kaldırma da
  ekrandan istenir): saklamanın kendi silmeleri web'den bağımsızdır; değiştirilemez saklamalı hedef (S3 nesne kilidi)
  uygulamanın silme isteğini reddeder; üstüne geri yükleme `erp_onceki_*`
  kopyasını ve öncesi yedeğini bırakır; her istek denetim kaydına yazılır.
  Ele geçirilmiş web'e karşı yedeklerin silinmezliği isteniyorsa yol,
  değiştirilemez saklamalı bir sunucu dışı hedeftir.
- **Yayın kilidi (BY-013-R1, BY-029-R1).** `deploy.sh`, modül açıksa bakım
  modu / kod çekme / migration'dan önce ayrı bir yayın kilidini
  (`<durum klasörü>/yayin.kilit`, `flock`) alır ve betik bitene kadar tutar;
  kilit altında açık geri yüklemeyi iki yolda da (yedekli ve `--no-backup`)
  denetler, açık işlem varsa hiçbir şeye dokunmadan çıkar. Üstüne geri
  yükleme ilk durumunu yazmadan önce kilidi beklemeden almayı dener; yayın
  sürüyorsa başlamaz (`GERI_YUKLEME_YAYIN_SURUYOR`). Kilit, yedek işlem
  kilidinden ayrıdır: yayın öncesi yedek servisten istenirken ikisi birbirini
  beklemez. Yayın yalnız kendi açtığı bakım modunu kapatır.
- **Geçiş dönemi:** servis hesabının Linux tarafı kurulana kadar (BY-025) ilk aşamalarda
  yedeği uygulama kullanıcısı alır. Bu dönemde imza yalnız "bizim ürettiğimiz
  dosya" ile "dışarıdan gelen dosya"yı ayırır; ele geçirilmiş bir web sürecine
  karşı koruma sağlamaz. Üstüne geri yükleme servis hesabı olmadan açılmaz.
- **Nesnelerin sahibi uygulama rolü kalır.** Geri yüklemeyi başka bir rol
  yaptığında tablolar o rolün olur ve yer değiştirmeden sonra uygulama kendi
  tablolarını okuyamaz. **Doğrulanan yöntem** ([kesif.md](kesif.md) §4):
  servis hesabı veritabanını `CREATE DATABASE … OWNER <uygulama rolü>` ile açar
  ve `pg_restore --no-owner --role=<uygulama rolü>` ile geri yükler. Böylece
  tablolar ve `public` şeması uygulama rolünündür; migration geri yüklenen
  veritabanına tablo açabilir. Bunun koşulu: **servis hesabı uygulama rolünün
  üyesidir.** Anlamı: servis hesabı uygulamanın veriye erişebildiği her şeye
  erişir. Ayrı hesabın veritabanı tarafındaki değeri, uygulamanın kendisinin
  veritabanı açma yetkisi (`CREATEDB`) taşımamasıdır; `erp`'nin adını
  değiştirmek de bu yetkiyi ister. Bağlantı koparma ayrım sağlamaz: bir rol,
  kendi rolünün bağlantılarını zaten sonlandırabilir. Asıl ayrım sunucu
  tarafındadır: yedek dosyaları, imza anahtarı ve parola dosyası web
  sürecinden ayrı tutulur (§5.5).
- **Dış komutlar kabuk metniyle değil, argüman dizisiyle çalıştırılır**
  (Laravel `Process`). Parola komut satırında ya da ortam değişkeninde durmaz;
  yalnız çalıştırıcının okuyabildiği bir parola dosyasından okunur
  (`PGPASSFILE`, izin 0600).
- **Zamanlama mevcut kalıbı izler:** e-Fatura senkronundaki gibi tek komut
  dakikada bir çalışır, veritabanındaki planlara bakıp vadesi geleni başlatır.
  Planlar arayüzden değişir, kod yayını gerekmez. Servis kipinde planlı yedek
  ve günlük temizlik Laravel zamanlayıcısında (web hesabı) değil, servisin
  döngüsünde (`yedek:calistir`) yürür (BY-009-R1).

### 5.3 Manifest (her yedeğin yanındaki `.json`)

Yedeği tek başına tanımlamaya yeten bilgi: benzersiz kimlik, alınma zamanı
(saat dilimiyle), kaynak (elle/plan/yayın/geri yükleme öncesi), plan adı,
veritabanı adı, PostgreSQL ve `pg_dump` sürümü, uygulama sürümü (git commit),
**uygulanmış migration'ların tam listesi**, dosya boyutu, SHA-256, tablo başına
satır sayısı (yedekle aynı anlık görüntüden; verisi dışarıda bırakılan tablolar
için beklenen sayı 0), verisi dışarıda bırakılan tablolar, şifreli mi, şifreli
alanların hangi `APP_KEY` ile yazıldığını gösteren **anahtar parmak izi**
(anahtarın kendisi değil), not, alan kişi.

Manifest ayrıca veritabanının, **tasarımın geri yükleme yolunda uygulanmayan**
özelliklerini kaydeder: kodlama, sıralama kuralı, veritabanı düzeyindeki
ayarlar (ör. saat dilimi) ve bağlanma yetkileri. Bunlar özel biçimli yedek
arşivinde vardır, ama `pg_restore` onları yalnız `--create` ile uygular;
`--create` ise veritabanını arşivdeki adla açtığı için farklı ada geri
yüklemede kullanılamaz ([kesif.md](kesif.md) §7). Geri yüklemede yeni
veritabanı manifestteki değerlerle oluşturulur; yoksa geri yüklenen veritabanı
sessizce farklı davranır (sıralama, saat). Manifesti olmayan eski yedeklerde
bu bilgiler arşivden okunabilir (`pg_restore --create --file=-` çıktısı).

Nesnelerin sahibi olan rol de manifeste yazılır, ama nedeni farklıdır: tablo
sahiplikleri `--create` olmadan da arşivden uygulanır; tasarım bunu bilerek
`--no-owner` ile kapatır ve sahipliği `--role=<uygulama rolü>` ile verir (§5.2).
Manifestteki sahip bilgisi, geri yüklemenin doğru role yapılıp yapılmadığını
denetlemek içindir.

Manifest, veritabanı dışında saklanan bir anahtarla **imzalanır**; sistemin
kendi ürettiği yedeği dışarıdan gelen dosyadan ayıran budur (§4.5).

Manifest sayesinde: (a) veritabanı kaybolsa da yedek listesi kurulur,
(b) geri yüklemeden önce şema uyumu denetlenir, (c) başka sunucuya taşınan
yedek kendini tanıtır.

**Sonradan değişen bilgiler ayrı tutulur.** Manifest yedek alındığı anda yazılır
ve değişmez. Sonradan değişenler — sabitleme, doğrulama sonuçları, sunucu dışı
kopyanın kimliği ve durumu, silinme — yedek klasöründeki **salt eklemeli bir
olay günlüğüne** yazılır. Dizin yeniden kurulurken manifest ile olay günlüğü
birlikte okunur. Böylece eski bir yedeğe dönüldüğünde, bugün sabitlenmiş bir
yedek sabitliğini kaybedip temizliğe girmez.

**Kopyanın yeri de kaydedilir (BY-028-R5).** Kopya kaydı ve kopya olayı,
kopyanın yapıldığı yerin değişmez izini taşır: hedefin konum alanlarının
(sunucu, port, kullanıcı, klasör, kova, Google hesabı…) normalleştirilmiş
hâlinin SHA-256'sı (`HedefKonumu::iz`; sır içermez). Dizin kurulurken olaydaki
iz hedefin bugünkü iziyle uyuşmayan — ya da izi olmayan eski — kopya hedefe
`tamam` bağlanmaz, **"konumu doğrulanamadı"** (`konum_belirsiz`) olur: ekranda
uyarıyla görünür, geri yükleme kaynağı, silme, saklama ve kurtarma hazırlığı
onu kopya saymaz, hedefin konum kilidini tutmaz. Dosyası bir yerde
durabileceği için yedeği ise `silindi` yaptırmaz (F7; BY-028-R7): yerel
dosyası olmayan yedek varsayılan listede kopyanın uyarısıyla kalır, elle
silmede "kopya silinemedi" sonucu döner ve "tamamen silindi" olayı yazılmaz.
Hedefin bugünkü tanımı yedek klasörünün kendisi (ana klasör) olsa da böyledir:
"ana klasör hedefinin kopyası yerel dosyanın kendisidir" eşitliği yalnız yeri
doğrulanmış (`tamam`) kopyaya uygulanır; iz uyuşmayan kopya başka bir yerde
(ör. ikinci disk) durabilir (G21; BY-028-R8).
Örnek: eski yedeğin anında
kopyasız olan hedef sonra yer değiştirip yeni yerde kopya almışsa, eski
veritabanı geri yüklenince hedef eski yerindedir; yeni yerdeki kopya ona
bağlanmaz. Hedef kopyanın yerine döndürülünce sonraki taramada kopya yeniden
`tamam` olur. Konumu bilinemeyen hedefe (hesabı okunmamış Google bağlantısı)
kopya gönderilmez.

**Hedefin tanımı yoksa kopya yine görünür (BY-028-R9).** Geri yüklenen eski
veritabanında, yedekten sonra eklenmiş bir hedefin tanımı bulunmaz; olay
günlüğü o hedefteki kopyayı (uzak kimliği ve konum iziyle) yine anar. Dizin
kurulurken olaydaki tamam kopyanın hedefi önce olaydaki kimlik / adla aranır;
bulunamazsa ya da bulunan hedefin izi uyuşmazsa izi aynı olan hedef aranır
(aynı yer yeni kayıtla — başka kimlik ve adla — eklenmiş olabilir). Kopyanın
yeri yalnız iz eşitliğiyle belirlenir; günlükten hedef kimliği ya da konumu
tahmin edilmez, tarama hiçbir dosya işlemi yapmaz. Hiçbir hedef eşleşmezse
kopya atlanmaz: hedefsiz, "konumu doğrulanamadı" kayıt olarak tutulur (hata
kodu `KOPYA_HEDEFI_TANIMSIZ`, ekranda olaydaki hedef adıyla). Diğer konumu
belirsiz kopyalar gibi yedeği `silindi` yaptırmaz ve saklamada "kalan kopya"
sayılır, ama geri yükleme, silme, saklama ve kurtarma hazırlığının kaynağı
olmaz; hedefe dayanan kararlar (kurtarma paketi, hesap değişikliği, konum
kilidi) onu görmez. Kullanıcı kopyanın yerini gösteren hedefi yeniden
eklediğinde sonraki taramada kopya o hedefe `tamam` bağlanır ve hedefsiz kayıt
kalkar; olaydaki kimlik başka bir yere verilmiş bir hedefte bulunursa kopya
önce ona `konum_belirsiz` bağlanır, aynı yer eklenince oradan taşınır.

**Aynı hedefe düşen kopyalar birbirini ezmez (BY-028-R10).** Konum iziyle
eşleme, olay günlüğünde farklı hedeflere yazılmış kopyaları (ör. tanımı
olmayan eski hedef ve aynı yere yeni kayıtla eklenen hedef) aynı güncel hedefe
bağlayabilir. Bunlar ayrı fiziksel nesneler olabilir (Google Drive'da aynı
klasörde iki dosya). Kopya kaydı hedef başına tektir; eşleme şöyle karar verir:
aynı yerdeki aynı uzak kimlik tek nesnedir, durumu son olayınkidir (üstüne
yazılan da, sonradan silinen de odur). Silinmiş nesne yaşayan nesnenin kaydını
ezmez. Aynı hedefte birden çok yaşayan nesne varsa hedefe biri bağlanır: önce
yeri doğrulanmış (`tamam`) olan, sonra olayı o hedefi kendi kimliği / adıyla
anan, sonra en son olaylı. Diğerleri kaybolmaz: hedefsiz, "konumu
doğrulanamadı" kayıt olarak listede kalır (hata kodu
`KOPYA_HEDEFTE_BASKA_KOPYA`); hiçbir işlemin kaynağı olmaz, yedeği tutar.
Hedefe bağlı nesne silinince sonraki taramada sıradaki nesne hedefe bağlanır.
Silme olayı silinen nesnenin uzak kimliğini ve konum izini taşır; yalnız o
nesneye uygulanır — kayıt konum iziyle bu hedefe bağlanmış başka bir olay
hedefinin kopyasıysa ona; aynı hedefin olayındaki farklı nesneye değil. Uzak
kimliksiz eski silme olayı, eskisi gibi yalnız kendi hedefindeki kopyaya
uygulanır. Sonuç olayların sırasına bağlı değildir; tarama tekrarlanınca
aynıdır.

**Ayarlar da geri döner.** Planlar, saklama kuralları ve hedef tanımları
veritabanında durduğu için üstüne geri yüklemede eski hâllerine döner. Her
değişiklikte bu ayarların bir kopyası dosyaya yazılır — yalnız commit olmuş
ayarlardan, veritabanından yeniden okunarak (BY-018-R2): commit düşerse dosya
değişmez; commit'ten sonra yazılamazsa ayar kaydedilmiş sayılır, ekranda "kopya
güncel değil" görünür ve çalıştırıcının her turu (doğrudan kipte zamanlayıcının
dakikalık turu, servis kipinde servis döngüsü) farkı görüp yazar. Veritabanındaki şifreli
ayar (SMTP parolası) çözülemiyorsa kopya üretilemez: kayıt yine başarılıdır, denetime
`AYAR_KOPYASI_AYAR_COZULEMEDI` düşer, ekran "kopya güncel değil" gösterir (BY-018-R3). Geri yüklemeden sonra
dosyadaki güncel ayarlar ile veritabanındaki eski ayarlar karşılaştırılır; fark
yöneticiye gösterilir ve hangisinin geçerli olacağı sorulur (§6.4).

### 5.4 Veri modeli (öneri; Türkçe adlandırma mevcut kalıpta)

| Tablo | İçerik |
|---|---|
| `yedek_planlari` | ad, sıklık tanımı, saat, etkin, son/sıradaki çalışma |
| `yedek_plan_hedefleri` | plan ↔ hedef; o hedefteki saklama kuralı (son N yedek, N gün) |
| `yedek_ayarlari` | zamanlanmamış yedeklerin süreleri, hedef başına en az sayı, bildirim alıcıları |
| `yedekler` | manifestin dizini: kimlik, dosya adı, kaynak, boyut, özet, durum, doğrulama durumu, sabit, not |
| `yedekler` (ek) | `yerel_silinme_zamani`: yedek klasöründeki dosya silindi ama uzak kopya duruyor olabilir; durum değişmez |
| `yedek_kopyalari` | bir yedeğin bir hedefteki TEK fiziksel kopyası (yedek + hedef tekil): durum, şifreli kopyanın özeti, hata, silinme zamanı |
| `yedek_kopya_planlari` | kopyayı tutan planlar; aynı hedefi paylaşan planlar tek kopyaya bağlanır, her plan kendi bağını kaldırır, dosya bağ kalmayınca silinir. Bağ planın o hedefteki saklama kuralına (`yedek_plan_hedefleri`) bileşik anahtarla bağlıdır: hedefi kullanmayan plan bağlanamaz, bağ varken kural silinemez |
| `yedek_hedefleri` | hedef tanımları: tür (yerel / FTP / FTPS / SFTP / Google Drive / …), bağlantı bilgileri (şifre `encrypted` cast) |
| `geri_yuklemeler` | hangi yedek, tür (kopya/üstüne), hedef ad, isteyen, durum, adımlar, hata |
| `yedek_islem_kayitlari` | denetim: kim, ne, ne zaman, sonuç |

Yedek durumları: `kuyrukta → calisiyor → tamam | basarisiz`; ayrıca `silindi`
(yedeğin hiçbir yerde kopyası kalmadı; kayıt kalır — geçmiş kaybolmaz). Yalnız
yerel dosya gittiyse durum `silindi` olmaz (§4.2).

### 5.5 Dosya düzeni

- Yedek klasörü servis hesabının yazdığı, uygulamanın yalnız okuduğu, web'den
  **erişilemeyen** bir yerde: `/var/lib/emor-yedek` (kurulumu BY-003 betiği).
- Ad kalıbı: `erp_<kaynak>_<YYYY-AA-GG_SSddss>.dump` + aynı adla `.json`.
- Bugünkü `/var/backups/emor` klasörüne uygulama yazamıyor (§9). Karar (BY-003):
  yeni klasöre geçilir; eski yedekler taranıp içeri alınır (BY-013).

**Dosya yetkileri (hedef düzen; kurulum betiğinde yazılı durur — BY-003):**

| Dosya / klasör | Servis hesabı | Uygulama (`www-data`) | Yayın kullanıcısı (`admin`) |
|---|---|---|---|
| Yedek klasörü, manifestler, olay günlüğü | okur, yazar | yalnız okur | erişmez |
| İstek klasörü | okur, siler | yazar | yazar (yayın öncesi yedek isteği) |
| İşlem durumu dosyası | okur, yazar | yalnız okur | yalnız okur |
| Kilit dosyası | alır | alır | alır |
| İmza anahtarı, parola dosyası | yalnız okur | erişmez | erişmez |
| Ayar kopyası, denetim günlüğü | okur, yazar | yalnız okur | erişmez |

Yedek kökünün (`/var/lib/emor-yedek`) sahibi **root**'tur; alt klasörlerin
sahibi servis hesabı. Servis hesabı alt klasörleri silemez, yerine bağlantı
koyamaz: root'un çalıştırdığı kurulum betiği onları izleyip yönetilen alanın
dışındaki bir dosyaya dokunamaz (BY-003-R5, sunucu-kurulumu.md §2).

**İstek klasörünün alt düzeni (BY-025):** `bekleyen/` (web ve yayın
kullanıcısı yazar), `isleniyor/` (çalıştırıcının üstlendiği istek; tek adımlık
taşımayla alınır, iki çalıştırıcı aynı isteği alamaz) ve `sonuc/` (çalıştırıcı
yazar, ekran yoklar). `isleniyor/` ve `sonuc/` yalnız servis hesabına yazılabilir
(web okur): istek taşındıktan sonra denetlenir, web süreci onu bağlantıyla
değiştiremez; sonuç da web sürecince uydurulamaz. Yayın kullanıcısı `bekleyen/`'e
yazabilmeli ve `sonuc/`'u okuyabilmeli (kurulum betiği). Çalıştırıcı isteğe
güvenmez: düz dosya, boyut, şema (anahtarlar birebir, değerlerin türü ve
biçimi), 24 saatten eski olmama ve kullanıcının yetkisi (veritabanından, işlem
anında) denetlenir; reddedilen istek makine okunur kodla `basarisiz` sonuç alır.

**İstek kabulü (BY-014-R1/R2, BY-030-R3).** Ekran istekleri ve üstüne geri
yüklemenin kararları aynı sözleşmeden geçer (`YedekIstekServisi`): önce denetim
kaydı, sonra yayın; denetim yazılamazsa istek yayınlanmaz. İstek dosyası
`bekleyen/`'e taşındıktan sonraki hata (klasör eşitlenemedi) isteği başarısız
göstermez: istek çalıştırıcıya görünmüştür, kabul (202) döner, denetime
belirsizlik notu düşer. İstemci her eylem için bir `Idempotency-Key` üretir ve
yeniden denemede aynısını gönderir — bekleyen anahtar tarayıcıda
(`localStorage`, kullanıcı ya da işlem kapsamında, 15 dakika) durur, sayfa
yenilense de kaybolmaz, kabulde silinir (BY-014-R3, BY-030-R4). Kabul yalnız
kabul edilen anahtarı siler: eski isteğin geciken 202'si, aynı eylem için
sonradan üretilmiş yeni anahtarı silmez (BY-014-R8). Depoyu okuyup
yazan her adım (eski sürüm taşıması, anahtar yazımı, kabul temizliği) sekmeler
arası tek bir Web Locks kilidi altındadır; Web Locks yoksa depo kullanılmaz,
anahtar yalnız sayfanın belleğinde tutulur (BY-014-R7); istek kimliği bu anahtar ile eylemden
(tür, kullanıcı, parametreler) türetilir. O kimlikle istek (bekleyen /
işleniyor / sonuç) varsa ikinci dosya yazılmaz, mevcut kabul döner; bakış ve
yazım `bekleyen/.kabul.kilit` dosya kilidi altında tek adımdır.

`deploy.sh` `pg_dump`'ı kendisi çalıştırmaz; yayın öncesi yedeği çalıştırıcıdan
ister ve bitmesini bekler. Böylece yayın öncesi yedekler de imzalı, manifestli
ve aynı kilit altında alınır.

## 6. Akışlar

### 6.1 Yedek alma

1. Kilit al (başka işlem varsa bekle ya da vazgeç).
2. Boş disk alanını denetle.
3. Anlık görüntü aç; satır sayılarını ve migration listesini **o görüntüden**
   oku; `pg_dump`'ı aynı görüntüyle çalıştır (`--snapshot`) → geçici dosya.
4. Özet hesapla, `pg_restore --list` ile oku, manifesti yaz ve imzala.
5. Geçici dosyayı asıl adına taşı; veritabanı dizinine kaydet.
6. Yedeği, o anda vadesi gelen bütün planların hedeflerine kopyala; her kopya
   ayrı kaydedilir, biri başarısız olursa diğerleri etkilenmez.
7. Her plan + hedef için saklama kuralını uygula.
8. Sonucu kaydet; başarısızsa bildirim.

### 6.2 Temizlik

Saklama kuralına göre tutulacak yedekleri hesapla → koruma kurallarını ve en az
sayıyı uygula → kalanları sil → her silmeyi nedeniyle kaydet. "Önce hesapla, sonra göster" modu: ekranda
"bu kuralla şu yedekler silinecek" önizlemesi (kural değiştirirken sürpriz olmasın).

### 6.3 Farklı adla geri yükleme

1. Yedeğin imzasını ve özetini doğrula.
2. Hedef adı üret, yokluğunu denetle.
3. Boş veritabanını manifestteki kodlama, sıralama ve ayarlarla oluştur.
4. Geri yükle: `pg_restore --exit-on-error --single-transaction`. Varsayılan
   davranış hatalara rağmen devam eder ve yarım bir veritabanını "başarılı"
   bırakır; bu iki seçenekle ya tamamı yüklenir ya hiçbiri.
5. Satır sayılarını ve migration listesini manifestle karşılaştır.
6. Kaydet, bildir. Hata olursa yarım veritabanını sil.

### 6.4 Üstüne geri yükleme

Ekrandan başlatılır; ilerleme ve onay da ekrandadır (§4.5, §7.2). Her adım,
işlem durumu dosyasına yazılır. Aynı işlemler acil durum için komut satırından
da yapılabilir.

1. Ön denetimler: imza ve özet, şema uyumu (§4.5), disk alanı, kilit (başka
   yedek / geri yükleme / yayın çalışmıyor). Çalıştırıcı, isteği yapanın
   yetkisini ve onayını kendisi doğrular.
2. Bakım moduna al; zamanlayıcıyı ve kuyruk işçisini durdur; işlem durumunu
   "geri yükleme sürüyor" olarak yaz. (Kuyruk işçisi servis olarak durdurulur.
   Zamanlayıcının servisi durdurulmaz: Laravel bakım modunda zamanlanmış işleri
   çalıştırmaz; bakım modu dosyadadır, veritabanına dayanmaz — §9.)
3. **Geri yükleme öncesi yedek** al ve doğrula. Bu yedek bakım modundan
   **sonra** alınır: önce alınsaydı, arada girilen kayıt yedekte olmaz ve
   `erp_onceki_*` silinince tamamen kaybolurdu. Başarısızsa servisler açılır, dur.
4. Yeni veritabanını (`erp_geri_<zaman>`) canlıyla aynı kodlama, sıralama ve
   ayarlarla oluştur (§5.3); yedeği geri yükle
   (`pg_restore --exit-on-error --single-transaction`, nesnelerin sahibi
   uygulama rolü — §5.2).
5. Bekleyen migration'ları **yeni veritabanında** çalıştır; satır sayılarını
   manifestle karşılaştır; sağlık denetimi yap (bağlanılıyor mu, temel tablolar
   okunuyor mu, uygulama rolü tabloları okuyup yazabiliyor mu).
   Migration **ayrı bir süreçte**, bütün veritabanı ayarı yeni veritabanını
   gösterecek şekilde çalışır. Yalnız bağlantı adını değiştirmek yetmez:
   önbelleği, kuyruğu ve varsayılan bağlantıyı kullanan kod canlı `erp`'ye gider.
   Canlıyı gösteren bağlantılarda DSN'i `database` / `port`'tan önce kuran
   yönlendirme alanları (`connect_via_database`, `connect_via_port`, `read`,
   `write`, `direct`, `pooled` — DB_URL sorgu parametresinden de gelir)
   kaldırılır: çocuk süreç pg_restore'un yazdığı uç noktaya (host:port) ve yeni
   veritabanına bağlanır (BY-028-R6).
   Buraya kadar canlı veritabanına dokunulmadı. 4. ya da 5. adımda hata olursa
   yeni veritabanı silinir, servisler başlatılır, bakım modundan çıkılır; veri
   aynen durur.
6. `erp`'yi **önce yeni bağlantılara kapat** (`ALLOW_CONNECTIONS false`), sonra
   mevcut bağlantıları kopar (`pg_terminate_backend`). Sıra önemlidir: önce
   koparılırsa araya giren tek bir bağlantı ad değiştirmeyi başarısız kılar.
   Kapatma, yöneticinin yeni bağlantılarını da engeller; açık bağlantılar
   kendiliğinden kopmaz ([kesif.md](kesif.md) §6).
7. Yer değiştir: `erp` → `erp_onceki_<zaman>`, `erp_geri_<zaman>` → `erp`. İki
   ad değişikliği **tek işlem** içinde yapılır; ikincisi hata verirse birincisi
   de geri alınır, adlar değişmez (lokal PostgreSQL 16'da doğrulandı —
   [kesif.md](kesif.md) §5). Bağlı olunan veritabanının adı değiştirilemediği
   için işlem `postgres` veritabanına bağlanılarak yapılır. Yeni `erp`
   bağlantıya açılır; `erp_onceki_*` kapalı kalır, kimse yanlışlıkla bağlanamaz.
8. Yedek dizinini klasörden yeniden kur (§7.3); ayarları uzlaştır (§5.3);
   önbellekleri temizle.
9. Durum **"onay bekleniyor"** olur. Bakım modu sürer; zamanlayıcı ve kuyruk
   **kapalıdır**. Yöneticiye gösterilir (§7.6):
   - aktif ERP ortamı (test / canlı), e-Fatura senkron durumu, alarm kuralları,
   - ayar farkları (planlar, hedefler),
   - **kullanıcı ve rol farkı:** `erp_onceki_*` ile karşılaştırılır; yedekten
     sonra pasife alınan kullanıcılar ve kaldırılan izinler geri gelmiştir.
10. Yönetici onaylarsa kuyruk işçisi ve zamanlayıcı başlar, bakım modundan
    çıkılır. Onaylamazsa "geri dön" seçer: adlar eski hâline çevrilir, eski
    veritabanıyla açılır.
11. Denetim günlüğüne yaz; bildir (doğrudan mail — §4.8).

**Hata kuralı:** hiçbir hata yolu servisleri koşulsuz açmaz. 7. adımdan önceki
hatada eski veritabanı yerinde olduğu için açmak güvenlidir. 7. adımdan sonra
açma kararı yalnız yöneticinindir.

**Yeniden başlatmaya dayanıklılık.** "Geri yükleme sürüyor" ve "onay bekleniyor"
durumları sunucu yeniden başlasa da korunmalıdır:

- Sunucu yeniden başlarsa işletim sistemi kuyruğu ve zamanlayıcıyı kendiliğinden
  açar. Bu servisler başlarken işlem durumu dosyasına bakar ve durum açık bir
  geri yükleme gösteriyorsa çalışmayı reddeder.
- Dosya kilidi, onu tutan süreç bitince düşer. Bu yüzden kilit tek başına
  yetmez: `deploy.sh` ve planlı yedek ayrıca işlem durumu dosyasına bakar; açık
  bir geri yükleme varken başlamaz.
- Onay ve geri dönüş için her zaman bir **komut satırı yolu** vardır. Ekrandaki
  erişim anahtarı kaybolsa da sistem bakım modunda kilitli kalmaz.

**Yarıda kalan işlem.** Süreç herhangi bir adımda ölürse (elektrik, bellek,
elle durdurma) durum dosyası son tamamlanan adımı gösterir. Devam komutu buna
göre karar verir: 7. adımdan önce kaldıysa yeni veritabanını silip servisleri
açar (geri sarar); 7. adım tamamlandıysa 8. adımdan devam eder. İki ad
değişikliği tek işlemde yapıldığı için 7. adımın ortasında kalma ihtimali
yoktur. Durum dosyasının yazılmasından önce ölünürse adlar okunarak hangi
tarafta kalındığı anlaşılır (`erp_geri_*` duruyor mu, `erp_onceki_*` var mı).

**Açıldıktan sonra geri dönüş ayrı bir işlemdir.** Uygulama yeniden yazmaya
açıldıktan sonra `erp_onceki_*`'e dönmek, arada girilen kayıtları kaybettirir.
Bu, 10. adımdaki "geri dön" ile aynı şey değildir; yeni bir üstüne geri yükleme
gibi ele alınır (önce yedek, onay, aynı akış).

Yer değiştirme yöntemi seçildi, çünkü canlı veritabanının içine doğrudan
geri yükleme (`pg_restore --clean`) yarıda kalırsa ortada ne eski ne yeni,
bozuk bir veritabanı bırakır.

**Uygulamada verilen kararlar (BY-028…030):**

- Adımlar tek enum'dadır (`UstuneGeriYuklemeAdimi`); her adım işi yapılmadan
  önce durum dosyasına yazılır. İşlemin kimliği, ekranın bıraktığı isteğin
  kimliğidir. Çalıştırıcı işleyicisi 9. adımda döner (tek çalıştırıcı kilidi:
  dönmezse onay isteği işlenemezdi); yarıda kalan işlemi her turun başında
  (en çok 10 dakikada bir) sürdürür ya da geri sarar. 7. adımdan sonraki
  hatayla durmuş işlem kendiliğinden sürdürülmez: karar yöneticinindir.
- Bakım modu: servis yalnız `storage/framework/down` JSON dosyasını
  (Laravel'in biçimi + işlem kimliği) yazar; `artisan down` çağrılmaz, çünkü
  o komut web'in her istekte yüklediği `maintenance.php`'yi de yazar (D7).
  Yol ya da klasörü bağlantıysa yazılmaz; yalnız kendi dosyasını kaldırır.
  Uygulama zaten bakım modundaysa işlem başlamaz.
- Yayınla çakışma (BY-013-R1, BY-029-R1; §5.2 "Yayın kilidi"): işleyici yayın
  kilidini ilk durumdan önce beklemeden alır, dönene kadar tutar; sonra yayın
  açık işlemi durum dosyasından görür. `deploy.sh` başta bakım modu zaten
  açıksa (`storage/framework/down` var) `down` / `up` çağırmaz: geri yüklemenin
  bakım modunu kaldıramaz.
- Kuyruk servisi `YEDEKLEME_KUYRUK_SERVISI` tanımlıysa `sudo -n systemctl
  stop|start` ile yönetilir (kurulum betiğinin dar sudo kuralı); başlatma
  hatası uygulamanın açılmasını engellemez, bildirilir. Ayrıca işçi ve
  zamanlayıcı açık işlemi durum dosyasından görür ve iş çalıştırmaz
  (`AcikGeriYuklemeKorumasi`; modül kapalıyken etkisiz).
- 5. adımdaki migration süreci yapılandırmayı bu sürecin yapılandırmasından
  üretilmiş geçici bir önbellek dosyasından okur (`APP_CONFIG_CACHE`): canlıyı
  gösteren her bağlantı yeni veritabanını gösterir; önbellek / oturum / kuyruk
  veritabanına dayanmaz. Dosya parola taşıdığı için 0700 geçici klasörde
  durur, her durumda silinir.
- 9. adımdaki karşılaştırmanın "önceki" yanı, `erp_onceki_*` açılarak değil,
  6. adımdan hemen önce canlıdan alınır (o anda bakım modu, kuyruk ve
  zamanlayıcı durmuş, geri yükleme öncesi yedek alınmış: veri aynıdır). Böylece
  bağlantıya kapalı eski veritabanı hiç açılmaz. İki yan da durum klasöründe
  dosyadadır; ilerleme ucu veritabanına dayanmadan okur.
- Erişim anahtarı 8 saat geçerlidir. İlerleme, onay ve geri dön uçları
  oturumsuzdur (yer değiştirmeyle oturumlar düşer); tek yetki anahtardır.
  Anahtar hiçbir dosyaya yazılmaz (BY-030-R1): web ucu anahtarı doğrular,
  isteğe kısa ömürlü karar kanıtı yazar — `HMAC-SHA256(anahtar = özet,
  işlem|karar|son geçerlilik|nonce|sha256(ayar seçimleri))`, son geçerlilik en
  çok 5 dakika. Çalıştırıcı kanıtı işlem durumundaki özetle sabit sürede
  doğrular (kullanıcı tablosuna bakmaz: o tablo yedeğin anındadır), süresi
  geçmişse uygulamaz, nonce'u işlem durumunda tüketir (tek kullanım; süresi
  geçmemiş tüketilmiş nonce listeden düşmez, liste — 64 — doluysa yeni karar
  `GERI_YUKLEME_KARAR_KAPASITESI_DOLU` ile reddedilir — BY-030-R2). Özet
  işlem durumu dosyasındadır ve web süreci de okur: kanıt, diske düşen
  isteğin yeniden oynatılmasını, başka karara / seçime çevrilmesini ve
  anahtarın sızmasını önler; ele geçirilmiş web sürecine karşı koruma değildir.
- **Yazıcı bariyeri (BY-028-R1).** Bakım modu yeni web isteklerini keser ama
  başlamış istek, zamanlanmış komut ya da sonradan bağlantı açan iş öncesi
  yedekten sonra da canlıya yazabilirdi. Bakım modundan sonra, öncesi yedekten
  önce (`yazici_bariyeri` adımı): canlının özgün `datacl`'si (null =
  varsayılan) kurtarma bağlamıyla işlem durumuna yazılır; CONNECT servis
  rolüne açıkça verilir, PUBLIC'ten ve diğer rollerden geri alınır; bu sürecin
  kendi oturumları dışındaki istemci oturumları sonlandırılır ve bitmeleri
  beklenir (sonlandırılamayan oturum → geri sarma). Sonlandırılan işlemin
  yazısı geri alınır: kabul edilmiş her yazı öncesi yedektedir. Öncesi yedeğin
  anlık görüntü bağlantısı servis rolüyle ve parola dosyasıyla açılır;
  manifesti daraltılmış değil ÖZGÜN yetkileri taşır. Geri sarmada ve geri
  dönüşte canlıya özgün yetkiler aynen geri konur; yer değiştirmeden sonra
  `erp_onceki_*`'e özgün yetkiler konur (bağlantıya kapalı kalır). Servis
  rolü uygulama rolüyle aynıysa ya da parola dosyası yoksa işlem ön denetimde
  `GERI_YUKLEME_SERVIS_ROLU_GEREKLI` ile başlamaz.
- **Bağlanma yetkileri (BY-026-R1).** Farklı ada kopya, sınama ve üstüne
  geri yüklemenin yeni veritabanı manifestteki `baglanma_yetkileri`'ne
  eşdeğer yapılır (tek DO bloğu: yetkiler geri alınır, verilir, sonuç
  rol / yetki / devredilebilir kümesi olarak karşılaştırılır — veren
  karşılaştırılmaz). Rol yoksa ya da eşdeğerlik sağlanamazsa yetkiler hiç
  değişmez, `GERI_YUKLEME_VERITABANI_YETKILERI` (yarım veritabanı silinir).
- **Ayar seçimi (BY-028-R2, §5.3).** Gruplar ayar kopyasının gruplarıdır:
  mail, yedek ayarları, planlar, hedefler. "Güncel" yan dosya kopyasıdır (5.
  adımda donmuş canlıdan tazelenir; yazılamazsa yer değiştirmeden önce
  durulur), "geri yüklenen" yan veritabanıdır. Farkı olan her grup için
  onayda açık seçim zorunludur (varsayılan yok; ekran, uç ve çalıştırıcı
  reddeder; komut satırında `--ayar=grup=guncel|geri_yuklenen`). Seçim işlem
  durumuna yazılır, `guncel` gruplar sistem açılmadan tek veritabanı işleminde
  yazılır (yeniden çalıştırılabilir); yazılamazsa uygulama kapalı kalır, karar
  yine yöneticinindir. Seçim ve uyarılar denetim kaydına gider. Sırlar: mail
  parolası dosyada `APP_KEY` ile şifrelidir, çözülüp yazılır; hedef sırları
  dosyaya hiç yazılmaz — geri yüklenen veritabanındaki hedefin sırları olduğu
  gibi kalır, yedekten sonra eklenmiş hedef sırsız ve PASİF açılır (uyarı).
  "Güncel" hedeflerde mevcut hedefe normal hedef güncellemesinin kuralları
  aynı yardımcıyla (`HedefKonumu`) uygulanır (BY-028-R3): kopyası olan
  hedefin yeri (sunucu, port, kullanıcı, klasör, kova, hesap…) yedekten sonra
  değişmişse hedef geri yüklenen yerinde ve sırlarıyla kalır (kopya kayıtları
  o yeri gösterir) ama PASİF açılır, yeni yer için yeni hedef eklenir; sırrın
  gideceği adres değişmişse eski sırlar silinir ve hedef PASİF açılır — eski
  sır yeni adrese hiç gönderilmez (uyarı);
  açık şifreleme anahtarı dosyada yoktur, parmak izi farklıysa uyarı. Bugün
  olmayan plan / hedef kullanılmıyorsa silinir, kopyası / bağı varsa pasife
  alınıp adına "(silinmiş #id)" eklenir.
- "Geri dön"de geri yüklenen veritabanı kendi `erp_geri_*` adına döner,
  silinmez ve bağlantıya kapalı kalır.
- Disk ön denetimi: yedek klasörünün diskinde canlı veritabanının boyutu +
  iki yedek dosyası kadar boş yer aranır. PostgreSQL veri klasörünün diski
  uygulamaca ölçülemez (yetki yok); yetmezse 4. adımda düşülür ve geri sarılır.

## 7. Dikkat edilecekler (bu projeye özgü tuzaklar)

### 7.1 Oturumlar, önbellek ve kuyruk aynı veritabanında

`SESSION_DRIVER`, `CACHE_STORE` ve `QUEUE_CONNECTION` üçü de `database`.
Bu tabloların verisi yedeğe girerse, üstüne geri yüklemede:

- Yedek anında kuyrukta bekleyen işler **yeniden çalışır** — örneğin günler
  önce gönderilmiş alarm mailleri tekrar gider.
- Eski oturumlar dirilir; önbellekteki eski değerler (ör. İzibiz token'ı) geri gelir.

Karar: bu tabloların **verisi yedeklenmez**. Sonuç: üstüne geri yüklemeden
sonra herkes yeniden giriş yapar; bu istenen davranıştır.

### 7.2 Geri yükleme kendi bastığı dalı kesemez

Geri yükleme kuyruk işi olsaydı, işin kaydı geri yüklenen veritabanının
içinde olurdu. Aynı şekilde web isteği de bağlı olduğu veritabanını değiştiremez.
Bu yüzden: geri yükleme bağımsız bir süreçtir, ilerlemesini **dosyaya** yazar,
ekran ilerlemeyi o dosyadan okuyan bir uçtan gösterir. Bu uç, bakım modunda
da yanıt vermelidir (bakım modundan muaf tek adres).

Bu uç oturuma da dayanamaz: oturumlar veritabanındadır ve yer değiştirmeyle
birlikte herkesin oturumu düşer. İşlemi başlatan yöneticiye, yalnız **o
işlemin** ilerlemesini okumaya ve 9. adımdaki onayı vermeye yarayan, süreli bir
erişim anahtarı verilir; karşılığı işlem durum dosyasında tutulur. Anahtarı
olmayan kimse ilerlemeyi okuyamaz.

Erişim anahtarı kaybolursa (tarayıcı kapanır, bilgisayar değişir) işlem
kilitlenmez: sunucudaki acil durum komutu aynı durum dosyasını okur, onay ve
geri dönüşü oradan da verir.

### 7.3 Yedek geçmişi de geri döner

Yedek listesi ve denetim kaydı veritabanındaysa, 3 gün önceki yedeğe dönünce
son 3 günün yedekleri listeden kaybolur — dosyaları diskte dururken. Çözüm:
manifest dosyaları + geri yüklemeden sonra klasörü tarayıp dizini yeniden kurma;
denetim kaydının dosya kopyası.

### 7.4 Şema sürümü

Yedek, alındığı andaki şemayı taşır. Kod ilerlemişse geri yüklemeden sonra
migration çalışır. Veri taşıyan migration'lar (ör. izin anahtarı değişiklikleri)
bu yüzden **tekrar çalıştırılabilir** yazılmalıdır — bugünküler öyle.

### 7.5 `APP_KEY` olmadan yedek yarım kalır

Bağlantı parolaları (`sql_baglantilari`, entegratör, mail) `APP_KEY` ile
şifreli. Anahtar kaybolursa yedek geri yüklenir ama bu parolalar okunamaz;
hepsi elle yeniden girilir. `.env` ayrı ve güvenli bir yerde saklanmalı
(yedek dosyasının yanında **değil**).

Anahtar değiştirilirse eski anahtar `APP_PREVIOUS_KEYS`'e alınır; Laravel eski
anahtarla yazılmış veriyi bununla okur (projede bu ayar tanımlı). Manifestteki
anahtar parmak izi, eski bir yedeğin hangi anahtarı istediğini gösterir. Eski
anahtarlar da kurtarma paketinde saklanır (§9).

### 7.6 Eski veriyle çalışan otomatik işler

Geri yüklenen veritabanında alarm kuralları, e-Fatura senkron durumu ve
**aktif MSSQL ortamı** yedek anındaki hâliyle gelir. Üstüne geri yüklemeden
hemen sonra zamanlayıcı çalışırsa: eski duruma göre alarm maili gidebilir;
aktif ortam yedek anında "test" idiyse sistem sessizce test ERP'sine döner.
Önlem: geri yüklemeden sonra aktif ortam ve senkron durumu ekranda gösterilip
**onay alınmadan** zamanlanmış işler başlatılmaz.

**Dış sistemlerle mutabakat.** Veritabanı eski bir ana döner ama dış dünya
dönmez. Bu uygulamada etkilenenler:

- Gönderilmiş alarm maillerinin kaydı geri döner; aynı uyarı yeniden gidebilir.
- e-Fatura kayıtları senkronla yeniden çekilir (kayıp olmaz), ama yedekten sonra
  yapılan "gizle" işaretleri kaybolur.
- ERP'ye store proc ile yazılmış kayıtlar ERP'de durur; uygulamadaki izleri
  (varsa) geri döner. Satınalma Talebi devreye girdiğinde aynı talebin ERP'ye
  **ikinci kez** yazılma riski ayrıca ele alınmalıdır.
- Kesinti verisinde yedekten sonraki yüklemeler kaybolur; kullanıcıların
  yeniden yüklemesi gerekir. Onay ekranı kaybolacak yükleme sayısını gösterir.

**Yetkilerin geri dönmesi.** Yedekten sonra pasife alınan kullanıcılar ve
rollerden kaldırılan izinler geri gelir. Parolalar ERP'de tutulduğu için
etkilenmez. Onay ekranı kullanıcı ve rol farkını `erp_onceki_*` ile
karşılaştırarak gösterir (§6.4).

### 7.7 PostgreSQL araç sürümü

Canlıda sunucu da araçlar da **18.6** — eşleşiyor ([kesif.md](kesif.md) §1;
2026-11-18). Kural kalıcıdır: yeni sürüm araçla alınan yedek, eski sürüm
sunucuya her zaman geri yüklenemez. Somut örnek: PostgreSQL 17+ araçları
yedeğe `transaction_timeout` ayarını yazar; 16 sunucusu bu ayarı tanımaz ve
`--exit-on-error` ile geri yükleme başarısız olur. Sunucu yükseltilir ya da
araçlar ayrıca güncellenirse sürümler yeniden eşleştirilir; yedekleme,
manifestteki araç / sunucu sürümünü geri yüklemeden önce denetler.

Lokal geliştirme ortamı farklıdır (PostgreSQL 16, sıralama `C.UTF-8`; canlı
`en_US.UTF-8`). Canlı yedeğin canlı sunucuda geçici bir veritabanına
gerçekten geri yüklenebildiği **kanıtlandı** (2026-11-18, [kesif.md](kesif.md) §2).

### 7.8 Saat dilimi

Planlar İstanbul saatiyle; dosya adları ve manifest saat dilimini açıkça taşır.
(e-Fatura modülünde PostgreSQL saat dilimi yüzünden yaşanan hata tekrarlanmasın.)
Yaz saati olmadığı için "var olmayan saat" sorunu yok.

### 7.9 Tutarlılık

`pg_dump` tek anlık görüntü alır; yedek sırasında uygulama çalışmaya devam
edebilir, bakım modu gerekmez. Dosya yedeği eklendiğinde veritabanı ile dosyalar
aynı ana ait olmayabilir — ikisi birlikte yedeklenecekse sıra ve tutarlılık
ayrıca tasarlanır.

### 7.10 Kişisel veri (KVKK)

- Uygulamadan silinen kişisel veri (ör. bir işçinin adı, bir kullanıcı kaydı)
  yedeklerde saklama süresi boyunca (aylık yedeklerle 12 ay) yaşamaya devam eder
  ve geri yüklemeyle canlıya döner.
- Yurt dışındaki bir buluta (Google Drive, yurt dışı S3) yedek göndermek,
  şifreli olsa da kişisel verinin yurt dışına aktarımı sayılabilir.
- Bu ikisi hukuki konulardır; saklama süreleri ve bulut hedefi kesinleşmeden
  şirketin veri sorumlusuyla teyit edilmelidir (§13).

## 8. Güvenlik

- Yedek dosyası en hassas varlıktır: bütün veri tek dosyada. Klasör web
  kökünün dışında, yalnız uygulama kullanıcısı okuyabilir.
- **Tarayıcıya indirme** veriyi sunucudan çıkarır; ayrı izin, her indirme
  denetim kaydında, varsayılan kapalı. Gerek var mı kararı §13'te.
- Hedef veritabanı adı, dosya adı gibi girdiler serbest metin kabul edilmez;
  sıkı kalıp + beyaz liste. Dış komutlara kullanıcı girdisi doğrudan gitmez.
- Parolalar komut satırında, ortam değişkeninde ya da günlükte durmaz; yalnız
  çalıştırıcının okuyabildiği parola dosyasında durur.
- Dışarıdan gelen, imzası doğrulanmamış yedek bu sunucuda geri yüklenmez (§4.5).
- Üstüne geri yükleme ve silme hız sınırlı; aynı anda tek işlem.
- Sunucu dışı kopya şifreli; özel anahtar sunucuda değil.
- Geri yüklenen kopyalar (`erp_geri_*`) canlı veriyle aynı hassasiyettedir;
  süresiz bırakılmaz.

## 9. Altyapı kısıtları ve sunucuda yönetici yetkisiyle yapılacaklar

Bugünkü durum (2026-11-16):

| Konu | Durum | Etkisi |
|---|---|---|
| Yedek klasörü `/var/backups/emor` | Sahibi `admin`, uygulama kullanıcısı (`www-data`) yazamıyor | Servis hesabının yazdığı ayrı bir klasör gerek (§5.5) |
| Zamanlayıcı ve kuyruk | `www-data` olarak çalışıyor; kuyrukta iş zaman aşımı 300 sn, tek işçi | Yedekleme için ayrı işçi/servis tanımı gerek |
| `admin` kullanıcısı | `sudo` şifreyle; şifre kullanıcıda | Bilgi işlemden bir şey istenmez (kullanıcı kararı 2026-11-18): servis ve izin değişiklikleri kurulum betiğiyle (BY-003) `admin` + `sudo` ile yapılır |
| Yükleme sınırı | 2 MB | Tarayıcıdan yedek yükleme yok |
| Veritabanı kullanıcısının yetkileri | Uygulamanın kullanıcısı: yönetici değil, **veritabanı açamaz**, rol açamaz, üyeliği yok; `erp`'nin sahibi (2026-11-18, [kesif.md](kesif.md) §3) | Geri yükleme (§4.5, §6.4) uygulama kullanıcısıyla yapılamaz; servis hesabı şart |
| Yedekleme veritabanı kullanıcısı | **Kuruldu (2026-11-18):** `emor_yedek` — veritabanı açabilir, uygulama kullanıcısına üye; parolası `/home/admin/.emor_yedek_pgpass` (600). Canlı yedek bununla geçici veritabanına geri yüklendi ([kesif.md](kesif.md) §2) | Parola dosyası şimdilik `admin`'de; BY-025'te servis hesabının Linux kullanıcısına taşınır |
| Sunucu dışı hedef | Yok | NAS / sunucu / depolama + erişim bilgisi gerek |
| Kurulu araçlar | `gpg`, `rsync`, `zstd` var; `rclone` yok | S3 hedefi seçilirse kurulum gerek |

Yapılacaklar (kurulum betiğiyle, `admin` + `sudo`; bilgi işlemden istenmez):

1. Geri yükleme için **ayrı bir servis hesabı** (işletim sistemi kullanıcısı +
   veritabanı rolü). Veritabanı tarafı (`emor_yedek`) kuruldu; işletim sistemi
   kullanıcısı BY-025'te. Web sürecinin kullanıcısına geniş yetki verilmez. Yetki
   matrisi kurulum betiğinde **yazılı** durur (BY-003):
   - veritabanı oluşturma (`CREATEDB`),
   - **uygulama rolüne üyelik** — veritabanını uygulama rolü adına açmak, geri
     yüklemeyi onun adına yapmak, `erp`'nin adını değiştirmek ve uygulamanın
     bağlantılarını koparmak bununla mümkün olur ([kesif.md](kesif.md) §4–§6),
   - `postgres` veritabanına bağlanma (ad değiştirme oradan yapılır),
   - kuyruk servisini durdurup başlatma (yalnız bu komutlara izin veren dar
     `sudo` kuralı). Zamanlayıcı (cron) için yetki gerekmez: bakım modundayken
     zamanlanmış işler çalışmaz (§6.4).
2. Servis hesabının yazdığı, uygulamanın yalnız okuduğu, web dışı bir yedek
   klasörü (`/var/lib/emor-yedek`; §5.2, §5.5).
3. Yedekleme için ayrı servis tanımı (kuyruk işçisi ve geri yükleme çalıştırıcısı).
4. Sunucu dışı kopya için hedef ve erişim; mümkünse silinmeye karşı korumalı.
5. Sessizlik alarmı için uygulama dışı bir izleyici (§4.8).
6. Geri yüklenen kopyaları incelemek için salt okunur bir veritabanı rolü (§4.5).
7. Dosya yetkilerinin §5.5'teki tabloya göre kurulması.
8. Sanal makine düzeyinde yedek (snapshot) alınıyor mu, ne sıklıkla — bu sistem
   onun yerine geçmez, onu tamamlar.

Her maddenin kurulum betiğindeki karşılığı (ya da neden betikte olmadığı),
betiğin çalıştırılması ve geri alınması: [sunucu-kurulumu.md](sunucu-kurulumu.md).

### Kurtarma paketi (sunucu tamamen kaybedilirse)

Veritabanı yedeği tek başına sistemi ayağa kaldırmaz. Sunucu dışında ve
yedeklerden **ayrı** bir yerde şunlar saklanmalı:

- `.env` ve `APP_KEY` (varsa eski anahtarlar); şifreleme özel anahtarının yeri.
- **Manifest imza anahtarı.** Sunucuyla birlikte kaybolursa yeni sunucuda bütün
  uzak yedekler "güvenilmeyen" görünür ve geri yüklenemez.
- Boş bir sunucuda **ilk geri yükleme adımları**: ortada ne uygulama ne
  veritabanı varken hangi sırayla ne kurulur, imza anahtarı ve şifreleme
  anahtarı nereden alınır, ilk yedek nasıl doğrulanıp yüklenir.
- PostgreSQL rolleri ve yetkileri. `pg_dump` bunları içermez; ya
  `pg_dumpall --globals-only` çıktısı (yönetici yetkisi ister) ya da yazılı
  kurulum adımları.
- Gerekli PostgreSQL eklentileri; PostgreSQL, PHP ve araç sürümleri.
- Uygulamayı sıfırdan kurma adımları: sunucu bileşenleri, servis tanımları,
  zamanlayıcı, SSL sertifikası, MSSQL sürücüsü.
- Yazılı kurtarma adımları ve bunların en son ne zaman denendiği.

## 10. Sınama

- **Hemen yapılacak tek şey (koddan önce):** mevcut yayın öncesi yedeklerden
  birini ayrı bir veritabanına geri yükleyip çalıştığını görmek.
- Otomatik testler: dış komutlar sahtelenir (`Process::fake`) — testler SQLite
  ile çalışıyor, gerçek `pg_dump` yok. Sınanacak mantık: plan vadesi hesabı,
  saklama kuralı (hangi yedek kalır/silinir, koruma kuralları), durum
  geçişleri, yetkiler, ad kalıbı denetimi, manifest okuma/yazma, klasör tarama.
- Gerçek araçlarla uçtan uca sınama: lokal PostgreSQL'de (`erp` / `erp_test`)
  ayrı bir test grubu; her yayında değil, bu modül değişince.
- Üstüne geri yükleme canlıda ilk kez gerçek bir arıza anında denenmemeli:
  önce lokalde, sonra canlıda **kopya** ile prova, yazılı adım listesi.
- Düzenli tatbikat: öneri 3 ayda bir, gerçek bir geri yükleme provası. Tatbikat
  **sunucu dışındaki şifreli kopyadan** yapılır; böylece yerel dosyanın yanında
  şifreleme anahtarının ve uzak kopyanın da kullanılabildiği kanıtlanır.
- Arıza senaryoları, her biri ayrı sınanır: disk dolması, yarım kalan uzak
  kopya, sürecin öldürülmesi, bozuk / eksik / imzası tutmayan manifest, yanlış
  şifreleme anahtarı, migration hatası, ad değiştirme sırasında kesinti,
  veritabanına erişimin kesilmesi (kilit korunuyor mu, yetkili ilerlemeyi
  okuyabiliyor mu, yetkisiz okuyamıyor mu).
- **Başarı ölçütü** "işlem başarısız olarak işaretlendi" değildir: mevcut veri
  yerinde durmalı ve uygulama yanlışlıkla açılmamalıdır.
- Zamanlayıcı ve kuyruk bilerek durdurulur; dış izleyicinin uyarı ürettiği görülür.
- "Onay bekleniyor" durumundayken sunucu yeniden başlatılır: kuyruk ve
  zamanlayıcının açılmadığı, yayının ve planlı yedeğin başlamadığı, komut
  satırından onay ve geri dönüşün çalıştığı görülür.
- Geri yüklemeden sonra uygulama rolünün tabloları okuyup yazabildiği ve
  sıralama / saat dilimi ayarlarının canlıyla aynı olduğu denetlenir.

## 11. Önerilen aşamalar

Her aşama tek başına işe yarar; sıra riske göre.

| Kod | Aşama | İçerik |
|---|---|---|
| YDK-00 | Geçici önlem (koddan önce) | En yeni yedeği ve `.env`'i elle başka bir makineye kopyala; yayın kullanıcısının zamanlayıcısına günlük `pg_dump` satırı ekle. **En ağır iki risk dakikalar içinde kapanır;** asıl sistem gelince kaldırılır |
| YDK-01 | Kanıt | Mevcut bir yedeği geri yükleyip doğrula; veritabanı sürümünü ve kullanıcı yetkilerini öğren; sahiplik (`--role`), tek işlemde ad değiştirme ve veritabanı düzeyindeki ayarların taşınmasını dene; servis hesabının veritabanı tarafını kur ve canlı yedeği geçici veritabanına gerçekten geri yükle |
| YDK-02 | Çekirdek | Yedek alma komutu (anlık görüntüyle), imzalı manifest, olay günlüğü, özet, dosya kilidi (`deploy.sh` dahil), klasör tarama; geçici tabloların verisini dışarıda bırakma |
| YDK-03 | Günlük yedek | Tek sabit plan (günlük) + basit saklama; uygulama dışı "sessizlik" izleyicisi. **Yayın yapılmayan günlerdeki açığı kapatır** |
| YDK-04 | Sunucu dışı kopya ve kurtarma paketi | Hedef tanımı, şifreleme, yeniden deneme, yaz–oku–doğrula sınaması; `.env` / `APP_KEY` ve kurulum bilgilerinin saklanması. **Disk ve sunucu kaybı açığını kapatır** |
| YDK-05 | Yedekler ekranı | Liste, elle yedek, doğrulama, sabitleme, silme; izinler; denetim kaydı |
| YDK-06 | Planlar | Arayüzden plan ve saklama kuralı; sıradaki çalışma ve temizlik önizlemesi |
| YDK-07 | Kopya olarak geri yükleme | Ayrı servis hesabıyla farklı ada geri yükleme; kopyaların listesi ve silinmesi; zamanlanmış sınama geri yüklemesi |
| YDK-08 | Üstüne geri yükleme | §6.4 akışı ekrandan: ilerleme, onay bekleme, geri dönüş, yarıda kalan işlemi sürdürme, yeniden başlatmaya dayanıklılık, acil durum komutu; arıza senaryoları; yazılı işletim adımları |
| YDK-09 | Yayın öncesi yedekler | `deploy.sh`'ın yedeği çalıştırıcıdan istemesi; eski 159 yedeği içeri alma ve temizleme |
| YDK-10 | Dosya yedeği | `storage/app` — dosya saklayan bir özellik geldiğinde |

**Uygulama görevleri ayrı dosyadadır:** [Backup-Jira.md](Backup-Jira.md)
(BY-001 …). Bu tablo aşamaları özetler; her görevin kapsamı, kabul ölçütleri ve
inceleme durumu orada tutulur. Bu doküman tasarımı anlatır; bir görevin
ayrıntısı eksikse (işlem durumu dosyasının şeması, komutların tam seçenekleri)
görev başlamadan önce Backup-Jira.md'de tamamlanır, tahminle doldurulmaz.
§13'teki açık kararlar da ilgili görevden önce kapatılır.

YDK-03 ile en sık karşılaşılacak risk (yayın yapılmayan günlerde yedeksiz
kalmak), YDK-04 ile en ağır risk (disk ya da sunucu kaybında yedeklerin de
gitmesi) kapanır. Sunucu dışı kopya bu yüzden ekranlardan önce gelir; aynı
diskte duran düzenli yedek, sunucu kaybına karşı koruma sağlamaz. YDK-04 ve
YDK-07 sunucu dışı hedefe ve servis hesabının kurulmasına bağlıdır; gecikirlerse
aradaki aşamalar beklemeden ilerler.

## 12. İlk sürümde olmayacaklar

- İki yedek arasındaki herhangi bir ana dönme (sürekli arşivleme / WAL).
- Yalnız seçili tabloları geri yükleme; kopyadan tek kayıt kurtarma ekranı.
- Tarayıcıdan yedek dosyası yükleme.
- Kopya veritabanını uygulama içinden gezme.
- ERP (MSSQL) yedekleri.
- Başka bir sunucuya otomatik devretme.

## 13. Açık kararlar (kullanıcıdan)

1. Kabul edilebilir veri kaybı ve ayağa kalkma süresi (§3).
2. Varsayılan planlar ve saklama süreleri (§4.2, §4.3) — öneriler uygun mu?
3. Sunucu dışı hedef ne olacak: NAS, SFTP, FTP, S3, Google Drive? Yedeklerin
   şirket dışındaki bir buluta çıkmasına izin var mı? (§4.6)
4. Şifreleme özel anahtarı kimde, nerede saklanacak? (§4.7)
5. `.env` / `APP_KEY` nerede saklanacak? (§7.5)
6. Yedek dosyasını tarayıcıya indirme gerekli mi? (§8)
7. Bildirimler kime gidecek?
8. Üstüne geri yüklemeyi kimler yapabilir — yalnız sistem yöneticisi yeterli mi,
   ikinci bir kişinin onayı istenir mi?
9. ~~Bilgi işlem §9’daki değişiklikleri yapabilir mi?~~ **Karar (2026-11-18):** bilgi işlemden bir şey istenmez; §9 kurulum betiğiyle `admin` + `sudo` ile yapılır. Açık kalan: sanal
   makine yedeği var mı?
10. Geri yüklenen kopyalar ve `erp_onceki_*` kaç gün bekletilsin?
11. Sessizlik alarmını kim izleyecek: şirketin izleme sistemi mi (varsa), başka bir
    sunucumuzdaki küçük bir denetim mi? (§4.8)
12. ~~Servis hesabı ve yetki matrisi nasıl yazılı hâle getirilecek?~~ **Karar:** kurulum
    betiğinde, Codex incelemesinden geçerek (BY-003; §9).
13. Kurtarma paketi nerede, kimin sorumluluğunda saklanacak? (§9)
14. ~~Saklama için tek havuz mu, plan başına kural mı?~~ **Karar (2026-11-18):**
    plan + hedef başına kural; her plan kendi sıklığı ve hedefiyle (§4.2, §4.3).
15. ~~Üstüne geri yükleme ilk sürümde yalnız komut satırından mı?~~ **Karar
    (2026-11-18):** ekrandan; komut satırı yalnız acil durum yolu (§4.5).
16. KVKK: yedeklerin saklama süresi ve yurt dışı bulut hedefi veri sorumlusuyla
    teyit edildi mi? (§7.10)
17. Geri yüklenen kopyaları kimler inceleyebilecek? (§4.5)
18. Hazır paket kullanılsın mı (§15)?

## 14. İnceleme geçmişi

**2026-11-18 — Codex incelemesi.** Bulguların tamamı kabul edildi ve işlendi:

| Bulgu | İşlendiği yer |
|---|---|
| Geri yüklemeden sonra servislerin onaysız açılması; migration'ın ad değişiminden önce çalışması; açıldıktan sonraki geri dönüşün ayrı işlem olması | §6.4 |
| Kilit, işlem durumu ve ilerleme ucunun veritabanından bağımsız olması; `deploy.sh`'ın aynı kilide katılması | §5.2, §7.2 |
| Web sürecine geniş veritabanı yetkisi verilmemesi; ayrı servis hesabı ve yetki matrisi | §5.2, §9 |
| Sessizlik alarmının uygulama dışında izlenmesi; üç ayrı zaman göstergesi | §4.8 |
| Sabitleme gibi sonradan değişen bilgilerin ve ayarların geri yüklemeyle kaybolmaması | §5.3 |
| Satır sayıları ve migration listesinin yedekle aynı anlık görüntüden alınması; tam migration listesiyle uyum denetimi | §4.1, §4.5, §5.3, §6.1 |
| Dışarıdan gelen yedek için güven sınırı (imzalı manifest) | §4.5, §8 |
| Sunucu dışı kopyanın öne alınması; kurtarma paketi; eski anahtarlar | §7.5, §9, §11 |
| "Bir saatten küçük kayıp için WAL gerekir" ifadesinin düzeltilmesi | §3, §12 |
| Parolanın ortam değişkeni yerine parola dosyasında tutulması | §5.2, §8 |
| Silinmeye karşı korumalı hedef, kullanımdaki yedeğin korunması, yaz–oku–doğrula sınaması, çalışma önizlemesi, uzak kopyadan tatbikat, arıza senaryoları | §4.2, §4.3, §4.6, §10 |

**2026-11-18 — Claude (web) incelemesi.** PostgreSQL davranışlarına dair
maddeler sunucuda denenmedi; YDK-01'de doğrulanacak. İşlenenler:

| Bulgu | İşlendiği yer |
|---|---|
| Geri yüklenen nesnelerin sahipliği (uygulama kendi tablolarını okuyamaz) | §5.2, §6.4 |
| Kodlama, sıralama, veritabanı düzeyindeki ayarlar geri yüklemeye taşınmıyor (düzeltme, BY-002-R1: arşivde vardır, yalnız `--create` ile uygulanır) | §5.3, §6.3, §6.4 |
| Geri yükleme öncesi yedeğin bakım modundan sonra alınması | §6.4 |
| Bağlantı koparma ile ad değiştirme arasındaki yarış; tek işlemde ad değiştirme | §6.4 |
| "Onay bekleniyor" durumunun yeniden başlatmaya dayanıklılığı; komut satırı yolu; yarıda kalan işlem | §6.4, §10 |
| İmza anahtarının ve yedek almanın servis hesabına geçmesi; çalıştırıcının isteği doğrulaması | §5.2, §5.5 |
| Migration'ın canlıya dokunmaması (ayrı süreç) | §6.4 |
| Her yedekte bütün verinin okunması | §4.4 |
| Bildirimlerin veritabanına dayanmaması; üç ayrı yaşam sinyali | §4.8 |
| Dosya yetkileri tablosu; `deploy.sh`'ın yedeği çalıştırıcıdan istemesi | §5.5 |
| İmza anahtarının kurtarma paketine girmesi; boş sunucuda ilk adımlar; günlüklerin sunucu dışına gitmesi | §4.6, §9 |
| Saklama kuralı boşlukları; en az sayı | §4.3 |
| Saklama için tek havuz | **Kabul edilmedi** (kullanıcı kararı: plan + hedef başına kural) — §4.2, §4.3 |
| Dış sistemlerle mutabakat; yetkilerin geri dönmesi | §6.4, §7.6 |
| `pg_restore` seçenekleri | §6.3, §6.4 |
| Kopyaları inceleme rolü | §4.5 |
| KVKK | §7.10 |
| Sınama geri yüklemesinin her yedekten sonra çalışması | §4.4 |
| Üstüne geri yüklemenin ilk sürümde komut satırından olması | **Kabul edilmedi** (kullanıcı kararı: ekrandan). Komut satırı acil durum yolu olarak kaldı — §4.5, §6.4 |
| Koddan önce geçici önlem | §11 (YDK-00) |
| Değerlendirilen alternatifler; görev dosyası kuralı | §11, §15 |

## 15. Değerlendirilen alternatifler

**`spatie/laravel-backup`** (Laravel için hazır yedekleme paketi). Yedek alma,
uzak hedeflere kopyalama (FTP, S3, Google Drive gibi), eski yedekleri temizleme
ve sağlık bildirimi sunar. Sunmadıkları: geri yükleme, sınama geri yüklemesi,
imzalı manifest ve güven sınırı, yedekle aynı andan alınan sayımlar, servis
hesabı ayrımı, ekrandan yönetilen planlar. Yani bu dokümandaki işin kolay
yarısını karşılar, zor yarısını (geri yükleme ve güvence) karşılamaz. Öneri:
çekirdek bizde kalsın, uzak hedeflere kopyalama için Laravel'in kendi dosya
sistemi sürücüleri kullanılsın. Paketi yalnız "al + kopyala + temizle" için
kullanmak da mümkündür; yeni bağımlılık olduğu için karar kullanıcıdadır (§13).

**pgBackRest** (PostgreSQL için kurumsal yedekleme aracı). Veritabanı
dosyalarını ve sürekli arşivi (WAL) yedekler; herhangi bir ana dönmeyi sağlar.
Sunucu yöneticisi yetkisi ve PostgreSQL yapılandırma değişikliği ister; bizde
ikisi de yok. 1,6 MB'lık bir veritabanı ve "1 saat kayıp kabul" hedefi için
ağır kalır. Veritabanı büyür ya da dakikalık geri dönüş istenirse yeniden
değerlendirilir.

**Yalnız sanal makine yedeği (snapshot).** Sunucu düzeyinde alınıyorsa değerli bir
ikinci katmandır, ama tek başına yetmez: tek bir tabloyu ya da dünkü durumu geri
getirmek için bütün sunucuyu geri almak gerekir ve ne zaman alındığı, çalışıp
çalışmadığı bizim elimizde değildir.
