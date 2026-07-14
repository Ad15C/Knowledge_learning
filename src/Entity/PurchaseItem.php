<?php

namespace App\Entity;

use App\Repository\PurchaseItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseItemRepository::class)]
class PurchaseItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Purchase $purchase = null;

    #[ORM\ManyToOne]
    private ?Lesson $lesson = null;

    #[ORM\ManyToOne]
    private ?Cursus $cursus = null;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $unitPrice = '0.00';

    public function getId(): ?int { return $this->id; }

    public function getPurchase(): ?Purchase { return $this->purchase; }
    public function setPurchase(?Purchase $purchase): static { $this->purchase = $purchase; return $this; }

    public function getLesson(): ?Lesson { return $this->lesson; }
    public function setLesson(?Lesson $lesson): static { $this->lesson = $lesson; return $this; }

    public function getCursus(): ?Cursus { return $this->cursus; }
    public function setCursus(?Cursus $cursus): static { $this->cursus = $cursus; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): static { $this->quantity = max(1, $quantity); return $this; }

    public function getUnitPrice(): float { return (float) $this->unitPrice; }
    public function getUnitPriceInCents(): int
    {
        return (int) round(((float) $this->unitPrice) * 100);
    }
    public function setUnitPrice(float|int|string $price): static
    {
        $price = (float) $price;

        if ($price < 0) {
            throw new \InvalidArgumentException(
                'Le prix unitaire ne peut pas être négatif.'
            );
        }

        $this->unitPrice = number_format($price, 2, '.', '');

        return $this;
    }

    public function getTotal(): float
    {
        return $this->getUnitPrice() * $this->quantity;
    }
}