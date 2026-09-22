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
        private string $prompt,
        private float $timeout = 300.0,
        private int $batchSize = 100,
        private ?string $reasoningEffort = null
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
                        $catalogue->get($key, $domain) === "__$value"
                    ) {
                        $locales[] = $locale;
                    }
                }
                if ($locales === []) {
                    continue;
                }
                $batch[] = ['key' => $key, 'text' => $value, 'target_locales' => $locales];
                if (count($batch) === $this->batchSize) {
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
        $inputs = array_column($messages, 'text');
        $targetLocales = [];
        foreach ($messages as $message) {
            foreach ($message['target_locales'] as $locale) {
                if (!in_array($locale, $targetLocales, true)) {
                    $targetLocales[] = $locale;
                }
            }
        }
        $locales = implode(', ', $targetLocales);

        $data = [
            'model' => $this->model,
            'store' => false,
            'instructions' => strtr($this->prompt, [
                    '{source_locale}' => $this->defaultLocale,
                    '{target_locales}' => $locales,
                    '{target_locale}' => $locales,
                ])."\nОбязательный формат ответа: translations — массив объектов {locale, values}. locale — точный код из target_locales, values[j] — перевод messages[j] на этот язык. Верни каждый запрошенный язык ровно один раз и ровно ".count($inputs)." строк в values. Сохраняй порядок сообщений. Эти требования к формату заменяют любые другие указания о структуре ответа.",
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
                                'type' => 'array',
                                'minItems' => count($targetLocales),
                                'maxItems' => count($targetLocales),
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'locale' => ['type' => 'string', 'enum' => $targetLocales],
                                        'values' => [
                                            'type' => 'array',
                                            'minItems' => count($inputs),
                                            'maxItems' => count($inputs),
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                    'required' => ['locale', 'values'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['translations'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $effort = $this->reasoningEffort;
        if ($effort === null && ($this->model === 'gpt-5-nano' || str_starts_with($this->model, 'gpt-5-nano-'))) {
            $effort = 'minimal';
        }
        if ($effort !== null) {
            $data['reasoning'] = ['effort' => $effort];
        }

        $options = [
            'auth_bearer' => $this->apiKey,
            'timeout' => $this->timeout,
            'json' => $data,
        ];

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', $options)->toArray();
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

        $matrix = json_decode($text, true, 512, JSON_THROW_ON_ERROR)['translations'] ?? null;
        if (!is_array($matrix) || !array_is_list($matrix)) {
            throw new RuntimeException('OpenAI returned an invalid translations structure: expected a list of '.count($targetLocales).' locale rows, received '.(is_array($matrix) ? 'an object' : get_debug_type($matrix)).'. Check auto_translator.prompt for outdated output-format instructions.');
        }
        if (count($matrix) !== count($targetLocales)) {
            throw new RuntimeException('OpenAI returned '.count($matrix).' translation rows; expected '.count($targetLocales).' locales ('.$locales.'), with '.count($inputs).' messages per row. Check auto_translator.prompt for outdated output-format instructions.');
        }
        $translations = [];
        foreach ($matrix as $row) {
            if (!is_array($row) || !isset($row['locale']) || !is_string($row['locale']) || !in_array($row['locale'], $targetLocales, true)) {
                throw new RuntimeException('OpenAI returned a missing or unexpected locale code.');
            }
            $locale = $row['locale'];
            if (array_key_exists($locale, $translations)) {
                throw new RuntimeException('OpenAI returned duplicate translations for locale '.$locale.'.');
            }
            $values = $row['values'] ?? null;
            if (!is_array($values) || !array_is_list($values)) {
                throw new RuntimeException('OpenAI returned an invalid translation row for locale '.$locale.'; expected a list of '.count($inputs).' strings.');
            }
            if (count($values) !== count($inputs)) {
                throw new RuntimeException('OpenAI returned '.count($values).' translations for locale '.$locale.'; expected '.count($inputs).'.');
            }
            foreach ($inputs as $index => $source) {
                $translation = $values[$index];
                if (!is_string($translation) || (trim($translation) === '' && trim($source) !== '')) {
                    throw new RuntimeException('OpenAI returned an invalid translation for locale '.$locale.'.');
                }
            }
            $translations[$locale] = $values;
        }

        return $translations;
    }

    private function translation(array $catalogues, array $inputs, string $domain): void
    {
        $translations = $this->getOpenAITranslations($inputs);

        foreach ($inputs as $index => $message) {
            foreach ($message['target_locales'] as $locale) {
                $catalogues[$locale]->set($message['key'], $translations[$locale][$index], $domain);
            }
        }
    }
}
