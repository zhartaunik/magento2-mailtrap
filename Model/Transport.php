<?php

declare(strict_types=1);

namespace PerfectCode\Mailtrap\Model;

use Laminas\Mail\Message;
use Laminas\Mail\Transport\Smtp;
use Laminas\Mail\Transport\SmtpFactory;
use Laminas\Mail\Transport\SmtpOptionsFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Transport implements TransportInterface
{
    /**
     * Configuration path to source of Return-Path and whether it should be set at all
     * @see \Magento\Config\Model\Config\Source\Yesnocustom to possible values
     */
    private const XML_PATH_SENDING_SET_RETURN_PATH = 'system/smtp/set_return_path';

    /**
     * Configuration path for custom Return-Path email
     */
    private const XML_PATH_SENDING_RETURN_PATH_EMAIL = 'system/smtp/return_path_email';

    /**
     * @var Smtp|null
     */
    private ?Smtp $transport = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private MessageInterface $message,
        private SmtpFactory $smtpFactory,
        private SmtpOptionsFactory $smtpOptionsFactory,
        private ScopeConfigInterface $scopeConfig,
        private LoggerInterface $logger,
        private EncryptorInterface $encryptor,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function sendMessage()
    {
        try {
            $this->validateMailCredentials();
            $isSetReturnPath = (int) $this->scopeConfig->getValue(
                self::XML_PATH_SENDING_SET_RETURN_PATH,
                ScopeInterface::SCOPE_STORE
            );
            $returnPathValue = $this->scopeConfig->getValue(
                self::XML_PATH_SENDING_RETURN_PATH_EMAIL,
                ScopeInterface::SCOPE_STORE
            );

            $laminasMessage = Message::fromString($this->message->getRawMessage())->setEncoding('utf-8');
            if (2 === $isSetReturnPath && $returnPathValue) {
                $laminasMessage->setSender($returnPathValue);
            } elseif (1 === $isSetReturnPath && $laminasMessage->getFrom()->count()) {
                $fromAddressList = $laminasMessage->getFrom();
                $fromAddressList->rewind();
                $laminasMessage->setSender($fromAddressList->current()->getEmail());
            }

            $this->getTransport()->send($laminasMessage);
        } catch (\Exception $e) {
            $this->logger->error($e);
            throw new MailException(new Phrase('Unable to send mail. Please try again later.'), $e);
        }
    }

    /**
     * @inheritdoc
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return Smtp
     */
    private function getTransport(): Smtp
    {
        if ($this->transport === null) {
            $smtpOptions = $this->smtpOptionsFactory->create([
                'options' =>
                    [
                        'name'              => 'localhost',
                        'host'              => 'sandbox.smtp.mailtrap.io',
                        'port'              => 587,
                        'connection_class'  => 'plain',
                        'connection_config' => [
                            'username' => $this->encryptor->decrypt(
                                $this->scopeConfig->getValue('system/mailtrap/username')
                            ),
                            'password' => $this->encryptor->decrypt(
                                $this->scopeConfig->getValue('system/mailtrap/password')
                            ),
                        ],
                    ],
            ]);

            $this->transport = $this->smtpFactory->create(['options' => $smtpOptions]);
        }

        return $this->transport;
    }

    /**
     * @return void
     * @throws MailException
     */
    private function validateMailCredentials()
    {
        if (
            !$this->encryptor->decrypt($this->scopeConfig->getValue('system/mailtrap/username'))
            || !$this->encryptor->decrypt($this->scopeConfig->getValue('system/mailtrap/password'))
        ) {
            throw new MailException(__('Mailtrap credentials are invalid'));
        }
    }
}
