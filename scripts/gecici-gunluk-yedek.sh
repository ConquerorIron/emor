#!/usr/bin/env bash
#
# ERP Web (emor) — GEÇİCİ günlük PostgreSQL yedeği (BY-001).
#
# Asıl yedekleme sistemi (docs/yedekleme) gelene kadar, yayın yapılmayan
# günlerde de yedek alınsın diye yayın kullanıcısının zamanlayıcısından günde
# bir çalışır. BY-013 canlıya alınınca bu betik ve zamanlayıcı satırı kaldırılır.
#
# Yaptığı: backend/.env'deki veritabanını pg_dump (custom biçim) ile yedekler.
#   - Önce geçici ada yazar, doğrular, sonra asıl adına taşır: yarım dosya
#     hiçbir zaman "yedek" gibi görünmez.
#   - Parolayı komut satırına ve ortam değişkenine koymaz; yalnız bu kullanıcının
#     okuyabildiği geçici bir parola dosyası kullanır (PGPASSFILE).
#   - Yalnız kendi ürettiği dosyaları temizler (<veritabanı>_gunluk_*.dump);
#     deploy.sh'ın <veritabanı>_predeploy_* yedeklerine dokunmaz.
#
# Kullanım (proje kökünden bağımsız çalışır):
#   scripts/gecici-gunluk-yedek.sh
#
# Ortam değişkenleri (isteğe bağlı):
#   EMOR_BACKUP_DIR         yedek klasörü           (varsayılan /var/backups/emor)
#   EMOR_GUNLUK_SAKLA_GUN   bu günden eskiler silinir (varsayılan 30)
#   EMOR_GUNLUK_EN_AZ       her durumda tutulan en yeni yedek sayısı (varsayılan 7)
#   EMOR_ENV_DOSYASI        .env yolu               (varsayılan <proje>/backend/.env)
#   EMOR_GUNLUK_SURE_SINIRI her dış komut için saniye (varsayılan 1800); aşılırsa
#                           komut sonlandırılır — asılı kalan bir yedek kilidi
#                           tutup sonraki günlerin yedeğini engellemesin
#   EMOR_GUNLUK_BAGLANTI_SURESI veritabanına bağlanma süre sınırı, saniye (varsayılan 15)
#
# .env, uygulamanın kendi ayrıştırıcısıyla okunur (scripts/gecici-env-oku.php):
# tırnak, kaçış ve satır sonu yorumu kuralları uygulamanın gördüğüyle aynıdır.
#
# Çıkış kodu: 0 başarılı; 1 hata (yedek alınamadı); 75 başka bir kopya çalışıyor.
#
set -euo pipefail

# Yedek bütün veriyi içerir: dosyalar yalnız sahibi tarafından okunabilir
umask 077

PROJE_KOKU="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_DOSYASI="${EMOR_ENV_DOSYASI:-$PROJE_KOKU/backend/.env}"
YEDEK_KLASORU="${EMOR_BACKUP_DIR:-/var/backups/emor}"
SAKLA_GUN="${EMOR_GUNLUK_SAKLA_GUN:-30}"
EN_AZ="${EMOR_GUNLUK_EN_AZ:-7}"
SURE_SINIRI="${EMOR_GUNLUK_SURE_SINIRI:-1800}"
BAGLANTI_SURESI="${EMOR_GUNLUK_BAGLANTI_SURESI:-15}"
ENV_OKUYUCU="$PROJE_KOKU/scripts/gecici-env-oku.php"
AUTOLOAD="$PROJE_KOKU/backend/vendor/autoload.php"

GUNLUK="$YEDEK_KLASORU/gecici-gunluk-yedek.log"
GECICI=""
PAROLA_DOSYASI=""

gunluge_yaz() {
  local satir
  satir="$(date '+%F %T') $*"
  echo "$satir"
  # Günlük yazılamıyorsa (klasör yok vb.) asıl hata mesajı yine ekranda kalır
  echo "$satir" >> "$GUNLUK" 2>/dev/null || true
}

hata() {
  gunluge_yaz "HATA: $*"
  exit 1
}

temizle() {
  # Hata yolunda geçici yedek ve parola dosyası bırakılmaz
  [[ -n "$GECICI" && -f "$GECICI" ]] && rm -f "$GECICI"
  [[ -n "$PAROLA_DOSYASI" && -f "$PAROLA_DOSYASI" ]] && rm -f "$PAROLA_DOSYASI"
  return 0
}
trap temizle EXIT

pozitif_tam_sayi_mi() {
  [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 ))
}

# Dış komutu süre sınırıyla çalıştırır. Süre dolarsa komut (ve alt süreçleri)
# sonlandırılır; 10 sn içinde kapanmazsa zorla öldürülür. Çıkış kodu komutunkidir;
# süre aşımında 124 (zorla öldürmede 137).
sureli() {
  timeout --kill-after=10 "$SURE_SINIRI" "$@"
}

# Süre aşımını ayrı mesajla, diğer hataları verilen mesajla bildirir
komut_hatasi() {
  local kod="$1" ad="$2" mesaj="$3"
  if (( kod == 124 || kod == 137 )); then
    hata "$ad süre sınırını aştı (${SURE_SINIRI} sn) ve sonlandırıldı."
  fi
  hata "$mesaj"
}

# --------- 1. Ön denetimler ---------
[[ -f "$ENV_DOSYASI" ]] || hata "$ENV_DOSYASI bulunamadı."
[[ -d "$YEDEK_KLASORU" && -w "$YEDEK_KLASORU" ]] || hata "Yedek klasörü yok ya da yazılamıyor: $YEDEK_KLASORU"
pozitif_tam_sayi_mi "$SAKLA_GUN" || hata "EMOR_GUNLUK_SAKLA_GUN pozitif tam sayı olmalı: $SAKLA_GUN"
pozitif_tam_sayi_mi "$EN_AZ" || hata "EMOR_GUNLUK_EN_AZ pozitif tam sayı olmalı: $EN_AZ"
pozitif_tam_sayi_mi "$SURE_SINIRI" || hata "EMOR_GUNLUK_SURE_SINIRI pozitif tam sayı olmalı: $SURE_SINIRI"
pozitif_tam_sayi_mi "$BAGLANTI_SURESI" || hata "EMOR_GUNLUK_BAGLANTI_SURESI pozitif tam sayı olmalı: $BAGLANTI_SURESI"
for KOMUT in pg_dump pg_restore php timeout flock; do
  command -v "$KOMUT" >/dev/null 2>&1 || hata "$KOMUT bulunamadı."
done
[[ -f "$ENV_OKUYUCU" ]] || hata "$ENV_OKUYUCU bulunamadı."

# .env'i uygulamanın ayrıştırıcısıyla oku. Değerler NUL ile ayrılmış gelir;
# dosya source / eval edilmez, parola komut satırına ve ortam değişkenine girmez.
ENV_DEGERLERI=()
mapfile -d '' -t ENV_DEGERLERI < <(
  php "$ENV_OKUYUCU" "$AUTOLOAD" "$ENV_DOSYASI" DB_DATABASE DB_USERNAME DB_PASSWORD DB_HOST DB_PORT 2>> "$GUNLUK"
)
# Okuyucu hata verirse hiçbir değer yazmaz (nedeni günlüktedir)
(( ${#ENV_DEGERLERI[@]} == 5 )) || hata ".env okunamadı: $ENV_DOSYASI (ayrıntı günlükte)."

VT_ADI="${ENV_DEGERLERI[0]}"
VT_KULLANICI="${ENV_DEGERLERI[1]}"
VT_PAROLA="${ENV_DEGERLERI[2]}"
VT_SUNUCU="${ENV_DEGERLERI[3]:-127.0.0.1}"
VT_PORT="${ENV_DEGERLERI[4]:-5432}"

[[ -n "$VT_ADI" && -n "$VT_KULLANICI" ]] || hata "$ENV_DOSYASI içinde DB_DATABASE / DB_USERNAME boş."
# Dosya adına girecek: yalnız güvenli karakterler
[[ "$VT_ADI" =~ ^[A-Za-z0-9_]+$ ]] || hata "Veritabanı adı dosya adı için uygun değil: $VT_ADI"
# Parola dosyası satır tabanlıdır: satır sonu içeren değer yazılamaz
for DEGER in "$VT_KULLANICI" "$VT_PAROLA" "$VT_SUNUCU" "$VT_PORT"; do
  [[ "$DEGER" != *$'\n'* && "$DEGER" != *$'\r'* ]] || hata "Veritabanı ayarlarından biri satır sonu içeriyor; parola dosyasına yazılamaz."
done

# --------- 2. Tek kopya ---------
# Önceki çalışma hâlâ sürüyorsa ikincisi başlamaz (zamanlayıcı üst üste binmesin)
exec 9>"$YEDEK_KLASORU/.gecici-gunluk-yedek.kilit"
if ! flock -n 9; then
  gunluge_yaz "ATLANDI: başka bir yedek çalışıyor."
  exit 75
fi

# --------- 3. Yedek ---------
ZAMAN="$(date +%F_%H%M%S)"
HEDEF="$YEDEK_KLASORU/${VT_ADI}_gunluk_${ZAMAN}.dump"
# Aynı saniyede ikinci çalışma var olan yedeğin üzerine yazmasın
SIRA=2
while [[ -e "$HEDEF" ]]; do
  HEDEF="$YEDEK_KLASORU/${VT_ADI}_gunluk_${ZAMAN}_${SIRA}.dump"
  SIRA=$((SIRA + 1))
done
GECICI="$(mktemp "$YEDEK_KLASORU/.${VT_ADI}_gunluk_${ZAMAN}.XXXXXX.tmp")"

# Parola dosyası: sunucu:port:veritabanı:kullanıcı:parola (":" ve "\" kaçırılır)
pgpass_kacir() {
  local deger="${1//\\/\\\\}"
  printf '%s' "${deger//:/\\:}"
}
PAROLA_DOSYASI="$(mktemp "$YEDEK_KLASORU/.pgpass.XXXXXX")"
printf '%s:%s:%s:%s:%s\n' \
  "$(pgpass_kacir "$VT_SUNUCU")" "$(pgpass_kacir "$VT_PORT")" "$(pgpass_kacir "$VT_ADI")" \
  "$(pgpass_kacir "$VT_KULLANICI")" "$(pgpass_kacir "$VT_PAROLA")" > "$PAROLA_DOSYASI"

KOD=0
PGPASSFILE="$PAROLA_DOSYASI" PGCONNECT_TIMEOUT="$BAGLANTI_SURESI" sureli pg_dump \
    --host="$VT_SUNUCU" --port="$VT_PORT" --username="$VT_KULLANICI" \
    --dbname="$VT_ADI" --no-password --format=custom --file="$GECICI" || KOD=$?
(( KOD == 0 )) || komut_hatasi "$KOD" "pg_dump" "pg_dump başarısız; yedek alınamadı."

# --------- 4. Doğrulama ---------
[[ -s "$GECICI" ]] || hata "Yedek dosyası boş."
# İçindekiler okunabiliyor mu ve bütün veri blokları açılıyor mu (veritabanı gerekmez)
KOD=0
sureli pg_restore --list "$GECICI" >/dev/null 2>&1 || KOD=$?
(( KOD == 0 )) || komut_hatasi "$KOD" "pg_restore" "Yedek okunamıyor (pg_restore --list)."
KOD=0
sureli pg_restore --file=/dev/null "$GECICI" >/dev/null 2>&1 || KOD=$?
(( KOD == 0 )) || komut_hatasi "$KOD" "pg_restore" "Yedeğin verisi okunamıyor (pg_restore)."

mv "$GECICI" "$HEDEF"
GECICI=""
BOYUT="$(stat -c %s "$HEDEF")"
gunluge_yaz "TAMAM: $(basename "$HEDEF") ($BOYUT bayt)"

# --------- 5. Temizlik (yalnız bu betiğin dosyaları) ---------
# En yeni EN_AZ dosya her durumda kalır; kalanlardan SAKLA_GUN günden eskiler silinir.
# Ad zaman damgası taşıdığı için ada göre ters sıra = yeniden eskiye.
SILINEN=0
SAYAC=0
while IFS= read -r DOSYA; do
  SAYAC=$((SAYAC + 1))
  (( SAYAC <= EN_AZ )) && continue
  if [[ -n "$(find "$DOSYA" -maxdepth 0 -type f -mtime +"$SAKLA_GUN" -print 2>/dev/null)" ]]; then
    rm -f "$DOSYA"
    SILINEN=$((SILINEN + 1))
  fi
done < <(find "$YEDEK_KLASORU" -maxdepth 1 -type f -name "${VT_ADI}_gunluk_*.dump" | sort -r)

# Çökmüş eski çalışmalardan kalan geçici dosyalar (bir günden eski)
find "$YEDEK_KLASORU" -maxdepth 1 -type f \
  \( -name ".${VT_ADI}_gunluk_*.tmp" -o -name ".pgpass.*" \) -mmin +1440 -delete 2>/dev/null || true

(( SILINEN > 0 )) && gunluge_yaz "TEMIZLIK: $SILINEN eski günlük yedek silindi."
exit 0
