# Winning Group Arena - Calendar Sync

Automaticky parsuje kalendar akci z webu a generuje ICS soubor.

**Zdroj:** https://www.winninggrouparena.cz/kalendar-akci/
**Misto:** Winning Group Arena, Brno
**Repository:** git@github.com:milous/rondo-akce.git

## Struktura projektu (PHP)

```
rondo-akce/
├── .github/workflows/sync-calendar.yml   # GitHub Actions (kazdych 6h)
├── src/
│   ├── Scraper.php                       # Parsovani webu
│   ├── IcsGenerator.php                  # Generovani ICS
│   └── EventStorage.php                  # Prace s JSON soubory
├── tests/
│   ├── ScraperTest.php                   # Testy scraperu
│   ├── EventStorageTest.php              # Testy ukladani
│   ├── IcsGeneratorTest.php              # Testy ICS generatoru
│   └── fixtures/                         # Testovaci HTML soubory
│       ├── calendar.html
│       └── event-detail.html
├── data/
│   └── events/
│       ├── 2026-01-02.json               # Akce na 2.1.2026
│       └── ...                           # Soubor se vytvori jen kdyz jsou akce
├── output/calendar.ics                   # Generovany kalendar (ze vsech JSON)
├── sync.php                              # Vstupni bod
├── phpunit.xml                           # PHPUnit konfigurace
└── composer.json                         # zavislosti + phpunit
```

## Implementace (PHP)

### Zavislosti (composer.json)
```json
{
  "require": {
    "php": ">=8.1",
    "symfony/dom-crawler": "^7.0",
    "symfony/css-selector": "^7.0",
    "eluceo/ical": "^2.0"
  }
}
```

### 1. Scraper (src/Scraper.php)
- Pouziva `symfony/dom-crawler` pro parsovani HTML

**Krok 1 - Kalendar (ziska seznam akci):**
- URL: `?viewmonth=X&viewyear=YYYY` (6 mesicu dopredu)
- Extrahuje pouze ODKAZY na akce (`/event/...`)
- Z kalendare se NEBERE datum ani cas!

**Krok 2 - Detail akce (ziska data):**
- Pro KAZDOU nalezenou akci stahne detailni stranku
- Z detailu parsuje VSE:
  - **Nazev** akce
  - **Datum** (format `D.M.YYYY`)
  - **Cas** (format `HH.MM` nebo `HH:MM`)
- Nektere akce maji VICE terminu na jedne strance → vytvori se vice zaznamu
- Parsovani: regex pro datum a cas v textu stranky
- **Fallback:** Pokud cas neni nalezen, pouzije se `19:00`

### 2. ICS Generator (src/IcsGenerator.php)
- Pouziva `eluceo/ical` knihovnu
- Kazdej event ma UID, DTSTART, DTEND, SUMMARY, LOCATION, URL
- Predpokladana delka akce: 3 hodiny
- Timezone: Europe/Prague
- **Zrusene akce:**
  - SUMMARY: `[ZRUSENO] Nazev akce`
  - STATUS: `CANCELLED` (standardni ICS atribut)

### 3. Event Storage (src/EventStorage.php)
- Uklada/nacita JSON soubory z `data/events/`
- **Logika synchronizace:**
  1. Nacte existujici JSON soubory
  2. Porovna s novymi daty z webu
  3. Nove akce = `status: active`
  4. Existujici akce na webu = zachova `status: active`
  5. Akce zmizela z webu a datum v budoucnosti = `status: cancelled` + `cancelled_at`
  6. Stare akce (datum v minulosti) = bez zmeny

### 4. JSON format (po dnech)

**Soubor: `data/events/2026-01-02.json`**
```json
{
  "date": "2026-01-02",
  "updated_at": "2026-01-23T10:00:00",
  "events": [
    {
      "id": "hc-kometa-brno-rytiri-kladno-14",
      "title": "HC Kometa Brno - Rytiri Kladno",
      "time": "18:00",
      "url": "https://www.winninggrouparena.cz/event/hc-kometa-brno-rytiri-kladno-14/",
      "status": "active"
    }
  ]
}
```

**Priklad zrusene akce:**
```json
{
  "date": "2026-01-15",
  "updated_at": "2026-01-23T10:00:00",
  "events": [
    {
      "id": "nejaka-akce",
      "title": "Nejaka akce",
      "time": "19:00",
      "url": "https://...",
      "status": "cancelled",
      "cancelled_at": "2026-01-20T10:00:00"
    }
  ]
}
```

**Logika statusu:**
- `active` = akce je na webu
- `cancelled` = akce byla v JSON, ale zmizela z webu (a datum je v budoucnosti)
- Soubor se NEMAZE, jen se zmeni status na `cancelled`
- V ICS se zobrazi jako `[ZRUSENO] Nazev akce`

**Dulezite:**
- Stare JSON soubory (minule datumy) se NEMAZI - zustavaji v git historii
- Akce s vice terminy (napr. Dracula 17.1. + 18.1.) = dva samostatne JSON soubory

**Edge cases:**
- **Akce zmeni datum** (17.1. → 20.1.): Stary zaznam (17.1.) = `cancelled`, novy (20.1.) = `active`
- **Chybi cas na detailu:** Pouzije se vychozi cas `19:00`
- **Akce zmeni nazev/cas:** Aktualizuje se v existujicim JSON souboru
- **Duplicity** (2 akce ve stejny den): Obe se ulozi do jednoho JSON souboru (pole `events`)
- **Ceske znaky:** UTF-8 encoding v JSON i ICS

### 5. GitHub Actions (.github/workflows/sync-calendar.yml)
```yaml
name: Sync Calendar
on:
  schedule:
    - cron: '0 */6 * * *'
  workflow_dispatch:
  push:
    branches: [main]
    paths: ['src/**', 'sync.php', 'composer.json']

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-dev
      - run: php sync.php
      - name: Commit changes
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "github-actions[bot]@users.noreply.github.com"
          git add data/ output/
          git diff --staged --quiet || git commit -m "chore: sync calendar"
          git push
```
- ICS dostupny na: `https://raw.githubusercontent.com/milous/rondo-akce/main/output/calendar.ics`

## Pouziti v mobilu

ICS soubor bude dostupny na:
```
https://raw.githubusercontent.com/milous/rondo-akce/main/output/calendar.ics
```

**Google Calendar:**
1. Otevrit Google Calendar na webu
2. Nastaveni > Pridat kalendar > Z URL adresy
3. Vlozit URL vys

**Apple Calendar (iPhone/Mac):**
1. Nastaveni > Kalendar > Ucty > Pridat ucet > Dalsi > Pridat odebirany kalendar
2. Vlozit URL

Kalendar se automaticky aktualizuje (Google ~12h, Apple ~1h).

## Overeni

1. Lokalne: `composer install && php sync.php`
2. Zkontrolovat `data/events/` - jsou tam JSON soubory pro dny s akcemi?
3. Zkontrolovat `output/calendar.ics` - validni ICS format?
4. Importovat ICS do kalendare
5. Pockat na dalsi sync a overit, ze commituje pouze zmeny

## Error handling

- **Web nedostupny:** Skript skonci s chybou, workflow selze → odesle se email
- **Zmena struktury HTML:** Akce se nenajdou, workflow selze → odesle se email
- **Zadne zmeny:** Workflow probehne uspesne, ale nevytvori commit

**Email notifikace pri selhani (volitelne):**
Konfigurace pres GitHub Secrets. Pokud NEJSOU vyplneny, email se NEODESLE.

Potrebne secrets:
- `SMTP_SERVER` - napr. smtp.gmail.com
- `SMTP_PORT` - napr. 465
- `SMTP_USERNAME` - email ucet
- `SMTP_PASSWORD` - heslo/app password
- `NOTIFICATION_EMAIL` - kam posilat notifikace

```yaml
- name: Send failure email
  if: failure() && secrets.SMTP_SERVER != ''
  uses: dawidd6/action-send-mail@v3
  with:
    server_address: ${{ secrets.SMTP_SERVER }}
    server_port: ${{ secrets.SMTP_PORT }}
    username: ${{ secrets.SMTP_USERNAME }}
    password: ${{ secrets.SMTP_PASSWORD }}
    subject: "Calendar sync failed"
    to: ${{ secrets.NOTIFICATION_EMAIL }}
    from: GitHub Actions
    body: "Synchronizace kalendare selhala. Zkontrolujte strukturu webu."
```

## Unit testy

### Zavislosti pro testy
```json
{
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  }
}
```

### Testovane komponenty

**1. Scraper testy (`tests/ScraperTest.php`):**
- Parsovani HTML s akcemi → spravne extrahuje odkazy
- Parsovani detailu → spravne extrahuje nazev, datum, cas
- Vice terminu na jedne strance → vsechny terminy
- Chybejici cas → fallback na 19:00
- Nevalidni HTML → graceful handling

**2. EventStorage testy (`tests/EventStorageTest.php`):**
- Nova akce → status: active
- Akce zmizela → status: cancelled
- Zmena data akce → stary cancelled, novy active
- Zmena nazvu/casu → aktualizace zaznamu
- Vice akci ve stejny den → obe v jednom souboru

**3. IcsGenerator testy (`tests/IcsGeneratorTest.php`):**
- Generovani ICS z JSON → validni format
- Zrusena akce → [ZRUSENO] prefix + STATUS: CANCELLED
- Spravny timezone (Europe/Prague)
- UTF-8 znaky v nazvu

### Spusteni testu
```bash
composer install
./vendor/bin/phpunit tests/
```

### GitHub Actions s testy
Testy se spusti PRED sync krokem:
```yaml
- run: ./vendor/bin/phpunit tests/
- run: php sync.php
```

## Naklady

$0 - GitHub Actions free tier (2000 min/mesic, toto spotrebuje ~10 min/den)
