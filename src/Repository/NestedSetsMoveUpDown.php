<?php

namespace SymfonySimpleSite\NestedSets\Repository;

use SymfonySimpleSite\NestedSets\DataDriver\DataDriverInterface;
use SymfonySimpleSite\NestedSets\Entity\NodeInterface;
use SymfonySimpleSite\NestedSets\Exception\NestedSetsNodeNotFoundException;
use PhpParser\Node;

class NestedSetsMoveUpDown extends AbstractBase implements NestedSetsMoveUpDownInterface
{
    public function upDown(NodeInterface $node, bool $isUp = true): void
    {
        try {
            $this->getEntityManager()->getConnection()->beginTransaction();

            $this->findExtreme($node, $isUp);

            if ($node->getParentId() == 0) {
                $this->moveRoot($node, $isUp);
            } else {
                $nextNode = $this->findNear($node, $isUp);

                if (empty($nextNode)) {
                    $nextNode = $this->findExtreme($node, $isUp);
                }

                if (empty($nextNode)) {
                    throw new NestedSetsNodeNotFoundException();
                }

                $this->exchangeKeys($node, $nextNode);
                $this->exchangeParentIdForSubItems($node, $nextNode);
            }

            $this->getEntityManager()->getConnection()->commit();
        } catch (\Throwable $exception) {
            $this->getEntityManager()->getConnection()->rollback();
            throw $exception;
        }
    }

    private function moveRoot(NodeInterface $node, bool $isUp = true): int
    {

        $near = $this->getNearRoot($node, !$isUp);

        if (empty($near)) {
            $near = $this->getFirstLastRoot($node, !$isUp);
        }

        $sql = "UPDATE `{$this->getTableName()}` SET `tree` = 0 WHERE `tree` = {$node->getTree()}; ";
        $sql .= "UPDATE `{$this->getTableName()}` SET `tree` = {$node->getTree()} WHERE `tree` = {$near->getTree()}; ";
        $sql .= "UPDATE `{$this->getTableName()}` SET `tree` = {$near->getTree()} WHERE `tree` = 0; ";

        return $this->getEntityManager()->getConnection()->executeQuery($sql)->rowCount();
    }

    private function getNearRoot(NodeInterface $node, bool $isUp): ?NodeInterface
    {
        $z = $isUp ? " > " : " < ";
        $sql = "SELECT * FROM {$this->getTableName()} WHERE `tree` {$z} {$node->getTree()} ORDER BY `tree` ASC";
        $result = $this->getEntityManager()->getConnection()->fetchAssociative($sql);

        if (empty($result)) {
            return null;
        }

        return $this->getEntity(  $result['id'],
            $result['lft'],
            $result['rgt'],
            $result['lvl'],
            $result['parent_id'],
            $result['tree']);
    }

    private function getFirstLastRoot(NodeInterface $node, bool $isLast): ?NodeInterface
    {
        $orderByType = $isLast ? "DESC" : "ASC";
        $sql = "SELECT * FROM {$this->getTableName()} ORDER BY `tree` $orderByType LIMIT 1";
        $result = $this->getEntityManager()->getConnection()->fetchAssociative($sql);

        if (empty($result)) {
            return null;
        }

        return $this->getEntity(  $result['id'],
            $result['lft'],
            $result['rgt'],
            $result['lvl'],
            $result['parent_id'],
            $result['tree']);
    }

    private function exchangeKeys(NodeInterface $node1, NodeInterface $node2): ?int
    {
        $lft = $node1->getLft();
        $rgt = $node1->getRgt();
        $lvl = $node1->getLvl();

        $parentId = $node1->getParentId();

        $sql = "UPDATE `{$this->getTableName()}` SET 
                            `lft` = {$node2->getLft()},
                            `rgt` = {$node2->getRgt()},
                            `lvl` = {$node2->getLvl()},                          
                            `parent_id` = {$node2->getParentId()}                          
                         WHERE `id` = {$node1->getId()} AND `tree` = {$node1->getTree()}       
                    ;";

        $sql .= "UPDATE `{$this->getTableName()}` SET 
                            `lft` = {$lft},
                            `rgt` = {$rgt},
                            `lvl` = {$lvl},
                            `parent_id` = {$parentId}
                         WHERE `id` = {$node2->getId()} AND `tree` = {$node1->getTree()}       
                    ;";

        try {
            return $this->getEntityManager()->getConnection()->executeQuery($sql)->rowCount();
        } catch (\Throwable $exception) {
            throw $exception;
        }
    }

    private function exchangeParentIdForSubItems(NodeInterface $node1, NodeInterface $node2): ?int
    {
        $sql = "UPDATE `{$this->getTableName()}` SET `parent_id` = {$node2->getId()}
                WHERE `lft` > {$node1->getLft()} AND                                
                     `rgt` < {$node1->getRgt()} AND 
                      `lvl` <= {$node1->getLvl()} + 1 AND
                      `tree` = {$node1->getTree()};";

        $sql .= "UPDATE `{$this->getTableName()}` SET `parent_id` = {$node1->getId()}
                WHERE `lft` > {$node2->getLft()} AND                                
                     `rgt` < {$node2->getRgt()} AND 
                      `lvl` <= {$node2->getLvl()} + 1 AND
                      `tree` = {$node1->getTree()};";


        try {
            return $this->getEntityManager()->getConnection()->executeQuery($sql)->rowCount();
        } catch (\Throwable $exception) {
            throw $exception;
        }
    }

    private function findNear(NodeInterface $node, bool $isUp = true): ?NodeInterface
    {
        $this->getEntityManager()->refresh($node);

        $sql = "SELECT * FROM `{$this->getTableName()}` WHERE ";
        $sql .= $isUp ? "`lft` < {$node->getLft()}" : "`lft` > {$node->getLft()}";
        //$sql .= $isUp ? "`rgt` < {$node->getRgt()}" : "`lft` > {$node->getLft()}";
        $sql .= " AND `tree` = {$node->getTree()} AND `parent_id` > 0";
        $sql .= " ORDER BY `lft` ";
        $sql .= ($isUp ? "DESC" : "ASC");
        $sql .= " LIMIT 1";
        $result = $this->getEntityManager()->getConnection()->fetchAssociative($sql);

        if (!empty($result)) {
            return $this->getEntity(
                $result['id'],
                $result['lft'],
                $result['rgt'],
                $result['lvl'],
                $result['parent_id'],
                $result['tree']
            );
        }

        return null;
    }

    private function findExtreme(NodeInterface $node, bool $isLast = true): ?NodeInterface
    {
        $sql = "SELECT * FROM `{$this->getTableName()}` WHERE";
        $sql .= !$isLast ? "`lft`= 2 AND " : "" ." `parent_id` > 0 AND";
        $sql .= "`tree` = {$node->getTree()}";
        $sql .= $isLast ? " ORDER BY `rgt` DESC" : "";
        $sql .= " LIMIT 1";
         $result = $this->getEntityManager()->getConnection()->fetchAssociative($sql);

        if (!empty($result)) {
            return $this->getEntity(
                $result['id'],
                $result['lft'],
                $result['rgt'],
                $result['lvl'],
                $result['parent_id'],
                $result['tree']
            );
        }
        return null;
    }

}