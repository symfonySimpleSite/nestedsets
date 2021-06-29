<?php

namespace SymfonySimpleSite\NestedSets\Repository;

use SymfonySimpleSite\NestedSets\Entity\NodeInterface;

class NestedSetsCreateDelete extends AbstractBase implements NestedSetsCreateDeleteInterface
{
    public function create(NodeInterface $nestedSetEntity, ?NodeInterface $parent = null): NodeInterface
    {
        if (!empty($nestedSetEntity->getId())) {
            return $nestedSetEntity;
        }

        $this->getEntityManager()->beginTransaction();

        try {
            if ($parent) {

                $lft = $parent->getLft();
                $rgt = $parent->getRgt();
                $lvl = $parent->getLvl();
                $parentId = $parent->getId();
                $tree = $parent->getTree();

                $tableName = $this->getTableName();
                $sql = "UPDATE $tableName {$this->alias} 
                            SET {$this->alias}.rgt={$this->alias}.rgt + 2 
                         WHERE {$this->alias}.tree=:tree AND {$this->alias}.rgt>=:lft;";

                $sql .= "UPDATE $tableName {$this->alias} 
                            SET {$this->alias}.lft={$this->alias}.lft + 2 
                         WHERE {$this->alias}.tree=:tree AND {$this->alias}.lft>:lft;";

                $lft = $rgt;
                $rgt++;
                $lvl++;

                $params = [
                    'tree' => $tree,
                    'lft' => $lft,
                    'rgt' => $rgt
                ];

                $this->getEntityManager()->getConnection()->executeQuery($sql, $params);

            } else {

                $tree = (int)$this->getEntityManager()->getConnection()->fetchOne(
                    self::getLastTreeIdSql($this->getTableName())
                );

                $tree++;
                $lft = $lvl = 1;
                $rgt = 2;
                $parentId = 0;
            }

            $nestedSetEntity
                ->setLft($lft)
                ->setLvl($lvl)
                ->setTree($tree)
                ->setRgt($rgt)
                ->setParentId($parentId);

            $this->getEntityManager()->persist($nestedSetEntity);
            $this->getEntityManager()->flush();

            $this->getEntityManager()->commit();


        } catch (\Throwable $e) {
            $this->getEntityManager()->rollback();
            throw $e;
        }

        return $nestedSetEntity;
    }

    public function delete(NodeInterface $node, bool $isSafeDelete = true): void
    {
        if ($isSafeDelete) {
            $sql = "UPDATE `{$this->getTableName()}` SET `is_deleted` = 1 WHERE `id` = {$node->getId()}";
        } else {
            $sql = $this->getDeleteQuery($node, $this->getTableName());
        }

        try {
            $this->getEntityManager()->beginTransaction();
            $this->getEntityManager()->getConnection()->executeQuery($sql);
            $this->getEntityManager()->commit();
        } catch (\Throwable $exception) {
            throw $exception;
            $this->getEntityManager()->rollback();
        }
    }

    public static function getDeleteQuery (
        NodeInterface $nestedSetEntity,
        string $tableName
    ): string {

        $sql = "DELETE FROM `{$tableName}` 
                    WHERE lft >= {$nestedSetEntity->getLft()} 
                        AND rgt <= {$nestedSetEntity->getRgt()} 
                        AND tree = {$nestedSetEntity->getTree()};";

        $sql .= "UPDATE `{$tableName}` SET
                    lft = IF (lft > {$nestedSetEntity->getLft()},
                    lft - (((( {$nestedSetEntity->getRgt()} - {$nestedSetEntity->getLft()} - 1) / 2) + 1)*2), lft),
                    rgt = rgt- (((( {$nestedSetEntity->getRgt()} - {$nestedSetEntity->getLft()} - 1) / 2) + 1)*2)

                 WHERE rgt > {$nestedSetEntity->getRgt()} AND tree = {$nestedSetEntity->getTree()};";

        return $sql;
    }

    public static function getLastTreeIdSql(string $tableName): string
    {
        return "SELECT MAX(tree) FROM {$tableName}";
    }
}