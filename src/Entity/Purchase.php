<?php

namespace App\Entity;

use App\Repository\PurchaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Purchase
{
    public const STATUS_CART     = 'cart';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_PAID     = 'paid';
    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [
        self::STATUS_CART,
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_CANCELED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $orderNumber = null;

    #[ORM\ManyToOne(inversedBy: 'purchases')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CART;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /** @var Collection<int, PurchaseItem> */
    #[ORM\OneToMany(mappedBy: 'purchase', targetEntity: PurchaseItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function generateOrderNumber(): void
    {
        if (!$this->orderNumber) {
            $this->orderNumber = 'ORD-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
        }
    }

    public function calculateTotal(): void
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->getTotal();
        }
        $this->total = number_format($total, 2, '.', '');
    }

    public function markPaid(?\DateTimeImmutable $paidAt = null): static
    {
        $this->setStatus(self::STATUS_PAID);
        $this->paidAt = $paidAt ?? new \DateTimeImmutable();
        return $this;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripeSessionId;
    }

    public function setStripeSessionId(?string $stripeSessionId): static
    {
        $this->stripeSessionId = $stripeSessionId;
        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;
        return $this;
    }

    public function markPending(): static
    {
        $this->setStatus(self::STATUS_PENDING);
        return $this;
    }

    public function markCanceled(): static
    {
        $this->setStatus(self::STATUS_CANCELED);
        return $this;
    }

    public function getId(): ?int { return $this->id; }

    public function getOrderNumber(): ?string { return $this->orderNumber; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid purchase status "%s".', $status));
        }
        $this->status = $status;
        return $this;
    }

    public function getTotal(): float { return (float) $this->total; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    /** @return Collection<int, PurchaseItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(PurchaseItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPurchase($this);
        }
        return $this;
    }

    public function removeItem(PurchaseItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getPurchase() === $this) {
                $item->setPurchase(null);
            }
        }
        return $this;
    }

    public function isPaid(): bool { return $this->status === self::STATUS_PAID; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isCanceled(): bool { return $this->status === self::STATUS_CANCELED; }
    public function isCart(): bool { return $this->status === self::STATUS_CART; }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CART     => 'Panier',
            self::STATUS_PENDING  => 'En attente',
            self::STATUS_PAID     => 'Payée',
            self::STATUS_CANCELED => 'Annulée',
            default               => $this->status,
        };
    }
}