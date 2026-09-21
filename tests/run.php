<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Olek\Bundle\AutoTranslatorBundle\Command\AutoTranslatorCommand;
use Olek\Bundle\AutoTranslatorBundle\DependencyInjection\AutoTranslatorExtension;
use Olek\Bundle\AutoTranslatorBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Reader\TranslationReaderInterface;
use Symfony\Component\Translation\Writer\TranslationWriterInterface;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function responseBody(array $translations): array
{
    return ['status' => 'completed', 'output' => [
        ['type' => 'reasoning'],
        ['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => json_encode(['translations' => $translations], JSON_THROW_ON_ERROR)],
        ]],
    ]];
}

function runCommand(MockHttpClient $client, string $key = 'test-key', array $locales = ['en', 'pl', 'de'], float $timeout = 300.0, int $batchSize = 100): array
{
    $reader = new class implements TranslationReaderInterface {
        public function read(string $directory, MessageCatalogue $catalogue): void
        {
            if ($catalogue->getLocale() === 'en') {
                for ($i = 0; $i < 102; ++$i) {
                    $catalogue->set('key'.$i, 'Message '.$i, 'messages');
                }
                $catalogue->set('multiline', "Hello {{ name }}\nWelcome %user%", 'other');
            } else {
                $catalogue->set('key0', 'Existing translation', 'messages');
                $catalogue->set('key2', '__Message 2', 'messages');
                if ($catalogue->getLocale() === 'de') {
                    $catalogue->set('key1', 'Vorhanden', 'messages');
                }
            }
            $catalogue->set('promotion', 'Promotion', 'messages');
        }
    };
    $writer = new class implements TranslationWriterInterface {
        public array $catalogues = [];
        public function write(MessageCatalogue $catalogue, string $format, array $options = []): void
        {
            $this->catalogues[] = $catalogue;
        }
    };
    $command = new AutoTranslatorCommand($reader, $writer, $client, 'en', $locales, '/unused', $key, 'gpt-5-nano', 'Translate {source_locale} to {target_locales}', $timeout, $batchSize);
    $tester = new CommandTester($command);
    try {
        $status = $tester->execute([]);
        return [$status, $writer->catalogues, null];
    } catch (Throwable $exception) {
        return [1, $writer->catalogues, $exception];
    }
}

$config = (new Processor())->processConfiguration(new Configuration(), []);
check($config['model'] === 'gpt-5-nano' && $config['api_key'] === '', 'Configuration defaults');
check($config['timeout'] === 300.0 && $config['batch_size'] === 100, 'Request defaults');
foreach ([['timeout' => 0], ['batch_size' => 0], ['batch_size' => 101]] as $invalidConfig) {
    try {
        (new Processor())->processConfiguration(new Configuration(), [$invalidConfig]);
        throw new RuntimeException('Invalid request configuration accepted');
    } catch (Symfony\Component\Config\Definition\Exception\InvalidConfigurationException) {
    }
}
$container = new ContainerBuilder();
(new AutoTranslatorExtension())->load([['api_key' => 'custom-key', 'model' => 'custom-model', 'prompt' => 'Custom prompt', 'timeout' => 600, 'batch_size' => 20, 'reasoning_effort' => 'low']], $container);
$arguments = $container->getDefinition(AutoTranslatorCommand::class)->getArguments();
check(array_slice($arguments, 6) === ['custom-key', 'custom-model', 'Custom prompt', 600, 20, 'low'], 'Configuration injection');

$batches = [];
$expectedTimeout = 300.0;
$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$batches, &$expectedTimeout): MockResponse {
    check($options['timeout'] === $expectedTimeout, 'Configured idle timeout');
    check($method === 'POST' && $url === 'https://api.openai.com/v1/responses', 'Request endpoint');
    check(in_array('Authorization: Bearer test-key', $options['headers'], true), 'Authorization');
    $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
    check($body['model'] === 'gpt-5-nano' && str_starts_with($body['instructions'], 'Translate en to pl, de'), 'Request settings');
    check($body['reasoning'] === ['effort' => 'minimal'], 'Nano uses minimal reasoning by default');
    check($body['text']['format']['strict'] === true, 'Structured output');
    $payload = json_decode($body['input'], true, 512, JSON_THROW_ON_ERROR);
    check($payload['target_locales'] === ['pl', 'de'], 'Shared target locales');
    $inputs = $payload['messages'];
    $batches[] = count($inputs);
    $translations = [];
    check(array_is_list($inputs), 'Messages are a list');
    foreach ($payload['target_locales'] as $locale) {
        $row = [];
        foreach ($inputs as $message) {
            check(is_string($message) && $message !== 'Promotion', 'Only missing source strings sent without metadata');
            $row[] = strtoupper($locale).': '.$message;
        }
        $translations[] = $row;
    }
    $schema = $body['text']['format']['schema'];
    check($schema['properties']['translations'] === [
        'type' => 'array', 'minItems' => 2, 'maxItems' => 2,
        'items' => ['type' => 'array', 'minItems' => count($inputs), 'maxItems' => count($inputs), 'items' => ['type' => 'string']],
    ], 'Constant matrix schema');
    return new MockResponse(json_encode(responseBody($translations), JSON_THROW_ON_ERROR));
});
[$status, $catalogues, $error] = runCommand($client);
check($status === 0 && $error === null, 'Successful translation: '.($error?->getMessage() ?? ''));
check(count($catalogues) === 2, 'Both target catalogues written');
check($catalogues[1]->get('key1') === 'Vorhanden', 'Preserve locale-specific existing translation');
check($catalogues[1]->get('key101') === 'DE: Message 101', 'German translation mapping');
check($batches === [100, 1, 1], 'Batch boundaries across domains');
check($catalogues[0]->get('key0') === 'Existing translation', 'Existing translation preserved');
check($catalogues[0]->get('key2') === 'PL: Message 2', 'Placeholder translated');
check($catalogues[0]->get('promotion') === 'Promotion', 'Source-identical translation preserved');
check($catalogues[0]->get('key101') === 'PL: Message 101', 'Last batch entry');
check($catalogues[0]->get('multiline', 'other') === "PL: Hello {{ name }}\nWelcome %user%", 'Multiline and placeholders');

$batches = [];
$expectedTimeout = 600.0;
[$status, $catalogues, $error] = runCommand($client, timeout: 600.0, batchSize: 20);
check($status === 0 && $error === null && $batches === [20, 20, 20, 20, 20, 1, 1], 'Custom request settings');

$storage = new class implements TranslationReaderInterface, TranslationWriterInterface {
    public array $saved = [];

    public function read(string $directory, MessageCatalogue $catalogue): void
    {
        if ($catalogue->getLocale() === 'en') {
            $catalogue->set('promotion', 'Promotion');
        } elseif (isset($this->saved[$catalogue->getLocale()])) {
            $catalogue->addCatalogue($this->saved[$catalogue->getLocale()]);
        }
    }

    public function write(MessageCatalogue $catalogue, string $format, array $options = []): void
    {
        $this->saved[$catalogue->getLocale()] = clone $catalogue;
    }
};
$promotionClient = new MockHttpClient(new MockResponse(json_encode(responseBody([['Promotion']]), JSON_THROW_ON_ERROR)));
for ($run = 0; $run < 2; ++$run) {
    $tester = new CommandTester(new AutoTranslatorCommand($storage, $storage, $promotionClient, 'en', ['en', 'fr'], '/unused', 'test-key', 'gpt-5-nano', 'Translate'));
    check($tester->execute([]) === 0, 'Source-identical translation run succeeds');
    check($storage->saved['fr']->get('promotion') === 'Promotion', 'Source-identical response saved');
}
check($promotionClient->getRequestsCount() === 1, 'Second run does not request source-identical translation again');

$manyLocales = explode(',', 'ar,az,cs,da,de,es,fa,fr,he,hr,hy,is,ja,ka,kk,ko,ky,lt,nl,pl,pt,ro,ru,sk,sr,tk,tr,uk,uz');
$compactClient = new MockHttpClient(function (string $method, string $url, array $options) use ($manyLocales, $config): MockResponse {
    check(in_array('Content-Type: application/json', $options['headers'], true), 'JSON content type');
    $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
    check(str_starts_with($body['instructions'], strtr($config['prompt'], ['{source_locale}' => 'en', '{target_locales}' => implode(', ', $manyLocales)])), 'Custom instructions preserved');
    check(str_contains($body['instructions'], 'translations[i][j]') && str_contains($body['instructions'], 'ровно 29 элементов') && str_contains($body['instructions'], 'ровно 2 строк'), 'Mandatory matrix ordering and dimensions');
    $schema = $body['text']['format']['schema'];
    check(!isset($schema['$defs']), 'No per-locale definitions');
    $matrixSchema = $schema['properties']['translations'];
    check($matrixSchema['minItems'] === 29 && $matrixSchema['maxItems'] === 29, 'Exactly 29 locale rows required');
    check($matrixSchema['items']['minItems'] === 2 && $matrixSchema['items']['maxItems'] === 2, 'Exactly two messages per row required');
    $payload = json_decode($body['input'], true, 512, JSON_THROW_ON_ERROR);
    check($payload === ['target_locales' => $manyLocales, 'messages' => ['Car history', 'Car #']], 'Compact string input');
    $translations = array_fill(0, count($manyLocales), ['Car history', 'Car #']);
    $compactBytes = strlen(json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    check($compactBytes < 250, 'Schema stays below 250 bytes');
    echo "Schema for 2 messages / 29 locales: $compactBytes bytes.\n";
    return new MockResponse(json_encode(responseBody($translations), JSON_THROW_ON_ERROR));
});
$compactCommand = new AutoTranslatorCommand($storage, $storage, $compactClient, 'en', $manyLocales, '/unused', 'test-key', 'gpt-5-nano', $config['prompt']);
$result = (new ReflectionMethod(AutoTranslatorCommand::class, 'getOpenAITranslations'))->invoke($compactCommand, [
    ['key' => 'history', 'text' => 'Car history', 'target_locales' => $manyLocales],
    ['key' => 'number', 'text' => 'Car #', 'target_locales' => $manyLocales],
]);
check(count($result) === 29 && $result['fr'][1] === 'Car #', 'Compact request retains translation mapping');

foreach ([
    [[array_fill(0, 100, 'ok')], '1 translation rows; expected 2 locales (pl, de), with 100 messages per row'],
    [[array_fill(0, 99, 'ok'), array_fill(0, 100, 'ok')], '99 translations for locale pl; expected 100'],
    [['pl' => ['ok']], 'expected a list of 2 locale rows, received an object'],
] as [$invalidMatrix, $diagnostic]) {
    [$status, $catalogues, $error] = runCommand(new MockHttpClient(new MockResponse(json_encode(responseBody($invalidMatrix), JSON_THROW_ON_ERROR))));
    check($status !== 0 && $catalogues === [] && str_contains($error?->getMessage() ?? '', $diagnostic), 'Malformed matrix reports expected and actual structure');
}

foreach ([['gpt-5-nano-2025-08-07', null, 'minimal'], ['gpt-5-nano', 'low', 'low'], ['custom-model', null, null]] as [$model, $effort, $expectedEffort]) {
    $effortClient = new MockHttpClient(function (string $method, string $url, array $options) use ($expectedEffort): MockResponse {
        $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
        check(($body['reasoning']['effort'] ?? null) === $expectedEffort, 'Reasoning effort selection');
        check($expectedEffort !== null || !isset($body['reasoning']), 'Other models retain API defaults');
        return new MockResponse(json_encode(responseBody([['Promotion']]), JSON_THROW_ON_ERROR));
    });
    $command = new AutoTranslatorCommand($storage, $storage, $effortClient, 'en', ['fr'], '/unused', 'test-key', $model, 'Translate', reasoningEffort: $effort);
    (new ReflectionMethod(AutoTranslatorCommand::class, 'getOpenAITranslations'))->invoke($command, [
        ['key' => 'promotion', 'text' => 'Promotion', 'target_locales' => ['fr']],
    ]);
}

$timeoutResponse = new MockResponse((function (): Generator {
    yield '';
})());
[$status, $catalogues, $error] = runCommand(new MockHttpClient($timeoutResponse));
check($status !== 0 && $catalogues === [] && $error instanceof Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface, 'Timeout prevents writing catalogues');

foreach ([
    responseBody(['too few']),
    responseBody([array_fill(0, 100, 'Only Polish')]),
    responseBody([array_fill(0, 99, 'ok'), array_fill(0, 100, 'ok')]),
    responseBody([array_fill(0, 101, 'ok'), array_fill(0, 100, 'ok')]),
    responseBody(array_fill(0, 3, array_fill(0, 100, 'ok'))),
    responseBody(['pl' => array_fill(0, 100, 'ok'), 'de' => array_fill(0, 100, 'ok')]),
    responseBody([array_fill_keys(range(1, 100), 'ok'), array_fill(0, 100, 'ok')]),
    responseBody([array_fill(0, 100, ''), array_fill(0, 100, 'ok')]),
    responseBody([array_fill(0, 100, 'ok'), array_fill(0, 100, null)]),
    responseBody([array_fill(0, 100, 'ok'), array_fill(0, 100, 123)]),
    ['status' => 'incomplete', 'output' => []],
    ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal']]]]],
] as $body) {
    [$status, $catalogues, $error] = runCommand(new MockHttpClient(new MockResponse(json_encode($body, JSON_THROW_ON_ERROR))));
    check($status !== 0 && $catalogues === [] && $error !== null, 'Invalid response must not write catalogue');
}
foreach ([new MockResponse('invalid JSON'), new MockResponse('{}', ['http_code' => 401])] as $response) {
    [$status, $catalogues, $error] = runCommand(new MockHttpClient($response));
    check($status !== 0 && $catalogues === [] && $error !== null, 'API error must not write catalogue');
}
$client = new MockHttpClient(function (): never { throw new RuntimeException('Unexpected request'); });
[$status, $catalogues, $error] = runCommand($client, '');
check($status === 1 && $catalogues === [] && $error === null && $client->getRequestsCount() === 0, 'Missing key fails before API call');

[$status, $catalogues, $error] = runCommand($client, 'test-key', ['en']);
check($status === 0 && $catalogues === [] && $client->getRequestsCount() === 0, 'No target locales means no requests');

$requests = 0;
$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
    if (++$requests > 1) {
        return new MockResponse('{}', ['http_code' => 500]);
    }
    $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
    $translations = [];
    $payload = json_decode($body['input'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($payload['target_locales'] as $locale) {
        $translations[] = array_fill(0, count($payload['messages']), 'Translated');
    }
    return new MockResponse(json_encode(responseBody($translations), JSON_THROW_ON_ERROR));
});
[$status, $catalogues, $error] = runCommand($client);
check($requests === 2 && $status !== 0 && $catalogues === [] && $error !== null, 'Later batch failure prevents writing all locales');

echo "All checks passed.\n";
