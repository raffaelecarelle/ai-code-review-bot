<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use AICR\Providers\AIProviderFactory;
use PHPUnit\Framework\TestCase;

final class AIProviderFactoryTest extends TestCase
{
    public function testWithPromptsInjectsGuidelines(): void
    {
        // Prepare a temp config file with guidelines_file
        $gl = sys_get_temp_dir().'/aicr_gl_'.uniqid('', true).'.txt';
        file_put_contents($gl, "Line A\nLine B");

        $cfgFile = sys_get_temp_dir().'/aicr_cfg_'.uniqid('', true).'.yml';
        $yaml = <<<YML
providers:
  default: mock
prompts:
  extra:
    - "hello"
# guidelines file path
guidelines_file: {$gl}
YML;
        file_put_contents($cfgFile, $yaml);
        $cfg = Config::load($cfgFile);
        @unlink($cfgFile);

        $factory = new AIProviderFactory($cfg);

        $ref = new \ReflectionClass($factory);
        $m = $ref->getMethod('withPrompts');
        $m->setAccessible(true);
        /** @var array<string,mixed> $opts */
        $opts = $m->invoke($factory, []);

        $this->assertArrayHasKey('prompts', $opts);
        $this->assertIsArray($opts['prompts']);
        $found = false;
        foreach (($opts['prompts']['extra'] ?? []) as $x) {
            if (is_string($x) && str_contains($x, 'Coding guidelines file content is provided below in base64')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected guidelines prompt to be injected');

        @unlink($gl);
    }
}
