<?php

namespace App\Core\Push;

class PushDeliveryRecorder
{
    public function recordSent(
        ?int $userId,
        string $subscriptionHash,
        string $notificationType,
        ?int $resultCode,
        int $attempt,
    ): void {
        $this->write($userId, $subscriptionHash, $notificationType, $resultCode, PushDelivery::STATUS_SENT, $attempt);
    }

    public function recordFailed(
        ?int $userId,
        string $subscriptionHash,
        string $notificationType,
        ?int $resultCode,
        int $attempt,
    ): void {
        $this->write($userId, $subscriptionHash, $notificationType, $resultCode, PushDelivery::STATUS_FAILED, $attempt);
    }

    public function recordExpired(
        ?int $userId,
        string $subscriptionHash,
        string $notificationType,
        ?int $resultCode,
        int $attempt,
    ): void {
        $this->write($userId, $subscriptionHash, $notificationType, $resultCode, PushDelivery::STATUS_EXPIRED, $attempt);
    }

    private function write(
        ?int $userId,
        string $subscriptionHash,
        string $notificationType,
        ?int $resultCode,
        string $status,
        int $attempt,
    ): void {
        PushDelivery::query()->create([
            'user_id' => $userId,
            'subscription_hash' => $subscriptionHash,
            'notification_type' => $notificationType,
            'result_code' => $resultCode,
            'status' => $status,
            'attempts' => $attempt,
            'created_at' => now(),
        ]);
    }
}