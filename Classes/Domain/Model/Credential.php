<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Domain\Model;

final class Credential
{
    public function __construct(
        private int $uid = 0,
        private int $pid = 0,
        private int $beUser = 0,
        private string $credentialId = '',
        private string $publicKeyCose = '',
        private int $signCount = 0,
        private string $userHandle = '',
        private string $aaguid = '',
        private string $transports = '[]',
        private string $label = '',
        private int $createdAt = 0,
        private int $lastUsedAt = 0,
        private int $revokedAt = 0,
        private int $revokedBy = 0,
    ) {}

    public function getUid(): int
    {
        return $this->uid;
    }

    public function setUid(int $uid): void
    {
        $this->uid = $uid;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function setPid(int $pid): void
    {
        $this->pid = $pid;
    }

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function setBeUser(int $beUser): void
    {
        $this->beUser = $beUser;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function setCredentialId(string $credentialId): void
    {
        $this->credentialId = $credentialId;
    }

    public function getPublicKeyCose(): string
    {
        return $this->publicKeyCose;
    }

    public function setPublicKeyCose(string $publicKeyCose): void
    {
        $this->publicKeyCose = $publicKeyCose;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    public function setSignCount(int $signCount): void
    {
        $this->signCount = $signCount;
    }

    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    public function setUserHandle(string $userHandle): void
    {
        $this->userHandle = $userHandle;
    }

    public function getAaguid(): string
    {
        return $this->aaguid;
    }

    public function setAaguid(string $aaguid): void
    {
        $this->aaguid = $aaguid;
    }

    public function getTransports(): string
    {
        return $this->transports;
    }

    public function setTransports(string $transports): void
    {
        $this->transports = $transports;
    }

    /**
     * @return list<string>
     */
    public function getTransportsArray(): array
    {
        $decoded = \json_decode($this->transports, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return \array_values(\array_filter($decoded, '\is_string'));
    }

    /**
     * @param list<string> $transports
     */
    public function setTransportsArray(array $transports): void
    {
        $this->transports = \json_encode(\array_values($transports), JSON_THROW_ON_ERROR);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(int $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function getRevokedAt(): int
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(int $revokedAt): void
    {
        $this->revokedAt = $revokedAt;
    }

    public function getRevokedBy(): int
    {
        return $this->revokedBy;
    }

    public function setRevokedBy(int $revokedBy): void
    {
        $this->revokedBy = $revokedBy;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'pid' => $this->pid,
            'be_user' => $this->beUser,
            'credential_id' => $this->credentialId,
            'public_key_cose' => $this->publicKeyCose,
            'sign_count' => $this->signCount,
            'user_handle' => $this->userHandle,
            'aaguid' => $this->aaguid,
            'transports' => $this->transports,
            'label' => $this->label,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
            'revoked_at' => $this->revokedAt,
            'revoked_by' => $this->revokedBy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uid: self::intVal($data['uid'] ?? null),
            pid: self::intVal($data['pid'] ?? null),
            beUser: self::intVal($data['be_user'] ?? null),
            credentialId: self::stringVal($data['credential_id'] ?? null),
            publicKeyCose: self::stringVal($data['public_key_cose'] ?? null),
            signCount: self::intVal($data['sign_count'] ?? null),
            userHandle: self::stringVal($data['user_handle'] ?? null),
            aaguid: self::stringVal($data['aaguid'] ?? null),
            transports: self::stringVal($data['transports'] ?? null, '[]'),
            label: self::stringVal($data['label'] ?? null),
            createdAt: self::intVal($data['created_at'] ?? null),
            lastUsedAt: self::intVal($data['last_used_at'] ?? null),
            revokedAt: self::intVal($data['revoked_at'] ?? null),
            revokedBy: self::intVal($data['revoked_by'] ?? null),
        );
    }

    private static function intVal(mixed $value, int $default = 0): int
    {
        return \is_numeric($value) ? (int) $value : $default;
    }

    private static function stringVal(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'uid' => $this->uid,
            'label' => $this->label,
            'createdAt' => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
            'isRevoked' => $this->isRevoked(),
        ];
    }
}
