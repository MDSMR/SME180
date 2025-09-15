<?php
/**
 * SME 180 SaaS POS System
 * Currency Helper Functions
 * Path: /helpers/currency.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/tenant.php';

/**
 * Format amount as currency based on tenant settings
 * @param float|int|string $amount
 * @param bool $includeSymbol
 * @return string
 */
function format_currency($amount, bool $includeSymbol = true): string {
    // Use TenantManager for formatting
    if ($includeSymbol) {
        return TenantManager::formatCurrency($amount);
    }
    
    // Format without symbol
    $decimals = TenantManager::getCurrencyDecimals();
    return number_format((float)$amount, $decimals, '.', ',');
}

/**
 * Get currency symbol from tenant settings
 * @return string
 */
function get_currency_symbol(): string {
    return TenantManager::getCurrencySymbol();
}

/**
 * Get currency decimals from tenant settings
 * @return int
 */
function get_currency_decimals(): int {
    return TenantManager::getCurrencyDecimals();
}

/**
 * Parse currency string to float
 * @param string $value
 * @return float
 */
function parse_currency(string $value): float {
    // Remove currency symbol and spaces
    $value = preg_replace('/[^0-9.,\-]/', '', $value);
    
    // Handle different decimal separators
    $value = str_replace(',', '', $value);
    
    return (float)$value;
}

/**
 * Calculate tax amount based on tenant settings
 * @param float $amount
 * @param float|null $taxRate Override tax rate
 * @return float
 */
function calculate_tax(float $amount, ?float $taxRate = null): float {
    if ($taxRate === null) {
        $taxRate = (float)TenantManager::getTenantSetting('tax_percent', 0);
    }
    
    $taxMethod = TenantManager::getTenantSetting('tax_inclusive', '0');
    
    if ($taxMethod === '1') {
        // Tax inclusive - extract tax from amount
        return $amount - ($amount / (1 + ($taxRate / 100)));
    } else {
        // Tax exclusive - add tax to amount
        return $amount * ($taxRate / 100);
    }
}

/**
 * Calculate service charge based on tenant settings
 * @param float $amount
 * @param float|null $serviceRate Override service rate
 * @return float
 */
function calculate_service(float $amount, ?float $serviceRate = null): float {
    if ($serviceRate === null) {
        $serviceRate = (float)TenantManager::getTenantSetting('service_percent', 0);
    }
    
    return $amount * ($serviceRate / 100);
}

/**
 * Format percentage for display
 * @param float $value
 * @param int $decimals
 * @return string
 */
function format_percentage(float $value, int $decimals = 2): string {
    return number_format($value, $decimals, '.', '') . '%';
}

/**
 * Compare two amounts considering currency decimals
 * @param float $amount1
 * @param float $amount2
 * @return int -1 if amount1 < amount2, 0 if equal, 1 if amount1 > amount2
 */
function compare_amounts(float $amount1, float $amount2): int {
    $decimals = TenantManager::getCurrencyDecimals();
    $precision = pow(10, -$decimals);
    
    $diff = $amount1 - $amount2;
    
    if (abs($diff) < $precision) {
        return 0;
    }
    
    return $diff > 0 ? 1 : -1;
}

/**
 * Round amount to currency decimals
 * @param float $amount
 * @return float
 */
function round_currency(float $amount): float {
    $decimals = TenantManager::getCurrencyDecimals();
    return round($amount, $decimals);
}

/**
 * Get display format for receipts and reports
 * @param float $amount
 * @param string $type 'receipt', 'report', 'export'
 * @return string
 */
function format_amount_display(float $amount, string $type = 'receipt'): string {
    switch ($type) {
        case 'receipt':
            return format_currency($amount, true);
            
        case 'report':
            return format_currency($amount, false);
            
        case 'export':
            $decimals = TenantManager::getCurrencyDecimals();
            return number_format($amount, $decimals, '.', '');
            
        default:
            return format_currency($amount, true);
    }
}