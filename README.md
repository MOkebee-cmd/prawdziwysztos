# Skilltree Studio – wersja PHP + MySQL

Edytor drzewek perków `prp-perks` z backendem PHP i bazą MySQL/MariaDB.
Każda zmiana w UI (dodanie perka, przesunięcie, edycja efektu, usunięcie zależności)
trafia do bazy w czasie rzeczywistym (debounce ~350 ms).
Wielu użytkowników może pracować jednocześnie – polling co 5 s podchwytuje cudze zmiany.

## Pliki

```
skilltree-php/
├── index.html       Frontend (edytor)
├── api.php          REST API (load / save_perk / delete_perk / save_spec ...)
├── config.php       Konfiguracja (BAZA, TOKEN, LOGOWANIE)  ← edytuj
├── db.php           Helper PDO
├── install.php      Instalator schematu + dane demo  ← uruchom raz, potem usuń
└── README.md
```

## Wymagania

- PHP 7.4+ (działa na 8.x)
- MySQL 5.7+ albo MariaDB 10.2+
- Rozszerzenia PHP: `pdo`, `pdo_mysql`, `json`

## Instalacja

1. **Wgraj wszystkie pliki** na serwer (np. do katalogu `public_html/skilltree/`).

2. **Edytuj `config.php`** i wpisz dane bazy:
   ```php
   'db' => [
       'host'     => 'localhost',
       'database' => 'prp_skilltree',
       'username' => 'twoj_user',
       'password' => 'twoje_haslo',
       ...
   ],
   ```

3. **Uruchom instalator** w przeglądarce:
   `https://twoja-strona.pl/skilltree/install.php`

   Utworzy bazę (jeśli nie istnieje), tabele i wstępne dane demo.

4. **USUŃ `install.php`** z serwera. Bez tego ktoś mógłby zresetować dane.

5. **Otwórz `index.html`** – możesz pracować.

## Bezpieczeństwo

Domyślnie API jest otwarte dla każdego, kto ma adres URL. Aby zabezpieczyć
edytor:

1. Wygeneruj losowy token, np. w PHP: `php -r 'echo bin2hex(random_bytes(16));'`
2. W `config.php` ustaw `'token' => 'wygenerowany_token'`
3. W `index.html` znajdź `APP_CONFIG` (góra `<script>`) i wpisz ten sam token
   w `apiToken: '...'`

Token leci w nagłówku `X-API-Token`. Bez niego API zwraca 401.

W produkcji rozważ też dodanie `.htaccess` na poziomie katalogu (BasicAuth)
albo umieszczenie edytora pod ścieżką znaną tylko adminom.

## Schemat bazy

| Tabela | Zawiera |
|---|---|
| `categories` | 4 wiersze: `general`, `civ`, `crime`, `faction` + globalne `levels[]` |
| `specializations` | drzewka perków (np. `strength`, `trucker`) – PK to `(id, category_id)` |
| `perks` | pojedyncze perki – PK `(id, spec_id, category_id)`, FK do `specializations` z `ON DELETE CASCADE` |
| `change_log` | historia operacji (audit trail) – możesz wyłączyć w `config.php` |

`requiredPerks` i `levels[]` są trzymane jako JSON w jednej kolumnie –
to upraszcza zapis bez tracenia zgodności z `Config.Types`.

## API endpoints

Wszystkie używają `POST application/json` z polem `action`:

| Action | Payload | Opis |
|---|---|---|
| `load` | – | Zwraca pełne `data.types` (4 kategorie ze specami i perkami) |
| `save_perk` | `{ categoryId, specId, perk }` | Upsert pojedynczego perka |
| `delete_perk` | `{ categoryId, specId, perkId }` | Usuwa + czyści referencje w innych perkach |
| `move_perk` | `{ categoryId, specId, perkId, x, y }` | Lekki update tylko pozycji (drag&drop) |
| `save_spec` | `{ categoryId, spec }` | Upsert specjalizacji |
| `delete_spec` | `{ categoryId, specId }` | Usuwa drzewko (kaskaduje na perki) |
| `save_full` | `{ data: {...} }` | Bulk replace (używane przez Import JSON) |
| `log` | `{ message, data }` | Wpis do `change_log` z frontu (opcjonalne) |

## Jak działa zapis w czasie rzeczywistym

1. Użytkownik zmienia coś w UI (np. opis perka).
2. Frontend dodaje operację do kolejki `Sync` z kluczem `perk:cat:spec:id`.
3. Po `350 ms` bezczynności kolejka jest wysyłana batchem.
4. Jeżeli kilka zmian dotyczy tego samego perka – ostatnia wygrywa
   (klucz w mapie się nadpisuje).
5. W przypadku błędu sieci operacja zostaje w kolejce i jest ponawiana co 2 s.
6. Przed zamknięciem karty `navigator.sendBeacon` wymusza flush.

Wskaźnik w prawym dolnym rogu canvasu pokazuje aktualny stan:
`Wczytywanie…` / `Zapisywanie…` / `Zapisano ✓` / `Offline` / `Błąd`.

## Polling (multi-user)

Co 5 s frontend odpytuje `load` i podmienia stan, ale tylko jeżeli:
- karta jest aktywna (`document.hidden = false`),
- nie ma niewysłanych zmian (priorytet ma własny zapis).

Aby wyłączyć: ustaw `pollIntervalMs: 0` w `APP_CONFIG`.

## Eksport do Lua (opcjonalnie, gotowy snippet)

W bazie wszystkie pola odpowiadają strukturze `Config.Types`. Jeśli chcesz
wygenerować plik `*_perks.lua` automatycznie, dodaj endpoint typu
`?action=export_lua&categoryId=general` i wypluj zwykłym `echo`. Mogę dorzucić
gotowy generator – daj znać.

## Backup

Najprostszy:
```bash
mysqldump -u user -p prp_skilltree > backup.sql
```

Albo z poziomu UI: przycisk **Eksport** → kopia całego stanu w JSON.

## Rozwiązywanie problemów

- **„Server error: SQLSTATE[HY000] [1045]”** – złe hasło/user w `config.php`.
- **„Server error: SQLSTATE[HY000] [2002]”** – zły host MySQL albo MySQL nie działa.
- **„Unauthorized”** – token w `index.html` nie pasuje do tego w `config.php`.
- **Brak połączenia, status „Offline”** – sprawdź czy `api.php` jest dostępne
  pod tym samym hostem co `index.html`. Otwórz DevTools → Network.
- **CORS** – jeżeli frontend i API są na różnych domenach, odkomentuj nagłówki
  CORS w `api.php` (góra pliku).

## Zmienne wewnątrz `index.html`

W górze sekcji `<script>` masz `APP_CONFIG`:
```js
const APP_CONFIG = {
  apiUrl: 'api.php',          // ścieżka do API
  apiToken: '',                // pusty = bez tokenu
  pollIntervalMs: 5000,        // co ile ms sprawdzać zmiany innych userów
  saveDebounceMs: 350,         // debounce zapisu
};
```
