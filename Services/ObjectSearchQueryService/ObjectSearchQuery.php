<?php

namespace Nodeart\BuilderBundle\Services\ObjectSearchQueryService;

use GraphAware\Neo4j\OGM\EntityManager;
use GraphAware\Neo4j\OGM\Query;
use Nodeart\BuilderBundle\Entity\FieldValueNode;
use Nodeart\BuilderBundle\Entity\ObjectNode;
use Nodeart\BuilderBundle\Entity\TypeFieldNode;

class ObjectSearchQuery
{

    const DEFAULT_PAGE_LIMIT = 20;
    const REL_LINK_TO_CHILD = false;
    const REL_LINK_TO_PARENT = true;

    private $query;

    private $baseFiltersCount = 0;

    private $baseWhere = '';
    private $objectFilter = '';
    private $etFilter = '';
    private $valuesFilter = '';
    private $parentChildLinks = '';
    private $parentChildFilter = '';
    private $skip = 0;
    private $limit = self::DEFAULT_PAGE_LIMIT;
    private $baseOrder = '';
    private $secondOrder = '';
    private $additionalReturnString = '';

    private $partialQueryResultPart = '';
    private $partialFVSubQuery = '';

    public function __construct(EntityManager $entityManager)
    {
        $this->query = $entityManager->createQuery();
    }

    /**
     * @param bool $reformatFields Should reformat fields?
     * @return array|mixed
     */
    public function execute($reformatFields = false)
    {
        $result = $this->getQuery()->getResult();
        if ($reformatFields) {
            foreach ($result as &$resultRow) {
                $fields = $resultRow['objectFields'];
                $resultRow['objectFields'] = [];
                foreach ($fields as $field) {
                    $resultRow['objectFields'][$field['etfSlug']] = $field['valsByFields'];
                }

            }
        }
        return $result;
    }

    public function getQuery()
    {
        $this->prepareMainQuery();
        return $this->query;
    }

    private function prepareMainQuery()
    {
        $baseWhere = $this->baseWhere;
        $skip = 'skip ' . $this->skip;
        $limit = 'limit ' . $this->limit;
        $parentChildLinks = $this->parentChildLinks;
        $objectFilter = $this->objectFilter;
        $etFilter = $this->etFilter;
        $valuesFilter = $this->valuesFilter;
        $parentChildFilter = $this->parentChildFilter;
        $baseOrder = $this->baseOrder;
        $secondOrder = $this->secondOrder;
        $additionalReturnString = $this->additionalReturnString;

        $cql = "MATCH (type:EntityType)<-[:has_type]-(o:Object)$parentChildLinks $baseWhere $objectFilter $etFilter $parentChildFilter
                WITH o $additionalReturnString $baseOrder $valuesFilter
        	    OPTIONAL MATCH (o)<-[rel:is_field_of]-(fv:FieldValue)-[:is_value_of]->(etf:EntityTypeField)-[:has_field]->(type:EntityType)<-[:has_type]-(o)
                WITH etf, o, collect(DISTINCT fv) as val $additionalReturnString
                RETURN o as object, CASE WHEN etf IS NULL THEN [] ELSE collect({etfSlug:etf.slug, valsByFields:{fieldType:etf, val:val}}) END as objectFields $additionalReturnString
                $secondOrder $skip $limit";
        $this->query->setCQL($cql);

        $this->query->addEntityMapping('object', ObjectNode::class);
        $this->query->addEntityMapping('fieldType', TypeFieldNode::class);
        $this->query->addEntityMapping('val', FieldValueNode::class, Query::HYDRATE_COLLECTION);
        $this->query->addEntityMapping('valsByFields', null, Query::HYDRATE_MAP);
        $this->query->addEntityMapping('objectFields', null, Query::HYDRATE_MAP_COLLECTION);
        $this->query->addEntityMapping('objects', null, Query::HYDRATE_MAP_COLLECTION);
    }

    public function executeCount()
    {
        return $this->getCountQuery()->getOneResult();
    }

    public function getCountQuery()
    {
        $this->prepareSearchQuery();
        return $this->query;
    }

    private function prepareSearchQuery()
    {
        $baseWhere = $this->baseWhere;
        $parentChildLinks = $this->parentChildLinks;
        $objectFilter = $this->objectFilter;
        $etFilter = $this->etFilter;
        $valuesFilter = $this->valuesFilter;
        $parentChildFilter = $this->parentChildFilter;

        $cql = "MATCH (type:EntityType)<-[:has_type]-(o:Object)$parentChildLinks $baseWhere $objectFilter $etFilter $parentChildFilter
                WITH o $valuesFilter
                RETURN count(o) as count";

        $this->query->setCQL($cql);
    }

    public function getPartialQuery()
    {
        if (empty($this->partialQueryResultPart))
            throw new \LogicException(ObjectSearchQuery::class . '`s addPartialQueryResult() must be called before query building');

        $this->preparePartialQuery();
        return $this->query;
    }

    private function preparePartialQuery()
    {
        $baseWhere = $this->baseWhere;
        $objectFilter = $this->objectFilter;
        $etFilter = $this->etFilter;
        $valuesFilter = $this->valuesFilter;
        $baseOrder = $this->baseOrder;
        $partialResult = $this->partialQueryResultPart;
        $partialFVSubQuery = $this->partialFVSubQuery;

        $cql = "MATCH (type:EntityType)<-[:has_type]-(o:Object) $baseWhere $objectFilter $etFilter $valuesFilter $baseOrder
                $partialFVSubQuery
                $partialResult";
        $this->query->setCQL($cql);

        $this->query->addEntityMapping('o', ObjectNode::class);
    }

    /**
     * @param array $params ['cql'=>'', 'params'=> ['name'=>'', 'value'=>''] ]
     * @return $this
     */
    public function addObjectFilters($params = [])
    {
        if (!empty($params)) {
            $this->baseWhere = 'WHERE ';
            $this->objectFilter = $params['cql'];

            foreach ($params['params'] as $paramPair) {
                $this->query->setParameter($paramPair['name'], $paramPair['values']);
            }
            $this->baseFiltersCount++;
        }
        return $this;
    }

    public function addETFilters($params = [])
    {
        if (!empty($params)) {
            if ($this->baseFiltersCount < 1) {
                $this->baseWhere = 'WHERE ';
                $this->etFilter = $params['cql'];
            } else {
                $this->etFilter = ' AND ' . $params['cql'];
            }
            foreach ($params['params'] as $paramPair) {
                $this->query->setParameter($paramPair['name'], $paramPair['values']);
            }
            $this->baseFiltersCount++;
        }
        return $this;
    }

    public function addValuesFilter($params = [])
    {
        if (!empty($params)) {

            if (empty($this->valuesFilter)) {
                $this->valuesFilter = ' MATCH (o)<-[:is_field_of]-(filterFV:FieldValue)-[:is_value_of]->(filterETF:EntityTypeField) WHERE ' . $params['cql'];
            } else {
                $this->valuesFilter .= ' AND ' . $params['cql'];
            }

            foreach ($params['params'] as $paramPair) {
                $this->query->setParameter($paramPair['name'], $paramPair['values']);
            }
        }
        return $this;
    }

    public function addParentChildRelations(int $refObjectId, $linkToParent = self::REL_LINK_TO_PARENT)
    {

        $this->parentChildLinks = ($linkToParent) ?
            '-[:is_child_of]->(refObject:Object)' :
            '<-[:is_child_of]-(refObject:Object)';

        if ($this->baseFiltersCount < 1) {
            $this->baseWhere = 'WHERE ';
        } else {
            $this->parentChildFilter = ' AND ';
        }
        $this->parentChildFilter .= 'id(refObject) = {refObjectId}';
        $this->query->setParameter('refObjectId', $refObjectId);
        $this->baseFiltersCount++;
        return $this;
    }

    public function addParentChildRelationsFilters(string $relatedETSlug, $params = [], $linkToParent = true)
    {
        if (!empty($params)) {
            $this->parentChildLinks = ($linkToParent) ?
                '-[:is_child_of]->(refObject:Object)-->(refET:EntityType)' :
                '<-[:is_child_of]-(refObject:Object)-->(refET:EntityType)';

            if ($this->baseFiltersCount < 1) {
                $this->baseWhere = 'WHERE ';
                $this->parentChildFilter = $params['cql'];
            } else {
                $this->parentChildFilter = ' AND ' . $params['cql'];
            }
            $this->parentChildFilter .= ' AND refET.slug = {refETSlug}';
            $this->query->setParameter('refETSlug', $relatedETSlug);

            foreach ($params['params'] as $paramPair) {
                $this->query->setParameter($paramPair['name'], $paramPair['values']);
            }
            $this->baseFiltersCount++;
        }
        return $this;
    }

    public function addSkip(int $skip)
    {
        $this->skip = $skip;
        return $this;
    }

    public function addLimit(int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function addBaseOrder(string $cql)
    {
        if (!empty($cql)) {
            $this->baseOrder = ' ORDER BY ' . $cql;
        }
        return $this;
    }

    public function addSecondOrder(string $cql)
    {
        if (!empty($cql)) {
            $this->secondOrder = ' ORDER BY ' . $cql;
        }
        return $this;
    }


    public function addPartialQueryResult(string $cql)
    {
        if (!empty($cql)) {
            $this->partialQueryResultPart = 'RETURN DISTINCT ' . $cql;
        }
        return $this;
    }

    public function addPartialFVSubQuery($params = [])
    {
        $this->partialFVSubQuery = 'OPTIONAL MATCH (o)<-[rel:is_field_of]-(fv:FieldValue)-[:is_value_of]->(etf:EntityTypeField)-[:has_field]-(type:EntityType)-[:has_type]-(o)';
        if (!empty($params)) {
            $this->partialFVSubQuery .= ' WHERE ' . $params['cql'];
            foreach ($params['params'] as $paramPair) {
                $this->query->setParameter($paramPair['name'], $paramPair['values']);
            }
        }
        return $this;
    }

    public function addReturnString(string $cql, array $mappings)
    {
        $this->additionalReturnString = ', ' . $cql;
        foreach ($mappings as $alias => $class) {
            $this->query->addEntityMapping($alias, $class);
        }
        return $this;
    }
}