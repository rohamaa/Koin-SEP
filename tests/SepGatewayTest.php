<?php

use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\Sep\SepGateway;
use Rohama\Translator\Translator;

final class SepGatewayTest extends TestCase
{
    public function testParseCallbackMapsStateOkToSuccess(): void
    {
        $gateway = new SepGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['ResultCode' => 0, 'TransactionDetail' => ['OrginalAmount' => 1000]]],
        ]));
        $result = $gateway->parseCallback(['RefNum' => 'r1', 'ResNum' => 'o1', 'State' => 'OK'], []);

        $this->assertSame('success', $result->status);
        $this->assertSame('r1', $result->transactionId);
        $this->assertSame('o1', $result->orderId);
    }

    public function testParseCallbackVerifiesTheCallbackResult(): void
    {
        $gateway = new SepGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['ResultCode' => -6, 'ResultDescription' => 'expired']],
        ]));
        $result = $gateway->parseCallback(['RefNum' => 'r1', 'ResNum' => 'o1', 'State' => 'OK'], []);

        $this->assertSame('failed', $result->status);
    }

    public function testParseCallbackMapsACanceledState(): void
    {
        $gateway = new SepGateway('k');
        $result = $gateway->parseCallback(['RefNum' => 'r1', 'ResNum' => 'o1', 'State' => 'Canceled'], []);

        $this->assertSame('canceled', $result->status);
    }

    public function testVerifyMapsAnExpiredTransactionToFailed(): void
    {
        $gateway = new SepGateway('12571198');
        $gateway->setClient($this->mockHttp([
            [200, ['ResultCode' => -6, 'ResultDescription' => 'expired']],
        ]));

        $result = $gateway->verify('r1');

        $this->assertSame('failed', $result->status);
    }

    public function testVerifyMapsANotFoundTransactionToFailed(): void
    {
        $gateway = new SepGateway('12571198');
        $gateway->setClient($this->mockHttp([
            [200, ['ResultCode' => -2, 'ResultDescription' => 'not found']],
        ]));

        $result = $gateway->verify('r1');

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('-2', $result->message);
    }

    public function testVerifyThrowsForATerminalLevelError(): void
    {
        $gateway = new SepGateway('12571198');
        $gateway->setClient($this->mockHttp([
            [200, ['ResultCode' => -105, 'ResultDescription' => 'terminal not found']],
        ]));

        $this->expectException(VerificationFailedException::class);
        $this->expectExceptionMessage('-105');
        $gateway->verify('r1');
    }

    public function testVerifyThrowsOnAmountMismatch(): void
    {
        $gateway = new SepGateway('12571198');
        $gateway->setClient($this->mockHttp([
            [200, ['ResultCode' => 0, 'TransactionDetail' => ['OrginalAmount' => 9999]]],
        ]));

        $this->expectException(VerificationFailedException::class);
        $this->expectExceptionMessage('amount mismatch');
        $gateway->verify('r1', amount: 1000);
    }

    public function testConfigFieldsListsTheSepInputs(): void
    {
        $keys = array_map(fn ($f) => $f['key'], SepGateway::configFields());

        $this->assertSame(['apiKey'], $keys);
    }

    public function testLangFilesCoverTheSepAndBluepayNames(): void
    {
        $this->assertSame('SEP (Saman)', Translator::trans('Koin-sep:driver.name'));
        $this->assertSame('BluePay', Translator::trans('Koin-bluepay:driver.name'));
    }
    public function testRefundRequiresTheRefundableFlag(): void
    {
        $gateway = new SepGateway('k');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('constructed with refundable: true');

        $gateway->refund('t1');
    }
}
