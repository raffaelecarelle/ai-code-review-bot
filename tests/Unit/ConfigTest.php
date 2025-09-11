<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/aicr_cfg_'.uniqid('', true).'.yml';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    public function testEnvExpansionAndDefaults(): void
    {
        putenv('AICR_TEST_ENV=hello');
        $yaml = <<<YML
version: 1
providers:
  default: mock
context:
  diff_token_limit: 9000
custom_path: \${AICR_TEST_ENV}
YML;
        file_put_contents($this->tmp, $yaml);

        $cfg = Config::load($this->tmp);
        $all = $cfg->getAll();

        // Defaults merged
        $this->assertArrayHasKey('policy', $all);
        $this->assertArrayHasKey('rules', $all);
        // Overrides applied
        $this->assertSame(9000, $all['context']['diff_token_limit']);
        // Env expanded
        $this->assertSame('hello', $all['custom_path']);

        putenv('AICR_TEST_ENV'); // unset
    }
}
