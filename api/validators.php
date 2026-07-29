<?php

declare(strict_types=1);

const DOCUMENT_NAMES = ['budget', 'investments', 'fund_snapshots', 'appearance'];

function validationError(string $message): never
{
    respond(422, ['error' => $message]);
}

function isListArray(mixed $value, int $max): bool
{
    return is_array($value) && array_is_list($value) && count($value) <= $max;
}

function validString(mixed $value, int $max, bool $required = false): bool
{
    return is_string($value) &&
        mb_strlen($value) <= $max &&
        (!$required || trim($value) !== '');
}

function validFiniteNumber(mixed $value, bool $nullable = false): bool
{
    if ($nullable && $value === null) {
        return true;
    }
    return (is_int($value) || is_float($value)) && is_finite((float) $value);
}

function validDate(mixed $value, bool $optional = false): bool
{
    return is_string($value) &&
        (($optional && $value === '') ||
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1);
}

function validateNumberMap(mixed $value, int $maxOuter = 200): bool
{
    if (
        !is_array($value) ||
        ($value !== [] && array_is_list($value)) ||
        count($value) > $maxOuter
    ) {
        return false;
    }
    foreach ($value as $key => $number) {
        if (!validString($key, 80, true) || !validFiniteNumber($number)) {
            return false;
        }
    }
    return true;
}

function validateNestedNumberMap(mixed $value): bool
{
    if (
        !is_array($value) ||
        ($value !== [] && array_is_list($value)) ||
        count($value) > 240
    ) {
        return false;
    }
    foreach ($value as $key => $map) {
        if (!validString($key, 10, true) || !validateNumberMap($map)) {
            return false;
        }
    }
    return true;
}

function validateTransactionCore(array $value): bool
{
    return validString($value['title'] ?? null, 160, true) &&
        validFiniteNumber($value['amount'] ?? null) &&
        ($value['amount'] ?? -1) >= 0 &&
        in_array($value['type'] ?? null, ['income', 'expense'], true) &&
        validString($value['category'] ?? null, 80, true) &&
        validString($value['paymentMethod'] ?? null, 80) &&
        validString($value['note'] ?? null, 2000) &&
        (!array_key_exists('budgetMonthOverride', $value) ||
            (is_string($value['budgetMonthOverride']) &&
                preg_match('/^\d{4}-\d{2}$/D', $value['budgetMonthOverride']) === 1));
}

function validateBudget(mixed $value): array
{
    if (!is_array($value) ||
        ($value['version'] ?? null) !== 2 ||
        !isListArray($value['transactions'] ?? null, 10000) ||
        !isListArray($value['recurringSeries'] ?? null, 2000) ||
        !validateNumberMap($value['baseBudgets'] ?? null) ||
        !validateNestedNumberMap($value['budgetForwardChanges'] ?? null) ||
        !validateNestedNumberMap($value['budgetPeriodOverrides'] ?? null) ||
        !is_int($value['cycleStartDay'] ?? null) ||
        $value['cycleStartDay'] < 1 ||
        $value['cycleStartDay'] > 28 ||
        !is_array($value['openingBalance'] ?? null) ||
        !validFiniteNumber($value['openingBalance']['amount'] ?? null) ||
        ($value['openingBalance']['amount'] ?? -1) < 0 ||
        !validDate($value['openingBalance']['date'] ?? null) ||
        !validFiniteNumber($value['savingsTarget'] ?? null) ||
        !validFiniteNumber($value['savingsBalance'] ?? null) ||
        $value['savingsTarget'] < 0 ||
        $value['savingsBalance'] < 0
    ) {
        validationError('Budgetdokumentet har fel format.');
    }

    $ids = [];
    foreach ($value['transactions'] as $transaction) {
        if (!is_array($transaction) ||
            !validString($transaction['id'] ?? null, 160, true) ||
            isset($ids[$transaction['id']]) ||
            !validateTransactionCore($transaction) ||
            !validDate($transaction['date'] ?? null)
        ) {
            validationError('En budgetpost har fel format.');
        }
        $ids[$transaction['id']] = true;
    }

    foreach ($value['recurringSeries'] as $series) {
        $rule = is_array($series) ? ($series['rule'] ?? null) : null;
        $exceptions = is_array($series) ? ($series['exceptions'] ?? null) : null;
        if (!is_array($series) ||
            !validString($series['id'] ?? null, 160, true) ||
            isset($ids[$series['id']]) ||
            !validateTransactionCore($series) ||
            !is_array($rule) ||
            !in_array($rule['frequency'] ?? null, ['day', 'week', 'month', 'year'], true) ||
            !is_int($rule['interval'] ?? null) ||
            $rule['interval'] < 1 ||
            $rule['interval'] > 100 ||
            !validDate($rule['startDate'] ?? null) ||
            (isset($rule['endDate']) && !validDate($rule['endDate'])) ||
            !is_array($exceptions) ||
            ($exceptions !== [] && array_is_list($exceptions)) ||
            count($exceptions) > 5000
        ) {
            validationError('En återkommande serie har fel format.');
        }
        foreach ($exceptions as $date => $exception) {
            if (!validDate($date) || !is_array($exception) ||
                !in_array($exception['kind'] ?? null, ['skip', 'modify'], true)
            ) {
                validationError('Ett serieundantag har fel format.');
            }
            if (($exception['kind'] ?? null) === 'modify') {
                $changes = $exception['changes'] ?? null;
                if (!is_array($changes) || count($changes) > 8) {
                    validationError('Ett ändrat serietillfälle har fel format.');
                }
            }
        }
        $ids[$series['id']] = true;
    }
    return $value;
}

function validateInvestments(mixed $value): array
{
    if (!is_array($value) ||
        ($value['version'] ?? null) !== 1 ||
        !isListArray($value['accounts'] ?? null, 100) ||
        !isListArray($value['holdings'] ?? null, 5000)
    ) {
        validationError('Investeringsdokumentet har fel format.');
    }
    $accountIds = [];
    foreach ($value['accounts'] as $account) {
        if (!is_array($account) ||
            !validString($account['id'] ?? null, 160, true) ||
            isset($accountIds[$account['id']]) ||
            !validString($account['provider'] ?? null, 120, true) ||
            !validString($account['name'] ?? null, 120) ||
            !validString($account['accountType'] ?? null, 80)
        ) {
            validationError('Ett investeringskonto har fel format.');
        }
        $accountIds[$account['id']] = true;
    }
    $holdingIds = [];
    foreach ($value['holdings'] as $holding) {
        if (!is_array($holding) ||
            !validString($holding['id'] ?? null, 160, true) ||
            isset($holdingIds[$holding['id']]) ||
            !isset($accountIds[$holding['accountId'] ?? '']) ||
            !in_array($holding['kind'] ?? null, ['stock', 'fund', 'crypto', 'other'], true) ||
            !validString($holding['name'] ?? null, 200, true) ||
            !validString($holding['symbol'] ?? null, 80) ||
            !validString($holding['isin'] ?? null, 24) ||
            !validString($holding['exchange'] ?? null, 120) ||
            !validString($holding['category'] ?? null, 120) ||
            !validFiniteNumber($holding['quantity'] ?? null, true) ||
            !validFiniteNumber($holding['averagePrice'] ?? null, true) ||
            !validFiniteNumber($holding['currentPrice'] ?? null, true) ||
            (array_key_exists('todayChangePercent', $holding) &&
                !validFiniteNumber($holding['todayChangePercent'], true)) ||
            (array_key_exists('todayChangeValue', $holding) &&
                !validFiniteNumber($holding['todayChangeValue'], true)) ||
            (array_key_exists('oneMonthChangePercent', $holding) &&
                !validFiniteNumber($holding['oneMonthChangePercent'], true)) ||
            (array_key_exists('threeMonthChangePercent', $holding) &&
                !validFiniteNumber($holding['threeMonthChangePercent'], true)) ||
            (array_key_exists('reportedMarketValue', $holding) &&
                (!validFiniteNumber($holding['reportedMarketValue'], true) ||
                    ($holding['reportedMarketValue'] !== null &&
                        $holding['reportedMarketValue'] < 0))) ||
            (array_key_exists('reportedInvestedValue', $holding) &&
                (!validFiniteNumber($holding['reportedInvestedValue'], true) ||
                    ($holding['reportedInvestedValue'] !== null &&
                        $holding['reportedInvestedValue'] < 0))) ||
            (array_key_exists('loanValue', $holding) &&
                (!validFiniteNumber($holding['loanValue'], true) ||
                    ($holding['loanValue'] !== null &&
                        $holding['loanValue'] < 0))) ||
            !validString($holding['currency'] ?? null, 3, true) ||
            (array_key_exists('valuationCurrency', $holding) &&
                !validString($holding['valuationCurrency'], 3, true)) ||
            !validDate($holding['purchaseDate'] ?? null, true) ||
            !validFiniteNumber($holding['annualFee'] ?? null, true) ||
            !validFiniteNumber($holding['riskLevel'] ?? null, true) ||
            !validString($holding['note'] ?? null, 2000) ||
            !validString($holding['priceUpdatedAt'] ?? null, 40) ||
            !in_array(
                $holding['priceSource'] ?? null,
                ['', 'manual', 'alpha-vantage', 'avanza-public'],
                true
            )
        ) {
            validationError('Ett innehav har fel format.');
        }
        $holdingIds[$holding['id']] = true;
    }
    return $value;
}

function validateFundSnapshots(mixed $value): array
{
    if (!isListArray($value, 5000)) {
        validationError('Fondöversikten har fel format.');
    }
    foreach ($value as $snapshot) {
        if (!is_array($snapshot) ||
            !validString($snapshot['name'] ?? null, 200, true) ||
            !validString($snapshot['priceDate'] ?? null, 80) ||
            !validFiniteNumber($snapshot['changePercent'] ?? null, true) ||
            !validFiniteNumber($snapshot['secondaryChangePercent'] ?? null, true) ||
            !validFiniteNumber($snapshot['gain'] ?? null) ||
            !validFiniteNumber($snapshot['gainPercent'] ?? null) ||
            !validFiniteNumber($snapshot['value'] ?? null) ||
            $snapshot['value'] < 0 ||
            !is_bool($snapshot['interestDiscountEligible'] ?? null)
        ) {
            validationError('Ett fondvärde har fel format.');
        }
    }
    return $value;
}

function validateAppearance(mixed $value): array
{
    if (!is_array($value) ||
        !in_array($value['theme'] ?? null, ['light', 'dark'], true) ||
        !in_array($value['fontSize'] ?? null, ['normal', 'large', 'xlarge'], true) ||
        !in_array($value['fontFamily'] ?? null, ['rounded', 'system', 'readable'], true)
    ) {
        validationError('Utseendeinställningarna har fel format.');
    }
    return [
        'theme' => $value['theme'],
        'fontSize' => $value['fontSize'],
        'fontFamily' => $value['fontFamily'],
    ];
}

function validateDocument(string $name, mixed $data): array
{
    return match ($name) {
        'budget' => validateBudget($data),
        'investments' => validateInvestments($data),
        'fund_snapshots' => validateFundSnapshots($data),
        'appearance' => validateAppearance($data),
        default => validationError('Okänd dokumenttyp.'),
    };
}

function schemaVersionFor(string $name): int
{
    return $name === 'budget' ? 2 : 1;
}
