# MyBudget Backend

Backend API REST per **MyBudget**, applicazione di gestione budget/spese personali. Costruito con [Laravel](https://laravel.com) 12 e [Sanctum](https://laravel.com/docs/sanctum) per l'autenticazione a token, espone dati in JSON a un frontend [Angular](https://angular.dev) separato (repo `mybudget-frontend`).

## Cosa fa

- Gestione **conti** (`conti`), **movimenti** (`operazioni`) e **categorie** (`tags`), con relazione many-to-many operazioni↔tag
- **Multi-tenant**: ogni risorsa è isolata per utente tramite global scope automatico ([`App\Traits\BelongsToUser`](app/Traits/BelongsToUser.php))
- **Autenticazione** via Sanctum (register/login/me/logout/cambio password)
- **Statistiche e grafici**: filtri avanzati sulle operazioni, spese per tag, guadagni vs spese, andamento saldo

## Struttura API

Tutte le rotte applicative vivono sotto `/api/v1` (vedi [routes/api.php](routes/api.php)), protette da `auth:sanctum` tranne login/register. `routes/web.php` espone solo un endpoint di health-check: il backend non serve alcuna vista HTML.

## Setup locale

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Il frontend Angular gira separatamente (tipicamente su `localhost:4200`); `SANCTUM_STATEFUL_DOMAINS` e `SESSION_DOMAIN` in `.env` vanno configurati di conseguenza per l'autenticazione stateful da SPA.
