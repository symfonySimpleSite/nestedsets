<?php

namespace SymfonySimpleSite\NestedSets\Repository;

use SymfonySimpleSite\NestedSets\Entity\NodeInterface;

interface NestedSetsMoveItemsInterface
{
    public function move(NodeInterface $node, ?NodeInterface $parent): void;
}
