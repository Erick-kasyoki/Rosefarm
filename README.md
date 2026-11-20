# POS System (PHP + PostgreSQL + Vanilla JS) — XAMPP setup

> **Putting it on a server or the internet?** Read `DEPLOYMENT.md`: Docker or Ubuntu, HTTPS, backups, and a smoke test.

Set up for a shop selling **milk, mala, eggs, chicken, cereals, flours and cooking oil**.

## 1. Enable PostgreSQL in XAMPP's PHP
Open `C:\xampp\php\php.ini`, find and **remove the leading `;`** from:
```
extension=pdo_pgsql
extension=pgsql
```
Restart Apache from the XAMPP control panel.

## 2. Set your PostgreSQL password
Copy `config/config.local.php.example` to `config/config.local.php` and put your PostgreSQL password in it (change `user` / `port` if different; keep `dbname` as `pos_db`). Do not edit `config/config.php`: real passwords never go in it. The example file sets `'env' => 'development'`, which is what lets `setup.php` run on your own computer.

## 3. Create the database and tables (one click)
Copy the project folder to `C:\xampp\htdocs\pos`, start Apache, then open:

    http://localhost/pos/public/setup.php

It creates `pos_db` if needed, installs every table inside it and **creates your first admin login**: the page shows the username and a random password **once** (write it down). Safe to run again; it never overwrites data.
It starts with an empty product list. Press **Load shop products** on the Products page (or open `setup.php?seed=1`) for the milk, eggs, chicken, flour and oil starter list.
(Manual alternative: run `public/database/schema.sql` in a Query Tool connected to `pos_db`, then open `public/setup.php` to create the admin.)

## 4. Open the app

    http://localhost/pos/

## Logins
Only the admin creates accounts: managers and cashiers cannot sign themselves up (there is no public sign-up: the "Create account" form and its API endpoint have been removed). There are no built-in passwords any more. The first admin is created by `setup.php` (shown once) or by `php public/bin/install.php` on a server. Create the managers and cashiers under **Users**; everyone can change their own password from the user menu (top right).
Older databases that still contain `admin / admin123` or `cashier / cashier123` keep working, but those accounts are forced to choose a new password at their next sign-in.

## Selling loose goods (kg and litres)
- Every product has a **unit**. **kg** and **L** items can be sold in fractions (1.25 kg of chicken, 0.75 L of milk); **pc, tray, pack, dozen** items are sold whole (you cannot sell 1.5 eggs).
- In the cart, tap a loose item and type the weight or litres, or use **"or sell by amount"** to enter money instead (e.g. KES 100 of cooking oil gives 0.303 L). The server works the quantity out from the stored price.
- Stock is kept in the same unit, so a loose item's stock goes down by the weight/litres sold.
- The starter catalogue (26 items in 6 categories) uses example prices in KES: edit them under **Products**.
- Tax defaults to **0%** (Settings). Check with your accountant which of your products carry VAT; the POS applies one rate to the whole sale.

## Reports, expenses and roles
- **Reports** (Manager/Admin): Sales, Stock, Product, Expense and Profit & Loss. Pick a date range, and download any table as CSV (opens in Excel).
- **Expenses** (Manager/Admin): record rent, electricity, transport etc. with date, category, payment method; edit, delete, search. They feed the Expense report and Profit & Loss.
- **Profit & Loss** = net sales (after discounts, voids excluded) - cost of goods sold = gross profit; minus expenses = net profit or loss.
- **Roles**: *Cashier* sells and sees own sales. *Manager* also manages products, categories, expenses, reports, voids. *Admin* also manages users and settings.
- Users page shows created date, role, and lets an admin delete users (a user who has recorded sales can't be deleted: untick Active instead).

## Suppliers and stock management
- **Suppliers**: name, phone, email, address; search, edit, delete. Link each product to its supplier in Products.
- **Stock**: current stock for every product with status (In stock / Low / Out) and an **Add Stock** button. Enter the quantity received (decimals for kg / L) and, optionally, the new buying price (it becomes the product's cost). Every delivery is logged under *Recently received stock*.
- **Dashboard** now shows today's expenses and net profit, plus Quick Access tiles to each area.

## Customers and dashboard
- Customers have first name, last name, phone, email and address, with Search / Clear and a "Back to Dashboard" button. Existing customers are split into first/last name automatically on upgrade.
- The dashboard shows today's sales, sales count, products, products in stock, customers, suppliers, low / out of stock, expenses and net profit.

## Navigation and top bar
- The side menu is always full height and scrolls inside itself on short screens; the « button collapses it to an icon bar.
- A top bar in the same colours as the menu holds the live date/time, a **screen size** control (70% to 125%, handy on small monitors), the **day/night** switch, a **stock-alerts bell** (managers/admins) and your **user menu** with Log out. Settings are remembered per browser.

## Top-bar search
The search box next to the clock looks through the whole system: **pages and reports**, **products** (name, SKU, barcode, category, supplier), **customers**, **suppliers**, **categories**, **receipts** (number, customer, cashier, payment method, amount), **expenses** (description, category, amount, date), **stock deliveries** and **users**. Click a result, or use the arrow keys and Enter. Products are added to the current sale; the rest open the right page already filtered. Each person only sees results they are allowed to open (cashiers: products, customers and their own receipts).

## If you don't see the changes
1. Copy **everything** from the zip over your `htdocs/pos` folder (replace all files). The root `index.html` opens the app in `public/`.
2. Open the POS and press **Ctrl + F5** once. After that the browser always loads the newest version automatically.
3. Check the user menu (your name, top right): it shows a *Version* number that changes with every update.
4. An older database upgrades itself the first time you log in; no need to run `setup.php`.
5. An existing shop keeps its existing products. As admin, open **Products** and press **Load shop products** to add milk, mala, eggs, chicken, cereals, flours and cooking oil. Nothing already there is changed or removed.

## Upgrading an existing install
Copy the new files over the old ones and open `http://localhost/pos/public/setup.php` once. It adds the unit column and decimal quantities without touching your data.

## Features
- Animated login screen. There is no self sign-up; admins create every account under Users. "Remember me" remembers the username only, never the password.
- Login with roles (admin / manager / cashier), hashed passwords, PHP sessions, login throttling, change-password, forced change of weak passwords
- POS screen: product grid, category filter, search, barcode scan (type/scan + Enter), cart, discount, tax, customer, Cash / M-Pesa / Card, change calculation, printable receipt
- Sales saved in a database transaction with row locks; stock is deducted automatically and prices are re-read from the DB (never trusted from the browser)
- Sales history with date filter, reprint receipts, admin can void a sale (stock restored)
- Admin: dashboard (today/week/month, profit, 7-day chart, top products, low stock), products, categories, customers, users, store settings (name, currency, tax %, receipt footer)

## Troubleshooting
- "pdo_pgsql is not enabled" → step 1, then restart Apache.
- "tables are not installed yet" → open `http://localhost/pos/public/setup.php`.
- "Cannot reach the database" → check `config/config.local.php` and that the PostgreSQL service is running.
- Blank page / invalid response → open `http://localhost/pos/public/api/index.php?route=health` – it should return `{"ok":true}`; `?route=me` should return JSON too.
- `setup.php` says it is switched off → you are in production mode; create `config/config.local.php` (step 2) for local use, or run `php public/bin/install.php` on a server.
