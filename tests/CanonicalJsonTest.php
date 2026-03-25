<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Support\Json\CanonicalJson;

final class CanonicalJsonTest extends TestCase
{
    public function testAssociativeKeysAreSortedRecursively(): void
    {
        $json = CanonicalJson::encode([
            'b' => 2,
            'a' => [
                'd' => 4,
                'c' => 3,
            ],
        ]);

        self::assertSame("{\n    \"a\": {\n        \"c\": 3,\n        \"d\": 4\n    },\n    \"b\": 2\n}\n", $json);
    }

    public function testListsPreserveOrder(): void
    {
        $json = CanonicalJson::encode([
            'items' => [
                ['b' => 2, 'a' => 1],
                ['d' => 4, 'c' => 3],
            ],
        ]);

        self::assertStringContainsString("\"items\": [", $json);
        self::assertStringContainsString("{\n            \"a\": 1,\n            \"b\": 2\n        }", $json);
        self::assertStringContainsString("{\n            \"c\": 3,\n            \"d\": 4\n        }", $json);
    }

    public function testEquivalentStructuresProduceIdenticalOutput(): void
    {
        $left = CanonicalJson::encode(['z' => 1, 'a' => ['y' => 2, 'x' => 3]]);
        $right = CanonicalJson::encode(['a' => ['x' => 3, 'y' => 2], 'z' => 1]);

        self::assertSame($left, $right);
    }

    public function testOutputEndsWithNewline(): void
    {
        self::assertStringEndsWith("\n", CanonicalJson::encode(['a' => 1]));
    }
}
