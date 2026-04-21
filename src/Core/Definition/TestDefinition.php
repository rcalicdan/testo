<?php

declare(strict_types=1);

namespace Testo\Core\Definition;

use Testo\Inline\TestInline;

/**
 * @api
 */
final readonly class TestDefinition
{
    public function __construct(
        public \ReflectionFunctionAbstract $reflection,
    ) {}

    public function getDescription(): ?string
    {
        $attributes = $this->reflection->getDocComment();
        return $attributes === false ? null : self::clearPhpDoc($attributes);
    }

    /**
     * Cut the PHPDoc comment to get the description.
     */
    #[TestInline(["/**\n * Foo bar\n */"], 'Foo bar')]
    #[TestInline(["/**\n *\n * Foo bar\n *\n */"], 'Foo bar')]
    #[TestInline(["/**\n *\n Foo bar\n *\n */"], 'Foo bar')]
    #[TestInline(["/** Foo bar */"], 'Foo bar')]
    #[TestInline(["/** Foo bar\n */"], 'Foo bar')]
    #[TestInline(["/**\n * Foo * bar\n */"], 'Foo * bar')]
    #[TestInline(["/**\n * Foo\n * bar\n */"], "Foo\nbar")]
    #[TestInline(["/**\n\t* Foo\n\t*\n\t* - bar\n */"], "Foo\n\n- bar")]
    private static function clearPhpDoc(string $doc): string
    {
        $doc = \preg_replace('#^\s*/\*\*|\*/\s*$#', '', $doc);
        $doc = \preg_replace('#^\s*+\*[ \x0B\t\f\r]?#m', '', $doc);

        return \trim($doc);
    }

    public function __serialize(): array
    {
        if ($this->reflection instanceof \ReflectionMethod) {
            return [
                'type' => 'method',
                'class' => $this->reflection->getDeclaringClass()->getName(),
                'name' => $this->reflection->getName(),
            ];
        }
        return [
            'type' => 'function',
            'name' => $this->reflection->getName(),
        ];
    }

    public function __unserialize(array $data): void
    {
        if ($data['type'] === 'method') {
            $this->reflection = new \ReflectionMethod($data['class'], $data['name']);
        } else {
            $this->reflection = new \ReflectionFunction($data['name']);
        }
    }
}
