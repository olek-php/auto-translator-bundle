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

function runCommand(MockHttpClient $client, string $key = 'test-key', array $locales = ['en', 'pl', 'de']): array
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
                if ($catalogue->getLocale() === 'de') {
                    $catalogue->set('key1', 'Vorhanden', 'messages');
                }
            }
        }
    };
    $writer = new class implements TranslationWriterInterface {
        public array $catalogues = [];
        public function write(MessageCatalogue $catalogue, string $format, array $options = []): void
        {
            $this->catalogues[] = $catalogue;
        }
    };
    $command = new AutoTranslatorCommand($reader, $writer, $client, 'en', $locales, '/unused', $key, 'gpt-5-nano', 'Translate {source_locale} to {target_locales}');
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
$container = new ContainerBuilder();
(new AutoTranslatorExtension())->load([['api_key' => 'custom-key', 'model' => 'custom-model', 'prompt' => 'Custom prompt']], $container);
$arguments = $container->getDefinition(AutoTranslatorCommand::class)->getArguments();
check(array_slice($arguments, 6) === ['custom-key', 'custom-model', 'Custom prompt'], 'Configuration injection');

$batches = [];
$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$batches): MockResponse {
    check($method === 'POST' && $url === 'https://api.openai.com/v1/responses', 'Request endpoint');
    check(in_array('Authorization: Bearer test-key', $options['headers'], true), 'Authorization');
    $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
    check($body['model'] === 'gpt-5-nano' && str_starts_with($body['instructions'], 'Translate en to pl, de'), 'Request settings');
    check($body['text']['format']['strict'] === true, 'Structured output');
    $payload = json_decode($body['input'], true, 512, JSON_THROW_ON_ERROR);
    check($payload['target_locales'] === ['pl', 'de'], 'Shared target locales');
    $inputs = $payload['messages'];
    $batches[] = count($inputs);
    $translations = [];
    foreach ($inputs as $message) {
        if ($message['text'] === 'Message 1') {
            check($message['target_locales'] === ['pl'], 'Request only missing locales');
        } else {
            check(!array_key_exists('target_locales', $message), 'Full translations inherit shared locales');
        }
        foreach ($message['target_locales'] ?? $payload['target_locales'] as $locale) {
            $translations[$locale][$message['id']] = strtoupper($locale).': '.$message['text'];
        }
    }
    $schema = $body['text']['format']['schema']['properties']['translations'];
    check($schema['required'] === ['pl', 'de'], 'All target locales required by schema');
    return new MockResponse(json_encode(responseBody($translations), JSON_THROW_ON_ERROR));
});
[$status, $catalogues, $error] = runCommand($client);
check($status === 0 && $error === null, 'Successful translation: '.($error?->getMessage() ?? ''));
check(count($catalogues) === 2, 'Both target catalogues written');
check($catalogues[1]->get('key1') === 'Vorhanden', 'Preserve locale-specific existing translation');
check($catalogues[1]->get('key101') === 'DE: Message 101', 'German translation mapping');
check($batches === [100, 1, 1], 'Batch boundaries across domains');
check($catalogues[0]->get('key0') === 'Existing translation', 'Existing translation preserved');
check($catalogues[0]->get('key101') === 'PL: Message 101', 'Last batch entry');
check($catalogues[0]->get('multiline', 'other') === "PL: Hello {{ name }}\nWelcome %user%", 'Multiline and placeholders');

foreach ([
    responseBody(['too few']),
    responseBody(['pl' => ['message_0' => 'Only Polish']]),
    responseBody(['pl' => array_fill_keys(array_map(fn ($i) => 'message_'.$i, range(0, 99)), 'ok'), 'de' => array_fill_keys(array_map(fn ($i) => 'wrong_'.$i, range(1, 99)), 'ok')]),
    responseBody(array_fill(0, 100, '')),
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
    foreach ($payload['messages'] as $message) {
        foreach ($message['target_locales'] ?? $payload['target_locales'] as $locale) {
            $translations[$locale][$message['id']] = 'Translated';
        }
    }
    return new MockResponse(json_encode(responseBody($translations), JSON_THROW_ON_ERROR));
});
[$status, $catalogues, $error] = runCommand($client);
check($requests === 2 && $status !== 0 && $catalogues === [] && $error !== null, 'Later batch failure prevents writing all locales');

echo "All checks passed.\n";
