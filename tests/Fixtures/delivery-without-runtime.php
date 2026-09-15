<?php

/** This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */
declare(strict_types=1);
$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
$loader->unregister();
$blocked = match ($argv[1]) {
    'runtime' => 'Milpa\\AppRuntime\\',
    'old' => 'Milpa\\AppRuntime\\Agent\\DeliveryScope',
    'agent' => 'Milpa\\Agent\\',
};
spl_autoload_register(static function (string $class) use ($loader, $blocked): void {
    if (!str_starts_with($class, $blocked)) {
        $loader->loadClass($class);
    }
});
$reader = new Milpa\AgentWorkspace\Data\DeliveryEvidence(sys_get_temp_dir(), new Milpa\Container\DIContainer());
echo json_encode(['missing' => !class_exists($blocked === 'Milpa\\AppRuntime\\Agent\\DeliveryScope' ? $blocked : $blocked . ($argv[1] === 'agent' ? 'SessionStore' : 'Agent\\DeliveryScope')),'sample' => $reader->read('session')], JSON_THROW_ON_ERROR),"\n";
