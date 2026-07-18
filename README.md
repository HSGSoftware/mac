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
- **Üyelik:** Ücretsiz (günlük limitli) / Premium (sınırsız).

## Dizin yapısı

```
mac/
├── codemagic.yaml           # CI/CD (Android & iOS build)
├── app/                     # Flutter uygulaması
│   ├── lib/
│   │   ├── core/            # tema, sabitler, api_client router
│   │   ├── models/          # Match, Analysis, User ...
│   │   ├── services/        # ApiClient, TokenStore
│   │   ├── providers/       # Riverpod state
│   │   ├── screens/         # ekranlar
│   │   └── widgets/         # MatchCard, ProbabilityRing, AnalysisView
│   └── android/             # Android platform (INTERNET izni dahil)
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

```bash
cd app
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
| GET | `/matches?date=YYYY-MM-DD` | Bülten (lige gruplu) |
| GET | `/matches/{id}` | Maç detayı (oran + istatistik + analiz) |
| POST | `/matches/{id}/analyze` | AI analizi iste (önbellekli, limitli) |
| GET | `/matches/{id}/analysis` | Mevcut analiz |
| GET/POST/DELETE | `/favorites` | Favoriler |
| GET | `/stats/success-rate` | AI isabet istatistikleri |

Yanıt formatı: `{"success":true,"data":{...}}` / hata: `{"success":false,"error":"...","message":"..."}`.
