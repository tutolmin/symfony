<?php

namespace App\Entity;

use App\Repository\CompleteAnalysisRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=CompleteAnalysisRepository::class)
 */
class CompleteAnalysis
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=User::class, inversedBy="complete_analyses")
     * @ORM\JoinColumn(nullable=false)
     */
    private $user;

    /**
     * @ORM\Column(type="integer")
     */
    private $analysis_id;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getAnalysisId(): ?int
    {
        return $this->analysis_id;
    }

    public function setAnalysisId(int $analysis_id): self
    {
        $this->analysis_id = $analysis_id;

        return $this;
    }
}
