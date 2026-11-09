<?php

declare(strict_types=1);

function expenseCategories(): array
{
    return [
        'Makanan',
        'Transportasi',
        'Tagihan',
        'Belanja',
        'Kesehatan',
        'Hiburan',
        'Pendidikan',
        'Lainnya',
    ];
}

function validateExpenseInput(array $input): array
{
    $amountInput = $input['amount'] ?? '';
    $categoryInput = $input['category'] ?? '';
    $dateInput = $input['expense_date'] ?? '';
    $noteInput = $input['note'] ?? '';

    $data = [
        'amount' => 0,
        'category' => '',
        'expense_date' => '',
        'note' => null,
    ];
    $errors = [];

    $amount = is_string($amountInput) ? filter_var($amountInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

    if ($amount === false) {
        $errors['amount'] = 'Nominal harus berupa bilangan bula lebih dari 0.';
    } else {
        $data['amount'] = (int) $amount;
    }

    if (!is_string($categoryInput) || !in_array($categoryInput, expenseCategories(), true)) {
        $errors['category'] = 'Kategori tidak valid';
    } else {
        $data['category'] = $categoryInput;
    }

    $date = false;
    if (is_string($dateInput) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $dateInput) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
    }

    $dateErrors = $date instanceof DateTimeImmutable ? DateTimeImmutable::getLastErrors() : false;
    $dateHasErrors = $dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);

    if (!$date instanceof DateTimeImmutable || $dateHasErrors) {
        $errors['expense_date'] = 'Tanggal tidak valid. Gunakan format YYYY-MM-DD.';
    } else {
        $data['expense_date'] = $date->format('Y-m-d');
    }

    if (!is_string($noteInput)) {
        $errors['note'] = 'Catatan maksimal 500 karakter';
    } else {
        $note = trim($noteInput);

        if (strlen($note) > 500) {
            $errors['note'] = 'Catatan maksimal 500 karakter.';
        } else {
            $data['note'] = $note === '' ? null : $note;
        }
    }

    return [
        'data' => $data,
        'errors' => $errors,
    ];
}

function createExpense(PDO $pdo, int $userId, array $expense): int
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user ID is required.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO expenses (user_id, amount, category, expense_date, note) VALUES (:user_id, :amount, :category, :expense_date, :note)'
    );
    $statement->execute([
        'user_id' => $userId,
        'amount' => $expense['amount'],
        'category' => $expense['category'],
        'expense_date' => $expense['expense_date'],
        'note' => $expense['note'],
    ]);

    return (int) $pdo->lastInsertId();
}

function listExpenses(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, amount, category, expense_date, note, created_at
         FROM expenses
         WHERE user_id = :user_id
         ORDER BY expense_date DESC, id DESC'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

function deleteExpense(PDO $pdo, int $userId, int $expenseId): bool
{
    if ($userId < 1 || $expenseId < 1) {
        return false;
    }

    $statement = $pdo->prepare(
        'DELETE FROM expenses WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute(['id' => $expenseId, 'user_id' => $userId]);

    return $statement->rowCount() > 0;
}
