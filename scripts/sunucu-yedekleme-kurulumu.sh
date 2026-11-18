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
#   3. Klasörler ve izinler         /var/lib/emor-yedek/{yedekler,istekler,durum,gunluk,gizli}
#                                   (README §5.5 tablosu; yayın kullanıcısına ACL ile dar erişim)
#   4. Gizli dosyalar               veritabanı parola dosyası; imza anahtarının yeri
#   5. PostgreSQL rolleri           emor_yedek (yedek / geri yükleme), emor_inceleme (salt okunur)
#   6. Servis tanımı                emor-yedek.service — KURULUR, ETKİNLEŞTİRİLMEZ
#   7. Dar sudo kuralı              yalnız kuyruk servisini durdur / başlat / sorgula
#
# Tekrar çalıştırılabilir: ikinci çalıştırmada hiçbir şeyi değiştirmez
# ("0 değişiklik"). Var olan hiçbir şeyi silmez (tek istisna: taşınan parola
# dosyasının eski kopyası). Parolayı yalnız parola dosyası hiçbir yerde yoksa
# yeniler. Uygulamanın veritabanına (erp) bağlanmaz, yazmaz.
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
set -euo pipefail

KIP=kur
case "${1:-}" in
  "") ;;
  --denetle) KIP=denetle ;;
  -h|--help) sed -n '2,33p' "$0"; exit 0 ;;
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
SERVIS_DOSYASI="$SYSTEMD_DIZINI/$SERVIS_ADI.service"
GERCEK_SISTEM=0
if [[ "$SYSTEMD_DIZINI" == /etc/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
  GERCEK_SISTEM=1
fi

DEGISIKLIK=0
SORUN=0
GECICI=""
trap '[[ -z "$GECICI" ]] || rm -f "$GECICI"' EXIT

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

# ---------------------------------------------------------------------------
# 0. Ön denetimler
# ---------------------------------------------------------------------------
(( EUID == 0 )) || hata "root olarak çalıştırılmalı: sudo $0 ${1:-}"
for komut in useradd groupadd usermod getent install visudo openssl runuser psql php stat setfacl getfacl; do
  command -v "$komut" >/dev/null 2>&1 || hata "$komut bulunamadı."
done
[[ -f "$UYGULAMA_KOKU/backend/.env" ]] || hata "$UYGULAMA_KOKU/backend/.env bulunamadı."
[[ -f "$UYGULAMA_KOKU/scripts/gecici-env-oku.php" ]] || hata "$UYGULAMA_KOKU/scripts/gecici-env-oku.php bulunamadı."
getent passwd "$WEB_KULLANICI" >/dev/null || hata "web kullanıcısı yok: $WEB_KULLANICI"
getent passwd "$YAYIN_KULLANICI" >/dev/null || hata "yayın kullanıcısı yok: $YAYIN_KULLANICI"

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
if uye_mi "$YAYIN_KULLANICI" "$YEDEK_KULLANICI"; then
  eksik "$YAYIN_KULLANICI, $YEDEK_KULLANICI grubunda (§5.5: yedek klasörüne erişmemeli) — betik çıkarmaz, elle inceleyin"
fi

# ---------------------------------------------------------------------------
# 3. Klasörler ve izinler (README §5.5)
#    Sahip:grup emor-yedek:emor-yedek. setgid (2xxx): içinde açılan dosya
#    grubu devralır; web kullanıcısı grup üyesi olarak okur.
#
#      klasör     izin  servis       web (grup)   yayın (admin, ACL)
#      (kök)      0750  her şey      geçer        yalnız geçer (--x)
#      yedekler/  2750  okur, yazar  okur         erişmez
#      istekler/  2770  okur, siler  yazar        yazar (rwx)
#      durum/     2750  okur, yazar  okur         okur (r-x; yeni dosyalara da)
#      gunluk/    2750  okur, yazar  okur         erişmez   (olay / denetim günlüğü, ayar kopyası)
#      gizli/     0700  okur         erişmez      erişmez   (parola dosyası, imza anahtarı)
#
#    durum/kilit: herkes salt okunur açıp kilit alır (flock, okuma için
#    açılmış dosyada da çalışır). Önceden açılır; yoksa yazma izni olmayan
#    yayın betiği (deploy.sh) onu yaratamazdı.
# ---------------------------------------------------------------------------
baslik "3. Klasörler ve izinler"
izin_denetle() { # $1 yol, $2 beklenen izin (ör. 2750)
  local yol="$1" kip="$2" mevcut beklenen
  mevcut="$(stat -c '%U:%G %a' "$yol")"
  beklenen="$YEDEK_KULLANICI:$YEDEK_KULLANICI ${kip#0}"
  if [[ "$mevcut" == "$beklenen" ]]; then
    tamam "$yol ($mevcut)"
  else
    # Başa eklenen 0 beş haneli kip yapar: GNU chmod klasörde dört haneli
    # "0750" ile setgid bitini KORUR, "00750" ile temizler.
    duzelt "$yol: $mevcut → $beklenen" \
      bash -c 'chown "$1:$1" "$2" && chmod "0$3" "$2"' _ "$YEDEK_KULLANICI" "$yol" "$kip"
  fi
}
klasor() { # $1 yol, $2 izin
  if [[ -d "$1" ]]; then
    izin_denetle "$1" "$2"
  else
    duzelt "$1 açıldı ($YEDEK_KULLANICI:$YEDEK_KULLANICI $2)" \
      install -d -o "$YEDEK_KULLANICI" -g "$YEDEK_KULLANICI" -m "$2" "$1"
  fi
}
acl() { # $1 yol, $2 setfacl girdisi (ör. u:admin:r-x, d:o::---)
  local yol="$1" girdi="$2" onek="" tur beklenen
  # getfacl'ın yazdığı biçime çevir: d:u:ad:izin → default:user:ad:izin
  if [[ "$girdi" == d:* ]]; then onek="default:"; girdi="${girdi#d:}"; fi
  case "${girdi%%:*}" in
    u) tur=user ;; g) tur=group ;; o) tur=other ;; *) hata "ACL girdisi anlaşılmadı: $2" ;;
  esac
  beklenen="$onek$tur:${girdi#*:}"
  if getfacl -cp "$yol" 2>/dev/null | grep -qxF "$beklenen"; then
    tamam "$yol ACL $beklenen"
  else
    duzelt "$yol ACL $beklenen" setfacl -m "$2" "$yol"
  fi
}
if (( KULLANICI_VAR )); then
  klasor "$YEDEK_KOKU" 0750
  klasor "$YEDEK_KOKU/yedekler" 2750
  klasor "$YEDEK_KOKU/istekler" 2770
  klasor "$YEDEK_KOKU/durum" 2750
  klasor "$YEDEK_KOKU/gunluk" 2750
  klasor "$YEDEK_KOKU/gizli" 0700
else
  eksik "klasörler (önce servis kullanıcısı açılmalı)"
fi
if [[ -d "$YEDEK_KOKU/durum" ]]; then
  acl "$YEDEK_KOKU" "u:$YAYIN_KULLANICI:--x"
  acl "$YEDEK_KOKU/istekler" "u:$YAYIN_KULLANICI:rwx"
  acl "$YEDEK_KOKU/durum" "u:$YAYIN_KULLANICI:r-x"
  # Varsayılan ACL: durum/ içinde sonradan açılan dosyaları da yayın kullanıcısı
  # okur. Varsayılan ACL olan klasörde umask uygulanmaz; izinler bu girdilerden gelir.
  for girdi in "d:u::rwx" "d:g::r-x" "d:o::---" "d:u:$YAYIN_KULLANICI:r-x"; do
    acl "$YEDEK_KOKU/durum" "$girdi"
  done
  if [[ -f "$KILIT_DOSYASI" ]]; then
    izin_denetle "$KILIT_DOSYASI" 0640
    acl "$KILIT_DOSYASI" "u:$YAYIN_KULLANICI:r--"
  else
    duzelt "$KILIT_DOSYASI açıldı (0640; yayın kullanıcısı okur)" \
      bash -c 'install -o "$1" -g "$1" -m 640 /dev/null "$2" && setfacl -m "u:$3:r--" "$2"' \
        _ "$YEDEK_KULLANICI" "$KILIT_DOSYASI" "$YAYIN_KULLANICI"
  fi
elif [[ "$KIP" == denetle ]]; then
  eksik "ACL'ler ve kilit dosyası (klasörler yok)"
fi

# ---------------------------------------------------------------------------
# 4. Gizli dosyalar
#    pgpass        emor_yedek rolünün parolası (libpq parola dosyası, 0600).
#                  BY-002'de /home/admin altında açılmıştı: oradan TAŞINIR.
#                  Hiçbir yerde yoksa bölüm 5 yeni parola üretir, role yazar,
#                  dosyayı ancak ondan sonra yazar (yarım kalan kurulumda rol
#                  ile dosya birbirini tutmaz hâle gelmesin).
#    imza.anahtar  manifest imza anahtarının YERİ. Betik anahtar ÜRETMEZ:
#                  biçimi BY-006'da belirlenir, geçiş döneminde uygulamada
#                  durur ve BY-025'te buraya taşınır. Yeni anahtar üretmek eski
#                  yedeklerin imzasını geçersiz kılar.
# ---------------------------------------------------------------------------
baslik "4. Gizli dosyalar"
YENI_PAROLA_GEREK=0
if [[ ! -d "$YEDEK_KOKU/gizli" ]]; then
  eksik "gizli dosyalar (klasör yok)"
else
  if [[ -f "$PGPASS_DOSYASI" ]]; then
    izin_denetle "$PGPASS_DOSYASI" 0600
    if [[ -f "$ESKI_PGPASS" ]]; then
      # İkisi birden varsa eskisi gereksiz bir sır kopyasıdır
      duzelt "eski parola kopyası silindi: $ESKI_PGPASS" rm -f "$ESKI_PGPASS"
    fi
  elif [[ -f "$ESKI_PGPASS" ]]; then
    duzelt "parola dosyası taşındı: $ESKI_PGPASS → $PGPASS_DOSYASI" \
      bash -c 'install -o "$1" -g "$1" -m 600 "$2" "$3" && rm -f "$2"' \
        _ "$YEDEK_KULLANICI" "$ESKI_PGPASS" "$PGPASS_DOSYASI"
  elif [[ "$KIP" == denetle ]]; then
    eksik "parola dosyası yok: $PGPASS_DOSYASI"
  else
    YENI_PAROLA_GEREK=1
    bilgi "parola dosyası yok; bölüm 5'te yeni parola üretilecek"
  fi
  if [[ -f "$IMZA_ANAHTARI" ]]; then
    izin_denetle "$IMZA_ANAHTARI" 0600
  else
    bilgi "imza anahtarı henüz yok: $IMZA_ANAHTARI (BY-025'te uygulamadan taşınır; betik üretmez)"
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
#                   açılması ayrı bir karardır; betik açmaz.
#    Parola hiçbir komut satırına yazılmaz: psql'e standart girdiden verilir.
# ---------------------------------------------------------------------------
baslik "5. PostgreSQL rolleri"
rol_ozellikleri() {
  pg_yonetici -c "select concat_ws(' ', rolcanlogin, rolcreatedb, rolsuper, rolcreaterole) from pg_roles where rolname = '$1'"
}
pgpass_kacir() { local d="${1//\\/\\\\}"; printf '%s' "${d//:/\\:}"; }
yeni_parola_yaz() { # Parolayı role yazar; başarılıysa parola dosyasını yazar (geçici ad → taşı)
  local parola
  parola="$(openssl rand -hex 24)"
  printf "alter role %s password '%s';\n" "$VT_YEDEK_ROLU" "$parola" | pg_yonetici -f -
  GECICI="$(mktemp "$YEDEK_KOKU/gizli/.pgpass.XXXXXX")"
  printf '%s:%s:*:%s:%s\n' "$(pgpass_kacir "$VT_SUNUCU")" "$(pgpass_kacir "$VT_PORT")" \
    "$VT_YEDEK_ROLU" "$parola" > "$GECICI"
  chown "$YEDEK_KULLANICI:$YEDEK_KULLANICI" "$GECICI"
  chmod 600 "$GECICI"
  mv "$GECICI" "$PGPASS_DOSYASI"
  GECICI=""
}

OZ="$(rol_ozellikleri "$VT_YEDEK_ROLU")"
if [[ -z "$OZ" ]]; then
  if [[ "$KIP" == denetle ]]; then
    eksik "rol yok: $VT_YEDEK_ROLU"
  elif (( ! YENI_PAROLA_GEREK )); then
    hata "rol $VT_YEDEK_ROLU yok ama parola dosyası var ($PGPASS_DOSYASI). Hangisinin doğru olduğu belli değil; dosyayı inceleyip kaldırın ve yeniden çalıştırın."
  else
    pg_yonetici -c "create role $VT_YEDEK_ROLU login createdb nosuperuser nocreaterole"
    yapildi "rol açıldı: $VT_YEDEK_ROLU (LOGIN CREATEDB)"
  fi
elif [[ "$OZ" == "t t f f" ]]; then
  tamam "rol $VT_YEDEK_ROLU: giriş evet, veritabanı açma evet, yönetici hayır, rol açma hayır"
else
  duzelt "rol $VT_YEDEK_ROLU özellikleri ($OZ) → LOGIN CREATEDB NOSUPERUSER NOCREATEROLE" \
    pg_yonetici -c "alter role $VT_YEDEK_ROLU login createdb nosuperuser nocreaterole"
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
fi

OZ="$(rol_ozellikleri "$VT_INCELEME_ROLU")"
if [[ -z "$OZ" ]]; then
  duzelt "rol açıldı: $VT_INCELEME_ROLU (NOLOGIN, salt okunur inceleme)" \
    pg_yonetici -c "create role $VT_INCELEME_ROLU nologin nosuperuser nocreatedb nocreaterole"
elif [[ "$OZ" == "f f f f" ]]; then
  tamam "rol $VT_INCELEME_ROLU: giriş hayır, yetki yok"
else
  bilgi "rol $VT_INCELEME_ROLU özellikleri: $OZ (giriş elle açılmış olabilir; dokunulmadı)"
fi

# Servis hesabı, parola dosyasıyla gerçekten bağlanabiliyor mu
if [[ -f "$PGPASS_DOSYASI" ]] && (( KULLANICI_VAR )); then
  if runuser -u "$YEDEK_KULLANICI" -- env PGPASSFILE="$PGPASS_DOSYASI" PGCONNECT_TIMEOUT=10 \
       psql -h "$VT_SUNUCU" -p "$VT_PORT" -U "$VT_YEDEK_ROLU" -d postgres -X -A -t -q -w -c 'select 1' >/dev/null 2>&1; then
    tamam "$YEDEK_KULLANICI, parola dosyasıyla $VT_YEDEK_ROLU olarak postgres veritabanına bağlanabiliyor"
  else
    eksik "$YEDEK_KULLANICI, $VT_YEDEK_ROLU olarak bağlanamıyor (parola dosyası ile rol parolası uyuşmuyor ya da sunucu / port farklı)"
  fi
fi

# ---------------------------------------------------------------------------
# 6. Servis tanımı (README §9 madde 3)
#    Yedekleme çalıştırıcısı bu hesapla, arka planda sürekli çalışır.
#    UMask 0027: ürettiği dosyaları grup okur, başkalarına kapalı.
#    Çalıştırdığı komut BY-025'te yazılır: tanım şimdi KURULUR ama
#    etkinleştirilmez, başlatılmaz (komut adı değişirse betik tanımı günceller).
# ---------------------------------------------------------------------------
baslik "6. Servis tanımı"
SERVIS_ICERIGI="[Unit]
Description=emor yedekleme calistiricisi (docs/yedekleme)
After=network.target postgresql.service

[Service]
User=$YEDEK_KULLANICI
Group=$YEDEK_KULLANICI
UMask=0027
WorkingDirectory=$UYGULAMA_KOKU/backend
Environment=PGPASSFILE=$PGPASS_DOSYASI
Environment=EMOR_YEDEK_KOKU=$YEDEK_KOKU
ExecStart=/usr/bin/php $UYGULAMA_KOKU/backend/artisan yedek:calistir
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target"
if [[ -f "$SERVIS_DOSYASI" && "$(cat "$SERVIS_DOSYASI")" == "$SERVIS_ICERIGI" ]]; then
  tamam "$SERVIS_DOSYASI güncel"
else
  if [[ -f "$SERVIS_DOSYASI" ]]; then neden="içeriği farklı"; else neden="yok"; fi
  duzelt "$SERVIS_DOSYASI yazıldı ($neden; etkinleştirilmedi)" \
    bash -c 'printf "%s\n" "$1" > "$2" && chmod 644 "$2"' _ "$SERVIS_ICERIGI" "$SERVIS_DOSYASI"
  if [[ "$KIP" == kur ]] && (( GERCEK_SISTEM )); then
    systemctl daemon-reload
  fi
fi
if (( GERCEK_SISTEM )); then
  bilgi "$SERVIS_ADI etkin mi: $(systemctl is-enabled "$SERVIS_ADI" 2>/dev/null || true) (BY-025'e kadar 'disabled' beklenir)"
fi

# ---------------------------------------------------------------------------
# 7. Dar sudo kuralı (README §9 madde 1, §6.4 adım 2)
#    Üstüne geri yüklemede kuyruk işçisi durdurulur. Servis hesabı YALNIZ şu
#    üç komutu şifresiz çalıştırabilir; başka hiçbir şeyi.
#    Zamanlayıcı (cron, www-data) için sudo gerekmez: uygulama bakım
#    modundayken zamanlanmış işler çalışmaz (Laravel Scheduling\Event;
#    bakım modu dosya sürücüsüyle, veritabanına dayanmaz). Ayrıca işler açık
#    geri yüklemeyi durum dosyasından görür (BY-029).
# ---------------------------------------------------------------------------
baslik "7. Dar sudo kuralı"
SYSTEMCTL="$(command -v systemctl || echo /usr/bin/systemctl)"
SUDOERS_ICERIGI="# emor yedekleme (scripts/sunucu-yedekleme-kurulumu.sh) - yalniz kuyruk servisi
$YEDEK_KULLANICI ALL=(root) NOPASSWD: $SYSTEMCTL stop $KUYRUK_SERVISI, $SYSTEMCTL start $KUYRUK_SERVISI, $SYSTEMCTL is-active $KUYRUK_SERVISI"
if [[ -f "$SUDOERS_DOSYASI" && "$(cat "$SUDOERS_DOSYASI")" == "$SUDOERS_ICERIGI" \
      && "$(stat -c '%U:%G %a' "$SUDOERS_DOSYASI")" == "root:root 440" ]]; then
  tamam "$SUDOERS_DOSYASI güncel (root:root 440)"
elif [[ "$KIP" == denetle ]]; then
  eksik "$SUDOERS_DOSYASI yok ya da farklı"
else
  # Önce geçici dosyada doğrulanır: hatalı bir sudoers dosyası sudo'yu tümden bozar
  GECICI="$(mktemp)"
  printf '%s\n' "$SUDOERS_ICERIGI" > "$GECICI"
  visudo -cf "$GECICI" >/dev/null || hata "sudo kuralı visudo denetiminden geçmedi; dosya yazılmadı."
  install -o root -g root -m 440 "$GECICI" "$SUDOERS_DOSYASI"
  rm -f "$GECICI"
  GECICI=""
  yapildi "$SUDOERS_DOSYASI yazıldı (visudo ile doğrulandı)"
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
  - Servisi etkinleştirmek BY-025'in işidir: sudo systemctl enable --now $SERVIS_ADI
EOF
fi
(( SORUN == 0 ))
