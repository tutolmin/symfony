<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 */
class User implements UserInterface
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
     */
    private $email;

    /**
     * @ORM\Column(type="json")
     */
    private $roles = [];

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $apiToken;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $facebookId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $googleId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $lichessId;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $queueLimit;

    /**
     * @ORM\Column(type="float", nullable=true)
     */
    private $balance;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdDateTime;

    /**
     * @ORM\Column(type="boolean", options={"default":true})
     */
    private $canUpload;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $firstName;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $lastName;

    /**
     * @ORM\Column(type="string", length=127, options={"default":"instant"})
     */
    private $notification_type;

    /**
     * @ORM\OneToMany(targetEntity=CompleteAnalysis::class, mappedBy="user", orphanRemoval=true)
     */
    private $complete_analyses;

    public function __construct()
    {
        $this->createdDateTime = new \DateTime();
        $this->canUpload = true;
        $this->notification_type = 'instant';
        $this->complete_analyses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUsername(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getPassword()
    {
        // not needed for apps that do not check user passwords
	return null;
    }

    /**
     * @see UserInterface
     */
    public function getSalt()
    {
        // not needed for apps that do not check user passwords
	return null;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(string $apiToken): self
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getFacebookId(): ?string
    {
        return $this->facebookId;
    }

    public function setFacebookId(?string $facebookId): self
    {
        $this->facebookId = $facebookId;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): self
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getLichessId(): ?string
    {
        return $this->lichessId;
    }

    public function setLichessId(?string $lichessId): self
    {
        $this->lichessId = $lichessId;

        return $this;
    }

    public function getQueueLimit(): ?int
    {
        return $this->queueLimit;
    }

    public function setQueueLimit(?int $queueLimit): self
    {
        $this->queueLimit = $queueLimit;

        return $this;
    }

    public function getBalance(): ?float
    {
        return $this->balance;
    }

    public function setBalance(?float $balance): self
    {
        $this->balance = $balance;

        return $this;
    }

    public function getCreatedDateTime(): ?\DateTimeInterface
    {
        return $this->createdDateTime;
    }

    public function setCreatedDateTime(\DateTimeInterface $createdDateTime): self
    {
        $this->createdDateTime = $createdDateTime;

        return $this;
    }

    public function getCanUpload(): ?bool
    {
        return $this->canUpload;
    }

    public function setCanUpload(bool $canUpload): self
    {
        $this->canUpload = $canUpload;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getNotificationType(): ?string
    {
        return $this->notification_type;
    }

    public function setNotificationType(string $notification_type): self
    {
        $this->notification_type = $notification_type;

        return $this;
    }

    /**
     * @return Collection|CompleteAnalysis[]
     */
    public function getCompleteAnalyses(): Collection
    {
        return $this->complete_analyses;
    }

    public function addCompleteAnalysis(CompleteAnalysis $completeAnalysis): self
    {
        if (!$this->complete_analyses->contains($completeAnalysis)) {
            $this->complete_analyses[] = $completeAnalysis;
            $completeAnalysis->setUser($this);
        }

        return $this;
    }

    public function removeCompleteAnalysis(CompleteAnalysis $completeAnalysis): self
    {
        if ($this->complete_analyses->contains($completeAnalysis)) {
            $this->complete_analyses->removeElement($completeAnalysis);
            // set the owning side to null (unless already changed)
            if ($completeAnalysis->getUser() === $this) {
                $completeAnalysis->setUser(null);
            }
        }

        return $this;
    }
}
?>
