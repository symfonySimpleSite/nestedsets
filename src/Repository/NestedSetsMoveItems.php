<?php

namespace SymfonySimpleSite\NestedSets\Repository;

use SymfonySimpleSite\NestedSets\Entity\NodeInterface;
use SymfonySimpleSite\NestedSets\Exception\NestedSetsMoveUnderSelfException;
use SymfonySimpleSite\NestedSets\Exception\NestedSetsNodeNotFoundException;

class NestedSetsMoveItems extends AbstractBase implements NestedSetsMoveItemsInterface
{
    private const MOVE_TMP_TABLE = '_move_tmp';
    private const MOVE_TMP_TABLE_ALL_NODES = '_move_tmp_all_nodes';

    public function move(NodeInterface $node, ?NodeInterface $parent): void
    {

        $moveTmpTable = $this->getMovedTemporaryTableName();
        $nsTableName = $this->getTableName();
        $tmpAllNodesTableName = $this->getMovedTemporaryTableNameForAllNodes();

        $this->deleteTemplateTables();

        try {
            $this->testIfMoveUnderSelf($node, $parent);
        } catch (NestedSetsMoveUnderSelfException $exception) {
            throw $exception;
        }


        $sql = "CREATE TABLE IF NOT EXISTS `{$moveTmpTable}` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,                
                `lft` int unsigned DEFAULT NULL,
                `rgt` int unsigned DEFAULT NULL,          
                `tree` int unsigned DEFAULT NULL,          
                `parent_id` int unsigned DEFAULT NULL,
                 i int unsigned DEFAULT NULL,
                PRIMARY KEY (`id`)); ";

        $sql .= "CREATE TABLE IF NOT EXISTS `{$tmpAllNodesTableName}` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,                
                `parent_id` int unsigned DEFAULT NULL,
                `lft` int unsigned DEFAULT NULL,
                `rgt` int unsigned DEFAULT NULL,          
                `tree` int unsigned DEFAULT NULL,          
                `lvl` int unsigned DEFAULT NULL,
                i int unsigned DEFAULT NULL,
                PRIMARY KEY (`id`)); ";

        $treeIdArray = [
            $node->getTree()
        ];

        if (!empty($parent) && $parent->getTree() != $node->getTree()) {
            $treeIdArray[] = $parent->getTree();
        }

        $sql .=  " INSERT INTO `{$tmpAllNodesTableName}` 
                SELECT `ns`.`id`, 
                       `ns`.`parent_id`, 
                       `ns`.`lft`, 
                       `ns`.`rgt`, 
                       `ns`.`tree`, 
                       `ns`.`lvl`,
                       '0'  i 
                    FROM `{$nsTableName}` `ns` 
                WHERE `ns`.`tree` IN (" . implode(',', $treeIdArray) . ");";

        $this->getEntityManager()->getConnection()->executeQuery($sql);

        $this->getEntityManager()->getConnection()->beginTransaction();
        $throwException = null;

        try {
            $sql = "INSERT INTO `{$moveTmpTable}` 
                    SELECT `ns`.`id`, 
                           `ns`.`lft`, 
                           `ns`.`rgt`, 
                           `ns`.`tree`, 
                           `ns`.`parent_id`,
                           '0'  i  
                    FROM `{$nsTableName}` `ns` 
                       WHERE `ns`.`lft` >= {$node->getLft()}
                          AND `ns`.`rgt` <= {$node->getRgt()}
                          AND `ns`.`tree` = {$node->getTree()}
                      ORDER BY `ns`.`lft` ";

            $insertedLength = $this->getEntityManager()->getConnection()->executeQuery($sql)->rowCount();

            if ($insertedLength == 0) {
                throw new \Exception('Inserted move users length is 0', 4);
            }

            $insertedLength *= 2;

            $sql = NestedSetsCreateDelete::getDeleteQuery($node, $tmpAllNodesTableName);

            $this->getEntityManager()->getConnection()->executeQuery($sql);

            if ($parent !== null) {
                $parentNew = $this->getEntityManager()->getConnection()
                    ->fetchAssociative("SELECT * FROM {$tmpAllNodesTableName} WHERE id=:id AND tree=:tree",
                                 [
                                     "id" => $parent->getId(),
                                     "tree" => $parent->getTree()
                                 ]);

                if (empty($parentNew)) {
                    throw new NestedSetsNodeNotFoundException($parent->getId());
                }

                $sql = "UPDATE `{$tmpAllNodesTableName}` SET
                            lft = (
                                CASE
                                   WHEN lft > {$parentNew['lft']}
                                        AND rgt < {$parentNew['rgt']}
                                        AND tree = {$parentNew['tree']}
                                THEN lft + {$insertedLength}
                                
                                WHEN lft > {$parentNew['lft']}
                                     AND rgt > {$parentNew['rgt']}
                                     AND tree = {$parentNew['tree']}
                                THEN lft + {$insertedLength}
                                ELSE lft END
                            ),

                            rgt = (
                                CASE
                                    WHEN lft > {$parentNew['lft']}
                                         AND rgt < {$parentNew['rgt']}
                                         AND tree = {$parentNew['tree']}
                                    THEN rgt + {$insertedLength}
                                    
                                    WHEN (lft > {$parentNew['lft']}
                                         AND rgt > {$parentNew['rgt']}
                                         AND tree = {$parentNew['tree']}
                                         ) OR (lft <= {$parentNew['lft']}
                                         AND rgt >= {$parentNew['rgt']}
                                         AND tree = {$parentNew['tree']}
                                         ) 
                                    THEN rgt + {$insertedLength}
                                    ELSE rgt END
                                )
                        WHERE tree = {$parent->getTree()};";
                $sql .= "SET @s_ := 0;";
                $sql .= "INSERT INTO `{$tmpAllNodesTableName}` 
                        SELECT  id,
                                IF (@s_ = 0, {$parentNew['id']}, parent_id),
                                lft - {$node->getLft()} + 1 + {$parentNew['lft']}, 
                                rgt - {$node->getLft()} + 1 + {$parentNew['lft']},
                                {$parentNew['tree']},
                                 
                                ( 
                                    (SELECT COUNT(*) FROM {$moveTmpTable} t1 WHERE t1.lft < t2.lft AND t1.rgt>t2.rgt)  
                                    + {$parent->getLvl()} + 1
                                ),
                                @s_ := @s_ + 1
                                FROM {$moveTmpTable} t2;";

                $this->getEntityManager()->getConnection()->executeQuery($sql);
            } else {

                $maxTree = (int)$this->getEntityManager()->getConnection()->fetchOne(
                    NestedSetsCreateDelete::getLastTreeIdSql($nsTableName)
                );

                $maxTree++;
                $sql = "SET @s_ := 0;";
                $sql .= "INSERT INTO `{$tmpAllNodesTableName}` 
                        SELECT id, IF (@s_ = 0, 0, parent_id),
                               
                               lft - {$node->getLft()} + 1, 
                               rgt - {$node->getLft()} + 1,
                               {$maxTree}, 
                               (
                                    IF (@s_ = 0, 1,   (SELECT COUNT(*) FROM `{$moveTmpTable}` t1 
                                        WHERE t1.lft < t2.lft AND t1.rgt>t2.rgt)  + 1
                                    )
                               ),
                               @s_ := 1
                        FROM {$moveTmpTable} t2";


                $this->getEntityManager()->getConnection()->executeQuery($sql);
            }


            $this->migrateFromTemporaryTable();
            $this->getEntityManager()->getConnection()->commit();
        } catch (\Throwable $exception) {
            $this->getEntityManager()->getConnection()->rollBack();
            $throwException = $exception;
        }

        $this->deleteTemplateTables();

        if ($throwException instanceof \Throwable) {
            throw $throwException;
        }
    }

    protected function testIfMoveUnderSelf(NodeInterface $node, ?NodeInterface $parent): void
    {
        if (!empty($parent) &&
            $node->getTree() == $parent->getTree() &&
            $node->getLft() <= $parent->getLft() &&
            $node->getRgt() >= $parent->getRgt()) {

            throw new NestedSetsMoveUnderSelfException();
        }
    }

    private function getMovedTemporaryTableName(): string
    {
        return $this->getTableName() . '_' . self::MOVE_TMP_TABLE;
    }

    private function getMovedTemporaryTableNameForAllNodes(): string
    {
        return $this->getTableName() . '_' . self::MOVE_TMP_TABLE_ALL_NODES;
    }

    private function migrateFromTemporaryTable(): void
    {
        $allNodesTmpTableName = $this->getMovedTemporaryTableNameForAllNodes();
        $nsTableName = $this->getTableName();
        $sql = "UPDATE `{$nsTableName}` ns 
                    INNER JOIN {$allNodesTmpTableName} nst 
                    ON ns.id = nst.id 
                SET ns.lft = nst.lft, 
                    ns.rgt = nst.rgt, 
                    ns.tree = nst.tree, 
                    ns.lvl = nst.lvl, 
                    ns.parent_id = nst.parent_id";

        $this->getEntityManager()->getConnection()->executeQuery($sql);
    }

    private function deleteTemplateTables(): void
    {
        $moveTmpTable = $this->getMovedTemporaryTableName();
        $tmpAllNodesTableName = $this->getMovedTemporaryTableNameForAllNodes();
       $sql = "DROP TABLE IF EXISTS `{$moveTmpTable}`;";
        $sql .= "DROP TABLE IF EXISTS `{$tmpAllNodesTableName}`;";
        $this->getEntityManager()->getConnection()->executeQuery($sql);
    }
}

