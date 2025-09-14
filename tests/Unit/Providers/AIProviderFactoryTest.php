<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Config;
use AICR\Providers\AIProviderFactory;
use AICR\Providers\AnthropicProvider;
use AICR\Providers\GeminiProvider;
use AICR\Providers\MockProvider;
use AICR\Providers\OllamaProvider;
use AICR\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class AIProviderFactoryTest extends TestCase
{
    private function makeConfig(array $cfg): Config
    {
        $r = new \ReflectionClass(Config::class);
        $obj = $r->newInstanceWithoutConstructor();
        $prop = $r->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($obj, $cfg);

        return $obj;
    }

    public function testBuildOpenAIProviderAndPromptsPassThrough(): void
    {
        $cfgArr = [
            'provider' => [
                'type' => 'openai',
                'openai' => [
                    'api_key' => 'k',
                    'model'   => 'm',
                ],
            ],
            'prompts' => [
                'system_append' => 'SYSX',
                'user_append'   => ['U1','U2'],
                'extra'         => ['E1'],
            ],
        ];
        $factory = new AIProviderFactory($this->makeConfig($cfgArr));
        $prov    = $factory->build();
        $this->assertInstanceOf(OpenAIProvider::class, $prov);

        // Reflect provider options to assert prompts preserved
        $rp = new \ReflectionProperty(OpenAIProvider::class, 'options');
        $rp->setAccessible(true);
        $opts = $rp->getValue($prov);
        $this->assertSame($cfgArr['prompts'], $opts['prompts'] ?? null);
    }

    public function testGuidelinesFileInjectionIntoPrompts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gl');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "abc-guidelines");

        $cfgArr = [
            'provider' => [
                'type' => 'openai',
                'openai'  => ['api_key' => 'k'],
            ],
            'prompts' => [
                'extra' => [],
            ],
            'guidelines_file' => $tmp,
        ];
        $factory = new AIProviderFactory($this->makeConfig($cfgArr));
        $prov    = $factory->build();

        $rp = new \ReflectionProperty(OpenAIProvider::class, 'options');
        $rp->setAccessible(true);
        $opts = $rp->getValue($prov);
        $extra = $opts['prompts']['extra'] ?? [];
        $this->assertIsArray($extra);
        $found = false;
        foreach ($extra as $x) {
            if (is_string($x) && str_contains($x, 'Coding guidelines file content') && str_contains($x, base64_encode('abc-guidelines'))) {
                $found = true; break;
            }
        }
        $this->assertTrue($found, 'Guidelines content should be injected into prompts.extra');

        @unlink($tmp);
    }

    public function testBuildOtherProvidersAndMock(): void
    {
        $cfgBase = ['prompts' => []];

        $configs = [
            [
                'provider' => [ 'type' => 'gemini', 'gemini' => ['api_key' => 'g'] ],
            ],
            [
                'provider' => [ 'type' => 'anthropic', 'anthropic' => ['api_key' => 'a'] ],
            ],
            [
                'provider' => [ 'type' => 'ollama', 'ollama' => [] ],
            ],
            [
                'provider' => [ 'type' => 'mock' ],
            ],
        ];

        $classes = [GeminiProvider::class, AnthropicProvider::class, OllamaProvider::class, MockProvider::class];

        foreach ($configs as $i => $cfg) {
            $cfgArr  = array_merge($cfgBase, $cfg);
            $factory = new AIProviderFactory($this->makeConfig($cfgArr));
            $prov    = $factory->build();
            $this->assertInstanceOf($classes[$i], $prov);
        }
    }

    public function testUnknownProviderThrows(): void
    {
        $cfgArr = [
            'provider' => [ 'type' => 'nope' ],
            'prompts'   => [],
        ];
        $factory = new AIProviderFactory($this->makeConfig($cfgArr));
        $this->expectException(\InvalidArgumentException::class);
        $factory->build();
    }
}
