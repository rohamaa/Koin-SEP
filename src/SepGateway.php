<?php

namespace Rohama\Koin\Sep;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Support\PaymentStatus;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\PaymentGateway;
use Rohama\Koin\RefundableGateway;
use Rohama\Koin\Requests\Callback;
use Rohama\Koin\Responses\PaymentResponse;
use Rohama\Koin\Responses\RefundResponse;
use Rohama\Koin\Responses\VerificationResponse;
use Rohama\Koin\Traits\IsPayment;
use Rohama\Translator\Translator;

/**
 * SEP (Saman Electronic Payment) gateway — REST OnlinePG API.
 */
class SepGateway implements PaymentGateway, RefundableGateway
{
    use IsPayment { __construct as protected paymentConstruct; }

    public const name = 'sep';

    private const TOKEN_STATUS_SUCCESS = 1;

    private const VERIFY_RESULT_SUCCESS = 0;
    private const VERIFY_RESULT_DUPLICATE = 2;
    private const VERIFY_RESULT_ALREADY_REVERSED = 5;
    private const VERIFY_RESULT_NOT_FOUND = -2;
    private const VERIFY_RESULT_EXPIRED = -6;

    private const VERIFY_RESULT_CODES = [
        0 => 'success',
        2 => 'duplicate request',
        5 => 'transaction already reversed',
        -2 => 'transaction not found',
        -6 => 'more than 30 minutes have elapsed since the transaction',
        -104 => 'terminal is inactive',
        -105 => 'terminal not found',
        -106 => 'requesting IP is not allowed',
    ];

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [], bool $refundable = false)
    {
        $this->paymentConstruct($apiKey, $callbackUrl, $sandbox, $extra, $refundable);

        Translator::register('Koin-' . self::name, dirname(__DIR__) . '/lang');
        $this->client = new Client([
            'base_uri' => 'https://sep.shaparak.ir',
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public static function configFields(): array
    {
        $prefix = 'Koin-' . self::name;

        return [
            [
                'key' => 'apiKey',
                'name' => Translator::trans($prefix . ':fields.apiKey'),
                'desc' => Translator::trans($prefix . ':fields.apiKey_help'),
                'type' => 'text',
                'required' => true,
                'validation' => ['required', 'string'],
                'regex' => '/^.+$/',
            ],
        ];
    }

    public function request(
        int $amount,
        string $orderId = '',
        ?string $callbackUrl = null,
        string $description = '',
        ?string $mobile = null,
        ?string $email = null,
        ?string $currency = null,
        array $metadata = [],
        array $extra = [],
    ): PaymentResponse {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        if ($orderId === '') {
            throw new InvalidRequestException('SEP requires an order id (ResNum).', gateway: self::name);
        }

        if ($callbackUrl === null && $this->callbackUrl === null) {
            throw new InvalidRequestException('SEP requires a callback URL.', gateway: self::name);
        }

        $response = $this->client()->post('/OnlinePG/OnlinePG', [
            'json' => array_filter(array_merge([
                'Action' => 'Token',
                'TerminalId' => $this->apiKey,
                'RedirectUrl' => $callbackUrl ?? $this->callbackUrl,
                'ResNum' => $orderId,
                'Amount' => Currency::toRial($currency ?? Currency::TOMAN, $amount),
                'CellNumber' => $mobile,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $status = (int) ($data['status'] ?? -1);

        if ($status !== self::TOKEN_STATUS_SUCCESS || !isset($data['token']) || $data['token'] === '') {
            throw new PaymentFailedException($this->tokenError($data), $status, raw: $data, gateway: self::name);
        }

        $token = (string) $data['token'];

        return new PaymentResponse(
            $data,
            $response,
            PaymentStatus::PENDING,
            $token,
            $this->payUrl($response, $token),
            $orderId,
            null,
        );
    }

    /**
     * Builds the URL the payer is redirected to with the issued token.
     *
     * @param  ResponseInterface  $response  the issued-token response
     *
     * @return string
     */
    protected function payUrl(ResponseInterface $response, string $token): string
    {
        $base = trim($response->getHeaderLine('X-IPG-Url'));

        if ($base !== '') {
            return rtrim($base, '/') . '?token=' . $token;
        }

        return 'https://sep.shaparak.ir/OnlinePG/SendToken?token=' . $token;
    }

    public function parseCallback(array $query, array $body): Callback
    {
        $raw = array_merge($query, $body);
        $refNum = isset($raw['RefNum']) ? (string) $raw['RefNum'] : '';
        $orderId = isset($raw['ResNum']) ? (string) $raw['ResNum'] : '';

        if ($refNum === '') {
            throw new InvalidRequestException('SEP invalid Callback payload.', gateway: self::name);
        }

        $extra = $raw;

        if (strcasecmp($raw['State'] ?? '', 'OK') !== 0) {
            return new Callback(PaymentStatus::CANCELED, $refNum, $orderId, $extra);
        }

        $verified = $this->verify($refNum, $orderId);

        return new Callback($verified->status, $refNum, $orderId, $extra);
    }

    public function verify(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        $response = $this->client()->post('/verifyTxnRandomSessionkey/ipg/VerifyTransaction', [
            'json' => array_filter(array_merge([
                'RefNum' => $transactionId,
                'TerminalNumber' => (int) $this->apiKey,
                'TxnRandomSessionKey' => isset($extra['TxnRandomSessionKey']) ? (string) $extra['TxnRandomSessionKey'] : null,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $resultCode = (int) ($data['ResultCode'] ?? -1);

        if ($resultCode !== self::VERIFY_RESULT_SUCCESS && $resultCode !== self::VERIFY_RESULT_DUPLICATE) {
            if (in_array($resultCode, [self::VERIFY_RESULT_EXPIRED, self::VERIFY_RESULT_NOT_FOUND, self::VERIFY_RESULT_ALREADY_REVERSED], true)) {
                return new VerificationResponse(
                    $data,
                    $response,
                    PaymentStatus::FAILED,
                    $transactionId,
                    null,
                    null,
                    null,
                    $this->resultError($resultCode, $data),
                );
            }

            throw new VerificationFailedException($this->resultError($resultCode, $data), $resultCode, raw: $data, gateway: self::name);
        }

        $detail = isset($data['TransactionDetail']) && is_array($data['TransactionDetail'])
            ? $data['TransactionDetail']
            : [];

        if ($amount !== null) {
            $expected = Currency::toRial($currency ?? Currency::TOMAN, $amount);
            $actual = isset($detail['OrginalAmount']) ? (int) $detail['OrginalAmount'] : null;

            if ($actual === null || $actual !== $expected) {
                throw new VerificationFailedException(
                    sprintf('SEP verified amount mismatch: expected %d, got %s.', $expected, $actual === null ? 'null' : (string) $actual),
                    0,
                    raw: ['expected' => $expected, 'actual' => $actual],
                    gateway: self::name,
                );
            }
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::SUCCESS,
            $transactionId,
            isset($detail['RRN']) ? (string) $detail['RRN'] : null,
            isset($detail['OrginalAmount']) ? Currency::fromRial($currency ?? Currency::TOMAN, (int) $detail['OrginalAmount']) : null,
            isset($detail['MaskedPan']) ? (string) $detail['MaskedPan'] : null,
            isset($data['ResultDescription']) ? (string) $data['ResultDescription'] : null,
        );
    }

    public function refund(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): RefundResponse {
        if (!$this->refundable) {
            throw new InvalidRequestException('SEP refunds are only available for gateways constructed with refundable: true.', gateway: self::name);
        }

        $response = $this->client()->post('/verifyTxnRandomSessionkey/ipg/ReverseTransaction', [
            'json' => array_filter(array_merge([
                'RefNum' => $transactionId,
                'TerminalNumber' => (int) $this->apiKey,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $resultCode = (int) ($data['ResultCode'] ?? -1);

        if ($resultCode !== self::VERIFY_RESULT_SUCCESS && $resultCode !== self::VERIFY_RESULT_DUPLICATE) {
            throw new PaymentFailedException($this->resultError($resultCode, $data), $resultCode, raw: $data, gateway: self::name);
        }

        $detail = isset($data['TransactionDetail']) && is_array($data['TransactionDetail'])
            ? $data['TransactionDetail']
            : [];

        return new RefundResponse(
            $data,
            $response,
            PaymentStatus::REFUNDED,
            $transactionId,
            isset($detail['RRN']) ? (string) $detail['RRN'] : null,
            isset($data['ResultDescription']) ? (string) $data['ResultDescription'] : null,
        );
    }

    /**
     * Builds an error message from a SEP token failure payload.
     */
    private function tokenError(array $data): string
    {
        $code = isset($data['errorCode']) ? (string) $data['errorCode'] : 'unknown';
        $desc = isset($data['errorDesc']) ? (string) $data['errorDesc'] : 'Unknown error';
        return sprintf('SEP token error (code %s): %s', $code, $desc);
    }

    /**
     * Builds an error message from a SEP result code.
     */
    private function resultError(int $resultCode, array $data): string
    {
        $label = self::VERIFY_RESULT_CODES[$resultCode] ?? 'unknown';
        $desc = isset($data['ResultDescription']) ? (string) $data['ResultDescription'] : 'Unknown error';
        return sprintf('SEP error (code %d, %s): %s', $resultCode, $label, $desc);
    }
}
