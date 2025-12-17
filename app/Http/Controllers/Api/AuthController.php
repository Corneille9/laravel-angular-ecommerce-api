<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Random\RandomException;

class AuthController extends Controller
{
    /**
     * Get the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     * @authenticated
     */
    public function me(Request $request)
    {
        return response()->json(new UserResource($request->user()));
    }

    /**
     * Register a new user.
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     * @unauthenticated
     */
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
        ]);

        $user->markEmailAsVerified();
        // Send email verification code
        $verificationService = new VerificationCodeService();
        $verificationService->sendEmailVerificationCode($user);

        $abilities = $this->getAbilitiesForRole($user->role);
        $token = $user->createToken('api_token', $abilities)->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
            'message' => 'Registration successful. A verification code has been sent to your email.'
        ], 201);
    }

    /**
     * Login a user.
     *
     * @param LoginRequest $request
     * @return JsonResponse
     * @unauthenticated
     */
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $abilities = $this->getAbilitiesForRole($user->role);
        $token = $user->createToken('api_token', $abilities)->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token
        ]);
    }

    /**
     * Update the user profile.
     *
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update($request->only('name'));

        return response()->json(new UserResource($user));
    }

    /**
     * Logout the user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Send email verification code
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendVerificationCode(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified'
            ], 400);
        }

        $verificationService = new VerificationCodeService();
        $verificationService->sendEmailVerificationCode($user);

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent to your email'
        ]);
    }

    /**
     * Verify email with code
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified'
            ], 400);
        }

        $verificationService = new VerificationCodeService();
        $verified = $verificationService->verifyEmailCode($user->email, $request->code);

        if (!$verified) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully',
            'user' => new UserResource($user->fresh())
        ]);
    }

    /**
     * Resend verification code
     *
     * @param Request $request
     * @return JsonResponse
     * @throws RandomException
     */
    public function resendVerificationCode(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified'
            ], 400);
        }

        $verificationService = new VerificationCodeService();
        $verificationService->resendVerificationCode($user);

        return response()->json([
            'success' => true,
            'message' => 'Verification code resent to your email'
        ]);
    }

    /**
     * Send password reset code
     *
     * @param Request $request
     * @return JsonResponse
     * @throws RandomException
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $verificationService = new VerificationCodeService();
        $verificationCode = $verificationService->sendPasswordResetCode($request->email);

        if (!$verificationCode) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset code sent to your email'
        ]);
    }

    /**
     * Verify password reset code
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyResetCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $verificationService = new VerificationCodeService();
        $verificationCode = $verificationService->verifyPasswordResetCode($request->email, $request->code);

        if (!$verificationCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset code'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reset code verified. You can now reset your password.'
        ]);
    }

    /**
     * Reset password with code
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $verificationService = new VerificationCodeService();
        $verificationCode = $verificationService->verifyPasswordResetCode($request->email, $request->code);

        if (!$verificationCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset code'
            ], 400);
        }

        // Update password
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Mark code as used
        $verificationCode->markAsUsed();

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please login with your new password.'
        ]);
    }

    /**
     * Change password (for authenticated users)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 400);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Optionally revoke all other tokens
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Get abilities based on user role.
     *
     * @param string $role
     * @return array
     */
    private function getAbilitiesForRole(string $role): array
    {
        if ($role === 'admin') {
            return [
                'manage-users',
                'manage-products',
                'manage-categories',
                'manage-orders',
                'manage-payments',
                'view-all-orders',
                'view-all-users',
            ];
        }

        return [
            'view-products',
            'manage-cart',
            'manage-orders',
            'manage-profile',
        ];
    }
}
