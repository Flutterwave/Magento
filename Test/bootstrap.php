<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Magento's translation helper __() is normally loaded by app/functions.php, which a standalone module lacks.
require_once __DIR__ . '/../vendor/magento/framework/Phrase/__.php';

// Magento generates *Factory classes at compile time (setup:di:compile). Generate a minimal
// equivalent on demand so they can be mocked without a Magento installation.
spl_autoload_register(static function (string $class): void {
    if (!str_ends_with($class, 'Factory')) {
        return;
    }
    $target = substr($class, 0, -strlen('Factory'));
    if (!class_exists($target) && !interface_exists($target)) {
        return;
    }
    $pos = strrpos($class, '\\');
    eval(sprintf(
        'namespace %s; class %s { public function create(array $data = []) { throw new \LogicException("Generated factory stub"); } }',
        substr($class, 0, $pos),
        substr($class, $pos + 1)
    ));
});
