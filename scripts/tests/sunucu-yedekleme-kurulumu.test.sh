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

echo "== A. İlk durum: --denetle eksik raporlar, hiçbir şey değiştirmez"
calistir "ilk denetim eksik" 1 --denetle
bekle "denetim kullanıcı açmadı" bash -c '! id emor-yedek-deneme >/dev/null 2>&1'
bekle "denetim klasör açmadı" test ! -e "$K"
bekle "denetim rol açmadı" bash -c "[[ -z \$(runuser -u postgres -- psql -X -A -t -c \"select 1 from pg_roles where rolname='$EMOR_VT_YEDEK_ROLU'\") ]]"
grep -c "\[EKSİK\]" "$D/son.txt" | sed 's/^/    eksik madde sayısı: /'

echo "== B. Kurulum"
calistir "kurulum" 0
grep -E "KURULUM:" "$D/son.txt" | sed 's/^/    /'
echo "== C. İkinci kurulum: değişiklik yok"
calistir "ikinci kurulum" 0
bekle "ikinci kurulumda 0 değişiklik" grep -q "KURULUM: 0 değişiklik" "$D/son.txt"
bekle "ikinci kurulumda YAPILDI yok" bash -c "! grep -q YAPILDI '$D/son.txt'"
echo "== D. Denetim temiz"
calistir "denetim temiz" 0 --denetle
cp "$D/son.txt" "$D/denetim_temiz.txt"

echo "== E. İzinler (§5.5)"
bekle "kök 0750"        bash -c "[[ \$(stat -c '%U:%G %a' $K) == 'emor-yedek-deneme:emor-yedek-deneme 750' ]]"
bekle "gizli 0700"      bash -c "[[ \$(stat -c '%a' $K/gizli) == 700 ]]"
bekle "pgpass 0600"     bash -c "[[ \$(stat -c '%U %a' $K/gizli/pgpass) == 'emor-yedek-deneme 600' ]]"
bekle "pgpass'ta deneme rolü" grep -q ":emor_yedek_deneme:" "$K/gizli/pgpass"
bekle "servis bağlanıyor" runuser -u emor-yedek-deneme -- env PGPASSFILE=$K/gizli/pgpass psql -h 127.0.0.1 -U emor_yedek_deneme -d postgres -X -A -t -w -c 'select 1'
bekle "servis erp üyeliğiyle veritabanı açıp silebiliyor" runuser -u emor-yedek-deneme -- env PGPASSFILE=$K/gizli/pgpass psql -h 127.0.0.1 -U emor_yedek_deneme -d postgres -X -q -w -v ON_ERROR_STOP=1 -c 'create database erp_by003_deneme owner erp' -c 'drop database erp_by003_deneme'
# web (www-data)
bekle "web yedekleri listeler"     runuser -u www-data -- ls $K/yedekler
bekle "web yedeklere yazamaz"      bash -c "! runuser -u www-data -- touch $K/yedekler/x 2>/dev/null"
bekle "web istek bırakır"          runuser -u www-data -- touch $K/istekler/web.istek
bekle "web gizli'ye giremez"       bash -c "! runuser -u www-data -- ls $K/gizli 2>/dev/null"
bekle "web kilidi alır"            runuser -u www-data -- flock -n $K/durum/kilit true
bekle "web durum'a yazamaz"        bash -c "! runuser -u www-data -- touch $K/durum/x 2>/dev/null"
bekle "web günlükleri okur"        runuser -u www-data -- ls $K/gunluk
# yayın ($Y)
bekle "yayın yedeklere giremez"    bash -c "! runuser -u $Y -- ls $K/yedekler 2>/dev/null"
bekle "yayın günlüklere giremez"   bash -c "! runuser -u $Y -- ls $K/gunluk 2>/dev/null"
bekle "yayın gizli'ye giremez"     bash -c "! runuser -u $Y -- ls $K/gizli 2>/dev/null"
bekle "yayın kökü listeleyemez"    bash -c "! runuser -u $Y -- ls $K 2>/dev/null"
bekle "yayın istek bırakır"        runuser -u $Y -- touch $K/istekler/yayin.istek
bekle "yayın kilidi alır"          runuser -u $Y -- flock -n $K/durum/kilit true
bekle "yayın durum'a yazamaz"      bash -c "! runuser -u $Y -- touch $K/durum/x 2>/dev/null"
runuser -u emor-yedek-deneme -- bash -c "umask 0027; echo '{}' > $K/durum/islem.json"
bekle "yayın, servisin sonradan açtığı durum dosyasını okur" runuser -u $Y -- cat $K/durum/islem.json
bekle "web, servisin durum dosyasını okur" runuser -u www-data -- cat $K/durum/islem.json
bekle "durum dosyası başkalarına kapalı" bash -c "[[ \$(stat -c '%a' $K/durum/islem.json) == 640 ]]"
bekle "servis yayın isteğini okur"  runuser -u emor-yedek-deneme -- cat $K/istekler/yayin.istek
bekle "servis istekleri siler"      runuser -u emor-yedek-deneme -- rm $K/istekler/yayin.istek $K/istekler/web.istek
rm -f $K/durum/islem.json
bekle "sudoers visudo'dan geçer"    visudo -cf "$EMOR_SUDOERS_DOSYASI"
bekle "sudoers root:root 440"       bash -c "[[ \$(stat -c '%U:%G %a' $EMOR_SUDOERS_DOSYASI) == 'root:root 440' ]]"
bekle "servis tanımı yazıldı"       grep -q "^User=emor-yedek-deneme$" "$EMOR_SYSTEMD_DIZINI/emor-yedek.service"
bekle "kullanıcı giriş yapamaz"     bash -c "[[ \$(getent passwd emor-yedek-deneme | cut -d: -f7) == /usr/sbin/nologin ]]"
bekle "yayın gruba alınmadı"        bash -c "! id -nG $Y | tr ' ' '\n' | grep -qx emor-yedek-deneme"
bekle "inceleme rolü NOLOGIN"       bash -c "[[ \$(runuser -u postgres -- psql -X -A -t -c \"select rolcanlogin from pg_roles where rolname='emor_inceleme_deneme'\") == f ]]"

echo "== F. Bozulan durumlar: denetim yakalar, kurulum düzeltir"
chmod 755 $K/yedekler; chmod 644 $K/gizli/pgpass; chmod 2750 $K
psqlp "revoke erp from emor_yedek_deneme" >/dev/null; psqlp "grant erp to emor_yedek_deneme with inherit false, set true, admin true" >/dev/null
setfacl -x u:$Y $K/istekler
rm -f "$EMOR_SYSTEMD_DIZINI/emor-yedek.service"; echo bozuk > "$EMOR_SUDOERS_DOSYASI"
calistir "bozuk durumda denetim eksik" 1 --denetle
for ifade in "kok: .*2750 →" "yedekler: .*755 →" "pgpass: .* 600" "üyelik seçenekleri (f t t)" "istekler ACL user:$Y:rwx" "emor-yedek.service" "sudoers-emor-yedek yok ya da farklı"; do
  bekle "denetim yakaladı: $ifade" grep -q -- "$ifade" "$D/son.txt"
done
calistir "kurulum düzeltir" 0
calistir "ardından denetim temiz" 0 --denetle

echo "== G. Eski yerdeki parola dosyası taşınır"
cp -p $K/gizli/pgpass "$EMOR_ESKI_PGPASS"; chown $Y "$EMOR_ESKI_PGPASS"; rm $K/gizli/pgpass
calistir "denetim: parola dosyası yok" 1 --denetle
calistir "kurulum taşır" 0
bekle "taşındı mesajı" grep -q "parola dosyası taşındı" "$D/son.txt"
bekle "eski kopya silindi" test ! -e "$EMOR_ESKI_PGPASS"
bekle "taşınan dosya servisin, 600" bash -c "[[ \$(stat -c '%U %a' $K/gizli/pgpass) == 'emor-yedek-deneme 600' ]]"
bekle "parola yenilenmedi (taşınan dosyayla bağlanıyor)" grep -q "postgres veritabanına bağlanabiliyor" "$D/son.txt"
calistir "denetim temiz" 0 --denetle

echo "== H. Parola dosyası hiçbir yerde yoksa parola yenilenir"
ESKI_SATIR="$(cat $K/gizli/pgpass)"; rm $K/gizli/pgpass
calistir "kurulum yeni parola" 0
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
