<?php

namespace TaxiAdmin\Bundle\AutoTranslatorBundle\Command;

use DOMDocument;
use DOMXPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Reader\TranslationReaderInterface;
use Symfony\Component\Translation\Writer\TranslationWriterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class AutoTranslatorCommand extends Command
{

    /**
     * @var TranslationReaderInterface
     */
    private $reader;
    /**
     * @var TranslationWriterInterface
     */
    private $writer;
    /**
     * @var HttpClientInterface
     */
    private $httpClient;
    /**
     * @var string
     */
    private $defaultLocale;
    /**
     * @var array
     */
    private $enabledLocales;
    /**
     * @var string
     */
    private $path;

    public function __construct(
        TranslationReaderInterface $reader,
        TranslationWriterInterface $writer,
        HttpClientInterface $httpClient,
        string $defaultLocale,
        array $enabledLocales,
        string $path
    )
    {
        $this->reader = $reader;
        $this->writer = $writer;
        $this->httpClient = $httpClient;
        $this->defaultLocale = $defaultLocale;
        $this->enabledLocales = $enabledLocales;
        $this->path = $path;

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

        foreach ($this->enabledLocales as $locale) {
            if ($locale === $this->defaultLocale) {
                continue;
            }
            $catalogue = new MessageCatalogue($locale);
            $this->reader->read($this->path, $catalogue);

            $qtyNotDefines = 0;
            foreach ($defaultCatalogue->all() as $domain => $messages) {
                $notDefines = [];
                foreach ($messages as $key => $value) {
                    if ($catalogue->defines($key, $domain) === false ||
                        $catalogue->get($key, $domain) === $value ||
                        $catalogue->get($key, $domain) === "__$value"
                    ) {
                        $notDefines[$key] = $value;
                        $qtyNotDefines++;
                        if ($qtyNotDefines === 100) {
                            $this->translation($catalogue, $notDefines, $domain);
                            $qtyNotDefines = 0;
                        }
                    }
                }
                if ($qtyNotDefines > 0) {
                    $this->translation($catalogue, $notDefines, $domain);
                }
            }

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

    private function getGoogleTranslation(string $message, string $locale): string
    {
        $rr = [];
        $rrIndex = 0;
        $query = preg_replace_callback("/{{[\s\w]+}}/", static function ($matches) use (&$rr, &$rrIndex) {
            $result = "{{{$rrIndex}}}";
            $rrIndex++;
            $rr[$result] = $matches[0];
            return $result;
        }, $message);

        $url = "https://translate.google.com/m";
        $options = [
            "query" => [
                "sl"    => $this->defaultLocale,
                "tl"    => $locale,
                "hl"    => $this->defaultLocale,
                "q"     => $query,
            ]
        ];
        try {
            $response = $this->httpClient->request("GET", $url, $options);
            $html = $response->getContent();
        } catch (Throwable $e) {
            return $message;
        }


        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR);

        $xpath = new DOMXpath($dom);
        $result = $this->parseToArray($xpath);
        if (empty($result)) {
            return $message;
        }
        $translateRaw = urldecode($result[0]);
        if (empty($rr)) {
            return $translateRaw;
        }

        return str_replace(array_keys($rr), array_values($rr), $translateRaw);
    }

    private function parseToArray($xpath): array
    {
        $query = "//div[@class='result-container']";
        $elements = $xpath->query($query);

        $result = [];
        foreach ($elements as $element) {
            $nodes = $element->childNodes;
            foreach ($nodes as $node) {
                $result[] = $node->nodeValue;
            }
        }
        return $result;
    }

    private function translation(MessageCatalogue $catalogue, array $inputs, string $domain): void
    {
        $string = implode("\r\n", array_values($inputs));
        $translationString = $this->getGoogleTranslation($string, $catalogue->getLocale());
        $translations = explode("\r\n", $translationString);

        if (count($inputs) !== count($translations)) {
            foreach ($inputs as $key => $value) {
                $translation = $this->getGoogleTranslation($value, $catalogue->getLocale());
                $catalogue->set($key, $translation, $domain);
            }
            return;
        }

        foreach (array_keys($inputs) as $i => $key) {
            $catalogue->set($key, $translations[$i], $domain);
        }
    }
}