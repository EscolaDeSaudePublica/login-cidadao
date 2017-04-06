<?php
/**
 * This file is part of the login-cidadao project or it's bundles.
 *
 * (c) Guilherme Donato <guilhermednt on github>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LoginCidadao\PhoneVerificationBundle\Service;

use Doctrine\ORM\EntityManager;
use libphonenumber\PhoneNumber;
use LoginCidadao\PhoneVerificationBundle\Event\PhoneVerificationEvent;
use LoginCidadao\PhoneVerificationBundle\PhoneVerificationEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use LoginCidadao\CoreBundle\Model\PersonInterface;
use LoginCidadao\PhoneVerificationBundle\Entity\PhoneVerification;
use LoginCidadao\PhoneVerificationBundle\Entity\PhoneVerificationRepository;
use LoginCidadao\PhoneVerificationBundle\Model\PhoneVerificationInterface;

class PhoneVerificationService
{
    /** @var PhoneVerificationOptions */
    private $options;

    /** @var EntityManager */
    private $em;

    /** @var PhoneVerificationRepository */
    private $phoneVerificationRepository;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    /**
     * PhoneVerificationService constructor.
     * @param PhoneVerificationOptions $options
     * @param EntityManager $em
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(
        PhoneVerificationOptions $options,
        EntityManager $em,
        EventDispatcherInterface $dispatcher
    ) {
        $this->options = $options;
        $this->em = $em;
        $this->dispatcher = $dispatcher;
        $this->phoneVerificationRepository = $this->em
            ->getRepository('LoginCidadaoPhoneVerificationBundle:PhoneVerification');
    }

    /**
     * Gets phone verification record (PhoneVerificationInterface) for the given phone number.
     *
     * @param PersonInterface $person
     * @param mixed $phone
     * @return PhoneVerificationInterface
     */
    public function getPhoneVerification(PersonInterface $person, PhoneNumber $phone)
    {
        /** @var PhoneVerificationInterface $phoneVerification */
        $phoneVerification = $this->phoneVerificationRepository->findOneBy(
            [
                'person' => $person,
                'phone' => $phone,
            ]
        );

        return $phoneVerification;
    }

    /**
     * @param PersonInterface $person
     * @param mixed $phone
     * @return PhoneVerificationInterface
     */
    public function createPhoneVerification(PersonInterface $person, PhoneNumber $phone)
    {
        $phoneVerification = new PhoneVerification();
        $phoneVerification->setPerson($person)
            ->setPhone($phone)
            ->setVerificationCode($this->generateVerificationCode());

        $this->em->persist($phoneVerification);
        $this->em->flush($phoneVerification);

        return $phoneVerification;
    }

    /**
     * @param PersonInterface $person
     * @param mixed $phone
     * @return PhoneVerificationInterface|null
     */
    public function getPendingPhoneVerification(PersonInterface $person, PhoneNumber $phone)
    {
        /** @var PhoneVerificationInterface $phoneVerification */
        $phoneVerification = $this->phoneVerificationRepository->findOneBy(
            [
                'person' => $person,
                'phone' => $phone,
                'verifiedAt' => null,
            ]
        );

        return $phoneVerification;
    }

    /**
     * @param PersonInterface $person
     * @return PhoneVerificationInterface[]
     */
    public function getAllPendingPhoneVerification(PersonInterface $person)
    {
        /** @var PhoneVerificationInterface[] $phoneVerification */
        $phoneVerification = $this->phoneVerificationRepository->findBy(
            [
                'person' => $person,
                'verifiedAt' => null,
            ]
        );

        return $phoneVerification;
    }

    /**
     * @param PhoneVerificationInterface $phoneVerification
     * @return bool
     */
    public function removePhoneVerification(PhoneVerificationInterface $phoneVerification)
    {
        $this->em->remove($phoneVerification);
        $this->em->flush($phoneVerification);

        return true;
    }

    /**
     * @param PersonInterface $person
     * @param mixed $phone
     * @return PhoneVerificationInterface
     */
    public function enforcePhoneVerification(PersonInterface $person, PhoneNumber $phone)
    {
        $phoneVerification = $this->getPhoneVerification($person, $phone);

        return $phoneVerification ?: $this->createPhoneVerification($person, $phone);
    }

    private function generateVerificationCode()
    {
        $length = $this->options->getLength();
        $useNumbers = $this->options->isUseNumbers();
        $useLower = $this->options->isUseLowerCase();
        $useUpper = $this->options->isUseUpperCase();

        $keySpace = $useNumbers ? '0123456789' : '';
        $keySpace .= $useLower ? 'abcdefghijklmnopqrstuvwxyz' : '';
        $keySpace .= $useUpper ? 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' : '';

        $code = '';
        $max = mb_strlen($keySpace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $code .= $keySpace[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Verifies code without dispatching any event or making any changes.
     *
     * @param $provided
     * @param $expected
     * @return bool
     */
    public function checkVerificationCode($provided, $expected)
    {
        if ($this->options->isCaseSensitive()) {
            return $provided === $expected;
        } else {
            return strtolower($provided) === strtolower($expected);
        }
    }

    /**
     * Verifies a phone and dispatches event.
     *
     * @param PhoneVerificationInterface $phoneVerification
     * @param $providedCode
     * @return bool
     */
    public function verify(PhoneVerificationInterface $phoneVerification, $providedCode)
    {
        if ($this->checkVerificationCode($providedCode, $phoneVerification->getVerificationCode())) {
            $phoneVerification->setVerifiedAt(new \DateTime());
            $this->em->persist($phoneVerification);
            $this->em->flush($phoneVerification);

            $event = new PhoneVerificationEvent($phoneVerification, $providedCode);
            $this->dispatcher->dispatch(PhoneVerificationEvents::PHONE_VERIFIED, $event);

            return true;
        } else {
            return false;
        }
    }
}