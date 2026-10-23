<?php

namespace Rohama\Koin\Sep;

use Rohama\Translator\Translator;

/**
 * SEP BluePay (neo-pg) gateway.
 */
class BluepayGateway extends SepGateway
{
    public const name = 'bluepay';

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [], bool $refundable = false)
    {
        parent::__construct($apiKey, $callbackUrl, $sandbox, $extra, $refundable);

        Translator::register('Koin-' . self::name, dirname(__DIR__) . '/lang-bluepay');
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
}
