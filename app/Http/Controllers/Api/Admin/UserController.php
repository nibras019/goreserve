<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Get all users
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'role' => 'nullable|string|in:customer,vendor,admin',
            'status' => 'nullable|string|in:active,suspended,deleted',
            'search' => 'nullable|string',
            'has_business' => 'nullable|boolean',
            'sort_by' => 'nullable|string|in:name,email,created_at,last_login',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = User::with(['roles', 'business']);

        // Filter by role
        if (!empty($validated['role'])) {
            $query->role($validated['role']);
        }

        // Filter by status
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Search
        if (!empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('name', 'like', "%{$validated['search']}%")
                  ->orWhere('email', 'like', "%{$validated['search']}%")
                  ->orWhere('phone', 'like', "%{$validated['search']}%");
            });
        }

        // Filter by business ownership
        if (isset($validated['has_business'])) {
            if ($validated['has_business']) {
                $query->has('business');
            } else {
                $query->doesntHave('business');
            }
        }

        // Add statistics
        $query->withCount(['bookings', 'reviews'])
              ->withSum('bookings as total_spent', 'amount');

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $users = $query->paginate($validated['per_page'] ?? 20);

        // Get summary statistics
        $stats = [
            'total_users' => User::count(),
            'by_role' => User::selectRaw('COUNT(*) as count')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->groupBy('roles.name')
                ->pluck('count', 'roles.name'),
            'by_status' => User::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'new_today' => User::whereDate('created_at', today())->count(),
            'new_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => UserResource::collection($users),
            'stats' => $stats,
            'pagination' => [
                'total' => $users->total(),
                'count' => $users->count(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'total_pages' => $users->lastPage()
            ]
        ]);
    }

    /**
     * Get user details
     */
    public function show(User $user)
    {
        $user->load([
            'roles',
            'business',
            'bookings' => function ($query) {
                $query->latest()->limit(10);
            },
            'reviews' => function ($query) {
                $query->latest()->limit(5);
            }
        ]);

        // Get user statistics
        $stats = [
            'total_bookings' => $user->bookings()->count(),
            'completed_bookings' => $user->bookings()->where('status', 'completed')->count(),
            'cancelled_bookings' => $user->bookings()->where('status', 'cancelled')->count(),
            'no_shows' => $user->bookings()->where('status', 'no_show')->count(),
            'total_spent' => $user->bookings()->where('payment_status', 'paid')->sum('amount'),
            'average_booking_value' => $user->bookings()->where('payment_status', 'paid')->avg('amount'),
            'total_reviews' => $user->reviews()->count(),
            'average_rating_given' => $user->reviews()->avg('rating'),
        ];

        // Get activity timeline
        $activities = DB::table('activity_log')
            ->where('causer_id', $user->id)
            ->where('causer_type', User::class)
            ->latest()
            ->limit(20)
            ->get();

        return $this->success([
            'user' => new UserResource($user),
            'stats' => $stats,
            'activities' => $activities
        ], 'User details retrieved successfully');
    }

    /**
     * Create new user (admin)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|string|in:customer,vendor,admin',
            'send_welcome_email' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'email_verified_at' => now() // Auto-verify admin-created users
            ]);

            $user->assignRole($validated['role']);

            if ($request->boolean('send_welcome_email')) {
                $user->notify(new \App\Notifications\WelcomeNotification($validated['password']));
            }

            DB::commit();

            activity()
                ->causedBy($request->user())
                ->performedOn($user)
                ->log('User created by admin');

            return $this->success(
                new UserResource($user),
                'User created successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to create user',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'status' => 'sometimes|in:active,suspended',
            'role' => 'sometimes|string|in:customer,vendor,admin',
            'password' => 'nullable|string|min:8',
            'wallet_balance' => 'nullable|numeric|min:0'
        ]);

        DB::beginTransaction();

        try {
            // Update basic info
            $user->update(array_filter([
                'name' => $validated['name'] ?? $user->name,
                'email' => $validated['email'] ?? $user->email,
                'phone' => $validated['phone'] ?? $user->phone,
                'status' => $validated['status'] ?? $user->status,
                'wallet_balance' => $validated['wallet_balance'] ?? $user->wallet_balance,
            ]));

            // Update password if provided
            if (!empty($validated['password'])) {
                $user->update(['password' => Hash::make($validated['password'])]);
            }

            // Update role if changed
            if (!empty($validated['role']) && !$user->hasRole($validated['role'])) {
                $user->syncRoles([$validated['role']]);
            }

            DB::commit();

            activity()
                ->causedBy($request->user())
                ->performedOn($user)
                ->withProperties(['changes' => $validated])
                ->log('User updated by admin');

            return $this->success(
                new UserResource($user->fresh(['roles'])),
                'User updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update user',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Suspend/Unsuspend user
     */
    public function toggleStatus(Request $request, User $user)
    {
        $newStatus = $user->status === 'active' ? 'suspended' : 'active';
        
        $user->update(['status' => $newStatus]);

        // Revoke all tokens if suspending
        if ($newStatus === 'suspended') {
            $user->tokens()->delete();
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->log("User {$newStatus}");

        return $this->success([
            'status' => $newStatus,
            'message' => "User {$newStatus} successfully"
        ]);
    }

    /**
     * Delete user (soft delete)
     */
    public function destroy(Request $request, User $user)
    {
        // Check if user has active bookings
        $hasActiveBookings = $user->bookings()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('booking_date', '>=', today())
            ->exists();

        if ($hasActiveBookings) {
            return $this->error(
                'Cannot delete user with active bookings',
                422,
                ['active_bookings' => true]
            );
        }

        // Soft delete
        $user->update(['status' => 'deleted']);
        $user->delete();

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('User deleted');

        return $this->success(null, 'User deleted successfully');
    }

    /**
     * Impersonate user
     */
    public function impersonate(Request $request, User $user)
    {
        if ($user->hasRole('admin')) {
            return $this->error('Cannot impersonate admin users', 403);
        }

        // Create impersonation token
        $token = $user->createToken('impersonation', ['*'], now()->addHours(1));

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('User impersonation started');

        return $this->success([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => new UserResource($user)
        ], 'Impersonation token created');
    }

    /**
     * Export users
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'format' => 'required|in:csv,excel',
            'filters' => 'nullable|array'
        ]);

        $query = User::with(['roles', 'business']);

        // Apply filters
        if (!empty($validated['filters']['role'])) {
            $query->role($validated['filters']['role']);
        }

        if (!empty($validated['filters']['status'])) {
            $query->where('status', $validated['filters']['status']);
        }

        $users = $query->get();

        if ($validated['format'] === 'csv') {
            return $this->exportToCsv($users);
        }

        return $this->error('Excel export not implemented', 501);
    }

    /**
     * Export users to CSV
     */
    private function exportToCsv($users)
    {
        $headers = ['ID', 'Name', 'Email', 'Phone', 'Role', 'Status', 'Created At', 'Last Login'];
        
        $csv = implode(',', $headers) . "\n";

        foreach ($users as $user) {
            $row = [
                $user->id,
                $user->name,
                $user->email,
                $user->phone ?? 'N/A',
                $user->roles->first()?->name ?? 'N/A',
                $user->status,
                $user->created_at->format('Y-m-d'),
                $user->last_login_at?->format('Y-m-d H:i') ?? 'Never'
            ];

            $csv .= implode(',', array_map(fn($value) => '"' . str_replace('"', '""', $value) . '"', $row)) . "\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="users_export.csv"');
    }
}