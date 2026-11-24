#!/usr/bin/env bash
#
# ERP Web (emor) — Yedekleme modülü SUNUCU KURULUMU (BY-003).
#
# Tasarım: docs/yedekleme/README.md §5.2, §5.5, §9. README §9 maddelerinin
# karşılıkları, çalıştırma ve geri alma: docs/yedekleme/sunucu-kurulumu.md.
#
# Yedekleme / geri yükleme işlerini web uygulamasından AYRI bir hesap yapar
# ("servis hesabı"): yedek dosyaları, imza anahtarı ve veritabanı parolası
# web sürecinden ayrı durur. Bu betik o hesabı ve çevresini kurar:
#
#   1. Linux kullanıcısı ve grubu   emor-yedek (giriş yapamaz)
#   2. Grup üyelikleri              web kullanıcısı yedekleri okuyabilsin, istek bırakabilsin
#   3. Klasörler ve izinler         /var/lib/emor-yedek/{yedekler,istekler,durum,gunluk,gizli,onbellek}
#                                   (README §5.5 tablosu; yayın kullanıcısına ACL ile dar erişim);
#                                   yedekler/.emor-yedek-deposu depo işareti (var olana dokunmaz)
#   4. Gizli dosyalar              veritabanı parola dosyası; imza anahtarının yeri
#   5. PostgreSQL rolleri           emor_yedek (yedek / geri yükleme), emor_inceleme (salt okunur)
#   6. Servis tanımı                emor-yedek.service — KURULUR, ETKİNLEŞTİRİLMEZ
#   7. Dar sudo kuralı              yalnız kuyruk servisini durdur / başlat
#
# Tekrar çalıştırılabilir: ikinci çalıştırmada hiçbir şeyi değiştirmez
# ("0 değişiklik"). Var olan hiçbir şeyi silmez (tek istisna: taşınan parola
# dosyasının eski kopyası — yeni yerdeki kopyayla bağlantı DOĞRULANDIKTAN
# sonra). Parolayı yalnız parola dosyası hiçbir yerde yoksa yeniler.
# Uygulamanın veritabanına (erp) bağlanmaz, yazmaz.
#
# Yetkiler TAM kümeyle karşılaştırılır: gerekenin bulunması yetmez, fazlası
# da sorun sayılır (ACL girdileri, rol özellikleri, rol üyelikleri, sistem
# dosyalarının sahibi / izni).
#
# Kullanım (sunucuda, proje kökünden):
#   sudo scripts/sunucu-yedekleme-kurulumu.sh            # kur / eksikleri tamamla
#   sudo scripts/sunucu-yedekleme-kurulumu.sh --denetle  # hiçbir şeyi değiştirmeden durumu raporla
#
# Çıkış kodu: 0 tamam; 1 hata ya da (--denetle) eksik / farklı madde var; 2 kullanım hatası.
#
# Bütün ad ve yollar EMOR_* ortam değişkenleriyle değiştirilebilir (lokal
# deneme için; bkz. sunucu-kurulumu.md). Sunucuda varsayılanlar kullanılır.
#
set -Eeuo pipefail
# Beklenmeyen hata sessiz kalmasın (YF-66): `set -e` komut ikamesindeki ya da
# fonksiyondaki bir hatada betiği mesajsız bitirirdi; -E tuzağı fonksiyonlara
# ve alt kabuklara da geçirir.
trap 'echo "HATA: beklenmeyen hata (satır $LINENO): $BASH_COMMAND" >&2' ERR

KIP=kur
case "${1:-}" in
  "") ;;
  --denetle) KIP=denetle ;;
  -h|--help) sed -n '2,38p' "$0"; exit 0 ;;
  *) echo "Bilinmeyen seçenek: $1 (yalnız --denetle)" >&2; exit 2 ;;
esac

UYGULAMA_KOKU="${EMOR_UYGULAMA_KOKU:-/var/www/emor}"
YEDEK_KOKU="${EMOR_YEDEK_KOKU:-/var/lib/emor-yedek}"
YEDEK_KULLANICI="${EMOR_YEDEK_KULLANICI:-emor-yedek}"
WEB_KULLANICI="${EMOR_WEB_KULLANICI:-www-data}"
YAYIN_KULLANICI="${EMOR_YAYIN_KULLANICI:-admin}"
VT_YEDEK_ROLU="${EMOR_VT_YEDEK_ROLU:-emor_yedek}"
VT_INCELEME_ROLU="${EMOR_VT_INCELEME_ROLU:-emor_inceleme}"
ESKI_PGPASS="${EMOR_ESKI_PGPASS:-/home/admin/.emor_yedek_pgpass}"
SYSTEMD_DIZINI="${EMOR_SYSTEMD_DIZINI:-/etc/systemd/system}"
SUDOERS_DOSYASI="${EMOR_SUDOERS_DOSYASI:-/etc/sudoers.d/emor-yedek}"
KUYRUK_SERVISI="${EMOR_KUYRUK_SERVISI:-emor-queue}"
SERVIS_ADI="${EMOR_SERVIS_ADI:-emor-yedek}"

PGPASS_DOSYASI="$YEDEK_KOKU/gizli/pgpass"
IMZA_ANAHTARI="$YEDEK_KOKU/gizli/imza.anahtar"
KILIT_DOSYASI="$YEDEK_KOKU/durum/kilit"
YAYIN_KILIDI="$YEDEK_KOKU/durum/yayin.kilit"
SERVIS_DOSYASI="$SYSTEMD_DIZINI/$SERVIS_ADI.service"
GERCEK_SISTEM=0
if [[ "$SYSTEMD_DIZINI" == /etc/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
  GERCEK_SISTEM=1
fi

DEGISIKLIK=0
SORUN=0
GECICI=""
trap '[[ -z "$GECICI" ]] || rm -f "$GECICI"' EXIT
# Kesilirse de EXIT tuzağı çalışsın, yarım geçici dosya kalmasın (D3)
trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM

tamam()   { echo "  [TAMAM]   $*"; }
eksik()   { echo "  [EKSİK]   $*"; SORUN=$((SORUN + 1)); }
yapildi() { echo "  [YAPILDI] $*"; DEGISIKLIK=$((DEGISIKLIK + 1)); }
bilgi()   { echo "  [BİLGİ]   $*"; }
hata()    { echo "HATA: $*" >&2; exit 1; }
baslik()  { echo; echo "== $*"; }

# Kur kipinde değişikliği yapar; denetle kipinde yalnız "eksik" der.
# Kullanım: duzelt "açıklama" komut [argüman...]
duzelt() {
  local aciklama="$1"; shift
  if [[ "$KIP" == denetle ]]; then
    eksik "$aciklama"
  else
    "$@"
    yapildi "$aciklama"
  fi
}

# Sistem dosyasını güvenle yazar: aynı klasörde geçici ada yazar, sahibini /
# iznini verir, (varsa) doğrular, sonra tek adımda yerine taşır (D3). Hedef
# hiçbir an yarım ya da geniş izinli kalmaz; hedef bağlantıysa bağlantının
# yerine dosya gelir. Geçici adın başındaki nokta: systemd ve sudo onu yok sayar.
# Kullanım: guvenli_yaz hedef içerik izin [doğrulama komutu...]
guvenli_yaz() {
  local hedef="$1" icerik="$2" kip="$3"; shift 3
  GECICI="$(mktemp "$(dirname "$hedef")/.$(basename "$hedef").XXXXXX")"
  printf '%s\n' "$icerik" > "$GECICI"
  chown root:root "$GECICI"
  chmod "$kip" "$GECICI"
  if (( $# )) && ! "$@" "$GECICI" >/dev/null; then
    hata "$hedef doğrulamadan geçmedi ($1); dosya yazılmadı."
  fi
  # İçerik diske, taşımadan sonra klasör girdisi diske (D3): kesintide yarım
  # ya da kaybolmuş sistem dosyası kalmasın
  sync -- "$GECICI"
  mv -T "$GECICI" "$hedef"
  GECICI=""
  sync -- "$(dirname "$hedef")"
}

# --- Bağlantı güvenliği (CWE-59; BY-003-R5) --------------------------------
# Servis hesabı kendi klasörlerindeki bir dosyayı, root'un dokunacağı bir yere
# giden sembolik bağlantıyla değiştirebilir. Bu yüzden:
#   - yedek kökünün sahibi root'tur: servis alt klasörleri değiştiremez;
#   - kökün üst klasörleri ve sistem dosyalarının klasörleri root dışında
#     kimsenin değiştiremeyeceği yerler olmalıdır (ust_yol_denetle);
#   - yönetilen her yol, dokunulmadan önce beklenen türde olmalıdır:
#     bağlantı, başka tür ya da birden çok adı olan dosya görülürse kurulum o
#     yola DOKUNMADAN durur (tur_denetle);
#   - servis hesabına ait nesnelerde root yalnız sahibi bağlantıyı izlemeden
#     değiştirir (chown -h); izni ve ACL'yi nesnenin sahibi (servis hesabı)
#     verir. Doğrulama ile değişiklik arasında yol değiştirilse bile servis
#     hesabı kendi yetkisinin dışına çıkamaz. Dosya içeriğini de servis yazar.
#   - Sabit bağlantıyla root dosyasına ikinci ad verilmesini çekirdek önler
#     (fs.protected_hardlinks=1; ön denetimde istenir).

# Bir klasör ve bütün üst klasörleri: bağlantı değil, sahibi root, grup /
# başkaları yazamaz (yapışkan bitli klasör hariç, ör. /tmp).
ust_yol_denetle() { # $1 mutlak klasör yolu (olmayan son parçalar atlanır)
  local yol="$1" p="" parca durum sahip kip parcalar=()
  [[ "$yol" == /* && "$yol/" != *"/../"* && "$yol/" != *"/./"* && "$yol" != *//* ]] \
    || hata "yol mutlak ve sade olmalı: $yol"
  IFS=/ read -r -a parcalar <<<"${yol#/}"
  for parca in "" "${parcalar[@]}"; do
    p="${p%/}/$parca"
    if [[ -L "$p" ]]; then hata "$p sembolik bağlantı; yol root dışında biri tarafından değiştirilebilir."; fi
    if [[ ! -e "$p" ]]; then return 0; fi
    [[ -d "$p" ]] || hata "$p klasör değil."
    durum="$(stat -c '%U %a' "$p")"; sahip="${durum% *}"; kip="${durum#* }"
    if [[ "$sahip" != root ]]; then hata "$p: sahibi $sahip; root olmalı (yol root dışında biri tarafından değiştirilebilir)."; fi
    if (( 8#$kip & 8#022 )) && ! (( 8#$kip & 8#1000 )); then
      hata "$p: grup ya da başkaları yazabiliyor ($kip); yol kurulum sırasında değiştirilebilir."
    fi
  done
}

declare -A REDDEDILEN=()
# Yönetilen yol beklenen türde mi. 0: yok ya da uygun. Değilse kurulum durur
# (o yola dokunmadan); denetimde eksik yazılır, yol sonraki adımlarda atlanır.
tur_denetle() { # $1 yol, $2 f (düz dosya) | d (klasör)
  local yol="$1" neden=""
  if [[ -L "$yol" ]]; then neden="sembolik bağlantı"
  elif [[ ! -e "$yol" ]]; then return 0
  elif [[ "$2" == d && ! -d "$yol" ]]; then neden="klasör değil"
  elif [[ "$2" == f && ! -f "$yol" ]]; then neden="düz dosya değil"
  elif [[ "$2" == f && "$(stat -c %h "$yol")" != 1 ]]; then neden="birden çok adı var (sabit bağlantı)"
  fi
  if [[ -z "$neden" ]]; then return 0; fi
  REDDEDILEN["$yol"]=1
  if [[ "$KIP" == kur ]]; then
    hata "$yol $neden — hedefi yönetilen alanın dışında olabilir; betik bu yola dokunmadan durdu. Elle inceleyip kaldırın, sonra yeniden çalıştırın."
  fi
  eksik "$yol $neden — hedefi yönetilen alanın dışında olabilir; betik dokunmaz, elle inceleyin"
  return 1
}

# İki satır kümesinin farkı ($1: comm seçeneği, -23 yalnız ilkinde, -13 yalnız
# ikincide). sort ve comm AYNI sırayı kullanmalı: Ubuntu 26.04'ün uutils
# araçlarında sort yerel dilin harf sırasını (en_US: ":" yok sayılır), comm
# bayt sırasını kullanır; "user:admin:--x" iki araçta farklı yere düşer ve
# comm "not in sorted order" ile durur. İkisi de C sırasıyla çalışır.
satir_farki() {
  LC_ALL=C comm "$1" <(LC_ALL=C sort <<<"$2") <(LC_ALL=C sort <<<"$3") | paste -sd ' '
}

# Komutu nesnenin sahibi olarak çalıştırır (root'a ait nesnede root olarak)
sahibi_olarak() { # $1 kullanıcı, sonra komut
  local kullanici="$1"; shift
  if [[ "$kullanici" == root ]]; then "$@"; else runuser -u "$kullanici" -- "$@"; fi
}

# ---------------------------------------------------------------------------
# 0. Ön denetimler
# ---------------------------------------------------------------------------
(( EUID == 0 )) || hata "root olarak çalıştırılmalı: sudo $0 ${1:-}"
for komut in useradd groupadd usermod getent install visudo openssl runuser psql php stat setfacl getfacl cmp comm; do
  command -v "$komut" >/dev/null 2>&1 || hata "$komut bulunamadı."
done
[[ -f "$UYGULAMA_KOKU/backend/.env" ]] || hata "$UYGULAMA_KOKU/backend/.env bulunamadı."
[[ -f "$UYGULAMA_KOKU/scripts/gecici-env-oku.php" ]] || hata "$UYGULAMA_KOKU/scripts/gecici-env-oku.php bulunamadı."
getent passwd "$WEB_KULLANICI" >/dev/null || hata "web kullanıcısı yok: $WEB_KULLANICI"
getent passwd "$YAYIN_KULLANICI" >/dev/null || hata "yayın kullanıcısı yok: $YAYIN_KULLANICI"
[[ "$(cat /proc/sys/fs/protected_hardlinks 2>/dev/null)" == 1 ]] \
  || hata "fs.protected_hardlinks açık değil: servis hesabı root dosyalarına sabit bağlantı verebilir."
# Yedek kökünün üstü ve sistem dosyalarının klasörleri yalnız root'un elinde mi
ust_yol_denetle "$(dirname "$YEDEK_KOKU")"
ust_yol_denetle "$SYSTEMD_DIZINI"
ust_yol_denetle "$(dirname "$SUDOERS_DOSYASI")"

# Uygulamanın veritabanı bilgileri, uygulamanın kendi ayrıştırıcısıyla (.env;
# Dikkat_Edilecekler G3). Parola OKUNMAZ: yalnız sunucu, port ve uygulama rolünün adı.
mapfile -d '' -t ENV_DEGERLERI < <(
  php "$UYGULAMA_KOKU/scripts/gecici-env-oku.php" "$UYGULAMA_KOKU/backend/vendor/autoload.php" \
    "$UYGULAMA_KOKU/backend/.env" DB_HOST DB_PORT DB_USERNAME
)
(( ${#ENV_DEGERLERI[@]} == 3 )) || hata ".env okunamadı."
VT_SUNUCU="${ENV_DEGERLERI[0]:-127.0.0.1}"
VT_PORT="${ENV_DEGERLERI[1]:-5432}"
VT_UYGULAMA_ROLU="${ENV_DEGERLERI[2]}"
for ad in "$VT_UYGULAMA_ROLU" "$VT_YEDEK_ROLU" "$VT_INCELEME_ROLU"; do
  # SQL'e tanımlayıcı olarak girer: yalnız güvenli karakterler
  [[ "$ad" =~ ^[a-z_][a-z0-9_]*$ ]] || hata "Veritabanı rol adı uygun değil: '$ad'"
done
for ad in "$YEDEK_KULLANICI" "$WEB_KULLANICI" "$YAYIN_KULLANICI" "$KUYRUK_SERVISI" "$SERVIS_ADI"; do
  # sudoers / systemd / ACL satırlarına girer
  [[ "$ad" =~ ^[a-z_][a-z0-9_-]*$ ]] || hata "Kullanıcı / servis adı uygun değil: '$ad'"
done
# Çalıştırıcı istek dosyasının göndereni dosyanın sahibiyle (uid) doğrular
# (BY-025-R1); servis tanımına bölüm 6'da yazılır. Aynı uid olsaydı web, yayın
# istisnasını taklit edebilirdi.
WEB_UID="$(id -u "$WEB_KULLANICI")"
YAYIN_UID="$(id -u "$YAYIN_KULLANICI")"
[[ "$WEB_UID" != "$YAYIN_UID" ]] || hata "web ve yayın kullanıcısının uid'si aynı ($WEB_UID); istek göndereni ayırt edilemez."

cd /   # postgres kullanıcısıyla çalışan psql, okuyamadığı bir klasörde başlamasın
pg_yonetici() { runuser -u postgres -- psql -X -A -t -q -v ON_ERROR_STOP=1 "$@"; }
pg_yonetici -c 'select 1' >/dev/null || hata "PostgreSQL'e yönetici (postgres) olarak bağlanılamadı."
[[ -n "$(pg_yonetici -c "select 1 from pg_roles where rolname = '$VT_UYGULAMA_ROLU'")" ]] \
  || hata "Uygulama rolü PostgreSQL'de yok: $VT_UYGULAMA_ROLU"

echo "Kip: $KIP · uygulama: $UYGULAMA_KOKU · yedek kökü: $YEDEK_KOKU"
echo "Veritabanı: $VT_SUNUCU:$VT_PORT · uygulama rolü: $VT_UYGULAMA_ROLU · servis rolü: $VT_YEDEK_ROLU"

# ---------------------------------------------------------------------------
# 1. Linux kullanıcısı ve grubu
#    Sistem hesabı; giriş kabuğu yok (kimse onunla oturum açamaz). Ev dizini
#    yedek kökü. Yalnız çalıştırıcı servis (BY-025) bu hesapla çalışır.
# ---------------------------------------------------------------------------
baslik "1. Linux kullanıcısı ve grubu"
if getent group "$YEDEK_KULLANICI" >/dev/null; then
  tamam "grup var: $YEDEK_KULLANICI"
else
  duzelt "grup açıldı: $YEDEK_KULLANICI" groupadd --system "$YEDEK_KULLANICI"
fi
if getent passwd "$YEDEK_KULLANICI" >/dev/null; then
  kabuk="$(getent passwd "$YEDEK_KULLANICI" | cut -d: -f7)"
  if [[ "$kabuk" == */nologin || "$kabuk" == */false ]]; then
    tamam "kullanıcı var: $YEDEK_KULLANICI (giriş kabuğu yok: $kabuk)"
  else
    eksik "kullanıcı $YEDEK_KULLANICI giriş yapabiliyor ($kabuk) — betik dokunmaz, elle inceleyin"
  fi
else
  duzelt "kullanıcı açıldı: $YEDEK_KULLANICI (giriş yapamaz)" \
    useradd --system --gid "$YEDEK_KULLANICI" --home-dir "$YEDEK_KOKU" --no-create-home \
      --shell /usr/sbin/nologin "$YEDEK_KULLANICI"
fi
KULLANICI_VAR=0
if getent passwd "$YEDEK_KULLANICI" >/dev/null; then KULLANICI_VAR=1; fi

# ---------------------------------------------------------------------------
# 2. Grup üyelikleri
#    - web kullanıcısı → emor-yedek grubu: yedekleri, günlükleri, durum ve
#      kilit dosyasını OKUR; istek klasörüne YAZAR (izinler bölüm 3).
#    - emor-yedek → web grubu: geri yüklemede uygulamayı bakım moduna alır,
#      önbelleği temizler; bunlar uygulamanın storage/ klasörüne (grubu web
#      grubu, 2775) yazmayı gerektirir.
#    - Yayın kullanıcısı (admin) gruba ALINMAZ: §5.5'e göre yedek klasörüne
#      erişmez; istek ve durum klasörlerine erişimi ACL ile verilir (bölüm 3).
#    Not: üyelik, ilgili süreç yeniden başlayınca etkinleşir (sona bakın).
# ---------------------------------------------------------------------------
baslik "2. Grup üyelikleri"
uye_mi() { id -nG "$1" 2>/dev/null | tr ' ' '\n' | grep -qx "$2"; }
for cift in "$WEB_KULLANICI:$YEDEK_KULLANICI" "$YEDEK_KULLANICI:$WEB_KULLANICI"; do
  kullanici="${cift%%:*}"; grup="${cift##*:}"
  if ! getent passwd "$kullanici" >/dev/null || ! getent group "$grup" >/dev/null; then
    eksik "$kullanici → $grup grubu (kullanıcı ya da grup henüz yok)"
  elif uye_mi "$kullanici" "$grup"; then
    tamam "$kullanici, $grup grubunda"
  else
    duzelt "$kullanici → $grup grubuna eklendi" usermod -aG "$grup" "$kullanici"
  fi
done
# Üyelikler TAM kümeyle karşılaştırılır (G5; YF-27): yedekler/ ve bekleyen/
# grup üzerinden açıktır; servis grubuna eklenen her hesap bütün dökümleri
# okur. Servis hesabının fazladan grubu (sudo, adm, postgres, docker…) da
# yetkisini genişletir. Fazlası betikçe çıkarılmaz (betiğin verdiği bir şey
# değil), elle bakılır.
if getent group "$YEDEK_KULLANICI" >/dev/null; then
  grup_uyeleri="$(getent group "$YEDEK_KULLANICI" | cut -d: -f4 | tr ',' '\n' | sed '/^$/d')"
  grup_gid="$(getent group "$YEDEK_KULLANICI" | cut -d: -f3)"
  # Birincil grubu servis grubu olan hesaplar da grup üyesidir (getent group onları listelemez)
  birincil="$(getent passwd | awk -F: -v g="$grup_gid" -v s="$YEDEK_KULLANICI" '$4 == g && $1 != s { print $1 }')"
  fazla="$(satir_farki -23 "$(printf '%s\n%s' "$grup_uyeleri" "$birincil" | sed '/^$/d')" "$WEB_KULLANICI")"
  if [[ -n "$fazla" ]]; then
    eksik "$YEDEK_KULLANICI grubunda beklenmeyen hesap: $fazla (yalnız $WEB_KULLANICI olmalı; yedekleri okur) — betik çıkarmaz, elle inceleyin (gpasswd -d <hesap> $YEDEK_KULLANICI)"
  else
    tamam "$YEDEK_KULLANICI grubunda yalnız beklenen hesap var"
  fi
fi
if getent passwd "$YEDEK_KULLANICI" >/dev/null; then
  fazla="$(satir_farki -23 "$(id -nG "$YEDEK_KULLANICI" | tr ' ' '\n')" "$(printf '%s\n%s' "$YEDEK_KULLANICI" "$WEB_KULLANICI")")"
  if [[ -n "$fazla" ]]; then
    eksik "$YEDEK_KULLANICI beklenmeyen gruplarda: $fazla (yalnız $YEDEK_KULLANICI, $WEB_KULLANICI olmalı) — betik çıkarmaz, elle inceleyin (gpasswd -d $YEDEK_KULLANICI <grup>)"
  else
    tamam "$YEDEK_KULLANICI yalnız beklenen gruplarda"
  fi
fi

# ---------------------------------------------------------------------------
# 3. Klasörler ve izinler (README §5.5)
#    Sahip:grup emor-yedek:emor-yedek; kökün sahibi root (root:emor-yedek):
#    servis hesabı alt klasörleri silemez, yerine bağlantı koyamaz (R5).
#    setgid (2xxx): içinde açılan dosya grubu devralır; web kullanıcısı grup
#    üyesi olarak okur.
#
#      klasör     izin  servis       web (grup)   yayın (admin, ACL)
#      (kök)      0750  geçer        geçer        yalnız geçer (--x)   sahibi root
#      yedekler/  2750  okur, yazar  okur         erişmez
#      istekler/  2750  okur, yazar  geçer        yalnız geçer (--x)
#        bekleyen/  2770  okur, taşır  yazar      yazar (rwx)   (yeni istekler)
#        isleniyor/ 2750  okur, yazar  okur       erişmez       (çalıştırıcının aldıkları)
#        sonuc/     2750  okur, yazar  okur       okur (r-x)    (deploy.sh sonucu bekler)
#      istekler/'in kendisi web'e ve yayına yazılabilir DEĞİL: yazılabilseydi
#      `sonuc/` bağlantıyla değiştirilip çalıştırıcının yazımı başka yere
#      yönlendirilebilirdi (D5). Web yalnız bekleyen/'e yazar; oradaki dosya
#      çalıştırıcıda tek bağlantılı düz dosya olarak denetlenir (BY-025).
#      durum/     2750  okur, yazar  okur         okur (r-x; yeni dosyalara da)
#      gunluk/    2750  okur, yazar  okur         erişmez   (olay / denetim günlüğü, ayar kopyası)
#      gizli/     0700  okur         erişmez      erişmez   (parola dosyası, imza anahtarı)
#      onbellek/  0700  okur, yazar  erişmez      erişmez   (servisin Laravel önbellekleri — bölüm 6)
#
#    durum/kilit, durum/yayin.kilit: herkes salt okunur açıp kilit alır
#    (flock, okuma için açılmış dosyada da çalışır). Önceden açılır; yoksa
#    yazma izni olmayan yayın betiği (deploy.sh) yayın kilidini yaratamazdı.
#    yayin.kilit (BY-013-R1): deploy.sh yayın boyunca tutar, üstüne geri
#    yükleme başlarken servis beklemeden almayı dener; işlem kilidinden ayrıdır.
#
#    ACL her yolda TAMAMEN karşılaştırılır: izin bitleri doğru görünse de ek
#    bir ACL girdisi (ör. yedekler/'e u:admin:r-x) yasak erişimi açar. Fark
#    varsa kurulum ACL'yi tablodaki tam kümeyle değiştirir (setfacl --set).
#
#    Bağlantı güvenliği (yukarıdaki "Bağlantı güvenliği" notu): her yol önce
#    tur_denetle'den geçer; root yalnız sahibi değiştirir (chown -h), izni ve
#    ACL'yi nesnenin sahibi verir. Kök, alt klasörlerden ÖNCE root'a geçer.
# ---------------------------------------------------------------------------
baslik "3. Klasörler ve izinler"
izin_denetle() { # $1 yol, $2 beklenen izin (ör. 2750), $3 beklenen sahip (varsayılan servis)
  local yol="$1" kip="$2" sahip="${3:-$YEDEK_KULLANICI}" mevcut beklenen
  if [[ -n "${REDDEDILEN[$yol]:-}" ]]; then return 0; fi
  # Yoksa (denetimde) zaten "eksik" yazıldı; stat'ın hatası betiği kesmesin (YF-66)
  [[ -e "$yol" ]] || return 0
  mevcut="$(stat -c '%U:%G %a' "$yol")"
  beklenen="$sahip:$YEDEK_KULLANICI ${kip#0}"
  if [[ "$mevcut" == "$beklenen" ]]; then
    tamam "$yol ($mevcut)"
  else
    duzelt "$yol: $mevcut → $beklenen" izin_duzelt "$yol" "$sahip" "$kip"
  fi
}
izin_duzelt() { # $1 yol, $2 sahip, $3 izin
  # Sahip bağlantı izlenmeden (chown -h); izni yeni sahip verir: yol arada
  # bağlantıya çevrilse de servis hesabı yalnız kendi yetkisiyle işlem yapar.
  chown -h "$2:$YEDEK_KULLANICI" "$1"
  # Başa eklenen 0 beş haneli kip yapar: GNU chmod klasörde dört haneli
  # "0750" ile setgid bitini KORUR, "00750" ile temizler.
  sahibi_olarak "$2" chmod "0$3" "$1"
}
# Kalıcı kök (BY-005-R11): uygulama klasör eşitlemesini durum/ klasöründe
# durdurur, kökün üstü yalnız geçilebilir olabilir. Bu yüzden kökün ve alt
# klasörlerin girdilerini kurulum diske zorlar — klasör önceden açılmış olsa
# da: önceki çalıştırma eşitlemeden önce kesilmiş olabilir (D3). Klasörler
# root'un kökündedir; servis onları bağlantıyla değiştiremez (D5). Denetim
# kipinde yapılmaz.
kalici_kil() { # $1 klasör
  if [[ "$KIP" != denetle && -d "$1" && ! -L "$1" ]]; then sync -- "$(dirname "$1")" "$1"; fi
}
klasor() { # $1 yol, $2 izin, $3 sahip (varsayılan servis)
  local sahip="${3:-$YEDEK_KULLANICI}"
  tur_denetle "$1" d || return 0
  if [[ -d "$1" ]]; then
    izin_denetle "$1" "$2" "$sahip"
  else
    # Üst klasör (kök ya da /var/lib) yalnız root'un: arada başkası araya giremez
    duzelt "$1 açıldı ($sahip:$YEDEK_KULLANICI $2)" \
      install -d -o "$sahip" -g "$YEDEK_KULLANICI" -m "$2" "$1"
  fi
  kalici_kil "$1"
}
HARF=(--- --x -w- -wx r-- r-x rw- rwx)
# Bir yolun izin verilen TAM ACL'si. Sonuç iki değişkende:
#   ACL_BEKLENEN  getfacl -cpE çıktısı biçiminde (karşılaştırma için)
#   ACL_TANIMI    setfacl --set girdisi (düzeltme için)
# $1 izin (ör. 2750), $2 yayın kullanıcısının izni (ör. r-x; yoksa ""),
# $3 "varsayilan" ise aynı girdiler varsayılan ACL olarak da (durum/).
# Maske grup izninin aynısıdır: yayın izni hiçbir yerde grubunkini aşmaz.
acl_tanimi() {
  local kip="${1: -3}" yayin="$2" vars="$3" u g o adli=""
  u="${HARF[${kip:0:1}]}"; g="${HARF[${kip:1:1}]}"; o="${HARF[${kip:2:1}]}"
  if [[ -n "$yayin" ]]; then adli="$YAYIN_KULLANICI:$yayin"; fi
  ACL_BEKLENEN="user::$u"; ACL_TANIMI="u::$u,g::$g,o::$o"
  if [[ -n "$adli" ]]; then ACL_BEKLENEN+=$'\n'"user:$adli"; ACL_TANIMI+=",u:$adli,m::$g"; fi
  ACL_BEKLENEN+=$'\n'"group::$g"
  if [[ -n "$adli" ]]; then ACL_BEKLENEN+=$'\n'"mask::$g"; fi
  ACL_BEKLENEN+=$'\n'"other::$o"
  if [[ "$vars" == varsayilan ]]; then
    ACL_BEKLENEN+=$'\n'"default:user::$u"$'\n'"default:user:$adli"$'\n'"default:group::$g"$'\n'"default:mask::$g"$'\n'"default:other::$o"
    ACL_TANIMI+=",d:u::$u,d:u:$adli,d:g::$g,d:m::$g,d:o::$o"
  fi
}
acl_denetle() { # $1 yol, $2 izin, $3 yayın izni ya da "", $4 "varsayilan" ya da "", $5 sahip (varsayılan servis)
  local yol="$1" sahip="${5:-$YEDEK_KULLANICI}" mevcut fazla eksikler fark komut
  if [[ -n "${REDDEDILEN[$yol]:-}" ]]; then return 0; fi
  [[ -e "$yol" ]] || return 0   # yok: yukarıda "eksik" yazıldı (YF-66)
  acl_tanimi "$2" "$3" "${4:-}"
  mevcut="$(getfacl -cpE "$yol" 2>/dev/null | sed '/^$/d')"
  if [[ "$mevcut" == "$ACL_BEKLENEN" ]]; then
    tamam "$yol ACL (yalnız izin verilen girdiler)"
    return
  fi
  fazla="$(satir_farki -23 "$mevcut" "$ACL_BEKLENEN")"
  eksikler="$(satir_farki -13 "$mevcut" "$ACL_BEKLENEN")"
  fark=""
  if [[ -n "$fazla" ]]; then fark="fazla: $fazla"; fi
  if [[ -n "$eksikler" ]]; then fark="${fark:+$fark; }eksik: $eksikler"; fi
  # --set varsayılan ACL'ye dokunmaz; beklenmiyorsa ayrıca kaldırılır (-k)
  komut='setfacl --set "$1" "$2"'
  if [[ -d "$yol" && "${4:-}" != varsayilan ]]; then komut+=' && setfacl -k "$2"'; fi
  duzelt "$yol ACL farklı — $fark" sahibi_olarak "$sahip" bash -c "$komut" _ "$ACL_TANIMI" "$yol"
}
if (( KULLANICI_VAR )); then
  # Önce kök: sahibi, izni ve ACL'si düzelmeden servis (ya da ACL ile yazma
  # izni almış biri) alt klasörleri değiştirebilir.
  klasor "$YEDEK_KOKU" 0750 root
  if [[ -d "$YEDEK_KOKU" ]]; then acl_denetle "$YEDEK_KOKU" 0750 "--x" "" root; fi
  klasor "$YEDEK_KOKU/yedekler" 2750
  klasor "$YEDEK_KOKU/istekler" 2750
  klasor "$YEDEK_KOKU/istekler/bekleyen" 2770
  klasor "$YEDEK_KOKU/istekler/isleniyor" 2750
  klasor "$YEDEK_KOKU/istekler/sonuc" 2750
  klasor "$YEDEK_KOKU/durum" 2750
  klasor "$YEDEK_KOKU/gunluk" 2750
  klasor "$YEDEK_KOKU/gizli" 0700
  klasor "$YEDEK_KOKU/onbellek" 0700
else
  eksik "klasörler (önce servis kullanıcısı açılmalı)"
fi
if [[ -d "$YEDEK_KOKU/durum" ]]; then
  acl_denetle "$YEDEK_KOKU/yedekler" 2750 ""
  acl_denetle "$YEDEK_KOKU/istekler" 2750 "--x"
  # Alt klasörler sonradan eklendi (BY-025): önceki kurulumda yoksa yukarıda "eksik" bildirildi
  if [[ -d "$YEDEK_KOKU/istekler/bekleyen" ]]; then acl_denetle "$YEDEK_KOKU/istekler/bekleyen" 2770 "rwx"; fi
  if [[ -d "$YEDEK_KOKU/istekler/isleniyor" ]]; then acl_denetle "$YEDEK_KOKU/istekler/isleniyor" 2750 ""; fi
  if [[ -d "$YEDEK_KOKU/istekler/sonuc" ]]; then acl_denetle "$YEDEK_KOKU/istekler/sonuc" 2750 "r-x"; fi
  # Varsayılan ACL: durum/ içinde sonradan açılan dosyaları da yayın kullanıcısı
  # okur. Varsayılan ACL olan klasörde umask uygulanmaz; izinler bu girdilerden gelir.
  acl_denetle "$YEDEK_KOKU/durum" 2750 "r-x" varsayilan
  acl_denetle "$YEDEK_KOKU/gunluk" 2750 ""
  acl_denetle "$YEDEK_KOKU/gizli" 0700 ""
  # Sonradan eklendi: önceki kurulumda yoksa yukarıda "eksik" bildirildi
  if [[ -d "$YEDEK_KOKU/onbellek" ]]; then acl_denetle "$YEDEK_KOKU/onbellek" 0700 ""; fi
  for kilit in "$KILIT_DOSYASI" "$YAYIN_KILIDI"; do
    if [[ -z "${REDDEDILEN[$YEDEK_KOKU/durum]:-}" ]] && tur_denetle "$kilit" f; then
      if [[ -f "$kilit" ]]; then
        izin_denetle "$kilit" 0640
        acl_denetle "$kilit" 0640 "r--"
      else
        # Servis hesabı açar: root, servisin yazabildiği klasörde dosya açmaz.
        # Dosya ve girdisi diske zorlanır: uygulama var olan kilidi eşitlemez (BY-005-R11)
        acl_tanimi 0640 "r--" ""
        duzelt "$kilit açıldı (0640; yayın kullanıcısı okur)" \
          runuser -u "$YEDEK_KULLANICI" -- bash -c 'install -m 640 /dev/null "$1" && setfacl --set "$2" "$1" && sync -- "$1" "$(dirname "$1")"' \
            _ "$kilit" "$ACL_TANIMI"
      fi
    fi
  done
elif [[ "$KIP" == denetle ]]; then
  eksik "ACL'ler ve kilit dosyası (klasörler yok)"
fi

# Depo işareti (BY-011-R3; backend YedekDeposu): yedekler/'in bu sistemin
# deposu olduğunu gösteren tek satırlık dosya, "emor-yedek-deposu <uuid>".
# Yedek klasörü ayrı bir diske bağlıysa disk ayrılınca bağlama noktası boş ve
# okunabilir kalır; işaret diskte durduğu için o zaman görünmez ve uygulama
# tarama / yedek almayı dizine ve klasöre dokunmadan durdurur. Disk ayrı
# bağlanacaksa ÖNCE bağlanır, sonra bu betik çalışır (sunucu-kurulumu.md).
# Var olan işarete dokunulmaz (kimliği değişmez); biçimi tanınmıyorsa elle
# bakılır. Dosyayı servis hesabı açar ve okur: root, servisin yazabildiği
# klasörde dosya açmaz / bağlantı izlemez (bağlantı güvenliği). Yerine
# sabit bağlantıyla konur: aynı anda açılmış bir işaretin üstüne yazılmaz.
DEPO_ISARETI="$YEDEK_KOKU/yedekler/.emor-yedek-deposu"
DEPO_BICIMI=$'^emor-yedek-deposu [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\n?$'
if [[ -n "${REDDEDILEN[$YEDEK_KOKU/yedekler]:-}" ]]; then
  :   # yukarıda bildirildi
elif [[ ! -d "$YEDEK_KOKU/yedekler" ]] || (( ! KULLANICI_VAR )); then
  if [[ "$KIP" == denetle ]]; then eksik "depo işareti (yedekler/ klasörü yok)"; fi
elif tur_denetle "$DEPO_ISARETI" f; then
  if [[ -f "$DEPO_ISARETI" ]]; then
    # Sondaki "x": komut ikamesi sondaki satır sonlarını silmesin (biçim birebir denetlenir)
    depo_icerigi="$(runuser -u "$YEDEK_KULLANICI" -- head -c 200 -- "$DEPO_ISARETI" 2>/dev/null; printf x)"
    depo_icerigi="${depo_icerigi%x}"
    if [[ "$depo_icerigi" =~ $DEPO_BICIMI ]]; then
      tamam "depo işareti: $DEPO_ISARETI"
    else
      eksik "depo işaretinin biçimi tanınmıyor: $DEPO_ISARETI — betik dokunmaz, elle inceleyin"
    fi
    izin_denetle "$DEPO_ISARETI" 0640
    acl_denetle "$DEPO_ISARETI" 0640 ""
  else
    duzelt "depo işareti açıldı: $DEPO_ISARETI (0640)" \
      runuser -u "$YEDEK_KULLANICI" -- bash -c '
        umask 027
        t="$(mktemp "$1/.emor-yedek-deposu.XXXXXX")" || exit 1
        trap "rm -f \"\$t\"" EXIT
        printf "emor-yedek-deposu %s\n" "$(cat /proc/sys/kernel/random/uuid)" > "$t" && chmod 0640 "$t" \
          && sync -- "$t" && ln -T -- "$t" "$2" && sync -- "$1"' _ "$YEDEK_KOKU/yedekler" "$DEPO_ISARETI"
  fi
fi

# ---------------------------------------------------------------------------
# 4. Gizli dosyalar
#    pgpass        emor_yedek rolünün parolası (libpq parola dosyası, 0600).
#                  BY-002'de /home/admin altında açılmıştı: oradan TAŞINIR.
#                  Taşıma: geçici ada kopyalanır, tek adımda yerine konur;
#                  eski kopya ancak bölüm 5'in sonunda yeni yerdeki dosyayla
#                  GERÇEK bağlantı kurulduktan sonra silinir. İki kopya farklıysa
#                  ya da bağlantı kurulamazsa hiçbiri silinmez (elle bakılır).
#                  Hiçbir yerde yoksa bölüm 5 yeni parola üretir, role yazar,
#                  dosyayı ancak ondan sonra yazar (yarım kalan kurulumda rol
#                  ile dosya birbirini tutmaz hâle gelmesin).
#    imza.anahtar  manifest imza anahtarının YERİ. Betik anahtar ÜRETMEZ ve
#                  taşımaz: geçiş kipinde çalışmış kurulumda anahtar
#                  uygulamadadır (storage/app/yedek-imza.key) ve servis hesabı
#                  standart girdiden buraya yazar; boş kurulumda servis olarak
#                  `yedek:imza-anahtari` üretir (docs/yedekleme/isletim.md §2).
#                  Var olan anahtarın yerine yeni üretmek eski yedeklerin
#                  imzasını geçersiz kılar.
# ---------------------------------------------------------------------------
baslik "4. Gizli dosyalar"
YENI_PAROLA_GEREK=0
ESKI_KOPYA_SILINEBILIR=0   # 1: eski kopya yenisinin aynısı; bağlantı doğrulanırsa silinir
# Standart girdideki içeriği SERVİS HESABI olarak parola dosyasına yazar
# (geçici ad → tek adımda taşı; yarıda kalırsa geçici dosya silinir). Root,
# servisin yazabildiği gizli/ klasöründe dosya açmaz (bağlantı güvenliği).
pgpass_servis_yaz() {
  runuser -u "$YEDEK_KULLANICI" -- bash -c '
    umask 077
    t="$(mktemp "$1/.pgpass.XXXXXX")" || exit 1
    trap "rm -f \"\$t\"" EXIT
    cat > "$t" && sync -- "$t" && mv -T "$t" "$2" && sync -- "$1"' _ "$YEDEK_KOKU/gizli" "$PGPASS_DOSYASI"
}
pgpass_kopyala() { pgpass_servis_yaz < "$ESKI_PGPASS"; }
if [[ -n "${REDDEDILEN[$YEDEK_KOKU/gizli]:-}" ]]; then
  :   # bölüm 3'te bildirildi
elif [[ ! -d "$YEDEK_KOKU/gizli" ]]; then
  eksik "gizli dosyalar (klasör yok)"
else
  # Eski kopyanın yeri yayın kullanıcısının evinde: o da bağlantı olmamalı
  if tur_denetle "$PGPASS_DOSYASI" f && tur_denetle "$ESKI_PGPASS" f; then
    if [[ -f "$PGPASS_DOSYASI" ]]; then
      izin_denetle "$PGPASS_DOSYASI" 0600
      acl_denetle "$PGPASS_DOSYASI" 0600 ""
      if [[ -f "$ESKI_PGPASS" ]]; then
        if cmp -s "$ESKI_PGPASS" "$PGPASS_DOSYASI"; then
          ESKI_KOPYA_SILINEBILIR=1
          bilgi "eski kopya da duruyor (içerik aynı): $ESKI_PGPASS — yeni yerdekiyle bağlantı doğrulanırsa silinir"
        else
          eksik "iki parola dosyası farklı: $ESKI_PGPASS ve $PGPASS_DOSYASI — betik hiçbirini silmez; hangisiyle bağlanılabildiğine bakıp yanlış olanı kaldırın"
        fi
      fi
    elif [[ -f "$ESKI_PGPASS" ]]; then
      duzelt "parola dosyası kopyalandı: $ESKI_PGPASS → $PGPASS_DOSYASI (eski kopya bağlantı doğrulanınca silinir)" \
        pgpass_kopyala
      if [[ "$KIP" == kur ]]; then ESKI_KOPYA_SILINEBILIR=1; fi
    elif [[ "$KIP" == denetle ]]; then
      eksik "parola dosyası yok: $PGPASS_DOSYASI"
    else
      YENI_PAROLA_GEREK=1
      bilgi "parola dosyası yok; bölüm 5'te yeni parola üretilecek"
    fi
  fi
  if tur_denetle "$IMZA_ANAHTARI" f; then
    if [[ -f "$IMZA_ANAHTARI" ]]; then
      izin_denetle "$IMZA_ANAHTARI" 0600
      acl_denetle "$IMZA_ANAHTARI" 0600 ""
    else
      bilgi "imza anahtarı henüz yok: $IMZA_ANAHTARI (betik üretmez / taşımaz: isletim.md §2)"
    fi
  fi
fi

# ---------------------------------------------------------------------------
# 5. PostgreSQL rolleri (README §5.2, §9 madde 1 ve 6; kesif.md §3–§6)
#    emor_yedek     LOGIN CREATEDB; yönetici değil, rol açamaz. Uygulama rolüne
#                   ÜYE (INHERIT + SET, ADMIN değil): veritabanını uygulama rolü
#                   adına açar, geri yüklemeyi onun adına yapar (pg_restore
#                   --role), erp'nin adını değiştirir, bağlantılarını koparır.
#                   postgres veritabanına bağlanabilir (ad değiştirme oradan).
#    emor_inceleme  NOLOGIN, yetkisiz: geri yüklenen kopyalarda salt okuma
#                   yetkisini çalıştırıcı verir (README §4.5-A). Girişin
#                   açılması ayrı bir karardır; betik açmaz, açıksa dokunmaz.
#                   NOLOGIN rolü salt okunur yapmaz: yönetici / veritabanı açma /
#                   rol açma / çoğaltma / RLS atlama özelliklerinden biri açıksa
#                   denetim bildirir, kurulum kapatır.
#    Fazla rol üyeliği (ör. emor_inceleme → erp, emor_yedek → pg_write_all_data)
#    yetkiyi genişletir: bildirilir, betik geri almaz (betiğin verdiği bir şey değil).
#    Bu iki role ÜYE olan roller de bildirilir (onların yetkisini devralırlar).
#    Parola hiçbir komut satırına yazılmaz: psql'e standart girdiden verilir.
# ---------------------------------------------------------------------------
baslik "5. PostgreSQL rolleri"
# Sıra: giriş, veritabanı açma, yönetici, rol açma, çoğaltma, RLS atlama
rol_ozellikleri() {
  pg_yonetici -c "select concat_ws(' ', rolcanlogin, rolcreatedb, rolsuper, rolcreaterole, rolreplication, rolbypassrls) from pg_roles where rolname = '$1'"
}
acik_yetkiler() { # rol_ozellikleri çıktısından girişten sonraki açık özelliklerin adları
  local adlar=("veritabanı açma" yönetici "rol açma" çoğaltma "RLS atlama") oz i sonuc=""
  read -r -a oz <<<"$1"
  for i in 0 1 2 3 4; do
    if [[ "${oz[i+1]}" == t ]]; then sonuc="${sonuc:+$sonuc, }${adlar[i]}"; fi
  done
  printf '%s' "$sonuc"
}
fazla_uyelikler() { # $1 rol, $2 beklenen tek üyelik (yoksa "")
  pg_yonetici -c "select coalesce(string_agg(r.rolname, ', ' order by r.rolname), '') from pg_auth_members m join pg_roles r on r.oid = m.roleid join pg_roles u on u.oid = m.member where u.rolname = '$1' and r.rolname <> '$2'"
}
uyelik_denetle() { # $1 rol, $2 beklenen tek üyelik (yoksa "")
  local fazla
  fazla="$(fazla_uyelikler "$1" "$2")"
  if [[ -z "$fazla" ]]; then
    tamam "$1 başka rollere üye değil"
  else
    eksik "$1 başka rollere üye: $fazla — yetkisini genişletir; betik geri almaz, elle inceleyin (revoke <rol> from $1)"
  fi
}
# Tersi de (YF-27; G5): bu role ÜYE olan roller onun yetkisini devralır
# (emor_yedek'e üye bir rol veritabanı açar, erp adına yazar). Beklenen üye yok.
uyeleri_denetle() { # $1 rol
  local uyeler
  uyeler="$(pg_yonetici -c "select coalesce(string_agg(u.rolname, ', ' order by u.rolname), '') from pg_auth_members m join pg_roles r on r.oid = m.roleid join pg_roles u on u.oid = m.member where r.rolname = '$1'")"
  if [[ -z "$uyeler" ]]; then
    tamam "$1 rolüne başka rol üye değil"
  else
    eksik "$1 rolüne üye roller: $uyeler — onun yetkilerini devralır; betik geri almaz, elle inceleyin (revoke $1 from <rol>)"
  fi
}
pgpass_kacir() { local d="${1//\\/\\\\}"; printf '%s' "${d//:/\\:}"; }
# Standart girdideki parolanın PostgreSQL SCRAM-SHA-256 doğrulayıcısı (RFC 5802;
# PostgreSQL'in sakladığı biçim). Parola argümanda / ortamda durmaz (D2).
scram_ozeti() {
  php -r '
    $parola = stream_get_contents(STDIN);
    $tuz = random_bytes(16);
    $tekrar = 4096;
    $anahtar = hash_pbkdf2("sha256", $parola, $tuz, $tekrar, 32, true);
    $istemci = hash_hmac("sha256", "Client Key", $anahtar, true);
    $sunucu = hash_hmac("sha256", "Server Key", $anahtar, true);
    printf("SCRAM-SHA-256\$%d:%s\$%s:%s", $tekrar, base64_encode($tuz), base64_encode(hash("sha256", $istemci, true)), base64_encode($sunucu));'
}
# Parolayı role yazar; başarılıysa parola dosyasını yazar (geçici ad → taşı).
# Role düz parola değil SCRAM doğrulayıcısı gönderilir: `alter role … password
# '<düz parola>'` metni sunucu günlüğüne (log_statement, hata günlüğü) ve
# pg_stat_statements'a düşebilir (D2, Fable incelemesi F3). PostgreSQL
# doğrulayıcı biçimindeki metni olduğu gibi saklar.
yeni_parola_yaz() {
  local parola ozet
  parola="$(openssl rand -hex 24)"
  ozet="$(printf '%s' "$parola" | scram_ozeti)"
  [[ "$ozet" =~ ^SCRAM-SHA-256\$4096:[A-Za-z0-9+/=]+\$[A-Za-z0-9+/=]+:[A-Za-z0-9+/=]+$ ]] \
    || hata "Parolanın SCRAM özeti üretilemedi (php); rol parolası değiştirilmedi."
  printf "alter role %s password '%s';\n" "$VT_YEDEK_ROLU" "$ozet" | pg_yonetici -f -
  printf '%s:%s:*:%s:%s\n' "$(pgpass_kacir "$VT_SUNUCU")" "$(pgpass_kacir "$VT_PORT")" \
    "$VT_YEDEK_ROLU" "$parola" | pgpass_servis_yaz
}

OZ="$(rol_ozellikleri "$VT_YEDEK_ROLU")"
if [[ -z "$OZ" ]]; then
  if [[ "$KIP" == denetle ]]; then
    eksik "rol yok: $VT_YEDEK_ROLU"
  elif (( ! YENI_PAROLA_GEREK )); then
    hata "rol $VT_YEDEK_ROLU yok ama parola dosyası var ($PGPASS_DOSYASI). Hangisinin doğru olduğu belli değil; dosyayı inceleyip kaldırın ve yeniden çalıştırın."
  else
    pg_yonetici -c "create role $VT_YEDEK_ROLU login createdb nosuperuser nocreaterole noreplication nobypassrls"
    yapildi "rol açıldı: $VT_YEDEK_ROLU (LOGIN CREATEDB)"
  fi
elif [[ "$OZ" == "t t f f f f" ]]; then
  tamam "rol $VT_YEDEK_ROLU: giriş ve veritabanı açma evet; yönetici, rol açma, çoğaltma, RLS atlama hayır"
else
  duzelt "rol $VT_YEDEK_ROLU özellikleri ($OZ) → LOGIN CREATEDB NOSUPERUSER NOCREATEROLE NOREPLICATION NOBYPASSRLS" \
    pg_yonetici -c "alter role $VT_YEDEK_ROLU login createdb nosuperuser nocreaterole noreplication nobypassrls"
fi
if (( YENI_PAROLA_GEREK )); then
  yeni_parola_yaz
  yapildi "yeni parola role yazıldı, parola dosyası açıldı: $PGPASS_DOSYASI"
fi

if [[ -n "$(rol_ozellikleri "$VT_YEDEK_ROLU")" ]]; then
  UYELIK="$(pg_yonetici -c "select concat_ws(' ', m.inherit_option, m.set_option, m.admin_option) from pg_auth_members m join pg_roles r on r.oid = m.roleid join pg_roles u on u.oid = m.member where r.rolname = '$VT_UYGULAMA_ROLU' and u.rolname = '$VT_YEDEK_ROLU'")"
  if [[ "$UYELIK" == "t t f" ]]; then
    tamam "$VT_YEDEK_ROLU, $VT_UYGULAMA_ROLU rolüne üye (inherit, set; admin değil)"
  elif [[ -z "$UYELIK" ]]; then
    duzelt "$VT_YEDEK_ROLU → $VT_UYGULAMA_ROLU üyeliği verildi" \
      pg_yonetici -c "grant $VT_UYGULAMA_ROLU to $VT_YEDEK_ROLU with inherit true, set true, admin false"
  else
    # Seçenekler farklı: tek işlemde geri alınıp doğru seçeneklerle verilir
    duzelt "$VT_YEDEK_ROLU → $VT_UYGULAMA_ROLU üyelik seçenekleri ($UYELIK) → inherit, set, admin değil" \
      pg_yonetici -1 -c "revoke $VT_UYGULAMA_ROLU from $VT_YEDEK_ROLU" \
        -c "grant $VT_UYGULAMA_ROLU to $VT_YEDEK_ROLU with inherit true, set true, admin false"
  fi
  if [[ "$(pg_yonetici -c "select has_database_privilege('$VT_YEDEK_ROLU', 'postgres', 'CONNECT')")" == t ]]; then
    tamam "$VT_YEDEK_ROLU, postgres veritabanına bağlanabilir"
  else
    duzelt "$VT_YEDEK_ROLU → postgres veritabanına bağlanma yetkisi" \
      pg_yonetici -c "grant connect on database postgres to $VT_YEDEK_ROLU"
  fi
  uyelik_denetle "$VT_YEDEK_ROLU" "$VT_UYGULAMA_ROLU"
  uyeleri_denetle "$VT_YEDEK_ROLU"
fi

OZ="$(rol_ozellikleri "$VT_INCELEME_ROLU")"
if [[ -z "$OZ" ]]; then
  duzelt "rol açıldı: $VT_INCELEME_ROLU (NOLOGIN, salt okunur inceleme)" \
    pg_yonetici -c "create role $VT_INCELEME_ROLU nologin nosuperuser nocreatedb nocreaterole noreplication nobypassrls"
else
  ACIK="$(acik_yetkiler "$OZ")"
  if [[ -z "$ACIK" ]]; then
    tamam "rol $VT_INCELEME_ROLU: yönetici, veritabanı açma, rol açma, çoğaltma, RLS atlama hayır"
  else
    duzelt "rol $VT_INCELEME_ROLU salt okunur değil: $ACIK açık → hepsi kapatıldı (giriş durumuna dokunulmadı)" \
      pg_yonetici -c "alter role $VT_INCELEME_ROLU nosuperuser nocreatedb nocreaterole noreplication nobypassrls"
  fi
  if [[ "${OZ%% *}" == t ]]; then
    bilgi "rol $VT_INCELEME_ROLU: giriş açık (elle açılmış olabilir; ayrı karar, dokunulmadı)"
  fi
  uyelik_denetle "$VT_INCELEME_ROLU" ""
  uyeleri_denetle "$VT_INCELEME_ROLU"
fi

# Servis hesabı, parola dosyasıyla gerçekten bağlanabiliyor mu
BAGLANTI_TAMAM=0
if [[ -f "$PGPASS_DOSYASI" && ! -L "$PGPASS_DOSYASI" ]] && (( KULLANICI_VAR )); then
  # Temiz ortam (env -i): kabuktan devralınan PGPASSWORD dosyanın önüne geçer;
  # bağlantı dosya geçersizken de kurulup eski kopyanın silinmesine yol açardı
  # (BY-001-R6). Servis de systemd'nin temiz ortamında çalışır.
  if runuser -u "$YEDEK_KULLANICI" -- env -i PATH="$PATH" PGPASSFILE="$PGPASS_DOSYASI" PGCONNECT_TIMEOUT=10 \
       psql -h "$VT_SUNUCU" -p "$VT_PORT" -U "$VT_YEDEK_ROLU" -d postgres -X -A -t -q -w -c 'select 1' >/dev/null 2>&1; then
    tamam "$YEDEK_KULLANICI, parola dosyasıyla $VT_YEDEK_ROLU olarak postgres veritabanına bağlanabiliyor"
    BAGLANTI_TAMAM=1
  else
    eksik "$YEDEK_KULLANICI, $VT_YEDEK_ROLU olarak bağlanamıyor (parola dosyası ile rol parolası uyuşmuyor ya da sunucu / port farklı)"
  fi
fi

# Taşınan parola dosyasının eski kopyası: yalnız yeni yerdeki aynı içerikli
# dosyayla bağlantı kurulduysa silinir (bölüm 4). Aksi hâlde son çalışan kopya
# o olabilir; korunur.
if (( ESKI_KOPYA_SILINEBILIR )) && [[ -f "$ESKI_PGPASS" ]]; then
  if (( BAGLANTI_TAMAM )) && cmp -s "$ESKI_PGPASS" "$PGPASS_DOSYASI"; then
    duzelt "eski parola kopyası silindi: $ESKI_PGPASS (yeni yerdeki dosyayla bağlantı doğrulandı)" \
      rm -f "$ESKI_PGPASS"
  else
    bilgi "eski parola kopyası korunuyor: $ESKI_PGPASS (yeni yerdeki dosyayla bağlantı doğrulanamadı)"
  fi
fi

# ---------------------------------------------------------------------------
# 6. Servis tanımı (README §9 madde 3)
#    Yedekleme çalıştırıcısı bu hesapla, arka planda sürekli çalışır.
#    UMask 0027: ürettiği dosyaları grup okur, başkalarına kapalı.
#    Laravel günlükleri (bütün dosya kanalları, acil durum dahil) LOG_KLASORU
#    ile servisin gunluk/ klasöründe: web'in yazabildiği storage/logs'taki bir
#    dosyayı web süreci bağlantıyla değiştirip servisin yazımını servisin
#    kendi dosyalarına (parola, manifest) yönlendirebilirdi (D5, BY-003-R6).
#    Laravel önbellekleri (APP_*_CACHE, VIEW_COMPILED_PATH) servisin kendi
#    onbellek/ klasöründe: uygulamanın bootstrap/cache'ini ve derlenmiş
#    görünümlerini web kullanıcısı YAZABİLİR (grubu www-data, g+w). Servis
#    onları yüklerse ele geçirilmiş bir web süreci servis hesabıyla kod
#    çalıştırır, imza anahtarına ve parola dosyasına ulaşır (D7, Fable
#    incelemesi F1). storage/ yolu (LARAVEL_STORAGE_PATH) bilerek DEĞİŞMEZ:
#    servis geri yüklemede uygulamayı bakım moduna alır; bakım modu dosyası
#    uygulamanın storage/framework'ünde olmalıdır (bölüm 2). Oradan PHP
#    dosyası yüklenmez. O dosya web'in yazabildiği yerde olduğu için onu
#    yazan kod bağlantı izlememelidir (BY-028 kabul ölçütü).
#    Tanım KURULUR ama etkinleştirilmez, başlatılmaz: servis kipine geçiş
#    kullanıcı talimatıyla yapılır (isletim.md §2). Komut adı değişirse betik
#    tanımı günceller.
#    Durdurulunca çalıştırıcı o anki işi bitirip çıkar: KillMode=mixed ile
#    SIGTERM yalnız ana sürece (PHP) gider; pg_dump / pg_restore / gpg / sftp
#    alt süreçleri işlerini bitirir (systemd'nin varsayılanı control-group
#    SIGTERM'ü bütün sürece gönderip süren yedeği / geri yüklemeyi yarıda
#    öldürürdü — YF-23). Geri yükleme `sure_siniri.geri_yukleme` (3600 sn)
#    sürebilir: durdurma / yeniden başlatma TimeoutStopSec (4000 sn) kadar
#    bekleyebilir; iş bu süreyi aşarsa yine bütün süreçler SIGKILL ile öldürülür.
#    YEDEKLEME_WEB_UID / YEDEKLEME_YAYIN_UID: çalıştırıcı istek dosyasının
#    göndereni dosyanın sahibiyle doğrular (BY-025-R1) — kullanıcılı istekler
#    yalnız web kullanıcısının, kullanıcısız yayın öncesi yedek yalnız yayın
#    kullanıcısının dosyasıysa işlenir. Değerler yukarıdaki kullanıcı adlarından
#    (bölüm 0; sudoers'taki gibi tek kaynak); kullanıcı yeniden açılıp uid'si
#    değişirse içerik farklı görünür, kurulum tanımı günceller.
#    Planlı yedek ve günlük temizlik de bu serviste yürür (BY-009-R1).
# ---------------------------------------------------------------------------
baslik "6. Servis tanımı"
# Servis ortamındaki systemctl yolu ve kuyruk servisi (BY-028) sudoers kuralıyla
# (bölüm 7) birebir aynı olmalı: ikisi de buradan gelir.
SYSTEMCTL="$(command -v systemctl || echo /usr/bin/systemctl)"
SERVIS_ICERIGI="[Unit]
Description=emor yedekleme calistiricisi (docs/yedekleme)
After=network.target postgresql.service

[Service]
User=$YEDEK_KULLANICI
Group=$YEDEK_KULLANICI
UMask=0027
WorkingDirectory=$UYGULAMA_KOKU/backend
Environment=PGPASSFILE=$PGPASS_DOSYASI
Environment=APP_CONFIG_CACHE=$YEDEK_KOKU/onbellek/config.php
Environment=APP_SERVICES_CACHE=$YEDEK_KOKU/onbellek/services.php
Environment=APP_PACKAGES_CACHE=$YEDEK_KOKU/onbellek/packages.php
Environment=APP_ROUTES_CACHE=$YEDEK_KOKU/onbellek/routes.php
Environment=APP_EVENTS_CACHE=$YEDEK_KOKU/onbellek/events.php
Environment=VIEW_COMPILED_PATH=$YEDEK_KOKU/onbellek/views
Environment=LOG_KLASORU=$YEDEK_KOKU/gunluk
Environment=YEDEKLEME_KUYRUK_SERVISI=$KUYRUK_SERVISI
Environment=YEDEKLEME_SYSTEMCTL=$SYSTEMCTL
Environment=YEDEKLEME_WEB_UID=$WEB_UID
Environment=YEDEKLEME_YAYIN_UID=$YAYIN_UID
ExecStart=/usr/bin/php $UYGULAMA_KOKU/backend/artisan yedek:calistir
Restart=always
RestartSec=5
KillMode=mixed
TimeoutStopSec=4000

[Install]
WantedBy=multi-user.target"
# Sistem dosyası: içerik kadar sahibi ve izni de denetlenir (root:root 644).
# Başkasının yazabildiği bir servis tanımı, root'un başlatacağı komutu ve
# çalıştıran kullanıcıyı değiştirmeye açıktır. stat bağlantıyı izlemez:
# hedef bağlantıysa izni "777" görünür ve dosya yeniden yazılır.
sistem_dosyasi_durumu() { # $1 yol, $2 beklenen içerik, $3 beklenen izin → boşsa uygun, değilse neden
  local yol="$1" sahip
  if [[ ! -e "$yol" && ! -L "$yol" ]]; then echo "yok"; return; fi
  sahip="$(stat -c '%U:%G %a' "$yol")"
  if [[ -L "$yol" || ! -f "$yol" ]]; then echo "düz dosya değil ($sahip)"; return; fi
  if [[ "$(cat "$yol")" != "$2" ]]; then echo "içeriği farklı"; return; fi
  if [[ "$sahip" != "root:root $3" ]]; then echo "sahibi / izni $sahip, beklenen root:root $3"; fi
}
neden="$(sistem_dosyasi_durumu "$SERVIS_DOSYASI" "$SERVIS_ICERIGI" 644)"
if [[ -z "$neden" ]]; then
  tamam "$SERVIS_DOSYASI güncel (root:root 644)"
else
  duzelt "$SERVIS_DOSYASI yazıldı ($neden; etkinleştirilmedi)" \
    guvenli_yaz "$SERVIS_DOSYASI" "$SERVIS_ICERIGI" 644
  if [[ "$KIP" == kur ]] && (( GERCEK_SISTEM )); then
    systemctl daemon-reload
  fi
fi
if (( GERCEK_SISTEM )); then
  bilgi "$SERVIS_ADI etkin mi: $(systemctl is-enabled "$SERVIS_ADI" 2>/dev/null || true) (servis kipine geçilene kadar 'disabled' beklenir)"
fi

# ---------------------------------------------------------------------------
# 7. Dar sudo kuralı (README §9 madde 1, §6.4 adım 2)
#    Üstüne geri yüklemede kuyruk işçisi durdurulur. Servis hesabı YALNIZ şu
#    iki komutu (stop / start; KuyrukServisi yalnız bunları çağırır)
#    şifresiz çalıştırabilir; başka hiçbir şeyi.
#    Zamanlayıcı (cron, www-data) için sudo gerekmez: uygulama bakım
#    modundayken zamanlanmış işler çalışmaz (Laravel Scheduling\Event;
#    bakım modu dosya sürücüsüyle, veritabanına dayanmaz). Ayrıca işler açık
#    geri yüklemeyi durum dosyasından görür (BY-029).
# ---------------------------------------------------------------------------
baslik "7. Dar sudo kuralı"
SUDOERS_ICERIGI="# emor yedekleme (scripts/sunucu-yedekleme-kurulumu.sh) - yalniz kuyruk servisi
$YEDEK_KULLANICI ALL=(root) NOPASSWD: $SYSTEMCTL stop $KUYRUK_SERVISI, $SYSTEMCTL start $KUYRUK_SERVISI"
neden="$(sistem_dosyasi_durumu "$SUDOERS_DOSYASI" "$SUDOERS_ICERIGI" 440)"
if [[ -z "$neden" ]]; then
  tamam "$SUDOERS_DOSYASI güncel (root:root 440)"
else
  # Yerine konmadan önce geçici adda doğrulanır: hatalı bir sudoers dosyası
  # sudo'yu tümden bozar. (sudo, adında nokta olan geçici dosyayı okumaz.)
  duzelt "$SUDOERS_DOSYASI yok ya da farklı ($neden) → yazıldı (visudo ile doğrulandı)" \
    guvenli_yaz "$SUDOERS_DOSYASI" "$SUDOERS_ICERIGI" 440 visudo -cf
fi

# ---------------------------------------------------------------------------
# Sonuç
# ---------------------------------------------------------------------------
echo
if [[ "$KIP" == denetle ]]; then
  if (( SORUN == 0 )); then
    echo "DENETİM: her şey yerinde."
    exit 0
  fi
  echo "DENETİM: $SORUN eksik / farklı madde var. Kurmak için: sudo $0"
  exit 1
fi
echo "KURULUM: $DEGISIKLIK değişiklik yapıldı; elle bakılacak madde: $SORUN."
if (( DEGISIKLIK > 0 )); then
  cat <<EOF

Otomatik yapılmayanlar:
  - Grup üyelikleri ilgili süreçler yeniden başlayınca etkinleşir:
      sudo systemctl restart php8.5-fpm $KUYRUK_SERVISI
    Kısa bir kesinti olur; kullanıcıya haber verilmeden yapılmaz.
  - Servisi etkinleştirmek servis kipine geçişin işidir (isletim.md §2):
      sudo systemctl enable --now $SERVIS_ADI
    Servis zaten çalışıyorsa ve tanımı değiştiyse yeni ortam yeniden başlatınca
    geçerli olur (o anki işi bitirip çıkar): sudo systemctl restart $SERVIS_ADI
EOF
fi
# `(( ))` 0 sonucunda 1 döner: ERR tuzağı "beklenmeyen hata" demesin
if (( SORUN == 0 )); then exit 0; fi
exit 1
