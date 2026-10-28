<?php

// Reads a PAYMENT-REQUIRED header on stdin, prints a PAYMENT-SIGNATURE for
// its first offer. Run inside DDEV: ddev exec php bin/pay.php
declare(strict_types=1);

require __DIR__ . '/../packages/x402/vendor/autoload.php';

use Quebracho\X402\EvmExact;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;

// Default: the unfunded web3.js docs key, enough for mock mode.
$key = getenv('X402_BUYER_KEY') ?: '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
$required = PaymentHeaders::decode(trim(stream_get_contents(STDIN)));
echo PaymentHeaders::encode((new EvmExact(new Signer($key)))->pay($required, $required['accepts'][0]));
