<?php

namespace App\Exceptions;

use Exception;
use App\Models\User;

class InsufficientBalanceException extends Exception
{
    /**
     * The amount required for the transaction
     *
     * @var float
     */
    protected $requiredAmount;

    /**
     * The available balance
     *
     * @var float
     */
    protected $availableAmount;

    /**
     * The user ID associated with the balance
     *
     * @var int|null
     */
    protected $userId;

    /**
     * The type of balance (wallet, credit, etc.)
     *
     * @var string
     */
    protected $balanceType;

    /**
     * Additional context about the transaction
     *
     * @var array
     */
    protected $context;

    /**
     * Available payment options
     *
     * @var array
     */
    protected $paymentOptions;

    /**
     * Create a new insufficient balance exception.
     *
     * @param float $requiredAmount
     * @param float $availableAmount
     * @param string $balanceType
     * @param int|null $userId
     * @param array $context
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        float $requiredAmount,
        float $availableAmount,
        string $balanceType = 'wallet',
        ?int $userId = null,
        array $context = [],
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->requiredAmount = $requiredAmount;
        $this->availableAmount = $availableAmount;
        $this->balanceType = $balanceType;
        $this->userId = $userId;
        $this->context = $context;

        if (empty($message)) {
            $message = $this->buildMessage();
        }

        parent::__construct($message, $code, $previous);

        $this->generatePaymentOptions();
    }

    /**
     * Create exception for wallet balance
     */
    public static function forWallet(
        float $requiredAmount,
        float $availableAmount,
        ?User $user = null
    ): self {
        return new static(
            $requiredAmount,
            $availableAmount,
            'wallet',
            $user?->id,
            ['currency' => config('goreserve.payment.currency', 'USD')]
        );
    }

    /**
     * Create exception for credit balance
     */
    public static function forCredit(
        float $requiredCredits,
        float $availableCredits,
        ?User $user = null
    ): self {
        return new static(
            $requiredCredits,
            $availableCredits,
            'credit',
            $user?->id,
            ['credit_type' => 'booking_credits']
        );
    }

    /**
     * Create exception for deposit requirement
     */
    public static function forDeposit(
        float $requiredDeposit,
        float $availableAmount,
        array $bookingDetails
    ): self {
        return new static(
            $requiredDeposit,
            $availableAmount,
            'deposit',
            $bookingDetails['user_id'] ?? null,
            [
                'booking_ref' => $bookingDetails['booking_ref'] ?? null,
                'total_amount' => $bookingDetails['total_amount'] ?? null,
                'deposit_percentage' => $bookingDetails['deposit_percentage'] ?? 50,
            ]
        );
    }

    /**
     * Create exception for refund
     */
    public static function forRefund(
        float $refundAmount,
        float $businessBalance,
        int $businessId
    ): self {
        return new static(
            $refundAmount,
            $businessBalance,
            'business_balance',
            null,
            [
                'business_id' => $businessId,
                'refund_type' => 'customer_refund',
            ],
            'Insufficient business balance to process refund'
        );
    }

    /**
     * Build the default exception message
     */
    protected function buildMessage(): string
    {
        $shortage = $this->getShortage();
        $formattedShortage = number_format($shortage, 2);
        $formattedRequired = number_format($this->requiredAmount, 2);
        $formattedAvailable = number_format($this->availableAmount, 2);

        return match($this->balanceType) {
            'wallet' => "Insufficient wallet balance. Required: \${$formattedRequired}, Available: \${$formattedAvailable}, Short by: \${$formattedShortage}",
            'credit' => "Insufficient credits. Required: {$this->requiredAmount}, Available: {$this->availableAmount}",
            'deposit' => "Insufficient funds for deposit. Required: \${$formattedRequired}, Available: \${$formattedAvailable}",
            'business_balance' => "Insufficient business balance. Required: \${$formattedRequired}, Available: \${$formattedAvailable}",
            default => "Insufficient balance. Required: {$formattedRequired}, Available: {$formattedAvailable}",
        };
    }

    /**
     * Generate available payment options
     */
    protected function generatePaymentOptions(): void
    {
        $this->paymentOptions = [];

        // Add top-up option for wallet
        if ($this->balanceType === 'wallet') {
            $this->paymentOptions[] = [
                'type' => 'top_up',
                'minimum_amount' => $this->getShortage(),
                'suggested_amounts' => $this->getSuggestedTopUpAmounts(),
                'methods' => ['card', 'bank_transfer', 'paypal'],
            ];
        }

        // Add direct payment option
        $this->paymentOptions[] = [
            'type' => 'direct_payment',
            'amount' => $this->requiredAmount,
            'methods' => ['card', 'paypal', 'google_pay', 'apple_pay'],
        ];

        // Add credit purchase option
        if ($this->balanceType === 'credit') {
            $this->paymentOptions[] = [
                'type' => 'purchase_credits',
                'minimum_credits' => ceil($this->getShortage()),
                'packages' => $this->getCreditPackages(),
            ];
        }

        // Add payment plan option for large amounts
        if ($this->requiredAmount > 500) {
            $this->paymentOptions[] = [
                'type' => 'payment_plan',
                'installments' => $this->calculateInstallments(),
                'down_payment' => $this->requiredAmount * 0.3,
            ];
        }
    }

    /**
     * Get suggested top-up amounts
     */
    protected function getSuggestedTopUpAmounts(): array
    {
        $shortage = $this->getShortage();
        
        return [
            ceil($shortage / 10) * 10,  // Round up to nearest 10
            ceil($shortage / 50) * 50,  // Round up to nearest 50
            ceil($shortage / 100) * 100, // Round up to nearest 100
        ];
    }

    /**
     * Get available credit packages
     */
    protected function getCreditPackages(): array
    {
        return [
            ['credits' => 10, 'price' => 9.99, 'savings' => 0],
            ['credits' => 50, 'price' => 44.99, 'savings' => 10],
            ['credits' => 100, 'price' => 79.99, 'savings' => 20],
        ];
    }

    /**
     * Calculate installment options
     */
    protected function calculateInstallments(): array
    {
        return [
            ['months' => 3, 'monthly_payment' => round($this->requiredAmount / 3, 2)],
            ['months' => 6, 'monthly_payment' => round($this->requiredAmount / 6, 2)],
            ['months' => 12, 'monthly_payment' => round($this->requiredAmount / 12, 2)],
        ];
    }

    /**
     * Get the shortage amount
     */
    public function getShortage(): float
    {
        return max(0, $this->requiredAmount - $this->availableAmount);
    }

    /**
     * Get the required amount
     */
    public function getRequiredAmount(): float
    {
        return $this->requiredAmount;
    }

    /**
     * Get the available amount
     */
    public function getAvailableAmount(): float
    {
        return $this->availableAmount;
    }

    /**
     * Get the user ID
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Get the balance type
     */
    public function getBalanceType(): string
    {
        return $this->balanceType;
    }

    /**
     * Get the transaction context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get payment options
     */
    public function getPaymentOptions(): array
    {
        return $this->paymentOptions;
    }

    /**
     * Check if the shortage is within a certain threshold
     */
    public function isWithinThreshold(float $threshold): bool
    {
        return $this->getShortage() <= $threshold;
    }

    /**
     * Convert exception to array for API responses
     */
    public function toArray(): array
    {
        return [
            'error' => 'insufficient_balance',
            'message' => $this->getMessage(),
            'balance_type' => $this->balanceType,
            'required_amount' => $this->requiredAmount,
            'available_amount' => $this->availableAmount,
            'shortage' => $this->getShortage(),
            'payment_options' => $this->paymentOptions,
            'context' => $this->context,
        ];
    }
}