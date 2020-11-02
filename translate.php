<?php

require_once __DIR__ . '/vendor/autoload.php';

use Aws\Credentials\CredentialProvider;
use Aws\Translate\TranslateClient;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;

function replace(string $regex, string $original, array &$replacers)
{
    return preg_replace_callback($regex, function ($data) use (&$replacers) {
        $id = $data[0];
        $hash = '[' . microtime(true) . '.' . count($replacers) . ']';
        $replacers[$hash] = $id;
        return $hash;
    }, $original);
}

/**
 * @param string $dir
 * @return string[]|Generator
 */
function find(string $dir): Generator
{
    foreach (glob($dir . '/*') as $item) {
        if (is_dir($item)) {
            yield from find($dir);
        } elseif (preg_match('!\.po$!', $item)) {
            yield $item;
        }
    }
}

function translateFile(string $file, int $limit, PoLoader $loader, TranslateClient $client): void
{
    echo $file . "\n";
    $translations = $loader->loadFile($file);
    $save = false;
    $verbose = true;
    foreach ($translations as $translation) {
        assert($translation instanceof Translation);
        if ($translation->getTranslation() === null || $translation->getTranslation() === '') {
            $limit--;
            $replacers = [];
            $original = $translation->getOriginal();
            $toTranslate = $original;
            $toTranslate = replace('!<[^>]+>!', $toTranslate, $replacers);
            $toTranslate = replace('!\{[a-z0-9_-\}\{]+\}!i', $toTranslate, $replacers);
            $toTranslate = replace('!\%\{[^\}]+\}!', $toTranslate, $replacers);
            echo 'EN: ' . $original . "\n";
            $result = $client->translateText([
                'SourceLanguageCode' => 'en',
                'TargetLanguageCode' => 'uk',
                'Text' => $toTranslate,
            ]);
            $result = $result['TranslatedText'];
            $uk = strtr($result, $replacers);
            echo 'UK: ' . $uk . "\n";
            if ($verbose && $uk !== $result) {
                echo '<<<~~~~~~~~~~~~~~~~~~>>>' . "\n";
                echo '>>> ->: ' . $toTranslate . "\n";
                echo '>>> <-: ' . $result . "\n";
                echo '^^^^^^ ~~~~~~~~~~~~~~~~~~ //// ' . "\n";
            }
            echo '~~~~~~~~~~~~~~~~~~~' . "\n";
            $translation->translate($uk);
            $save = true;
            sleep(1);
        }
        if ($limit <= 0) {
            break;
        }
    }
    $translated = 0;
    foreach ($translations as $translation) {
        assert($translation instanceof Translation);
        $translated = $translated + (int)$translation->isTranslated();
    }
    echo 'Translated: ' . $translated . "\n";
    if ($save) {
        (new PoGenerator())->generateFile(
            $translations,
            $file
        );
        echo 'Saved' . "\n";
    }
}

$path = ($argv[1] ?? null);
$limit = (int)($argv[2] ?? 10);

$loader = new PoLoader();
$client = new TranslateClient([
    'region' => 'us-west-2',
    'version' => 'latest',
    'credentials' => CredentialProvider::ini('default', __DIR__ . '/credentials.ini')
]);

if (is_dir($path)) {
    foreach (find($path) as $file) {
        translateFile($file, $limit, $loader, $client);
    }
    return;
}
if (is_file($path)) {
    translateFile($path, $limit, $loader, $client);
    return;
}
echo "First argument should be valid file or directory path\n";
exit(2);
