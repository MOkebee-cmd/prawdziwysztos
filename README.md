# Skilltree Studio – wersja Supabase + GitHub Pages

Edytor drzewek perków `prp-perks` w pełni statyczny (HTML + JS), z backendem Supabase
(Postgres + Realtime przez WebSocket). Działa na GitHub Pages, Netlify, Cloudflare Pages
albo dowolnym statycznym hostingu.

**Realtime** (nie polling) – kiedy ktoś inny coś zmieni, Twoja karta dostaje update
przez WebSocket w ciągu ~100 ms. Bez odświeżania, bez interwałów.

## Pliki

```
skilltree-supabase/
├── index.html             Frontend (edytor)
├── supabase-setup.sql     Schemat bazy + dane demo + RPC
└── README.md
```

## Krok po kroku – pierwsza instalacja

### 1. Załóż projekt Supabase

1. Wejdź na <https://supabase.com> → **Start your project** → zaloguj się GitHubem.
2. Kliknij **New project**.
3. Wybierz organizację, wpisz nazwę (np. `skilltree`), wygeneruj hasło bazy
   (zapisz – będzie potrzebne do kopii zapasowych), wybierz region najbliższy
   (Frankfurt dla PL/EU). **Free tier** wystarczy w zupełności.
4. Poczekaj ~1 min aż projekt wstanie.

### 2. Utwórz schemat

1. Lewy panel → **SQL Editor** → **New query**.
2. Otwórz lokalnie plik `supabase-setup.sql`, skopiuj **całą zawartość**.
3. Wklej do edytora i kliknij **Run** (Ctrl+Enter).
4. Na dole zobaczysz wynik:
   ```
   tbl              | n
   categories       | 4
   specializations  | 2
   perks            | 7
   ```
   To znaczy: schemat utworzony, dane demo wstawione.

### 3. Pobierz klucze API

1. Lewy panel → **Project Settings** (ikonka koła zębatego) → **API**.
2. Skopiuj dwie wartości:
   - **Project URL** – np. `https://abcdefghij.supabase.co`
   - **anon public** key – długi token JWT (zaczyna się od `eyJ...`)

### 4. Wklej dane do `index.html`

Otwórz `index.html` w edytorze, znajdź na górze sekcji `<script>`:

```js
const APP_CONFIG = {
  supabaseUrl:     'https://TWOJ-PROJEKT.supabase.co',
  supabaseAnonKey: 'TWOJ-ANON-KEY',
  saveDebounceMs: 350,
  realtime: true,
};
```

Wpisz tam swoje wartości z kroku 3.

### 5. Test lokalny (opcjonalnie)

Możesz po prostu otworzyć `index.html` dwukrotnym kliknięciem – działa.
Albo z prostym serwerem:

```bash
python3 -m http.server 8000
# otwórz http://localhost:8000
```

Powinieneś zobaczyć drzewka Siła i Trucker (dane demo).

### 6. Deploy na GitHub Pages

```bash
git init
git add index.html README.md supabase-setup.sql
git commit -m "Initial"
git remote add origin git@github.com:TWOJ_USER/skilltree-editor.git
git push -u origin main
```

Następnie w repo na GitHubie:
- **Settings** → **Pages** → Source = `Deploy from a branch` → Branch = `main` / `/ (root)` → **Save**
- Po ~1 minucie strona jest na `https://TWOJ_USER.github.io/skilltree-editor/`

## Jak działa zapis i sync

Każda zmiana w UI (dodanie perka, przesunięcie, edycja efektu, usunięcie zależności):

1. **Lokalnie aktualizujemy stan** + odświeżamy widok natychmiast.
2. Operacja trafia do **kolejki** (per-encja, klucz np. `perk:general:strength:p_str_1`).
3. Po **350 ms bezczynności** kolejka jest opróżniana batchem do Supabase
   (każda zmiana to upsert na odpowiedniej tabeli).
4. Każdy upsert ustawia `last_modified_by = CLIENT_ID` (unikalne dla karty/przeglądarki).
5. **Supabase rozsyła zmianę** przez Realtime do wszystkich subskrybowanych kart.
6. Karty filtrują własne zmiany (po `last_modified_by`) i przeładowują tylko
   przy zmianach z innych klientów.

W rezultacie: szybkie UI lokalne + natychmiastowy refresh u innych edytorów.

## Bezpieczeństwo

⚠️  **Ważne:** Klucz `anon` w kodzie **JEST PUBLICZNY** – tak działa Supabase.
Razem z domyślnymi politykami RLS (Row Level Security) z `supabase-setup.sql`,
**każdy kto zna URL Twojego repo na GitHubie może edytować dane**.

To jest OK jeżeli:
- Jesteś sam i URL nie jest jakoś szczególnie publiczny
- Twój zespół (kilku adminów) zna URL i sobie ufa
- Repo na GitHubie jest **prywatne** (URL Pages też jest mniej widoczny)

Jeżeli potrzebujesz prawdziwej autoryzacji:

### Opcja A – Supabase Auth (proste)

1. W Supabase Dashboard → **Authentication** → **Providers** → włącz `Email`
2. **Authentication** → **Users** → **Add user** → utwórz konta dla każdego admina
3. W `supabase-setup.sql` zamień policy:
   ```sql
   CREATE POLICY "all access perks" ON perks
     FOR ALL USING (auth.uid() IS NOT NULL) WITH CHECK (auth.uid() IS NOT NULL);
   ```
   (i analogicznie dla `categories`, `specializations`)
4. W `index.html` dorzuć ekran logowania – mogę dorzucić gotowy snippet, daj znać.

### Opcja B – tajny prefiks URL

Trzymaj edytor pod ścieżką `https://TWOJ_USER.github.io/repo/?key=ABCD...`
i sprawdzaj `URLSearchParams` przed inicjalizacją Supabase. Słabe, ale wystarczy
przeciw przypadkowym odwiedzającym.

### Opcja C – prywatne repo

GitHub Pages dla prywatnych repo jest dostępny w planie GitHub Pro
(za 4 USD/mies) lub Enterprise. URL Pages jest publiczny ale obfuskowany.

## Schemat bazy

| Tabela | Klucze | Zawiera |
|---|---|---|
| `categories` | PK: `id` | 4 wiersze (general/civ/crime/faction) z `levels[]` |
| `specializations` | PK: `(id, category_id)`, FK→categories | drzewka perków |
| `perks` | PK: `(id, spec_id, category_id)`, FK→specializations | pojedyncze perki |

Wszystkie kolumny `requiredPerks` i `levels` są typu `JSONB` – pełna elastyczność,
indeksowanie po polach JSON jeżeli kiedyś będzie potrzebne.

## Backup i przywracanie

### Eksport
Z poziomu UI: przycisk **Eksport** → JSON. Albo w Supabase Dashboard:
**Database** → **Backups** (codzienne, w darmowym tier 7 dni wstecz).

### Import
Z UI: **Import** → wklej JSON. Wywołuje atomowy RPC `replace_all_data` –
cała baza zastępowana w jednej transakcji.

## Limity Supabase Free Tier

- 500 MB bazy danych
- 2 GB transferu / mies (z lichwą wystarczy dla edytora)
- 200 jednoczesnych połączeń realtime
- Projekt zostaje zatrzymany po 7 dniach bezczynności (wystarczy raz wejść)

Dla tej aplikacji to ZNACZNIE więcej niż potrzeba.

## Troubleshooting

| Objaw | Diagnoza |
|---|---|
| W konsoli `supabase is not defined` | CDN się nie załadował – sprawdź sieć / adblocker |
| `Brak konfiguracji Supabase` | Zostawiłeś placeholdery `TWOJ-PROJEKT` w `APP_CONFIG` |
| `Invalid API key` w Network | Skopiowałeś niewłaściwy klucz – ma być **anon public**, nie service_role |
| Status `Błąd: ...row-level security...` | RLS jest włączone, ale brakuje polityk – uruchom ponownie `supabase-setup.sql` |
| Zmiany się zapisują, ale inni nie widzą natychmiast | Realtime nie włączony – sekcja w `supabase-setup.sql` powinna była to zrobić, sprawdź Dashboard → Database → Replication, czy `perks`, `specializations`, `categories` są tam zaznaczone |
| Zmiany w ogóle się nie zapisują | Otwórz F12 → Network, znajdź request do `*.supabase.co/rest/v1/perks`, zobacz odpowiedź |

## Dlaczego Supabase, a nie Firebase / Cloudflare D1 / inne

- **Supabase** = Postgres pod spodem. Znasz SQL z MariaDB – to działa identycznie.
- Realtime przez WebSocket out of the box, bez polling.
- Darmowy tier wystarcza z dużym zapasem.
- Otwarty kod, można self-hostować jak będzie taka potrzeba.
- JS SDK ma 30 KB, jeden script tag z CDN i działa.
