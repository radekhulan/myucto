UPDATE role_permissions rp
JOIN roles r ON r.id = rp.role_id
SET rp.access_level = 1
WHERE r.system_key = 'readonly'
  AND rp.permission_key <> 'profile.tokens'
  AND rp.access_level >= 2;
