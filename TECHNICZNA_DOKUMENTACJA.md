# Dokumentacja Techniczna Pluginu Notification Centre

**Wersja:** 1.0.1 (Wersja deweloperska)
**Data ostatniej aktualizacji:** 2026-01-06

## 1. Architektura i Struktura

Plugin oparty jest na architekturze obiektowej. Główna klasa inicjuje komponenty odpowiedzialne za poszczególne obszary funkcjonalności.

### Struktura Plików
```
notification-centre/
├── notification-centre.php       # Główny plik pluginu, inicjalizacja, ładowanie assetów, zmienne CSS
├── assets/
│   ├── css/
│   │   ├── admin.css             # Style panelu administracyjnego
│   │   └── style.css             # Style front-end (Notification Center, Toast, TopBar)
│   └── js/
│       ├── admin.js              # Skrypty panelu administracyjnego (zarządzanie metaboxami)
│       └── main.js               # Główna logika front-end (pobieranie powiadomień, renderowanie, localStorage)
└── includes/
    ├── class-nc-post-type.php    # Rejestracja CPT 'nc_notification'
    ├── class-nc-metaboxes.php    # Obsługa metaboxów (pola edycji powiadomienia)
    ├── class-nc-settings.php     # Globalna strona ustawień pluginu
    ├── class-nc-logic.php        # Logika biznesowa (filtrowanie, przygotowanie danych dla API)
    ├── class-nc-rest-api.php     # (Zastąpione przez class-rest-api.php lub do usunięcia?)
    ├── class-rest-api.php        # Endpointy REST API
    └── class-nc-onesignal.php    # Placeholder pod przyszłą integrację OneSignal
```

### Główne Klasy
- **`Notification_Centre`**: Singleton. Zarządza cyklem życia pluginu, ładuje pliki językowe (do zrobienia), dołącza skrypty i style, generuje dynamiczne zmienne CSS (`:root`).
- **`NC_Post_Type`**: Rejestruje typ postu `nc_notification`.
- **`NC_Metaboxes`**: Tworzy interfejs edycji powiadomienia. Obsługuje logikę zależności pól (np. ukrywanie ustawień TopBar gdy wybrano Toast). Zapisuje metadane.
- **`NC_Settings`**: Rejestruje i renderuje stronę globalnych ustawień (kolory, zachowanie, wygląd dzwonka). Ustawia domyślne opcje.
- **`NC_Logic`**: "Mózg" pluginu. Zawiera funkcje pomocnicze do pobierania aktywnych powiadomień, sprawdzania warunków (daty, zalogowany user) i formatowania danych.

---

## 2. Funkcjonalności

### Custom Post Type (`nc_notification`)
- **Tytuł i Treść**: Standardowy edytor WP (uznany za treść powiadomienia).
- **Statusowa**: `publish`, `draft` etc.

### Metaboxy (Ustawienia Powiadomienia)
1. **Treść i Przycisk**:
   - URL przycisku CTA.
   - Etykieta przycisku.
   - Opcjonalne: Własne kolory (nadpisują globalne).
2. **Harmonogram i Odbiorcy**:
   - Data rozpoczęcia i zakończenia wyświetlania.
   - Odbiorcy: Wszyscy, Tylko Zalogowani, Tylko Wylogowani.
3. **Zachowanie**:
   - **Przypięte**: Zawsze na górze listy.
   - **Można zamknąć (X)**: Pozwala użytkownikowi usunąć powiadomienie (zapisuje ID w localStorage).
   - **Pokaż jako Toast**: Wyświetla dymek w rogu ekranu.
     - Opóźnienie (s).
     - Czas trwania (auto-hide).
   - **Top Bar**: Pasek na górze strony.
     - Sticky (przyklejony).
     - Pozycja (Nad/Pod headerem).
     - Rotacja (dla wielu TopBarów).
4. **Odliczanie (Countdown)**:
   - Typ: Do daty lub Codziennie (np. do 15:00).
   - Etykieta licznika.
   - Opcja włączenia/wyłączenia.

### Front-end (JS & CSS)
- **`main.js`**:
  - Pobiera dane z REST API (`/wp-json/nc/v1/notifications`).
  - Renderuje dzwonek oraz panel powiadomień (Drawer).
  - Obsługuje logikę "Przeczytane" (localStorage `nc_read_notifications`).
  - Renderuje Toast i TopBar.
  - Obsługuje liczniki czasu (Countdown).
- **Stylizacja**:
  - Oparta na zmiennych CSS (`var(--nc-...)`).
  - Pełna konfigurowalność kolorów z panelu admina.
  - Responsywność.
  - Badge "NOWE" dla nieprzeczytanych.

---

## 3. Roadmapa Rozwoju

Poniżej znajduje się plan rozwoju pluginu z oceną trudności (1-5, gdzie 1 = łatwe, 5 = bardzo trudne/czasochłonne).

### A. Wielojęzyczność (i18n)
Dostosowanie pluginu do standardów tłumaczeń WordPress (Loco Translate, WPML).
- **Działania**:
  - Owinięcie wszystkich stringów w funkcje `__()`, `_e()`, `esc_html__()`.
  - Wygenerowanie pliku `.pot`.
  - Załadowanie `load_plugin_textdomain` w głównej klasie.
  - W JS: użycie `wp.i18n` lub przekazywanie przetłumaczonych stringów przez `wp_localize_script`.
- **Trudność**: ⭐️⭐️ (2/5) - Żmudne, ale proste technicznie.
- **Priorytet**: Wysoki.

### B. Integracja z WooCommerce
Wyświetlanie powiadomień o zdarzeniach sklepowych.
- **Funkcje**:
  - Powiadomienie o zmianie statusu zamówienia (np. "W realizacji", "Wysłane").
  - Powiadomienie o porzuconym koszyku (wymaga crona).
  - Powiadomienie o nowym produkcie/promocji (można dodać automatyczne tworzenie postu `nc_notification` przy publikacji produktu).
- **Działania**:
  - Hooki pod akcje WooCommerce (`woocommerce_order_status_changed`, `woocommerce_new_product`).
  - Mechanizm powiadomień "tymczasowych" lub "użytkownika" (nie globalnych postów, ale dedykowanych dla User ID).
  - Rozbudowa bazy danych lub wykorzystanie usermeta do przechowywania powiadomień per user (np. o zamówieniu).
- **Trudność**: ⭐️⭐️⭐️⭐️ (4/5) - Wymaga logiki "Private Notification" (per user), czego obecnie nie ma (obecnie są globalne z filtrowaniem).

### C. Własny System Push (Web Push API)
Wysyłanie powiadomień przeglądarkowych nawet gdy user nie jest na stronie (Service Workers).
- **Działania**:
  - Implementacja Service Worker (`sw.js`).
  - Obsługa subskrypcji (`PushManager`).
  - Przechowywanie endpointów subskrypcji w bazie danych (powiązanie z User ID lub cookie).
  - Backend do wysyłania payloadu (VAPID keys).
- **Trudność**: ⭐️⭐️⭐️⭐️⭐️ (5/5) - Skomplikowane. Wymaga HTTPS, obsługi kluczy, Service Workers, UI do zgody na powiadomienia. Duża konkurencja (OneSignal robi to dobrze).
  - **Alternatywa**: Integracja z OneSignal (mamy już placeholder). Łatwiejsze (2/5).

### D. System "Logów" / Historii dla Użytkownika
Obecnie system opiera się na postach. Jeśli zmienimy treść posta, zmienia się u wszystkich. Brakuje historii "co dostałem miesiąc temu".
- **Pomysł**: Tabela w DB `wp_nc_logs` zapisująca wysłane powiadomienia do konkretnych userów.
- **Trudność**: ⭐️⭐️⭐️ (3/5).

---

## 4. Inspiracje i Pomysły (Inspiracja: WP Notification Bell)

Analizując konkurencję, warto rozważyć:

1.  **Ikony SVG**: Zamiast jednej, wybór z biblioteki ikon (FontAwesome/Dashicons) dla dzwonka i dla poszczególnych powiadomień.
    - *Trudność*: 2/5.
2.  **Dźwięki Powiadomień**: Odtwarzanie cichego dźwięku przy nadejściu nowego powiadomienia (Toast).
    - *Trudność*: 1/5.
3.  **Integracja z BuddyPress / bbPress**: Powiadomienia o wzmiankach, odpowiedziach na forum.
    - *Trudność*: 3/5 (Wymaga systemu powiadomień per user).
4.  **Reguły Wyświetlania (Conditional Logic)**:
    - Pokaż tylko na stronie X.
    - Pokaż jeśli user ma rolę Y.
    - Pokaż jeśli w koszyku jest produkt Z.
    - *Trudność*: 3/5 (Rozbudowa metaboxów i logiki `NC_Logic`).
5.  **Analityka**: Zliczanie kliknięć w powiadomienia (CTR).
    - *Trudność*: 2/5 (Prosty endpoint AJAX `hit_counter`).

## 5. Integracja z Wtyczkami Formularzy

### Problem: Formularze w Dynamicznie Ładowanej Treści

Gdy treść powiadomienia zawiera shortcode formularza (np. Fluent Forms, WPForms, Contact Form 7), a powiadomienie jest wyświetlane jako popup/toast, występuje problem z przeładowaniem strony przy próbie wysłania formularza.

#### Przyczyna Problemu

1. **innerHTML nie wykonuje skryptów**
   - Gdy HTML formularza jest wstawiany przez `innerHTML`, tagi `<script>` są parsowane ale **NIE wykonywane** (zabezpieczenie przeglądarek)
   - Fluent Forms wymaga zmiennych konfiguracyjnych typu `window.fluent_form_ff_form_instance_X_X`, które są normalnie tworzone przez inline `<script>`
   - Bez tych zmiennych, Fluent Forms nie rejestruje handlera submit przez AJAX

2. **Brak event delegation**
   - Wtyczki formularzy rejestrują handlery eventów podczas page load
   - Dynamicznie dodane formularze nie są automatycznie rozpoznawane
   - Formularz działa jak zwykły HTML form i powoduje przeładowanie strony

#### Rozwiązanie

Implementacja znajduje się w `assets/js/main.js` i składa się z trzech komponentów:

**1. Globalny Handler Submit (linie 57-103)**
```javascript
document.addEventListener('submit', function(e) {
    const form = e.target;

    // Sprawdź czy to Fluent Form w powiadomieniu
    if (form.classList.contains('frm-fluent-form')) {
        const notificationContainer = form.closest('.nc-floating, .nc-item, .nc-topbar-item');
        if (!notificationContainer) return; // Normalny formularz - pozwól działać

        const instanceId = form.getAttribute('data-form_instance');
        const specificVarName = 'fluent_form_' + instanceId;

        // Jeśli brakuje konfiguracji - zapobiegaj reload
        if (!window[specificVarName]) {
            e.preventDefault();
            e.stopPropagation();
            // Próba reinicjalizacji...
            return false;
        }

        // Konfiguracja istnieje - pozwól Fluent Forms obsłużyć AJAX
    }
}, true); // Capture phase - przechwytuj wcześnie!
```

**Kluczowe aspekty:**
- `addEventListener(..., true)` - **capture phase** przechwytuje event PRZED innymi handlerami
- Sprawdzanie kontekstu (`.closest()`) - tylko formularze wewnątrz powiadomień
- Warunkowe `preventDefault()` - blokuje reload tylko gdy konfiguracja brakuje

**2. Funkcja Inicjalizacji Fluent Forms (linie 112-244)**
```javascript
window.ncInitFluentForms = function (container) {
    // Znajdź wszystkie formularze Fluent Forms w kontenerze
    const forms = container.querySelectorAll('form.frm-fluent-form');

    forms.forEach(form => {
        // 1. Obsługa kolizji ID (gdy ten sam formularz w panelu i popup)
        if (document.querySelectorAll('#' + form.id).length > 1) {
            // Generuj unikalny ID
            const newInstanceId = instanceId + '_' + Math.random().toString(36).substr(2, 9);
            form.setAttribute('data-form_instance', newInstanceId);
            form.id = newFormId;
        }

        // 2. Rekonstrukcja konfiguracji (innerHTML problem)
        // Pobierz generyczny model: window.fluent_form_model_2
        const genericConfig = window['fluent_form_model_' + formId];
        if (genericConfig) {
            // Utwórz specyficzną konfigurację
            const config = JSON.parse(JSON.stringify(genericConfig));
            config.form_instance = currentInstanceId;
            window['fluent_form_' + currentInstanceId] = config;
        }

        // 3. Inicjalizacja przez API Fluent Forms
        setTimeout(() => {
            if (typeof window.fluentFormApp === 'function') {
                const formApp = window.fluentFormApp($form);
                if (formApp) {
                    formApp.initFormHandlers(); // Rejestruje submit handler
                    formApp.initTriggers(); // Inicjalizuje warunki
                }
            }

            // Fallback: event ff_reinit
            jQuery(document).trigger('ff_reinit', [$form]);
        }, 100);
    });
};
```

**3. Wywołanie po Dodaniu Formularza do DOM (linia 788-790)**
```javascript
// Po dodaniu HTML do DOM
container.appendChild(el);

// KRYTYCZNE: Inicjalizacja ПОСЛЕ dodania do DOM
if (typeof window.ncInitFluentForms === 'function') {
    window.ncInitFluentForms(el);
}
```

#### Uniwersalność Rozwiązania

Kod został zaprojektowany z myślą o rozszerzeniu na inne wtyczki formularzy:

```javascript
// W funkcji ncInitFluentForms można dodać:

// Fluent Forms
if (container.querySelectorAll('form.frm-fluent-form').length > 0) {
    // Logika Fluent Forms...
}

// WPForms (przykład do implementacji)
if (container.querySelectorAll('form.wpforms-form').length > 0) {
    // Logika WPForms...
}

// Contact Form 7 (przykład do implementacji)
if (container.querySelectorAll('form.wpcf7-form').length > 0) {
    // Logika CF7...
}

// Gravity Forms (przykład do implementacji)
if (container.querySelectorAll('form.gform_wrapper').length > 0) {
    // Logika GF...
}
```

#### Kluczowe Zasady

1. **Zawsze inicjalizuj formularze PO dodaniu do DOM** - wtyczki formularzy potrzebują dostępu do pełnej struktury DOM
2. **Używaj capture phase** - `addEventListener(..., true)` pozwala przechwycić event wcześnie
3. **Sprawdzaj kontekst** - nie interferuj z normalnymi formularzami poza powiadomieniami
4. **Wielowarstwowa inicjalizacja** - używaj wielu metod (API wtyczki, eventy, flagi) dla maksymalnej kompatybilności
5. **Obsługuj kolizje ID** - ten sam formularz może być wyświetlony w wielu miejscach jednocześnie

#### Debugowanie

W trybie debug (`ncData.debugMode = true`) sprawdzaj logi konsoli:
```
NC: Form submit detected for ff_form_instance_2_1 inside notification
NC: Config exists for ff_form_instance_2_1, letting Fluent Forms handle AJAX submission
NC: Created Fluent Form config for ff_form_instance_2_1 from fluent_form_model_2
NC: Initialized form handlers for ff_form_instance_2_1
```

Jeśli widzisz:
```
NC: Fluent Form config fluent_form_ff_form_instance_2_1 is missing! Preventing page reload.
```
Oznacza to, że inicjalizacja się nie powiodła - sprawdź czy:
- `window.fluent_form_model_X` istnieje (gdzie X to ID formularza)
- Funkcja `ncInitFluentForms` została wywołana PO dodaniu do DOM
- jQuery jest dostępne

---

## 6. Podsumowanie Stanu Obecnego

Mamy solidną bazę:
- ✅ Działający system CPT.
- ✅ Zaawansowane ustawienia wyglądu (Globalne + Per Powiadomienie).
- ✅ Dzwonek, Drawer, Toast i TopBar.
- ✅ Odliczanie (Countdown).
- ✅ Badge "Nowe".
- ✅ Responsywność.
- ✅ Czysty kod JS (Modułowy, bez jQuery).
- ✅ **Obsługa formularzy w dynamicznej treści (Fluent Forms)**.

**Rekomendowany Następny Krok**: Wdrożenie **Wielojęzyczności (Loco Translate)**, ponieważ jest to podstawa dla pluginu, który ma być używany szerzej lub sprzedawany. Następnie proste reguły **Display Conditions** (na jakich stronach pokazać).
