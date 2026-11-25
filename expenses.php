<?php

declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/helpers.php";
require_once __DIR__ . "/src/db.php";
require_once __DIR__ . "/src/auth.php";
require_once __DIR__ . "/src/expenses.php";

require_login();

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];

$errors = [];
$old = [
    'amount' => '',
    'category' => '',
    'expense_date' => date('Y-m-d'),
    'note' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['_csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $errors['_form'] = 'Permintaan tidak valid. Muat ualgn halaman atau coba lagi.';
    } elseif ($action === 'create') {
        $old = [
            'amount' => is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '',
            'category' => is_string($_POST['category'] ?? null) ? $_POST['category'] : '',
            'expense_date' => is_string($_POST['expense_date'] ?? null) ? $_POST['expense_date'] : '',
            'note' => is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '',
        ];

        $result = validateExpenseInput($old);

        if ($result['errors'] != []) {
            $errors = $result['errors'];
        } else {
            createExpense($pdo, $userId, $result['data']);
            flash('success', 'Pengeluaran berhasil disimpan');
            redirect('expenses.php');
        }
    } elseif ($action === 'delete') {
        $idInput = $_POST['id'] ?? '';
        $expenseId = is_string($idInput) ? filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if ($expenseId === false) {
            $errors['_form'] = 'Transaksi yang ingin dihapus tidak valid.';
        } elseif (deleteExpense($pdo, $userId, (int) $expenseId)) {
            flash('success', 'Transaksi berhasil dihapus.');
            redirect('expenses.php');
        } else {
            flash('error', 'Transaksi tidak ditemukan atau bukan milik Anda.');
            redirect('expenses.php');
        }
    } else {
        $errors['_form'] = 'Aksi tidak dikenal.';
    }
}

$expenses = listExpenses($pdo, $userId);
$successMessage = flash('success');
$flashError = flash('error');
$categories = expenseCategories();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengeluaran - <?= e(APP_NAME) ?></title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 960px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th,
        td {
            border-bottom: 1px solid #e5e7eb;
            padding: .55rem;
            text-align: left;
            vertical-align: top;
        }

        .field {
            margin-bottom: .8rem;
        }

        .field label {
            display: block;
            font-weight: 600;
            margin-bottom: .25rem;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            max-width: 28rem;
            padding: .45rem;
            box-sizing: border-box;
        }

        .error {
            color: #b91c1c;
        }

        .success {
            color: #15803d;
        }

        .muted {
            color: #6b7280;
        }
    </style>
</head>

<body>
    <nav>
        <a href="index.php">Dashboard</a>
    </nav>

    <h1>Catat Pengeluaran</h1>

    <?php if (isset($errors['_form'])): ?>
        <p class="error" role="alert"><?= e($errors['_form']) ?></p>
    <?php endif; ?>

    <?php if ($successMessage !== null): ?>
        <p class="success" role="status"><?= e($successMessage) ?></p>
    <?php endif; ?>

    <?php if ($flashError !== null): ?>
        <p class="error" role="alert"><?= e($flashError) ?></p>
    <?php endif; ?>

    <form method="post" action="expenses.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="field">
            <label for="amount">Nominal (Rp)</label>
            <input type="number" name="amount" id="amount" min="1" step="1" value="<?= e($old['amount']) ?>" required>
            <?php if (isset($errors['amount'])): ?>
                <span class="error"><?= e($errors['amount']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="category">Kategori</label>
            <select name="category" id="category" required>
                <option value="">-- Pilih Kategori --</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category) ?>" <?= $old['category'] === $category ? 'selected' : '' ?>>
                        <?= e($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['category'])): ?>
                <span class="error"><?= e($errors['category']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="expense_date">Tanggal</label>
            <input type="date" name="expense_date" id="expense_date" value="<?= e($old['expense_date']) ?>" required>
            <?php if (isset($errors['expense_date'])): ?>
                <span class="error"><?= e($errors['expense_date']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="note">Catatan (opsional)</label>
            <textarea name="note" id="note" maxlength="500"><?= e($old['note']) ?></textarea>
            <?php if (isset($errors['note'])): ?>
                <span class="error"><?= e($errors['note']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit">Simpan Pengeluaran</button>
    </form>

    <h2>Daftar Transaksi</h2>

    <?php if ($expenses === []): ?>
        <p class="muted">Belum ada transaksi. Tambahkan pengeluaran pertama anda melalui form di atas.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Kategori</th>
                    <th>Nominal</th>
                    <th>Catatan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $expense): ?>
                    <td><?= e((string) $expense['expense_date']) ?></td>
                    <td><?= e((string) $expense['category']) ?></td>
                    <td>Rp <?= e(number_format((int) $expense['amount'], 0, ',', '.')) ?></td>
                    <td><?= e((string) ($expense['note'] ?? '')) ?></td>
                    <td>
                        <form action="expenses.php" method="post" onsubmit="return confirm('Hapus transaksi ini?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e((string) $expense['id']) ?>">
                            <button type="submit">Hapus</button>
                        </form>
                    </td>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>

</html>