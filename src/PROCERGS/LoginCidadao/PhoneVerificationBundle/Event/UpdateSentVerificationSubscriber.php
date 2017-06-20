<?php
/**
 * This file is part of the login-cidadao project or it's bundles.
 *
 * (c) Guilherme Donato <guilhermednt on github>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PROCERGS\LoginCidadao\PhoneVerificationBundle\Event;

use Eljam\CircuitBreaker\Breaker;
use Eljam\CircuitBreaker\Exception\CircuitOpenException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use LoginCidadao\CoreBundle\Model\PersonInterface;
use LoginCidadao\PhoneVerificationBundle\Entity\SentVerification;
use LoginCidadao\PhoneVerificationBundle\Event\SendPhoneVerificationEvent;
use LoginCidadao\PhoneVerificationBundle\Event\UpdateStatusEvent;
use LoginCidadao\PhoneVerificationBundle\Model\DeliveryStatus;
use LoginCidadao\PhoneVerificationBundle\Model\PhoneVerificationInterface;
use LoginCidadao\PhoneVerificationBundle\PhoneVerificationEvents;
use PROCERGS\Sms\Exception\SmsServiceException;
use PROCERGS\Sms\SmsService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorInterface;

class UpdateSentVerificationSubscriber implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait, LoggerTrait;

    /** @var SmsService */
    private $smsService;

    /** @var Breaker */
    private $breaker;

    /**
     * PhoneVerificationSubscriber constructor.
     *
     * @param SmsService $smsService
     * @param Breaker $breaker
     */
    public function __construct(
        SmsService $smsService,
        Breaker $breaker
    ) {
        $this->smsService = $smsService;
        $this->breaker = $breaker;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    protected function log($level, $message, array $context = array())
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2')))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            PhoneVerificationEvents::PHONE_VERIFICATION_GET_SENT_VERIFICATION_STATUS => 'onStatusRequested',
        ];
    }

    public function onStatusRequested(UpdateStatusEvent $event)
    {
        $javaFormat = 'Y-m-d\TH:i:s.uP';
        $statuses = $this->protectedGetStatus($this->smsService, $event->getTransactionId());

        $status = reset($statuses);
        $sentAt = $status->dthEnvio ? \DateTime::createFromFormat($javaFormat, $status->dthEnvio) : null;
        $deliveredAt = $status->dthEntrega ? \DateTime::createFromFormat($javaFormat, $status->dthEntrega) : null;

        try {
            $deliveryStatus = DeliveryStatus::parse($status->resumoEntrega);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                "Invalid status for transaction {$event->getTransactionId()}: {$status->resumoEntrega}",
                $e->getCode(),
                $e
            );
        }

        $event->setDeliveredAt($deliveredAt)
            ->setSentAt($sentAt)
            ->setDeliveryStatus($deliveryStatus);
    }

    private function protectedGetStatus(
        SmsService $smsService,
        $transactionId
    ) {
        return $this->breaker->protect(
            function () use ($smsService, $transactionId) {
                $statuses = $this->smsService->getStatus($transactionId);

                $this->info(
                    'Updating status for transaction ID: {transaction_id}',
                    ['transaction_id' => $transactionId]
                );

                return $statuses;
            }
        );
    }
}
