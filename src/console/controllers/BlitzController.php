<?php

namespace samuelreichor\betterCache\console\controllers;

use Craft;
use craft\console\Controller;
use samuelreichor\betterCache\helpers\BlitzConfig;
use yii\console\ExitCode;
use yii\helpers\VarDumper;

/**
 * Helps switching from Blitz.
 */
class BlitzController extends Controller
{
    /**
     * Prints config/blitz.php translated to a config/better-cache.php.
     */
    public function actionConfig(): int
    {
        $path = Craft::$app->getConfig()->getConfigFilePath('blitz');
        if (!is_file($path)) {
            $this->stderr("$path does not exist.\n");
            return ExitCode::NOINPUT;
        }

        $blitz = require $path;
        if (!is_array($blitz)) {
            $this->stderr("$path does not return an array.\n");
            return ExitCode::DATAERR;
        }

        $environments = isset($blitz['*']) ? $blitz : [null => $blitz];
        $config = [];
        $lines = ['<?php', '', '// Translated from config/blitz.php by `php craft better-cache/blitz/config`.'];
        foreach ($environments as $environment => $settings) {
            [$translated, $notes] = BlitzConfig::translate(is_array($settings) ? $settings : []);
            $config[$environment] = $translated;
            foreach ($notes as $note) {
                $note = ($environment === '' ? '' : "$environment: ") . $note;
                $lines[] = '// ' . wordwrap($note, 100, "\n//   ");
            }
        }

        $queue = Craft::$app->getPlugins()->pluginConfigs['blitz']['queue'] ?? null;
        if (is_string($queue)) {
            $lines[] = "// queue: set 'pluginConfigs' => ['better-cache' => ['queue' => '$queue']] in config/app.php.";
        }

        $lines[] = '';
        $lines[] = 'return ' . VarDumper::export(isset($blitz['*']) ? $config : $config['']) . ';';
        $this->stdout(implode("\n", $lines) . "\n");

        return ExitCode::OK;
    }
}
