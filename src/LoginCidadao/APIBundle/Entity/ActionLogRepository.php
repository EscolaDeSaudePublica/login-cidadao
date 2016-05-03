<?php

namespace LoginCidadao\APIBundle\Entity;

use Doctrine\ORM\EntityRepository;
use LoginCidadao\CoreBundle\Model\PersonInterface;
use LoginCidadao\OAuthBundle\Model\ClientInterface;

/**
 * ActionLogRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ActionLogRepository extends EntityRepository
{

    public function getWithClientByPerson(PersonInterface $person, $limit = null)
    {
        $excludeTypes = array(
            ActionLog::TYPE_IMPERSONATE,
            ActionLog::TYPE_DEIMPERSONATE,
            ActionLog::TYPE_LOGIN
        );

        $query = $this->createQueryBuilder('l')
            ->select('l, c')
            ->innerJoin('LoginCidadaoOAuthBundle:Client', 'c', 'WITH',
                'c.id = l.clientId')
            ->where('l.userId = :person_id')
            ->andWhere('l.actionType NOT IN (:excludeTypes)')
            ->setParameter('person_id', $person->getId())
            ->setParameter('excludeTypes', $excludeTypes)
            ->orderBy('l.createdAt', 'DESC');

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }

        $result = array(
            'actionLogs' => array(),
            'clients' => array()
        );
        foreach ($query->getQuery()->getResult() as $item) {
            $object = '';
            if ($item instanceof ActionLog) {
                $object = 'actionLogs';
            } elseif ($item instanceof ClientInterface) {
                $object = 'clients';
            }
            $result[$object][$item->getId()] = $item;
        }

        foreach ($result['actionLogs'] as $log) {
            $log->setClient($result['clients'][$log->getClientId()]);
        }

        return $result['actionLogs'];
    }

    public function findLoginsByPerson(PersonInterface $person, $limit = null)
    {
        $query = $this->createQueryBuilder('l')
            ->where('l.userId = :person_id')
            ->andWhere('l.actionType IN (:type)')
            ->setParameter('person_id', $person->getId())
            ->setParameter('type',
                array(ActionLog::TYPE_LOGIN, ActionLog::TYPE_IMPERSONATE))
            ->orderBy('l.createdAt', 'DESC');

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * @param int $limit
     * @param PersonInterface $impersonator
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function getImpersonatonsWithoutReportsQuery($limit = null,
                                                            PersonInterface $impersonator
    = null)
    {
        $query = $this->createQueryBuilder('l')
            ->leftJoin('LoginCidadaoCoreBundle:ImpersonationReport', 'r',
                'WITH', 'r.actionLog = l')
            ->where('r.id IS NULL')
            ->andWhere('l.actionType = :type')
            ->setParameter('type', ActionLog::TYPE_IMPERSONATE)
        ;

        if ($impersonator instanceof PersonInterface) {
            $query->andWhere('l.clientId = :impersonatorId')
                ->setParameter('impersonatorId', $impersonator->getId())
            ;
        }

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }

        return $query;
    }

    public function findImpersonatonsWithoutReports($limit = null,
                                                    PersonInterface $impersonator
    = null, $returnArray = false)
    {
        $query = $this->getImpersonatonsWithoutReportsQuery($limit,
            $impersonator);

        if (!$returnArray) {
            $query->select('l');

            return $query->getQuery()->getResult();
        } else {
            $query->select('l.id AS log_id, l.createdAt AS date, COALESCE(p.firstName, p.email) AS person_name')
                ->join('LoginCidadaoCoreBundle:Person', 'p', 'WITH',
                    'l.userId = p.id')
            ;

            return $query->getQuery()->getScalarResult();
        }
    }

    public function countImpersonatonsWithoutReports(PersonInterface $impersonator
    = null)
    {
        $query = $this->getImpersonatonsWithoutReportsQuery(null, $impersonator);
        $query->select('COUNT(l)');

        return $query->getQuery()->getSingleScalarResult();
    }
}
