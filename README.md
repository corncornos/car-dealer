# Autoluxe Car Dealer Inventory

## Directory Structure

```
/                          ← Web root (all public PHP pages)
├── index.php              ← Entry point; redirects to login or dashboard
├── login.php              ← Login page
├── logout.php             ← Session destroy + redirect
├── dashboard.php          ← Main dashboard (reservations, viewings, priority)
├── vehicles.php           ← Vehicle inventory list
├── vehicle_add.php        ← Add new vehicle
├── vehicle_edit.php       ← Edit existing vehicle
├── vehicle_delete.php     ← Delete vehicle (POST action)
├── sales.php              ← Sales overview
├── sales_data.php         ← AJAX/included sales chart data
├── sale_mark.php          ← Mark vehicle as sold
├── sale_edit.php          ← Edit an existing sale record
├── customer_history.php   ← Customer list with transaction history
├── audit_trails.php       ← Admin-only audit log viewer
│
├── core/                  ← Shared backend files (not web-accessible directly)
│   ├── config.php         ← DB connection, CSRF helpers, validation functions
│   ├── header.php         ← HTML head, side navigation, session check helper
│   └── footer.php         ← Closing HTML tags
│
├── assets/
│   └── css/               ← All stylesheets
│       ├── styles.css             ← Global / layout styles
│       ├── vehicles.css           ← Inventory table styles
│       ├── vehicle_add_edit.css   ← Add/edit vehicle form styles
│       ├── sales.css              ← Sales page styles
│       ├── sale_mark.css          ← Mark-sold page styles
│       ├── audit_trails.css       ← Audit log styles
│       ├── login.css              ← Login page styles
│       └── customer_history.css   ← Customer history styles
│
└── database/
    ├── car_dealer.sql     ← Full schema dump (import to set up fresh DB)
    └── install.php        ← One-time setup script (creates DB, tables, admin users)
```

## Setup

1. Import `database/car_dealer.sql` into MySQL/MariaDB **or** run `database/install.php` once.
2. Edit `core/config.php` to set your DB credentials.
3. Point your web server root to this directory.
4. Open `http://localhost/` — you will be redirected to the login page.

## Default Credentials

| Email         | Password  | Role  |
|---------------|-----------|-------|
| admin@local   | admin123  | Admin |
| admin2@local  | admin456  | Admin |

> Change these immediately after first login.

## Key Rules

- Page files always `require_once __DIR__ . '/core/config.php'` for DB and helpers.
- Page files always `require __DIR__ . '/core/header.php'` and `require __DIR__ . '/core/footer.php'`.
- CSS is loaded via `assets/css/<name>.css`.
- `database/install.php` is a one-time tool — remove or protect it after setup.
