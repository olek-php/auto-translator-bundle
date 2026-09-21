<?php

namespace Olek\Bundle\AutoTranslatorBundle\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Reader\TranslationReaderInterface;
use Symfony\Component\Translation\Writer\TranslationWriterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AutoTranslatorCommand extends Command
{
    public function __construct(
        private TranslationReaderInterface $reader,
        private TranslationWriterInterface $writer,
        private HttpClientInterface $httpClient,
        private string $defaultLocale,
        private array $enabledLocales,
        private string $path,
        private string $apiKey,
        private string $model,
        private string $prompt
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName("translation:auto-update")
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Override the default output format.', 'xlf12')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (trim($this->apiKey) === '') {
            $io->error('Configure auto_translator.api_key before running translations.');

            return Command::FAILURE;
        }

        $format = $input->getOption('format');
        $xliffVersion = '1.2';
        switch ($format) {
            case 'xlf20': $xliffVersion = '2.0';
            // no break
            case 'xlf12': $format = 'xlf';
        }

        $writeOptions = [
            "path" => $this->path,
            "xliff_version" => $xliffVersion,
            "default_locale" => $this->defaultLocale,
        ];

        $defaultCatalogue = new MessageCatalogue($this->defaultLocale);
        $this->reader->read($this->path, $defaultCatalogue);

        $catalogues = [];
        foreach (array_unique($this->enabledLocales) as $locale) {
            if ($locale === $this->defaultLocale) {
                continue;
            }
            $catalogues[$locale] = new MessageCatalogue($locale);
            $this->reader->read($this->path, $catalogues[$locale]);
        }

        foreach ($defaultCatalogue->all() as $domain => $messages) {
            $batch = [];
            foreach ($messages as $key => $value) {
                $locales = [];
                foreach ($catalogues as $locale => $catalogue) {
                    if (!$catalogue->defines($key, $domain) ||
                        $catalogue->get($key, $domain) === $value ||
                        $catalogue->get($key, $domain) === "__$value"
                    ) {
                        $locales[] = $locale;
                    }
                }
                if ($locales === []) {
                    continue;
                }
                $batch[] = ['key' => $key, 'text' => $value, 'target_locales' => $locales];
                if (count($batch) === 100) {
                    $this->translation($catalogues, $batch, $domain);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->translation($catalogues, $batch, $domain);
            }
        }

        foreach ($catalogues as $catalogue) {
            foreach ($catalogue->all() as $domain => $messages) {
                foreach ($messages as $key => $value) {
                    if ($defaultCatalogue->defines($key, $domain) === false) {
                        unset($messages[$key]);
                    }
                }
                $catalogue->replace($messages, $domain);
            }

            $this->writer->write($catalogue, $format, $writeOptions);
            $io->writeln("Save: ". $catalogue->getLocale());
        }

        $io->success("Success");

        return 0;
    }

    private function getOpenAITranslations(array $messages): array
    {
        $inputs = [];
        $localeSchemas = [];
        foreach ($messages as $index => $message) {
            $id = 'message_'.$index;
            $inputs[] = ['id' => $id, 'text' => $message['text'], 'target_locales' => $message['target_locales']];
            foreach ($message['target_locales'] as $locale) {
                $localeSchemas[$locale] ??= [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                    'additionalProperties' => false,
                ];
                $localeSchemas[$locale]['properties'][$id] = ['type' => 'string'];
                $localeSchemas[$locale]['required'][] = $id;
            }
        }
        $targetLocales = array_keys($localeSchemas);
        foreach ($inputs as &$message) {
            if (array_diff($targetLocales, $message['target_locales']) === []) {
                unset($message['target_locales']);
            }
        }
        unset($message);
        $locales = implode(', ', $targetLocales);

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
            'auth_bearer' => $this->apiKey,
            'json' => [
                'model' => $this->model,
                'store' => false,
                'instructions' => strtr($this->prompt, [
                    '{source_locale}' => $this->defaultLocale,
                    '{target_locales}' => $locales,
                    '{target_locale}' => $locales,
                ])."\nВерни JSON-объект с полем translations: ключи первого уровня — коды локалей, второго — исходные id сообщений. Переводи сообщения из массива messages на все языки общего поля target_locales. Если у сообщения есть собственное поле target_locales, оно полностью заменяет общий список для этого сообщения.",
                'input' => json_encode([
                    'target_locales' => $targetLocales,
                    'messages' => $inputs,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'translations',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'translations' => [
                                    'type' => 'object',
                                    'properties' => $localeSchemas,
                                    'required' => array_keys($localeSchemas),
                                    'additionalProperties' => false,
                                ],
                            ],
                            'required' => ['translations'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ],
        ])->toArray();

        if (($response['status'] ?? null) !== 'completed') {
            throw new RuntimeException('OpenAI did not complete the translation for locales '.$locales.'.');
        }

        $text = "";
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('OpenAI refused the translation for locales '.$locales.'.');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text .= $content['text'];
                }
            }
        }

        $translations = json_decode($text, true, 512, JSON_THROW_ON_ERROR)['translations'] ?? null;
        if (!is_array($translations) || count($translations) !== count($localeSchemas)) {
            throw new RuntimeException('OpenAI returned an unexpected set of translation locales.');
        }
        foreach ($localeSchemas as $locale => $schema) {
            $values = $translations[$locale] ?? null;
            if (!is_array($values) || count($values) !== count($schema['required'])) {
                throw new RuntimeException('OpenAI returned an unexpected number of translations for locale '.$locale.'.');
            }
        }
        foreach ($inputs as $message) {
            foreach ($message['target_locales'] ?? $targetLocales as $locale) {
                $translation = $translations[$locale][$message['id']] ?? null;
                if (!is_string($translation) || (trim($translation) === '' && trim($message['text']) !== '')) {
                    throw new RuntimeException('OpenAI returned an invalid translation for locale '.$locale.'.');
                }
            }
        }

        return $translations;
    }

    private function translation(array $catalogues, array $inputs, string $domain): void
    {
        $translations = $this->getOpenAITranslations($inputs);

        foreach ($inputs as $index => $message) {
            foreach ($message['target_locales'] as $locale) {
                $catalogues[$locale]->set($message['key'], $translations[$locale]['message_'.$index], $domain);
            }
        }
    }
}
