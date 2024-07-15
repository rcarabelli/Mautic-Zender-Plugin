<?php

namespace MauticPlugin\MauticZenderBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="zender_api_request_log")
 */
class ZenderApiRequestLog
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(name="requested_at", type="datetime")
     */
    private $requestedAt;

    /**
     * @ORM\Column(name="first_message_at", type="datetime", nullable=true)
     */
    private $firstMessageAt;

    /**
     * @ORM\Column(name="last_message_at", type="datetime", nullable=true)
     */
    private $lastMessageAt;

    /**
     * @ORM\Column(name="response_data", type="text")
     */
    private $responseData;

    /**
     * @ORM\Column(name="status", type="string", length=255)
     */
    private $status;

    /**
     * @ORM\Column(name="message_type", type="string", length=50)
     */
    private $messageType;

    /**
     * @ORM\Column(name="processed_at", type="datetime", nullable=true)
     */
    private $processedAt;

    // Getters and setters...

    public function getId(): int
    {
        return $this->id;
    }

    public function getRequestedAt(): \DateTime
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTime $requestedAt): void
    {
        $this->requestedAt = $requestedAt;
    }

    public function getFirstMessageAt(): ?\DateTime
    {
        return $this->firstMessageAt;
    }

    public function setFirstMessageAt(?\DateTime $firstMessageAt): void
    {
        $this->firstMessageAt = $firstMessageAt;
    }

    public function getLastMessageAt(): ?\DateTime
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTime $lastMessageAt): void
    {
        $this->lastMessageAt = $lastMessageAt;
    }

    public function getResponseData(): string
    {
        return $this->responseData;
    }

    public function setResponseData(string $responseData): void
    {
        $this->responseData = $responseData;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function setMessageType(string $messageType): void
    {
        $this->messageType = $messageType;
    }

    public function getProcessedAt(): ?\DateTime
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTime $processedAt): void
    {
        $this->processedAt = $processedAt;
    }
}
