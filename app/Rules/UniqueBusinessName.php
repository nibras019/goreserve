<?php
namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Business;

class UniqueBusinessName implements ValidationRule
{
    protected $excludeId;
    protected $userId;

    public function __construct($excludeId = null, $userId = null)
    {
        $this->excludeId = $excludeId;
        $this->userId = $userId;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Business::where('name', $value);

        // Exclude current business if updating
        if ($this->excludeId) {
            $query->where('id', '!=', $this->excludeId);
        }

        // Check for specific user if provided
        if ($this->userId) {
            $query->where('user_id', '!=', $this->userId);
        }

        // Only check against approved and pending businesses
        $query->whereIn('status', ['approved', 'pending']);

        if ($query->exists()) {
            $fail('A business with this name already exists.');
        }
    }

    /**
     * Create a new rule instance for updating.
     */
    public static function forUpdate($businessId, $userId = null)
    {
        return new static($businessId, $userId);
    }

    /**
     * Create a new rule instance for creation.
     */
    public static function forCreation($userId = null)
    {
        return new static(null, $userId);
    }
}