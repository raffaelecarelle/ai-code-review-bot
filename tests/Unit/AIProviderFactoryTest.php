<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use AICR\Providers\AIProviderFactory;
use AICR\Providers\MockProvider;
use PHPUnit\Framework\TestCase;

final class AIProviderFactoryTest extends TestCase
{
    public function testBuildReturnsMockByDefault(): void
    {
        $cfg = Config::load(null);
        $factory = new AIProviderFactory($cfg);
        $provider = $factory->build(null);
        $this->assertInstanceOf(MockProvider::class, $provider);
    }

    public function testWithPromptsMergesAndInjectsGuidelines(): void
    {
        $tmpCfg = sys_get_temp_dir().'/aicr_factory_'.uniqid('', true).'.yml';
        $guidelines = sys_get_temp_dir().'/aicr_guidelines_'.uniqid('', true).'.txt';
        file_put_contents($guidelines, "Line A\nLine B\n");
        $yaml = <<<YML
providers:
  default: mock
guidelines_file: {$guidelines}
prompts:
  system_append: "S1"
  user_append:
    - "U1"
  extra:
    - "E1"
YML;
        file_put_contents($tmpCfg, $yaml);
        $cfg = Config::load($tmpCfg);
        @unlink($tmpCfg);

        $factory = new AIProviderFactory($cfg);
        $provider = $factory->build();
        $this->assertInstanceOf(MockProvider::class, $provider);

        // Inspect effective prompts via reflection on factory private withPrompts is not possible, so we assert indirectly by re-reading the config
        $all = $cfg->getAll();
        $this->assertArrayHasKey('prompts', $all);
        $prompts = $all['prompts'];
        $this->assertIsArray($prompts);
        $this->assertSame('S1', $prompts['system_append']);
        $this->assertContains('E1', $prompts['extra']);
        $hasGuidelinesHint = false;
        foreach ($prompts['extra'] as $extra) {
            if (is_string($extra) && str_contains($extra, 'Coding guidelines file content is provided below in base64')) {
                $hasGuidelinesHint = true;
                break;
            }
        }
        $this->assertTrue($hasGuidelinesHint, 'Expected base64 guidelines hint injected into prompts.extra');
        @unlink($guidelines);
    }
}
