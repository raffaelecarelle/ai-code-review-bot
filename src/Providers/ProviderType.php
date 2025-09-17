<?php

declare(strict_types=1);

namespace AICR\Providers;

/**
 * Enum for AI provider types.
 * Eliminates dependency on Pipeline class constants (DIP compliance).
 */
enum ProviderType: string
{
    case OPENAI    = 'openai';
    case GEMINI    = 'gemini';
    case ANTHROPIC = 'anthropic';
    case OLLAMA    = 'ollama';
}
