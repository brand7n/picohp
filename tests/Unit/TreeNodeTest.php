<?php

declare(strict_types=1);

use App\PicoHP\Tree\{NodeTrait, NodeInterface};

// Example usage
class TestNode implements NodeInterface
{
    use NodeTrait;

    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

it('can manipulate a tree with the tree trait', function () {
    $root = new TestNode('root');
    $child1 = new TestNode('child1');
    $child2 = new TestNode('child2');

    $root->addChild($child1);
    $root = $child1->getRoot();
    assert($root instanceof TestNode);
    $root->addChild($child2);

    expect($root->getName())->toBe('root');
    expect($child1->getName())->toBe('child1');
    expect($child2->getName())->toBe('child2');

    expect($root->hasChildren())->toBeTrue();
    $parent = $child1->getParent();
    assert($parent instanceof TestNode);
    expect($parent->getName())->toBe('root');

    $root->removeChild($child1);
    $root->removeChild($child2);
    expect($root->hasChildren())->toBeFalse();
});
