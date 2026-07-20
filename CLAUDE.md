# MaçRadar — Claude için proje notları

Flutter mobil uygulama + saf PHP backend + web admin paneli.
Nesine iddaa bülteninden maç/oran çeker, yapay zeka ile her bahis marketini analiz eder.

Kullanıcı Türkçe konuşur; **kod yorumları, commit mesajları, admin paneli ve uygulama metinleri Türkçedir.**

## Dizin düzeni

```
lib/                      Flutter (pubspec.yaml kökte)
backend/public_html/      cPanel web root'a  → api/, admin/, migrate.php
backend/src/              web root DIŞINA    → Core/, Controllers/, Services/, Cron/
backend/database/         schema.sql + migration_*.sql
```

Autoloader `public_html/../src` yolunu bekler — `src/` daima `public_html`'in üstünde olmalı.

## Veri kaynağı: Nesine (Mackolik değil)

`MackolikScraper` adı tarihseldir; **veri Nesine'den gelir**
(`bulten.nesine.com/api/bulten/getprebultenfull` ve `getlivebultenfull`).

Bültenin futbol maçlarında **market adı ve seçenek etiketi YOKTUR** — yalnızca
`MTID` (market tipi), `MST`, `SOV` (çizgi) ve oranlar gelir. Adlar
`Services/MarketDictionary.php` sözlüğünden çözülür (704 market tipi; Nesine web
istemcisinin `CCAll.min.js` tanımlarından üretilmiştir). Bültendeki 65 aktif
market tipinin tamamını kapsar.

- Ad şablonundaki `{{handicap}}`, marketin `SOV` değeriyle doldurulur
  (MTID 268 + SOV -1.0 → "Handikaplı Maç Sonucu (-1,0)").
- Skor/oyuncu gibi dinamik marketlerde seçenek adı bültendeki `ON` alanından gelir.
- `MarketDictionary.php` **üretilmiş dosyadır, elle düzenlenmez.** Nesine yeni
  market tipi eklerse Admin > Marketler > "Nesine'den isimleri güncelle"
  butonu `settings.mk_market_names` override'ını tazeler (sözlüğün önüne geçer).

Takım amblemleri Nesine'de yoktur; `TeamLogoService` TheSportsDB'den bulup
`teams.logo_url`'e yazar (`fetch_fixtures` cron'unda her çalışmada 40 takım).
Bulunamayanlar `teams.logo_checked_at` ile işaretlenir, 30 gün sonra tekrar denenir.

## Analiz akışı (kullanıcı BEKLEMEZ)

Bir market analizi istendiğinde:

1. **Hazır ve tazeyse** → anında döner; ücretliyse kredi o an düşer.
2. **Hazır değilse** → maç "Analizlerim"e eklenir, HTTP **202 + `preparing:true`**
   hemen döner, bağlantı kapatılır (`fastcgi_finish_request`) ve arka planda
   `AnalysisEngine::analyzeAllMarkets()` çalışır: **maçın TÜM marketleri TEK AI
   çağrısıyla** üretilir (hız + maliyet). Bu aşamada kredi DÜŞMEZ.
3. Üretim bitince maçı son 6 saatte görüntülemiş kullanıcılara
   `notifications` kaydı düşer; uygulama bunu bildirim olarak gösterir.

Aynı maç için 3 dakika içinde ikinci bir üretim tetiklenmez.
Seçenek sayısı `ai_max_market_options` (vars. 24) üstündeki marketler
(oyuncu bazlı, 50-99 seçenek) analiz dışıdır — oranları yine gösterilir.

## Kredi modeli

- Günlük kredi paket kademesine göre; her gün sıfırlanır, devretmez. Bonus kredi devreder.
- Maliyet önceliği: **market tipi (MTID) override'ı > grup varsayılanı**
  (`Credits::marketCostFor($group, $mtid, $isLive)`).
- **Ana marketler (Maç Sonucu) varsayılan ÜCRETSİZ** — oranla birlikte gösterildiği
  için ayrıca ücretlendirilmez. 0 fiyatlı market canlıda da ücretsiz kalır.
- Aynı marketi tekrar açmak ücretsiz (`user_unlocks`); canlıda TTL dolunca yeniden ücretlenir.
- Grup görünürlüğü paket kademesine bağlıdır (`group_min_tier_*`); kilitli grubun
  marketleri API yanıtına hiç girmez.

`Credits::groupKeyForMarketName()` ile Flutter'daki `marketGroupKeyFor()`
**aynı mantığı** taşır — biri değişirse diğeri de değişmeli.

## Admin paneli

`Panel · Maçlar · Analizler · Marketler · AI Ayarları · Prompt Kayıtları · Kullanıcılar · Scraper · Genel Ayarlar`

Ayar anahtarları **tek yerden** yönetilir; aynı anahtarı iki sayfaya koymayın:

| Konu | Sayfa |
|---|---|
| Market/grup kredi maliyeti, market adı, Nesine sözlük güncelleme | **markets.php** |
| AI sağlayıcı, prompt şablonu, `ai_max_market_options` | ai_settings.php |
| Günlük krediler, grup min paket, TTL, web araması, grup adları | settings.php |

## Migration

`migrate.php` tarayıcıdan açılır ve `database/migration_*.sql` dosyalarını
**alfabetik sırayla, ifade ifade** çalıştırır. "Duplicate column/key/already exists"
hataları yutulur, yani migration'lar tekrar çalıştırılabilir. Uygulanmış migration
takibi yoktur — her açılışta hepsi yeniden çalışır, bu yüzden yeni migration
yazarken idempotent olmasına dikkat edin.

## Yerel geliştirme kısıtı

Bu Windows makinede **PHP kurulu değil** — `php -l` ile sözdizimi doğrulanamaz.
PHP değişikliklerini gözle dikkatle kontrol edin.
