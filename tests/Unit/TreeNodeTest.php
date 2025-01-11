<?php

declare(strict_types=1);

use App\PicoHP\Tree\{NodeTrait, NodeInterface};

// Example usage
class TestNode implements NodeInterface
{
    use NodeTrait;

    private string $value;

    public function __construct(string $value)
    {
        $this->setValue($value);
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

it('can manipulate a tree with the tree trait', function () {
    $root = new TestNode('root');
    $child1 = new TestNode('child1');
    $child2 = new TestNode('child2');

    $root->addChild($child1);
    $root->addChild($child2);

    expect($root->getValue())->toBe('root');
    expect($child1->getValue())->toBe('child1');
    expect($child2->getValue())->toBe('child2');

    expect($root->hasChildren())->toBeTrue();
    $parent = $child1->getParent();
    assert($parent instanceof TestNode);
    expect($parent->getValue())->toBe('root');
});
