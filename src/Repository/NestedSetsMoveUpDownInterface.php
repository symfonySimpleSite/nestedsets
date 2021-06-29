<?php

namespace SymfonySimpleSite\NestedSets\Repository;

use SymfonySimpleSite\NestedSets\Entity\NodeInterface;

interface NestedSetsMoveUpDownInterface
{
    public function upDown(NodeInterface $node, bool $isUp = true): void;
}

