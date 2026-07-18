# 📊 MaçRadar — AI Destekli İddaa Analiz Platformu

Flutter mobil uygulama + saf PHP backend + web admin paneli. Mackolik'ten maç ve oranları çeker, geçmiş/güncel maç verilerini **Gemini** veya **OpenAI uyumlu (custom base URL destekli)** yapay zeka ile analiz ederek her bahis seçeneğinin kazanma olasılığını ve gerekçesini gösterir.

> ⚠️ **Yasal uyarı:** Uygulama analizleri yatırım/bahis tavsiyesi değildir. 18+ içerik.

---

## İçindekiler
- [Mimari](#mimari)
- [Dizin yapısı](#dizin-yapısı)
- [Backend kurulumu (cPanel)](#backend-kurulumu-cpanel)
- [Cron job'ları](#cron-jobları)
- [Mackolik scraper yapılandırması](#mackolik-scraper-yapılandırması)
- [AI sağlayıcı ayarları](#ai-sağlayıcı-ayarları)
- [Flutter uygulaması](#flutter-uygulaması)
- [Codemagic ile build](#codemagic-ile-build)
- [API uçları](#api-uçları)

---

## Mimari

```
Flutter App  ──HTTPS──►  PHP REST API (/api/v1)  ──►  MySQL
Admin Panel  ──────────►  PHP (public_html/admin)      ▲
                          Scraper (cron) ── Mackolik ───┘
                          AI Engine ── Gemini / OpenAI / Custom LLM
```

- **Backend:** Framework'süz saf PHP (PSR-4 autoload, harici bağımlılık yok). PDO/MySQL.
- **Analiz:** İstek üzerine üretilir ve DB'de önbelleğe alınır — aynı maç için tek AI çağrısı.
- **Üyelik & Kredi:** 4 kademe (Ücretsiz / Bronz / Gümüş / Altın). Her paketin
  **günlük AI analiz kredisi** vardır (vars. 1/20/50/120); krediler her gün sıfırlanır,
  ertesi güne devretmez. **Her market AYRI bir AI çağrısıyla analiz edilir ve ayrı
  kredi tüketir** (internet araştırması dahil — Gemini web grounding). Aynı maçın aynı
  marketini tekrar görüntülemek ücretsizdir. Oran gruplarının hangi pakete açık olduğu,
  kredi miktarları ve maliyetler admin panelinden ayarlanır. Günün AI Kuponu: Gümüş +
  Altın. Canlı maçlarda anlık AI analizleri: yalnız Altın (daha yüksek kredi).

## Dizin yapısı

```
mac/
├── codemagic.yaml           # CI/CD (Android build)
├── pubspec.yaml             # Flutter uygulaması (proje kökünde)
├── lib/
│   ├── core/                # tema, sabitler, api_client, router
│   ├── models/              # Match, Analysis, User ...
│   ├── services/            # ApiClient, TokenStore
│   ├── providers/           # Riverpod state
│   ├── screens/             # ekranlar
│   └── widgets/             # MatchCard, ProbabilityRing, AnalysisView
├── android/                 # Android platform (INTERNET izni dahil)
└── backend/
    ├── public_html/         # ⇒ cPanel web root'a
    │   ├── api/             # REST API (index.php + .htaccess)
    │   ├── admin/           # Admin paneli
    │   └── install.php      # tek seferlik kurulum (sonra silin)
    ├── src/                 # ⇒ web root DIŞINA (güvenlik)
    │   ├── Core/            # Router, Database, Auth, Jwt, Settings ...
    │   ├── Controllers/Api/ # AuthController, MatchController, AnalysisController
    │   ├── Services/        # MackolikScraper, AnalysisEngine, Llm/*
    │   └── Cron/            # fetch_fixtures, fetch_results, cleanup
    ├── config/              # config.example.php (config.php'yi siz oluşturun)
    └── database/schema.sql
```

## Backend kurulumu (cPanel)

1. **Veritabanı:** cPanel > MySQL Databases'ten bir DB ve kullanıcı oluşturun; kullanıcıyı DB'ye tüm yetkilerle ekleyin.
2. **Dosyaları yükleyin:**
   - `backend/src`, `backend/config`, `backend/database` klasörlerini **public_html'in DIŞINA** (ev dizinine) yükleyin.
   - `backend/public_html/` **içeriğini** cPanel `public_html` klasörüne yükleyin (`api/`, `admin/`, `install.php`).
   - > Autoloader `public_html/../src` yolunu bekler; yani `src/` klasörü `public_html`'in üst dizininde olmalıdır.
3. **Yapılandırma:** `config/config.example.php`'yi `config/config.php` olarak kopyalayın; DB bilgileri ve rastgele bir `jwt.secret` girin (`bin2hex(random_bytes(32))`).
4. **Kurulum:** Tarayıcıdan `https://alanadiniz.com/install.php` açın — şema yüklenir. **Sonra `install.php`'yi silin.**
5. **Admin paneli:** `https://alanadiniz.com/admin/` — varsayılan `admin` / `admin123`. Girer girmez **Genel Ayarlar > şifre** değiştirin.
6. **Sağlık kontrolü:** `https://webdigistore.com/macradar/api/v1/health` → `{"success":true,...}` dönmeli.

## Cron job'ları

cPanel > Cron Jobs'ta ekleyin (PHP yolu sunucunuza göre değişebilir):

| Sıklık | Komut |
|---|---|
| 30 dk | `php /home/KULLANICI/src/Cron/fetch_fixtures.php` |
| 15 dk | `php /home/KULLANICI/src/Cron/fetch_results.php` |
| Günlük 04:00 | `php /home/KULLANICI/src/Cron/cleanup.php` |

## Mackolik scraper yapılandırması

Scraper iki stratejiyi destekler: **JSON feed** (birincil) ve **HTML/XPath** (yedek). Mackolik'in güncel yapısı zamanla değiştiği için uç adresleri ve XPath seçicileri **Admin > Scraper** sayfasından kod değiştirmeden güncellenebilir. `{date}` ve `{id}` yer tutucuları desteklenir. İlk kurulumda tarayıcı ağ trafiğini inceleyerek güncel JSON uçlarını girin; hiçbiri girilmezse HTML yedeği devreye girer.

Manuel test: Admin > Scraper > "Bugünü şimdi çek" veya Admin > Maçlar > "Bu tarihi Mackolik'ten çek".

## AI sağlayıcı ayarları

**Admin > AI Ayarları**:
- **Gemini:** API key + model (ör. `gemini-1.5-flash`).
- **OpenAI / Custom LLM:** API key + **Base URL** + model. Base URL'i değiştirerek OpenAI-uyumlu her sağlayıcıyı kullanabilirsiniz: OpenRouter, Groq, Together, **kendi LLM sunucunuz**, Ollama/LM Studio (`http://.../v1`).
- **"Kaydet & Test Et"** butonu bağlantıyı canlı doğrular.
- Analiz prompt şablonu isteğe bağlı olarak özelleştirilebilir.

## Flutter uygulaması

Flutter projesi deponun kökündedir (pubspec.yaml kökte).

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=https://webdigistore.com/macradar/api/v1
```

- API adresi `--dart-define=API_BASE_URL=...` ile geçilir (varsayılan `lib/core/constants.dart`).
- State: Riverpod · HTTP: dio (otomatik JWT yenileme) · Router: go_router.
- Koyu tema, olasılık ringleri, değerli oran (💎 value bet) rozetleri.

## Codemagic ile build

`codemagic.yaml` şu an yalnızca **Android** build içerir:
- **android-release:** `flutter build apk/appbundle` → APK + AAB artifact.
- İmza kurmadan da çalışır: keystore yoksa release APK debug anahtarıyla imzalanır (test derlemeleri için). Play Store'a yükleyecekseniz Codemagic'te bir değişken grubu (ör. `android_keystore`) oluşturup `CM_KEYSTORE_PATH`, `CM_KEY_ALIAS`, `CM_KEYSTORE_PASSWORD`, `CM_KEY_PASSWORD` ekleyin ve `codemagic.yaml`'daki `groups` satırlarının yorumunu kaldırın.

API adresi `API_BASE_URL` environment variable'ı ile derleme zamanında enjekte edilir. Gradle wrapper CI'da otomatik üretilir.

> iOS build'i şimdilik dahil değil; gerektiğinde eklenecek.

## API uçları

| Metod | Uç | Açıklama |
|---|---|---|
| GET | `/health` | Sağlık kontrolü |
| POST | `/auth/register` · `/auth/login` · `/auth/refresh` | Kimlik |
| GET | `/me` | Aktif kullanıcı |
| GET | `/leagues` | Ligler |
| GET | `/matches?date=YYYY-MM-DD` | Bülten (lige gruplu, listede model `signal` alanı) |
| GET | `/matches/live` | Canlı maçlar (canlı oran + skor) |
| GET | `/matches/{id}` | Maç detayı (oran + grup görünürlüğü `market_groups` + görünür marketler + istatistik + kullanıcının açtığı `market_analyses`) |
| POST | `/matches/{id}/analyze-market` | TEK marketi AI ile analiz et (`{market_key}`, kredi tüketir; canlı maçta yalnız Altın) |
| GET | `/me/analyses` | "Analizlerim" — kullanıcının incelediği maçlar + isabet |
| GET | `/coupon/daily` | "Günün AI Kuponu" — Gümüş + Altın |
| GET/POST/DELETE | `/favorites` | Favoriler |
| GET | `/stats/success-rate` | AI isabet istatistikleri |

Yanıt formatı: `{"success":true,"data":{...}}` / hata: `{"success":false,"error":"...","message":"..."}`.

### "Maç Analiz" tasarım güncellemesi (v2)

Arayüz, yüklenen tasarım dosyasına göre yeniden yapıldı:
- **4 sekme:** Maçlar (Canlı/Bugün/Yarın) · Kupon · Analizlerim · Hesap
- **Maç detayı:** model olasılığı vs. oranın iması barları, **DEĞER** sinyalleri, "Model neden böyle düşünüyor?" gerekçeleri + güven puanı, form/H2H/sezon karşılaştırması
- **Fontlar:** Archivo (genel) + JetBrains Mono (sayılar/oranlar) — `google_fonts` ile
- **AI çıktısı** artık `guven` (1-10) ve `nedenler` ([{etiket, metin}]) alanlarını da üretir.

**Mevcut kuruluma geçiş (panelsiz):** `Analizlerim` için yeni bir tablo gerekir.
phpMyAdmin'e girmeye gerek yok — güncellenen backend dosyalarını sunucuya yükledikten sonra
tarayıcıdan **`/migrate.php`** adresini açıp "Güncellemeleri uygula" deyin. Bu sayfa
`database/migration_*.sql` dosyalarını otomatik ve tekrar çalıştırılabilir şekilde uygular.
(İşiniz bitince `migrate.php`'yi silebilirsiniz.)

Yüklenecek/değişen backend dosyaları: `src/Controllers/Api/MatchController.php`,
`src/Services/AnalysisEngine.php`, `public_html/api/index.php`, `public_html/migrate.php`,
`database/migration_2026_07_history.sql`. Flutter tarafını Codemagic'ten yeniden derleyin.
