<?php

require_once __DIR__ . '/vendor/autoload.php';

use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Yandex\Translate\Translator;

$key = (string)getenv('YANDEX_TRANSLATE_API_KEY');
if ($key === '') {
    echo "Invalid environment variable: YANDEX_TRANSLATE_API_KEY \n";
    exit(1);
}
$translator = new Translator($key);

//import from a .po file:
$loader = new PoLoader();
$file = __DIR__ . '/' . $argv[1];
if (!is_file($file)) {
    echo "Invalid file path.\n";
    exit(2);
}
$translations = $loader->loadFile($file);
$save = false;
$limit = (int)($argv[2] ?? 10);
$verbose = true;
function replace(string $regex, string $original, array &$replacers)
{
    return preg_replace_callback($regex, function ($data) use (&$replacers) {
        $id = $data[0];
        $hash = '[' . microtime(true) . '.' . count($replacers) . ']';
        $replacers[$hash] = $id;
        return $hash;
    }, $original);
}

foreach ($translations as $translation) {
    assert($translation instanceof Translation);
    if ($translation->getTranslation() === null || $translation->getTranslation() === '') {
        $limit--;
        $replacers = [];
        $original = $translation->getOriginal();
        $toTranslate = $original;
        $toTranslate = replace('!<[^>]+>!', $toTranslate, $replacers);
        $toTranslate = replace('!\%\{[^\}]+\}!', $toTranslate, $replacers);
        echo 'EN: ' . $original . "\n";
        $result = $translator->translate($toTranslate, 'en-uk')->__toString();
        $uk = strtr($result, $replacers);
        echo 'UA: ' . $uk . "\n";
        if ($verbose && $uk !== $result) {
            echo '<<<>>>' . "\n";
            echo '>>> ->: ' . $toTranslate . "\n";
            echo '>>> <-: ' . $result . "\n";
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
