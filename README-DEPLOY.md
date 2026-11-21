# API Cesarstwa Playera — wdrożenie

Ta paczka to **tylko backend**. Strona WWW zostaje na `cesarstwoplayera.ct.ws`.

## Zmienne środowiskowe (Render → Environment)

| Zmienna | Wartość |
|---|---|
| `CP_DB_DSN` | `pgsql:host=aws-1-eu-central-1.pooler.supabase.com;port=6543;dbname=postgres;sslmode=require` |
| `CP_DB_USER` | `postgres.naetxjigidcvzhtnppie` |
| `CP_DB_PASSWORD` | hasło bazy (to samo co w configu) |
| `CP_FRONT_URL` | `https://cesarstwoplayera.ct.ws` |
| `API_PUBLIC_URL` | `https://cesarstwoplayera.onrender.com` |

## Deploy na Render

1. Wypchnij zawartość tej paczki do repozytorium GitHub (korzeń repo = te pliki).
2. render.com → **New → Web Service** → repo
3. *Language*: **Docker**, *Instance Type*: **Free**
4. **Health Check Path**: `/api/health.php`
5. **Environment** → dodaj zmienne z tabeli
6. **Deploy**

## Jesli serwis „Running", ale adres nie odpowiada

Objaw: TLS się łączy, serwer milczy do timeoutu. Prawie zawsze znaczy to,
że aplikacja nie nasłuchuje na porcie podanym przez Rendera.

Sprawdź w **Logs**:

| W logu widać | Co zrobić |
|---|---|
| `Address already in use` / nic o `Development Server started` | port wzięty na sztywno — użyj `Dockerfile` z tej paczki (czyta `$PORT`) |
| `PHP Fatal error` / `Class not found` | brak plików w repo — korzeń repo musi zawierać `router.php` i `index.php` |
| `could not find driver` | obraz nie ma `pdo_pgsql` — użyj `Dockerfile` z tej paczki |
| logi puste | Render nie znalazł `Dockerfile` w korzeniu repo (był w podkatalogu) |

Poprawny start w logach wygląda tak:

```
PHP 8.3.x Development Server (0.0.0.0:10000) started
```

Numer portu jest dowolny — ważne, że jest `0.0.0.0`, nie `127.0.0.1`.

## Po wdrożeniu

1. `https://TWOJE-API/api/health.php` — sekcja „Baza danych" zielona
2. Google Cloud → dodaj redirect `https://TWOJE-API/google/callback.php`
3. Discord → dodaj redirect `https://TWOJE-API/discord/callback.php`
4. W `config.local.php` na ct.ws: `'api_public_url' => 'https://TWOJE-API'`
