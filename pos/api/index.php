<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
send_security_headers();

// ---------- helpers ----------
function out($data, int $code = 200) { http_response_code($code); echo json_encode($data); exit; }
function body(): array {
    static $b = null;
    if ($b === null) {
        $raw = (string)file_get_contents('php://input', false, null, 0, 1048577);   // 1 MB is far more than any form needs
        if (strlen($raw) > 1048576) throw new ApiError('Request is too large', 413);
        $b = json_decode($raw, true);
        if (!is_array($b)) $b = [];
    }
    return $b;
}
function bool_str($v): string { return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on') ? 'true' : 'false'; }
function nul($v) { $v = is_string($v) ? trim($v) : $v; return ($v === '' || $v === null) ? null : $v; }

// Units that can be sold in fractions (1.25 kg, 0.5 L). Everything else (pc, tray, pack...) is sold whole.
const DECIMAL_UNITS = ['kg', 'L'];
function is_decimal_unit(string $u): bool { return in_array($u, DECIMAL_UNITS, true); }
function clean_unit($v): string {
    $u = trim((string)$v);
    if ($u === '') return 'pc';
    if (strlen($u) > 20) throw new ApiError('Unit is too long (max 20 characters)');
    return strtolower($u) === 'l' ? 'L' : (strtolower($u) === 'kg' ? 'kg' : $u);
}
function num3($v): float { return round((float)$v, 3); }

function me(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u = q('SELECT id, username, full_name, role FROM users WHERE id = ? AND active', [$_SESSION['uid']])->fetch();
    return $u ?: null;
}
function need_login(bool $allowPending = false): array {
    $u = me();
    if (!$u) throw new ApiError('Please log in', 401);
    if (!$allowPending && !empty($_SESSION['must_change'])) throw new ApiError('Please set a new password before continuing.', 403);
    return $u;
}
function is_manager(array $u): bool { return in_array($u['role'], ['admin', 'manager'], true); }
function need_manager(): array { $u = need_login(); if (!is_manager($u)) throw new ApiError('Manager access required', 403); return $u; }
function clean_role($v): string { return in_array($v, ['admin', 'manager'], true) ? $v : 'cashier'; }
function need_admin(): array { $u = need_login(); if ($u['role'] !== 'admin') throw new ApiError('Admin access required', 403); return $u; }

function settings(): array {
    $o = [];
    foreach (q("SELECT name, value FROM settings WHERE name <> 'schema_version'")->fetchAll() as $r) $o[$r['name']] = $r['value'];
    return $o;
}

function dup_guard(callable $fn, string $msg) {
    try { return $fn(); }
    catch (PDOException $e) {
        if ($e->getCode() === '23505') throw new ApiError($msg, 409);
        throw $e;
    }
}

function get_sale(int $id): array {
    $s = q('SELECT s.*, u.full_name AS cashier, c.name AS customer_name
            FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id
            WHERE s.id = ?', [$id])->fetch();
    if (!$s) throw new ApiError('Sale not found', 404);
    $s['items'] = q('SELECT name, qty::float AS qty, unit, price, line_total FROM sale_items WHERE sale_id = ? ORDER BY id', [$id])->fetchAll();
    return $s;
}

// ---------- handlers ----------
// ---------- passwords and throttling ----------
const WEAK_PASSWORDS = ['admin123', 'cashier123', 'password', 'password1', '12345678', '123456789', 'qwerty123', 'letmein123'];
function pw_check(string $pw, string $username = ''): void {
    if (strlen($pw) < 8) throw new ApiError('Password must be at least 8 characters');
    if (strlen($pw) > 200) throw new ApiError('Password is too long');
    if (in_array(strtolower($pw), WEAK_PASSWORDS, true) || ($username !== '' && strtolower($pw) === strtolower($username)))
        throw new ApiError('That password is too easy to guess. Choose a different one.');
}
function username_check(string $un): void {
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $un)) throw new ApiError('Username must be 3-50 characters: letters, numbers, dot, dash or underscore');
}
function attempts(string $kind, string $ip, ?string $name, int $minutes): int {
    $sql = "SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND ip = ? AND created_at > NOW() - INTERVAL '$minutes minutes'";
    $p = [$kind, $ip];
    if ($name !== null) { $sql .= ' AND username = ?'; $p[] = $name; }
    return (int)q($sql, $p)->fetchColumn();
}
function log_attempt(string $kind, string $ip, string $name = ''): void {
    q('INSERT INTO login_attempts (ip, username, kind) VALUES (?,?,?)', [$ip, substr(strtolower($name), 0, 50), $kind]);
    if (random_int(1, 20) === 1) q("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL '2 days'");
}
function user_payload(array $u): array {
    return ['id' => $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'must_change' => !empty($_SESSION['must_change'])];
}

function login() {
    $b = body();
    $name = trim((string)($b['username'] ?? ''));
    $pw = (string)($b['password'] ?? '');
    $ip = client_ip();
    // 5 wrong tries for the same account from one place, or 30 from one place, pauses logins for 15 minutes.
    if (attempts('login', $ip, strtolower($name), 15) >= 5 || attempts('login', $ip, null, 15) >= 30)
        throw new ApiError('Too many failed attempts. Please wait 15 minutes and try again.', 429);
    $u = q('SELECT * FROM users WHERE LOWER(username) = LOWER(?) ORDER BY (username = ?) DESC LIMIT 1', [$name, $name])->fetch();
    // Always run one password check so response time does not reveal whether a username exists.
    $hash = $u['password_hash'] ?? '$2y$10$HEFeB7dpq9IEdPnfmFkssuceM/TILZ1VMVpFUceXbaujNWuFbIIrq';
    $ok = password_verify($pw, $hash) && $u;
    if (!$ok) { log_attempt('login', $ip, $name); throw new ApiError('Invalid username or password', 401); }
    if (!in_array($u['active'], [true, 't', 'true', '1', 1], true)) throw new ApiError('Your account is waiting for an admin to activate it.', 403);
    q("DELETE FROM login_attempts WHERE kind = 'login' AND ip = ? AND username = ?", [$ip, strtolower($name)]);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    $_SESSION['last'] = time();
    // Accounts still using a shipped/guessable password must choose a new one before doing anything else.
    if (in_array(strtolower($pw), WEAK_PASSWORDS, true) || strtolower($pw) === strtolower($u['username'])) $_SESSION['must_change'] = 1;
    else unset($_SESSION['must_change']);
    out(['user' => user_payload($u), 'settings' => settings()]);
}

function change_password() {
    $u = need_login(true);
    $b = body();
    $cur = (string)($b['current_password'] ?? ''); $new = (string)($b['new_password'] ?? '');
    $ip = client_ip();
    if (attempts('login', $ip, strtolower($u['username']), 15) >= 5) throw new ApiError('Too many failed attempts. Please wait 15 minutes and try again.', 429);
    $row = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetch();
    if (!$row || !password_verify($cur, $row['password_hash'])) { log_attempt('login', $ip, $u['username']); throw new ApiError('Your current password is not correct', 400); }
    if ($new === $cur) throw new ApiError('The new password must be different from the current one');
    pw_check($new, $u['username']);
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    unset($_SESSION['must_change']);
    session_regenerate_id(true);
    out(['ok' => true]);
}

function categories_save($m, $id) {
    need_manager();
    $name = trim(body()['name'] ?? '');
    if ($name === '') throw new ApiError('Name is required');
    dup_guard(function () use ($m, $id, $name) {
        if ($m === 'POST') q('INSERT INTO categories (name) VALUES (?)', [$name]);
        else q('UPDATE categories SET name = ? WHERE id = ?', [$name, $id]);
    }, 'Category already exists');
    out(['ok' => true]);
}

function product_fields(): array {
    $b = body();
    $name = trim($b['name'] ?? ''); $sku = trim($b['sku'] ?? '');
    if ($name === '' || $sku === '') throw new ApiError('SKU and name are required');
    $price = (float)($b['price'] ?? 0); $cost = (float)($b['cost'] ?? 0);
    if ($price < 0 || $cost < 0) throw new ApiError('Price and cost cannot be negative');
    $unit = clean_unit($b['unit'] ?? 'pc');
    $stock = num3($b['stock'] ?? 0); $rl = num3($b['reorder_level'] ?? 5);
    if (!is_decimal_unit($unit)) { $stock = round($stock); $rl = round($rl); }   // whole units only
    return [$sku, nul($b['barcode'] ?? null), $name, nul($b['category_id'] ?? null), nul($b['supplier_id'] ?? null), $unit, $price, $cost,
            $stock, $rl, bool_str($b['active'] ?? true)];
}

function create_sale(array $u) {
    $b = body();
    $raw = $b['items'] ?? [];
    if (!$raw) throw new ApiError('Cart is empty');
    $pm = $b['payment_method'] ?? 'cash';
    if (!in_array($pm, ['cash', 'card', 'mpesa'], true)) throw new ApiError('Invalid payment method');

    // Merge repeated products. An item may carry either a quantity, or (for kg / L items) an
    // amount of money, e.g. "KES 100 of cooking oil"; the quantity is then worked out from the DB price.
    $merged = [];
    foreach ($raw as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid < 1) throw new ApiError('Invalid cart item');
        $merged[$pid][] = $it;
    }
    ksort($merged); // consistent lock order avoids deadlocks

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $subtotal = 0.0; $lines = [];
        foreach ($merged as $pid => $items) {
            $p = q('SELECT id, name, unit, price, cost, stock::float AS stock FROM products WHERE id = ? AND active FOR UPDATE', [$pid])->fetch();
            if (!$p) throw new ApiError("Product #$pid not found or inactive");
            $dec = is_decimal_unit($p['unit']);
            $price = (float)$p['price'];
            $qty = 0.0; $line = 0.0;
            foreach ($items as $it) {
                if ($dec && isset($it['amount']) && (float)$it['amount'] > 0 && $price > 0) {
                    $amt = round((float)$it['amount'], 2);
                    $q1 = round($amt / $price, 3);
                    if ($q1 <= 0) throw new ApiError("Amount is too small for {$p['name']}");
                    $qty += $q1; $line += $amt;
                } else {
                    $q1 = num3($it['qty'] ?? 0);
                    if ($q1 <= 0) throw new ApiError("Invalid quantity for {$p['name']}");
                    if (!$dec && abs($q1 - round($q1)) > 1e-9) throw new ApiError("{$p['name']} is sold in whole {$p['unit']} only");
                    $qty += $q1; $line += round($price * $q1, 2);
                }
            }
            $qty = round($qty, 3); $line = round($line, 2);
            if ($qty <= 0) throw new ApiError("Invalid quantity for {$p['name']}");
            if ($p['stock'] + 1e-9 < $qty) {
                $left = rtrim(rtrim(number_format($p['stock'], 3, '.', ''), '0'), '.');
                throw new ApiError("Insufficient stock for {$p['name']} (only $left {$p['unit']} left)", 409);
            }
            $subtotal += $line;
            $lines[] = [$p, $qty, $line];
        }
        $subtotal = round($subtotal, 2);
        $discount = max(0, min((float)($b['discount'] ?? 0), $subtotal));
        $rate = (float)(settings()['tax_rate'] ?? 0);
        $tax = round(($subtotal - $discount) * $rate / 100, 2);
        $total = round($subtotal - $discount + $tax, 2);
        $paid = $pm === 'cash' ? (float)($b['amount_paid'] ?? 0) : $total;
        if ($paid + 0.001 < $total) throw new ApiError('Amount paid is less than the total');
        $change = round($paid - $total, 2);

        $seq = (int)q("SELECT nextval('receipt_seq')")->fetchColumn();
        $receipt = 'RC' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
        $sid = (int)q('INSERT INTO sales (receipt_no, user_id, customer_id, subtotal, discount, tax, total, payment_method, amount_paid, change_due)
                       VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
            [$receipt, $u['id'], nul($b['customer_id'] ?? null), $subtotal, $discount, $tax, $total, $pm, $paid, $change])->fetchColumn();

        foreach ($lines as [$p, $qty, $line]) {
            q('INSERT INTO sale_items (sale_id, product_id, name, unit, qty, price, cost, line_total) VALUES (?,?,?,?,?,?,?,?)',
                [$sid, $p['id'], $p['name'], $p['unit'], $qty, $p['price'], $p['cost'], $line]);
            q('UPDATE products SET stock = stock - ? WHERE id = ?', [$qty, $p['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    out(get_sale($sid), 201);
}

function void_sale(int $id) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = q('SELECT status FROM sales WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$s) throw new ApiError('Sale not found', 404);
        if ($s['status'] === 'voided') throw new ApiError('Sale is already voided');
        foreach (q('SELECT product_id, qty FROM sale_items WHERE sale_id = ?', [$id])->fetchAll() as $it) {
            if ($it['product_id']) q('UPDATE products SET stock = stock + ? WHERE id = ?', [$it['qty'], $it['product_id']]);
        }
        q("UPDATE sales SET status = 'voided' WHERE id = ?", [$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    out(get_sale($id));
}

function report() {
    $sum = function (string $cond) {
        $r = q("SELECT COALESCE(SUM(total),0) AS total, COUNT(*) AS count FROM sales WHERE status='completed' AND $cond")->fetch();
        return ['total' => (float)$r['total'], 'count' => (int)$r['count']];
    };
    $profit = (float)q("SELECT COALESCE(SUM(si.line_total - si.cost * si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                        WHERE s.status='completed' AND s.created_at::date = CURRENT_DATE")->fetchColumn();
    $exp_today = (float)q('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURRENT_DATE')->fetchColumn();
    out([
        'counts' => q("SELECT (SELECT COUNT(*) FROM customers)::int AS customers, (SELECT COUNT(*) FROM suppliers)::int AS suppliers,
                              (SELECT COUNT(*) FROM products WHERE active)::int AS products,
                              (SELECT COUNT(*) FROM products WHERE active AND stock > 0)::int AS in_stock,
                              (SELECT COUNT(*) FROM products WHERE active AND stock > 0 AND stock <= reorder_level)::int AS low,
                              (SELECT COUNT(*) FROM products WHERE active AND stock <= 0)::int AS out")->fetch(),
        'expenses_today' => $exp_today,
        'net_profit_today' => round($profit - $exp_today, 2),
        'today' => $sum('created_at::date = CURRENT_DATE'),
        'week'  => $sum("created_at >= date_trunc('week', NOW())"),
        'month' => $sum("created_at >= date_trunc('month', NOW())"),
        'profit_today' => $profit,
        'daily' => q("SELECT to_char(d, 'Dy DD') AS label, COALESCE(SUM(s.total),0)::float AS total
                      FROM generate_series(CURRENT_DATE - 6, CURRENT_DATE, '1 day') d
                      LEFT JOIN sales s ON s.created_at::date = d::date AND s.status='completed'
                      GROUP BY d ORDER BY d")->fetchAll(),
        'top' => q("SELECT si.name, MAX(si.unit) AS unit, SUM(si.qty)::float AS qty, SUM(si.line_total)::float AS revenue
                    FROM sale_items si JOIN sales s ON s.id = si.sale_id
                    WHERE s.status='completed' AND s.created_at >= NOW() - INTERVAL '30 days'
                    GROUP BY si.name ORDER BY qty DESC LIMIT 5")->fetchAll(),
        'low_stock' => q('SELECT id, name, sku, unit, stock::float AS stock, reorder_level::float AS reorder_level FROM products WHERE active AND stock <= reorder_level ORDER BY stock LIMIT 20')->fetchAll(),
        'by_payment' => q("SELECT payment_method, COUNT(*)::int AS count, SUM(total)::float AS total FROM sales
                           WHERE status='completed' AND created_at::date = CURRENT_DATE GROUP BY payment_method")->fetchAll(),
    ]);
}

function stock_add(array $u) {
    $b = body();
    $pid = (int)($b['product_id'] ?? 0);
    $qty = num3($b['qty'] ?? 0);
    if ($qty <= 0) throw new ApiError('Enter a quantity greater than zero');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $p = q('SELECT p.id, p.name, p.unit, p.cost, p.supplier_id, sp.name AS supplier FROM products p LEFT JOIN suppliers sp ON sp.id = p.supplier_id WHERE p.id = ? FOR UPDATE OF p', [$pid])->fetch();
        if (!$p) throw new ApiError('Product not found', 404);
        if (!is_decimal_unit($p['unit']) && abs($qty - round($qty)) > 1e-9) throw new ApiError("{$p['name']} is counted in whole {$p['unit']} only");
        $cost = round((float)($b['cost'] ?? 0), 2); if ($cost < 0) throw new ApiError('Cost cannot be negative');
        if ($cost > 0) q('UPDATE products SET stock = stock + ?, cost = ? WHERE id = ?', [$qty, $cost, $pid]);   // latest buying price becomes the cost
        else { q('UPDATE products SET stock = stock + ? WHERE id = ?', [$qty, $pid]); $cost = (float)$p['cost']; }
        q('INSERT INTO stock_receipts (product_id, product_name, qty, cost, supplier_id, supplier_name, note, user_id) VALUES (?,?,?,?,?,?,?,?)',
            [$pid, $p['name'], $qty, $cost, $p['supplier_id'], $p['supplier'], mb_substr(trim($b['note'] ?? ''), 0, 255) ?: null, $u['id']]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    out(['ok' => true, 'stock' => (float)q('SELECT stock FROM products WHERE id = ?', [$pid])->fetchColumn()], 201);
}

// Searches (almost) everything the signed-in user is allowed to see. Plain substring match, case-insensitive.
function search_all(array $u) {
    $t = mb_strtolower(trim($_GET['q'] ?? '')); if ($t === '') out([]);
    $mgr = is_manager($u); $adm = $u['role'] === 'admin';
    $m = function (array $cols) { return '(' . implode(' OR ', array_map(fn($c) => "POSITION(? IN LOWER(COALESCE($c::text,''))) > 0", $cols)) . ')'; };
    $ps = fn(int $n) => array_fill(0, $n, $t);
    $r = [];
    $c = ['p.name', 'p.sku', 'p.barcode', 'c.name', 'sp.name'];
    $r['products'] = array_map(function ($x) { $x['stock'] = (float)$x['stock']; return $x; },
        q('SELECT p.id, p.name, p.sku, p.unit, p.price, p.stock::float AS stock, c.name AS category FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers sp ON sp.id = p.supplier_id
           WHERE p.active AND ' . $m($c) . ' ORDER BY p.name LIMIT 6', $ps(5))->fetchAll());
    $r['customers'] = q('SELECT id, name, phone, address FROM customers WHERE ' . $m(['name', 'phone', 'email', 'address']) . ' ORDER BY name LIMIT 5', $ps(4))->fetchAll();
    $sp = $ps(5); $sw = ''; if (!$mgr) { $sw = ' AND s.user_id = ?'; $sp[] = $u['id']; }
    $r['sales'] = q('SELECT s.id, s.receipt_no, s.total, s.status, s.payment_method, s.created_at, cu.name AS customer FROM sales s JOIN users us ON us.id = s.user_id LEFT JOIN customers cu ON cu.id = s.customer_id
           WHERE ' . $m(['s.receipt_no', 'cu.name', 'us.full_name', 's.payment_method', 's.total']) . $sw . ' ORDER BY s.id DESC LIMIT 6', $sp)->fetchAll();
    if ($mgr) {
        $r['suppliers']  = q('SELECT id, name, phone FROM suppliers WHERE ' . $m(['name', 'phone', 'email', 'address']) . ' ORDER BY name LIMIT 5', $ps(4))->fetchAll();
        $r['categories'] = q('SELECT id, name FROM categories WHERE ' . $m(['name']) . ' ORDER BY name LIMIT 4', $ps(1))->fetchAll();
        $r['expenses']   = q('SELECT id, expense_date::text AS expense_date, category, description, amount::float AS amount FROM expenses WHERE ' . $m(['description', 'category', 'amount', 'expense_date', 'payment_method']) . ' ORDER BY expense_date DESC LIMIT 6', $ps(5))->fetchAll();
        $r['deliveries'] = q('SELECT id, created_at, product_name, qty::float AS qty, supplier_name FROM stock_receipts WHERE ' . $m(['product_name', 'supplier_name', 'note']) . ' ORDER BY id DESC LIMIT 5', $ps(3))->fetchAll();
    }
    if ($adm) $r['users'] = q('SELECT id, username, full_name, role FROM users WHERE ' . $m(['username', 'full_name', 'role']) . ' ORDER BY username LIMIT 5', $ps(3))->fetchAll();
    out($r);
}

function date_range(bool $defaults = true): array {
    $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
    if (!$ok($from)) $from = $defaults ? date('Y-m-01') : null;
    if (!$ok($to))   $to   = $defaults ? date('Y-m-d') : null;
    return [$from, $to];
}

function expense_fields(): array {
    $b = body();
    $cat = trim($b['category'] ?? ''); if ($cat === '') throw new ApiError('Category is required');
    $amt = round((float)($b['amount'] ?? 0), 2); if ($amt <= 0) throw new ApiError('Amount must be greater than zero');
    $pm = $b['payment_method'] ?? 'cash'; if (!in_array($pm, ['cash', 'bank', 'mpesa'], true)) throw new ApiError('Invalid payment method');
    $date = $b['expense_date'] ?? ''; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) $date = date('Y-m-d');
    return [$date, mb_substr($cat, 0, 60), mb_substr(trim($b['description'] ?? ''), 0, 255), $amt, $pm];
}

function rpt() {
    $type = $_GET['type'] ?? '';
    [$from, $to] = date_range();
    $sd = "s.status = 'completed' AND s.created_at >= ?::date AND s.created_at < (?::date + 1)";
    $rg = [$from, $to];
    $exp = fn() => (float)q('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ?::date AND ?::date', $rg)->fetchColumn();

    switch ($type) {
        case 'sales':
            $rows = q("SELECT s.id, s.receipt_no, s.created_at, u.full_name AS cashier, c.name AS customer_name, s.subtotal::float AS subtotal, s.discount::float AS discount,
                              s.tax::float AS tax, s.total::float AS total, s.payment_method, s.status
                       FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id
                       WHERE s.created_at >= ?::date AND s.created_at < (?::date + 1) ORDER BY s.id DESC LIMIT 3000", $rg)->fetchAll();
            $sum = q("SELECT COUNT(*)::int AS count, COALESCE(SUM(subtotal),0)::float AS subtotal, COALESCE(SUM(discount),0)::float AS discount,
                             COALESCE(SUM(tax),0)::float AS tax, COALESCE(SUM(total),0)::float AS total FROM sales s WHERE $sd", $rg)->fetch();
            $voided = (int)q("SELECT COUNT(*) FROM sales s WHERE s.status = 'voided' AND s.created_at >= ?::date AND s.created_at < (?::date + 1)", $rg)->fetchColumn();
            $by = q("SELECT payment_method, COUNT(*)::int AS count, SUM(total)::float AS total FROM sales s WHERE $sd GROUP BY payment_method ORDER BY total DESC", $rg)->fetchAll();
            out(['from' => $from, 'to' => $to, 'rows' => $rows, 'summary' => $sum + ['voided' => $voided], 'by_payment' => $by]);

        case 'stock':
            $rows = q("SELECT p.sku, p.name, COALESCE(c.name,'') AS category, p.unit, p.stock::float AS stock, p.reorder_level::float AS reorder_level,
                              p.cost::float AS cost, p.price::float AS price, (p.stock * p.cost)::float AS stock_value, (p.stock * p.price)::float AS retail_value,
                              CASE WHEN p.stock <= 0 THEN 'Out of stock' WHEN p.stock <= p.reorder_level THEN 'Low stock' ELSE 'In stock' END AS status
                       FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.active ORDER BY p.name")->fetchAll();
            $t = ['items' => count($rows), 'stock_value' => 0.0, 'retail_value' => 0.0, 'low' => 0, 'out' => 0];
            foreach ($rows as $r) { $t['stock_value'] += $r['stock_value']; $t['retail_value'] += $r['retail_value'];
                if ($r['status'] === 'Low stock') $t['low']++; if ($r['status'] === 'Out of stock') $t['out']++; }
            out(['rows' => $rows, 'summary' => $t]);

        case 'product':
            $rows = q("SELECT p.sku, p.name, COALESCE(c.name,'') AS category, p.unit, p.price::float AS price, p.cost::float AS cost,
                              CASE WHEN p.price > 0 THEN ROUND(((p.price - p.cost) / p.price * 100)::numeric, 1) ELSE 0 END::float AS margin_pct,
                              p.stock::float AS stock, (p.stock * p.cost)::float AS stock_value,
                              COALESCE(x.qty,0)::float AS qty_sold, COALESCE(x.revenue,0)::float AS revenue,
                              CASE WHEN p.stock <= 0 THEN 'Out of stock' WHEN p.stock <= p.reorder_level THEN 'Low stock' ELSE 'In stock' END AS status
                       FROM products p LEFT JOIN categories c ON c.id = p.category_id
                       LEFT JOIN (SELECT si.product_id, SUM(si.qty) AS qty, SUM(si.line_total) AS revenue FROM sale_items si JOIN sales s ON s.id = si.sale_id
                                  WHERE $sd GROUP BY si.product_id) x ON x.product_id = p.id
                       WHERE p.active ORDER BY x.revenue DESC NULLS LAST, p.name", $rg)->fetchAll();
            out(['from' => $from, 'to' => $to, 'rows' => $rows]);

        case 'expense':
            $rows = q("SELECT e.id, e.expense_date::text AS expense_date, e.category, e.description, e.amount::float AS amount, e.payment_method, u.full_name AS recorded_by
                       FROM expenses e LEFT JOIN users u ON u.id = e.user_id WHERE e.expense_date BETWEEN ?::date AND ?::date ORDER BY e.expense_date DESC, e.id DESC", $rg)->fetchAll();
            out(['from' => $from, 'to' => $to, 'rows' => $rows, 'total' => $exp(),
                 'by_category' => q('SELECT category, COUNT(*)::int AS count, SUM(amount)::float AS total FROM expenses WHERE expense_date BETWEEN ?::date AND ?::date GROUP BY category ORDER BY total DESC', $rg)->fetchAll(),
                 'by_payment'  => q('SELECT payment_method, COUNT(*)::int AS count, SUM(amount)::float AS total FROM expenses WHERE expense_date BETWEEN ?::date AND ?::date GROUP BY payment_method ORDER BY total DESC', $rg)->fetchAll()]);

        case 'pnl':
            $r = q("SELECT COUNT(*)::int AS count, COALESCE(SUM(s.subtotal - s.discount),0)::float AS net_sales, COALESCE(SUM(s.tax),0)::float AS tax FROM sales s WHERE $sd", $rg)->fetch();
            $cogs = (float)q("SELECT COALESCE(SUM(si.cost * si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE $sd", $rg)->fetchColumn();
            $e = $exp();
            $gross = round($r['net_sales'] - $cogs, 2);
            out(['from' => $from, 'to' => $to, 'sales_count' => $r['count'], 'net_sales' => $r['net_sales'], 'tax_collected' => $r['tax'], 'cogs' => round($cogs, 2),
                 'gross_profit' => $gross, 'gross_margin' => $r['net_sales'] > 0 ? round($gross / $r['net_sales'] * 100, 1) : 0,
                 'expenses' => $e, 'net_profit' => round($gross - $e, 2),
                 'expenses_by_category' => q('SELECT category, SUM(amount)::float AS total FROM expenses WHERE expense_date BETWEEN ?::date AND ?::date GROUP BY category ORDER BY total DESC', $rg)->fetchAll()]);
    }
    throw new ApiError('Unknown report');
}

// Upgrades an older database by itself the first time new files are used, so running setup.php is not required.
// One tiny lookup per request; the migration only runs when SCHEMA_VERSION changes, under a lock so two requests cannot collide.
define('SCHEMA_VERSION', (string)(require __DIR__ . '/version.php')['schema']);
function auto_upgrade() {
    try { $v = q("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn(); }
    catch (PDOException $e) { if ($e->getCode() === '42P01') return; throw $e; }   // tables missing: the caller reports "not installed"
    if ($v === SCHEMA_VERSION) return;
    $sql = @file_get_contents(__DIR__ . '/../database/migrate.sql');
    if ($sql === false) throw new RuntimeException('database/migrate.sql is missing');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('SELECT pg_advisory_xact_lock(727001)');
        $v = q("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn();
        if ($v !== SCHEMA_VERSION) {
            $pdo->exec($sql);
            q("INSERT INTO settings (name, value) VALUES ('schema_version', ?) ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value", [SCHEMA_VERSION]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

// ---------- router ----------
try {
    $r = $_GET['route'] ?? '';
    $m = $_SERVER['REQUEST_METHOD'];
    $id = (int)($_GET['id'] ?? 0);

    // Uptime / load-balancer check: no login needed, reveals nothing but up or down.
    if ($r === 'health') {
        try { q('SELECT 1'); out(['ok' => true]); }
        catch (Throwable $e) { error_log('[pos] health check failed: ' . $e->getMessage()); out(['ok' => false], 503); }
    }

    // Every change must come from this app's own page: browsers will not add this header to a request sent from another site.
    if (!in_array($m, ['GET', 'HEAD', 'OPTIONS'], true) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest')
        throw new ApiError('Invalid request', 403);

    start_session();
    $b = body();
    header('Cache-Control: no-store');
    auto_upgrade();

    switch ("$m $r") {
        // ----- auth -----
        case 'POST login': login(); break;
        case 'POST logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) { $cp = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cp['path'], 'secure' => $cp['secure'], 'httponly' => true, 'samesite' => 'Strict']); }
            session_destroy(); out(['ok' => true]);
        case 'POST change_password': change_password(); break;
        case 'GET me':
            $u = me();
            if (!$u) {
                $n = q("SELECT value FROM settings WHERE name = 'store_name'")->fetch();
                out(['user' => null, 'brand' => ['store_name' => $n['value'] ?? null]]);
            }
            out(['user' => user_payload($u), 'settings' => empty($_SESSION['must_change']) ? settings() : []]);

        // ----- settings -----
        case 'PUT settings':
            need_admin();
            foreach (['store_name', 'address', 'phone', 'currency', 'tax_rate', 'receipt_footer'] as $k) {
                if (array_key_exists($k, $b)) q('INSERT INTO settings (name, value) VALUES (?,?) ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value', [$k, (string)$b[$k]]);
            }
            out(settings());

        // ----- categories -----
        case 'GET categories':
            need_login();
            out(q('SELECT c.id, c.name, COUNT(p.id)::int AS products FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.active GROUP BY c.id ORDER BY c.name')->fetchAll());
        case 'POST categories':
        case 'PUT categories': categories_save($m, $id); break;
        case 'DELETE categories':
            need_manager(); q('DELETE FROM categories WHERE id = ?', [$id]); out(['ok' => true]);

        // ----- products -----
        case 'GET products':
            $mgr = is_manager(need_login());
            $where = []; $p = [];
            if (empty($_GET['all'])) $where[] = 'p.active';
            if (!empty($_GET['q'])) { $where[] = '(LOWER(p.name) LIKE ? OR LOWER(p.sku) LIKE ? OR p.barcode = ?)'; $t = '%' . strtolower($_GET['q']) . '%'; array_push($p, $t, $t, $_GET['q']); }
            out(array_map(function ($r) use ($mgr) {
                    $r['stock'] = (float)$r['stock']; $r['reorder_level'] = (float)$r['reorder_level'];
                    if (!$mgr) unset($r['cost'], $r['supplier'], $r['supplier_id']);   // buying prices and suppliers are not for cashiers
                    return $r;
                },
                q('SELECT p.*, c.name AS category, sp.name AS supplier FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers sp ON sp.id = p.supplier_id'
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.name', $p)->fetchAll()));
        case 'POST products':
            need_manager(); $f = product_fields();
            dup_guard(fn() => q('INSERT INTO products (sku, barcode, name, category_id, supplier_id, unit, price, cost, stock, reorder_level, active) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $f), 'SKU or barcode already exists');
            out(['ok' => true], 201);
        case 'PUT products':
            need_manager(); $f = product_fields(); $f[] = $id;
            dup_guard(fn() => q('UPDATE products SET sku=?, barcode=?, name=?, category_id=?, supplier_id=?, unit=?, price=?, cost=?, stock=?, reorder_level=?, active=? WHERE id=?', $f), 'SKU or barcode already exists');
            out(['ok' => true]);
        case 'DELETE products':
            need_manager(); q('UPDATE products SET active = FALSE WHERE id = ?', [$id]); out(['ok' => true]);

        // ----- customers -----
        case 'GET customers':
            need_login();
            $p = []; $sql = 'SELECT id, name, first_name, last_name, phone, email, address FROM customers';
            if (!empty($_GET['q'])) {
                $sql .= " WHERE LOWER(name) LIKE ? OR LOWER(COALESCE(phone,'')) LIKE ? OR LOWER(COALESCE(email,'')) LIKE ? OR LOWER(COALESCE(address,'')) LIKE ?";
                $t = '%' . strtolower($_GET['q']) . '%'; $p = [$t, $t, $t, $t];
            }
            out(q($sql . ' ORDER BY name', $p)->fetchAll());
        case 'POST customers':
        case 'PUT customers':
            need_login();
            $fn = trim($b['first_name'] ?? ''); $ln = trim($b['last_name'] ?? '');
            if ($fn === '' && $ln === '' && trim($b['name'] ?? '') !== '') $fn = trim($b['name']);   // older clients send one "name"
            if ($fn === '') throw new ApiError('First name is required');
            $full = trim("$fn $ln");
            $f = [$full, $fn, nul($ln), nul($b['phone'] ?? null), nul($b['email'] ?? null), nul($b['address'] ?? null)];
            if ($m === 'POST') q('INSERT INTO customers (name, first_name, last_name, phone, email, address) VALUES (?,?,?,?,?,?)', $f);
            else q('UPDATE customers SET name=?, first_name=?, last_name=?, phone=?, email=?, address=? WHERE id=?', [...$f, $id]);
            out(['ok' => true], $m === 'POST' ? 201 : 200);
        case 'DELETE customers':
            need_manager(); q('DELETE FROM customers WHERE id = ?', [$id]); out(['ok' => true]);

        // ----- sales -----
        case 'POST sales': create_sale(need_login()); break;
        case 'GET sales':
            $u = need_login();
            if ($id) { $s = get_sale($id); if (!is_manager($u) && (int)$s['user_id'] !== (int)$u['id']) throw new ApiError('Not allowed', 403); out($s); }
            if (!empty($_GET['receipt'])) {   // quick lookup by receipt number, any date (used by the top-bar search)
                $w = 'LOWER(s.receipt_no) LIKE ?'; $pp = ['%' . strtolower(trim($_GET['receipt'])) . '%'];
                if (!is_manager($u)) { $w .= ' AND s.user_id = ?'; $pp[] = $u['id']; }
                out(q("SELECT s.id, s.receipt_no, s.total, s.status, s.created_at FROM sales s WHERE $w ORDER BY s.id DESC LIMIT 5", $pp)->fetchAll());
            }
            $where = ['s.created_at >= ?::date', "s.created_at < (?::date + 1)"];
            $p = [$_GET['from'] ?? date('Y-m-d'), $_GET['to'] ?? date('Y-m-d')];
            if (!is_manager($u)) { $where[] = 's.user_id = ?'; $p[] = $u['id']; }
            out(q('SELECT s.id, s.receipt_no, s.total, s.payment_method, s.status, s.created_at, u.full_name AS cashier, c.name AS customer_name,
                          (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id)::int AS items
                   FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id
                   WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC LIMIT 500', $p)->fetchAll());
        case 'POST void': need_manager(); void_sale($id); break;

        // ----- reports -----
        case 'GET report': need_manager(); report(); break;

        // ----- users -----
        case 'GET users': need_admin(); out(q('SELECT id, username, full_name, role, active, created_at FROM users ORDER BY id DESC')->fetchAll());
        case 'POST users':
            need_admin();
            $un = trim($b['username'] ?? ''); $fn = trim($b['full_name'] ?? ''); $pw = $b['password'] ?? '';
            if ($un === '' || $fn === '') throw new ApiError('Username and full name are required');
            username_check($un); pw_check((string)$pw, $un);
            $role = clean_role($b['role'] ?? '');
            dup_guard(fn() => q('INSERT INTO users (username, full_name, password_hash, role) VALUES (?,?,?,?)', [$un, $fn, password_hash($pw, PASSWORD_DEFAULT), $role]), 'Username already taken');
            out(['ok' => true], 201);
        case 'PUT users':
            $a = need_admin();
            $fn = trim($b['full_name'] ?? ''); if ($fn === '') throw new ApiError('Full name is required');
            $role = clean_role($b['role'] ?? '');
            $active = bool_str($b['active'] ?? true);
            if ($id === (int)$a['id'] && ($active === 'false' || $role !== 'admin')) throw new ApiError('You cannot deactivate or demote your own account');
            q('UPDATE users SET full_name=?, role=?, active=? WHERE id=?', [$fn, $role, $active, $id]);
            if (!empty($b['password'])) {
                pw_check((string)$b['password'], (string)q('SELECT username FROM users WHERE id = ?', [$id])->fetchColumn());
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($b['password'], PASSWORD_DEFAULT), $id]);
            }
            out(['ok' => true]);

        case 'DELETE users':
            $a = need_admin();
            if ($id === (int)$a['id']) throw new ApiError('You cannot delete your own account');
            try { q('DELETE FROM users WHERE id = ?', [$id]); }
            catch (PDOException $e) {
                if ($e->getCode() === '23503') throw new ApiError('This user has recorded sales, so it cannot be deleted. Untick Active to block the login instead.', 409);
                throw $e;
            }
            out(['ok' => true]);

        case 'POST seed_shop':
            need_admin();
            $sql = @file_get_contents(__DIR__ . '/../database/shop_seed.sql');
            if ($sql === false) throw new ApiError('database/shop_seed.sql is missing', 500);
            db()->exec($sql);
            out(['ok' => true, 'products' => (int)q('SELECT COUNT(*) FROM products')->fetchColumn()]);

        case 'GET search': $u = need_login(); search_all($u); break;

        // ----- suppliers (admin + manager) -----
        case 'GET suppliers':
            need_manager();
            $p = []; $sql = 'SELECT sp.*, (SELECT COUNT(*) FROM products WHERE supplier_id = sp.id AND active)::int AS products FROM suppliers sp';
            if (!empty($_GET['q'])) { $sql .= ' WHERE LOWER(sp.name) LIKE ? OR LOWER(COALESCE(sp.phone,\'\')) LIKE ? OR LOWER(COALESCE(sp.email,\'\')) LIKE ? OR LOWER(COALESCE(sp.address,\'\')) LIKE ?'; $t = '%' . strtolower($_GET['q']) . '%'; $p = [$t, $t, $t, $t]; }
            out(q($sql . ' ORDER BY sp.name', $p)->fetchAll());
        case 'POST suppliers':
        case 'PUT suppliers':
            need_manager();
            $name = trim($b['name'] ?? ''); if ($name === '') throw new ApiError('Supplier name is required');
            $f = [$name, nul($b['phone'] ?? null), nul($b['email'] ?? null), nul($b['address'] ?? null)];
            if ($m === 'POST') q('INSERT INTO suppliers (name, phone, email, address) VALUES (?,?,?,?)', $f);
            else q('UPDATE suppliers SET name=?, phone=?, email=?, address=? WHERE id=?', [...$f, $id]);
            out(['ok' => true], $m === 'POST' ? 201 : 200);
        case 'DELETE suppliers':
            need_manager(); q('DELETE FROM suppliers WHERE id = ?', [$id]); out(['ok' => true]);   // products keep working, supplier becomes empty

        // ----- receive stock (admin + manager) -----
        case 'POST stock_add': need_manager(); stock_add(me()); break;
        case 'GET stock_log':
            need_manager();
            out(q('SELECT r.id, r.created_at, r.product_name, p.unit, r.qty::float AS qty, r.cost::float AS cost, r.supplier_name, r.note, u.full_name AS added_by
                   FROM stock_receipts r LEFT JOIN products p ON p.id = r.product_id LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id DESC LIMIT 15')->fetchAll());

        // ----- expenses (admin + manager) -----
        case 'GET expenses':
            need_manager();
            [$from, $to] = date_range(false);
            $w = []; $p = [];
            if ($from) { $w[] = 'e.expense_date >= ?::date'; $p[] = $from; }
            if ($to)   { $w[] = 'e.expense_date <= ?::date'; $p[] = $to; }
            out(q('SELECT e.id, e.expense_date::text AS expense_date, e.category, e.description, e.amount::float AS amount, e.payment_method, u.full_name AS recorded_by
                   FROM expenses e LEFT JOIN users u ON u.id = e.user_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 1000', $p)->fetchAll());
        case 'POST expenses':
        case 'PUT expenses':
            $u = need_manager();
            [$date, $cat, $desc, $amt, $pm] = expense_fields();
            if ($m === 'POST') q('INSERT INTO expenses (expense_date, category, description, amount, payment_method, user_id) VALUES (?,?,?,?,?,?)', [$date, $cat, $desc, $amt, $pm, $u['id']]);
            else q('UPDATE expenses SET expense_date=?, category=?, description=?, amount=?, payment_method=? WHERE id=?', [$date, $cat, $desc, $amt, $pm, $id]);
            out(['ok' => true], $m === 'POST' ? 201 : 200);
        case 'DELETE expenses':
            need_manager(); q('DELETE FROM expenses WHERE id = ?', [$id]); out(['ok' => true]);

        // ----- reports hub -----
        case 'GET rpt': need_manager(); rpt(); break;

        default: throw new ApiError('Unknown endpoint', 404);
    }
} catch (ApiError $e) {
    out(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    error_log('[pos] database error ' . $e->getCode() . ': ' . $msg);   // full detail goes to the server log only
    if ($e->getCode() === '42P01') {   // undefined table: schema not installed in THIS database
        out(['error' => is_prod()
            ? 'The database tables are not installed yet. On the server run: php bin/install.php (see DEPLOYMENT.md).'
            : 'The database tables are not installed yet. Open /setup.php (from this computer) once to create them.'], 500);
    }
    if (stripos($msg, 'could not connect') !== false || stripos($msg, 'password authentication') !== false || stripos($msg, 'Connection refused') !== false
        || stripos($msg, 'timeout expired') !== false || stripos($msg, 'could not translate host') !== false
        || (stripos($msg, 'does not exist') !== false && stripos($msg, 'database') !== false)) {
        out(['error' => 'Cannot reach the database. ' . (cfg('debug') ? 'Check api/config.local.php or the DB_* environment variables and that PostgreSQL is running. (' . $msg . ')' : 'Please try again in a moment or contact the administrator.')], 503);
    }
    out(['error' => cfg('debug') ? 'Database error: ' . $msg : 'A database error occurred. Please try again or contact the administrator.'], 500);
} catch (Throwable $e) {
    error_log('[pos] server error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => cfg('debug') ? 'Server error: ' . $e->getMessage() : 'Something went wrong on the server. Please try again or contact the administrator.'], 500);
}
