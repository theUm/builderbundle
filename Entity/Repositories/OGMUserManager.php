<?php

/*
 * This file is part of the FOSUserBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nodeart\BuilderBundle\Entity\Repositories;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Persistence\ObjectRepository;
use FOS\UserBundle\Model\UserInterface;
use FOS\UserBundle\Model\UserManager as BaseUserManager;
use FOS\UserBundle\Util\CanonicalFieldsUpdater;
use FOS\UserBundle\Util\PasswordUpdaterInterface;
use Nodeart\BuilderBundle\Entity\UserNode;

class OGMUserManager extends BaseUserManager
{
    const DO_NOT_REFRESH = false;
    const DO_REFRESH = true;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var string
     */
    private $class;

    /**
     * Constructor.
     *
     * @param PasswordUpdaterInterface $passwordUpdater
     * @param CanonicalFieldsUpdater $canonicalFieldsUpdater
     * @param ObjectManager $om
     * @param string $class
     */
    public function __construct(PasswordUpdaterInterface $passwordUpdater, CanonicalFieldsUpdater $canonicalFieldsUpdater, ObjectManager $om, $class)
    {
        parent::__construct($passwordUpdater, $canonicalFieldsUpdater);

        $this->objectManager = $om;
        $this->class = $class;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteUser(UserInterface $user)
    {
        $this->objectManager->remove($user);
        $this->objectManager->flush();
    }

    /**
     * @todo: dirty dirty dirty hack. Current OGM has bug with serialization of proxy entity - php cant find class to unserialize onto,
     * @todo: so I`m casting proxy entity onto UserNode entity
     * {@inheritdoc}
     */
    public function findUserBy(array $criteria, $cast = true)
    {
        if (key($criteria) === 'id') {
            /** @var UserNode $res */
            $res = $this->getRepository()->find($criteria['id']);
        } else {
            /** @var UserNode $res */
            $res = $this->getRepository()->findOneBy($criteria);
        }

        if ($cast) {
            $res = serialize($res);
            $res = str_replace('C:53:"neo4j_ogm_proxy_Nodeart_BuilderBundle_Entity_UserNode"', 'C:37:"Nodeart\BuilderBundle\Entity\UserNode"', $res);
            $res = unserialize($res);
        }
        return $res;
    }

    /**
     * @return ObjectRepository
     */
    protected function getRepository()
    {
        return $this->objectManager->getRepository($this->getClass());
    }

    /**
     * {@inheritdoc}
     */
    public function getClass()
    {
        if (false !== strpos($this->class, ':')) {
            $metadata = $this->objectManager->getClassMetadata($this->class);
            $this->class = $metadata->getName();
        }

        return $this->class;
    }

    /**
     * {@inheritdoc}
     */
    public function findUsers()
    {
        return $this->getRepository()->findAll();
    }

    /**
     * {@inheritdoc}
     */
    public function reloadUser(UserInterface $user)
    {
        $this->objectManager->refresh($user);
    }

    /**
     * {@inheritdoc}
     */
    public function updateUser(UserInterface $user, $andFlush = true, $refreshUser = self::DO_REFRESH)
    {

        //reload user from DB due to $this->findUserBy() serialization work mechanics
        if (!is_null($user->getId()) && $refreshUser) {
            $user = $this->reFetchUser($user);
        }

        $this->updateCanonicalFields($user);
        $this->updatePassword($user);

        $this->objectManager->persist($user);
        if ($andFlush) {
            $this->objectManager->flush();
        }
    }

    /**
     * @param $user UserInterface|null
     *
     * @return null|object
     */
    public function reFetchUser($user)
    {
        return (is_null($user)) ? null : $this->getRepository()->find($user->getId());
    }
}
