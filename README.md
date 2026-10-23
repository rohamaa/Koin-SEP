# Koin — SEP

<p align="center">
  <a href="https://rohamaa.github.io/Koin-Core/">Docs site</a> ·
  <a href="https://github.com/rohamaa/Koin-Core/blob/docs/index.md">Docs on GitHub</a>
</p>

## The **SEP** gateway driver (Bank Saman) for the Koin package — built on SEP's OnlinePG REST API. The **Bluepay** sub-driver (`Rohama\Koin\Sep\BluepayGateway`) ships in the same package.

### Install

```bash
composer require rohama/koin-sep
```

### Features

- Payment request — returns token + redirect URL
- Verify
- Refund
- Callback parsing



### Configuration

| Field | Description |
| --- | --- |
| `apiKey` | API key |

### Usage

```php
use Rohama\Koin\Sep\SepGateway;
use Rohama\Koin\Support\PaymentStatus;

$gateway = new SepGateway('api-key', 'https://example.com/callback');

$payment = $gateway->request(150000, 'ORDER-1', 'Subscription purchase');

if ($payment->isSuccessful()) {
    header('Location: ' . $payment->redirectUrl);
    exit;
}

// In the callback page — parseCallback runs verify() before reporting success
$callback = $gateway->parseCallback($_GET, $_POST);
if ($callback->status === PaymentStatus::SUCCESS) {
    // Verified
}
```

> `refund()` (in gateways that offer Refund) is available only when the developer-only 5th constructor parameter (`refundable`) is enabled.

Full documentation: <https://rohamaa.github.io/Koin-Core/>