#!/usr/bin/env bash
# Yedekleme modülünün FTP / FTPS / SFTP bağlayıcıları için GERÇEK deneme
# sunucuları (BY-020, BY-022). YALNIZ yerel geliştirme makinesinde (WSL)
# çalıştırılır; canlı sunucuda çalıştırılmaz. `sudo -n` gerekir.
#
#   scripts/tests/gercek-hedef-sunuculari.sh kur      # paketler + kullanıcılar + sertifikalar + başlat
#   scripts/tests/gercek-hedef-sunuculari.sh baslat   # (yeniden başlatmadan sonra) sunucuları başlat
#   scripts/tests/gercek-hedef-sunuculari.sh durdur   # sunucuları durdur, küçük diski ayır
#   scripts/tests/gercek-hedef-sunuculari.sh kaldir   # durdur + deneme kullanıcılarını ve dosyaları sil
#
# Testler (backend/ içinde):
#   source ~/.cache/emor-yedek-deneme/ortam.sh
#   php artisan test --compact tests/Feature/FtpBaglayicisiGercekSunucuTest.php tests/Feature/SftpBaglayicisiGercekSunucuTest.php
#
# Sunucular yalnız 127.0.0.1'i dinler. Sistemin kendi vsftpd / ssh servisleri
# kapatılır; bu betiğin örnekleri /opt/emor-yedek-deneme altındaki
# yapılandırmayla elle başlatılır (sistem yapılandırması değişmez).
#   2121 düz FTP (TLS yok)              2122 FTPS, kendinden imzalı sertifika
#   2123 FTPS, deneme CA'sı (localhost) 2124 düz FTP, 2 MB/sn sınırlı (kesinti testi)
#   2222 SFTP                           2223 SFTP, 2 MB/sn sınırlı (kesinti testi)
set -euo pipefail

KOK=/opt/emor-yedek-deneme
ORTAM_KLASORU="${HOME}/.cache/emor-yedek-deneme"
FTP_KULLANICI=emorftp
SFTP_KULLANICI=emorsftp
PAM_DOSYASI=/etc/pam.d/emor-vsftpd-deneme
FTP_PORTLARI=(2121 2122 2123 2124)

kok() { sudo -n "$@"; }

rastgele_parola() {
    # Tırnak, boşluk, noktalı virgül, $ ve Türkçe harf içerir: bağlayıcının kaçış kurallarını sınar
    printf 'Dnm "%s"; $x ğüşİ %s' "$(openssl rand -hex 6)" "$(openssl rand -hex 4)"
}

paketler() {
    local eksik=()
    for p in vsftpd openssh-server pv; do
        dpkg -s "$p" >/dev/null 2>&1 || eksik+=("$p")
    done
    if [ "${#eksik[@]}" -gt 0 ]; then
        kok env DEBIAN_FRONTEND=noninteractive apt-get install -y -q "${eksik[@]}"
    fi
    # Paketin açtığı sistem servisleri (21 / 22) kapalı kalır
    kok systemctl disable --now vsftpd.service ssh.socket ssh.service >/dev/null 2>&1 || true
}

kullanicilar() {
    for k in "$FTP_KULLANICI" "$SFTP_KULLANICI"; do
        if ! id "$k" >/dev/null 2>&1; then
            # SFTP'nin yavaş portundaki ForceCommand bir kabuk ister
            kok useradd --no-create-home --home-dir "$KOK/ev/$k" --shell /bin/sh "$k"
        fi
        kok install -d -m 0755 -o "$k" -g "$k" "$KOK/ev/$k" "$KOK/ev/$k/yedekler" "$KOK/ev/$k/kucuk"
        kok install -d -m 0755 -o "$k" -g "$k" "$KOK/ev/$k/yedekler/alt"
        # Kullanıcının giremediği değil, YAZAMADIĞI klasör
        kok install -d -m 0755 -o root -g root "$KOK/ev/$k/salt-okunur"
    done
    FTP_SIFRE="$(rastgele_parola)"
    SFTP_SIFRE="$(rastgele_parola)"
    printf '%s:%s\n' "$FTP_KULLANICI" "$FTP_SIFRE" "$SFTP_KULLANICI" "$SFTP_SIFRE" | kok chpasswd
}

sertifikalar() {
    local s="$KOK/tls"
    kok install -d -m 0755 "$s"
    local gecici
    gecici="$(mktemp -d)"
    (
        cd "$gecici"
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj '/CN=EMOR Yedek Deneme CA' \
            -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
            -keyout ca.key -out ca.pem 2>/dev/null
        # Yalnız "localhost" adına: 127.0.0.1 ile bağlanmak alan adı uyuşmazlığıdır
        openssl req -new -newkey rsa:2048 -nodes -subj '/CN=localhost' -keyout ca-imzali.key -out ca-imzali.csr 2>/dev/null
        printf 'subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n' > ek.cnf
        openssl x509 -req -in ca-imzali.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 30 \
            -extfile ek.cnf -out ca-imzali.pem 2>/dev/null
        openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj '/CN=localhost' \
            -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' -keyout kendinden.key -out kendinden.pem 2>/dev/null
    )
    kok install -m 0644 "$gecici/ca.pem" "$gecici/ca-imzali.pem" "$gecici/kendinden.pem" "$s/"
    kok install -m 0600 "$gecici/ca-imzali.key" "$gecici/kendinden.key" "$s/"
    rm -rf "$gecici"

    # PHP'nin curl'ü bu paketi kullanır (yalnız `ortam.sh` yüklenen kabukta): sistem CA'ları + deneme CA'sı
    install -d -m 0700 "$ORTAM_KLASORU/php-ini"
    cat /etc/ssl/certs/ca-certificates.crt "$s/ca.pem" > "$ORTAM_KLASORU/ca-paketi.pem"
    printf 'curl.cainfo=%s\n' "$ORTAM_KLASORU/ca-paketi.pem" > "$ORTAM_KLASORU/php-ini/zz-emor-yedek-deneme.ini"
}

ftp_yapilandirmasi() {
    local port="$1" tls="$2" sertifika="$3" ek="$4"
    local d="$KOK/vsftpd-$port.conf"
    {
        cat <<EOF
listen=YES
listen_ipv6=NO
listen_address=127.0.0.1
listen_port=$port
background=YES
anonymous_enable=NO
local_enable=YES
write_enable=YES
local_umask=022
pam_service_name=emor-vsftpd-deneme
userlist_enable=YES
userlist_deny=NO
userlist_file=$KOK/vsftpd.kullanicilar
chroot_local_user=YES
allow_writeable_chroot=YES
secure_chroot_dir=$KOK/bos
pasv_enable=YES
pasv_min_port=$((40000 + (port - 2121) * 100))
pasv_max_port=$((40099 + (port - 2121) * 100))
port_enable=YES
connect_from_port_20=NO
xferlog_enable=YES
xferlog_std_format=NO
log_ftp_protocol=YES
vsftpd_log_file=$KOK/log/vsftpd-$port.log
EOF
        if [ "$tls" = evet ]; then
            cat <<EOF
ssl_enable=YES
rsa_cert_file=$KOK/tls/$sertifika.pem
rsa_private_key_file=$KOK/tls/$sertifika.key
allow_anon_ssl=NO
force_local_logins_ssl=YES
force_local_data_ssl=YES
ssl_ciphers=HIGH
EOF
        else
            echo 'ssl_enable=NO'
        fi
        printf '%s\n' "$ek"
    } | kok tee "$d" >/dev/null
    kok chmod 0644 "$d"
}

yapilandirma() {
    kok install -d -m 0755 "$KOK/log" "$KOK/ssh" "$KOK/ssh/yetkili"
    kok install -d -m 0755 -o root -g root "$KOK/bos"
    echo "$FTP_KULLANICI" | kok tee "$KOK/vsftpd.kullanicilar" >/dev/null
    printf 'auth required pam_unix.so\naccount required pam_unix.so\nsession required pam_unix.so\n' | kok tee "$PAM_DOSYASI" >/dev/null

    ftp_yapilandirmasi 2121 hayir - ''
    ftp_yapilandirmasi 2122 evet kendinden ''
    ftp_yapilandirmasi 2123 evet ca-imzali ''
    ftp_yapilandirmasi 2124 hayir - 'local_max_rate=2000000'

    # SFTP: sunucu anahtarları, istemci anahtarları (parolasız + parolalı)
    for t in ed25519 ecdsa rsa; do
        [ -f "$KOK/ssh/ssh_host_${t}_key" ] || kok ssh-keygen -q -t "$t" -N '' -f "$KOK/ssh/ssh_host_${t}_key"
    done
    install -d -m 0700 "$ORTAM_KLASORU"
    rm -f "$ORTAM_KLASORU/istemci" "$ORTAM_KLASORU/istemci.pub" "$ORTAM_KLASORU/istemci-parolali" "$ORTAM_KLASORU/istemci-parolali.pub"
    ssh-keygen -q -t ed25519 -N '' -C emor-deneme -f "$ORTAM_KLASORU/istemci"
    ANAHTAR_PAROLASI="$(openssl rand -hex 8)"
    ssh-keygen -q -t ed25519 -N "$ANAHTAR_PAROLASI" -C emor-deneme-parolali -f "$ORTAM_KLASORU/istemci-parolali"
    cat "$ORTAM_KLASORU/istemci.pub" "$ORTAM_KLASORU/istemci-parolali.pub" | kok tee "$KOK/ssh/yetkili/$SFTP_KULLANICI" >/dev/null
    kok chmod 0644 "$KOK/ssh/yetkili/$SFTP_KULLANICI"

    printf '#!/bin/sh\n# İstemciden gelen veri 2 MB/sn ile sınırlı: yükleme ortasında kesmek için zaman kalır\npv -q -L 2m | /usr/lib/openssh/sftp-server\n' \
        | kok tee "$KOK/yavas-sftp-sunucusu" >/dev/null
    kok chmod 0755 "$KOK/yavas-sftp-sunucusu"

    kok tee "$KOK/ssh/sshd_config" >/dev/null <<EOF
Port 2222
Port 2223
ListenAddress 127.0.0.1
HostKey $KOK/ssh/ssh_host_ed25519_key
HostKey $KOK/ssh/ssh_host_ecdsa_key
HostKey $KOK/ssh/ssh_host_rsa_key
PidFile $KOK/ssh/sshd.pid
AllowUsers $SFTP_KULLANICI
PermitRootLogin no
UsePAM yes
PasswordAuthentication yes
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthorizedKeysFile $KOK/ssh/yetkili/%u
AllowTcpForwarding no
AllowAgentForwarding no
X11Forwarding no
PermitTTY no
LogLevel VERBOSE
Subsystem sftp internal-sftp
ForceCommand internal-sftp
Match LocalPort 2223
    ForceCommand $KOK/yavas-sftp-sunucusu
EOF
    kok chmod 0644 "$KOK/ssh/sshd_config"
}

ortam_dosyasi() {
    local d="$ORTAM_KLASORU/ortam.sh"
    install -d -m 0700 "$ORTAM_KLASORU"
    ( umask 077; : > "$d" )
    {
        printf 'export YEDEKLEME_GERCEK_FTP=1\n'
        printf 'export YEDEKLEME_FTP_SUNUCU=127.0.0.1\n'
        printf 'export YEDEKLEME_FTP_PORT=2121 YEDEKLEME_FTPS_KENDINDEN_PORT=2122 YEDEKLEME_FTPS_CA_PORT=2123 YEDEKLEME_FTP_YAVAS_PORT=2124\n'
        printf 'export YEDEKLEME_FTP_KULLANICI=%q\n' "$FTP_KULLANICI"
        printf 'export YEDEKLEME_FTP_SIFRE=%q\n' "$FTP_SIFRE"
        printf 'export YEDEKLEME_FTP_EV=%q\n' "$KOK/ev/$FTP_KULLANICI"
        printf 'export YEDEKLEME_HEDEF_GUNLUK=%q\n' "$KOK/log"
        printf 'export YEDEKLEME_GERCEK_SFTP=1\n'
        printf 'export YEDEKLEME_SFTP_SUNUCU=127.0.0.1 YEDEKLEME_SFTP_PORT=2222 YEDEKLEME_SFTP_YAVAS_PORT=2223\n'
        printf 'export YEDEKLEME_SFTP_KULLANICI=%q\n' "$SFTP_KULLANICI"
        printf 'export YEDEKLEME_SFTP_SIFRE=%q\n' "$SFTP_SIFRE"
        printf 'export YEDEKLEME_SFTP_ANAHTAR=%q\n' "$ORTAM_KLASORU/istemci"
        printf 'export YEDEKLEME_SFTP_PAROLALI_ANAHTAR=%q\n' "$ORTAM_KLASORU/istemci-parolali"
        printf 'export YEDEKLEME_SFTP_EV=%q\n' "$KOK/ev/$SFTP_KULLANICI"
        printf 'export YEDEKLEME_SFTP_SUNUCU_ANAHTARI=%q\n' "$(kok cat "$KOK/ssh/ssh_host_ed25519_key.pub" | awk '{print $1" "$2}')"
        # Deneme CA'sına güvenen curl (yalnız bu kabukta; varsayılan ini klasörü de okunur)
        printf 'export PHP_INI_SCAN_DIR=%q\n' ":$ORTAM_KLASORU/php-ini"
        printf 'export YEDEKLEME_FTPS_CA_PAKETI=%q\n' "$ORTAM_KLASORU/ca-paketi.pem"
    } >> "$d"
}

baslat() {
    durdur
    kok mkdir -p /run/sshd
    # Doluluk testi: 8 MB'lık bellek diski
    for k in "$FTP_KULLANICI" "$SFTP_KULLANICI"; do
        kok mount -t tmpfs -o "size=8m,mode=0755,uid=$(id -u "$k"),gid=$(id -g "$k")" emor-deneme "$KOK/ev/$k/kucuk"
    done
    for p in "${FTP_PORTLARI[@]}"; do
        # Günlük okunabilir olsun (testler parolanın TLS'ten önce gitmediğini buradan okur; vsftpd PASS'i maskeler)
        kok install -m 0644 /dev/null "$KOK/log/vsftpd-$p.log"
        kok /usr/sbin/vsftpd "$KOK/vsftpd-$p.conf"
    done
    kok install -m 0644 /dev/null "$KOK/log/sshd.log"
    kok /usr/sbin/sshd -f "$KOK/ssh/sshd_config" -E "$KOK/log/sshd.log"
    sleep 0.5
    for p in "${FTP_PORTLARI[@]}" 2222 2223; do
        if ! ss -ltn "sport = :$p" | grep -q LISTEN; then
            echo "Port $p dinlenmiyor" >&2
            exit 1
        fi
    done
    echo "Sunucular çalışıyor: FTP ${FTP_PORTLARI[*]}, SFTP 2222 2223"
}

durdur() {
    kok pkill -f "^/usr/sbin/vsftpd $KOK/" >/dev/null 2>&1 || true
    if [ -f "$KOK/ssh/sshd.pid" ]; then
        kok kill "$(kok cat "$KOK/ssh/sshd.pid")" >/dev/null 2>&1 || true
        kok rm -f "$KOK/ssh/sshd.pid"
    fi
    kok pkill -KILL -u "$FTP_KULLANICI" >/dev/null 2>&1 || true
    kok pkill -KILL -u "$SFTP_KULLANICI" >/dev/null 2>&1 || true
    for k in "$FTP_KULLANICI" "$SFTP_KULLANICI"; do
        if mountpoint -q "$KOK/ev/$k/kucuk" 2>/dev/null; then
            kok umount "$KOK/ev/$k/kucuk"
        fi
    done
}

kaldir() {
    durdur
    for k in "$FTP_KULLANICI" "$SFTP_KULLANICI"; do
        if id "$k" >/dev/null 2>&1; then
            kok userdel "$k"
        fi
    done
    kok rm -rf "$KOK" "$PAM_DOSYASI"
    rm -rf "$ORTAM_KLASORU"
}

case "${1:-}" in
    kur)
        paketler
        kullanicilar
        sertifikalar
        yapilandirma
        ortam_dosyasi
        baslat
        echo "Ortam: source $ORTAM_KLASORU/ortam.sh"
        ;;
    baslat) baslat ;;
    durdur) durdur; echo "Sunucular durduruldu." ;;
    kaldir) kaldir; echo "Deneme sunucuları kaldırıldı." ;;
    *)
        echo "Kullanım: $0 kur|baslat|durdur|kaldir" >&2
        exit 2
        ;;
esac
