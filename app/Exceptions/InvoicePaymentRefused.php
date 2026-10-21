<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the invoice ledger when a payment or a refund breaks a rule (status, amount above the balance or the amount
 * paid). The message is meant for the user.
 */
class InvoicePaymentRefused extends RuntimeException {}
