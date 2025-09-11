<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsContainsExpectedKeys(): void
    {
        $d = Config::defaults();
        $this->assertIsArray($d);
        $this->assertArrayHasKey('providers', $d);
        $this->assertArrayHasKey('context', $d);
        $this->assertArrayHasKey('policy', $d);
        $this->assertArrayHasKey('rules', $d);
    }

    public function testLoadMergesAndExpandsEnvFromYaml(): void
    {
        $tmp = sys_get_temp_dir().'/aicr_test_'.uniqid('', true);
        $yaml = <<<'YML'
providers:
  default: openai
context:
  diff_token_limit: 100
policy:
  max_comments: 5
rules:
  inline: []
  include: []
YML;
        file_put_contents($tmp, $yaml);

        putenv('AICR_TEST_VAR=xyz');
        // Add an env-backed key to ensure expansion works
        $yaml2 = <<<'YML'
context:
  some_env: "${AICR_TEST_VAR}"
YML;
        $tmp2 = $tmp.'_2.yml';
        file_put_contents($tmp2, $yaml2);

        $cfg1 = Config::load($tmp);
        $cfg2 = Config::load($tmp2);

        $this->assertSame('openai', $cfg1->providers()['default']);
        $this->assertSame(100, $cfg1->context()['diff_token_limit']);
        $this->assertSame(5, $cfg1->policy()['max_comments']);

        $this->assertSame('xyz', $cfg2->context()['some_env']);

        @unlink($tmp);
        @unlink($tmp2);
    }
}
