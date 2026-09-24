# EFAT-01 — Gizli bilgilerin repoya girmesini önle

- **Durum:** Bitti
- **Bağımlılık:** —
- **Skill:** —

## Neden

`publish.sh:110` `git add -A` çalıştırıyor. `docs/izibiz/mail.md:18` içinde
İzibiz portal kullanıcı adı ve şifresi var ve bu dosya `.gitignore`'da değil.
Bir sonraki yayında şifre GitHub'a gider. Canlı bilgiler geldiğinde risk büyür.

Teknik gerekçe: sırlar Git geçmişine girdikten sonra dosyayı silmek sızıntıyı
geri almaz; Laravel security `Keep Secrets Out of Application Code` kuralı.
En küçük düzeltme ham gizli dosyaları commit öncesinde hariç tutmaktır.

## Yapılacaklar

- [x] Ham mail ve environment export'ları `.gitignore` ile hariç tutuldu;
      orijinaller yerinde. Collection incelendi (karar aşağıda).
- [x] `Zone.Identifier` artığı `.gitignore`'a eklendi (dosya silinmedi).
- [x] Görev dosyalarına ve koda hiçbir zaman şifre ya da token yazılmaz;
      kalıcı uygulama şifreleri `encrypted` cast'le saklanır (EFAT-03).
      Token cache'i ve geçici import girdisi de gizli veri sayılır. — kalıcı kural
- [x] `git ls-files`, `git check-ignore -v` ve `git add -n` ayrı ayrı kontrol edildi.
- [x] Geçmiş kontrolü sır değerleri terminale dökülmeden yapıldı.

## Kararlar

- **Collection repoda kalır.** İçindeki üç `accessToken` İzibiz'in kendi
  dokümanındaki örnek yanıtlar: hesaplar `izibiz-dev` ve `izibiz-kanal`
  (bizim değil), süreleri 2021'de dolmuş. İstek gövdeleri kimlik bilgisi yerine
  `{{username}}`/`{{password}}` değişkenleri kullanıyor. Kendi hesabımıza ait
  bir export gelirse repoya alınmadan önce placeholder'a çevrilir.
- **`Zone.Identifier` deseni iki nokta içermez.** WSL dosya adındaki `:`
  karakterini özel Unicode karaktere (U+F03A) çevirdiği için `*:Zone.Identifier`
  eşleşmez; kural `*Zone.Identifier`.

## Kabul kriterleri

- [x] Ham gizli dosyalar ignore edilmiş ve izlenmiyor; stage'e girecek
      dosyalarda gerçek şifre/token yok.
- [x] Erişilebilen yerel Git geçmişi kontrolünün kapsamı ve sonucu kayıtlı;
      sızıntı bulunmadığı için yenileme işi gerekmedi.

## Sonuç

2026-09-23 incelemesi: `mail.md` izlenmiyor, ignore edilmiyor; bu dosya
yolu için yerel geçmiş kaydı yok. Collection'da örnek token alanları var.

2026-09-23 (Claude): kök `.gitignore`'a `/docs/izibiz/mail.md` eklendi.

2026-09-23 (Claude) — tamamlandı:

- `.gitignore` kuralları: `/docs/izibiz/mail.md`, `*.postman_environment.json`,
  `*Zone.Identifier`.
- Doğrulama: `git check-ignore -v` üç kuralı da gösteriyor (environment kuralı
  `--no-index` ile örnek yolda); `git add -n docs/` listesinde mail.md,
  Zone.Identifier veya environment dosyası yok.
- Geçmiş taraması (yerel repo, `git log --all`): `docs/izibiz` yolu için
  commit yok; test şifresi ve test kullanıcı adı `-S` aramasında hiçbir
  commit'te geçmiyor. Kapsam yalnız yerel klondur; GitHub'daki uzak dallar
  bu klonda olanlarla sınırlı kontrol edildi.
