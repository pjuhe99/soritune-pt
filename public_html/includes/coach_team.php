<?php
declare(strict_types=1);

const KAKAO_ROOM_URL_REGEX = '/^https:\/\/open\.kakao\.com\/(o|me)\/[A-Za-z0-9_]+$/';

function normalizeKakaoRoomUrl(?string $raw): ?string
{
    if ($raw === null) return null;
    $trimmed = trim($raw);
    if ($trimmed === '') return null;
    if (!preg_match(KAKAO_ROOM_URL_REGEX, $trimmed)) {
        throw new InvalidArgumentException(
            '카톡방 링크 형식이 올바르지 않습니다 (https://open.kakao.com/o/... 또는 /me/...)'
        );
    }
    return $trimmed;
}
