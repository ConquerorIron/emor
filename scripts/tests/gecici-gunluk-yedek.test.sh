#!/usr/bin/env bash
#
# scripts/gecici-gunluk-yedek.sh testleri (BY-001). Veritabanı gerektirmez:
# pg_dump ve pg_restore, PATH'e konan sahte komutlarla değiştirilir.
#
# Çalıştırma: scripts/tests/gecici-gunluk-yedek.test.sh
# Çıkış kodu: 0 hepsi geçti, 1 en az biri başarısız.
#
set -uo pipefail

BETIK="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/gecici-gunluk-yedek.sh"
CALISMA="$(mktemp -d)"
trap 'rm -rf "$CALISMA"' EXIT

GECEN=0
KALAN=0

gecti() { GECEN=$((GECEN + 1)); echo "  ✓ $1"; }
kaldi() { KALAN=$((KALAN + 1)); echo "  ✗ $1"; }
esit() { if [[ "$2" == "$3" ]]; then gecti "$1"; else kaldi "$1 (beklenen: $3, gelen: $2)"; fi; }

# --- sahte komutlar ---
SAHTE="$CALISMA/bin"
mkdir -p "$SAHTE"
cat > "$SAHTE/pg_dump" <<'EOF'
#!/usr/bin/env bash
# Argümanları ve parola dosyasının durumunu kaydeder; --file yoluna sahte yedek yazar
printf '%s\n' "$*" > "$SAHTE_KAYIT/pg_dump.args"
printf '%s' "${PGPASSWORD-YOK}" > "$SAHTE_KAYIT/pg_dump.pgpassword"
if [[ -n "${PGPASSFILE:-}" && -f "$PGPASSFILE" ]]; then
  stat -c %a "$PGPASSFILE" > "$SAHTE_KAYIT/pgpass.izin"
  cat "$PGPASSFILE" > "$SAHTE_KAYIT/pgpass.icerik"
fi
dosya=""
for arg in "$@"; do
  case "$arg" in --file=*) dosya="${arg#--file=}" ;; esac
done
printf 'yarim' > "$dosya"
[[ "${SAHTE_PG_DUMP_HATA:-0}" == "1" ]] && exit 1
[[ "${SAHTE_PG_DUMP_BEKLE:-0}" != "0" ]] && sleep "$SAHTE_PG_DUMP_BEKLE"
printf 'PGDMP sahte yedek' > "$dosya"
exit 0
EOF
cat > "$SAHTE/pg_restore" <<'EOF'
#!/usr/bin/env bash
[[ "${SAHTE_PG_RESTORE_HATA:-0}" == "1" ]] && exit 1
exit 0
EOF
# Gerçek flock'a geçer; SAHTE_FLOCK_HATA=1 ise çakışma dışı bir hata taklidi (64)
GERCEK_FLOCK="$(command -v flock)"
cat > "$SAHTE/flock" <<EOF
#!/usr/bin/env bash
[[ "\${SAHTE_FLOCK_HATA:-0}" == "1" ]] && exit 64
exec "$GERCEK_FLOCK" "\$@"
EOF
chmod +x "$SAHTE/pg_dump" "$SAHTE/pg_restore" "$SAHTE/flock"

# Her test için temiz klasör + .env. Parametre: .env'e AYNEN yazılacak parola
# satırı (tırnak ve yorum kurallarını sınamak için); verilmezse sıradan bir parola.
hazirla() {
  KLASOR="$CALISMA/yedek-$RANDOM$RANDOM"
  KAYIT="$CALISMA/kayit-$RANDOM$RANDOM"
  ENV="$CALISMA/env-$RANDOM$RANDOM"
  mkdir -p "$KLASOR" "$KAYIT"
  {
    echo 'DB_CONNECTION=pgsql'
    echo 'DB_HOST=127.0.0.1'
    echo 'DB_PORT=5432'
    echo 'DB_DATABASE=erp'
    echo 'DB_USERNAME=erp'
    printf '%s\n' "${1:-DB_PASSWORD=\"gizli-parola\"}"
  } > "$ENV"
}

# pg_dump'a ulaşan parola dosyasının içeriği (sahte komutun kaydettiği)
pgpass_icerigi() { cat "$KAYIT/pgpass.icerik" 2>/dev/null || echo "(yok)"; }

calistir() {
  PATH="$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" EMOR_BACKUP_DIR="$KLASOR" EMOR_ENV_DOSYASI="$ENV" \
    "$@" bash "$BETIK" > "$KAYIT/cikti.txt" 2>&1
}

yedek_sayisi() { find "$KLASOR" -maxdepth 1 -name 'erp_gunluk_*.dump' | wc -l | tr -d ' '; }
artik_sayisi() { find "$KLASOR" -maxdepth 1 \( -name '*.tmp' -o -name '.pgpass.*' \) | wc -l | tr -d ' '; }

echo "1. Arka arkaya iki başarılı çalışma"
hazirla
calistir env; esit "ilk çalışma çıkış kodu" "$?" "0"
calistir env; esit "ikinci çalışma çıkış kodu" "$?" "0"
esit "iki ayrı yedek oluştu (aynı saniyede üzerine yazmadı)" "$(yedek_sayisi)" "2"
esit "geçici dosya / parola dosyası kalmadı" "$(artik_sayisi)" "0"
esit "günlükte iki TAMAM satırı" "$(grep -c 'TAMAM:' "$KLASOR/gecici-gunluk-yedek.log")" "2"
esit "yedek dosyası yalnız sahibine açık" "$(stat -c %a "$(find "$KLASOR" -name 'erp_gunluk_*.dump' | head -n1)")" "600"

echo "2. Parola komut satırında ve ortam değişkeninde yok"
hazirla "DB_PASSWORD='pa:ro\\la'"
calistir env
if grep -q 'pa:ro' "$KAYIT/pg_dump.args"; then kaldi "parola argümanlarda görünüyor"; else gecti "parola argümanlarda yok"; fi
esit "PGPASSWORD tanımlı değil" "$(cat "$KAYIT/pg_dump.pgpassword")" "YOK"
esit "parola dosyası izni" "$(cat "$KAYIT/pgpass.izin")" "600"
esit "parola dosyasında ':' ve '\\' kaçırıldı" "$(pgpass_icerigi)" '127.0.0.1:5432:erp:erp:pa\:ro\\la'
if grep -rq 'pa:ro' "$KLASOR"; then kaldi "parola yedek klasöründe kaldı"; else gecti "parola yedek klasöründe kalmadı"; fi
# Kabuktan devralınan PGPASSWORD parola dosyasının önüne geçer (libpq); temizlenmeli (BY-001-R6)
hazirla
calistir env PGPASSWORD=devralinan-yanlis-parola; esit "devralınan PGPASSWORD ile çıkış kodu" "$?" "0"
esit "devralınan PGPASSWORD pg_dump'a ulaşmadı" "$(cat "$KAYIT/pg_dump.pgpassword")" "YOK"

echo "3. pg_dump başarısız"
hazirla
calistir env SAHTE_PG_DUMP_HATA=1; esit "çıkış kodu" "$?" "1"
esit "yedek oluşmadı" "$(yedek_sayisi)" "0"
esit "yarım dosya / parola dosyası kalmadı" "$(artik_sayisi)" "0"
esit "günlükte HATA satırı" "$(grep -c 'HATA:' "$KLASOR/gecici-gunluk-yedek.log")" "1"

echo "4. Yedek doğrulanamıyor (pg_restore başarısız)"
hazirla
calistir env SAHTE_PG_RESTORE_HATA=1; esit "çıkış kodu" "$?" "1"
esit "doğrulanamayan dosya yedek sayılmadı" "$(yedek_sayisi)" "0"
esit "yarım dosya kalmadı" "$(artik_sayisi)" "0"

echo "5. Temizlik: eski günlük yedekler silinir, en yeni 7 ve yayın öncesi yedekler kalır"
hazirla
for i in 01 02 03 04 05 06 07 08 09 10; do
  touch -d '40 days ago' "$KLASOR/erp_gunluk_2026-01-${i}_020000.dump"
done
touch -d '400 days ago' "$KLASOR/erp_predeploy_2025-08-05_115245.dump"
touch -d '400 days ago' "$KLASOR/baska_gunluk_2025-01-01_020000.dump"
calistir env; esit "çıkış kodu" "$?" "0"
esit "11 dosyadan en yeni 7'si kaldı" "$(yedek_sayisi)" "7"
if [[ -f "$KLASOR/erp_predeploy_2025-08-05_115245.dump" ]]; then gecti "yayın öncesi yedeğe dokunulmadı"; else kaldi "yayın öncesi yedek silindi"; fi
if [[ -f "$KLASOR/baska_gunluk_2025-01-01_020000.dump" ]]; then gecti "başka veritabanının dosyasına dokunulmadı"; else kaldi "başka veritabanının dosyası silindi"; fi
if [[ -f "$KLASOR/erp_gunluk_2026-01-10_020000.dump" && ! -f "$KLASOR/erp_gunluk_2026-01-04_020000.dump" ]]; then
  gecti "silinenler en eskiler"
else
  kaldi "yanlış dosyalar silindi"
fi

echo "6. Temizlik: süresi dolmamış yedekler sayı sınırını aşsa da silinmez"
hazirla
for i in 01 02 03 04 05 06 07 08 09 10; do
  touch -d '5 days ago' "$KLASOR/erp_gunluk_2026-02-${i}_020000.dump"
done
calistir env
esit "11 dosyanın hepsi duruyor" "$(yedek_sayisi)" "11"

echo "7. Başarısız çalışma eski yedekleri silmez"
hazirla
for i in 01 02 03 04 05 06 07 08 09 10; do
  touch -d '40 days ago' "$KLASOR/erp_gunluk_2026-01-${i}_020000.dump"
done
calistir env SAHTE_PG_DUMP_HATA=1
esit "yeni yedek alınamayınca 10 eski yedek duruyor" "$(yedek_sayisi)" "10"

echo "8. Aynı anda ikinci kopya çalışmaz"
hazirla
calistir env SAHTE_PG_DUMP_BEKLE=3 &
ILK=$!
sleep 1
PATH="$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" EMOR_BACKUP_DIR="$KLASOR" EMOR_ENV_DOSYASI="$ENV" \
  bash "$BETIK" > "$KAYIT/ikinci.txt" 2>&1
esit "ikinci kopya 75 ile çıktı" "$?" "75"
wait "$ILK"; esit "ilk kopya başarıyla bitti" "$?" "0"
esit "tek yedek oluştu" "$(yedek_sayisi)" "1"
# Kilit aracının çakışma dışı hatası "başka yedek çalışıyor" sayılmaz (BY-001-R5, D6)
hazirla
calistir env SAHTE_FLOCK_HATA=1; esit "kilit aracı hatasında çıkış kodu 1 (75 değil)" "$?" "1"
esit "günlükte HATA satırı" "$(grep -c 'HATA: .*kilit' "$KLASOR/gecici-gunluk-yedek.log")" "1"
esit "günlükte 'başka bir yedek çalışıyor' yok" "$(grep -c 'başka bir yedek çalışıyor' "$KLASOR/gecici-gunluk-yedek.log")" "0"
esit "kilit hatasında yedek alınmadı" "$(yedek_sayisi)" "0"

echo "9. Eksik / hatalı yapılandırma"
hazirla
EMOR_ENV_DOSYASI="$CALISMA/yok.env" EMOR_BACKUP_DIR="$KLASOR" PATH="$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" \
  bash "$BETIK" > /dev/null 2>&1
esit ".env yoksa çıkış kodu 1" "$?" "1"
EMOR_BACKUP_DIR="$CALISMA/olmayan-klasor" EMOR_ENV_DOSYASI="$ENV" PATH="$SAHTE:$PATH" SAHTE_KAYIT="$KAYIT" \
  bash "$BETIK" > /dev/null 2>&1
esit "yedek klasörü yoksa çıkış kodu 1" "$?" "1"
calistir env EMOR_GUNLUK_EN_AZ=0; esit "en az sayı 0 kabul edilmez" "$?" "1"
esit "geçersiz ayarda yedek alınmadı" "$(yedek_sayisi)" "0"

echo "10. .env uygulamanın ayrıştırıcısıyla okunur (BY-001-R1)"
hazirla "DB_PASSWORD='fake\"password'"
calistir env; esit "tek tırnak içinde çift tırnak: çıkış kodu" "$?" "0"
esit "parola içindeki tırnak korundu" "$(pgpass_icerigi)" '127.0.0.1:5432:erp:erp:fake"password'
esit "geçici dosya kalmadı" "$(artik_sayisi)" "0"
hazirla 'DB_PASSWORD="fake-password" # credential comment'
calistir env; esit "satır sonu yorumu: çıkış kodu" "$?" "0"
esit "yorum parolaya eklenmedi" "$(pgpass_icerigi)" '127.0.0.1:5432:erp:erp:fake-password'
esit "geçici dosya kalmadı" "$(artik_sayisi)" "0"
hazirla 'DB_PASSWORD="a\"b\\c d"'
calistir env; esit "çift tırnakta kaçışlar ve boşluk: çıkış kodu" "$?" "0"
esit "kaçışlar çözüldü, pgpass için yeniden kaçırıldı" "$(pgpass_icerigi)" '127.0.0.1:5432:erp:erp:a"b\\c d'
hazirla 'DB_PASSWORD=null'
calistir env; esit "null → boş parola (Laravel env kuralı): çıkış kodu" "$?" "0"
esit "parola boş yazıldı" "$(pgpass_icerigi)" '127.0.0.1:5432:erp:erp:'

# Laravel'in ayrılmış değerleri (BY-001-R3): uygulama true'yu "1", false / empty /
# null'u boş metin olarak görür; parantezli ve büyük-küçük harfli biçimler dahil.
# Beklenen değerler gerçek Laravel okuyucusuyla doğrulandı (inceleme günlüğü).
ayrilmis_deger() { # $1: .env satırı, $2: pg_dump'a ulaşması beklenen parola
  hazirla "$1"
  calistir env; esit "$1 → çıkış kodu" "$?" "0"
  esit "$1 → parola '$2'" "$(pgpass_icerigi)" "127.0.0.1:5432:erp:erp:$2"
}
ayrilmis_deger 'DB_PASSWORD=true' '1'
ayrilmis_deger 'DB_PASSWORD=(true)' '1'
ayrilmis_deger 'DB_PASSWORD=TRUE' '1'
ayrilmis_deger 'DB_PASSWORD="true"' '1'
ayrilmis_deger 'DB_PASSWORD=false' ''
ayrilmis_deger 'DB_PASSWORD=(False)' ''
ayrilmis_deger 'DB_PASSWORD=(EMPTY)' ''
ayrilmis_deger 'DB_PASSWORD=(Null)' ''
# Ayrılmış değere yalnız benzeyen sıradan parolalar değişmemeli
ayrilmis_deger 'DB_PASSWORD=truefalse' 'truefalse'
ayrilmis_deger 'DB_PASSWORD="true "' 'true '

echo "11. Okunamayan / parola dosyasına yazılamayan .env"
# Ayrıştırıcının reddettiği satır: kapanan tırnaktan sonra karakter
hazirla 'DB_PASSWORD="sizmamali"x'
calistir env; esit "bozuk .env: çıkış kodu" "$?" "1"
esit "bozuk .env'de yedek alınmadı" "$(yedek_sayisi)" "0"
esit "günlükte okunamadı satırı" "$(grep -c '.env okunamadı' "$KLASOR/gecici-gunluk-yedek.log")" "1"
if grep -rq 'sizmamali' "$KLASOR" "$KAYIT/cikti.txt"; then kaldi "hatalı satır günlüğe / çıktıya sızdı"; else gecti "hatalı satır günlüğe ve çıktıya yazılmadı"; fi
hazirla 'DB_PASSWORD="iki\nsatir"'
calistir env; esit "satır sonu içeren parola: çıkış kodu" "$?" "1"
esit "satır sonu içeren parolada yedek alınmadı" "$(yedek_sayisi)" "0"
esit "parola dosyası kalmadı" "$(artik_sayisi)" "0"

echo "12. Asılı kalan dış komut süre sınırıyla sonlandırılır (BY-001-R2)"
hazirla
BASLANGIC=$SECONDS
calistir env SAHTE_PG_DUMP_BEKLE=30 EMOR_GUNLUK_SURE_SINIRI=1; esit "çıkış kodu" "$?" "1"
if (( SECONDS - BASLANGIC < 20 )); then gecti "30 sn beklemeden sonlandı"; else kaldi "süre sınırı işlemedi"; fi
esit "günlükte süre aşımı satırı" "$(grep -c 'süre sınırını aştı' "$KLASOR/gecici-gunluk-yedek.log")" "1"
esit "yedek oluşmadı" "$(yedek_sayisi)" "0"
esit "yarım dosya / parola dosyası kalmadı" "$(artik_sayisi)" "0"
calistir env; esit "sonraki çalışma kilidi alıp başarıyla bitti" "$?" "0"
esit "sonraki çalışmada yedek oluştu" "$(yedek_sayisi)" "1"
calistir env EMOR_GUNLUK_SURE_SINIRI=0; esit "süre sınırı 0 kabul edilmez" "$?" "1"

echo "13. .env'de olmayan anahtar sürecin ortamından okunmaz (Fable incelemesi F8)"
hazirla
sed -i '/^DB_HOST=/d; /^DB_PORT=/d' "$ENV"
calistir env DB_HOST=ortamdan.gelen DB_PORT=9999 DB_PASSWORD=ortam-parolasi; esit "çıkış kodu" "$?" "0"
esit "sunucu / port betiğin varsayılanı, parola .env'den" "$(pgpass_icerigi)" '127.0.0.1:5432:erp:erp:gizli-parola'

echo
echo "Geçen: $GECEN, kalan: $KALAN"
[[ "$KALAN" -eq 0 ]]
