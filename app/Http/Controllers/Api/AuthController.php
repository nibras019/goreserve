<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Events\Registered;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        DB::beginTransaction();
        
        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
            ]);

            // Assign role
            $user->assignRole($validated['role']);

            // Send verification email
            event(new Registered($user));

            // Send welcome notification
            $user->notify(new WelcomeNotification());

            // Create authentication token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Log the registration
            activity()
                ->causedBy($user)
                ->performedOn($user)
                ->log('User registered');

            DB::commit();

            return $this->success([
                'user' => new UserResource($user),
                'token' => $token,
                'token_type' => 'Bearer'
            ], 'Registration successful. Please verify your email address.', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error('Registration failed. Please try again.', 500, [
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ]);
        }
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is active
        if ($user->status !== 'active') {
            return $this->error('Your account has been suspended. Please contact support.', 403);
        }

        // Revoke previous tokens if requested
        if ($request->boolean('revoke_other_tokens', false)) {
            $user->tokens()->delete();
        }

        // Create new token
        $token = $user->createToken(
            'auth_token',
            $this->getTokenAbilities($user)
        )->plainTextToken;

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Log the login
        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ])
            ->log('User logged in');

        return $this->success([
            'user' => new UserResource($user->load('roles', 'business')),
            'token' => $token,
            'token_type' => 'Bearer',
            'abilities' => $this->getTokenAbilities($user)
        ], 'Login successful');
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Log the logout
        activity()
            ->causedBy($request->user())
            ->log('User logged out');

        return $this->success(null, 'Logged out successfully');
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'business']);

        return $this->success([
            'user' => new UserResource($user),
            'abilities' => $request->user()->currentAccessToken()->abilities ?? []
        ]);
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        
        // Delete current token
        $user->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken(
            'auth_token',
            $this->getTokenAbilities($user)
        )->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'abilities' => $this->getTokenAbilities($user)
        ], 'Token refreshed successfully');
    }

    /**
     * Verify email
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'hash' => 'required|string'
        ]);

        $user = User::findOrFail($request->id);

        if (!hash_equals((string) $request->hash, sha1($user->getEmailForVerification()))) {
            return $this->error('Invalid verification link', 403);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, 'Email already verified');
        }

        $user->markEmailAsVerified();

        return $this->success(null, 'Email verified successfully');
    }

    /**
     * Resend verification email
     */
    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->error('Email already verified', 422);
        }

        $user->sendEmailVerificationNotification();

        return $this->success(null, 'Verification email sent');
    }

    /**
     * Get token abilities based on user role
     */
    private function getTokenAbilities(User $user): array
    {
        $abilities = ['user:read', 'user:update'];

        if ($user->hasRole('customer')) {
            $abilities = array_merge($abilities, [
                'booking:create',
                'booking:read',
                'booking:cancel',
                'review:create',
                'payment:create'
            ]);
        }

        if ($user->hasRole('vendor')) {
            $abilities = array_merge($abilities, [
                'business:manage',
                'service:manage',
                'staff:manage',
                'booking:manage',
                'report:read'
            ]);
        }

        if ($user->hasRole('admin')) {
            $abilities = ['*']; // All abilities
        }

        return $abilities;
    }
}