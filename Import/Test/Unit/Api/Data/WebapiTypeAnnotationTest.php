<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Api\Data;

use Laminas\Code\Reflection\ClassReflection;
use Magento\Framework\Reflection\TypeProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Guards the `@return` annotations of every request DTO against the one mistake
 * that silently breaks the whole endpoint.
 *
 * Magento's ServiceInputProcessor types a nested property from its *getter's*
 * `@return` tag alone (the PHP return type and the setter's `@param` are never
 * consulted), and anything it cannot recognise as a simple type is treated as a
 * class name to instantiate. A tag the Laminas doc-block parser truncates —
 * `array<string, string|int>` collapses to `array` — therefore fails the entire
 * request with `Class "array" does not exist`, whatever the field's value is.
 * Free-form maps must be annotated `mixed`.
 */
class WebapiTypeAnnotationTest extends TestCase
{
    /**
     * @dataProvider getterProvider
     */
    public function testGetterReturnTypeIsResolvable(string $interface, string $method, string $type): void
    {
        $processor = new TypeProcessor();
        if ($processor->isTypeSimple($type) || $processor->isTypeAny($type)) {
            $this->assertTrue(true);
            return;
        }

        $itemType = $processor->getArrayItemType($type);
        $this->assertTrue(
            class_exists($itemType) || interface_exists($itemType),
            sprintf(
                '%s::%s() is annotated "%s", which the webapi type processor resolves to the class'
                . ' "%s". Use a simple type, an existing interface, or "mixed" for a free-form map.',
                $interface,
                $method,
                $type,
                $itemType
            )
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function getterProvider(): array
    {
        $processor = new TypeProcessor();
        $cases = [];

        foreach (glob(dirname(__DIR__, 4) . '/Api/Data/*Interface.php') as $file) {
            $interface = 'ReadyData\\Import\\Api\\Data\\' . basename($file, '.php');
            if (!interface_exists($interface)) {
                continue;
            }
            $reflection = new ClassReflection($interface);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getName(), 'get')) {
                    continue;
                }
                $type = $processor->getGetterReturnType($method)['type'];
                $cases[$interface . '::' . $method->getName()] = [$interface, $method->getName(), (string) $type];
            }
        }

        return $cases;
    }
}
