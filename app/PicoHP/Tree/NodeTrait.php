<?php

declare(strict_types=1);

namespace App\PicoHP\Tree;

trait NodeTrait
{
    private ?NodeInterface $parent = null;
    /**
     * @var array<NodeInterface>
     */
    private $children = [];

    // Set the parent node
    public function setParent(?NodeInterface $parent): void
    {
        $this->parent = $parent;
    }

    // Get the parent node
    public function getParent(): ?NodeInterface
    {
        return $this->parent;
    }

    // Add a child node
    public function addChild(NodeInterface $child): void
    {
        $this->children[] = $child;
        $child->setParent($this);
    }

    // Remove a child node
    public function removeChild(NodeInterface $child): void
    {
        $index = array_search($child, $this->children, true);
        if ($index !== false) {
            assert(is_int($index));
            array_splice($this->children, $index, 1);
            $child->setParent(null);
        }
    }

    // Get all child nodes
    /**
     * @return array<NodeInterface>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    // Check if the node has children
    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    // Get the root node
    public function getRoot(): NodeInterface
    {
        $node = $this;
        while ($node->getParent() !== null) {
            $node = $node->getParent();
        }
        return $node;
    }
}
