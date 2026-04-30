-- 코치 팀(self-FK) + 1:1 카톡방 링크 컬럼 추가
-- 시드: active 코치 30명을 3개 팀(Kel/Nana/Flora)에 배정 + 카톡방 URL 28개
-- (참고: MySQL에서 ALTER TABLE은 implicit commit. 트랜잭션 래퍼 무의미하므로 생략.
--  시드 UPDATE는 모두 멱등하므로 부분 실패 시 재실행 가능)

ALTER TABLE coaches
  ADD COLUMN team_leader_id INT NULL DEFAULT NULL AFTER evaluation,
  ADD COLUMN kakao_room_url VARCHAR(255) NULL DEFAULT NULL AFTER memo,
  ADD INDEX idx_team_leader (team_leader_id),
  ADD CONSTRAINT fk_coach_team_leader
      FOREIGN KEY (team_leader_id) REFERENCES coaches(id) ON DELETE SET NULL;

-- 1) 팀장 자기 자신을 가리키도록 설정 (3명)
UPDATE coaches SET team_leader_id = id WHERE coach_name IN ('Kel','Nana','Flora');

-- 2) Kel팀 팀원
UPDATE coaches SET team_leader_id = (SELECT id FROM (SELECT id FROM coaches WHERE coach_name='Kel') AS t)
  WHERE coach_name IN ('Lulu','Ella','Jay','Darren','Cera','Jacey','Ethan','Sen','Sophia');

-- 3) Nana팀 팀원
UPDATE coaches SET team_leader_id = (SELECT id FROM (SELECT id FROM coaches WHERE coach_name='Nana') AS t)
  WHERE coach_name IN ('Hyun','Raina','Bree','Kathy','Anne','Ej','Tia','Rin','Jenny');

-- 4) Flora팀 팀원
UPDATE coaches SET team_leader_id = (SELECT id FROM (SELECT id FROM coaches WHERE coach_name='Flora') AS t)
  WHERE coach_name IN ('Rachel','Julia','Frida','Jun','Salley','Hani','Tess','Hazel','Sophie');

-- 5) 카톡방 URL 시드 (28명)
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sz1en1ag' WHERE coach_name='Nana';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sOGKYyde' WHERE coach_name='Ella';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sdowrLEg' WHERE coach_name='Hani';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/s1wgF5sf' WHERE coach_name='Jun';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/me/raina'    WHERE coach_name='Raina';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sHQgUU1e' WHERE coach_name='Kel';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sbW4SsOd' WHERE coach_name='Jay';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sKf7CpRg' WHERE coach_name='Hyun';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sT1QVejh' WHERE coach_name='Bree';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sXIf8qse' WHERE coach_name='Rachel';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/s7ubBXli' WHERE coach_name='Julia';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sBcGGboi' WHERE coach_name='Ethan';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sJ9UQcVf' WHERE coach_name='Ej';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/me/soritune_hazel' WHERE coach_name='Hazel';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sr0qkNAh' WHERE coach_name='Kathy';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sI59jeSf' WHERE coach_name='Cera';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/shzl2FMd' WHERE coach_name='Salley';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sYhdGboi' WHERE coach_name='Tia';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sGMLOqEg' WHERE coach_name='Darren';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/s4vazNEg' WHERE coach_name='Lulu';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sDC69Wcf' WHERE coach_name='Jacey';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/soF5eNEg' WHERE coach_name='Anne';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/me/Coach_Tess' WHERE coach_name='Tess';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/skOGHboi' WHERE coach_name='Sophie';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sOfV4aoi' WHERE coach_name='Sen';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sKkVp1ag' WHERE coach_name='Flora';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sou9Cboi' WHERE coach_name='Rin';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sQ9CQ9ye' WHERE coach_name='Sophia';
