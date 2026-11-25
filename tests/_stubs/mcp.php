<?php

/**
 * Stand-ins for the Craft 5.12.1 / mcp/sdk 0.9 classes Microscope's MCP tools use, for
 * Microscope's own dev install (Craft 5.10, which has no MCP server). PHPStan reads them through
 * `scanFiles`. Each one is only declared when the real class is missing, and mirrors its signature.
 *
 * Never loaded by the plugin itself: on a real site the tools are only registered on 5.12.1+.
 */

namespace craft\mcp {
    use Craft;
    use craft\elements\User;
    use Mcp\Exception\ToolCallException;

    if (!class_exists(McpActor::class)) {
        class McpActor
        {
            public function user(): User
            {
                $user = Craft::$app->getUser()->getIdentity();

                if (!$user instanceof User) {
                    throw new ToolCallException('Authentication is required.');
                }

                return $user;
            }
        }
    }
}

namespace craft\mcp\attributes {
    use Attribute;

    if (!class_exists(RequiresPermission::class)) {
        #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
        readonly class RequiresPermission
        {
            public function __construct(public string $permission)
            {
            }
        }
    }
}

namespace craft\services {
    if (!class_exists(Mcp::class)) {
        class Mcp extends \yii\base\Component
        {
            public const EVENT_REGISTER_CAPABILITY_TYPES = 'registerCapabilityTypes';
        }
    }
}

namespace Mcp\Exception {
    if (!interface_exists(ExceptionInterface::class)) {
        interface ExceptionInterface extends \Throwable
        {
        }
    }

    if (!class_exists(ToolCallException::class)) {
        final class ToolCallException extends \RuntimeException implements ExceptionInterface
        {
        }
    }
}

namespace Mcp\Schema {
    if (!class_exists(ToolAnnotations::class)) {
        class ToolAnnotations
        {
            public function __construct(
                public readonly ?string $title = null,
                public readonly ?bool $readOnlyHint = null,
                public readonly ?bool $destructiveHint = null,
                public readonly ?bool $idempotentHint = null,
                public readonly ?bool $openWorldHint = null,
            ) {
            }
        }
    }
}

namespace Mcp\Capability\Attribute {
    use Mcp\Schema\ToolAnnotations;

    if (!class_exists(McpTool::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
        class McpTool
        {
            /**
             * @param array<mixed>|null $icons
             * @param array<string, mixed>|null $meta
             * @param array<string, mixed>|null $outputSchema
             */
            public function __construct(
                public ?string $name = null,
                public ?string $title = null,
                public ?string $description = null,
                public ?ToolAnnotations $annotations = null,
                public ?array $icons = null,
                public ?array $meta = null,
                public ?array $outputSchema = null,
            ) {
            }
        }
    }

    if (!class_exists(Schema::class)) {
        #[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_METHOD)]
        class Schema
        {
            /**
             * @param array<string, mixed>|null $definition
             * @param array<mixed>|null $enum
             * @param array<string, mixed>|null $items
             * @param array<string, mixed>|null $properties
             * @param string[]|null $required
             * @param bool|array<string, mixed>|null $additionalProperties
             */
            public function __construct(
                ?array $definition = null,
                ?string $type = null,
                ?string $description = null,
                ?array $enum = null,
                ?string $format = null,
                ?int $minLength = null,
                ?int $maxLength = null,
                ?string $pattern = null,
                int|float|null $minimum = null,
                int|float|null $maximum = null,
                ?bool $exclusiveMinimum = null,
                ?bool $exclusiveMaximum = null,
                int|float|null $multipleOf = null,
                ?array $items = null,
                ?int $minItems = null,
                ?int $maxItems = null,
                ?bool $uniqueItems = null,
                ?array $properties = null,
                ?array $required = null,
                bool|array|null $additionalProperties = null,
            ) {
            }
        }
    }
}
