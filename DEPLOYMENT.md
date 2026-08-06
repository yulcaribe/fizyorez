# FizyoRez Production Setup

Domain: https://www.yulcaribe.com

## 1. Create MySQL Database

In cPanel or your hosting panel:

1. Create a database named `fizyorez`.
2. Create a database user named `fizyorez`.
3. Give that user all privileges on the database.

cPanel usually adds an account prefix. If your cPanel username is `yulcarib`, the real values may look like:

- Database: `yulcarib_fizyorez`
- User: `yulcarib_fizyorez`
- Password: the password you choose

## 2. Update Config

Edit `config/config.php`:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'yulcarib_fizyorez',
    'user' => 'yulcarib_fizyorez',
    'pass' => 'YOUR_DATABASE_PASSWORD',
    'charset' => 'utf8mb4',
],
```

If the app is uploaded directly under `public_html`, keep:

```php
'base_path' => '',
```

If it is uploaded under a folder like `public_html/fizyorez`, use:

```php
'base_path' => '/fizyorez',
```

## 3. Import SQL

In phpMyAdmin, select the database and import these files in order:

1. `database/schema.sql`
2. `database/production-admin.sql`

## 4. Check Installation

Open:

```text
https://www.yulcaribe.com/health
```

Every row should show `OK`.

## 5. Login

Open:

```text
https://www.yulcaribe.com/login
```

Use the production admin email and temporary password, then create your real users from the admin panel.
