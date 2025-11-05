<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerificationCode;
use App\Notifications\VerificationCodeNotification;
use Carbon\Carbon;
use Random\RandomException;

class VerificationCodeService
{
    /**
     * Generate a random 6-digit code
     * @throws RandomException
     */
    public function generateCode(): string
    {
        return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Create and send email verification code
     * @throws RandomException
     */
    public function sendEmailVerificationCode(User $user): VerificationCode
    {
        // Delete old codes for this email and type
        VerificationCode::where('email', $user->email)
            ->where('type', 'email_verification')
            ->delete();

        // Generate new code
        $code = $this->generateCode();

        // Create verification code
        $verificationCode = VerificationCode::create([
            'email' => $user->email,
            'code' => $code,
            'type' => 'email_verification',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        // Send notification
        $user->notify(new VerificationCodeNotification($code, 'email_verification'));

        return $verificationCode;
    }

    /**
     * Create and send password reset code
     * @throws RandomException
     */
    public function sendPasswordResetCode(string $email): ?VerificationCode
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return null;
        }

        // Delete old codes for this email and type
        VerificationCode::where('email', $email)
            ->where('type', 'password_reset')
            ->delete();

        // Generate new code
        $code = $this->generateCode();

        // Create verification code
        $verificationCode = VerificationCode::create([
            'email' => $email,
            'code' => $code,
            'type' => 'password_reset',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        // Send notification
        $user->notify(new VerificationCodeNotification($code, 'password_reset'));

        return $verificationCode;
    }

    /**
     * Verify email with code
     */
    public function verifyEmailCode(string $email, string $code): bool
    {
        $verificationCode = VerificationCode::where('email', $email)
            ->where('code', $code)
            ->where('type', 'email_verification')
            ->valid()
            ->first();

        if (!$verificationCode) {
            return false;
        }

        // Mark code as used
        $verificationCode->markAsUsed();

        // Mark email as verified
        $user = User::where('email', $email)->first();
        if ($user && !$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return true;
    }

    /**
     * Verify password reset code
     */
    public function verifyPasswordResetCode(string $email, string $code): ?VerificationCode
    {
        return VerificationCode::where('email', $email)
            ->where('code', $code)
            ->where('type', 'password_reset')
            ->valid()
            ->first();
    }

    /**
     * Clean up expired codes
     */
    public function cleanupExpiredCodes(): int
    {
        return VerificationCode::where('expires_at', '<', Carbon::now())
            ->orWhere('used', true)
            ->delete();
    }

    /**
     * Resend verification code
     * @throws RandomException
     */
    public function resendVerificationCode(User $user): VerificationCode
    {
        return $this->sendEmailVerificationCode($user);
    }
}

