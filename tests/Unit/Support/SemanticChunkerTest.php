<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Support;

use AICR\Support\SemanticChunker;
use PHPUnit\Framework\TestCase;

final class SemanticChunkerTest extends TestCase
{
    public function testChunkByContextGroupsSimilarContexts(): void
    {
        $changes = [
            [
                'file_path' => 'src/ClassA.php',
                'unified_diff' => "+class ClassA {\n+    public function test() {}"
            ],
            [
                'file_path' => 'src/ClassB.php', 
                'unified_diff' => "+class ClassB {\n+    private \$var = 1;"
            ],
            [
                'file_path' => 'src/Helper.php',
                'unified_diff' => "+public function helper() {\n+    return true;\n+}"
            ],
            [
                'file_path' => 'src/Utils.php',
                'unified_diff' => "+private function utils() {\n+    \$result = process();\n+}"
            ]
        ];

        $chunks = SemanticChunker::chunkByContext($changes);

        $this->assertCount(2, $chunks, 'Should group class definitions and methods separately');
        
        // First chunk should contain class definitions
        $this->assertCount(2, $chunks[0], 'Class definition chunk should have 2 items');
        $this->assertStringContainsString('ClassA', $chunks[0][0]['unified_diff']);
        $this->assertStringContainsString('ClassB', $chunks[0][1]['unified_diff']);
        
        // Second chunk should contain method definitions
        $this->assertCount(2, $chunks[1], 'Method chunk should have 2 items');
        $this->assertStringContainsString('helper', $chunks[1][0]['unified_diff']);
        $this->assertStringContainsString('utils', $chunks[1][1]['unified_diff']);
    }

    public function testChunkByContextHandlesEmptyInput(): void
    {
        $chunks = SemanticChunker::chunkByContext([]);
        $this->assertEmpty($chunks);
    }

    public function testChunkByContextHandlesSingleItem(): void
    {
        $changes = [
            [
                'file_path' => 'src/Test.php',
                'unified_diff' => "+public function test() {\n+    return 'test';\n+}"
            ]
        ];

        $chunks = SemanticChunker::chunkByContext($changes);
        $this->assertCount(1, $chunks);
        $this->assertCount(1, $chunks[0]);
        $this->assertEquals($changes[0], $chunks[0][0]);
    }

    public function testGroupSimilarChangesAggregatesSimilarItems(): void
    {
        $changes = [
            [
                'file_path' => 'src/A.php',
                'unified_diff' => "+public function methodA() {\n+    return 1;\n+}"
            ],
            [
                'file_path' => 'src/B.php',
                'unified_diff' => "+public function methodB() {\n+    return 2;\n+}"
            ],
            [
                'file_path' => 'src/C.php',
                'unified_diff' => "+\$var = 'different';\n+echo \$var;"
            ]
        ];

        $grouped = SemanticChunker::groupSimilarChanges($changes);

        $this->assertCount(2, $grouped, 'Should group similar method changes but keep different ones separate');
        
        // Check that similar method changes were aggregated
        $aggregatedChange = array_filter($grouped, fn($change) => str_contains($change['file_path'], ','));
        $this->assertCount(1, $aggregatedChange, 'Should have one aggregated change');
        
        $aggregated = array_values($aggregatedChange)[0];
        $this->assertStringContainsString('src/A.php, src/B.php', $aggregated['file_path']);
        $this->assertStringContainsString('Aggregated method changes', $aggregated['unified_diff']);
    }

    public function testGroupSimilarChangesHandlesEmptyInput(): void
    {
        $grouped = SemanticChunker::groupSimilarChanges([]);
        $this->assertEmpty($grouped);
    }

    public function testDetectContextIdentifiesClassDefinitions(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $context = $method->invoke(null, "+class TestClass {\n+    private \$prop;");
        $this->assertEquals('class_definition', $context);
    }

    public function testDetectContextIdentifiesMethods(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $contexts = [
            "+public function test() {" => 'method',
            "+private function helper() {" => 'method',
            "+protected function process() {" => 'method'
        ];

        foreach ($contexts as $content => $expectedContext) {
            $context = $method->invoke(null, $content);
            $this->assertEquals($expectedContext, $context, "Failed for content: $content");
        }
    }

    public function testDetectContextIdentifiesVariableAssignments(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $context = $method->invoke(null, "+\$variable = 'value';");
        $this->assertEquals('variable_assignment', $context);
    }

    public function testDetectContextIdentifiesControlFlow(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $contexts = [
            "+if (\$condition) {" => 'control_flow',
            "+while (\$loop) {" => 'control_flow',
            "+for (\$x; \$x < 10; \$x++) {" => 'control_flow'
        ];

        foreach ($contexts as $content => $expectedContext) {
            $context = $method->invoke(null, $content);
            $this->assertEquals($expectedContext, $context, "Failed for content: $content");
        }
    }

    public function testDetectContextIdentifiesImportsAndNamespace(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $contexts = [
            "+namespace App\\Test;" => 'imports_namespace',
            "+use App\\Helper;" => 'imports_namespace'
        ];

        foreach ($contexts as $content => $expectedContext) {
            $context = $method->invoke(null, $content);
            $this->assertEquals($expectedContext, $context, "Failed for content: $content");
        }
    }

    public function testDetectContextIdentifiesDocumentation(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $contexts = [
            "+/* Comment */" => 'documentation',
            "+// Single line comment" => 'documentation'
        ];

        foreach ($contexts as $content => $expectedContext) {
            $context = $method->invoke(null, $content);
            $this->assertEquals($expectedContext, $context, "Failed for content: $content");
        }
    }

    public function testDetectContextDefaultsToGeneral(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('detectContext');
        $method->setAccessible(true);

        $context = $method->invoke(null, "+echo 'hello world';");
        $this->assertEquals('general', $context);
    }

    public function testGetChangeSignatureGeneratesConsistentSignatures(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('getChangeSignature');
        $method->setAccessible(true);

        $change1 = [
            'file_path' => 'src/A.php',
            'unified_diff' => "+public function test() {\n+    return 1;\n+}"
        ];
        
        $change2 = [
            'file_path' => 'src/B.php',
            'unified_diff' => "+public function different() {\n+    return 2;\n+}"
        ];

        $signature1 = $method->invoke(null, $change1);
        $signature2 = $method->invoke(null, $change2);

        $this->assertEquals($signature1, $signature2, 'Similar method changes should have same signature');
    }

    public function testCreateAggregatedChangeFormatsCorrectly(): void
    {
        $reflection = new \ReflectionClass(SemanticChunker::class);
        $method = $reflection->getMethod('createAggregatedChange');
        $method->setAccessible(true);

        $changes = [
            [
                'file_path' => 'src/A.php',
                'unified_diff' => "+public function testA() {\n+    return 'A';\n+}"
            ],
            [
                'file_path' => 'src/B.php',
                'unified_diff' => "+public function testB() {\n+    return 'B';\n+}"
            ],
            [
                'file_path' => 'src/C.php',
                'unified_diff' => "+public function testC() {\n+    return 'C';\n+}"
            ],
            [
                'file_path' => 'src/D.php',
                'unified_diff' => "+public function testD() {\n+    return 'D';\n+}"
            ]
        ];

        $aggregated = $method->invoke(null, $changes);

        $this->assertEquals('src/A.php, src/B.php, src/C.php and 1 more', $aggregated['file_path']);
        $this->assertStringContainsString('Aggregated method changes in 4 files', $aggregated['unified_diff']);
        $this->assertStringContainsString('testA', $aggregated['unified_diff']);
        $this->assertStringContainsString('testB', $aggregated['unified_diff']);
    }
}