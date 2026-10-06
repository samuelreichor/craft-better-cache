<?php

// Tests boot a real Craft install with this plugin installed, by default the showcase project around this repo.
require getenv('CRAFT_BOOTSTRAP_PATH') ?: dirname(__DIR__, 3) . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
