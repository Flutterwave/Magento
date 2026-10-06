<?php
declare(strict_types=1);

namespace Flutterwave\Payment\Test\Unit\Model\Config;

use Flutterwave\Payment\Model\Config\Source\Environment;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    public function testProvidesSandboxAndProductionOptions(): void
    {
        $options = (new Environment())->toOptionArray();

        $this->assertSame(['sandbox', 'production'], array_column($options, 'value'));
        $this->assertSame(['Sandbox', 'Production'], array_map('strval', array_column($options, 'label')));
    }
}
