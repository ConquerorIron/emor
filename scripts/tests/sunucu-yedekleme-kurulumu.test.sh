#!/usr/bin/env bash
#
# scripts/sunucu-yedekleme-kurulumu.sh testleri (BY-003).
#
# GERÇEK sistem değişikliği yapar: deneme adlarıyla bir Linux kullanıcısı / grubu
# (emor-yedek-deneme) ve iki PostgreSQL rolü (emor_yedek_deneme,
# emor_inceleme_deneme) açar, web kullanıcısını (www-data) deneme grubuna ekler;
# klasörler /tmp/emor-yedek-deneme altında. Başta ve sonda hepsini siler.
# Gerçek adlara (emor-yedek, emor_yedek, /var/lib/emor-yedek, /etc/...) dokunmaz.
#
# Gerekenler: root (lokal WSL gibi atılabilir bir ortam — sunucuda ÇALIŞTIRILMAZ),
# yerel PostgreSQL (postgres yönetici kullanıcısı, 127.0.0.1'de parola girişi),
# PostgreSQL'de .env'deki uygulama rolü, acl paketi, www-data kullanıcısı.
#
# Kullanım (proje kökünden):
#   sudo scripts/tests/sunucu-yedekleme-kurulumu.test.sh
# Yayın kullanıcısı yerine geçen gerçek bir kullanıcı gerekir: varsayılan sudo'yu
# çağıran kullanıcı (EMOR_DENEME_YAYIN_KULLANICI ile değiştirilebilir).
#
set -uo pipefail
PROJE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
B="$PROJE/scripts/sunucu-yedekleme-kurulumu.sh"
Y="${EMOR_DENEME_YAYIN_KULLANICI:-${SUDO_USER:-}}"
[[ -n "$Y" && "$Y" != root ]] || { echo "Yayın kullanıcısı yerine geçecek root olmayan bir kullanıcı gerekli (sudo ile çalıştırın ya da EMOR_DENEME_YAYIN_KULLANICI verin)."; exit 2; }
D=/tmp/emor-yedek-deneme
export EMOR_UYGULAMA_KOKU="$PROJE"
export EMOR_YEDEK_KOKU=$D/kok
export EMOR_YEDEK_KULLANICI=emor-yedek-deneme
export EMOR_WEB_KULLANICI=www-data
export EMOR_YAYIN_KULLANICI="$Y"
export EMOR_VT_YEDEK_ROLU=emor_yedek_deneme
export EMOR_VT_INCELEME_ROLU=emor_inceleme_deneme
export EMOR_ESKI_PGPASS=$D/eski_pgpass
export EMOR_SYSTEMD_DIZINI=$D/systemd
export EMOR_SUDOERS_DOSYASI=$D/sudoers-emor-yedek
export EMOR_KUYRUK_SERVISI=emor-queue-deneme
K=$EMOR_YEDEK_KOKU
GECTI=0; KALDI=0
ok()  { echo "  ✓ $*"; GECTI=$((GECTI+1)); }
bad() { echo "  ✗ $*"; KALDI=$((KALDI+1)); }
bekle() { local ad="$1"; shift; if "$@" >/dev/null; then ok "$ad"; else bad "$ad"; fi; }
calistir() { local ad="$1" beklenen_kod="$2"; shift 2; local cikti kod; cikti="$(bash "$B" "$@" 2>&1)"; kod=$?; echo "$cikti" > "$D/son.txt"; if [[ $kod == "$beklenen_kod" ]]; then ok "$ad (çıkış $kod)"; else bad "$ad (çıkış $kod, beklenen $beklenen_kod)"; echo "$cikti" | sed 's/^/      /'; fi; }
psqlp() { runuser -u postgres -- psql -X -A -t -q -c "$1"; }
temizle() {
  psqlp "drop role if exists $EMOR_VT_YEDEK_ROLU" >/dev/null 2>&1
  psqlp "drop role if exists $EMOR_VT_INCELEME_ROLU" >/dev/null 2>&1
  gpasswd -d www-data emor-yedek-deneme >/dev/null 2>&1
  id emor-yedek-deneme >/dev/null 2>&1 && userdel emor-yedek-deneme
  getent group emor-yedek-deneme >/dev/null && groupdel emor-yedek-deneme
  rm -rf "$D"
}
cd /
temizle
mkdir -p "$D/systemd"; chmod 755 "$D"
# Eşitleme (BY-005-R11) dışarıdan görünmez: sahte sync argümanları günlüğe
# yazar, sonra gerçeğini çalıştırır. Servis hesabı da yazabilsin diye 0666.
GERCEK_SYNC="$(command -v sync)"
mkdir -m 755 "$D/sahte"
printf '#!/bin/sh\nprintf "%%s\\n" "$*" >> %s/sync.gunluk\nexec %s "$@"\n' "$D" "$GERCEK_SYNC" > "$D/sahte/sync"
chmod 755 "$D/sahte/sync"
SAHTE_PATH="$D/sahte:$PATH"
esitlendi() { grep -qxF -- "-- $1" "$D/sync.gunluk"; } # $1 "yol1 yol2"
sync_sifirla() { install -m 666 /dev/null "$D/sync.gunluk"; }

echo "== A. İlk durum: --denetle eksik raporlar, hiçbir şey değiştirmez"
sync_sifirla
PATH="$SAHTE_PATH" calistir "ilk denetim eksik" 1 --denetle
bekle "denetim eşitleme yapmadı" test ! -s "$D/sync.gunluk"
bekle "denetim kullanıcı açmadı" bash -c '! id emor-yedek-deneme >/dev/null 2>&1'
bekle "denetim klasör açmadı" test ! -e "$K"
bekle "denetim rol açmadı" bash -c "[[ -z \$(runuser -u postgres -- psql -X -A -t -c \"select 1 from pg_roles where rolname='$EMOR_VT_YEDEK_ROLU'\") ]]"
grep -c "\[EKSİK\]" "$D/son.txt" | sed 's/^/    eksik madde sayısı: /'

echo "== B. Kurulum"
sync_sifirla
PATH="$SAHTE_PATH" calistir "kurulum" 0
grep -E "KURULUM:" "$D/son.txt" | sed 's/^/    /'
# Uygulama eşitlemeyi kalıcı kökte (durum/) durdurur; üstünü kurulum zorlar (BY-005-R11)
bekle "kök ve üstü eşitlendi" esitlendi "$(dirname "$K") $K"
bekle "durum klasörü ve kök eşitlendi" esitlendi "$K $K/durum"
bekle "kilit dosyası ve klasörü eşitlendi" esitlendi "$K/durum/kilit $K/durum"
echo "== C. İkinci kurulum: değişiklik yok"
sync_sifirla
PATH="$SAHTE_PATH" calistir "ikinci kurulum" 0
bekle "ikinci kurulumda 0 değişiklik" grep -q "KURULUM: 0 değişiklik" "$D/son.txt"
bekle "ikinci kurulumda YAPILDI yok" bash -c "! grep -q YAPILDI '$D/son.txt'"
bekle "var olan klasörler de yeniden eşitlendi (D3)" esitlendi "$K $K/durum"
echo "== D. Denetim temiz"
calistir "denetim temiz" 0 --denetle
cp "$D/son.txt" "$D/denetim_temiz.txt"

echo "== E. İzinler (§5.5)"
bekle "kök root:servis 0750" bash -c "[[ \$(stat -c '%U:%G %a' $K) == 'root:emor-yedek-deneme 750' ]]"
bekle "gizli 0700"      bash -c "[[ \$(stat -c '%a' $K/gizli) == 700 ]]"
bekle "pgpass 0600"     bash -c "[[ \$(stat -c '%U %a' $K/gizli/pgpass) == 'emor-yedek-deneme 600' ]]"
bekle "pgpass'ta deneme rolü" grep -q ":emor_yedek_deneme:" "$K/gizli/pgpass"
bekle "servis bağlanıyor" runuser -u emor-yedek-deneme -- env PGPASSFILE=$K/gizli/pgpass psql -h 127.0.0.1 -U emor_yedek_deneme -d postgres -X -A -t -w -c 'select 1'
bekle "servis erp üyeliğiyle veritabanı açıp silebiliyor" runuser -u emor-yedek-deneme -- env PGPASSFILE=$K/gizli/pgpass psql -h 127.0.0.1 -U emor_yedek_deneme -d postgres -X -q -w -v ON_ERROR_STOP=1 -c 'create database erp_by003_deneme owner erp' -c 'drop database erp_by003_deneme'
# web (www-data)
bekle "web yedekleri listeler"     runuser -u www-data -- ls $K/yedekler
bekle "web yedeklere yazamaz"      bash -c "! runuser -u www-data -- touch $K/yedekler/x 2>/dev/null"
bekle "web istek bırakır"          runuser -u www-data -- touch $K/istekler/bekleyen/web.istek
bekle "web istekler kökünü değiştiremez (D5)" bash -c "! runuser -u www-data -- mkdir $K/istekler/x 2>/dev/null"
bekle "web sonuç yazamaz"          bash -c "! runuser -u www-data -- touch $K/istekler/sonuc/x 2>/dev/null"
bekle "web sonuç okur"             runuser -u www-data -- ls $K/istekler/sonuc
bekle "web gizli'ye giremez"       bash -c "! runuser -u www-data -- ls $K/gizli 2>/dev/null"
bekle "web kilidi alır"            runuser -u www-data -- flock -n $K/durum/kilit true
bekle "web durum'a yazamaz"        bash -c "! runuser -u www-data -- touch $K/durum/x 2>/dev/null"
bekle "web günlükleri okur"        runuser -u www-data -- ls $K/gunluk
# yayın ($Y)
bekle "yayın yedeklere giremez"    bash -c "! runuser -u $Y -- ls $K/yedekler 2>/dev/null"
bekle "yayın günlüklere giremez"   bash -c "! runuser -u $Y -- ls $K/gunluk 2>/dev/null"
bekle "yayın gizli'ye giremez"     bash -c "! runuser -u $Y -- ls $K/gizli 2>/dev/null"
bekle "yayın kökü listeleyemez"    bash -c "! runuser -u $Y -- ls $K 2>/dev/null"
bekle "yayın istek bırakır"        runuser -u $Y -- touch $K/istekler/bekleyen/yayin.istek
bekle "yayın sonuçları okur"       runuser -u $Y -- ls $K/istekler/sonuc
bekle "yayın sonuç yazamaz"        bash -c "! runuser -u $Y -- touch $K/istekler/sonuc/x 2>/dev/null"
bekle "yayın işlenenlere giremez"  bash -c "! runuser -u $Y -- ls $K/istekler/isleniyor 2>/dev/null"
bekle "yayın istekler kökünü listeleyemez" bash -c "! runuser -u $Y -- ls $K/istekler 2>/dev/null"
bekle "yayın kilidi alır"          runuser -u $Y -- flock -n $K/durum/kilit true
bekle "yayın durum'a yazamaz"      bash -c "! runuser -u $Y -- touch $K/durum/x 2>/dev/null"
runuser -u emor-yedek-deneme -- bash -c "umask 0027; echo '{}' > $K/durum/islem.json"
bekle "yayın, servisin sonradan açtığı durum dosyasını okur" runuser -u $Y -- cat $K/durum/islem.json
bekle "web, servisin durum dosyasını okur" runuser -u www-data -- cat $K/durum/islem.json
bekle "durum dosyası başkalarına kapalı" bash -c "[[ \$(stat -c '%a' $K/durum/islem.json) == 640 ]]"
bekle "servis yayın isteğini okur"  runuser -u emor-yedek-deneme -- cat $K/istekler/bekleyen/yayin.istek
bekle "servis isteği işleniyor/e taşır" runuser -u emor-yedek-deneme -- mv $K/istekler/bekleyen/yayin.istek $K/istekler/isleniyor/yayin.istek
bekle "servis istekleri siler"      runuser -u emor-yedek-deneme -- rm $K/istekler/isleniyor/yayin.istek $K/istekler/bekleyen/web.istek
rm -f $K/durum/islem.json
bekle "sudoers visudo'dan geçer"    visudo -cf "$EMOR_SUDOERS_DOSYASI"
bekle "sudoers root:root 440"       bash -c "[[ \$(stat -c '%U:%G %a' $EMOR_SUDOERS_DOSYASI) == 'root:root 440' ]]"
bekle "servis tanımı yazıldı"       grep -q "^User=emor-yedek-deneme$" "$EMOR_SYSTEMD_DIZINI/emor-yedek.service"
bekle "servis uzun geri yüklemeyi bekler"  grep -qx "TimeoutStopSec=4000" "$EMOR_SYSTEMD_DIZINI/emor-yedek.service"
bekle "servis kuyruk servisini sudoers ile aynı adla yönetir" bash -c "grep -qx \"Environment=YEDEKLEME_KUYRUK_SERVISI=$EMOR_KUYRUK_SERVISI\" \"$EMOR_SYSTEMD_DIZINI/emor-yedek.service\" && grep -q \"systemctl stop $EMOR_KUYRUK_SERVISI\" \"$EMOR_SUDOERS_DOSYASI\""
bekle "kullanıcı giriş yapamaz"     bash -c "[[ \$(getent passwd emor-yedek-deneme | cut -d: -f7) == /usr/sbin/nologin ]]"
bekle "yayın gruba alınmadı"        bash -c "! id -nG $Y | tr ' ' '\n' | grep -qx emor-yedek-deneme"
bekle "inceleme rolü NOLOGIN"       bash -c "[[ \$(runuser -u postgres -- psql -X -A -t -c \"select rolcanlogin from pg_roles where rolname='emor_inceleme_deneme'\") == f ]]"
bekle "onbellek servisin, 0700"     bash -c "[[ \$(stat -c '%U %a' $K/onbellek) == 'emor-yedek-deneme 700' ]]"
bekle "web onbellek'e giremez"      bash -c "! runuser -u www-data -- ls $K/onbellek 2>/dev/null"

echo "== E2. Servis, web kullanıcısının yazabildiği PHP dosyalarını yüklemez (Fable incelemesi F1)"
# Uygulama servis tanımındaki ortamla açılır; yüklenen dosyalar arasında
# uygulamanın bootstrap/cache'i ve storage'ı (web yazabilir) olmamalı.
SERVIS_ORTAMI=()
while IFS= read -r satir; do SERVIS_ORTAMI+=("${satir#Environment=}"); done < <(grep '^Environment=' "$EMOR_SYSTEMD_DIZINI/emor-yedek.service")
YUKLENEN="$(cd "$PROJE/backend" && env "${SERVIS_ORTAMI[@]}" php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  foreach (config("logging.channels") as $kanal) { if (isset($kanal["path"])) { echo "gunluk:", $kanal["path"], "\n"; } }
  echo "gorunum:", config("view.compiled"), "\n", implode("\n", get_included_files()), "\n";' 2>&1)"
bekle "uygulama servisin ortamıyla açılıyor"      grep -qx "$PROJE/backend/vendor/autoload.php" <<<"$YUKLENEN"
bekle "bootstrap/cache'ten PHP yüklenmedi"         bash -c "! grep -q '^$PROJE/backend/bootstrap/cache/' <<<\"\$1\"" _ "$YUKLENEN"
bekle "storage'dan PHP yüklenmedi"                 bash -c "! grep -q '^$PROJE/backend/storage/' <<<\"\$1\"" _ "$YUKLENEN"
bekle "derlenmiş görünümler servisin klasöründe"   grep -qx "gorunum:$K/onbellek/views" <<<"$YUKLENEN"
bekle "paket önbelleği servisin klasöründe üretildi" test -f "$K/onbellek/packages.php"
# BY-003-R6: acil durum ve adıyla çağrılan kanallar dahil hiçbir günlük web'in yazabildiği yerde değil
bekle "günlük kanalları listelendi"                grep -q "^gunluk:" <<<"$YUKLENEN"
bekle "bütün günlük kanalları servisin gunluk/ klasöründe" bash -c "! grep '^gunluk:' <<<\"\$1\" | grep -vq '^gunluk:$K/gunluk/'" _ "$YUKLENEN"

echo "== F. Bozulan durumlar: denetim yakalar, kurulum düzeltir"
chmod 755 $K/yedekler; chmod 644 $K/gizli/pgpass; chmod 2750 $K; chown emor-yedek-deneme $K
psqlp "revoke erp from emor_yedek_deneme" >/dev/null; psqlp "grant erp to emor_yedek_deneme with inherit false, set true, admin true" >/dev/null
setfacl -x u:$Y $K/istekler/bekleyen
rm -f "$EMOR_SYSTEMD_DIZINI/emor-yedek.service"; echo bozuk > "$EMOR_SUDOERS_DOSYASI"
calistir "bozuk durumda denetim eksik" 1 --denetle
for ifade in "kok: .*2750 →" "yedekler: .*755 →" "pgpass: .* 600" "üyelik seçenekleri (f t t)" "bekleyen ACL farklı — eksik: user:$Y:rwx" "emor-yedek.service" "sudoers-emor-yedek yok ya da farklı"; do
  bekle "denetim yakaladı: $ifade" grep -q -- "$ifade" "$D/son.txt"
done
calistir "kurulum düzeltir" 0
calistir "ardından denetim temiz" 0 --denetle

echo "== F2. Fazla yetkiler: denetim yakalar ve dokunmaz, kurulum geri alır (BY-003-R2…R4)"
SERVIS_DOSYASI="$EMOR_SYSTEMD_DIZINI/emor-yedek.service"
rolde() { runuser -u postgres -- psql -X -A -t -c "select $2 from pg_roles where rolname='$1'"; }
export -f rolde
setfacl -m u:$Y:r-x $K/yedekler
setfacl -m d:u:$Y:r-x $K/gunluk
setfacl -m u:www-data:r-- $K/gizli/pgpass
bekle "ön koşul: ek ACL ile yayın yedekleri listeleyebiliyor" runuser -u $Y -- ls $K/yedekler
bekle "ön koşul: yedekler izni hâlâ 2750 görünüyor" bash -c "[[ \$(stat -c %a $K/yedekler) == 2750 ]]"
chmod 666 "$SERVIS_DOSYASI"
psqlp "alter role emor_inceleme_deneme superuser" >/dev/null
psqlp "alter role emor_yedek_deneme replication" >/dev/null
calistir "fazla yetkide denetim eksik" 1 --denetle
for ifade in "yedekler ACL farklı — fazla: .*user:$Y:r-x" "gunluk ACL farklı — fazla: default:" "pgpass ACL farklı — fazla: .*user:www-data:r--" \
             "emor-yedek.service .*666" "emor_inceleme_deneme salt okunur değil: yönetici" "emor_yedek_deneme özellikleri"; do
  bekle "denetim yakaladı: $ifade" grep -q -- "$ifade" "$D/son.txt"
done
bekle "denetim inceleme rolüne dokunmadı" bash -c "[[ \$(rolde emor_inceleme_deneme rolsuper) == t ]]"
bekle "denetim ACL'ye dokunmadı" bash -c "getfacl -cp $K/yedekler | grep -qx 'user:$Y:r-x'"
bekle "denetim servis dosyasına dokunmadı" bash -c "[[ \$(stat -c %a $SERVIS_DOSYASI) == 666 ]]"
calistir "kurulum fazla yetkileri geri alır" 0
bekle "yayın artık yedekleri listeleyemez" bash -c "! runuser -u $Y -- ls $K/yedekler 2>/dev/null"
bekle "web hâlâ yedekleri listeler"         runuser -u www-data -- ls $K/yedekler
bekle "web pgpass'ı okuyamaz"               bash -c "! getfacl -cp $K/gizli/pgpass | grep -q www-data"
bekle "günlük klasöründe varsayılan ACL yok" bash -c "! getfacl -cp $K/gunluk | grep -q '^default:'"
bekle "yayın hâlâ istek bırakır"            runuser -u $Y -- touch $K/istekler/bekleyen/yayin.istek
bekle "yayın hâlâ kilidi alır"              runuser -u $Y -- flock -n $K/durum/kilit true
rm -f $K/istekler/bekleyen/yayin.istek
bekle "servis dosyası root:root 644"        bash -c "[[ \$(stat -c '%U:%G %a' $SERVIS_DOSYASI) == 'root:root 644' ]]"
bekle "inceleme rolü yönetici değil"        bash -c "[[ \$(rolde emor_inceleme_deneme rolsuper) == f ]]"
bekle "yedek rolü çoğaltma yetkisiz"        bash -c "[[ \$(rolde emor_yedek_deneme rolreplication) == f ]]"
calistir "ardından kurulum" 0
bekle "ardından kurulumda 0 değişiklik" grep -q "KURULUM: 0 değişiklik" "$D/son.txt"
calistir "ardından denetim temiz" 0 --denetle
for oz in createdb createrole replication bypassrls; do
  psqlp "alter role emor_inceleme_deneme $oz" >/dev/null
  calistir "inceleme rolünde $oz: denetim eksik" 1 --denetle
  psqlp "alter role emor_inceleme_deneme no$oz" >/dev/null
done
psqlp "alter role emor_inceleme_deneme login" >/dev/null
calistir "inceleme rolünde yalnız giriş açık: denetim temiz" 0 --denetle
bekle "giriş bilgi olarak bildirildi" grep -q "giriş açık" "$D/son.txt"
psqlp "alter role emor_inceleme_deneme nologin" >/dev/null
psqlp "grant erp to emor_inceleme_deneme" >/dev/null
psqlp "grant pg_write_all_data to emor_yedek_deneme" >/dev/null
calistir "fazla üyelikte denetim eksik" 1 --denetle
bekle "denetim yakaladı: inceleme rolünün üyeliği" grep -q "emor_inceleme_deneme başka rollere üye: erp" "$D/son.txt"
bekle "denetim yakaladı: yedek rolünün fazla üyeliği" grep -q "emor_yedek_deneme başka rollere üye: pg_write_all_data" "$D/son.txt"
calistir "fazla üyelikte kurulum geri almaz, elle bakılacak der" 1
bekle "üyelik duruyor" bash -c "[[ -n \$(runuser -u postgres -- psql -X -A -t -c \"select 1 from pg_auth_members m join pg_roles r on r.oid=m.roleid join pg_roles u on u.oid=m.member where r.rolname='erp' and u.rolname='emor_inceleme_deneme'\") ]]"
psqlp "revoke erp from emor_inceleme_deneme" >/dev/null
psqlp "revoke pg_write_all_data from emor_yedek_deneme" >/dev/null
chown nobody "$SERVIS_DOSYASI"
calistir "servis dosyasının sahibi farklı: denetim eksik" 1 --denetle
calistir "kurulum sahibi düzeltir" 0
bekle "servis dosyası yine root:root 644" bash -c "[[ \$(stat -c '%U:%G %a' $SERVIS_DOSYASI) == 'root:root 644' ]]"
calistir "denetim temiz" 0 --denetle

echo "== F3. Bağlantılar: kurulum yönetilen alanın dışındaki nesneye dokunmaz (BY-003-R5)"
KD=$D/korunan_dosya; KZ=$D/korunan_dizin
printf 'korunan-icerik\n' > $KD; chmod 600 $KD; mkdir -p $KZ; chmod 700 $KZ
yalniz_temel_acl() { [[ -z "$(getfacl -cpE "$1" | grep -v -e '^user::' -e '^group::' -e '^other::' -e '^$')" ]]; }
korunan_ayni() {
  [[ "$(stat -c '%U:%G %a' $KD)" == "root:root 600" && "$(cat $KD)" == korunan-icerik \
     && "$(stat -c '%U:%G %a' $KZ)" == "root:root 700" && -z "$(ls -A $KZ)" ]] \
    && yalniz_temel_acl $KD && yalniz_temel_acl $KZ
}
servis_erisemez() { ! runuser -u emor-yedek-deneme -- cat $KD 2>/dev/null && ! runuser -u emor-yedek-deneme -- touch $KZ/x 2>/dev/null; }
bekle "ön koşul: servis korunan nesnelere erişemez" servis_erisemez
bekle "servis yönetilen alt klasörü yerinden oynatamaz" bash -c "! runuser -u emor-yedek-deneme -- mv $K/yedekler $K/yedekler.x 2>/dev/null"
bekle "servis kök klasöre bağlantı koyamaz"   bash -c "! runuser -u emor-yedek-deneme -- ln -s $KZ $K/yeni 2>/dev/null"
cp -p $K/gizli/pgpass $D/pgpass.yedek
for yaprak in gizli/pgpass gizli/imza.anahtar durum/kilit; do
  # Servis hesabı, yazabildiği klasörde dosyayı korunan dosyaya bağlantıyla değiştirir
  runuser -u emor-yedek-deneme -- bash -c "rm -f $K/$yaprak && ln -s $KD $K/$yaprak"
  calistir "$yaprak bağlantı: denetim eksik" 1 --denetle
  bekle "$yaprak: denetim bağlantıyı söyledi" grep -q "$yaprak sembolik bağlantı" "$D/son.txt"
  calistir "$yaprak bağlantı: kurulum durur" 1
  bekle "$yaprak: kurulum bağlantıyı söyledi" grep -q "$yaprak sembolik bağlantı" "$D/son.txt"
  bekle "$yaprak: korunan nesneler değişmedi" korunan_ayni
  bekle "$yaprak: servis korunan nesnelere hâlâ erişemez" servis_erisemez
  rm -f $K/$yaprak
done
cp -p $D/pgpass.yedek $K/gizli/pgpass
# Sabit bağlantı (servis bunu fs.protected_hardlinks yüzünden yapamaz; eski / elle bozulmuş durum)
ln $KD $K/durum/kilit
calistir "kilit sabit bağlantı: kurulum durur" 1
bekle "sabit bağlantı söylendi" grep -q "kilit birden çok adı var" "$D/son.txt"
bekle "sabit bağlantıda korunan nesneler değişmedi" korunan_ayni
rm -f $K/durum/kilit
# Alt klasör yerine bağlantı (servis artık koyamaz; eski kurulumdan kalmış olabilir)
mv $K/yedekler $D/yedekler.asil; ln -s $KZ $K/yedekler
calistir "yedekler bağlantı: denetim eksik" 1 --denetle
calistir "yedekler bağlantı: kurulum durur" 1
bekle "klasör bağlantısı söylendi" grep -q "yedekler sembolik bağlantı" "$D/son.txt"
bekle "klasör bağlantısında korunan nesneler değişmedi" korunan_ayni
bekle "klasör bağlantısında servis korunan nesnelere erişemez" servis_erisemez
rm $K/yedekler; mv $D/yedekler.asil $K/yedekler
# Üst klasörü başkaları yazabiliyorsa (yapışkan bit yok) yol kurulum sırasında değiştirilebilir
chmod 777 $D
calistir "üst klasör herkese yazılabilir: kurulum durur" 1
bekle "üst klasör söylendi" grep -q "$D: .*yazabiliyor" "$D/son.txt"
chmod 755 $D
calistir "geri konunca kurulum" 0
calistir "geri konunca denetim temiz" 0 --denetle
rm -f $D/pgpass.yedek

echo "== G. Eski yerdeki parola dosyası: yeni yer doğrulanmadan silinmez (BY-003-R1)"
P=$K/gizli/pgpass; E="$EMOR_ESKI_PGPASS"
eskiyle_baglan() { runuser -u $Y -- env PGPASSFILE="$E" psql -h 127.0.0.1 -U emor_yedek_deneme -d postgres -X -A -t -w -c 'select 1'; }
# G1: yalnız eski yerde, geçerli → taşınır, eski silinir
cp -p $P "$E"; chown $Y "$E"; rm $P
calistir "denetim: parola dosyası yok" 1 --denetle
calistir "kurulum taşır" 0
bekle "taşındı mesajı" grep -q "parola dosyası kopyalandı" "$D/son.txt"
bekle "eski kopya silindi" test ! -e "$E"
bekle "taşınan dosya servisin, 600" bash -c "[[ \$(stat -c '%U %a' $P) == 'emor-yedek-deneme 600' ]]"
bekle "geçici dosya kalmadı" bash -c "[[ -z \$(ls -A $K/gizli | grep -v -x -e pgpass) ]]"
bekle "parola yenilenmedi (taşınan dosyayla bağlanıyor)" grep -q "postgres veritabanına bağlanabiliyor" "$D/son.txt"
calistir "denetim temiz" 0 --denetle
# G2: ikisi de var, aynı, geçerli → denetim söyler; kurulum eskiyi siler
cp -p $P "$E"; chown $Y "$E"
calistir "denetim: eski kopya duruyor" 1 --denetle
bekle "denetim eski kopyayı silmedi" test -e "$E"
calistir "kurulum eski kopyayı siler" 0
bekle "eski kopya silindi (aynı ve geçerli)" test ! -e "$E"
# G3: eski geçerli, hedef bozuk → hiçbiri silinmez
cp -p $P "$E"; chown $Y "$E"; GECERLI="$(cat "$E")"; GECERLI_PAROLA="$(cut -d: -f5 "$E")"
BOZUK="$(sed 's/:[^:]*$/:bozukparola/' $P)"; printf '%s\n' "$BOZUK" > $P
calistir "eski geçerli / hedef bozuk: kurulum elle bakılacak der" 1
bekle "iki dosyanın farklı olduğu söylendi" grep -q "iki parola dosyası farklı" "$D/son.txt"
bekle "eski dosya korundu, içeriği aynı" bash -c "[[ \"\$(cat '$E')\" == '$GECERLI' ]]"
bekle "eski dosyayla hâlâ bağlanılıyor" eskiyle_baglan
bekle "parola çıktıda yok" bash -c "! grep -qF '$GECERLI_PAROLA' '$D/son.txt'"
# G4: yarım kopya (hedef eskinin kesik hâli) → hiçbiri silinmez
head -c 20 "$E" > $P
calistir "yarım kopya: kurulum elle bakılacak der" 1
bekle "yarım kopyada eski dosya korundu" bash -c "[[ \"\$(cat '$E')\" == '$GECERLI' ]]"
# G5: ikisi aynı ama rolün parolası başka → eski silinmez
cat "$E" > $P
psqlp "alter role emor_yedek_deneme password 'baska-bir-parola'" >/dev/null
calistir "aynı içerik ama bağlanamıyor: kurulum elle bakılacak der" 1
bekle "bağlanamayınca eski korundu" test -e "$E"
bekle "korunduğu söylendi" grep -q "eski parola kopyası korunuyor" "$D/son.txt"
# G5b: aynısı, ama kabukta rolün GERÇEK parolası PGPASSWORD olarak duruyor →
# bağlantı denetimi dosyayı sınamalı, ortam parolasıyla geçmemeli (BY-001-R6)
export PGPASSWORD='baska-bir-parola'
calistir "geçersiz dosya + doğru PGPASSWORD: kurulum elle bakılacak der" 1
unset PGPASSWORD
bekle "ortam parolası geçersiz dosyayı geçerli göstermedi" grep -q "olarak bağlanamıyor" "$D/son.txt"
bekle "ortam parolasıyla da eski korundu" test -e "$E"
# G6: yalnız eski yerde, geçersiz → kopyalanır ama eski silinmez
rm $P
calistir "geçersiz eski dosya: kurulum elle bakılacak der" 1
bekle "yeni yere kopyalandı" test -f $P
bekle "geçersiz eski dosya silinmedi" test -e "$E"
rm -f "$E"

echo "== H. Parola dosyası hiçbir yerde yoksa parola yenilenir (G6'daki geçersiz dosya da gider)"
ESKI_SATIR="$(cat $K/gizli/pgpass)"; rm $K/gizli/pgpass
# Yönetici oturumlarının bütün komutları günlüğe yazılır: düz parola sunucu
# günlüğüne düşmemeli (D2, Fable incelemesi F3). Ayar yalnız bu çalıştırmanın
# oturumlarına verilir (PGOPTIONS; betiğin servis bağlantı denetimi temiz
# ortamda çalışır) — sunucunun ya da postgres rolünün ayarı değişmez (BY-003-R7, K7).
PG_GUNLUGU="$(ls -t /var/log/postgresql/postgresql-*.log 2>/dev/null | head -n1)"
GUNLUK_BOYU="$(stat -c %s "$PG_GUNLUGU" 2>/dev/null || echo 0)"
ROL_AYARI_ONCE="$(psqlp "select coalesce(string_agg(array_to_string(setconfig, ','), ';'), '') from pg_db_role_setting s join pg_roles r on r.oid = s.setrole where r.rolname = 'postgres'")"
export PGOPTIONS='-c log_statement=all'
calistir "kurulum yeni parola" 0
unset PGOPTIONS
bekle "postgres rolünün ayarı testten önceki gibi" bash -c "[[ \"\$1\" == \"\$(runuser -u postgres -- psql -X -A -t -q -c \"select coalesce(string_agg(array_to_string(setconfig, ','), ';'), '') from pg_db_role_setting s join pg_roles r on r.oid = s.setrole where r.rolname = 'postgres'\")\" ]]" _ "$ROL_AYARI_ONCE"
tail -c +"$((GUNLUK_BOYU + 1))" "$PG_GUNLUGU" > "$D/pg_gunlugu.txt" 2>/dev/null
bekle "deneme günlüğe yazdı (rol parolası komutu görünüyor)" grep -q "alter role emor_yedek_deneme password" "$D/pg_gunlugu.txt"
bekle "role SCRAM doğrulayıcısı gönderildi" grep -qF "password 'SCRAM-SHA-256\$4096:" "$D/pg_gunlugu.txt"
bekle "düz parola PostgreSQL günlüğüne düşmedi" bash -c "! grep -qF \"\$(cut -d: -f5 $K/gizli/pgpass)\" '$D/pg_gunlugu.txt'"
bekle "yenilendi mesajı" grep -q "yeni parola role yazıldı" "$D/son.txt"
bekle "parola değişti" bash -c "[[ \"\$(cat $K/gizli/pgpass)\" != '$ESKI_SATIR' ]]"
bekle "geçici dosya kalmadı" bash -c "[[ -z \$(ls -A $K/gizli | grep -v -x -e pgpass) ]]"
bekle "yeni parolayla bağlanıyor" grep -q "postgres veritabanına bağlanabiliyor" "$D/son.txt"
bekle "parola çıktıda yok" bash -c "! grep -qF \"\$(cut -d: -f5 $K/gizli/pgpass)\" '$D/son.txt'"

echo "== I. Rol yok ama parola dosyası var: kurulum durur, bir şey açmaz"
psqlp "drop role emor_yedek_deneme" >/dev/null
calistir "belirsiz durumda hata" 1
bekle "hata nedeni anlaşılır" grep -q "Hangisinin doğru olduğu belli değil" "$D/son.txt"
bekle "rol açılmadı" bash -c "[[ -z \$(runuser -u postgres -- psql -X -A -t -c \"select 1 from pg_roles where rolname='emor_yedek_deneme'\") ]]"

echo "== J. Kullanım hatası"
calistir "bilinmeyen seçenek" 2 --boyle-bir-sey-yok

echo; echo "Son temiz denetim çıktısı:"; sed 's/^/    /' "$D/denetim_temiz.txt"
temizle
echo; echo "Temizlik: kullanıcı $(id emor-yedek-deneme >/dev/null 2>&1 && echo DURUYOR || echo silindi), grup: $(getent group emor-yedek-deneme >/dev/null && echo DURUYOR || echo silindi), www-data grupları: $(id -nG www-data), roller: $(psqlp "select count(*) from pg_roles where rolname like '%_deneme'"), klasör: $(test -e $D && echo DURUYOR || echo silindi)"
echo "SONUÇ: $GECTI geçti, $KALDI kaldı"
(( KALDI == 0 ))
