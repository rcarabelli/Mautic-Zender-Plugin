<?php

namespace MauticPlugin\MauticZenderBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="leads")
 */
class Lead
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=191)
     */
    private $phone;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $last_sent_message_date;

    /**
     * @ORM\Column(type="string", length=191, nullable=true)
     */
    private $last_sent_message_status;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $last_sent_message_content;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $last_received_message_date;

    /**
     * @ORM\Column(type="string", length=191, nullable=true)
     */
    private $last_received_message_status;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $last_received_message_content;

    // Getters and setters...

    public function getId(): int
    {
        return $this->id;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): void
    {
        $this->phone = $phone;
    }

    public function getLastSentMessageDate(): ?\DateTime
    {
        return $this->last_sent_message_date;
    }

    public function setLastSentMessageDate(?\DateTime $lastSentMessageDate): void
    {
        $this->last_sent_message_date = $lastSentMessageDate;
    }

    public function getLastSentMessageStatus(): ?string
    {
        return $this->last_sent_message_status;
    }

    public function setLastSentMessageStatus(?string $lastSentMessageStatus): void
    {
        $this->last_sent_message_status = $lastSentMessageStatus;
    }

    public function getLastSentMessageContent(): ?string
    {
        return $this->last_sent_message_content;
    }

    public function setLastSentMessageContent(?string $lastSentMessageContent): void
    {
        $this->last_sent_message_content = $lastSentMessageContent;
    }

    public function getLastReceivedMessageDate(): ?\DateTime
    {
        return $this->last_received_message_date;
    }

    public function setLastReceivedMessageDate(?\DateTime $lastReceivedMessageDate): void
    {
        $this->last_received_message_date = $lastReceivedMessageDate;
    }

    public function getLastReceivedMessageStatus(): ?string
    {
        return $this->last_received_message_status;
    }

    public function setLastReceivedMessageStatus(?string $lastReceivedMessageStatus): void
    {
        $this->last_received_message_status = $lastReceivedMessageStatus;
    }

    public function getLastReceivedMessageContent(): ?string
    {
        return $this->last_received_message_content;
    }

    public function setLastReceivedMessageContent(?string $lastReceivedMessageContent): void
    {
        $this->last_received_message_content = $lastReceivedMessageContent;
    }
}
